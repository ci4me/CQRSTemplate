# 11 — Migrations & Schema

**Slice:** Cookie migrations + seeds + schema constraints
**Reviewer:** codeigniter4-specialist
**Date:** 2026-05-22
**Source files reviewed:** 5 (2 cookie migrations + 1 drop migration + 1 seeder + CookieModel for cross-check; glanced at 6 sibling migrations for convention comparison)

## TL;DR

The canonical `cookies` table is a solid ERP-baseline template (tenant_id, version, audit columns, soft delete, composite unique with `deleted_at`). It demonstrably fixes the MySQL "UNIQUE doesn't deduplicate NULLs" pitfall flagged in round-2 r03 V4 — but the fix is fragile (relies on `deleted_at` being non-NULL after delete; an active row's NULL `deleted_at` does still deduplicate correctly here). The big template defects are: (1) a **money/schema mismatch** — `CookiePrice` uses `Money` (minor units + Currency) but the canonical schema stores `price DECIMAL(10,2)` with no currency column, so a cloned domain inherits a contradiction; (2) the **Create-then-Drop read-model migration pair one day apart** leaves the template in a confusing "we have CQRS code structure but not the table" state with no `down()` migration unification; (3) the **seeder uses raw arrays bypassing VOs**, missing tenant_id/version/created_by, so `db:seed CookieSeeder` produces rows the rest of the system treats as half-initialised; (4) **no explicit ENGINE/charset on `createTable`** anywhere — relies on connection defaults; (5) the **CookieModel `existsByName` uses `withDeleted()` + `LOWER(name)`** which contradicts the migration's `(tenant_id, name, deleted_at)` unique key semantics (round-2 told you names are NOT reserved after soft delete; the model contradicts that).

## Verdict
READY-WITH-FIXES

## Findings

### F1 — HIGH — Money/schema mismatch: DECIMAL(10,2) write column vs Money VO (minor-units)
- **Location:** `app/Database/Migrations/2025-01-21-000001_CreateCookiesTable.php:69–73` vs `app/Domain/Cookie/ValueObjects/CookiePrice.php:42–83`
- **Observation:** The canonical write table stores `price DECIMAL(10,2) NOT NULL`. The domain's `CookiePrice` VO wraps a `Money` object that exposes `getMinorUnits()` and `getCurrency()` (with `Currency::default()` = USD). The dropped read-model migration knew this — it stored `price_minor INT`, `price_currency CHAR(3) DEFAULT 'USD'`, `price_decimal DECIMAL(10,2)`, and `price_formatted VARCHAR(32)`. After collapsing the projection into the write table (`DropCookieReadModelTable`), the write side lost the minor-units + currency columns and now silently round-trips a `Money` through a single `DECIMAL`. A cloner doing `sed Cookie/Invoice/` inherits a "money-but-not-really-money" schema for an Invoice domain where currency matters.
- **Why this is a template defect:** The template loudly advertises a `Money` value object as a best practice in `app/Domain/Shared/ValueObjects/`, yet the canonical persistence example throws away currency and uses float-prone DECIMAL conversion. Cloners will either (a) keep the lossy schema and discover the bug at first multi-currency request, or (b) silently re-introduce a different schema per domain.
- **Suggested fix:** Either (i) make the canonical table store `price_minor INT UNSIGNED NOT NULL` + `price_currency CHAR(3) NOT NULL DEFAULT 'USD'` (and let the repository hydrate from those columns), or (ii) explicitly mark `CookiePrice` as a "DECIMAL-only convenience VO" in the docblock and remove its dependence on `Money`. Option (i) matches the rest of the ERP baseline philosophy.

### F2 — HIGH — Seeder bypasses VOs, omits ERP-baseline columns (tenant_id, version, created_by)
- **Location:** `app/Database/Seeds/CookieSeeder.php:25–119`
- **Observation:** The seeder inserts ten raw arrays with `insertBatch()`. It populates `name, description, price, stock, is_active, created_at, updated_at` only — no `tenant_id`, no `version`, no `created_by/updated_by`, no `deleted_at`. After running, ten rows exist with `version=0` and `tenant_id=NULL`, which is internally consistent with the schema defaults but inconsistent with how `CreateCookieHandler` and `CookieRepository` produce rows (which set `version=1` on first save and call `setActor()`).
- **Why this is a template defect:** Round-2 r09 already flagged seeders that don't exercise the domain. For a reference template, the seeder is the first thing a junior dev runs after `migrate --all`; getting "rows that the rest of the app would never create" sets the wrong example. Worse, every cloned domain that copies this seeder pattern will recreate the same drift.
- **Suggested fix:** Rewrite the seeder to dispatch `CreateCookieCommand` through the command bus (preferred — exercises the full pipeline), or at minimum hydrate via `Cookie::create()` and persist through `CookieRepository::save()`. Also: replace `date('Y-m-d H:i:s')` (called 20 times) with a single captured `$now` for determinism in test fixtures.

### F3 — HIGH — `CookieModel::existsByName` contradicts migration's unique-key semantics
- **Location:** `app/Models/Cookie/CookieModel.php:84–98` vs `2025-01-21-000001_CreateCookiesTable.php:128–130`
- **Observation:** The migration's docblock (lines 24–27) and the actual `addUniqueKey(['tenant_id', 'name', 'deleted_at'])` exist precisely so that soft-deleted rows do **not** block re-creation. But `CookieModel::existsByName()` calls `->withDeleted()` and checks `LOWER(name)` — meaning it reports a name as "taken" even when only a soft-deleted row holds it. The docblock at line 87 ("Cookie names are reserved after soft delete") directly contradicts the migration docblock at line 25–27 ("soft-deleted rows do not block creation"). Whichever assertion is wrong, a cloner will pick the wrong one.
- **Why this is a template defect:** Two pieces of the reference template state opposite invariants. The template should pick one, document why, and make both layers agree. (Out of scope to fix the model, but in scope to call out the schema/model disagreement.)
- **Suggested fix:** Align with the migration intent: `existsByName` should NOT include `withDeleted()`, and the docblock at lines 87–89 should be corrected to "Soft-deleted names are released." The migration's behaviour is the right one for ERP.

### F4 — MEDIUM — Create-then-Drop read-model migration pair (one day apart) is a confusing template signal
- **Location:** `app/Database/Migrations/2026-05-20-200000_CreateCookieReadModelTable.php` (full) + `2026-05-21-120000_DropCookieReadModelTable.php` (full)
- **Observation:** Migration on 2026-05-20 creates `cookie_read_model` with 16 columns and 3 indexes; the migration the next day drops it. The drop's `down()` re-creates it identically. The justification (Phase 2 simplification) is documented, but a cloner running `migrate --all` will execute two no-ops back-to-back, and a cloner running `migrate:rollback` once will resurrect a table whose code consumer was deleted (the projection file is now `.example`). The `migrate:status` output will forever have two entries that exist only to delete each other.
- **Why this is a template defect:** Reference templates should present a single coherent end state, not a history-of-decisions audit trail. Either consolidate into a single "no read model" migration (delete both files, keep only the 2025-01-21 cookies table), or keep the read-model migration and re-introduce the projection so the table is actually used. The current state is "we changed our mind, and you inherit the indecision."
- **Suggested fix:** Squash both migrations: delete `2026-05-20-200000_CreateCookieReadModelTable.php` and `2026-05-21-120000_DropCookieReadModelTable.php`. The `.php.example` projection in `app/Domain/Cookie/Projections/` already preserves the schema for cloners who want to add a real projection later.

### F5 — MEDIUM — No explicit ENGINE / DEFAULT CHARSET / COLLATE on `createTable` calls
- **Location:** `2025-01-21-000001_CreateCookiesTable.php:136` and `2026-05-20-200000_CreateCookieReadModelTable.php:132`
- **Observation:** Both `createTable()` calls rely on the connection's default engine and charset. The `name` column on the canonical table pins `utf8mb4_unicode_ci` (good), but the table-level default is unspecified. On a MySQL server defaulting to `MyISAM` or `utf8mb3`, the unique-key behaviour and emoji handling diverge from what the docblock promises.
- **Why this is a template defect:** A cloned ERP template often lands in a CI environment with surprising defaults. The reference table should pass `['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci']` as the `attributes` parameter to `createTable()`. Round-2 r09 raised the same theme; the canonical table doesn't yet show the pattern.
- **Suggested fix:** `$this->forge->createTable('cookies', true, ['ENGINE' => 'InnoDB', 'CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci']);`. Apply to every migration as a convention.

### F6 — MEDIUM — No foreign keys on `tenant_id` / `created_by` / `updated_by` / `deleted_by`
- **Location:** `2025-01-21-000001_CreateCookiesTable.php:51–111, 126–134`
- **Observation:** Four audit/scope columns reference logical foreign tables (tenants, users) but no `addForeignKey()` is declared. There is no `tenants` table yet (the docblock at line 17 says "while the template is single-tenant"), but `users` does exist. A cloner who is shown the canonical entity learns "FKs are optional in this template."
- **Why this is a template defect:** ERP integrity demands FK enforcement. The reference table should either declare `created_by REFERENCES users(id) ON DELETE SET NULL` (and same for `updated_by`/`deleted_by`), or include a prominent TODO comment explaining that FKs were skipped because the tenancy story isn't fully baked. Right now there is silence.
- **Suggested fix:** Add `$this->forge->addForeignKey('created_by', 'users', 'id', '', 'SET NULL');` (and updated_by, deleted_by). For `tenant_id`, leave a documented TODO until a tenants table lands.

### F7 — MEDIUM — Filename convention inconsistent across `Database/Migrations/` (hyphens vs underscores)
- **Location:** directory listing
- **Observation:** The two cookie migrations and most newer migrations use `YYYY-MM-DD-HHMMSS_Name.php`. But four user/auth migrations use `YYYY_MM_DD_HHMMSS_Name.php` (underscores). CI4 spark `make:migration` produces hyphens by default. The mix means `ls -1` ordering by name is not strictly chronological in edge cases.
- **Why this is a template defect:** A clean reference repo should pick one style. The cookie file is on the "modern" side, but a cloner copying `sed`-style won't even notice the divergence exists.
- **Suggested fix:** Out of scope to rename others, but call this out in `.claude/skills/domain-scaffolding/SKILL.md` so future scaffolds always use hyphenated filenames.

### F8 — LOW — `cookies` table name hard-coded everywhere a cloner would `sed`
- **Location:** `2025-01-21-000001_CreateCookiesTable.php:136, 141`; `CookieSeeder.php:119`; `CookieModel.php:30`
- **Observation:** The string literal `'cookies'` appears in the migration up/down, in the seeder's `insertBatch('cookies')`, and in the model's `$table` property. A `sed s/Cookie/Foo/g` does NOT rewrite `cookies` → `foos` (it would produce `Foos` only if applied to lowercase). A cloner ends up with `class FooModel extends Model { protected $table = 'cookies'; }`.
- **Why this is a template defect:** The naming pattern is correct (lower-snake pluralised), but `sed` will not pluralise. The scaffolding skill needs to call out "rename table name to `<entity>s` separately" or use a generator script.
- **Suggested fix:** No code change; document in scaffolding skill that `sed s/Cookie/Foo/g; sed s/cookies/foos/g` is the minimum, and pluralisation is the cloner's job.

### F9 — LOW — `created_at`/`updated_at`/`deleted_at` are nullable; nothing enforces `created_at IS NOT NULL` on insert
- **Location:** `2025-01-21-000001_CreateCookiesTable.php:112–123`
- **Observation:** All three timestamp columns are `null => true`. The model has `useTimestamps = true` which sets them on insert/update, but if a row is inserted via raw SQL (e.g. seeder F2, or future ad-hoc fix scripts), `created_at` can legitimately be NULL.
- **Why this is a template defect:** ERP rows must have a non-null `created_at` for audit. Nullable is the CI4 default to support soft-delete-only models, but `created_at` and `updated_at` should be `NOT NULL`.
- **Suggested fix:** Set `'null' => false` on `created_at` and `updated_at`; leave only `deleted_at` nullable (it must be nullable for soft-delete semantics + the composite unique key).

### F10 — LOW — `is_active` is `TINYINT(1)` but model validator says `in_list[0,1]` while migration default is `1` — semantics ambiguous
- **Location:** `2025-01-21-000001_CreateCookiesTable.php:81–86` and `CookieModel.php:60`
- **Observation:** Storing booleans as `TINYINT(1)` is a MySQL convention; it works but is not type-safe. The migration defaults to `1` (active on insert), which contradicts "should default behaviour be opt-in or opt-out?" depending on the domain.
- **Why this is a template defect:** Minor, but a cloner for a domain where "inactive on creation" is the safer default (e.g. published-state on Article) inherits an opt-out posture.
- **Suggested fix:** Document the design choice in the migration docblock; consider `BOOLEAN` (which MySQL silently aliases to TINYINT(1)) for self-documenting intent.

### F11 — LOW — Composite UNIQUE on `(tenant_id, name, deleted_at)` deduplicates only when both rows agree on a non-NULL `deleted_at`
- **Location:** `2025-01-21-000001_CreateCookiesTable.php:130`
- **Observation:** Round-2 r03 V4 raised the MySQL NULL-uniqueness pitfall: in MySQL, a unique index treats every `NULL` as distinct. Here, two soft-deleted rows with the same `(tenant_id, name)` and different `deleted_at` timestamps coexist fine (desired). But two active rows with `(NULL tenant_id, 'Chocolate Chip', NULL deleted_at)` are also tolerated by the engine — MySQL allows multiple `(NULL, x, NULL)` because at least one of the three is NULL on both sides. Test: `INSERT INTO cookies(tenant_id, name, deleted_at, ...) VALUES (NULL, 'Foo', NULL, ...);` twice — both will succeed.
- **Why this is a template defect:** During the "single-tenant template" phase, `tenant_id` is NULL on every row, so the global active-name uniqueness is silently lost. The whole point of the composite unique was tenant-scoped name uniqueness; with NULL tenant it doesn't apply.
- **Suggested fix:** Either (a) make `tenant_id` `NOT NULL DEFAULT 0` (use 0 as the "default tenant" sentinel until tenancy lands), or (b) add a separate partial-ish unique workaround: a generated column `name_lower_for_uniq` plus a second `UNIQUE (tenant_id, name_lower_for_uniq) WHERE deleted_at IS NULL` — but MySQL doesn't support partial indexes, so option (a) is the pragmatic answer. Document this NULL-uniqueness behaviour in the migration docblock so cloners aren't caught out.

### F12 — INFO — `version` defaults to `0`, but `CreateCookieHandler` likely sets `1` on first save
- **Location:** `2025-01-21-000001_CreateCookiesTable.php:87–93`
- **Observation:** Optimistic-locking version defaults to `0` on insert. If the repository's save path always rewrites version, this is fine. If any insert path forgets to set version, you get `version=0` and the first update from `version=0` collides with itself. Cross-check with the repository (slice 06 scope) to be sure; from the schema's side, defaulting to `1` and incrementing-on-update is the safer convention.
- **Why this is a template defect:** Minor convention nudge; not a bug if every insert path goes through the repository.
- **Suggested fix:** Change `'default' => 0` to `'default' => 1` so that no row can ever live with `version=0`. Or remove the default entirely and force every insert to set it (catches bugs earlier).

### F13 — INFO — No covering index for `LOWER(name)` lookup used by `existsByName`
- **Location:** `CookieModel.php:96` (`->where('LOWER(name)', strtolower($name))`) vs migration indexes lines 130–134
- **Observation:** `LOWER(name)` in a WHERE clause is not sargable on the existing `(tenant_id, name, deleted_at)` unique index. Every `existsByName` call full-scans the table. For a reference template with 10 seed rows this never bites; for a cloned domain with 100k rows it does.
- **Why this is a template defect:** The collation is already `utf8mb4_unicode_ci` (case-insensitive), so `LOWER(...)` is redundant — a plain `where('name', $name)` will already match case-insensitively and use the index. The redundant `LOWER()` defeats the index AND defeats the collation choice.
- **Suggested fix:** Drop `LOWER()` from the model query; rely on `utf8mb4_unicode_ci` collation for case-insensitive matching. (Out of scope to edit, but the schema is paying the cost.)

## What is correct / praiseworthy

- The canonical migration explicitly carries the ERP-baseline columns (`tenant_id`, `version`, `created_by`, `updated_by`, `deleted_by`) with docblock rationale tying each to a baseline identifier (B7/B9/B10/B11/B16). This is the template's strongest feature.
- The composite `UNIQUE(tenant_id, name, deleted_at)` and the supporting docblock prove that round-2's "names not reserved after soft delete" feedback was actually acted on.
- The `name` column collation is explicitly pinned (`utf8mb4_unicode_ci`), not left to connection defaults. Most ERP repos forget this.
- Indexes are deliberate (is_active, deleted_at, tenant_id) and the rationale lives in the docblock.
- `down()` is present and reversible on every migration, including the unusual Drop migration that re-creates the table on rollback — that's the right pattern for one-way data-loss migrations.
- `declare(strict_types=1)` and `final`-ish class style consistent with the rest of the codebase.
- The read-model migration deliberately stored `price_minor` + `price_currency` + `price_decimal` + `price_formatted` — proof that the team understood the money-modelling pattern even if the canonical table doesn't apply it (see F1).

## Top 3 fixes before cloning
1. **Decide and unify the money representation** (F1): either add `price_minor` + `price_currency` to the canonical `cookies` table or drop `Money` from `CookiePrice`. The current contradiction is what cloners will copy unchanged.
2. **Reconcile the schema↔model story on "names reserved after soft delete"** (F3): pick one answer (migration says "released", model says "reserved"), make both layers agree, and update both docblocks.
3. **Squash the Create-then-Drop read-model migration pair** (F4) and rewrite the seeder to use the domain pipeline (F2). The reference template should land in a single coherent state and exercise its own command bus.

---

**Severity counts:** CRITICAL 0 | HIGH 3 | MEDIUM 4 | LOW 4 | INFO 2
**Top finding:** F1 — Canonical `cookies.price DECIMAL(10,2)` discards the currency that `CookiePrice`/`Money` VOs carry, so every cloned domain inherits a money/schema contradiction.
