# Review (codeigniter4-specialist) — v2 Remediation Plan

## Verdict
APPROVED-WITH-CHANGES

## Strengths
- Phase 0 sequencing is correct: E01 (drop `force="true"` SQLite lock) before
  any MySQL-claim verification, then E03 (`sessionVariables`) before destructive
  schema epics. CI4's `database.tests` group only takes effect once the forced
  env is removed; the plan recognizes that.
- E13 explicitly relocates `web_auth` to the route group inside
  `registerRoutes()` AND deletes the URI deny-list entry from `Filters.php` —
  that ordering is correct (CI4's filter precedence applies URI-pattern
  filters AND route-group filters cumulatively, so leaving both = double
  application; removing both = open by default; the plan removes one and
  attaches the other atomically).
- E09 sequences the destructive `cookies.price` swap correctly: VO/schema
  change, then seeder rewrite via `CreateCookieCommand` (so the data path
  IS the test). The migration adds new columns and FKs alongside, which
  CI4 Forge supports in one `createTable` rewrite (E09 is a fresh table
  shape, not a column-rename — that simplification is correct).
- E12 mirrors `jobs`-table lease shape onto `event_outbox` (the slice 18
  praise list calls this out). Using `INSERT IGNORE` on `event_uuid`
  UNIQUE plus `SELECT ... FOR UPDATE SKIP LOCKED` is the canonical
  MySQL 8 path and the plan names both.
- The `RegisterProjectionsNoop` trait + `DomainServiceProviderInterface`
  extension (E13) closes the asymmetry that slice 08 F4 flagged without
  forcing every existing provider to grow a no-op method body.

## Required changes

1. **E09 migration is NOT just a rename — it is destructive + data-loss
   without a backfill step.** The plan describes "rename column" semantics
   (`...RenameCookiesPriceToMinorUnitsWithCurrency.php`) but the underlying
   change is: drop `price DECIMAL(10,2)`, add `price_minor BIGINT UNSIGNED`
   + `price_currency CHAR(3) NOT NULL`. Existing rows lose their value
   unless the migration's `up()` does (a) ADD new columns nullable, (b)
   UPDATE backfilling `price_minor = ROUND(price * 100)` and
   `price_currency = 'USD'`, (c) ALTER both to `NOT NULL`, (d) DROP `price`.
   The seeder rewrite (good) doesn't fix already-seeded production data.
   **Add an explicit four-step migration sketch to E09's "Files touched."**

2. **E13's controller constructor-injection is incompatible with CI4 4.6
   route closures and `initController()` lifecycle as written.** CI4
   instantiates controllers via `Factories::controllers($class)` which
   resolves constructor args via `Services::__callStatic` reflection ONLY
   for services registered in `Config\Services`. `CommandBus`, `QueryBus`,
   and `ActorResolver` are registered there today (good), but `Logger` is
   not domain-channeled. **E13 must add either (a) a factory binding
   `Services::cookieController(): CookieController` that wires the channel-
   specific logger, OR (b) document that the controller takes the generic
   `\Psr\Log\LoggerInterface` and derives the channel via a per-request
   tag.** Otherwise constructor injection will fail at boot with
   "argument $logger has no resolver" — not caught by static analysis.

3. **E03 `sessionVariables` does not propagate through CI4's connection
   pool on `Database::connect('tests')` calls.** CI4's `MySQLi::reconnect()`
   re-runs `_setSessionVariables()` but `BaseConnection::reconnect()`
   re-uses the prior config array shallow-copy; if `IntegrationTestCase`
   calls `Database::connect()->reconnect()` (which the test helpers do
   between feature tests for isolation), the session vars are reapplied
   only if `sessionVariables` is on the connection's `$this->` properties,
   not a runtime override. **E03 must add a regression assertion
   `test_session_variables_pinned_after_reconnect` (not just at-connect)
   to the gate list, and explicitly verify the `tests` connection group
   inherits the same `sessionVariables` (a separate group can shadow it).**

## Missing items

- **E09 / E11 / E12 don't address `numberNative=true` rollout risk.**
  Flipping `numberNative` from `false` → `true` (slice 18 F-C1 patch)
  changes `version` and `is_active` from PHP string to int — every
  `=== '1'` / `=== '0'` comparison in repository/seeder/handler code
  will silently break. Plan ships the flip in E03 but doesn't allocate
  a sweep for stringly-typed numeric comparisons. Add to E11.
- **`Routes.php` auto-mount try/catch (08/F17) is allocated to E13 but
  the plan doesn't specify behavior on partial failure.** CI4's routing
  layer is built once at boot; a single provider's `registerRoutes()`
  throwing leaves `$routes` in an inconsistent state. Plan should pick
  fail-fast (rethrow after logging) — the alternative leaves cloned
  domains silently un-routed in production.
- **`Config\Cookie` framework-name collision (08/F7) is deferred
  indefinitely.** Plan documents it but doesn't schedule the rename.
  Every cloner pays the grep ambiguity tax forever. Add as a Phase-5
  follow-up epic or commit to a name in E15.
- **CI4 Forge generated-column support (F-S1 fix) requires raw SQL.**
  E09's `name_active_key` generated column cannot be expressed through
  `addField()` — Forge has no `generated` key. Plan must call out
  `$this->db->query('ALTER TABLE cookies ADD COLUMN ... GENERATED ALWAYS
  AS (...) STORED')` after `createTable()`. Not allocated.
- **`POST /cookies/{id}/delete` HTML-form convention (09/F9) deferred to
  documentation.** No sibling API-controller reference. The "ship an
  Api/CookieApiController" suggestion in slice 09 isn't allocated to
  any epic. Either accept (and document) or schedule.

## CI4-specific risks

- **Filter precedence (E13):** CI4 evaluates filters in order:
  `globals.before` → URI-matched `filters` → route-group `filter` → route
  `filter`. Plan removes `cookies/*` from URI deny-list AND attaches
  `web_auth` on group — correct. But verify `Filters.php` `globals.before`
  doesn't already short-circuit auth for `cookies` via the negate list.
  Also: `permission:cookie.manage` syntax only works if `PermissionMiddleware`
  reads the `cookie.manage` arg via `$arguments` — plan should add a smoke
  test.

- **Model lifecycle (E09, E11):** Making `CookieModel` `final` (E13/E17)
  is correct, but CI4's `Model::$beforeInsert`/`$beforeUpdate` callbacks
  are reflection-resolved; `final` doesn't break those, but
  `useSoftDeletes=true` combined with the new `(tenant_id, name,
  deleted_at)` UNIQUE means the model's auto-generated `WHERE deleted_at
  IS NULL` on every `find*()` will hide the soft-delete-uniqueness
  contract from PHPUnit unless tests use `withDeleted()`. Not flagged.

- **Forge migration gotchas (E09, E12):**
  - `addField` with `'type' => 'JSON'` (E12 outbox payload) is silently
    converted to `TEXT` by CI4's SQLite Forge — tests will pass on JSON
    type but production MySQL gets the JSON validation. Add MySQL-only
    gate for JSON-shape assertions.
  - `addForeignKey()` requires the referenced table to exist at
    migration time — E09's FK on `users(id)` only works because
    `CreateUsersTable` (2025-10-26) precedes it; the rename to
    `2026-05-22-100000_...` keeps that order. Verify ordering after
    rename.
  - `dropTable($name, true, true)` for `cascadeForeignKeys` is needed
    on the read-model squash (E09) — plan says "delete the migration
    files" but if any dev has already run them, `migrate:rollback`
    won't find the down. Add a `MigrationsHistoryFixup.php` step.

- **`ServiceProviderRegistry` re-entrance (08/F8):** E13 moves
  `$providersRegistered = true` BEFORE `registerAll()` — correct, but
  this changes idempotency: a recursive call now no-ops silently instead
  of recursing. Confirm `EventDispatcher` resolution path still gets the
  fully-populated registry. Add assertion
  `test_event_dispatcher_resolved_during_provider_registration_still_works`.

- **`Filters.php` test-env CSRF disable (slice 13/14 noted):** E14 wires
  `can()` gates into views but doesn't address the controller-side gap
  that CSRF is silently disabled in tests — leaves
  `test_csrf_rejection` permanently un-implementable. Allocate to E18.

---

Review written to `/home/gabriel/Documentos/CQRSTemplate/.audit/round3/REVIEW-ci4.md`.
