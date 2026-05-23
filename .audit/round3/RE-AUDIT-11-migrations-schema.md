# RE-AUDIT — Slice 11 — Migrations & Schema

**Reviewer:** codeigniter4-specialist
**Date:** 2026-05-23
**PRs reviewed (via gh pr diff):** #30 (E01), #31 (E03), #38 (E12.5), #41 (E11)
**Original slice:** `.audit/round3/11-migrations-schema.md`
**Local branch base:** `integration/phase-1-cookie-foundation` (E04+E05+E06+E07 merged only)
**Verification note:** none of the open PRs touch the canonical
`2025-01-21-000001_CreateCookiesTable.php`, `CookieSeeder.php`, or the
`Create…/Drop CookieReadModelTable` pair. F1/F2/F4/F6/F7/F8/F9/F10/F11/F12
remain entirely OPEN. Only F3 (model↔migration contradiction) and F13
(`LOWER()` defeats the index) are fixed by PR #41; F5 is partially
relieved by PR #31 (connection-level DBCollat alignment), but no
migration emits explicit `ENGINE`/`CHARSET`/`COLLATE` attributes on
`createTable()`.

## Closure matrix

| F#  | Sev   | Title                                                           | Status            | Evidence                                                                                                                                                                                                          |
|-----|-------|-----------------------------------------------------------------|-------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| F1  | HIGH  | Money/schema mismatch: DECIMAL(10,2) vs `Money`/Currency        | OPEN              | `2025-01-21-000001_CreateCookiesTable.php:69–73` still `DECIMAL(10,2)`; no `price_minor`/`price_currency`. E09 (multi-currency migration) not yet opened. None of PRs #29–#42 touch the cookies table schema.     |
| F2  | HIGH  | Seeder bypasses VOs, omits tenant/version/audit columns         | OPEN              | `app/Database/Seeds/CookieSeeder.php:25–119` unchanged — 10 raw `insertBatch` rows lacking `tenant_id`, `version`, `created_by/updated_by/deleted_by`. No PR touches the file.                                    |
| F3  | HIGH  | `CookieModel::existsByName` contradicts migration intent (B16/B17) | CLOSED by PR #41 | Diff drops `withDeleted()` + `LOWER()`; docblock now reads "Scope EXCLUDES soft-deleted rows… closes 06/F1." Same fix on `existsByNameExcludingId`. Repository port now takes `CookieName` VO. Aligns with B16/B17. |
| F4  | MED   | Create-then-Drop read-model migration pair (1-day apart)         | OPEN              | Both `2026-05-20-200000_CreateCookieReadModelTable.php` and `2026-05-21-120000_DropCookieReadModelTable.php` still present in `app/Database/Migrations/`. No PR squashes the pair.                               |
| F5  | MED   | No explicit ENGINE/CHARSET/COLLATE on `createTable()`            | PARTIAL via PR #31 | PR #31 aligns `DBCollat = utf8mb4_unicode_ci` at the connection level (fixes the round-2 hazard for default). But no migration — including PR #38's new `processed_events` — passes the `attributes` array to `createTable()`. PR #38 explicitly documents the choice as "rely on connection defaults so SQLite tests don't choke on `ENGINE = 'InnoDB'`." Accepted as a pragmatic trade-off, but the underlying finding (no in-migration declaration) is unchanged. |
| F6  | MED   | No foreign keys on tenant_id/created_by/updated_by/deleted_by    | OPEN              | Migration unchanged; `addForeignKey()` absent. No PR addresses.                                                                                                                                                   |
| F7  | MED   | Inconsistent filename convention (hyphens vs underscores)        | OPEN              | Listing still shows four `YYYY_MM_DD_…` user/auth migrations alongside the hyphenated newer set; no rename PR.                                                                                                    |
| F8  | LOW   | `'cookies'` literal hard-coded in migration/seeder/model         | OPEN              | Out-of-scope for code fix; scaffolding-skill doc note still pending (E15 partial in PR #40 doesn't address pluralisation).                                                                                        |
| F9  | LOW   | `created_at`/`updated_at` nullable                               | OPEN              | Migration unchanged; both still `'null' => true`.                                                                                                                                                                 |
| F10 | LOW   | `is_active TINYINT(1)` default=1 (opt-out posture)               | OPEN              | Migration unchanged.                                                                                                                                                                                              |
| F11 | LOW   | Composite UNIQUE allows duplicate active rows when tenant_id NULL | OPEN              | Migration unchanged; `tenant_id` still nullable. F11 will silently fire on every cloner using the template in single-tenant mode.                                                                                |
| F12 | INFO  | `version` defaults to `0`                                        | OPEN              | Migration unchanged.                                                                                                                                                                                              |
| F13 | INFO  | No covering index for `LOWER(name)`; `LOWER()` defeats collation | CLOSED by PR #41 | Diff: `->where('LOWER(name)', strtolower($name))` → `->where('name', $name)` on both `existsByName` and `existsByNameExcludingId`. Index is now hit; collation handles case-insensitivity.                       |

**Counts:** CLOSED = 2 (F3, F13). PARTIAL = 1 (F5). OPEN = 10 (F1, F2, F4, F6, F7, F8, F9, F10, F11, F12).

## New issues

### N1 — INFO — `processed_events` migration relies on connection defaults for `ENGINE`/`CHARSET`/`COLLATE` (PR #38)

- **Location:** `app/Database/Migrations/2026-05-22-100000_CreateProcessedEventsTable.php:94` (new in PR #38)
- **Observation:** The new dedup table is created with `createTable('processed_events', true)` — no `attributes` parameter, so `ENGINE`/`CHARSET`/`COLLATE` come from the active connection. The class docblock explicitly justifies the choice: "passing them here would emit literal `ENGINE = 'InnoDB'` syntax that SQLite (used in the unit test suite) refuses to parse." With PR #31 also merging (connection-level `DBCollat = utf8mb4_unicode_ci`), the MySQL lane will get the right defaults at runtime, but the migration itself is silent. This matches the existing F5 pattern across all 24 cookie/auth migrations — it is consistent, but the consistency is "everyone is silent," not "everyone declares."
- **Why it matters:** Two of the row-budget calculations in the docblock (`CHAR(36)*4 + VARCHAR(190)*4 = 904 bytes`) depend on the column charset being utf8mb4. If a future operator changes the connection charset (or runs migrations against a MySQL with a different `character_set_database`), the calculation silently breaks the 3072-byte InnoDB key prefix limit. Declaring the attributes on `createTable()` would make the assumption load-bearing in the same file as the calculation.
- **Suggested fix:** Either (a) accept the connection-default convention project-wide and add a one-line `Database.php` invariant assertion at boot, or (b) introduce a small `MigrationHelpers::innodbUtf8mb4()` factory that returns the standard `attributes` array and only emits it when the active driver is MySQLi. Option (b) keeps the SQLite unit-test compatibility intact while making the InnoDB+utf8mb4 contract local to each migration.

### N2 — INFO — `processed_events` PK shape is correct, but missing index on `processed_at` for ops "when did we fire?" queries

- **Location:** `2026-05-22-100000_CreateProcessedEventsTable.php:88–90`
- **Observation:** The docblock states the column exists "for observability only" so operations can answer "when did we fire this side effect?" without joining the outbox. But the only key on the table is the composite primary `(event_id, listener_class)`. Range queries like `WHERE processed_at BETWEEN x AND y` will full-scan the table, growing linearly with traffic. Once the dedup table holds millions of rows (its expected steady state — see slice 05/F5) the ops queries the docblock promises will degrade.
- **Why it matters:** The intent expressed in the docblock contradicts the index layout. Either drop the observability claim or back it with an index. Storage cost of a single secondary index on `processed_at` is negligible compared to the row-key footprint.
- **Suggested fix:** Add `$this->forge->addKey('processed_at');` before `createTable()`.

### N3 — INFO — Cross-PR coupling: PR #41 corrects `CookieModel::existsByName` (F3), but the model still has DB-shape validation duplication that the seeder will trigger

- **Location:** `CookieModel::$validationRules` (post-PR #41) + `CookieSeeder` (unchanged)
- **Observation:** PR #41 narrows the model's `$validationRules` to DB-shape only (`required|max_length[100]` for `name` etc.) — good. But the seeder still inserts 10 rows with `name` strings of various lengths via `insertBatch()`, bypassing model validation entirely (`insertBatch` doesn't run validation by default in CI4). Combined with F2, this means PR #41's careful validation-narrowing has no protective effect on seeded data.
- **Why it matters:** If F2 is fixed by routing the seeder through `CookieRepository::save()`, the freshly narrowed validators will run; if F2 is fixed by routing through the command bus, the VO invariants run instead. Both are correct outcomes; the seeder's continued use of raw `insertBatch` makes either fix necessary, not optional.
- **Suggested fix:** Tackle F2 in the same PR as any future schema/validation change so the seeder doesn't silently produce data the rest of the system rejects on update.

## Verdict shift

**Was:** READY-WITH-FIXES (HIGH 3 / MED 4 / LOW 4 / INFO 2)
**Now:** READY-WITH-FIXES — unchanged overall standing. PR #41 closes 2 findings (F3 HIGH, F13 INFO) and PR #31 partially relieves F5, but the three highest-impact items (F1 money/schema, F2 seeder bypass, F4 read-model migration pair) are entirely untouched because their owning epics (E09 multi-currency, E12 outbox hardening, plus a missing seeder-rewrite epic) have not been opened. New PR #38 adds one well-shaped migration with two minor INFO follow-ups (N1, N2). Severity matrix on the remaining slice is now: **HIGH 2 / MED 3 / LOW 4 / INFO 2 (+ 1 new INFO).**

## Top 3 still-open items

1. **F1 (HIGH) — money/schema mismatch.** `cookies.price DECIMAL(10,2)` still discards the currency that `Money`/`CookiePrice` carries. Every cloner copying the canonical migration inherits the contradiction. Blocked on E09 (multi-currency migration) being opened.
2. **F2 (HIGH) — seeder bypasses VOs.** `CookieSeeder::run()` still does 10 raw `insertBatch` rows with no `tenant_id`/`version`/`created_by`. PR #41's validation-narrowing depends on inserts going through the model; the seeder doesn't. Needs a seeder-rewrite epic that either dispatches `CreateCookieCommand` or calls `CookieRepository::save()`.
3. **F4 (MED) — Create-then-Drop read-model migration pair.** Both files (2026-05-20 and 2026-05-21) still present. `migrate --all` runs two no-ops back-to-back; `migrate:rollback` once resurrects an orphaned table whose projection is `.example`. Squash into a single no-read-model end-state.

