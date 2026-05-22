# 18 — MySQL / Database Layer

**Slice:** MySQL operational concerns: indexes, isolation, charset, FKs, outbox/audit tables, connection config, test-DB divergence, GDPR shape
**Reviewer:** codeigniter4-specialist
**Date:** 2026-05-22
**Source files reviewed:** 23
  - 16 migrations (Cookie + ERP foundation tables: users, audit_log, event_outbox, jobs, idempotency_keys, permissions, document_sequences, settings, notifications, attachments)
  - `app/Models/Cookie/CookieModel.php` + 2 traits
  - `app/Domain/Cookie/Repositories/{CookieRepository,CookieQueryRepository}.php`
  - `app/Config/Database.php`, `.env`, `.env.example`
  - `phpunit.xml.dist`
  - `app/Infrastructure/Bus/Middleware/TransactionMiddleware.php`
  - `app/Infrastructure/Outbox/EventOutboxRelay.php`
  - `app/Database/Seeds/CookieSeeder.php`

## TL;DR

The Cookie schema makes a real effort to model an ERP entity (tenant_id, version, created_by/updated_by/deleted_by, composite UNIQUE for soft-delete) and the auxiliary tables (`event_outbox`, `audit_log`, `jobs`, `idempotency_keys`, `document_sequences`) are competently shaped. But the **MySQL** story has serious operational defects: ENGINE/ROW_FORMAT/charset are never pinned in DDL (the project relies on whatever the connected server defaults to); the connection sets NO `sql_mode`, NO isolation level, no `sessionVariables`; `Config\Database::$default::DBCollat` is `utf8mb4_general_ci` while the `cookies.name` column is pinned to `utf8mb4_unicode_ci` (collation-mix on JOIN risk); the `cookies` table has no FK on `created_by/updated_by/deleted_by/tenant_id`; the **event_outbox table has NO claim/idempotency unique key and no leasing lease/owner column**, so multi-worker relay is a race; `SELECT ... FOR UPDATE SKIP LOCKED` is not used despite MySQL 8 supporting it; the **test DB is hard-locked to in-memory SQLite** (`phpunit.xml.dist` forces `CI_ENVIRONMENT=testing` which switches `$defaultGroup` to `$tests` which is `:memory:` SQLite), meaning *every* MySQL-only behavior in this audit — UNIQUE-NULL semantics, FK CASCADE, LIKE collation, JSON, FULLTEXT, FOR UPDATE — is **untested**. Cookie should not be cloned until at least the SQL_MODE + isolation pin + outbox indexes + Database.php DBCollat alignment are fixed.

## Verdict

**NOT-READY** — Cookie is a defensible *schema* template (composite UNIQUE done right; optimistic lock + tenant_id done right) but it is a *MySQL-operational* anti-template: it leaves SQL_MODE, isolation, engine, row_format, and charset to whatever the connecting server happens to default to. Three template-clone footguns (T3, T6, T10) materially block adoption.

## MySQL version & connection config

**Driver:** `MySQLi` (Config/Database.php:33). `pConnect = false`. `port = 3306`. `compress = false`. `strictOn = true` (this maps to CI4 setting `STRICT_ALL_TABLES` *only on the first query*, not as a persistent session var — see F-C2 below). `encrypt = false` (no TLS in dev; .env.example has the SSL block commented out for prod). `numberNative = false` (so `INT UNSIGNED` columns come back as PHP strings — a real footgun for the `version` optimistic-lock column).

**Charset:** connection-level `utf8mb4` / `utf8mb4_general_ci`. Cookie table-level `utf8mb4_unicode_ci` on the `name` column. **Mixed.**

**SQL mode:** not set. No `sessionVariables` key. No `SET SESSION sql_mode`. No `ONLY_FULL_GROUP_BY`, `NO_ZERO_DATE`, `ERROR_FOR_DIVISION_BY_ZERO`.

**Isolation level:** not set. MySQL InnoDB default is `REPEATABLE READ`. The TransactionMiddleware (`app/Infrastructure/Bus/Middleware/TransactionMiddleware.php:74-103`) calls `transBegin()` without picking an isolation level, so every command runs under REPEATABLE READ. The optimistic-lock UPDATE in `CookieRepository::updateWithOptimisticLock` (line 471) ASSUMES READ-COMMITTED semantics for its `affectedRows() === 1` check — it works under REPEATABLE READ because `affected_rows` counts rows actually changed regardless of snapshot, but the **`getOldPrice()` read at line 398** (run before the write inside the same transaction) returns the snapshot, not the latest committed value, so two concurrent renames of the same cookie may both log a "price unchanged" instead of one detecting the other.

**MySQL version assumption:** Documentation (CLAUDE.md) says MySQL 8. CI/CD: no docker-compose or `services:` block defines a MySQL version; everything assumes "whatever you have". `SKIP LOCKED` (MySQL 8 only) is documented in DocumentNumberingService comments (line 26, 139, 145) but the actual SQL string is `FOR UPDATE` — not `FOR UPDATE SKIP LOCKED`. So the project says it targets MySQL 8 but uses only MySQL-5.7-compatible SQL.

**Read replica:** not configured. `failover` is `[]`. CookieQueryRepository uses `Database::connect()` (the *default* connection) — there is no read-replica connection group, so reads and writes hit the same server. For a CQRS template, that's a notable miss.

## Schema & type findings

### F1 — [HIGH] No `ENGINE`, no `ROW_FORMAT`, no table charset on any migration

**Location:** All migrations. `CreateCookiesTable::up` (line 136) calls `$this->forge->createTable('cookies', true)` with no options array. Same for `event_outbox`, `audit_log`, `jobs`, `idempotency_keys`, `cookie_read_model`, every table.

**Observation:** CI4's MySQL Forge defaults to InnoDB *IF* the connecting server's `default_storage_engine` is InnoDB. Older MySQL 5.5 and some MariaDB builds default to MyISAM. Likewise the table charset / collation is whatever `character_set_server` is on the live server. A cloned project landing on a hosting environment whose server has `default_storage_engine=MyISAM` or `character_set_server=latin1` will silently produce non-transactional, non-utf8mb4 tables. The whole transactional outbox + optimistic-lock story dies on MyISAM.

**Why a template defect:** template users won't notice until the second concurrent write loses data.

**Fix:** Every `createTable` call should pass an attributes array:
```php
$this->forge->createTable('cookies', true, [
    'ENGINE'     => 'InnoDB',
    'CHARSET'    => 'utf8mb4',
    'COLLATE'    => 'utf8mb4_unicode_ci',
    'ROW_FORMAT' => 'DYNAMIC',
]);
```
And the canonical reference (Cookie) should be the first to do it.

### F2 — [HIGH] `DECIMAL(10,2)` for price loses currency dimension and caps at ~99M

**Location:** `CreateCookiesTable.php:69-73` (`price`), `cookie_read_model.price_decimal` (line 71), `audit_log.duration_ms` (line 90).

**Observation:** Slice 11 F1 already flagged this; from a MySQL-operational angle, the issues compound:
- `DECIMAL(10,2)` caps the magnitude at `99,999,999.99` — too small for many ERP scenarios (annual revenue, big-ticket assets, lifetime customer value).
- No `currency` column anywhere. The CookiePrice VO documents minor units / currency internally but the column has no `price_currency` — the round-trip through the DB is lossy.
- `DECIMAL` in MySQL has a 4-byte-per-9-digits storage cost; an alternative idiom is `BIGINT` minor-units (cents) + `CHAR(3)` currency, which is exactly what `cookie_read_model` was using before slice 16 dropped it. The write side never adopted the read side's better shape.

**Fix:** Migration shape for the canonical cookies table:
```php
'price_minor'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
'price_currency' => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'USD', 'null' => false],
// Drop `price DECIMAL(10,2)` entirely (or keep as a generated column for legacy reads).
```

### F3 — [MEDIUM] `is_active` is `TINYINT(1)` but the project's other boolean uses are inconsistent

**Location:** `cookies.is_active` is `TINYINT(1) DEFAULT 1` (line 81-86). `cookie_read_model.is_active` is `TINYINT(1) DEFAULT 1` (good). `settings.is_secret` is `TINYINT(1) DEFAULT 0` (good). But CookieModel returns `is_active` as `int` and the cast to `bool` happens in `CookieRepository::toDomainEntity` (line 381) — there's no MySQL `BOOLEAN` cast at the driver level (`numberNative = false`).

**Observation:** `TINYINT(1)` is a display width hint, not a constraint — `TINYINT(1)` accepts 0..127. There is no CHECK constraint enforcing 0/1. Application code that writes `is_active = 2` will get a perfectly valid row. Application code that reads it as `(bool)` will silently coerce 2 → true.

**Fix:** Either add a CHECK constraint (`CHECK (is_active IN (0,1))`) — MySQL 8 enforces these — or use a real `BOOLEAN` column type and rely on MySQL's BOOLEAN→TINYINT(1) alias. The migration's `'type' => 'TINYINT', 'constraint' => 1` is the worst of both: it doesn't enforce, and it confuses readers.

### F4 — [MEDIUM] `cookies.tenant_id` is nullable

**Location:** `CreateCookiesTable.php:51-56`. The migration docblock at line 14-17 hand-waves "Nullable while the template is single-tenant; required by the time a real tenant resolver is wired in."

**Observation:** The composite UNIQUE on `(tenant_id, name, deleted_at)` will silently fail to enforce uniqueness for tenant_id=NULL rows (MySQL treats NULL != NULL in UNIQUE indexes). The docblock at line 25-29 talks around this; the migration does not flip `null` to false even though the schema currently has *zero* nullable-tenant deployments planned in the long term. Every template clone inherits a "soft enforcement" UNIQUE on day 1.

**Fix:** Either default to `0` (a "global tenant" sentinel) and `null => false`, or add a generated column `tenant_id_for_unique INT UNSIGNED GENERATED ALWAYS AS (IFNULL(tenant_id, 0)) STORED` and put the UNIQUE on that. The handler-level pre-check (`existsByName` in `CookieModel:93-98`) is a band-aid for the soft enforcement, not a fix.

### F5 — [MEDIUM] `event_outbox.payload` is `LONGTEXT`, not `JSON`

**Location:** `CreateEventOutboxTable.php:58-61`.

**Observation:** MySQL 5.7+ has a native `JSON` type with constraint, validation, and JSON path indexes. Storing as `LONGTEXT`:
- Skips MySQL's JSON validation at write time. A malformed payload (impossible today via `EventOutboxWriter` but cheap insurance) won't be rejected.
- Forbids JSON path indexes if a future "find events by payload.entityId" query is needed.
- Cannot benefit from MySQL 8's improved JSON storage.

Same applies to `notifications.data_json` (TEXT), `settings.value_json` (LONGTEXT), `idempotency_keys.response_body` (LONGTEXT), `idempotency_keys.response_headers` (TEXT), `jobs.payload` (LONGTEXT).

**Fix:** Use `'type' => 'JSON'` for native validation. SQLite (in tests) does not have JSON as a type but accepts it as TEXT with the same value — portable.

### F6 — [LOW] `cookies.description` is `TEXT NULL` with no length cap

**Location:** `CreateCookiesTable.php:65-68`.

**Observation:** `TEXT` is 64 KiB, `MEDIUMTEXT` is 16 MiB. A 100-char cookie name and a 64 KiB description is a strange ratio. The VO does not enforce a max length (verified via the Cookie domain). A template that copies this and stores customer notes or invoice line memos gets a 64 KiB free-for-all.

**Fix:** Either pin to `VARCHAR(1000)` if a hard limit is acceptable, or document the limit in the VO + a CHECK on `CHAR_LENGTH(description) <= N`.

### F7 — [LOW] `users.role` and `users.status` are `ENUM` columns

**Location:** `CreateUsersTable.php:30-37`.

**Observation:** ENUMs are notoriously painful to migrate in MySQL (adding a value requires an ALTER TABLE that rewrites the whole table on older versions; the values are positional so reordering corrupts data). For an ERP template that prides itself on extensibility, this is an anti-pattern; the project's own `CreatePermissionsSchema` migration (200200) literally creates a proper RBAC schema that obsoletes the `users.role` ENUM and the migration docblock (line 21-23) acknowledges it. The ENUM column is now both stale and a migration trap.

**Fix:** Either drop the column in a new migration, or document it as deprecated. The role data should live in `user_roles`.

### F8 — [INFO] `audit_log.payload_digest` is `CHAR(64)` (good) but no `payload` snapshot

**Location:** `CreateAuditLogTable.php:76-80`. The docblock says it stores SHA-256 of a redacted payload "NOT the payload itself — protects PII while still letting auditors detect tampering". That's a privacy-first decision.

**Observation:** Audit auditors typically need the actual payload for forensics. A digest-only audit log can only answer "did this exact command happen" not "what did the command do". The choice is defensible for a privacy-strict template, but the docblock should explicitly recommend pairing this with a separate, retention-controlled, encrypted payload table for jurisdictions that require it (e.g. SOX, HIPAA, certain Brazilian LGPD requirements).

## Index & constraint findings

### F-I1 — [HIGH] No leasing index on `event_outbox`

**Location:** `CreateEventOutboxTable.php:97-103`. Index list:
- PRIMARY(id)
- KEY(status, available_at)
- KEY(correlation_id)
- KEY(aggregate_type, aggregate_id)

**Observation:** The relay's polling query (`EventOutboxRelay::fetchPending` line 108-115) is:
```sql
WHERE status='pending' AND available_at <= NOW()
ORDER BY available_at ASC, id ASC LIMIT ?
```
The `(status, available_at)` index supports the WHERE but not the secondary `ORDER BY id ASC`. Under high contention with many rows at the same `available_at`, MySQL needs a filesort. Worse: there is **no `id` in the index** so the relay must read the full row anyway. Worse still: there is **no claim/owner column** at all (`reserved_at`, `reserved_by`, `lease_token`) so the "claim" must be a separate UPDATE — see F-I2.

**Fix:** Index on `(status, available_at, id)` for the leasing query. Add `reserved_at`, `reserved_by` columns matching the `jobs` table's shape (which got this right at `CreateJobsTable.php:86`).

### F-I2 — [CRITICAL] `event_outbox` has no idempotency unique key (no event_id / event_uuid)

**Location:** `CreateEventOutboxTable.php` — there is no `event_id`, no `event_uuid`, no unique constraint.

**Observation:** A retried command can append the same event twice (transaction rolled back and replayed at a higher layer, or a partial write before commit). The relay will deliver both. Listeners that aren't idempotent (sending emails, allocating numbers) will double-fire. Slice 05 F5 already flagged this; from a DB angle it means we cannot even *detect* a duplicate after the fact: there's no unique key on `(aggregate_type, aggregate_id, event_class, occurred_at)` or — better — a `event_uuid CHAR(36) UNIQUE`. The `id` PK is auto-increment, so duplicates by definition get distinct PKs.

**Fix:** Add `event_uuid CHAR(36) NOT NULL` with `UNIQUE` index. EventOutboxWriter mints the UUID once per event. The relay can then `INSERT IGNORE` or `ON DUPLICATE KEY UPDATE` safely.

### F-I3 — [HIGH] No claim semantics: relay uses `UPDATE ... WHERE status='pending'` without row locks

**Location:** `EventOutboxRelay.php` — the `claim($id)` method (not in the snippet I read, but the docblock at line 18-21 acknowledges "SQLite has no RETURNING but our claim is a transaction so concurrent runners still see consistent rows").

**Observation:** MySQL InnoDB supports `SELECT ... FOR UPDATE SKIP LOCKED` (MySQL 8). The relay docblock claims multi-worker safety on Postgres/MySQL but the actual claim path is a plain UPDATE — under REPEATABLE READ two workers reading the same `status='pending'` snapshot will both attempt the UPDATE; `affectedRows()` will tell only the loser they lost, but both consumed a connection round-trip. With `SKIP LOCKED`, only one worker even sees the row.

**Fix:** Replace the plain UPDATE-by-id with a transactional `SELECT id FROM event_outbox WHERE status='pending' AND available_at<=? ORDER BY available_at LIMIT ? FOR UPDATE SKIP LOCKED` followed by an UPDATE of those ids. SQLite tests cannot validate this path — see F-T2.

### F-I4 — [MEDIUM] `event_outbox` has no `tenant_id`

**Location:** `CreateEventOutboxTable.php:36-95`.

**Observation:** The `cookies` write goes through TenantContext and stamps `tenant_id` on the row. The aggregate emits an event. The event payload may or may not carry `tenant_id` (depends on the event class). The outbox row itself has no `tenant_id` column, so:
- The relay cannot lease per-tenant ("drain tenant 7's events first because they're VIP").
- A tenant-specific replay/reissue is impossible without scanning every payload.
- A future per-tenant Kafka topic mapping needs an explicit join.

**Fix:** Add `tenant_id INT UNSIGNED NULL` with an index. Cheap insurance.

### F-I5 — [MEDIUM] `audit_log` and `event_outbox` lack a retention/partitioning strategy

**Location:** `CreateAuditLogTable.php`, `CreateEventOutboxTable.php`.

**Observation:** Both tables grow unboundedly. No TTL column, no `cleanup_after`, no partition by `occurred_at`. After a year of production traffic these tables become the slowest in the database. MySQL 8 supports `PARTITION BY RANGE (TO_DAYS(occurred_at))` for cheap drop-partition pruning.

**Fix:** At minimum a documented retention policy and a `spark cleanup:audit-log` job (alongside the existing `spark events:relay`). Ideally partition both tables.

### F-I6 — [MEDIUM] Soft-delete predicate index missing

**Location:** `CreateCookiesTable.php:131-134` declares `addKey('deleted_at')` (single-column index on a column that's NULL for >99% of rows).

**Observation:** A single-column index on a mostly-NULL column has terrible cardinality. The hot read query is `WHERE deleted_at IS NULL AND is_active = 1`, but `is_active` is a 1-bit column — the composite `(deleted_at, is_active)` is barely useful. The correct shape is a **functional index** `(is_active) WHERE deleted_at IS NULL` (Postgres-style partial index), which MySQL 8 supports via expression-based indexes:
```sql
CREATE INDEX cookies_active_live ON cookies ((CASE WHEN deleted_at IS NULL THEN is_active END));
```
…or much simpler: a composite `(deleted_at, is_active, id)` covering index.

**Fix:** Replace `addKey('deleted_at')` and `addKey('is_active')` with one composite covering index `(deleted_at, is_active, id)` matching the `findAll` / `findPaginated` filters.

### F-I7 — [LOW] No FULLTEXT index on `cookies.name`

**Location:** `CookieRepository::executeFindPaginated` line 554 (`$builder->like('name', $searchTerm)`) and `CookieQueryRepository:131` likewise. Substring LIKE cannot use a B-tree index.

**Observation:** `LIKE 'term'` (suffix wildcard) can use the index; `LIKE '%term%'` (which CI4's `$builder->like('name', $term)` produces by default) **cannot**. Every search is a table scan. With 10k cookies this is fine; with 10M it's a problem. A `FULLTEXT INDEX` on `name, description` (MySQL 8 supports `FULLTEXT` on InnoDB) is the canonical fix.

**Fix:** Document the scale ceiling, and add a `FULLTEXT(name, description)` index plus `MATCH() AGAINST()` query path for templates that scale.

## Soft-delete + UNIQUE-NULL semantics

(Dedicated section per audit prompt — flagged in round 2/3.)

### F-S1 — [HIGH] Composite UNIQUE(tenant_id, name, deleted_at) does NOT prevent two active rows

**Location:** `CreateCookiesTable.php:130` — `$this->forge->addUniqueKey(['tenant_id', 'name', 'deleted_at'])`. The migration docblock (line 25-30) sells this as the right pattern. It is the right pattern **for re-creation after soft-delete**. It is **not** the right pattern for preventing duplicate *active* rows.

**Observation:** MySQL's UNIQUE index treats NULL as distinct from every other NULL. So:
- Row 1: `(tenant_id=1, name='Sugar', deleted_at=NULL)`
- Row 2: `(tenant_id=1, name='Sugar', deleted_at=NULL)`

Both rows satisfy the UNIQUE constraint because `(NULL, NULL)` is "distinct" from itself. The application's `existsByName` pre-check (`CookieModel.php:93-98`) is the only thing keeping duplicates out under concurrent inserts. Race window: two concurrent CreateCookie commands → both call `existsByName` → both see no rows → both INSERT → both succeed.

This is partially mitigated by the `CookieRepository::save` catch block at line 131-138 (`isDuplicateKey`), but the catch is **unreachable** because the DB doesn't raise the duplicate. Slice 11 / round-2 R03/V4 flagged this; my MySQL-specific verdict is: **the migration ships a footgun and a band-aid that does not actually fire on MySQL.**

`UsersEmailUniqueWithSoftDelete.php` (the more recent migration, lines 16-22) explicitly acknowledges this caveat in its docblock and points at the application-level guard as the actual enforcement. So someone on the team knows. Cookie is not yet updated to reflect the same caveat.

**Fix (MySQL 8):** Generated column workaround:
```php
'name_active_key' => [
    'type' => 'VARCHAR',
    'constraint' => 100,
    'null' => false,
    // GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN name ELSE NULL END) STORED
],
$this->forge->addUniqueKey(['tenant_id', 'name_active_key']);
```
CI4 Forge doesn't directly support generated columns; raw SQL via `$this->db->query('ALTER TABLE ... ADD COLUMN ... GENERATED ALWAYS AS ...')` is the path. SQLite supports the same syntax since 3.31. Cross-engine portable.

### F-S2 — [MEDIUM] `deleted_by` is paired-but-not-enforced with `deleted_at`

**Location:** `CookieRepository::delete` line 302-309. The repo stamps `deleted_by` *before* calling `$this->model->delete($id)`. That's two round-trips for an audit pair.

**Observation:** Nothing at the DB layer enforces `(deleted_at IS NULL) <=> (deleted_by IS NULL)`. A trigger or CHECK constraint would do it. Application bugs can produce `(deleted_at=NULL, deleted_by=42)` (e.g. a partial restore that nulls deleted_at but forgets deleted_by) — exactly the bug `restore()` line 334-337 has to remember to fix.

**Fix:** MySQL 8 supports `CHECK ((deleted_at IS NULL AND deleted_by IS NULL) OR (deleted_at IS NOT NULL AND deleted_by IS NOT NULL))`. SQLite supports it identically.

### F-S3 — [MEDIUM] No `restored_at` / `restored_by`

**Location:** `CookieRepository::restore` line 326-350 sets `updated_at` and `updated_by` on the restored row.

**Observation:** Coercing "restore" into "update" loses information. A user who restored a row gets credit for the most recent "update". For an ERP audit trail this matters: "who un-deleted this invoice?" is a question regulators ask.

**Fix:** Either add `restored_at`, `restored_by` columns, or rely on the `audit_log` table containing a `RestoreCookieCommand` row.

## Foreign-key findings

### F-FK1 — [HIGH] `cookies` has no FK on `created_by` / `updated_by` / `deleted_by` / `tenant_id`

**Location:** `CreateCookiesTable.php` — no `addForeignKey` calls.

**Observation:** Other tables in the codebase get this right:
- `refresh_tokens.user_id` → users.id (CASCADE)
- `password_reset_tokens.user_id` → users.id (CASCADE)
- `notifications.user_id` → users.id (CASCADE)
- `role_permissions.role_id` → roles.id (CASCADE)

The canonical Cookie reference does not. Slice 11 F6 already flagged this; from a MySQL angle the issue is that without an FK, MySQL won't auto-index the FK column either, so even the *implicit* index that FKs require is absent. The migration only indexes `tenant_id` directly (line 134); `created_by`/`updated_by`/`deleted_by` get no index at all, which means any "find all rows created by user X" query is a table scan.

The `CreateAttachmentsTable.php:115-122` docblock explains the deliberate decision to NOT FK on `uploaded_by` (the SYSTEM_ID=0 sentinel has no real user row). Cookie has the same actor-stamping pattern but never makes the equivalent decision explicit.

**Fix:** Either FK + sentinel 0 user row, or document the "no FK by design" decision and at least index the columns.

### F-FK2 — [MEDIUM] `event_outbox`, `audit_log` have no FK on `correlation_id`, `aggregate_id`

**Location:** `CreateEventOutboxTable.php:62-66`, `CreateAuditLogTable.php:66-70`.

**Observation:** `aggregate_id` is `VARCHAR(64)` — string, not an int — because aggregates may have UUID IDs in the future. So no FK is possible while the type is loose. But the relay rehydrates the event with `(int) $aggregate_id` at runtime, so the actual data is always integer-shaped. Pick one type.

**Fix:** Decide: numeric aggregate ids → `BIGINT UNSIGNED NULL` with optional polymorphic-type column (already present: `aggregate_type`). Or commit to UUID aggregate ids and stop casting.

### F-FK3 — [LOW] FK action on `notifications.user_id` is CASCADE

**Location:** `CreateNotificationsTable.php:102`.

**Observation:** Deleting a user wipes their notifications. Fine for an inbox. **Not fine** if the user record is hard-deleted (GDPR right to erasure) and you want an audit trail of "system delivered N notifications to deleted-user-42 before they were erased". For GDPR you usually want SET NULL, not CASCADE — the join-side row stays but loses PII. `security_events.user_id` correctly uses SET NULL (`CreateSecurityEventsTable.php:69`). Cookie's `created_by/updated_by/deleted_by` would need SET NULL too if they ever got a real FK.

**Fix:** Document the CASCADE-vs-SET-NULL decision per table. The Cookie reference should articulate this for ERP cloners.

## Outbox table findings

(Already addressed in F-I1..F-I5 above; consolidated here for cross-reference.)

- **F-O1 [CRITICAL]:** No event_uuid / idempotency key → duplicate delivery (see F-I2).
- **F-O2 [HIGH]:** No claim/lease columns → multi-worker race (see F-I3).
- **F-O3 [HIGH]:** No leasing index → filesort under load (see F-I1).
- **F-O4 [MEDIUM]:** No `tenant_id` column → no per-tenant draining (see F-I4).
- **F-O5 [MEDIUM]:** `LONGTEXT` payload, not `JSON` (see F5).
- **F-O6 [MEDIUM]:** No retention / partitioning (see F-I5).
- **F-O7 [INFO]:** Status is `VARCHAR(16)` not ENUM — defensible (the project avoids ENUMs F7) but invites typos. CHECK constraint with `status IN ('pending','in_flight','delivered','failed','unsupported_schema')` would help. Note also the relay code uses `unsupported_schema` (line 164) — a 19-character value that *does not fit in VARCHAR(16)*. Likely silent truncation on insert.

**The VARCHAR(16) vs. `'unsupported_schema'` mismatch is a CRITICAL data-correctness bug** — strict mode would reject it; without strict mode it silently truncates to `unsupported_schem`. This is a direct consequence of F-C2 (no SQL_MODE pinned).

Let me bump that to its own finding:

### F-O8 — [CRITICAL] `event_outbox.status VARCHAR(16)` cannot hold `'unsupported_schema'` (18 chars)

**Location:** Schema: `CreateEventOutboxTable.php:67-72`. Writer: `EventOutboxRelay.php:164` calls `markUnsupportedSchema()`.

**Observation:** `'unsupported_schema'` is 18 chars. VARCHAR(16) holds 16. With `strictOn=true` and strict SQL mode pinned, this is a write error. With the project's current effective `sql_mode = ''` (because no `sessionVariables` are set — see F-C2), MySQL silently truncates to `unsupported_sche`. The relay's subsequent lookup of rows by `status='unsupported_schema'` will return zero rows; the dead-letter row is effectively invisible and may be re-leased forever.

**Fix:** Bump `constraint => 32` and add a CHECK constraint listing the valid statuses. This bug only exists because no test ever exercised the unsupported-schema path on MySQL — see F-T1.

## Audit-log table findings

### F-A1 — [MEDIUM] `audit_log.actor_id` is `INT UNSIGNED NOT NULL DEFAULT 0`, no FK

**Location:** `CreateAuditLogTable.php:53-59`.

**Observation:** The `0` sentinel for system actor is consistent with `attachments.uploaded_by` (see F-FK1). But the docblock at `CreateAuditLogTable.php:19` says "0 for system actor, otherwise users.id" without acknowledging the no-FK design. Cloner expectation: there's a foreign key. Reality: there isn't.

**Fix:** Document the sentinel pattern in a dedicated `app/Domain/Shared/ValueObjects/Actor.php` cross-reference comment in the migration.

### F-A2 — [MEDIUM] `audit_log` has no `entity_type` / `entity_id`

**Location:** `CreateAuditLogTable.php`.

**Observation:** The audit row stores `command_class` but not the affected entity. Finding "every audit row touching cookie #42" requires decoding the `payload_digest` (which is a hash, not the payload) — impossible. The actual entity reference is in the `payload` of the *event*, not the *command*. So joining audit_log to event_outbox gives the trail, but it's a two-table join with no FK and weak indexes.

**Fix:** Add `aggregate_type VARCHAR(100) NULL`, `aggregate_id VARCHAR(64) NULL` columns (mirror event_outbox) and an index on `(aggregate_type, aggregate_id)`.

### F-A3 — [LOW] `duration_ms` is `DECIMAL(10,2)` — caps at ~99M ms (28 hours)

**Location:** `CreateAuditLogTable.php:90-94`.

**Observation:** Fine for the foreseeable future. Mentioned for completeness.

## Test-DB divergence (SQLite vs MySQL)

### F-T1 — [CRITICAL] Tests run on in-memory SQLite, blinding the suite to every MySQL-specific behavior in this audit

**Location:** `Config/Database.php:167-192` (`$tests` array: `DBDriver=SQLite3`, `database=':memory:'`). `Config/Database.php:201-203` forces `defaultGroup='tests'` when `ENVIRONMENT==='testing'`. `phpunit.xml.dist:58` sets `<env name="CI_ENVIRONMENT" value="testing" force="true"/>` — no escape hatch.

**Observation:** This is the same finding slice 13 F1 raised, but my MySQL-specific count of what this hides:
- **F-O8** (VARCHAR(16) truncation) — SQLite TEXT has no length limit; the bug doesn't reproduce.
- **F-S1** (UNIQUE-NULL semantics) — SQLite ALSO treats NULL as distinct in UNIQUE, so behavior matches MySQL by coincidence, but the round-2 mitigation via generated column won't compile if it uses MySQL-only syntax.
- **F-I3** (`FOR UPDATE SKIP LOCKED`) — SQLite ignores `FOR UPDATE` (single-writer). Concurrent-relay race not testable.
- **F-FK1** (FK behavior) — SQLite's `foreignKeys=true` is on (line 185), but FK action semantics (CASCADE vs RESTRICT) differ in edge cases.
- **F1** (engine/charset) — SQLite has neither, so ENGINE=InnoDB DDL is silently dropped.
- **F2** (DECIMAL range) — SQLite stores DECIMAL as a synonym for NUMERIC which is actually REAL: precision is lost, but no error.
- **Collation:** SQLite has no `utf8mb4_unicode_ci`; the Cookie migration's pinned collation (`CreateCookiesTable.php:63`) is silently ignored — the case-insensitive LIKE the migration docblock relies on works on SQLite for completely different reasons (`PRAGMA case_sensitive_like = OFF` by default).
- **JSON column:** SQLite stores as TEXT; MySQL native JSON validation is not exercised.
- **`SET NAMES`, `sql_mode`:** None of these apply to SQLite.

**The suite has been green for an unknown number of MySQL-only correctness bugs.** F-O8 is one I caught by reading; there are almost certainly others.

**Fix path:** Two-tier strategy:
1. Keep SQLite for the fast-feedback suite (`composer test`).
2. Add a `composer test:mysql` target that runs the SAME suite against a docker-compose MySQL 8 service. CI matrix: `[sqlite, mysql8]`. This requires:
   - `.github/workflows/ci.yml` with a `services: mysql` block.
   - A second phpunit config (`phpunit.mysql.xml`) that does NOT force `CI_ENVIRONMENT=testing` so `$default` is used.
   - A separate `database.tests` block in `Config/Database.php` that defaults to MySQL.
3. Pre-commit hook need not run MySQL tests; CI must.

### F-T2 — [HIGH] `database.tests.DBPrefix='db_'` in Config but not in .env

**Location:** `Config/Database.php:174` — `'DBPrefix' => 'db_'`. `.env:54` — `database.tests.DBPrefix =` (empty). `.env.example:73` — `database.tests.DBPrefix =` (empty).

**Observation:** The Config file warns "DO NOT REMOVE FOR CI DEVS" — but the .env override is empty, which means in real test runs the prefix is dropped. Migrations don't use the prefix in their `createTable` calls. So the comment is aspirational, not enforced. A cloner who pulls in `db_`-prefixed tests will find that the prefix is silently ignored — until someone DOES set the prefix in .env and every test breaks.

**Fix:** Pick one. Either drop the prefix entirely (the project doesn't use it anywhere) or document why it's there and align both files.

## GDPR / retention findings

### F-G1 — [HIGH] No hard-delete path for `cookies`

**Location:** `CookieRepository::delete` (line 294-318) is soft-delete only. No `forceDelete()` or `purge()`.

**Observation:** GDPR right to erasure (Art. 17), LGPD (Brazil) similar. A user requesting deletion of their data needs the row physically gone, not flagged. The Cookie domain is an example template; real ERP entities (Customer, Vendor, Employee) will have PII. The template must show the hard-delete pattern.

**Fix:** Add a `purge(int $id, Actor $actor)` method, gated by a `cookies.purge` permission. The `audit_log` entry survives (it has only a `payload_digest`, no PII — good); the row goes.

### F-G2 — [MEDIUM] PII columns not annotated

**Location:** All migrations.

**Observation:** No machine-readable inventory of "which columns hold PII". `users.email` is PII; `attachments.original_name` might be; `notifications.body` might be. A template-grade ERP scaffolder would put a `// @pii` comment or a project-level PII registry. Cloners building real domains have to re-decide every time.

**Fix:** A `app/Domain/Shared/Privacy/PiiRegistry.php` listing `(table, column, retention_days, erasure_strategy)`. Migrations cross-reference it.

### F-G3 — [MEDIUM] No retention or auto-purge on `event_outbox`, `audit_log`, `idempotency_keys`, `login_attempts`, `security_events`

**Location:** Already covered in F-I5. From a GDPR angle: idempotency_keys has a TTL via `expires_at` (good) but no purge job. login_attempts holds IPs — PII in some jurisdictions. No retention.

**Fix:** `spark cleanup:run` orchestrator with per-table retention configs.

### F-G4 — [LOW] No encryption-at-rest documentation

**Location:** `.env.example:60-64` documents SSL-in-transit (good) but says nothing about MySQL's `KEYRING` plugin or InnoDB tablespace encryption.

**Observation:** Not a code issue; a docs-completeness issue for a template that wants to be production-grade.

**Fix:** Add a "Production deployment" doc section.

## Migration hygiene findings

### F-M1 — [MEDIUM] Migration filename timestamps are inconsistent

**Location:** Migration files use three different naming styles:
- `2025-01-21-000001_CreateCookiesTable.php` (dashes)
- `2025_10_27_102358_CreatePasswordHistory.php` (underscores)
- `2025-10-26-110000_CreateUsersTable.php` (dashes, different format)

**Observation:** CI4 accepts both, but mixed conventions make `ls` ordering unpredictable. The Cookie migration is from "2025-01-21" but every other Cookie-era migration is "2026-05-19" or later — the Cookie migration appears chronologically first by timestamp but was the *last* template-shape change in slice 16. Migration history doesn't match git history.

**Fix:** Pick one style. Use a CI step (`spark migrate:status --silent --format=json`) to verify ordering matches reality.

### F-M2 — [LOW] CreateCookieReadModel + DropCookieReadModel within 1 day

**Location:** `2026-05-20-200000_CreateCookieReadModelTable.php` and `2026-05-21-120000_DropCookieReadModelTable.php`.

**Observation:** Slice 11 already flagged this. From a MySQL angle: any DBA reviewing the migration history sees a CREATE then a DROP, with the DROP migration also containing a full re-CREATE in `down()`. That's 200+ lines of dead schema living in the codebase forever. The reference projection at `Projections/CookieReadModelProjection.php.example` is a legitimate template artifact; the migration-pair is not.

**Fix:** Squash the create+drop into a single never-applied "example" migration outside the runtime path, or remove both and link to the git tag where the read model existed.

## Connection-time SQL_MODE findings

### F-C1 — [HIGH] No `sessionVariables` in Config/Database.php

**Location:** `Config/Database.php:27-54` — no `sessionVariables` key. CI4 MySQLi driver supports a `sessionVariables` array that runs `SET SESSION key=value` on connect.

**Observation:** Without this, the project relies entirely on the MySQL server's per-connection defaults. In a managed DB (RDS, PlanetScale, etc.) those defaults are at the operator's discretion. In a self-hosted MySQL 8 install the default `sql_mode` is `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` — that's actually pretty good. But MySQL 5.7's default is much weaker, and MariaDB's default omits `STRICT_TRANS_TABLES`. Cookie cannot rely on this.

**Fix:** See "Recommended Database.php patch" below.

### F-C2 — [HIGH] `strictOn=true` is not the same as pinned strict `sql_mode`

**Location:** `Config/Database.php:44` — `'strictOn' => true`.

**Observation:** CI4's `strictOn` causes the MySQLi driver to issue `SET SESSION sql_mode = CONCAT(@@sql_mode, ",STRICT_ALL_TABLES")` on first connect. This adds STRICT_ALL_TABLES but does NOT pin the full safe set (`NO_ZERO_DATE`, `NO_ZERO_IN_DATE`, `ERROR_FOR_DIVISION_BY_ZERO`, `ONLY_FULL_GROUP_BY`). It also concatenates onto whatever the server gave you — so a server with `sql_mode = 'IGNORE_SPACE'` ends up with `'IGNORE_SPACE,STRICT_ALL_TABLES'`, still missing the rest.

This is why F-O8 (status truncation) is a real bug rather than a theoretical one: on a non-strict MySQL 5.7 or MariaDB, the `INSERT ... VALUES (..., 'unsupported_schema')` silently truncates rather than rejecting.

**Fix:** `sessionVariables` (see patch below).

### F-C3 — [HIGH] No isolation level pinned

**Location:** No `SET SESSION TRANSACTION ISOLATION LEVEL`. TransactionMiddleware (`TransactionMiddleware.php:74`) calls plain `transBegin()`.

**Observation:** MySQL InnoDB default = REPEATABLE READ. Postgres default = READ COMMITTED. SQLite has effectively SERIALIZABLE (single-writer). The project's behavior under each is materially different. The optimistic-lock check survives REPEATABLE READ but the inter-transaction read-after-write semantics of `dispatchPendingEvents` (CookieRepository.php:172-188) are subtly different.

**Fix:** Pin `READ COMMITTED` in `sessionVariables`. It's the most-Postgres-like choice and makes the transaction semantics portable.

## What is correct / praiseworthy

- **Composite UNIQUE for soft-delete restoration** (`CreateCookiesTable.php:130`) is the right pattern with the right caveat (modulo F-S1).
- **Optimistic locking via `version` column** (`CookieRepository::updateWithOptimisticLock`) is textbook — bumps in lock-step with the entity, `affectedRows() === 1` check, `concurrentModification` exception. Cleanly implemented.
- **Pinned column collation** (`name` is `utf8mb4_unicode_ci`) is the right answer to case-insensitive uniqueness; the `CookieQueryRepository` docblock at line 127 even explains why.
- **`audit_log.payload_digest` as SHA-256, not payload** (CreateAuditLogTable docblock line 23-26) is privacy-first and well-reasoned.
- **`document_sequences` with `(series, scope)` UNIQUE + transactional `FOR UPDATE`** (`CreateDocumentSequencesTable.php:87`, `DocumentNumberingService.php:135`) is the right shape for gapless ERP numbering, with the caveats about cross-engine portability honestly documented.
- **`UsersEmailUniqueWithSoftDelete` migration explicitly discusses the NULL-vs-NULL UNIQUE caveat** (lines 13-25). Cookie should match this level of honesty.
- **`AttachmentsTable` explicitly comments why `uploaded_by` has no FK** (lines 115-122) — that's the kind of decision-trail every template wants.
- **`jobs` table has the leasing shape Cookie's outbox lacks** (`available_at`, `reserved_at`, `max_attempts`, `last_error`, claim semantics). The outbox should mimic this pattern.
- **Migration `dropTable($name, true)` with `cascadeForeignKeys` flag** — defensive against rollback-with-FKs.
- **`processIndexes` after `addKey` on UsersEmailUniqueWithSoftDelete** (line 56) shows the team understands CI4 Forge's two-phase index application.

## Top 5 fixes before cloning

1. **Pin `sql_mode`, isolation level, charset, ENGINE, and ROW_FORMAT at every layer** (Config/Database.php `sessionVariables`; every migration's `createTable` attributes array). Today the project takes the server's defaults — that is unfit for a template.
2. **Fix F-O8 immediately** (`event_outbox.status` length / unsupported_schema truncation) and add a CHECK constraint listing valid statuses. Same review pass should add `event_uuid` + UNIQUE for outbox idempotency (F-O1) and the `reserved_at`/`reserved_by` lease columns (F-O2) mirroring the `jobs` table.
3. **Build the MySQL test matrix** (F-T1). Cookie's MySQL claims cannot be validated by the current SQLite-only suite. A `composer test:mysql` target running in CI is the minimum bar. Without it, every finding here will silently regress.
4. **Fix the composite-UNIQUE-with-NULL footgun on `cookies`** (F-S1) using a generated `name_active_key` column. Document the engine difference between MySQL (this trick) and Postgres (partial unique index).
5. **Address ERP-money type** (F2): replace `price DECIMAL(10,2)` with `price_minor BIGINT + price_currency CHAR(3)` matching what the now-deleted `cookie_read_model` already used. Update the VO + repository round-trip. This single change cascades through the entire Cookie domain and should be the model for any new monetary template.

## Recommended `Config/Database.php` / SQL_MODE patch (sketch)

```php
public array $default = [
    'DSN'          => '',
    'hostname'     => 'localhost',
    'username'     => '',
    'password'     => '',
    'database'     => '',
    'DBDriver'     => 'MySQLi',
    'DBPrefix'     => '',
    'pConnect'     => false,
    'DBDebug'      => true,
    'charset'      => 'utf8mb4',
    // ALIGN with the column-level collation in CreateCookiesTable
    // (utf8mb4_unicode_ci). The MySQL 8 idiom is utf8mb4_0900_ai_ci;
    // pick one and use it project-wide.
    'DBCollat'     => 'utf8mb4_unicode_ci',
    'swapPre'      => '',
    'encrypt'      => false,
    'compress'     => false,
    // Keep strictOn so CI4 also issues its STRICT_ALL_TABLES on connect.
    // sessionVariables below is the AUTHORITATIVE pin; strictOn is belt+braces.
    'strictOn'     => true,
    'failover'     => [],
    'port'         => 3306,
    // Cast INT columns back to PHP int instead of string — fixes silent
    // string-vs-int comparison surprises on `version` and `is_active`.
    'numberNative' => true,
    'foundRows'    => false,
    'dateFormat'   => [
        'date'     => 'Y-m-d',
        'datetime' => 'Y-m-d H:i:s',
        'time'     => 'H:i:s',
    ],
    // SET SESSION ... on every connection. Order matters: sql_mode
    // first so subsequent statements run under the pinned mode.
    'sessionVariables' => [
        'sql_mode' => 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,'
                    . 'ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,'
                    . 'NO_ENGINE_SUBSTITUTION',
        // READ COMMITTED makes the transaction semantics portable with
        // Postgres and removes the "snapshot too old" surprise inside
        // long-running commands. The optimistic-lock UPDATE in
        // CookieRepository still works because affectedRows() counts
        // rows actually modified, not rows visible in the snapshot.
        'transaction_isolation' => 'READ-COMMITTED',
        // Pin the timezone so DATETIME columns are unambiguous.
        'time_zone' => '+00:00',
        // Belt+braces in case the server's default is wrong.
        'character_set_client'     => 'utf8mb4',
        'character_set_connection' => 'utf8mb4',
        'character_set_results'    => 'utf8mb4',
        'collation_connection'     => 'utf8mb4_unicode_ci',
    ],
];
```

Companion migration-side patch (one example, repeated per `createTable`):

```php
$this->forge->createTable('cookies', true, [
    'ENGINE'     => 'InnoDB',
    'CHARSET'    => 'utf8mb4',
    'COLLATE'    => 'utf8mb4_unicode_ci',
    'ROW_FORMAT' => 'DYNAMIC',
]);
```

Companion outbox-table patch (sketch):

```php
'event_uuid' => [
    'type' => 'CHAR',
    'constraint' => 36,
    'null' => false,
],
'reserved_at' => [
    'type' => 'DATETIME',
    'null' => true,
],
'reserved_by' => [
    'type' => 'VARCHAR',
    'constraint' => 64,
    'null' => true,
],
'tenant_id' => [
    'type' => 'INT',
    'unsigned' => true,
    'null' => true,
],
// ...
$this->forge->addUniqueKey('event_uuid');
$this->forge->addKey(['status', 'available_at', 'id']);
$this->forge->addKey('tenant_id');
// Bump status width:
'status' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false, 'default' => 'pending'],
```

---

**Severity counts:** CRITICAL 3 | HIGH 10 | MEDIUM 13 | LOW 6 | INFO 2
**Top finding:** `event_outbox.status VARCHAR(16)` silently truncates `'unsupported_schema'` because the project pins neither `sql_mode` nor a meaningful column length, and the SQLite-only test suite cannot reproduce the truncation — a CRITICAL data-correctness bug hiding behind a CRITICAL test-DB blind spot.
