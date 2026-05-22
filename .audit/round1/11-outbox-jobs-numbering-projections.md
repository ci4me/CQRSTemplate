# 11 — Outbox / Jobs / Numbering / Projections

## Files audited

- `app/Infrastructure/Outbox/EventOutboxWriter.php`
- `app/Infrastructure/Outbox/EventOutboxRelay.php`
- `app/Infrastructure/Jobs/JobHandlerInterface.php`
- `app/Infrastructure/Jobs/JobQueue.php`
- `app/Infrastructure/Jobs/JobWorker.php`
- `app/Infrastructure/Numbering/DocumentNumberingService.php`
- `app/Infrastructure/Projections/ProjectionInterface.php`
- `app/Infrastructure/Projections/ProjectionRegistry.php`
- `app/Commands/RelayOutboxEvents.php`
- `app/Commands/WorkJobs.php`
- `app/Commands/RebuildProjections.php`
- `app/Database/Migrations/2026-05-19-200300_CreateEventOutboxTable.php`
- `app/Database/Migrations/2026-05-19-200400_CreateDocumentSequencesTable.php`
- `app/Database/Migrations/2026-05-19-200600_CreateJobsTable.php`
- (context) `app/Infrastructure/Bus/Middleware/TransactionMiddleware.php`, `app/Infrastructure/Bus/EventDispatcher.php`, `app/Domain/Cookie/Projections/CookieReadModelProjection.php`, `app/Config/Services.php`

## Findings (group by module)

### Outbox

- **CRITICAL — Same-transaction guarantee not actually enforced.** `EventOutboxWriter::__construct` (`EventOutboxWriter.php:29`) takes an *optional* `?BaseConnection`; when null it falls back to `Database::connect()` (`:109`). `TransactionMiddleware` also resolves its own `Database::connect()` (`TransactionMiddleware.php:42`). With CI4 these *usually* return the same shared default connection so the INSERT lands inside the transaction — but the contract is implicit and silently broken if a handler is constructed with a non-default group, a test wire passes a different connection, or a writer is composed with `new EventOutboxWriter($otherDb)`. The class docblock claims "atomicity" (`:14-16`) yet there is no assertion or invariant enforcing it. Add a Services factory that *always* reuses the bus connection or pass `$db` through explicitly.
- **CRITICAL — Writer is dead code.** `EventOutboxWriter` has zero call sites (`grep` across `app/` finds only the class itself). No Services factory exists for it. Aggregates currently dispatch events synchronously through `EventDispatcher` (see `TransactionMiddleware:22` — "the simplest approximation of an outbox until we add one"). The outbox is plumbed in DB and relay only; nothing actually writes to `event_outbox`. The relay therefore drains an always-empty table. Either remove this until wired, or wire it in the aggregate-persistence path. The audit's "same transaction" claim is moot until that happens.
- **HIGH — Claim race is not atomic against multiple workers.** `EventOutboxRelay::fetchPending` (`EventOutboxRelay.php:80-98`) reads N rows with no row-lock; only the subsequent `UPDATE WHERE id=? AND status='pending'` (`:142-151`) is "atomic". With M workers and a batch of N each, every worker reads the same N rows and then issues N UPDATEs each; M-1 of them lose the race per row. Functionally OK (no double dispatch) but wasted DB round-trips proportional to worker count, and the docblock claim "safe under multiple workers" (`:20-21`) is misleading — it should say "no double-delivery; throughput degrades with worker count". A `SELECT ... FOR UPDATE SKIP LOCKED` (MySQL 8 / Postgres) or per-worker `UPDATE ... ORDER BY ... LIMIT N RETURNING id` would be correct.
- **HIGH — `claim()` return is broken on most drivers.** `EventOutboxRelay::claim` (`:142-151`) returns `$affected === true || $this->connection()->affectedRows() === 1`. CI4 `update()` returns `bool`, never an integer row count, and `true` is returned even when zero rows match (UPDATE succeeded with no targets). So when *another worker has already grabbed the row*, this method still returns `true` for the first branch in many driver paths, and the relay proceeds to dispatch it again — defeating the entire claim. The `affectedRows() === 1` half is the only correct gate; the `=== true` short-circuit must be removed.
- **HIGH — `in_flight` rows have no recovery path.** Once a row is flipped to `in_flight`, only `markDelivered`, `markFailed`, or `onDispatchFailure` move it. If the relay process crashes (or `dispatcher->dispatch` segfaults / OOMs) between `claim()` and either terminal branch, the row stays `in_flight` forever — no reaper, no `reserved_at` style timeout column on the outbox migration (`CreateEventOutboxTable.php:67-72`), no janitor command. Add `claimed_at` + a sweep task ("reset `in_flight` rows older than X back to pending").
- **HIGH — Reflection rehydrate breaks silently on constructor signature changes.** `EventOutboxRelay::rehydrate` (`:153-189`) matches JSON payload keys to constructor parameter *names* (`:170-171`). If an event is renamed, gets a new required parameter without a default, or has a parameter renamed (e.g. `cookieId` → `id`), every queued row of that class throws "missing required parameter" and is marked `failed` (`:122`). There is no migration story for event-schema evolution and no versioning column on the row. Add `event_version` to the table and a schema-aware deserialiser, or document an explicit "rename = drain old rows first" policy.
- **MEDIUM — Payload serialisation is lossy for value objects.** `EventOutboxWriter::serialiseEvent` (`:84-94`) JSON-encodes `get_object_vars($event)`. A readonly event with a `Money` or `Email` value object property serialises as `{}` or a nested object representation that rehydrate cannot pass back through a typed constructor parameter. Existing Cookie events use scalars/arrays so this is latent; the docblock acknowledges it (`:21-22`) but offers no enforcement. Add a `toArray()` requirement to a `DomainEventInterface` and assert it at write time.
- **MEDIUM — `correlation_id` leaks across rows in the relay.** `EventOutboxRelay::processRow` sets the static `CorrelationIdService` (`:111-113`) but never resets it. The next row inherits the previous row's correlation_id if its own is empty. Save/restore the previous id around dispatch.
- **MEDIUM — Backoff array off-by-one.** `MAX_ATTEMPTS = 6` (`:32`); on the 6th failure `$nextAttempt = 6` and `$maxed = true`, so attempts 0..4 actually backoff and attempt 5 fails-permanent. The "6 h, 24 h" tier in the docblock (`:25`) is effectively unreachable: `BACKOFF_SECONDS[min(5, 5)] = 86400` is used once, the 21600 (6 h) is the previous-to-last. Either compare `>` instead of `>=`, or trim the array, or document that the last advertised tier is never applied.
- **MEDIUM — Listener exceptions are swallowed inside `EventDispatcher`.** `EventDispatcher::dispatch` (`EventDispatcher.php:90-104`) catches every `\Throwable` from listeners. The relay calls `dispatcher->dispatch($event)` (`EventOutboxRelay.php:133`) inside a try/catch — but the dispatcher itself never re-throws, so a failing listener is logged and the relay marks the row *delivered*. The outbox cannot detect listener failure. The relay's retry logic (`onDispatchFailure`) is unreachable in practice. Either bubble listener errors when relaying, or accept that the outbox is fire-and-forget and remove the retry/backoff code.
- **MEDIUM — `failed` rows have no documented replay path.** Once `status='failed'`, nothing in code or `RelayOutboxEvents.php` revisits them. No `events:replay` command, no doc in the command's docblock. Operators must `UPDATE event_outbox SET status='pending', attempts=0 WHERE id=...` manually.
- **LOW — `--watch` mode never sleeps when work is found.** `RelayOutboxEvents::run` (`:73-75`) only sleeps if `processed === 0`. Under sustained load the loop spins flat-out (which is correct), but on small/steady inflow this means hundreds of empty selects per second once the queue drains to <batch. Acceptable; flag for ops awareness.
- **LOW — No `SIGTERM` handling in `--watch`.** Both `RelayOutboxEvents` (`:63-76`) and `WorkJobs` (`:60-74`) use a bare `do/while` loop. Under systemd/supervisord a `SIGTERM` will interrupt mid-`sleep()` (fine) but if it arrives mid-`drain`, the in-flight row stays `in_flight` (see HIGH above). Install `pcntl_signal` handlers and exit at next loop boundary.

### Jobs

- **HIGH — Same claim race as outbox; same broken `affectedRows` semantics partially mitigated.** `JobWorker::fetchPending` (`JobWorker.php:73-92`) reads under no lock; `claim()` (`:129-142`) returns `$this->connection()->affectedRows() === 1`. Correct (cleaner than the outbox), but the unlocked SELECT still wastes work proportional to worker count.
- **HIGH — `reserved` rows have no recovery.** `reserved_at` exists on the migration (`CreateJobsTable.php:86-89`) and the docblock advertises "stuck-job recovery" (`:27`), but no command, cron, or scheduled task ever resets a stuck `reserved` row to `pending`. A worker crash leaves the job stuck forever. Required: a `jobs:reap` command (e.g. "reservations older than N min → pending, attempts++").
- **HIGH — `maxAttempts` boundary is off-by-one in producer vs worker.** `JobQueue::push` validates `maxAttempts >= 1` (`JobQueue.php:53-55`), stored as-is. `JobWorker::onFailure` (`:162-185`) computes `$nextAttempts = $attempts + 1; $exhausted = $nextAttempts >= $maxAttempts`. With `maxAttempts=1`, the very first failure exhausts (1 ≥ 1) — that's actually correct (one try total). But the *docblock* (`JobQueue.php:48`) advertises "default 5", meaning 5 attempts; in practice the worker runs the handler 5 times before failing, which is consistent. OK in current shape — flag for unit test coverage to lock the semantics.
- **HIGH — Zero-arg constructor handler resolution is a footgun.** `JobWorker::resolveHandler` (`:144-160`) calls `new $class()`. Any handler that needs DI (a repository, a mailer, a logger) must service-locate inside `handle()`. This is brittle, untestable, and explicitly noted as such in the docblock (`:24-26`) but offered no escape hatch. Use the CI4 container or a small handler-registry factory; otherwise non-trivial domains will get `Config\Services::*` calls scattered through job handlers.
- **HIGH — No job handler registry / discovery.** Nothing validates at startup that `handler_class` is a real class or implements `JobHandlerInterface`. A typo or removed handler turns into a per-attempt `RuntimeException` (`:147`) that eats the entire retry budget before failing. Add registration at boot and validate-on-push in `JobQueue::push`.
- **MEDIUM — Producer transaction coupling unsafe by default.** `JobQueue::__construct` (`JobQueue.php:35-37`) takes an optional connection just like the outbox writer. The docblock promises "push runs inside the surrounding bus transaction" (`:21-22`), but Services factory `Services::jobQueue()` (`Services.php:459-465`) passes nothing, so it falls back to `Database::connect()`. Same fragile assumption as the outbox: works *if* the bus uses the default group.
- **MEDIUM — `payload` JSON has no schema versioning.** Same critique as the outbox: changing a job handler's payload shape invalidates queued rows with no migration path. Add `payload_version` or pin handler signatures.
- **MEDIUM — `--watch` lacks `SIGTERM` handling.** Same as outbox: an interrupt mid-`drain` leaves a `reserved` row stuck. Compounds with the missing reaper above.
- **MEDIUM — `correlation_id` leaks across jobs.** Same pattern as the relay (`JobWorker.php:101-104`) — set, never restored. Bleeds into subsequent jobs in the same batch.
- **LOW — `JobHandlerInterface::handle` has no return / no retry signal.** Handlers must throw to signal failure; there's no way to say "skip" or "succeed-with-warning". Acceptable but document it.

### Document numbering

- **CRITICAL — Not gapless under concurrency.** `DocumentNumberingService::fetchOrCreateRow` (`:106-151`) does a plain `SELECT ... FROM document_sequences WHERE series=? AND scope=?` (`:114-118`) *without* `FOR UPDATE`. The docblock explicitly promises "SELECT ... FOR UPDATE on MySQL/Postgres" (`:26-27`); the code does not implement it. Under MySQL InnoDB REPEATABLE READ, two concurrent `allocate()` calls each:
  1. open a transaction (`:59`),
  2. read `current_value = N`,
  3. compute `N+1`,
  4. UPDATE to `N+1`.
  The first commit wins; the second UPDATE re-applies `N+1` (lost update). Both transactions return the same formatted number. This is the exact failure mode `FOR UPDATE` was meant to prevent. Two callers minting the same invoice number is a compliance issue (tax authorities require gapless, unique). Required fix: either `$builder->lockForUpdate()` / a raw `SELECT ... FOR UPDATE`, or `INSERT ... ON DUPLICATE KEY UPDATE current_value = current_value + 1` returning `LAST_INSERT_ID(current_value)` (atomic on MySQL).
- **HIGH — Insert-then-update race when row doesn't exist.** `fetchOrCreateRow` (`:117-142`) does SELECT, falls through to INSERT if missing. Two callers for a brand-new (series, scope) both miss the SELECT and both try to INSERT. The unique key (`CreateDocumentSequencesTable.php:87`) makes the second INSERT fail with a constraint violation, which propagates out as an unhandled exception (no retry in the service). Replace with `INSERT IGNORE` or `INSERT ON DUPLICATE KEY UPDATE id=id` then re-SELECT.
- **HIGH — Composes badly inside the bus transaction.** Docblock says "composes fine inside one: the locking SELECT is still inside the outer transaction" (`:32-34`). But `allocate()` calls `transBegin/transCommit` itself (`:59,:72`). CI4's `transBegin` is a nested-counter, not a true SAVEPOINT — calling it inside an outer transaction increments the depth; `transCommit` decrements. So the allocator's "commit" doesn't actually commit until the *outer* commits — meaning a second concurrent caller in a separate request sees the *old* value, allocates the same number, and only one of the two commits wins (with both believing they got distinct numbers). Documenting "single allocate per command" alongside `FOR UPDATE` would mitigate.
- **MEDIUM — `peek()` is not transactionally meaningful.** `peek()` (`:86-100`) returns `current_value` from a snapshot read with no lock. Useful only for display; document that it's racy.
- **MEDIUM — `padLength` upper bound of 20 silently truncates.** `BIGINT UNSIGNED` max is 20 digits; `padLength=20` with a value of `1` returns 19 zeros + "1" = 20 chars, fits VARCHAR(255) in any consumer but the docblock doesn't mention the rationale.
- **LOW — No reset / rollover hook.** Common requirement (annual fiscal-year reset) is left as "create a new (series, scope)" but there's no helper to predict or set the next value for a new scope. Document.

### Projections

- **HIGH — `ProjectionRegistry` is dead code.** No `Services` factory, no caller anywhere in `app/`. `RebuildProjections::resolveProjection` (`RebuildProjections.php:80-90`) hardcodes `new CookieReadModelProjection(...)` and bypasses the registry entirely. The CookieReadModelProjection's `apply()` is never wired to the `EventDispatcher`, so the read model is **never updated by live events** — the only path that touches `cookie_read_model` is `projections:rebuild`. This is a load-bearing miss given the migration introduces a denormalised read table on the assumption that handlers stream into it.
- **HIGH — Rebuild is not safe against concurrent writes.** `CookieReadModelProjection::truncate()` (`:74-77`) issues a `TRUNCATE` (DDL — implicit commit on MySQL; bypasses transactions). Between TRUNCATE and the end of `rebuildFromSource`'s paginated walk (`:79-111`), any live mutation routes through the (broken) projection wiring above and is lost. Even *with* the wiring, a Cookie created mid-rebuild that the paginator already passed never lands in the table. Document a strategy: take projection offline, or build to a shadow table and rename.
- **HIGH — Rebuild reads through paginated repository — drift window.** `rebuildFromSource` paginates the *current* state of `findPaginated(includeInactive: true)` (`:85-90`). With `perPage=100`, between pages 1 and 2 an aggregate that lived on page 1 can move to page 2 (or vice versa) under concurrent writes, causing duplicate or missed projections. With idempotent upsert by `cookie_id` the dup case is fine; the *miss* case is not. Use a snapshot read or an immutable id-ordered cursor.
- **MEDIUM — `apply()` "idempotent" claim is conditional.** `onCreated` / `onUpdated` / `onRestored` (`:113-152`) re-read the aggregate from `repository->findById()` — replaying an old `CookieCreatedEvent` after the aggregate has since been updated will project the *current* state, not the state at event time. That's an upsert, not a true event-sourced replay. Document explicitly: replay regenerates from current source, not from event history.
- **MEDIUM — `ProjectionRegistry::register` captures `$projection` in a static closure (`ProjectionRegistry.php:40-43`).** Closure is `static`, so no `$this` leak; it captures one object reference. Acceptable. The leak risk is only if `$projection` itself holds a reference back to `ProjectionRegistry` (it does not). No cycle. Flag as low/no risk; the comment in the audit prompt suspected leaks — there are none here.
- **MEDIUM — `truncate()` on MySQL is `DELETE` cascading or `TRUNCATE` — depends on driver.** CI4's `$builder->truncate()` issues `TRUNCATE TABLE` on MySQL. On a table with FK references from other reporting tables (none today) this would fail. Document that the projection table must be a leaf.
- **MEDIUM — Stock-changed event uses payload directly; created/updated re-read from repo.** Inconsistent: `onStockChanged` (`:153-167`) trusts `$event->newStock`; the others reload from the write store (`:115,:124,:147`). A delayed replay of `onStockChanged` therefore writes a stale value if a later Updated event already changed stock. Decide one model.
- **LOW — `rowFor()` hardcodes `tenant_id => null`** (`CookieReadModelProjection.php:203`). Fine for now; flag for the day multi-tenant lands.
- **LOW — `RebuildProjections` hardcodes the `cookie` projection (`:85-87`).** Every new domain has to edit this command. Wire through the (currently unused) `ProjectionRegistry`.

## Concurrency analysis

| Concern | Outbox | Jobs | Numbering | Projections |
|---|---|---|---|---|
| Atomic claim under concurrent workers | UPDATE-WHERE-status='pending'; correct semantically but `claim()` truthiness gate (`EventOutboxRelay.php:150`) lets duplicates through on bool-returning drivers | UPDATE-WHERE-status='pending'; correct via `affectedRows()===1` (`JobWorker.php:141`) | No `FOR UPDATE`; lost-update race | N/A (no live write path) |
| Double-dispatch / double-execution | YES possible (see above) | No | N/A | YES (rebuild + future live path) |
| Stuck "in-progress" recovery | Missing reaper for `in_flight` | Missing reaper for `reserved`; column `reserved_at` exists unused | N/A (synchronous) | TRUNCATE then walk; drift window |
| Same-transaction-as-business-write | Implicit shared default connection only; brittle | Same | Self-starts a transaction; nests counter inside bus tx (no SAVEPOINT) | N/A |
| Backoff / retry overflow | Off-by-one in MAX_ATTEMPTS array indexing | Symmetric backoff array; OK | N/A | N/A |
| `SIGTERM` mid-loop | No handler; row stuck `in_flight` | No handler; row stuck `reserved` | Single-shot CLI | Single-shot CLI |

## Verdict

**Not production-ready.** Four CRITICAL issues across the four modules:

1. `EventOutboxWriter` is unwired — the entire outbox guarantee is aspirational; events still dispatch synchronously through the bus transaction (`TransactionMiddleware.php:22` admits this).
2. `EventOutboxRelay::claim()` truthiness gate (`:150`) admits double-dispatch.
3. `DocumentNumberingService` is *not* gapless: the documented `SELECT FOR UPDATE` is missing (`:114-118`); under concurrency two callers will mint duplicate document numbers.
4. `ProjectionRegistry` is never instantiated; the Cookie read model is only ever populated by manual `projections:rebuild`. Live writes do not project.

HIGH-priority items add up to: missing reapers for `in_flight` / `reserved`; insert-then-update races on first-time sequence creation; rebuild concurrency drift; no DI path for non-trivial job handlers; reflection rehydrate brittle to event-schema evolution. These compound under multi-worker setups.

Recommended sequence before relying on any of these in production:
1. Add a `FOR UPDATE` (or `INSERT ... ON DUPLICATE KEY UPDATE ... LAST_INSERT_ID`) in `DocumentNumberingService::fetchOrCreateRow`.
2. Wire `EventOutboxWriter` into the persistence path (or remove); replace synchronous-in-transaction dispatch with read-back via the relay.
3. Fix `EventOutboxRelay::claim()` to gate on `affectedRows() === 1` only.
4. Wire `ProjectionRegistry` from `CookieServiceProvider::boot()` into the dispatcher; switch the rebuild command to look the projection up from the registry.
5. Add `events:reap` / `jobs:reap` commands plus SIGTERM handlers in `--watch` loops.
6. Replace reflection rehydrate with an explicit `DomainEventInterface::toArray() / fromArray()` contract.
