# r09 — Data layer (schema, migrations, MySQL/SQLite portability)

Round 2. Focus: schema integrity, migration ordering, MySQL-vs-SQLite portability,
FK + index strategy across all 21 migrations under `app/Database/Migrations/`.

Cross-refs: Round 1 reports `05-cookie-repository.md`,
`15-views-helpers-migrations-tests.md` (migration section), and the
consolidated audit. r09 cites only new findings or facts that needed verification.

---

## Verified schema issues

1. **CI4 `timestampFormat` misaligned with 7 migrations.**
   `app/Config/Migrations.php:49` declares `'Y-m-d-His_'`. The 7 files
   `2025_10_27_102358_CreatePasswordHistory.php` through
   `2025_10_27_105200_CreateSecurityEventsTable.php` use `Y_m_d_His_`, which is
   a separate declared format. Today's `MigrationRunner` regex
   (`/^\d{4}[_-]?\d{2}[_-]?\d{2}[_-]?\d{6}_(\w+)$/`) is looser than the
   declared format and accepts both, but any future tightening drops the 7
   auth-table migrations silently. Lexical sort happens to land them between
   `2025-10-26-…` and `2026-…` because `-` (0x2D) < `_` (0x5F). Coincidence,
   not design — the next dash-stemmed `2025-10-27-…` file will sort before
   the underscored ones, contradicting intent.

2. **MySQL `UNIQUE(...)` with NULL doesn't fire on key composites.**
   - `cookies UNIQUE(tenant_id, name, deleted_at)` at
     `2025-01-21-000001_CreateCookiesTable.php:128-130`. `CookieRepository::performSave`
     (`app/Models/Cookie/CookieRepository.php:343-372`) never writes `tenant_id`;
     `deleted_at` is NULL on live rows. MySQL treats NULL as distinct in UNIQUE
     → duplicate active rows insert. SQLite (test DSN) treats NULL as equal →
     tests pass. The duplicate-key catch at `CookieRepository.php:100-119` is
     unreachable in MySQL prod.
   - `settings UNIQUE(key_name, tenant_id)` at
     `2026-05-19-200500_CreateSettingsTable.php:82`. Global-scope rows
     (`tenant_id IS NULL`) MySQL-distinct → two rows with the same `key_name`
     and NULL tenant insert.
   - `idempotency_keys UNIQUE(id_key, actor_id)`
     (`2026-05-19-200100_CreateIdempotencyKeysTable.php:99`) — fine because
     `actor_id` is `NOT NULL DEFAULT 0`.

3. **`users.email` is `UNIQUE(email)` inline, not `UNIQUE(email, deleted_at)`.**
   `2025-10-26-110000_CreateUsersTable.php:23`. Soft-delete via the
   `useSoftDeletes` model blocks re-registration after a delete entirely. The
   Cookie migration's docblock explicitly cites this pattern as the ERP
   baseline; Users does not follow.

4. **Auth subsystem uses `TIMESTAMP`, the rest uses `DATETIME`.**
   - `TIMESTAMP`: `refresh_tokens.expires_at`
     (`2025_10_27_104000_…:37`), `sessions.last_activity_at` and `expires_at`
     (`2025_10_27_105000_…:60-79`), `token_blacklist.expires_at`
     (`2025_10_27_104100_…:34`), `password_reset_tokens.expires_at`
     (`2025_10_27_104200_…:38`), `password_history.created_at`
     (`2025_10_27_102358_…:46`), `login_attempts.created_at`
     (`2025_10_27_105100_…:60-62`), `security_events.created_at`
     (`2025_10_27_105200_…:57-60`).
   - `DATETIME` everywhere else.
   MySQL `TIMESTAMP` converts to UTC on store and to session-tz on retrieve.
   `DATETIME` is stored verbatim. Cross-comparing a TIMESTAMP `expires_at`
   against a PHP `Y-m-d H:i:s` string with non-UTC `time_zone` produces a
   silent N-hour offset. SQLite has neither type and stores both as TEXT —
   bug invisible in tests.

5. **`audit_log.actor_id` is `NOT NULL DEFAULT 0`, not nullable + FK.**
   `2026-05-19-200000_CreateAuditLogTable.php:53-59`. Sentinel `0` collides with
   any legitimate user id (currently impossible due to autoincrement, but bad
   hygiene). Reporting joins on `audit_log.actor_id = users.id` lose system-
   actor rows.

6. **`refresh_tokens.jti` is INDEX not UNIQUE.**
   `2025_10_27_104000_CreateRefreshTokensTable.php:57`. `token_blacklist.jti`
   IS unique (`2025_10_27_104100_…:46`). Same JTI semantic enforced
   differently across tables of the same subsystem.

7. **`cookie_read_model.name_search` has no collation pin.**
   `2026-05-20-200000_CreateCookieReadModelTable.php:50-54`. Write-side
   `cookies.name` is `utf8mb4_unicode_ci`
   (`2025-01-21-000001_…:63`); read-side defaults to the connection
   default. Case folding for non-ASCII diverges write/read.

8. **`security_events.metadata JSON` is MySQL-only.**
   `2025_10_27_105200_…:52-56`. SQLite stores as TEXT silently; any test that
   uses JSON_EXTRACT predicates never exercises real MySQL semantics.

9. **No `INDEX(deleted_at)` on `users` or `attachments`.** Both tables soft-
   delete (`users` via `useSoftDeletes` model, `attachments` declares the
   column at `2026-05-20-100000_CreateAttachmentsTable.php:105-108`). Every
   `WHERE deleted_at IS NULL` scans. Cookies got it right at line 133.

10. **`event_outbox.occurred_at` and `jobs.reserved_at` indexed nowhere.**
    `2026-05-19-200300_CreateEventOutboxTable.php:97-102` and
    `2026-05-19-200600_CreateJobsTable.php:109-112`. Stuck-job reapers and
    "what happened at time T" queries table-scan.

11. **Two single indexes on `notifications` where one composite suffices.**
    `2026-05-20-100100_CreateNotificationsTable.php:96-97` declares
    `(user_id, read_at)` and `(user_id, created_at)` separately. The
    canonical UI query is `WHERE user_id=? AND read_at IS NULL ORDER BY
    created_at DESC` — wants one composite `(user_id, read_at, created_at)`
    for an index-only scan.

12. **`cookie_read_model.price_minor INT(11)` overflow risk.**
    `2026-05-20-200000_…:60-64`. Caps at signed-int-max = 21,474,836.47 USD.
    Should be `BIGINT UNSIGNED` to match the `Money::$amountMinor` int type
    used in the entity.

13. **`document_sequences` has no `tenant_id` column.**
    `2026-05-19-200400_CreateDocumentSequencesTable.php:34-90`. Scope is a
    free-form VARCHAR; multi-dimensional scoping (tenant + fiscal year)
    requires string concatenation. UNIQUE should become
    `(tenant_id, series, scope)`.

14. **`permissions/roles/role_permissions/user_roles` carry no audit, no
    tenant, no soft-delete.**
    `2026-05-19-200200_CreatePermissionsSchema.php`. No `granted_by` on
    `user_roles` (only `granted_at`, line 142). Revoking a role is a hard
    DELETE with no trail. Multi-tenant RBAC is impossible without backfill.

---

## Cross-table consistency findings

1. **Tenant-id is NULLABLE everywhere it exists** (cookies, settings,
   notifications, attachments, audit_log, cookie_read_model). Every NULLable
   tenant column propagates the Q2 NULL-UNIQUE issue once that column joins
   a composite unique.

2. **Three different "user link" column names.** `user_id` (notifications,
   sessions, login_attempts, etc.); `actor_id` (audit_log); `uploaded_by`
   (attachments); `created_by/updated_by/deleted_by` (cookies). No table-
   spanning convention — cross-table reports must memorise.

3. **Two different audit conventions.** Cookie has
   `created_by/updated_by/deleted_by` (`2025-01-21-000001_…:94-111`).
   `audit_log` uses `actor_id` + `command_class` + `status`. Neither is
   bridged; there is no view or report that joins both perspectives.

4. **`correlation_id VARCHAR(128)`** in `audit_log`, `event_outbox`,
   `notifications`, `jobs`. 128 chars is too wide — UUIDv4 is 36, ULID 26.
   Pin to 36 and document the format.

5. **`payload` representations diverge.** `event_outbox.payload LONGTEXT`,
   `jobs.payload LONGTEXT`, `idempotency_keys.response_body LONGTEXT`,
   `settings.value_json LONGTEXT` — but `security_events.metadata JSON`.
   Four LONGTEXT, one JSON.

6. **ERP-baseline column drift.** The Cookie migration's own docblock
   (`2025-01-21-000001_…:13-21`) declares the five columns every operational
   entity should have: `tenant_id, version, created_by, updated_by,
   deleted_by`. Cookie carries all five. **No other table in the schema
   carries all five.**

   | Table | tenant_id | version | created_by | updated_by | deleted_by |
   | --- | --- | --- | --- | --- | --- |
   | cookies | yes | yes | yes | yes | yes |
   | users | no | no | no | no | no |
   | settings | yes | no | no | no | no |
   | notifications | yes | no | no | no | no |
   | attachments | yes | no | no | no | (soft-deleted only) |
   | audit_log | yes | no | (actor_id) | no | no |
   | cookie_read_model | yes | yes | no | no | no |
   | document_sequences | no | no | no | no | no |
   | permissions* | no | no | no | no | no |
   | event_outbox | no | no | no | no | no |
   | jobs | no | no | no | no | no |

---

## MySQL-prod issues hidden by SQLite tests

The single biggest portability surface. Items where SQLite behaviour silently
passes:

1. **NULL in UNIQUE** (issue #2). SQLite UNIQUE treats NULLs as equal; MySQL
   treats them as distinct. Duplicate-active-row tests pass; production
   accepts duplicates.
2. **ENUM coercion.** `users.role/status` (`2025-10-26-110000_…:30,35`) and
   `security_events.severity` (`2025_10_27_105200_…:34-37`) are ENUM. SQLite
   translates to `TEXT CHECK(col IN (...))` and rejects invalid values.
   MySQL with `Config\Database::$default['strictOn'] = false`
   (`app/Config/Database.php:44`) silently coerces invalid values to `''`.
   **Tests are stricter than production.**
3. **`affectedRows()` semantics.** `CookieRepository.php:389-393` uses
   `affectedRows()` for the optimistic-lock check. SQLite returns rows
   matched; MySQL with `CLIENT_FOUND_ROWS=0` (default) returns rows changed.
   Idempotent updates throw false `ConcurrentModification` on MySQL only.
4. **Collation defaults.** `Config\Database::$default['DBCollat'] =
   'utf8mb4_general_ci'` (`app/Config/Database.php:38`). SQLite is byte-exact
   regardless. Case-insensitive uniqueness on `users.email`, `permissions.name`,
   `roles.slug` etc. depends on the connection default — Turkish-I, German-ß,
   full-width CJK edge cases unreachable from tests.
5. **`LOWER(name)` index usage.** `CookieModel.php:96` does
   `WHERE LOWER(name) = ?`. SQLite ignores function-based predicates at plan
   time anyway; MySQL refuses the unique index on `name` and table-scans.
   Fast in tests, slow in prod.
6. **FK constraint enforcement.** The SQLite `:memory:` test DSN in
   `phpunit.xml.dist:58-64` does not set `PRAGMA foreign_keys = ON`.
   MySQL InnoDB enforces FKs. Tests cannot exercise orphaning or cascade
   behaviour.
7. **`TIMESTAMP` timezone conversion** (issue #4). SQLite stores as TEXT;
   MySQL converts session-tz ↔ UTC. Auth-token expiry math diverges by N
   hours on any server with a non-UTC `time_zone`.
8. **`JSON` column.** Only on `security_events.metadata`. SQLite stores as
   TEXT.
9. **`TINYINT(1)` vs `BOOLEAN`.** Mixed: `cookies.is_active TINYINT(1)`,
   `sessions.revoked BOOLEAN`, `refresh_tokens.revoked BOOLEAN`,
   `login_attempts.success BOOLEAN`, `settings.is_secret TINYINT(1)`. CI4
   maps both; SQLite stores as INT; MySQL stores `TINYINT(1)` natively and
   `BOOLEAN` as an alias for `TINYINT(1)`. No functional bug today, just
   non-uniform.

---

## Index strategy assessment per table

| Table | Verdict | Key gaps |
| --- | --- | --- |
| cookies | mediocre | UNIQUE doesn't fire under MySQL NULLs; no `(is_active, deleted_at)` composite |
| cookie_read_model | mediocre | low-card `available` indexed; no FK on `cookie_id`; collation drift on `name_search` |
| users | poor | no `(email, deleted_at)`; no `INDEX(deleted_at)`; `failed_login_attempts` signed |
| rate_limit_attempts | OK | two singletons; composite `(identifier, expires_at)` would help |
| password_history | OK | composite by intent via PK + FK |
| refresh_tokens | poor | `jti` is INDEX not UNIQUE |
| token_blacklist | good | `jti` UNIQUE; `expires_at` indexed |
| password_reset_tokens | good | `token_hash` UNIQUE |
| sessions | OK | composite `(user_id, expires_at)` would help session limit check |
| login_attempts | OK | composite `(email, created_at)` would help brute-force detection |
| security_events | OK | composite `(severity, created_at)` would help dashboards |
| audit_log | mediocre | no composite for actor-timeline; no index on `tenant_id` |
| idempotency_keys | good | UNIQUE composite + `expires_at` indexed |
| permissions/roles/joins | poor | no FKs; no tenant; no audit |
| event_outbox | good | composite `(status, available_at)` matches relay claim |
| document_sequences | good | UNIQUE `(series, scope)` is the access path |
| settings | mediocre | UNIQUE doesn't fire under MySQL NULL tenant; no `version` |
| jobs | OK | missing `INDEX(reserved_at)` for reaper |
| attachments | mediocre | no soft-delete index; no FK on `uploaded_by` |
| notifications | poor | two singletons where one composite suffices |

---

## Missing FKs

- `role_permissions(role_id) → roles(id)` (`2026-05-19-200200_…:109-127`)
- `role_permissions(permission_id) → permissions(id)` (same file)
- `user_roles(user_id) → users(id)` (`2026-05-19-200200_…:129-151`)
- `user_roles(role_id) → roles(id)` (same)
- `notifications(user_id) → users(id)` (`2026-05-20-100100_…:40-44`)
- `attachments(uploaded_by) → users(id)` (`2026-05-20-100000_…:86-91`)
- `audit_log(actor_id) → users(id)` (`2026-05-19-200000_…:53-59`; needs to
  become nullable+FK)
- `cookie_read_model(cookie_id) → cookies(id)` (`2026-05-20-200000_…:33-38`)
- `idempotency_keys(actor_id) → users(id)` (`2026-05-19-200100_…:51-57`)
- `*.tenant_id → tenants(id)` — no tenants table exists yet; document as
  blocked-by-multi-tenancy.

The 6 auth FKs that DO exist (`password_history`, `refresh_tokens`,
`password_reset_tokens`, `sessions`, `login_attempts`, `security_events` →
`users`) are all `CASCADE/CASCADE` or `SET NULL/CASCADE` — uniform. Good.

---

## Per-table notes for new sprint additions

**`audit_log`** (`2026-05-19-200000`)

- `BIGINT UNSIGNED` id — good.
- `actor_id NOT NULL DEFAULT 0` — should be nullable + FK (issue #5).
- `tenant_id` nullable, unindexed → tenant-scoped queries scan.
- `correlation_id VARCHAR(128)` — too wide (issue 4 above).
- Missing composite `(actor_id, occurred_at)` for timeline reports.

**`idempotency_keys`** (`2026-05-19-200100`)

- `UNIQUE(id_key, actor_id)` + `INDEX(expires_at)`. Good.
- `response_body LONGTEXT` — bloat-prone; consider `MEDIUMTEXT`.
- No automated sweeper migration.

**`permissions/roles/role_permissions/user_roles`** (`2026-05-19-200200`)

- No FKs on join tables (see Missing FKs).
- No `tenant_id` on `roles` or `user_roles` — single-tenant RBAC only.
- No `granted_by` on `user_roles` (only `granted_at`).
- `permissions.name VARCHAR(100) UNIQUE` but no length floor / character
  validation in the DDL.

**`event_outbox`** (`2026-05-19-200300`)

- `INDEX(status, available_at)` — matches relay query at
  `app/Infrastructure/Outbox/EventOutboxRelay.php:84-85`. Good.
- `INDEX(aggregate_type, aggregate_id)` — good.
- No `event_version` column → reflection-based rehydrate breaks on
  signature change (Round 1 HIGH).
- No `INDEX(occurred_at)` — debug window queries scan.

**`document_sequences`** (`2026-05-19-200400`)

- `UNIQUE(series, scope)`. Good.
- No `tenant_id` (issue #13).
- No audit columns — gapless sequences are a tax-compliance requirement,
  tamper evidence missing.

**`settings`** (`2026-05-19-200500`)

- `UNIQUE(key_name, tenant_id)` — MySQL NULL distinct issue (issue #2).
- `value_json LONGTEXT` — passes up MySQL JSON-typed column features.
- No `version` → admin-edit lost-update race.
- No `updated_by` → no audit on config change.

**`jobs`** (`2026-05-19-200600`)

- `INDEX(queue, status, available_at)` — matches `JobWorker::pickNext` at
  `app/Infrastructure/Jobs/JobWorker.php:77-79`. Good.
- `max_attempts DEFAULT 5` — drifts from app constant `MAX_ATTEMPTS = 6`
  (Round 1).
- No `INDEX(reserved_at)` for reaper (issue #10).

**`attachments`** (`2026-05-20-100000`)

- `INDEX(attachable_type, attachable_id)`. Good polymorphic lookup.
- `INDEX(storage_key)` supports upload dedup.
- No FK on `uploaded_by` (Missing FKs).
- No `INDEX(deleted_at)` (issue #9).
- `checksum_sha256 NULLABLE` — should be `NOT NULL` once upload contract
  hardens.

**`notifications`** (`2026-05-20-100100`)

- Two singletons where composite wins (issue #11).
- `level VARCHAR(16)` — should be ENUM or backed enum.
- No FK on `user_id`.

**`cookie_read_model`** (`2026-05-20-200000`)

- `cookie_id` PK only, no FK to `cookies.id` (Missing FKs).
- `name_search` no collation pin (issue #7).
- `price_minor INT(11)` overflow (issue #12).
- Three price columns (`price_minor`, `price_currency`, `price_decimal`,
  `price_formatted`) all writable independently — no DB-side consistency
  check.
- Independent `version` and `deleted_at` columns duplicate write-side state
  with no consistency contract.

---

## Verdict

**FAIL — schema is functionally close but production-fragile.**

Specifically:

- **Migration ordering** is held together by lexical accident
  (`-` < `_` in ASCII) plus a CI4 regex that's looser than the declared
  `timestampFormat`. Pre-emptive rename of the 7 underscore-stemmed files to
  dash-stemmed is mandatory before the next CI4 upgrade.

- **MySQL NULL UNIQUE semantics** silently break `cookies`, `settings`, and
  most damagingly `users` duplicate-prevention. SQLite tests pass because
  SQLite treats NULLs as equal in UNIQUE. Cloning Cookie as the template
  produces per-entity duplicate-allowance the moment multi-tenancy lands.

- **Schema drift between Cookie and every other table** is severe: only
  Cookie carries the five ERP-baseline columns documented in its own
  docblock. Users, settings, attachments, notifications, audit_log,
  permissions et al. each pick a subset. No automated check guards drift.

- **Auth subsystem uses `TIMESTAMP`** while the rest uses `DATETIME`.
  Timezone semantics diverge silently between server timezone and
  comparison logic; SQLite tests cannot detect the bug. Refresh-token and
  session-expiry queries carry a silent N-hour offset on non-UTC MySQL.

- **ENUM columns silently coerce on MySQL non-strict** (the production
  default per `Config\Database::$default['strictOn'] = false`). SQLite
  CHECK constraints catch invalid values; MySQL accepts the empty string.
  `users.role` / `users.status` corruption-prone in prod.

- **Foreign keys are missing on every RBAC join table** and most cross-
  domain relations added in the recent sprints. SQLite test config doesn't
  enable FK enforcement; MySQL InnoDB does. Orphaning invisible in tests.

- **Indexes are mostly OK** but several common paths force file-sorts
  (`notifications` unread feed) or table-scans (cookie LIKE, soft-delete
  predicates on `users` and `attachments`).

**Pre-clone blocking schema fixes**:

1. Add `UNIQUE(email, deleted_at)` to `users` (drop the inline
   `unique=true` on `email`).
2. Rename the 7 `2025_10_27_*` migrations to `2025-10-27-…`.
3. Pin `DBCollat = utf8mb4_unicode_ci` globally; add `tableOptions
   COLLATE` to every `createTable` call.
4. Pick a `deleted_at` sentinel (e.g. `'9999-12-31 23:59:59'`) or move to
   a partial-unique-index pattern. Don't rely on the current composite
   under MySQL.
5. Add FKs on `role_permissions`, `user_roles`, `notifications`,
   `attachments`, `audit_log` (with nullable `actor_id`),
   `cookie_read_model`.
6. Switch `users.role` / `users.status` to VARCHAR + app-side validation,
   OR set `strictOn => true` in production config.
7. Replace `TIMESTAMP` columns in the auth subsystem with `DATETIME`, OR
   document and assert the UTC server-timezone contract at boot.
8. Add the ERP-baseline columns (`tenant_id`, `version`,
   `created_by/updated_by/deleted_by`) to `users`, `settings`,
   `attachments`, `notifications`, `document_sequences`, and the RBAC
   tables. Define a `BaselineMigration` trait.
9. Add a parallel MySQL CI job — the SQLite test suite cannot detect the
   most expensive class of schema bugs in this template.

The shape of the schema is reasonable. The implementation is held together
by small coincidences and SQLite's forgiveness; production MySQL will
surface real bugs in the first weeks of multi-tenant traffic.
