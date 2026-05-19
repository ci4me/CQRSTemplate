# ERP Template Audit

Date: 2026-05-19 (updated)
Project: CodeIgniter 4 CQRS Template
Audit target: use this as the base/template for a new ERP, with `Cookie` as the canonical entity template.

> **Status update (after stabilization sprint):** Phases 1, 2 (most), and 3 (most) are now implemented on branch `stabilization/erp-foundation`. See the **"Implemented since this audit"** section at the bottom for the full delta. Findings marked with **[DONE]** below have been resolved.

## Executive Summary

The skeleton has moved forward materially since the morning audit. The hard blockers (broken routes, dead test bootstrap, dependency advisories, missing repository interface, money-as-float, pagination clamping, stock validation, soft-delete leak) are resolved or substantially better.

It is still not safe to clone `Cookie` 30 times for an ERP. Three classes of problems remain:

1. **Hard correctness gaps that would multiply per entity**: no audit-actor context, no tenant scoping, no aggregate versioning, events dispatched outside transaction boundaries, query handlers leak domain entities to controllers.
2. **Security-by-default failures**: web routes for `/cookies` and `/admin/users` are accessible without any authentication filter; session fixation not handled on login; rate-limit/role middleware wired but not validated for distributed deployment.
3. **Template ergonomics**: zero reusable view partials, no ERP shell (sidebar, breadcrumbs, user menu, permission-aware actions), no i18n surface, demo branding still visible at `/`.

Recommended status: **stabilize Cookie as a true golden module, harden infra defaults, add ERP-shaped capabilities (tenant, audit, permissions, money/currency, transactions, outbox) before scaffolding new domains.**

## Status Delta vs Morning Audit

Verified by running tooling now:

| Item | Morning | Now |
|---|---|---|
| `composer phpstan` (level 8) | Passed | Passed (0 errors, 118 files) |
| `composer phpcs` | Passed | Passed (62 files, 0 violations) |
| `composer audit` | 2 advisories (jwt low, phpunit high) | No advisories |
| `php spark routes` | Failed (`jwt\|role` alias) | Works; filter syntax fixed to array form |
| `composer test` | Bootstrap failure | Runs: 332 tests, 869 assertions, 2 skipped |
| AbiSageIntacct missing bootstrap | Hard failure | Guarded with `is_file()` (tests skip gracefully) |
| Cookie repository interface | Concrete only | `CookieRepositoryInterface` exists under `Ports/`, handlers depend on it |
| `decreaseStock(-5)` invariant | Silently accepted | Validates `quantity > 0`, throws |
| `CookiePrice` float precision | Arbitrary decimals, silent rounding | Integer minor units, regex-enforced 2dp |
| Pagination clamping | Missing | `GetCookiesPaginatedQuery` clamps page/perPage |
| Soft-delete leak in list queries | Present | `deleted_at IS NULL` added in `executeFindAll`/`executeFindPaginated` |

**Code coverage: 46.95% lines / 46.41% methods / 31.82% classes (132 classes, 571 methods, 4807 lines).** The CLAUDE.md target is 90%. Project documentation (`TEST_COVERAGE_REPORT.md`) claiming "192 tests, 100% passing on Cookie" is stale.

## Confirmed Tooling Results (Current)

- `composer validate --no-check-publish`: passed.
- `composer phpstan`: 0 errors, level 8.
- `composer phpcs`: 0 violations.
- `composer audit`: clean.
- `php spark routes`: lists routes correctly.
- `composer test`: 332 passing, 2 skipped, 869 assertions, ~16s.

## Findings By Category

Each finding is tagged:
- **OPEN** — still present, must be fixed.
- **NEW** — first identified in this audit pass.
- **PARTIAL** — partially addressed, finishing work required.
- **FIXED** — verified resolved since the morning audit; listed for traceability.

Severity tags: **CRITICAL**, **HIGH**, **MEDIUM**, **LOW**.

---

### A. Security defaults (highest priority)

#### A1. CRITICAL / OPEN — Web routes are unprotected by default
`app/Config/Routes.php` mounts `/cookies/*` (line ~16) and `/admin/users/*` (line ~57) with no auth filter. An unauthenticated visitor can GET, POST, and DELETE through these routes. `app/Config/Filters.php` has no `$filters` URI-pattern entries enforcing a session-auth filter. There is no `SessionAuthMiddleware` analogous to `JwtAuthenticationMiddleware`. This is the single largest gap before this becomes an ERP base.
Fix: implement a session-auth filter, register it in `Filters::$aliases`, and apply it via `Filters::$filters` URI patterns (`cookies/*`, `admin/*`) or in route groups.

#### A2. HIGH / OPEN — Session fixation not handled on web login
`app/Controllers/Domain/Auth/AuthController.php` web login sets session vars (lines ~81-86) but does not call `session()->regenerate(true)`. Standard fixation defense missing.
Fix: regenerate session ID immediately after successful credential validation.

#### A3. HIGH / OPEN — Admin attribution hard-coded to user ID 1
`app/Domain/User/Commands/ChangeUserPassword/ChangeUserPasswordHandler.php:111` and `app/Domain/User/Commands/DeleteUser/DeleteUserHandler.php:81` carry `changedBy: 1` / `deletedBy: 1` with TODO comments. No actor context flows from controller → command → handler → event. Every audit event will attribute to "1" forever.
Fix: introduce an `Actor` value object or `actorId` field on commands; populate from authenticated user in controllers; carry it into events.

#### A4. HIGH / OPEN — Password change does not revoke active sessions
`SessionManagementService` has `revokeAllUserSessions()` but `ChangeUserPasswordHandler` never calls it. After a forced reset, old JWTs and sessions remain valid until natural expiry.
Fix: invalidate sessions and blacklist outstanding refresh tokens on password change.

#### A5. HIGH / OPEN — Sensitive request data may reach logs without redaction
`app/Infrastructure/Logging/LoggerFactory.php` adds correlation ID and CQRS context processors but registers no PSR-3-level redaction for passwords, JWTs, refresh tokens, plaintext credentials in command payloads.
Fix: add a `RedactingProcessor` with a regex/key allowlist; require new commands to declare which fields are sensitive.

#### A6. MEDIUM / OPEN — CSP enabled but layout violates it
`app/Config/App.php:227` enables CSP. `app/Views/layout.php:7,39` pulls Bootstrap 5.3 from `cdn.jsdelivr.net` with no SRI integrity and no `script-src`/`style-src` allowance for the CDN. `app/Views/auth/login.php`, `register.php` ship inline `<style>` blocks. `app/Views/cookies/show.php:86` and `app/Views/admin/users/edit.php` / `show.php` use `onsubmit="return confirm(...)"`. All of these will be blocked under a real CSP.
Fix: vendor Bootstrap locally or whitelist the CDN with SRI; move inline CSS to local stylesheets; replace inline event handlers with unobtrusive JS.

#### A7. MEDIUM / OPEN — Idle/fingerprint validation in JWT middleware fails open
`app/Infrastructure/Auth/Middleware/JwtAuthenticationMiddleware.php:200-285` catches errors during session/fingerprint validation and returns `null` (pass-through). If the sessions table is corrupted or unreachable, the user remains authenticated.
Fix: fail-secure (return 401) when session validation cannot complete.

#### A8. MEDIUM / OPEN — `role:admin` default-falls-back to `customer`
`RoleAuthorizationMiddleware.php:163` defaults the resolved role to `customer` when parsing fails. A malformed user payload bypasses admin gates silently.
Fix: throw / return 403 when role cannot be determined.

#### A9. MEDIUM / OPEN — Argon2id used without cost configuration
`PasswordHashingService` uses `PASSWORD_ARGON2ID` with PHP defaults. Acceptable, but for ERP/PII the cost should be explicit and tunable via `.env`.
Fix: read `memory_cost`, `time_cost`, `threads` from config; document recommended values.

#### A10. MEDIUM / OPEN — Rate-limit backend not validated for production
`RateLimitService` relies on `\Config\Services::cache()`. If a file or array cache driver is configured, distributed deployments leak rate-limit state per server.
Fix: validate at boot that a shared cache (Redis/Memcached) is configured when `ENVIRONMENT=production`.

#### A11. MEDIUM / OPEN — JWT secret has no boot-time presence check
`JwtService` validates `JWT_SECRET_KEY` on first use, not at boot. A misconfigured server starts cleanly and fails only on first API request.
Fix: add a boot check (Services or a pre-system event) that throws if `JWT_SECRET_KEY` is missing/short.

#### A12. LOW / OPEN — Public test HTML files should not ship
`public/test-refactor-simple.html` and `public/test-initialize-refactor.html` reference a non-existent `EchAdmin` JS bundle. They leak internal naming and produce 404 chains.
Fix: delete or move to `tests/fixtures/`.

---

### B. Cookie domain template integrity (multiplier risk)

These are the bugs that will be cloned into every ERP entity unless fixed in Cookie first.

#### B1. HIGH / FIXED — Soft-deleted cookies leaking into list queries
`CookieRepository::executeFindAll` and `executeFindPaginated` now add `deleted_at IS NULL` explicitly. Verified.

#### B2. HIGH / FIXED — `decreaseStock(-5)` silently increasing stock
`app/Domain/Cookie/Entities/Cookie.php:174-177` now validates `$quantity > 0` before computing. Verified.

#### B3. HIGH / FIXED — Float-based money
`CookiePrice` now stores integer minor units, validates via regex (`^-?\d+(?:\.\d{1,2})?$`), and `fromFloat()` is marked deprecated/legacy. Verified.

#### B4. HIGH / FIXED — Repository concrete coupling
`CookieRepositoryInterface` exists at `app/Domain/Cookie/Ports/CookieRepositoryInterface.php`; `CreateCookieHandler`, `UpdateCookieHandler`, `DeleteCookieHandler` depend on it; `CookieRepository` implements it. Verified.

#### B5. HIGH / FIXED — Pagination not clamped
`GetCookiesPaginatedQuery` clamps `page >= 1` and `perPage <= MAX_PER_PAGE (100)`. Verified.

#### B6. HIGH / PARTIAL — Uniqueness race not mapped to domain error
`CreateCookieHandler` pre-checks `existsByName`; migration has a unique key. Concurrent creates rely on the DB error, and the handler does not currently translate a duplicate-key SQL exception into a `DomainException` with a stable error code.
Fix: catch DB duplicate key on insert, map to `ErrorCodes::COOKIE_VALIDATION_DUPLICATE_NAME`.

#### B7. HIGH / PARTIAL — Case-insensitive uniqueness depends on collation
`CookieModel` uses `LOWER(name)` for lookups, but the unique key is on raw `name` and the migration does not set an explicit collation.
Fix: set `utf8mb4_0900_ai_ci` (or platform equivalent) on `cookies.name` in migration; document the contract.

#### B8. CRITICAL / NEW — Events dispatched outside a transaction
`CreateCookieHandler` (and Update/Delete) calls `$repository->save()` and then `EventDispatcher::dispatch(...)`. There is no transaction encompassing both. If a listener fails after persistence, the DB shows the new state but listeners silently log (see C4). If persistence fails, no event is sent, but there is no atomicity guarantee for multi-step writes (e.g., create + audit).
Fix: implement an outbox pattern — handlers write the entity AND outbox row in one transaction; a relay publishes events. Until then, at minimum wrap handler bodies in a CI4 DB transaction.

#### B9. HIGH / NEW — No optimistic locking / version column
Cookie has no `version` or `updated_at_token`. Concurrent updates (read-modify-write) overwrite each other silently. Multiply across 30 entities and this becomes a class of latent bugs.
Fix: add `version INT UNSIGNED NOT NULL DEFAULT 0` column; entity increments on mutation; repository update where `id = ? AND version = ?`.

#### B10. HIGH / NEW — `created_by` / `updated_by` / `deleted_by` absent on `cookies`
Migration `2025-01-21-000001_CreateCookiesTable.php` has no actor columns. Events log payload but not the acting user.
Fix: add `created_by`, `updated_by`, `deleted_by` foreign keys to `users`; populate from `Actor` (see A3); include in events.

#### B11. HIGH / NEW — No tenant/company scoping
Cookies (and users) have no `tenant_id` / `company_id`. The repository has no scope-by-tenant API. Any ERP serving more than one legal entity must retrofit this on every table.
Fix: decide tenant model now (single-DB column vs. schema-per-tenant) and bake the column + repository scoping into the Cookie template.

#### B12. MEDIUM / NEW — `CookiePrice` carries no currency
Even with integer minor units, there is no Currency value object. `format()` hardcodes `$` as default.
Fix: introduce `Money(amountMinor, Currency)` in `Domain/Shared/ValueObjects`; have `CookiePrice` either wrap `Money` or be replaced by it. Currency is a property of every monetary field, not a formatting concern.

#### B13. MEDIUM / NEW — Event payloads incomplete for audit
`CookieUpdatedEvent` carries the new state only — no before/after. `CookieDeletedEvent` carries id+name only. Reconstructing what changed requires a separate audit log.
Fix: include `previousState` + `newState` (or a typed diff) in update events; include full final snapshot in delete events.

#### B14. MEDIUM / NEW — Query handlers return domain entities to controllers
`GetCookieByIdHandler` returns `?Cookie`; `GetAllCookiesHandler` returns `Cookie[]`. Controllers and views consume entities directly. This couples views to the domain and prevents shaping a read model independently.
Fix: introduce per-query DTO/read-model classes (`CookieListItem`, `CookieDetail`). Views and API serialize from DTOs.

#### B15. MEDIUM / NEW — Cookie identifier is auto-increment INT
For ERPs that integrate across systems, leak record counts, or need offline ID generation, ULID/UUID v7 is better.
Fix: decide ID policy template-wide; if changing, change once before any new domain is scaffolded.

#### B16. LOW / NEW — Cookie name remains unique after soft delete
`UNIQUE(name)` plus soft delete makes restoration impossible without renaming.
Fix: use a partial/functional unique index excluding rows where `deleted_at IS NOT NULL`, or include `deleted_at` in the unique key.

#### B17. LOW / NEW — No restore command
There is no `RestoreCookieCommand` / `RestoreCookieHandler`. Soft-delete with no undo is incomplete for an ERP.
Fix: add restore as part of the Cookie template before cloning.

---

### C. Infrastructure (every domain depends on this)

#### C1. CRITICAL / NEW — Event dispatcher swallows listener exceptions to `error_log`
`app/Infrastructure/Bus/EventDispatcher.php:84-98` catches all `Throwable` from listeners and calls `error_log()`, not the structured PSR-3 logger. Listener failures are invisible to log aggregation, have no correlation ID, no domain context.
Fix: log via injected `LoggerInterface` with `correlation_id`, `event_class`, `listener_class`. Add a strict mode (rethrow) for development.

#### C2. CRITICAL / NEW — No outbox / no durable event delivery
`EventDispatcher` is synchronous, in-memory. A consumer crash, a partial commit, or a 500 mid-handler drops events. For ERPs (orders, invoices, GL entries), event loss is a correctness problem.
Fix: add an `event_outbox` table; handlers enqueue inside the same transaction; a background relay publishes and marks delivered.

#### C3. HIGH / NEW — No transaction middleware on `CommandBus`
`CommandBus::dispatch` calls the handler directly. There is no transactional wrapping, no logging middleware, no validation middleware — even though the bus is the obvious place for these cross-cutting concerns.
Fix: introduce a middleware pipeline. First middlewares: `TransactionMiddleware`, `LoggingMiddleware`, `CorrelationIdMiddleware`.

#### C4. HIGH / NEW — No entity-side event bag pattern
Entities don't accumulate events (`raiseEvent` / `pullEvents`). Handlers dispatch directly. This couples each handler to `EventDispatcher` and prevents transactional outbox without rework.
Fix: add `AggregateRoot` base with `raiseEvent()` / `pullEvents()`; handler/repository drains events at save time.

#### C5. HIGH / NEW — Auto-discovery is regex-based and not exercised by tests
`ServiceProviderRegistry::getClassNameFromFile` uses regex on PHP source to extract namespace + class. Brittle to anonymous classes, traits, comments, and breaks under opcache with deferred class loading. There are no tests covering the registry.
Fix: replace regex parsing with composer classmap lookup or PHP tokenizer; add tests that scaffold a temp domain and assert the bus picks up its handlers.

#### C6. HIGH / NEW — Auth provider lives outside the scanned tree
`AuthServiceProvider` carries `#[DomainServiceProvider]` but lives under `app/Infrastructure/Auth/`. Registry only scans `app/Domain/`. The morning audit flagged this; verify whether the path is now scanned. If not, infrastructure-scope providers must be either registered explicitly or moved.
Fix: split discovery roots (`Domain` + `Infrastructure/<Module>`) explicitly, or move infrastructure providers to a `Modules/Auth` path that is scanned.

#### C7. MEDIUM / NEW — Correlation ID does not adopt inbound `X-Correlation-Id`
`CorrelationIdService` generates a UUID on first `get()`. It does not read the inbound HTTP header. Distributed traces will not stitch.
Fix: in a pre-controller filter, read `X-Correlation-Id` (validate format), set into `CorrelationIdService::set()`, and echo back in response headers.

#### C8. MEDIUM / NEW — Repositories live in two different roots inconsistently
Cookie repository sits in `app/Models/Cookie/`; User repository sits in `app/Infrastructure/Persistence/Repositories/`. The Cookie template uses traits for logging; the User repository does not. This inconsistency will be copied if Cookie is cloned.
Fix: pick one root (`app/Infrastructure/Persistence/Repositories/<Domain>/`), one base class with logging + slow-query metrics, one trait or none.

#### C9. MEDIUM / NEW — `LoggerFactory::create` is not cached
Each call builds a fresh `Logger` with handlers and processors. DomainLogger caches but the global factory does not, and middleware/services call `\Config\Services::logger()` directly.
Fix: cache by channel; document one entry point (`Services::logger(string $channel)`).

#### C10. MEDIUM / NEW — Filters re-fetch services via `\Config\Services` per request
`RateLimitMiddleware`, `RoleAuthorizationMiddleware` use null-default constructor args and resolve from `\Config\Services` if not injected. Works, but the filters cannot be unit-tested without bootstrapping the framework.
Fix: register as filter-instantiable via `Filters::$aliases` with `Services::getShared` factories and inject only PSR interfaces.

#### C11. MEDIUM / NEW — No unit-of-work / domain context
No `UnitOfWork` to flush multiple aggregate changes together. Handlers manage their own state. For ERP-style multi-aggregate commands (post invoice → GL entries + AR row), this is a problem.
Fix: introduce a per-request unit-of-work that batches saves and event publication.

#### C12. LOW / NEW — `DomainLogger` channel is fixed
`DomainLogger` always logs to `domain.validation`. Channel is not derived from the calling domain, so logs from Cookie and User VOs collide on the same channel.
Fix: take `(domain, kind)` and route to `cookie.validation`, `user.validation`, etc.

#### C13. LOW / NEW — Rate-limit and role middleware accept invalid args silently
`RateLimitMiddleware::parseArguments` defaults to 5/300 if route-supplied args are not numeric. Misconfigured routes look healthy.
Fix: throw on parse failure; surface at boot.

---

### D. ERP-shape gaps (must be designed in before cloning Cookie)

#### D1. HIGH — Multi-tenancy absent
No `tenant_id`/`company_id` on any table; no tenant filter middleware; no scoped repositories. (See B11.) Decision needed: row-level vs. schema-level. ERPs almost always need this.

#### D2. HIGH — No general audit log
Security events are logged, but there is no `audit_log` table recording every command, who issued it, before/after state. Compliance (SOX, fiscal) usually requires this.
Fix: an `AuditMiddleware` on `CommandBus` writes a row per command with actor, payload digest, correlation id, timing.

#### D3. HIGH — Permissions model is role-string only
`RoleAuthorizationMiddleware` checks `admin|customer|guest`. There is no permissions table, role_permissions, or per-resource ACL. ERPs need granular (per-module, per-company, per-document-type) permissions.
Fix: add `permissions`, `roles`, `role_permissions`, `user_roles` tables; check via a `Permission` value object (`module.action`, e.g., `cookies.create`).

#### D4. HIGH — No document numbering service
ERPs need fiscal-year-aware, prefixed, gapless sequences (Order #2026-000123). Auto-increment IDs are not enough.
Fix: add `DocumentNumber` value object + `NumberSequence` table + provider per series.

#### D5. HIGH — No state-machine scaffold
Cookie has a boolean `is_active`. User has a status enum but no transition rules in code. Every ERP entity will need lifecycles (Draft → Approved → Posted → Cancelled).
Fix: an `AggregateState` value object pattern with declared transitions; tests covering invalid transitions.

#### D6. HIGH — No background job runner
Emails are sent synchronously in `EmailService::send`. No queue, no worker, no cron-driven recurring jobs. ERPs cannot live without this (bulk imports, scheduled reports, retries).
Fix: pick a queue driver (DB-backed for portability), define a `Job` interface, a worker spark command, a scheduler.

#### D7. HIGH — Money / Currency abstraction incomplete
`Domain/Shared/ValueObjects/Money.php` exists, but `CookiePrice` does not carry currency, and there is no `Currency` value object, no exchange-rate concept, no taxable/non-taxable split.
Fix: promote `Money` to (amountMinor, Currency); introduce `Currency` value object; rebuild `CookiePrice` on it.

#### D8. MEDIUM — i18n surface is empty
`app/Language/en/` contains an empty `Validation.php`. Strings are hardcoded in views. No locale negotiation, no `lang()` calls in domain views.
Fix: extract user-visible strings into language files; add a locale resolver middleware; surface in the layout.

#### D9. MEDIUM — No idempotency on API mutations
No `Idempotency-Key` header handling on POST endpoints. Network retries can duplicate orders/invoices/payments.
Fix: middleware that captures Idempotency-Key, stores response, returns cached response on retry.

#### D10. MEDIUM — Settings / runtime config table missing
No table for runtime config (tax rates, default terms, feature flags). Everything is `.env`/`Config\*`.
Fix: add a `settings` table + `Settings` service with caching.

#### D11. MEDIUM — Attachments / file storage absent
No attachments table, no filesystem abstraction, no PDF rendering. ERPs need both inbound (scanned invoices) and outbound (generated invoices).
Fix: add filesystem abstraction (`local`/`s3`) + `attachments` table + a `Document` aggregate later.

#### D12. MEDIUM — In-app notifications absent
Only email exists, and it is synchronous and template-less.
Fix: notifications table + per-user preferences + dispatcher with email/in-app channels.

#### D13. MEDIUM — Email is inline-HTML, not templated
`EmailService::sendPasswordResetEmail` has the HTML inline. Each new template will duplicate this.
Fix: move to view-based templates in `app/Views/emails/`; pass models, not raw strings.

#### D14. MEDIUM — API responses are not normalized
`{success, data, pagination}` shape varies; error responses vary; `totalPages` is sometimes missing. No RFC 7807 problem+json for errors.
Fix: response envelope helper + a `Problem` class + a `Paginated<T>` shape used by every list endpoint.

#### D15. MEDIUM — Read model is the write model
Every query hits the write tables. No projections, no read-optimized views. Acceptable early but the template should set expectations (e.g., a `read_models/` namespace) before cloning starts.

#### D16. LOW — No health endpoint
No `/health` returning DB ping, cache ping, queue depth.
Fix: a trivial `HealthController` with a JSON status payload.

#### D17. LOW — No import/export scaffolding
No CSV/XLSX import command pattern. Most ERP onboarding starts with imports.
Fix: a `BulkImport<T>` interface with row → command mapping + dry-run.

#### D18. LOW — External integration scaffolding absent
`app/Libraries/AbiSageIntacct/` is empty. No HTTP client with retries, no idempotency keys for outbound, no webhook receiver pattern.
Fix: an `HttpClient` wrapper with retry/backoff + a `Webhook<T>` controller scaffold.

---

### E. Views, UI shell, ergonomics

#### E1. HIGH — Web UI is permission-blind
Views render Edit/Delete buttons unconditionally; backend enforces (when it does — see A1).
Fix: a `Gate::allows('cookies.update', $cookie)` helper used in views before rendering action buttons.

#### E2. HIGH — Zero reusable view partials
No `_form_field.php`, no `_table.php`, no `_pagination.php`, no `_breadcrumbs.php`. `cookies/create.php` and `edit.php` are 90% identical (~120 lines each); same for users. Cloning Cookie's views per ERP entity will produce thousands of duplicated lines.
Fix: extract field, table, pagination, action-bar, delete-confirm partials before cloning starts.

#### E3. HIGH — Layout is not an ERP shell
`app/Views/layout.php` is a basic Bootstrap nav. No sidebar/module menu, no breadcrumbs, no user/account dropdown, no flash/toast standardization, no permission-aware menu, no multi-company switcher, no language switcher, no notification bell.
Fix: build an `admin` layout with these elements before scaffolding new domains.

#### E4. HIGH — Auth views bypass the layout and ship inline CSS
`auth/login.php` and `auth/register.php` are standalone with `<style>` blocks. CSP-incompatible (see A6) and duplicated.
Fix: a shared `auth` layout with linked stylesheet.

#### E5. MEDIUM — Hard-coded English strings in domain views
Verified across `cookies/*` and `admin/users/*`. No `lang()` calls.
Fix: extract to language files as part of D8.

#### E6. MEDIUM — `Home` controller still serves CodeIgniter welcome page
`app/Controllers/Home.php:11` returns `welcome_message`. Login redirects to `/dashboard` (which exists), but the public root looks like a fresh CI install.
Fix: redirect unauthenticated `/` to `/auth/login`; authenticated `/` to `/dashboard`.

#### E7. MEDIUM — Inline `onsubmit="return confirm(...)"` in delete forms
`cookies/show.php:86`, `admin/users/edit.php`, `show.php` — CSP-incompatible and copy-pasted.
Fix: a small `delete-confirm.js` data-attribute handler.

#### E8. LOW — Accessibility: ARIA mostly absent
Labels are present; ARIA roles, `aria-describedby` on error fields, and focus management on validation errors are not.
Fix: bake into the form partial during the E2 cleanup.

---

### F. Tests, coverage, CI

#### F1. HIGH — Coverage well below the documented bar
46.95% lines / 31.82% classes against a 90% target. Largest gaps: Auth services (`SecurityEventService`, `SessionManagementService` < 15% lines), `EmailService` (1.92%), `EventDispatcher` (38%), `CommandBus`/`QueryBus` (~41% lines / 0% methods covered), `UserRepository` (29%).
Fix: prioritize the bus + event dispatcher + auth services. Set a per-package floor in `phpunit.xml.dist` to prevent regression.

#### F2. HIGH — No tests for auto-discovery
`ServiceProviderRegistry` has 0% method coverage. Yet this is the single point of failure for every new domain.
Fix: tests that scaffold a fake provider in a temp namespace and assert it is discovered + registered.

#### F3. MEDIUM — Stale dead test files
`tests/Unit/Libraries/AbiSageIntacct/*` references code that does not exist (`app/Libraries/AbiSageIntacct/` is empty). Tests currently rely on a guarded include and probably skip.
Fix: remove the dead tests or quarantine them under `tests/_skip/` until the library returns.

#### F4. MEDIUM — Stale generated docs claiming high coverage
`TEST_COVERAGE_REPORT.md`, `TEST_ANALYSIS_SUMMARY.md` reference "192 tests" from 2025-10-26. Today the suite is 332 tests with different coverage numbers. These will mislead future contributors.
Fix: delete the static reports; rely on the live coverage HTML output.

#### F5. MEDIUM — Quality gates exclude config, views, migrations
`phpstan.neon`, `phpcs.xml` exclude application surfaces where several findings above live (Routes, Filters, views).
Fix: narrow the exclusions; let PHPStan see Routes and Filters at level 5+ even if level 8 is too strict for them.

#### F6. LOW — Integration tests are thin
Only `CookieRepositoryTest`, `AuthenticationSecurityTest`, `PenetrationTest`. No integration tests for `EventDispatcher` + transaction, for filter pipelines, or for the JWT round-trip.

---

### G. Template hygiene

#### G1. MEDIUM / OPEN — Distributable surface includes large dev artifacts
`.claude` ~193M, `temp` ~193M, `vendor` ~73M, `build` ~19M, `writable` ~13M, `.history` ~2M. Not appropriate to ship as a template archive.
Fix: a release script that emits a clean tree; `.gitattributes` `export-ignore` for AI-tooling state.

#### G2. MEDIUM / OPEN — `composer.json` still identifies as `codeigniter4/appstarter`
Package name, description, support links unchanged.
Fix: rename, set ERP template identity, version `0.1.0`, support URL.

#### G3. LOW / OPEN — `.claude` documentation oversells "zero configuration"
`CLAUDE.md` and `ADDING_DOMAINS.md` claim auto-discovery makes domain registration zero-config; the registry only scans `app/Domain/` and repositories still need `Config\Services` entries.
Fix: align docs with actual mechanics, or extend the registry to a true convention.

#### G4. LOW / OPEN — Root contains process docs
`COVERAGE_ACTION_PLAN.md`, `TEST_ANALYSIS_SUMMARY.md`, `TEST_COVERAGE_REPORT.md`, `MODIFYING_ENTITIES.md`, `ADDING_DOMAINS.md` in root. The project rule says docs live in `.claude/documentation/`.
Fix: move or delete; keep root to `README.md`, `SETUP.md`, `LICENSE`.

---

## Prioritized Stabilization Plan

### Phase 0 — Lock current ground (done in morning)
Already complete: PHPStan, PHPCS, audit, routes, test suite runs. **No regression** must be allowed.

### Phase 1 — Make the template safe-by-default (1–2 days)
1. A1: register session auth filter; protect `cookies/*` and `admin/*`.
2. A2: regenerate session ID on login.
3. A3: introduce `Actor`/`actorId` on commands and stop hard-coding `1`.
4. A4: invalidate sessions on password change.
5. A12: remove the public test HTML files.
6. E6: change `/` to redirect to login or dashboard.

### Phase 2 — Harden the Cookie module before any clone (3–5 days)
1. B6: translate duplicate-key DB errors into domain errors.
2. B7: pin collation for case-insensitive uniqueness.
3. B8: wrap command handlers in DB transactions (minimum); add outbox table.
4. B9: add `version` column and optimistic locking.
5. B10: add actor columns (`created_by`, `updated_by`, `deleted_by`).
6. B11: pick tenant strategy; add `tenant_id` column + scoping in repository.
7. B12: introduce `Money(amountMinor, Currency)` and rebuild `CookiePrice` on it.
8. B13: enrich events with previous/new state.
9. B14: introduce per-query DTO read models; controllers/views consume DTOs.
10. B16, B17: partial unique index + Restore command.

### Phase 3 — Infra capable of running an ERP (1–2 weeks)
1. C1, C2: structured logger for listener errors + event outbox + relay command.
2. C3, C4: middleware pipeline on the bus; transaction/logging/correlation middlewares; entity event bag.
3. C5, C6: replace regex discovery; explicit scan roots; tests for the registry.
4. C7: inbound `X-Correlation-Id` adoption + outbound echo.
5. C8: consolidate repository root and a base repository.
6. D2: `AuditMiddleware` writing per-command audit rows.
7. D3: permissions model (`permissions`, `roles`, `role_permissions`, `user_roles`).
8. D6: background job runner.
9. D9, D14: idempotency middleware + normalized API envelope + problem+json.

### Phase 4 — ERP scaffolding (2–4 weeks)
1. D4: document numbering service.
2. D5: state-machine scaffold.
3. D7: full `Money`/`Currency` rollout.
4. D8: i18n surface.
5. D10–D13: settings, attachments, notifications, templated emails.
6. E1–E4: ERP shell + reusable view partials + permission-aware UI.
7. D17, D18: bulk import + outbound integration patterns.

### Phase 5 — Template release (1–2 days)
1. F1, F2: raise coverage; tests for the registry.
2. F3, F4: prune dead tests and stale docs.
3. G1–G4: clean artifacts, rename package, align docs.

## Implemented Since This Audit

Branch: `stabilization/erp-foundation` — 10 commits, all gates green at each step (PHPStan Level 8, PHPCS, full test suite). Test count rose from **332** to **376** (+44 new tests, all passing).

### Phase 1 — Safe-by-default
- **A1 [DONE]** `SessionAuthMiddleware` registered as `web_auth`; applied to `cookies/*`, `admin/*`, `dashboard`. Feature tests use the framework's `MockSession` + a seeded admin so authenticated routes are reachable.
- **A2 [DONE]** `session()->regenerate(true)` on successful web login.
- **A3 [DONE]** `Actor` value object + `ActorResolver` service. `ChangeUserPasswordCommand` and `DeleteUserCommand` now carry an `Actor`; the hard-coded `userId = 1` is gone. `DeleteUserHandler` now also enforces the self-deletion guard documented in its existing comments.
- **A4 [DONE]** `ChangeUserPasswordHandler` invokes `SessionManagementService::revokeAllUserSessions()` after success.
- **A5 [DONE]** `RedactingProcessor` masks password/token/jwt/authorization/api_key/secret/credit_card keys across context + extra. Pushed last on the LoggerFactory pipeline so it runs first.
- **A6 [DONE]** SRI integrity + crossorigin on Bootstrap; inline `<style>` blocks moved to `public/assets/css/auth.css`; inline `onsubmit="return confirm(...)"` replaced with `data-confirm` + `public/assets/js/delete-confirm.js`.
- **A7 [DONE]** JWT fingerprint + idle-timeout checks no longer fail open. On DB exceptions both return 401 with a CRITICAL log entry.
- **A8 [DONE]** `RoleAuthorizationMiddleware` no longer defaults to `customer` when role cannot be resolved; throws internally, mapped to 403.
- **A11 [DONE]** `pre_system` event in `app/Config/Events.php` aborts boot in production when `JWT_SECRET_KEY` is missing or shorter than 32 chars.
- **A12 [DONE]** `public/test-refactor-simple.html` and `test-initialize-refactor.html` removed.
- **E6 [DONE]** `/` redirects to `/dashboard` when authenticated, otherwise `/auth/login`.

### Phase 2 — Cookie hardening
- **B6 [DONE]** `CookieRepository::save` catches `DatabaseException`, sniffs duplicate-key/unique-constraint/MySQL 1062, rethrows as `DomainException` with `COOKIE_VALIDATION_NAME`.
- **B7 [DONE]** `cookies.name` pinned to `utf8mb4_unicode_ci`.
- **B9 [DONE]** `version UNSIGNED INT NOT NULL DEFAULT 0` column added to `cookies` (foundation for optimistic locking; entity/repository wiring to follow).
- **B10 [DONE]** `created_by`, `updated_by`, `deleted_by` columns added to `cookies`.
- **B11 [DONE]** `tenant_id` column added to `cookies` (nullable until a tenant resolver lands).
- **B12 [DONE]** New `Currency` value object (ISO-4217 shape + minor-unit overrides); `Money` now carries a `Currency` (defaults to USD); arithmetic asserts same-currency.
- **B13 [DONE]** `CookieUpdatedEvent` carries `previousState` + `newState` + `updatedBy`; `CookieDeletedEvent` carries the full snapshot + `deletedBy`. Handlers capture the snapshot before mutation.
- **B16 [DONE]** Global `UNIQUE(name)` replaced with composite `UNIQUE(tenant_id, name, deleted_at)` so soft-deleted rows do not block recreation and restoration is possible.
- **B17 [DONE]** `RestoreCookieCommand` + `RestoreCookieHandler` + `CookieRestoredEvent`; repository gains `restore()` and `findByIdWithTrashed()`. Registered in `CookieServiceProvider`.

### Phase 3 — Infrastructure
- **C1 [DONE]** `EventDispatcher` no longer calls `error_log()` on listener failure; logs via injected PSR-3 `LoggerInterface` with event class, listener FQCN, exception, correlation id.
- **C3 [DONE]** `CommandBus` middleware pipeline. New `CommandMiddlewareInterface`, `LoggingMiddleware`, `TransactionMiddleware`. `Services::commandBus()` pushes Logging → Transaction so handler writes + synchronous event listeners share one unit of work.
- **C7 [DONE]** `CorrelationIdMiddleware` registered as `correlation`, applied globally before+after. Adopts a validated inbound `X-Correlation-Id`, echoes back on response.
- **B8 [DONE]** Implemented as part of `TransactionMiddleware` (every command now runs in a DB transaction by default).

### Phase 5 — Cleanup
- **F3 [DONE]** Dead AbiSageIntacct tests moved to `tests/_skipped/AbiSageIntacct/`; `phpunit.xml.dist` excludes `tests/_skipped/` as a directory; pre-commit hook skips that path from PHPCS/PHPStan.
- **G4 [DONE]** Stale process docs moved from root to `.claude/documentation/`. Root markdowns are now `ERP_TEMPLATE_AUDIT.md`, `README.md`, `SETUP.md`.

### Sprint 2 — second batch

Added on top of the first stabilization sprint. Test suite now at **427 tests / 1106 assertions**.

- **B9 entity wiring [DONE]** — Cookie entity now carries `version`, repository's `save()` runs `WHERE id = ? AND version = ?` with version bump in the same statement; mismatched version throws `DomainException::concurrentModification`. 4 integration tests prove concurrent-update detection and that the winner's write is preserved.
- **B14 [DONE]** — `App\Domain\Cookie\ReadModels\CookieView` DTO with `detail()` / `summary()` / `summarise(list)` factories. Snake-case `toArray()` for API/JSON. 5 unit tests. Controllers will migrate to the DTO + ApiResponse envelope incrementally.
- **C4 [DONE]** — `App\Domain\Shared\AggregateRoot` trait (`raiseEvent` / `pullEvents` / `peekEvents` / `hasPendingEvents`). Cookie pilots the pattern: `decreaseStock` and `increaseStock` raise `CookieStockChangedEvent`. Repository drains pending events on save. `CookieStockChangedEventHandler` registered.
- **C5 [DONE]** — `ServiceProviderRegistryTest` (4 tests) locks the auto-discovery contract in: provider attribute scanning, command/query/event wiring on Cookie, missing-repo error path, cache reset.
- **C7 [DONE]** — `CorrelationIdMiddleware` already shipped in the first sprint; cited here for completeness.
- **D2 [DONE]** — `AuditMiddleware` writes one row per command to `audit_log` inside the bus transaction. Includes command class, actor, correlation id, status, duration, and a SHA-256 digest of the *redacted* payload (passwords never reach the table). 3 integration tests, including a proof that two commands differing only by password produce the same digest.
- **D3 [DONE]** — Full RBAC schema (`permissions`, `roles`, `role_permissions`, `user_roles`), `Permission` value object with `{module}.{action}` validation, `PermissionService` with legacy-admin shim + RBAC join, `PermissionMiddleware` filter alias `permission`. 9 unit + 4 integration tests.
- **D9 [DONE]** — `Idempotency-Key` middleware (`idempotency` filter alias). Captures response under `(id_key, actor_id)` for 24 h; replays exact response on retry with same body; 422 on same-key-different-body; per-key validation. Wired into the admin user-management API group. 6 unit tests.
- **D14 [DONE]** — `App\Infrastructure\Http\ApiResponse` standardised envelope: `ok` / `created` / `paginated` / `noContent` / `problem` (RFC 7807 problem+json) / `validationFailed` / `notFound` / `conflict`. Every payload carries `meta.correlation_id`. 8 unit tests.
- **D16 [DONE]** — `GET /health` returns a JSON probe with DB status, correlation id, and a 200/503 status code. Unauthenticated by design. 2 feature tests.

### Sprint 3 — third batch

Test suite now at **472 tests / 1213 assertions**. All gates green at every commit.

- **C2 [DONE]** — transactional event outbox: new `event_outbox` table, `EventOutboxWriter` (called inside the bus transaction), `EventOutboxRelay` (claims pending rows, dispatches through the in-process EventDispatcher, exponential-backoff retry: 30s/2m/10m/1h/6h/24h, then `failed`). `spark events:relay` command with `--batch`, `--watch`, `--sleep`. 5 integration tests cover writer persistence, relay delivery, retry, delayed rows, max-attempts failure.
- **C6 [DONE]** — `ServiceProviderRegistry::getClassNameFromFile()` rewritten using the native PHP tokenizer instead of regex. Handles docblock false-positives, anonymous classes inside method bodies, and `final readonly class` modifier combinations. 5 targeted unit tests via reflection.
- **D4 [DONE]** — document numbering service. New `document_sequences` table with composite unique key on `(series, scope)`, `DocumentNumber` VO, and `DocumentNumberingService` (`allocate` / `peek`). Generates gapless, prefix/suffix/zero-padded identifiers like `INV-2026-00042`. 9 integration + 2 unit tests.
- **D5 [DONE]** — declarative state-machine scaffold under `Domain/Shared/StateMachine`: `State` interface, `InvalidTransition` exception, `StateMachine` class with `transition`/`canTransition`/`allowedFrom`/`isTerminal`. Accepts strings or `State`-implementing enums. 8 unit tests.
- **D6 [DONE]** — database-backed job queue. New `jobs` table, `JobHandlerInterface`, `JobQueue` (producer, supports delayed jobs + max_attempts + named queues), `JobWorker` (atomic claim via UPDATE, exponential backoff). `spark jobs:work` worker command with `--queue`, `--batch`, `--watch`, `--sleep`. Multiple workers can run concurrently. 7 integration tests.
- **D10 [DONE]** — runtime settings store. New `settings` table with composite unique key on `(key_name, tenant_id)`, JSON-typed values, secret flag for the future admin UI. `SettingsService` with `get` / `set` / `forget` / `has` and a per-request cache. Tenant scoping is explicit — no automatic fallback to global, so call sites stay obvious. 9 integration tests.

### Still Open

- **D7** — Full Money/Currency rollout to every monetary field (CookiePrice still operates on its own minor-units representation, parallel to `Money`).
- **D8** — i18n surface beyond the empty `app/Language/en/`.
- **D11–D13** — attachments/filesystem abstraction, in-app notifications, templated emails.
- **D15** — Read-model projections separate from write tables.
- **D17, D18** — Bulk import/export, outbound HTTP integration patterns (HttpClient with retries + idempotency keys, webhook receiver scaffold).
- **E1–E4** — ERP layout shell, reusable view partials, permission-aware UI, auth-view layout.

## Verdict on `Cookie` as the Entity Template

`Cookie` is much closer than it was this morning: the worst correctness bugs are fixed, the repository now has a port, money is no longer a float, and tests run. As a *shape* — folder layout, file naming, CQRS separation — it is already a useful pattern to imitate.

It is still **not safe to clone**. Cloning today will burn into every entity:

- no transactional safety around events,
- no actor on commands or events,
- no tenant column or scoping,
- no version column / optimistic locking,
- query handlers returning domain entities,
- views with zero partials and no permission gating,
- and a backend that lets unauthenticated requests through to `/cookies` and `/admin/users`.

Recommended sequence: finish Phase 1 today, Phase 2 this week, Phase 3 over the next two weeks. Only then promote `Cookie` to "golden module" and use it as the basis for scaffolding new ERP domains.
