# RE-AUDIT 18 — MySQL / Database Layer (Round 3)

**Reviewer:** codeigniter4-specialist
**Date:** 2026-05-23
**Original audit:** `.audit/round3/18-mysql-database.md`
**Original severity counts:** CRITICAL 3 | HIGH 10 | MEDIUM 13 | LOW 6 | INFO 2
**Original verdict:** NOT-READY

This re-audit verifies which round-3 findings have been addressed by the
open epic PRs (#30 E01, #31 E03, #38 E12.5, #41 E11) and which remain
open pending later epics (E09 money, E12 outbox hardening, future
audit/GDPR work).

## Re-audit method

- Walked every finding in `18-mysql-database.md`.
- For each, inspected the responsible PR diff via `gh pr diff <PR#>`
  and/or the working tree.
- Tagged each finding CLOSED / PARTIAL / OPEN with rationale and the
  PR that addresses (or fails to address) it.

## Finding-by-finding

### F-C1 — [CRITICAL] No `sessionVariables` in Config/Database.php — **CLOSED by PR #31 (E03)**

- `app/Config/Database.php` now declares `sessionVariables` on `$default`,
  the new `$mysql_ci` group, AND `$tests` (carried for the MySQL CI lane).
- Pinned: `sql_mode = STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,
  ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`,
  `transaction_isolation = READ-COMMITTED`, `time_zone = +00:00`,
  `character_set_connection = utf8mb4`, `collation_connection = utf8mb4_unicode_ci`.
- A subclassed `App\Infrastructure\Database\MySQLi\Connection` overrides
  `connect()` so the envelope is re-applied on every (re)connect — survives
  idle-timeout reconnects (the issue REVIEW-ci4 #3 flagged).
- `tests/Integration/Database/ConnectionEnvelopeTest.php` asserts both the
  config shape (every lane) and the live MySQL session (MySQL lane only,
  skipped on SQLite).

### F-C2 — [CRITICAL] `strictOn=true` is not pinned sql_mode — **CLOSED by PR #31**

`strictOn=true` is retained as belt-and-braces; `sessionVariables.sql_mode`
is now the authoritative pin and runs on every connect. The
`STRICT_TRANS_TABLES + …` superset makes the truncation pathway (F-O8) an
ERROR rather than silent.

### F-C3 — [CRITICAL] No isolation level pinned — **CLOSED by PR #31**

`transaction_isolation = READ-COMMITTED` is pinned. Matches the optimistic-
lock-by-affected-rows semantics and aligns with Postgres for portability.

### F-T1 — [CRITICAL] Tests forced to in-memory SQLite — **CLOSED by PR #30 (E01)**

- `phpunit.xml.dist` no longer `force="true"` overrides
  `database.tests.DBDriver = SQLite3` — the env block is now a soft default
  that the CI MySQL lane can override.
- `.github/workflows/ci.yml` adds a matrix `db: [sqlite, mysql]` running
  the SAME suite against MySQL 8.0.36 (image tag pinned). Coverage from
  both lanes is merged via `phpcov merge` and the ≥90% gate applies to
  the union.
- `docker-compose.yml` + `Makefile` (`make test-mysql`) provide a local
  reproduction path that mirrors the CI MySQL lane (port 33060→3306).
- A `migrate up / rollback / up` smoke step runs on the MySQL lane to
  catch irreversible schema diffs.
- Note: `ConnectionEnvelopeTest` runtime-verification path is still gated
  by the MySQL lane; SQLite-lane behaviour is unchanged for those bits.

### F-T2 — [HIGH] `database.tests.DBPrefix = 'db_'` divergence — **CLOSED by PR #30**

`'DBPrefix' => 'db_'` in `Config/Database.php::$tests` is changed to `''`
to match `.env`, `.env.example`, the phpunit `<env>` block, and the
migrations (none of which use the prefix). The "DO NOT REMOVE FOR CI DEVS"
comment is removed and replaced with a round-3 reference docblock.

### F1 — [HIGH] No ENGINE / ROW_FORMAT / table charset on any migration — **OPEN**

Not addressed by any landed PR. Even the new `processed_events` migration
in PR #38 explicitly relies on connection defaults rather than passing a
table-options array. Migrations remain dependent on whatever the
connecting server's `default_storage_engine` / `character_set_server` is.

### F2 — [HIGH] `DECIMAL(10,2)` for price — **OPEN — owned by E09**

REMEDIATION-PLAN lists E09 ("Multi-currency: CookiePrice bounds +
DECIMAL→price_minor migration + seeder rewrite") as Phase 2 work. No PR
for E09 in the open list.

### F3 — [MEDIUM] `is_active` TINYINT(1) without CHECK — **OPEN**

Unchanged. No CHECK constraint added; no migration touches `is_active`
column type.

### F4 — [MEDIUM] `cookies.tenant_id` nullable (UNIQUE-NULL soft enforcement) — **OPEN**

Unchanged; the docblock caveat is still load-bearing.

### F5 — [MEDIUM] `event_outbox.payload` is LONGTEXT not JSON — **OPEN — owned by E12**

E12 ("Outbox table hardening …") not yet shipped. Same for the other
LONGTEXT/TEXT JSON-ish columns flagged here.

### F6 — [LOW] `cookies.description TEXT NULL` — **OPEN**

Unchanged.

### F7 — [LOW] `users.role` / `users.status` ENUM columns — **OPEN**

Unchanged; the duplicate-with-RBAC concern remains.

### F8 — [INFO] `audit_log.payload_digest` is digest-only — **OPEN (documentation only)**

Unchanged; defensible privacy-first design, no docs cross-reference added.

### F-I1 — [HIGH] No leasing index on `event_outbox` — **OPEN — owned by E12**

### F-I2 — [CRITICAL] No `event_uuid` UNIQUE on `event_outbox` — **OPEN — owned by E12**

PR #38 (E12.5) added a *handler-side* `processed_events` table for
listener-level at-most-once dedup, but the *outbox-side* idempotency key
(`event_uuid` column + UNIQUE index on `event_outbox`) is still missing.
The `processed_events` migration explicitly defers to E12 ("once E12 ships
the two epics pair into end-to-end at-most-once" — see the docblock on
`Services::processedEventStore()`). So this CRITICAL remains open; the
auto-bind in EventDispatcher is intentionally not wired until E12 lands.

### F-I3 — [HIGH] No claim semantics / FOR UPDATE SKIP LOCKED on relay — **OPEN — owned by E12**

### F-I4 — [MEDIUM] `event_outbox` has no `tenant_id` — **OPEN — owned by E12**

### F-I5 — [MEDIUM] No retention / partitioning on `audit_log` / `event_outbox` — **OPEN**

No PR adds a retention sweeper or partition scheme.

### F-I6 — [MEDIUM] Soft-delete predicate index missing — **OPEN**

`cookies.deleted_at` still has a single-column index; no composite
`(deleted_at, is_active, id)` covering index.

### F-I7 — [LOW] No FULLTEXT on `cookies.name` — **OPEN**

PR #41 (E11) addresses LIKE-escape correctness in the repository, but
no FULLTEXT index is added.

### F-S1 — [HIGH] Composite UNIQUE allows two active rows under concurrent INSERTs — **PARTIAL — PR #41 (E11) tightens app-side guard**

PR #41 ("Cookie repository hygiene — `existsByName` …") improves the
application-level pre-check by passing the VO through the port and
escaping LIKE inputs, but the underlying DB-level race window is still
present: MySQL still treats `(NULL,NULL,NULL)` as distinct in the
composite UNIQUE. The generated-column workaround the audit recommended
is not implemented.

### F-S2 — [MEDIUM] No CHECK on `(deleted_at IS NULL) <=> (deleted_by IS NULL)` — **OPEN**

### F-S3 — [MEDIUM] No `restored_at` / `restored_by` — **OPEN**

PR #41 does add a `purge()` repository method (closes part of F-G1) but
does not introduce restore audit columns.

### F-FK1 — [HIGH] No FKs on `cookies.created_by` etc. — **OPEN**

### F-FK2 — [MEDIUM] `event_outbox` / `audit_log` aggregate_id type loose — **OPEN**

### F-FK3 — [LOW] CASCADE on `notifications.user_id` (GDPR concern) — **OPEN**

### F-O1..F-O7 — duplicates of F-I1/F-I2/F-I3/F-I4/F5 — **OPEN — owned by E12**

### F-O8 — [CRITICAL] `event_outbox.status VARCHAR(16)` truncates `'unsupported_schema'` — **PARTIAL — PR #31 makes it a hard ERROR but column not widened**

With `sql_mode = STRICT_TRANS_TABLES,...` pinned by PR #31, the
truncation flips from silent to a hard write error — so the
data-correctness bug becomes a visible failure rather than a silent
dead-letter loop. The actual column width (`VARCHAR(16)`) is still
unchanged; the column itself is widened by E12 (not yet landed). Net
effect: production safety improves immediately when PR #31 merges, but
the relay will start raising on every `markUnsupportedSchema()` call
until E12 widens the column. **This is a regression risk to track in
the E12 PR.**

### F-A1 — [MEDIUM] `audit_log.actor_id` sentinel, no FK, undocumented — **OPEN**

### F-A2 — [MEDIUM] `audit_log` lacks `entity_type` / `entity_id` — **OPEN**

### F-A3 — [LOW] `audit_log.duration_ms` DECIMAL(10,2) cap — **OPEN (acknowledged, no fix needed)**

### F-G1 — [HIGH] No hard-delete path for `cookies` — **CLOSED by PR #41 (E11)**

PR #41 adds `CookieRepositoryInterface::purge(int $id): bool` plus the
concrete implementation on `CookieRepository`, with the docblock making
the GDPR escape-hatch role explicit, and integration-test coverage
(`test_purge_hard_deletes_row`, `test_purge_also_removes_soft_deleted_row`,
`test_purge_returns_false_for_non_existent_row`,
`test_purge_logs_and_rethrows_when_model_throws`). Permission gating is
deferred to the command/controller layer — acceptable for a repo-level
escape hatch.

### F-G2 — [MEDIUM] PII columns not annotated — **OPEN**

### F-G3 — [MEDIUM] No retention / auto-purge on outbox / audit / login_attempts — **OPEN**

### F-G4 — [LOW] No encryption-at-rest documentation — **OPEN**

### F-M1 — [MEDIUM] Mixed migration filename timestamp styles — **OPEN**

PR #38 adds `2026-05-22-100000_CreateProcessedEventsTable.php` (dash
style). The repo still mixes dash and underscore styles across older
migrations.

### F-M2 — [LOW] CreateCookieReadModel + DropCookieReadModel pair — **OPEN**

## Residual severity counts

Counting only items not yet CLOSED:

- **CRITICAL:** 2 (F-I2 outbox UUID UNIQUE; F-O8 partial — column width
  still wrong, but PR #31 turns it from silent corruption into a hard error)
- **HIGH:** 8 (F1, F2, F-I1, F-I3, F-S1 partial, F-FK1, F-I7 LOW originally
  but F-I3 worth re-flagging — counting strictly per original sev: 7)
- **MEDIUM:** 11
- **LOW:** 5
- **INFO:** 1

Conservative count of *open* findings: **CRITICAL 2 | HIGH 7 | MEDIUM 11 | LOW 5 | INFO 1** (was CRIT 3 / HIGH 10 / MED 13 / LOW 6 / INFO 2).

## Verdict shift

**Was:** NOT-READY — three CRITICALs (F-C1/F-C2/F-C3) all in the
operational-connection envelope, plus F-T1 blinding the suite to every
MySQL-specific behaviour, plus F-O8 silently corrupting the dead-letter
path.

**Now:** **STILL NOT-READY**, but the blocker class has shifted. The
operational-envelope CRITICALs (F-C1/F-C2/F-C3) are CLOSED once PR #31
merges; the SQLite test blindness (F-T1/F-T2) is CLOSED once PR #30
merges. F-O8 moves from "silent corruption" to "hard error pending E12
column widening" — net safety win, but creates a forced ordering: PR #31
must NOT merge before E12 ships the VARCHAR widening, or the relay's
unsupported-schema path will start throwing in production. The
remaining CRITICALs (F-I2 outbox event_uuid UNIQUE, F-O8 column widening)
are explicitly owned by the unshipped E12 epic, and the E09 epic (money
type) still owns the largest HIGH (F2). Cookie remains an anti-template
for MySQL operational concerns until E12 + E09 land — but the Phase 0
infrastructure (PR #30 + #31) creates the bedrock on which those
remaining epics can safely build.

## Biggest residual

**E12 (outbox hardening) and E09 (money type) own essentially every
remaining CRITICAL/HIGH on this slice.** PR #31 must be sequenced *with
or after* E12 to avoid the F-O8 hard-error regression, and the F-I2
event_uuid UNIQUE is the gate the handler-side `processed_events`
table (PR #38) is already waiting on. Until both ship, the operational
foundations are sound but the outbox layer is not safe for multi-worker
production.
