# 15 — Views / Helpers / Migrations / Tests

## Files audited

- app/Views/layout.php
- app/Views/layouts/shell.php
- app/Views/layouts/auth.php
- app/Views/partials/_breadcrumbs.php
- app/Views/partials/_flash.php
- app/Views/partials/_form_field.php
- app/Views/partials/_pagination.php
- app/Views/partials/_sidebar.php
- app/Views/partials/_user_menu.php
- app/Views/dashboard.php
- app/Views/cookies/index.php
- app/Views/admin/users/index.php
- app/Views/auth/login.php
- app/Views/auth/register.php
- app/Helpers/auth_ui_helper.php
- public/assets/css/auth.css
- public/assets/js/delete-confirm.js
- app/Database/Migrations/ (21 files, listed)
- tests/Support/UnitTestCase.php
- tests/Support/IntegrationTestCase.php
- tests/Support/FeatureTestCase.php
- tests/Support/Factories/CookieFactory.php
- tests/Support/Factories/UserFactory.php
- tests/bootstrap.php
- phpunit.xml.dist

## View findings

### CRITICAL

- **CSP-violating inline `style` attribute on layout shell.** `app/Views/layout.php:39` (`style="min-height: calc(100vh - 56px);"`) and `app/Views/partials/_sidebar.php:36` (`style="min-width: 240px;"`) both inject inline CSS. The header docblock of `auth.css` literally advertises "CSP-clean, no inline styles," yet the authenticated shell is not. A strict `style-src 'self'` will blank the chrome.
- **CDN assets pin Bootstrap 5.3.0 with SRI that is fixed, but no fallback / no version lock to local copy.** `app/Views/layout.php:24-27, 53-55`. If jsdelivr is throttled or compromised in a way that still validates SRI (impossible by design — fine), but if the CDN simply 404s, the entire admin UI loses Bootstrap CSS+JS and the login/auth shell has no shared base because it uses `/assets/css/auth.css` instead. Two different design systems for one app. Recommend vendoring Bootstrap into `public/assets/vendor/bootstrap/` and keeping CDN as optional.

### HIGH

- **`cookies/index.php` will clone badly per entity.** `app/Views/cookies/index.php:1-96` re-rolls its own search box, table, pagination — none of it goes through `partials/_pagination`, `partials/_form_field`, or any list partial. Same disease is already present at `app/Views/admin/users/index.php:1-117`, which duplicates the same ~100 lines with three extra filter selects. When the next entity arrives (Order, Invoice…) it will be a third copy. The `partials/_pagination.php` partial exists but **nothing in the codebase uses it** — both index views hand-roll Previous/Next inside the view. Severity HIGH because every future entity multiplies the duplication, and changing pagination semantics means hunting N copies.
- **Hard-coded strings in core views — `lang()` is half-adopted.** `app/Views/dashboard.php:5,12,14,18,21,23` ("Dashboard", "Cookies", "Manage…", "Open Cookies", "Users", "Open Users") are raw English. Same in `cookies/index.php:6,8,16,27,35-41,57-66` ("Cookies", "Create New Cookie", "Search cookies…", "No cookies found.", every `<th>` label, "Active"/"Inactive", "View", "Edit"). `admin/users/index.php` is the same. `auth/login.php:13,21` use raw "Email"/"Password" labels but mix `lang('App.sign_in')` in the heading. `auth/register.php:1` sets `$title = 'Register'` instead of `lang('App.register')`. Locale switcher in the shell works, but switching it changes essentially nothing.
- **No permission gating in the dashboard tiles or cookies/index actions.** Sidebar (`_sidebar.php:43`) correctly uses `can()`, but `dashboard.php:14,23` renders both module tiles unconditionally — a customer with no `cookies.view` permission sees a tile they cannot open. Same in `cookies/index.php:7` (Create button always rendered, even without `cookies.create`), `:65-66` (View/Edit always rendered), and `admin/users/index.php:7,85-87` (Create/View/Edit/Reset-Pass always rendered).
- **`csrf_field()` missing on the cookies search form.** `app/Views/cookies/index.php:13` is `method="get"` so technically not required, but more importantly the **mutating actions** linked from this view (Edit, future Delete) need CSRF on submit forms — and nothing in `cookies/index.php` or `admin/users/index.php` shows where delete posts originate. `delete-confirm.js` listens for `submit` events but there is no corresponding form with `data-confirm` in any audited view; the JS is dead code so far.

### MEDIUM

- **`auth.css` does not use any locale-aware fallback / no theme variables.** `public/assets/css/auth.css:1-17`. Single hard-coded green palette (`#4CAF50`), no CSS custom properties, no dark mode hook. Will multiply badly when the auth shell needs branding per-tenant.
- **Auth screens drop the entire Bootstrap design system.** `app/Views/layouts/auth.php:26` loads only `/assets/css/auth.css`. Auth pages use raw `<button>` / `<input>` styled by auth.css; ERP pages use Bootstrap classes. Two visual languages, two style budgets. Consider unifying.
- **`_user_menu.php` swallows ALL Throwables from NotificationService.** `app/Views/partials/_user_menu.php:22-26`. A broken DB connection silently sets `$unread = 0` and the page renders with no indication anything is wrong. At minimum log the exception (project has Monolog), or surface a warning badge.
- **`_user_menu.php` instantiates `new NotificationService()` directly in a view.** `:23`. Views should not new up infrastructure services. Move to a view-cell or pass `$unreadCount` in from the layout.
- **`_sidebar.php` permission prefix logic has a subtle bug.** `:13`: `$isActive = fn(string $prefix): bool => str_starts_with('/' . trim($path, '/'), '/' . trim($prefix, '/'))`. `/admin/users` is a prefix of `/admin/users-archive` — false positives once more URLs land. Use a path-segment match instead.
- **`cookies/index.php:79-93` pagination uses ad-hoc query-string concat** with `urlencode`, and `admin/users/index.php:103-110` repeats the same pattern with three extra filter parameters. `partials/_pagination.php` already solves this via `preserved`. Use it.
- **Layout title fallback uses `lang('App.dashboard')` even for non-dashboard pages.** `app/Views/layout.php:14`. A page that forgets `$title` will silently render "Dashboard · App" — confusing.
- **`shell.php` is a no-op alias with an extra `section/renderSection/endSection` round-trip.** `app/Views/layouts/shell.php:11-14` extends `layout` and re-renders the content section. It exists only as an alias. Either delete it and update the docblock in `layout.php`, or commit to `shell` as the canonical name.

### LOW

- **`dashboard.php`, `cookies/index.php`, `admin/users/index.php` use emoji + raw `<i class="bi bi-...">` Bootstrap-Icons spans** but Bootstrap Icons is not loaded anywhere. The icons render as empty boxes. Either drop the `<i>` tags or load `bootstrap-icons.css`.
- **`auth/login.php:8` and `auth/register.php:8`** hard-code lock/clip emoji into the heading. Considered LOW because cosmetic.
- **`_form_field.php` allows raw `$attributes` HTML pass-through.** `:35,56,65,84`. Documented use is intentional (DocBlock says so), but any caller that forgets to pre-escape can inject markup. Mark the attribute in the DocBlock as "MUST be pre-escaped HTML attribute string."

## Helper findings

### MEDIUM

- **`auth_ui_helper.php:46-56` swallows all `\Throwable` from `permissionService()`.** The DocBlock argues this is intentional ("misconfigured permission name never blanks the page; the backend remains the source of truth"). Fine for an `\InvalidArgumentException` from `Permission::fromString()` — but catching all `\Throwable` from the service means a DB outage hides the entire admin UI's authoring buttons and the user gets a silently-degraded interface. Recommend catching specific exceptions (`InvalidArgumentException`, `PermissionLookupException`) and re-throwing or logging the rest.
- **No tests touch `auth_ui_helper.php`.** Grep shows no spec covering `can()` / `any_of()` / `all_of()`. Helper is the security boundary for the UI; it deserves unit coverage with a mock `permissionService`.

### LOW

- **`current_actor()` instantiates a fresh `ActorResolver` on every `can()` call.** Per-page with ~20 `can()` invocations that's 20 resolver constructions and 20 session reads. Cache per-request.
- **`any_of()` / `all_of()` short-circuit correctly** but build an extra closure context via `string ...$permissionNames` — totally fine, just noting LOW for completeness.

## Migration findings

### CRITICAL

- **Inconsistent migration timestamp delimiters break ordering portability.** `app/Database/Migrations/`:
  - Dash format: `2025-01-21-000001_…`, `2025-10-26-110000_…`, `2026-05-…`
  - Underscore format: `2025_10_27_102358_…` through `2025_10_27_105200_…`

  Lexical sort happens to work today because `_` (0x5F) > `-` (0x2D), so the underscored 2025-10-27 batch sorts AFTER `2025-10-26-…` and BEFORE `2026-…`. But it is luck, not design. CodeIgniter's migration loader uses the full filename for ordering. **Pick one delimiter and rename the underscored batch.** Otherwise the next developer adding a `2025-10-27-…` file dash-delimited will land it AFTER the underscored ones unexpectedly.

### HIGH

- **`CreateUsersTable` (2025-10-26-110000):**
  - `:39-43` `failed_login_attempts` is signed `INT` — should be `UNSIGNED`. Negative counters do not make semantic sense and the application logic could underflow.
  - No `email` index beyond the implicit one from `unique: true`. With soft deletes (`deleted_at` exists), a unique on `email` blocks re-registration after a delete. Same flaw the Cookie migration explicitly fixes via `UNIQUE(tenant_id, name, deleted_at)`. Users need `UNIQUE(email, deleted_at)`.
  - No `tenant_id`, no `version`, no `created_by/updated_by/deleted_by` despite the Cookie migration declaring those as the ERP-baseline (`2025-01-21-000001_CreateCookiesTable.php:14-21` documents it). Users will need backfill migrations.
- **`AddNameToUsersTable` (2025-10-26-151606):** `:27-33` `down()` is a no-op with a defensive comment. Acceptable, but means rolling back THIS migration in isolation leaves the table in a state the original `CreateUsersTable::up()` does not produce. Document this explicitly or implement a real `dropColumn`.
- **No composite index on `notifications(user_id, read_at, created_at)`.** `2026-05-20-100100_CreateNotificationsTable.php:96-97` adds `(user_id, read_at)` and `(user_id, created_at)` separately. The most common UI query is "unread feed ordered by created_at desc" — that wants all three columns in one composite for an index-only scan. Two single indexes mean MySQL picks one and sorts the rest.

### MEDIUM

- **`cookie_read_model` lacks `tenant_id, deleted_at` uniqueness.** `2026-05-20-200000_CreateCookieReadModelTable.php:126`. `cookie_id` is the primary key, fine. But there is no foreign-key-like constraint on `tenant_id` matching the write side. If a projection rebuild misroutes a row, you can have `cookies.tenant_id=A` and `cookie_read_model.tenant_id=B` and never notice.
- **`permissions_schema` join tables have no `FOREIGN KEY` constraints.** `2026-05-19-200200_CreatePermissionsSchema.php:122-128, 144-150`. Composite primary keys catch double-grants, but a deleted role does not cascade to `role_permissions`. Test environment is SQLite which the phpunit config sets `foreignKeys=true`, so on MySQL production this is laxer than on the test DB — divergence risk.
- **`audit_log.payload_digest` is the right choice but `error_message TEXT` is not guaranteed redacted.** `2026-05-19-200000_CreateAuditLogTable.php:84-89`. Comment claims "already redacted by logger" — not verifiable from migration alone. Either truncate to a known length or document the redaction guarantee where AuditMiddleware writes.

### LOW

- **Cookies `is_active TINYINT(1)` is reasonable** but `cookie_read_model` repeats both `is_active` and `available` (`2026-05-20-200000…:90-100`). Easy to drift. Comment-only — fine.
- **`migration` filenames mix `2025-` and `2025_` patterns** (already flagged CRITICAL above for ordering; flagging here LOW for consistency-of-style).

## Test findings

### HIGH

- **`bootstrap.php` requires `tests/_support/bootstrap_libraries.php`** but the audited path is `tests/Support/` (Capital S, PSR-4). Both directories coexist (verified `ls tests/_support` → `bootstrap_libraries.php`, `Database/`, `Libraries/`, `Models/`). Mixed casing is a recipe for case-sensitive-FS surprises (CI on Linux passes; macOS dev box passes; Linux container with `cifs` mount fails). Pick one casing.
- **No factory for `Permission`, `Role`, `UserRole` (the new RBAC schema).** `tests/Support/Factories/` has only `CookieFactory` and `UserFactory`. Every E3-onward test that needs "admin with cookies.create" has to wire up roles/permissions inline. Factory for `RoleFactory::adminWith(...$permissions)` is missing.
- **`FeatureTestCase::loginAsAdmin()` writes a fake argon2id hash.** `:115` `'password_hash' => '$argon2id$v=19$m=65536,t=4,p=1$Zm9v$' . str_repeat('a', 43)`. If any feature test ever attempts a real login (POST /auth/login then asserts redirect), the password verify will fail. Pre-hash a known password via `HashedPassword::fromPlaintext(...)` so login flows are testable through the factory too.
- **`IntegrationTestCase` and `FeatureTestCase` both eagerly construct `CookieRepository` in `setUp()`** (`IntegrationTestCase.php:60-62`, `FeatureTestCase.php:68-70`). Tests that have nothing to do with cookies still pay the cost and the indirect coupling means a CookieRepository constructor change breaks every test. Move into a lazy getter or a trait `WithCookieRepository`.

### MEDIUM

- **`phpunit.xml.dist` excludes `./app/Views` from coverage** (`:43-44`). Reasonable. But `./app/Config/Routes.php` is the only Config file excluded; `./app/Config/Services.php`, `./app/Config/App.php` etc. count toward coverage and skew the metric. Either include all config or exclude the whole `./app/Config/` directory.
- **`phpunit.xml.dist` forces SQLite `:memory:`** (`:58-64`). Production is MySQL. Schema features used in migrations — `ENUM` (users.role/status), `collation='utf8mb4_unicode_ci'` (cookies.name), `TINYINT(1)`, composite `UNIQUE(...,deleted_at)` semantics with NULLs — all behave differently on SQLite. Integration tests pass on SQLite and silently allow violations of MySQL behaviour (e.g. SQLite treats NULL-vs-NULL uniqueness differently). Recommend a parallel MySQL CI job.
- **`FeatureTestCase::$session` is a public-by-convention untyped property** assigned at `:97-102`. Set but never read elsewhere in the file. If `FeatureTestTrait` consumes it, fine; if not, it's noise. Verify or remove.
- **`UnitTestCase::assertExceptionMessage()` catches `\Exception` not `\Throwable`** (`:33`). PHP 8.4 errors that extend `\Error` (e.g. `\TypeError`, `\ValueError`) escape the helper.
- **`UnitTestCase::assertArraysMatch()` uses `sort()` which only handles scalar arrays.** Multi-dimensional or object arrays will fail confusingly. DocBlock should warn or implement a recursive compare.
- **`CookieFactory::priceFromMixed()` returns `CookiePrice::fromString('')` for unknown types** (`:174`). Silent fallback to invalid VO — at minimum throw `InvalidArgumentException` on unknown type.

### LOW

- **`CookieFactory::createDatabaseRow()` defaults `id => 1`** (`:89`). Two calls without override clash on PK. Either generate or document.
- **`UserFactory::createPersistedAdmin()` defaults `id => 999`** (`:198`). Fine as a marker, but if a test then seeds and re-fetches, the autoincrement value won't be 999.
- **No factory for `Notification`, `AuditLog`, `Permission`, `Role`, `Setting`, `Attachment`** — every new domain landed in migrations since the original Cookie/User pair lacks a factory. Multiplies badly when feature-test surface grows.
- **`tests/bootstrap.php` has no `error_reporting(E_ALL)` or `ini_set('display_errors', '1')`.** PHPUnit handles errors itself, but local debugging benefits.

## Verdict

**Presentation surface is the riskiest section.** The view layer is mid-migration to the new partials (`_form_field`, `_pagination`, `_breadcrumbs`, `_flash`, `_sidebar`) but the actual entity views (`cookies/index`, `admin/users/index`) bypass every one of those partials and re-roll the same 100 lines. Cloning a new entity today would import the duplication AND skip permission gating AND skip i18n — three regressions per new domain. The auth screens use a completely separate stylesheet from the ERP shell, so the visual + behavioural split is institutionalised.

**Helpers are tight** (`auth_ui_helper.php`), but the over-broad `Throwable` catch and the lack of any tests for it mean a silent failure mode that hides admin actions. Untested security-adjacent code.

**Migrations are functional but inconsistent.** The `2025_10_27_*` underscore-delimited batch is a timing accident waiting to break ordering when the next dash-delimited migration in that window lands. `users` table is missing `UNIQUE(email, deleted_at)` (re-registration after soft-delete is blocked), missing `tenant_id/version/created_by` (diverges from the ERP-baseline that `CreateCookiesTable` explicitly documents), and `failed_login_attempts` is signed. Notifications table is missing the obvious composite index.

**Test bases are solid but Cookie-shaped.** Both `IntegrationTestCase` and `FeatureTestCase` eagerly build a `CookieRepository`; the factories cover only Cookie and User; SQLite-in-memory hides MySQL collation/uniqueness behaviour. Adding a second entity exposes the Cookie-coupling immediately.

**Top 5 to fix before E4 lands:**
1. Refactor `cookies/index.php` + `admin/users/index.php` to use `partials/_pagination` and a new `partials/_list_table` — block per-entity duplication NOW.
2. Add `can()` gating to all action buttons in `dashboard.php` + both index views.
3. Rename `2025_10_27_*` migrations to dash delimiters; add `UNIQUE(email, deleted_at)` to users; widen `users.failed_login_attempts` to UNSIGNED.
4. Move inline `style="..."` out of `layout.php` and `_sidebar.php` into `auth.css` (or a new `shell.css`), so the codebase honours the CSP-clean claim.
5. Generalise `FeatureTestCase`/`IntegrationTestCase` so they don't eagerly own a `CookieRepository`; add `RoleFactory`, `PermissionFactory`.
