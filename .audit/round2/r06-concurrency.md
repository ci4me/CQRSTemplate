# r06 — Concurrency, atomicity, race conditions (round 2 review)

Scope: verify or reject the concurrency-class findings in round-1 reports 05/10/11, plus
spot-check for hazards those reports missed. Reading targets:
`TransactionMiddleware`, `AuditMiddleware`, `EventDispatcher`, `EventOutboxRelay`,
`JobWorker`, `JobQueue`, `DocumentNumberingService`, `CookieRepository`,
`IdempotencyMiddleware`, `CookieReadModelProjection`, `RebuildProjections`,
`CorrelationIdService`, `RelayOutboxEvents`, `WorkJobs`, migrations.
Cross-checked against vendored CI4 (`BaseConnection`, `BaseBuilder`, `MySQLi/Connection`).

---

## Verified concurrency hazards

### V1 — AuditMiddleware insert failure trips CI4 auto-rollback (report 10 C1) — CONFIRMED

`app/Infrastructure/Bus/Middleware/AuditMiddleware.php:90-113` does:

```php
try {
    $db->table('audit_log')->insert([...]);
} catch (\Throwable $writeError) {
    $this->logger->error('Audit log write failed', [...]);
}
```

But the audit insert runs *inside* `TransactionMiddleware` (verified by Services
wiring order in report 10 finding L3 and the runtime trace at lines 75-82).
CI4's `BaseConnection::query()` calls
`$this->handleTransStatus()` on **any** query failure
(`vendor/codeigniter4/framework/system/Database/BaseConnection.php:651-655`),
and that method sets `transStatus=false` whenever `transDepth !== 0`
(`BaseConnection.php:910-915`). The PHP exception is then thrown out of the
builder — `AuditMiddleware` catches it, but the transStatus flag is **already
flipped**. Control unwinds to `TransactionMiddleware.php:59`:

```php
if ($db->transStatus() === false) {
    $db->transRollback();
    throw new \RuntimeException(...);
}
```

→ Every successful business write whose audit insert fails (e.g. `audit_log`
DDL missing on a hotfix branch, ALTER lock, disk full on the audit
tablespace, FK to a non-existent actor) gets rolled back and surfaces as a
500 to the caller. The opposite of the AuditMiddleware docblock contract.

**Severity:** Critical, exactly as report 10 claims.
**Proposed fix sufficiency:** Report 10 lists three options. Only options (b)
and (c) actually fix it: (a) `$db->resetTransStatus()` works but is fragile —
some drivers throw on the query line itself (`DBDebug=true && transException`,
`BaseConnection.php:657-665`), bypassing the catch entirely, so reset
alone doesn't cover every case. Recommendation: **move audit out of the
business transaction** (option c) and write it to a sibling connection group
(option b) for durability; eat the orphaned-write risk by writing the audit
row from a deferred queue. Documenting the choice is mandatory.

### V2 — EventDispatcher swallows listener exceptions, breaking the documented transactional-events contract (report 10 C2) — CONFIRMED

`app/Infrastructure/Bus/EventDispatcher.php:82-105`: the `dispatch()` method
catches `\Throwable` per listener and only logs. `TransactionMiddleware.php:19-22`
docs claim "if any synchronous listener throws, the entire write is rolled
back." That claim is false — the exception never re-throws past the
dispatcher.

Compounding: `EventOutboxRelay::processRow` (`EventOutboxRelay.php:132-136`)
ALSO wraps `$this->dispatcher->dispatch($event)` in a try/catch expecting
exceptions to surface. Since the dispatcher swallows them, the relay marks
every event `delivered` regardless of listener health — the entire
`onDispatchFailure`/backoff/`failed`-state machine (lines 191-220) is
unreachable in practice. Two layers of dead code agreeing that they hand
each other exceptions that neither side actually throws.

**Severity:** Critical.
**Proposed fix sufficiency:** Report 10 frames it as "pick one." That's
correct but understates: the right answer is **two dispatch modes** —
`dispatchOrFail()` (re-throws, used by relay + tx-bound paths) and
`dispatch()` (best-effort, current behavior) — because legitimate use cases
exist for both. Hiding that decision behind a single method has produced the
contradiction.

### V3 — `EventOutboxRelay::claim()` truthiness gate admits double-dispatch (report 11) — CONFIRMED, *and stronger than the report stated*

`EventOutboxRelay.php:142-151`:

```php
$affected = $this->connection()->table('event_outbox')->where('id', $id)
    ->where('status', 'pending')->update(['status' => 'in_flight']);
return $affected === true || $this->connection()->affectedRows() === 1;
```

`BaseBuilder::update()` (verified at `vendor/.../BaseBuilder.php:2489-2533`)
returns `bool`: `true` whenever the query *executed successfully*, regardless
of matched rows. So when worker B loses the race against worker A:
- A's UPDATE sets the row to `in_flight` → affectedRows=1.
- B's UPDATE re-executes against `status='pending'` filter, matches 0 rows,
  still returns `true`.
- `$affected === true` short-circuits → `claim()` returns `true` for B.
- B proceeds to `dispatcher->dispatch($event)` on a row already in flight on
  A → **double delivery**.

The `|| affectedRows() === 1` half is dead because `true || …` never
evaluates the RHS. The `JobWorker::claim()` (`JobWorker.php:129-142`) does
the right thing by ignoring `$affected` and gating only on
`affectedRows() === 1`.

**Severity:** Critical.
**Proposed fix:** Remove the `=== true` short-circuit:

```php
$this->connection()->table('event_outbox')->where('id', $id)
    ->where('status', 'pending')->update(['status' => 'in_flight']);
return $this->connection()->affectedRows() === 1;
```

Then add a regression test that simulates two relayers racing the same row
and asserts only one gets `true`.

### V4 — `DocumentNumberingService` is **not** gapless under concurrency (report 11) — CONFIRMED

`app/Infrastructure/Numbering/DocumentNumberingService.php:106-151`. The
docblock (`:25-28`) claims `SELECT ... FOR UPDATE` on MySQL/Postgres. The code
issues a plain `->where(...)->get()` (lines 114-118). No
`lockForUpdate()`, no raw `FOR UPDATE` suffix, no `INSERT ... ON DUPLICATE
KEY UPDATE current_value = current_value + 1`. Under MySQL REPEATABLE READ
two concurrent `allocate('invoice','2026')` calls happily mint the same
number and both commit (last write wins on the UPDATE, both transactions
return the same `$next`).

Additionally: `fetchOrCreateRow` does a vanilla SELECT → INSERT-if-missing
path (lines 117-142). With a fresh `(series,scope)` and two callers, both
SELECTs miss → both INSERTs race → the second hits the unique constraint
(`document_sequences` unique index, `CreateDocumentSequencesTable.php:87`)
and the raw `DatabaseException` propagates out of `allocate()` — no retry.

And: `allocate()` opens its OWN `transBegin/transCommit` (lines 59/72), so
when invoked from inside `TransactionMiddleware` it just increments the
trans depth counter (verified `BaseConnection.php:826-855`); the inner
"commit" is a no-op until the outer commits. So even if a `FOR UPDATE` were
present, a second request in a parallel HTTP worker reads the *pre-outer-commit*
state and assigns a duplicate.

**Severity:** Critical for any compliance use (invoice/tax sequence
numbering). Document numbers are not gapless.
**Proposed fix sufficiency:** Report 11's "use `INSERT ... ON DUPLICATE KEY
UPDATE current_value = LAST_INSERT_ID(current_value+1)`" is the right shape
on MySQL because it is a single atomic write with no SELECT race. For the
nested-transaction issue, the fix is to drop the inner `transBegin/Commit`
entirely — let the bus middleware own the outer transaction — and require
callers that don't go through the bus to wrap themselves. Or use a
dedicated connection group for sequence allocation so it commits
independently of the business write (typical pattern in ERP systems).

### V5 — Optimistic-lock `affectedRows()` semantics on MySQL (report 05 C4) — CONFIRMED at the framework level, semi-mitigated by the version-always-bumps pattern

`CookieRepository.php:377-396`. Vendored `MySQLi/Connection.php:85-93,196-198`
confirms `$foundRows = false` by default → MySQL connects WITHOUT
`CLIENT_FOUND_ROWS`, so `affectedRows()` returns rows-CHANGED, not rows-MATCHED.

That **is** a footgun in the general sense, but for the Cookie repository
specifically the impact is **muted** because `updateWithOptimisticLock`
always bumps `version` (`:381`) and `updated_at` (`:380,:382`) in the same
write. Even if every other column happens to match the existing row's
values, the version column changes, so MySQL counts at least one row as
changed and `affectedRows()` returns 1.

The exception is the `restore()` path — `CookieRepository.php:266-281` does
NOT bump version on restore and writes only `deleted_at => null`. If the row
was already `deleted_at IS NULL` somehow (impossible by the call-site guard
at `:270` but reachable through direct repository misuse), affectedRows
would lie. Report 05 C3 calls out the missing version bump separately.

The audit's bigger point in report 05 C4 is correct for other
nullable/boolean columns one might add in future; the optimistic-lock check
should be hardened.

**Severity:** Medium today, Critical for any new column that doesn't
participate in version-bump (e.g. `is_active` toggles, a future
`reviewed_at`).
**Proposed fix sufficiency:** Report 05 proposes either enabling
`CLIENT_FOUND_ROWS` or a post-UPDATE `SELECT version`. The cleaner fix is to
add `'foundRows' => true` to the MySQLi config in `app/Config/Database.php`
— it's a connection-time flag, no per-query opt-in needed, and the entire
codebase benefits. Then the existing affectedRows-based logic in
`JobWorker::claim()` and `EventOutboxRelay::claim()` also becomes
unambiguous (today they accidentally work because INSERT/UPDATE always
changes the status column).

### V6 — IdempotencyMiddleware caches AFTER handler runs; concurrent retries double-execute — CONFIRMED

`app/Infrastructure/Http/Middleware/IdempotencyMiddleware.php`:

- `before()` (lines 48-85) does a `lookup()` (SELECT). If the row doesn't
  exist, lets the request through to the handler.
- `after()` (lines 90-134) `INSERT`s the (key, actor) row with the cached
  response.

If two retries arrive between `T0` (first request enters `before()`) and
`T1` (first request finishes `after()` insert), **both** retries miss the
lookup and both run the handler. The unique key
`(id_key, actor_id)` (`CreateIdempotencyKeysTable.php:97-99`) prevents the
**cache row** from being duplicated, but the **business command** has
already executed twice — the only thing the unique key protects is the
cache itself, not the side-effects.

This is the classic check-then-act race. The mitigation requires an
*advisory lock* on the (key, actor) pair around the entire request, or
`INSERT IGNORE` at the START of `before()` claiming the slot with a
placeholder row (status="processing"), then competing retries
SELECT-and-wait for the row to be filled in. Neither pattern is present.

**Severity:** High. This silently violates the documented RFC behavior for
non-idempotent endpoints.
**Proposed fix:** Insert a "processing" sentinel row at the start of
`before()` (an `INSERT IGNORE INTO idempotency_keys (id_key, actor_id,
status, …) VALUES (…)`); use `affectedRows() === 1` to determine "I am the
first" vs "another retry is already in flight." Retries see the sentinel,
poll or 409 immediately. `after()` then transitions sentinel → cached.

### V7 — Read-model rebuild races with live writes — CONFIRMED, *with the report's diagnosis incomplete*

`CookieReadModelProjection.php:74-77,79-111` and `RebuildProjections.php`.
Report 11 (HIGH) identifies TRUNCATE as DDL (implicit commit on MySQL) and
the paginator drift window. Both are real.

Additional issue the report missed: `findPaginated()` orders by
`created_at DESC` (`CookieRepository.php:475`), so any insert during the
rebuild lands on page 1, *shifting every existing row right by one*. With
`perPage=100` and N inserts during rebuild, the same row can appear on
multiple pages OR be skipped entirely (depending on relative timing
between page fetches and inserts). The "duplicates fine due to upsert" claim
in report 11 is only half right — duplicates are fine, **misses** are not,
and misses happen on EVERY concurrent insert.

Compounding: report 11 already identifies that the projection isn't wired
to live events at all (HIGH in projection section), so the only path that
populates `cookie_read_model` is the rebuild itself. Until live events are
wired, every rebuild starts from "empty + paginate all" — the drift window
isn't even bounded by "since last event."

**Severity:** High.
**Proposed fix:** Build to a *shadow* table (`cookie_read_model__rebuild`),
`RENAME TABLE` atomically at the end (MySQL `RENAME TABLE a TO a_old, a_old
TO a_new` is atomic). Alternatively snapshot the source under a single
REPEATABLE READ transaction and stream from the snapshot. Either way, the
current TRUNCATE+walk strategy must not run while writes are accepted.

### V8 — Composite UNIQUE indexes on nullable columns silently allow duplicates (MySQL `NULL != NULL` semantics) — CONFIRMED across multiple tables

Three migrations rely on composite UNIQUE that includes a nullable column:

| Migration | Unique index | Nullable columns |
|---|---|---|
| `2025-01-21-000001_CreateCookiesTable.php:130` | `(tenant_id, name, deleted_at)` | tenant_id (line 51-56), deleted_at (line 120-123) |
| `2026-05-19-200500_CreateSettingsTable.php:82` | `(key_name, tenant_id)` | tenant_id (line 45-49) |
| `2026-05-19-200400_CreateDocumentSequencesTable.php:87` | `(series, scope)` | scope is NOT NULL (verified — not affected) |
| `2026-05-19-200100_CreateIdempotencyKeysTable.php:99` | `(id_key, actor_id)` | actor_id has `default=0` and `null=>false` — not affected |

On MySQL InnoDB, two rows with `tenant_id IS NULL`, `name='Chocolate Chip'`,
`deleted_at IS NULL` do NOT collide. The unique index treats both NULLs as
distinct. Same for `(key_name, tenant_id)` when `tenant_id` is NULL — every
global setting can be inserted multiple times.

Cookie table is the most painful: the `tenant_id` column is never written
by the repository at all (see report 05 C1), so EVERY row has
`tenant_id=NULL`. The composite unique key is decorative.

**Severity:** High. Combined with report 05 C2 (the duplicate-key catch
block at `CookieRepository.php:105-112` will never fire), the system has
NO uniqueness on cookie names today.

**Proposed fix:**
- Settings: drop NULL on `tenant_id`, use `0` for "global" (and document).
- Cookies: same — make `tenant_id` NOT NULL with a sentinel, OR move to a
  functional index (MySQL 8.0+: `UNIQUE ((COALESCE(tenant_id, 0)), name,
  (COALESCE(deleted_at, '1970-01-01')))`). On Postgres use partial
  unique indexes (`WHERE deleted_at IS NULL`).
- Make the choice in code, not via "we'll resolve this when tenants land" —
  the production data being polluted in the meantime is unrecoverable.

### V9 — `CorrelationIdService` static state leaks across rows in long-running workers — CONFIRMED

`app/Infrastructure/Logging/CorrelationIdService.php:33` holds a `private
static ?string $correlationId`. The JobWorker and EventOutboxRelay both
call `CorrelationIdService::set($originalCorrelation)`
(`JobWorker.php:102-104`, `EventOutboxRelay.php:111-113`) but **never call
`clear()`** between rows.

Consequence in a `spark events:relay --watch` process draining 10k events:
- Row 1 has `correlation_id='abc'` → set → all listener logs tagged 'abc' ✓
- Row 2 has `correlation_id=''` (e.g. produced by a path that didn't have a
  request context) → `if (... !== '')` skipped → service still holds 'abc'
  → row 2's logs are tagged 'abc' (WRONG)
- Row 3 has `correlation_id='xyz'` → set → fine again

Same shape for `JobWorker`. The `CorrelationIdProcessor` (which reads from
the service for every log line) inherits stale ids silently.

**Severity:** Medium. Doesn't corrupt data but cripples log triage —
correlation IDs become unreliable in distributed traces, exactly where they
matter most.
**Proposed fix:** `CorrelationIdService::clear()` at the *start* of every
`processRow` (not the end — the worker might log something before set()),
or save/restore the previous id around dispatch via a local variable. The
latter composes better.

### V10 — `--watch` workers have no SIGTERM handling, leave rows orphaned mid-drain — CONFIRMED

`app/Commands/RelayOutboxEvents.php:63-76` and `app/Commands/WorkJobs.php:60-74`
are bare `do { … } while ($watch);`. No `pcntl_async_signals(true)`, no
`pcntl_signal(SIGTERM, …)`. Under systemd/supervisord:
- SIGTERM during `sleep($sleep)` interrupts cleanly — fine.
- SIGTERM during `$worker->drain(...)` aborts mid-iteration. A row that's
  been `claim()`ed (status='in_flight' / 'reserved') but hasn't reached
  `markDelivered`/`markDone` stays in that state forever.

Combined with report 11's finding that there's no reaper for `in_flight`
or `reserved` rows, this means **every SIGTERM in production strands one
or more rows**. After enough restarts the queue is permanently bleeding
slots.

**Severity:** High in any process-supervised deployment.
**Proposed fix:**
1. Add `declare(ticks=1)` or call `pcntl_async_signals(true)` at the top of
   the spark command.
2. Install handler: `pcntl_signal(SIGTERM, fn() => $stop = true)` (capture
   by reference).
3. Loop becomes `while ($watch && !$stop)`.
4. Inside `JobWorker::drain` / `EventOutboxRelay::drain`, check the stop
   flag between rows (requires plumbing a callable or moving the loop into
   the command).
5. Separately, add `events:reap` / `jobs:reap` commands that
   `UPDATE … SET status='pending' WHERE status IN ('in_flight','reserved')
   AND updated_at < NOW() - INTERVAL N MINUTE`.

---

## Disputed / overblown claims

### D1 — Report 11 "claim race is not atomic against multiple workers" (Outbox HIGH) — PARTIALLY DISPUTED

The finding says the unlocked `fetchPending()` SELECT causes wasted DB
round-trips proportional to worker count, and recommends `SELECT … FOR
UPDATE SKIP LOCKED`. That's a valid optimization, but the report's framing
"the docblock claim 'safe under multiple workers' (`:20-21`) is misleading"
overstates: the docblock says **"safe under multiple workers"** in the
sense of no double-delivery (modulo the V3 bug — once V3 is fixed, the
claim is true). It does NOT promise no wasted work. The fix is desirable
but the existing semantics are correct. Frame as Medium, not High.

### D2 — Report 10 H2 "Audit and Transaction middlewares may not share the connection" — TECHNICALLY CORRECT BUT LOW-RISK TODAY

Both call `Database::connect()` with no group; CI4 caches connections by
group name (`'default'` for both). The "implicit shared default connection"
is actually a documented invariant of CI4's connection cache, not an
accident. The risk only materializes if someone later wires a non-default
group into one but not the other. Worth a comment, but framing as HIGH is
generous. The actually-high concurrency item here is V1 above.

### D3 — Report 11's optimistic-lock concern about CookieRepository under MySQL — OVERSTATED IN PRACTICE

See V5. The audit treats `affectedRows() === 1` as broken on the optimistic
lock path. In the Cookie code path specifically, the version column always
bumps, so MySQL's rows-changed always reports ≥1 when the WHERE matched.
The audit's prescription (`CLIENT_FOUND_ROWS` or post-UPDATE SELECT) is
still recommended as future-proofing, but the immediate "false positive on
no-op writes" failure mode is not currently reachable on this codebase.
Reduce severity from Critical to Medium until a non-version-bumping update
exists.

---

## Concurrency hazards the round-1 reports MISSED

### M1 — `JobWorker::onFailure` reads `maxAttempts` from the worker-claimed row but the comparison uses stored attempts, leading to lost retries if the row was updated by an admin mid-flight

`JobWorker.php:113,162-165`: `$attempts` is read from the row at claim
time. If an operator manually `UPDATE jobs SET attempts=0 WHERE id=?` to
retry a failing job while the worker is mid-handler, the worker's
`onFailure` writes `attempts=$attempts+1` based on the stale value,
overwriting the admin's reset. Not a typical scenario but worth a row-level
UPDATE-WHERE-attempts=? gate.

### M2 — `DocumentNumberingService::peek()` reads outside any transaction, can return a value that is being mutated

`DocumentNumberingService.php:86-100`. The audit flags this Medium-ly but
under-emphasizes that two concurrent `peek()` calls *between* an `allocate()`
and its commit can return the *new* value to one caller and the *old* value
to another, depending on the isolation level. With READ COMMITTED both see
old; with REPEATABLE READ inside a different connection's transaction one
might see new. Document or remove `peek()` — it's a footgun masquerading as
a convenience.

### M3 — `IdempotencyMiddleware::actorId()` constructs a **new** `ActorResolver` per request (`IdempotencyMiddleware.php:152`)

This bypasses the configured `actorResolver` service wired in `Config\Services`,
so any future change to the resolver (e.g. caching by request, adding
tenant resolution) is silently bypassed by the idempotency path. Not a
race per se, but a divergence-by-construction that will produce
"the cached row's actor_id doesn't match the request's actor_id"
mismatches the moment the resolver becomes non-trivial.

### M4 — `EventOutboxRelay::processRow` does NOT save/restore `CorrelationIdService` before mutating it (`:111-113`)

Combined with V9 above, the relay's correlation_id manipulation
permanently overwrites whatever was set by the surrounding context (e.g. a
spark command's startup correlation_id), with no restoration path. After
the first row is processed the parent process's "outer" correlation_id is
gone for the rest of its life.

### M5 — `JobQueue::push` returns `(int) $this->connection()->insertID()` after a write, but the auto-increment can be stale if a concurrent insert ran on the SAME connection between push and `insertID()` call

`JobQueue.php:62-77`. The `insert()` and `insertID()` are two separate
statements; CI4 doesn't snapshot the insert id. On a shared connection
under MySQL `insertID()` returns the LAST insert on the connection — race
between two `push()` calls from the same request (e.g. a handler that
pushes two jobs) is harmless because they execute sequentially on a single
PHP request, but if a connection is shared across `pcntl_fork()`-style
workers or via persistent connections, this is unsafe. Acceptable today;
flag for the day persistent connections are enabled.

---

## Verdict per hazard

| # | Hazard | Round-1 verdict | This review's verdict | Proposed fix sufficient? |
|---|---|---|---|---|
| 1 | Audit insert trips auto-rollback | Critical (10 C1) | Critical — confirmed | Partial — options (b)/(c) work; (a) doesn't cover DBDebug-throws case |
| 2 | EventDispatcher swallows → tx contract lies | Critical (10 C2) | Critical — confirmed; relay retry logic is dead because of it | Underspecified — needs `dispatchOrFail()` *and* `dispatch()` |
| 3 | EventOutboxRelay claim() race admits double-dispatch | High (11) | **Critical** — `$affected === true` short-circuits, RHS is dead | Yes — drop `=== true` |
| 4 | DocumentNumberingService not gapless | Critical (11) | Critical — confirmed; also nested-tx no-op | Use `INSERT … ON DUPLICATE KEY UPDATE … LAST_INSERT_ID(…)`; drop inner transBegin |
| 5 | CookieRepository affectedRows() | Critical (05 C4) | **Medium** today (version always bumps); Critical for any new no-bump column | Set `foundRows=true` in `Config/Database` |
| 6 | Idempotency caches AFTER handler | Not in audit | High — new finding | Sentinel-row pattern via INSERT IGNORE |
| 7 | Read-model rebuild vs live writes | High (11) | High — confirmed, with ordering-skew nuance the audit missed | Build-to-shadow + RENAME |
| 8 | NULL semantics in composite UNIQUE | Partial (05 C1/C2) | High across cookies+settings | NOT NULL with sentinel, or functional/partial index |
| 9 | CorrelationIdService static state | Medium (11) | Medium — confirmed | Save/restore around dispatch |
| 10 | --watch SIGTERM | Low (11) | High in supervised deployments | pcntl handler + reaper commands |

Severity upgrades: V3 (High→Critical), V5 (Critical→Medium), V8 (Partial→High),
V10 (Low→High). New hazards: V6, M1–M5. The audit's overall "not
production-ready" verdict is reinforced — concurrency is the dominant risk
surface.

## Recommended ordering (fix sequence)

1. **V3** (remove `=== true` from outbox claim) — one-line fix, prevents
   double-dispatch the moment a second worker starts.
2. **V8** (NOT NULL the nullable composite-unique columns / use functional
   index) — production data starts being correct.
3. **V4** (`INSERT … ON DUPLICATE KEY UPDATE` in numbering, drop inner
   `transBegin`) — required before any compliance-bound document type
   ships.
4. **V1** (move audit out of business transaction, or sibling connection) —
   removes the audit-table-takes-down-everything failure mode.
5. **V2** + V10 + V7 in parallel — split dispatch into fail/swallow modes,
   add SIGTERM handlers + reapers, switch projection rebuild to shadow
   table.
6. **V6** — sentinel-row idempotency.
7. **V9** + **V5** + M1–M5 — hygiene.

Total of 10 verified + 5 new = 15 concurrency hazards. None of them is
isolated; several compound (V2 hides V3 retry failures; V10 hides V1's
audit failures; V8 hides V4's eventual unique-key fallback).
