# Round 1 — Consolidated audit

Date: 2026-05-20
Sources: 15 independent agent reports under `.audit/round1/`

---

## Executive summary

The template demonstrates correct CQRS/DDD shape at the surface but is **NOT safe to clone**. Across the 15 reports the same defects recur: tenant scoping is schema-only fiction (column exists, runtime ignores it everywhere — repository, queries, projection, notifications, attachments, settings); the read-side CQRS story is a no-op (`CookieReadModelProjection` and `ProjectionRegistry` are never wired and the read handlers query the write table); the outbox/event story is inconsistent (events dispatched directly from handlers bypass `AggregateRoot::raiseEvent`, `EventOutboxWriter` is dead code, `EventDispatcher` swallows listener exceptions defeating the transactional guarantee documented in `TransactionMiddleware`); the auth subsystem has six independent CRITICAL holes (file-cache blacklist, refresh-token replay window, two admin-bypass paths in `PermissionService`, web tier lacks role:admin filter, etc.); and the foundational shared VOs (`Money`, `DateTimeValue`, `DocumentNumber`, `AttachmentRef`) under-enforce invariants in ways that will be inherited by every cloned domain.

Cookie was specifically chosen as the canonical template. Cookie ships with at least five clone-multiplying defects (event-id-null-on-create, lifecycle events bypassing AggregateRoot, mono-currency bounds on a multi-currency VO, USD silent default, broken composite UNIQUE under MySQL NULL semantics). Every `/add-domain` run today imports these defects.

**Verdict on "is Cookie safe to clone?": NO.** Block all new domain scaffolding until the CRITICAL findings (especially: tenant scoping, projection wiring, event dispatch consolidation, error-code registry collision, MySQL UNIQUE/NULL behaviour, optimistic-lock semantics) are fixed in Cookie + Shared. The template's intent is sound; the implementation is not production-grade.

---

## Cross-cutting themes

1. **Tenant scoping is missing across the entire stack** (security/multi-tenant data leak).
   Schema declares `tenant_id` on `cookies`, `cookie_read_model`, `notifications`, `settings`, `attachments`. Runtime applies it nowhere.
   Touch points: `app/Models/Cookie/CookieRepository.php:79-120,150-296,425-461` (no `tenant_id` filter), `app/Domain/Cookie/Queries/*` (no tenant param), `app/Domain/Cookie/Projections/CookieReadModelProjection.php:203` (`tenant_id => null` hardcoded), `app/Infrastructure/Notifications/NotificationService.php:86-142` (list/count/mark scoped only by user), `app/Infrastructure/Storage/AttachmentService.php:46-154` (read/delete/list ignore tenant), `app/Infrastructure/Settings/SettingsService.php:21-26` (fallback documented, not encoded in API), `app/Controllers/Domain/Cookie/CookieController.php:50-92` (no tenant injection), `app/Domain/User/*` (no `tenant_id` in migration or repository at all). No `TenantContext` service exists anywhere in `app/`. **Severity: CRITICAL.**

2. **The CQRS read side is fiction.**
   `CookieReadModelProjection` writes to `cookie_read_model` (and only during manual `projections:rebuild`), but no query handler reads from it. `GetCookieByIdHandler`, `GetAllCookiesHandler`, `GetCookiesPaginatedHandler` all go through `CookieRepository` → `CookieModel` (`$table = 'cookies'`). `ProjectionRegistry` is dead code (no caller in `app/`, only in tests). `CookieServiceProvider::registerEvents` does not register the projection. `CookieRestoredEvent` has no subscribed handler at all. The "denormalised read model" benefit is paid for in write-side projection cost and yields zero query benefit. **Severity: CRITICAL.**

3. **Event dispatch is split between two incompatible models.**
   Stock changes use `AggregateRoot::raiseEvent` + repository `pullEvents()` (`Cookie.php:217-250`, `CookieRepository.php:130-142`); lifecycle events (Created/Updated/Deleted/Restored) bypass the aggregate and are constructed + dispatched directly from handlers (`CreateCookieHandler.php:104-110`, `UpdateCookieHandler.php:113-120`, etc.). When `EventOutboxWriter` lands, half the events will reach it and half will not. `Cookie::update()` mutates five fields and raises no event at all. `activate()`/`deactivate()` flip state silently. `EventDispatcher::dispatch` catches `\Throwable` and continues (`EventDispatcher.php:91-103`), so the `TransactionMiddleware` promise of "listener exceptions roll back the write" is a lie. **Severity: CRITICAL.**

4. **MySQL NULL semantics break the composite UNIQUE.**
   `cookies` table has `UNIQUE(tenant_id, name, deleted_at)`. Repository never writes `tenant_id` (always NULL) and `deleted_at` is NULL on live rows. MySQL treats NULL as distinct in UNIQUE indexes, so the constraint never fires for active rows. The "duplicate-key" catch in `CookieRepository.php:100-119,122-128` is dead code in MySQL prod. Tests pass on SQLite which is more forgiving. Same flaw projects forward to `users` (no `UNIQUE(email, deleted_at)` at all — re-registration after soft-delete is blocked entirely). **Severity: CRITICAL.**

5. **Static state survives across requests in long-running workers.**
   `CorrelationIdService::$correlationId` is process-static and never reset in production (`CorrelationIdMiddleware::after` does not call `clear()`). In Swoole/Roadrunner/queue workers, every job after the first inherits the first job's correlation id. Tests call `::clear()`; production has zero callers. Similar pattern in `CookieRepository::trackPopularCookie` (per-instance counter unbounded on long-lived workers), `DomainLogger` (singleton keyed only to `'domain.validation'` losing per-domain channel), `BusinessMetricsLogging` (instance state). **Severity: CRITICAL/HIGH.**

6. **The Domain layer depends on Infrastructure (DIP inversion).**
   `app/Domain/User/Ports/RateLimitInterface.php:7` imports `App\Infrastructure\Auth\ValueObjects\RateLimitResult` — Port importing Infrastructure. `app/Domain/User/Entities/User.php:65-75`, `app/Domain/Cookie/Entities/Cookie.php`, and User VOs (`Email.php:9`, `PasswordComplexity.php:9`, etc.) all import `App\Infrastructure\Logging\DomainLogger`. `UserRepositoryInterface` lives in `app/Infrastructure/Persistence/Repositories/` rather than `app/Domain/User/Ports/` (Cookie does this correctly; User does not). User handlers depend on concrete `UserRepository` not the interface (`RegisterUserHandler.php:22`, `GetUserByIdHandler.php:15`, `GetUserByEmailHandler.php:16`). **Severity: CRITICAL/HIGH.**

7. **Error-code registry is collision-prone and partly self-contradictory.**
   Per-domain `ErrorCodes` classes redefine the same numeric ranges: `Cookie/ErrorCodes.php:28` `COOKIE_VALIDATION_NAME = 101`, `User/ErrorCodes.php:21` `USER_VALIDATION_NAME = 100`, `USER_VALIDATION_EMAIL = 101`. Cross-domain log aggregation cannot disambiguate. Worse, `User/ErrorCodes.php:33,36` literally aliases two constants to the same int (`USER_BUSINESS_RULE_LOCKED = USER_BUSINESS_RULE_ACCOUNT_LOCKED = 301`; same for `_SUSPENDED = 303`) — Slevomat will flag duplicate constants. Multiple Cookie codes are declared but never used (`COOKIE_BUSINESS_RULE_INACTIVE`, `_NAME_DUPLICATE`, `COOKIE_STATE_DELETED`, `_CONCURRENT_MODIFICATION`). **Severity: HIGH.**

8. **No central `DomainEventInterface` / `DomainExceptionInterface` / `InfrastructureException`.**
   `AggregateRoot::raiseEvent(object $event)` accepts anything (`AggregateRoot.php:55`). `DomainException extends RuntimeException` and `ValidationException extends InvalidArgumentException` share no common base — catching "any domain fault" requires listing both. No infrastructure-exception type exists, so PDO failures bubble indistinguishable from domain rule violations. The CLAUDE.md claim of "clear separation between domain rule violations and infrastructure failures" is not honoured. **Severity: HIGH.**

9. **Shared Value Objects under-enforce invariants and ship implicit defaults.**
   `Money` silently defaults to USD on `Currency` omission (CRITICAL for a multi-tenant ERP). `DocumentNumber` and `AttachmentRef` have public constructors with zero validation. `DateTimeValue` uses the server timezone via `new DateTimeImmutable()` and `createFromFormat` without `DateTimeZone('UTC')` (CRITICAL — same string deserialised on two servers yields two instants). `DateTimeValue::equals` uses object identity `===` instead of timestamp comparison. `Money` is not round-trippable via `json_encode` (private `$amountMinor`, public `$currency` — amount silently dropped). `Actor::system($label)` accepts arbitrary multi-line input (log injection). `CookiePrice` advertises multi-currency but bounds are USD-cents (`MIN=1, MAX=999_999`) — JPY ¥1,000,000 cookie rejected; BHD displayed wrongly. **Severity: CRITICAL/HIGH.**

10. **No idempotency / no event versioning / no schema-evolution path.**
    `EventOutboxRelay::rehydrate` (`:153-189`) matches JSON keys to constructor parameter names by reflection. Rename a parameter, add a required parameter without default, or change a type — and every queued row throws `failed`. No `event_version` column. No `events:replay` command for failed rows. `IdempotencyMiddleware` writes the cache row AFTER the handler runs (TOCTOU window) and replay restores only `Content-Type`, dropping `Location`/`ETag`/custom headers. **Severity: HIGH.**

11. **Optimistic locking is half-implemented.**
    `Cookie::$version` + `bumpVersion()` + `updateWithOptimisticLock()` exist, but `UpdateCookieCommand` carries no `expectedVersion`; the handler reloads the entity inside its own scope so the `WHERE version = ?` clause compares the freshly-loaded version against itself. Last-write-wins. `restore()` bypasses version and timestamps entirely. The `affectedRows()` check (`CookieRepository.php:377-396`) reports rows-changed not rows-matched on MySQL — idempotent updates produce false-positive `ConcurrentModification` exceptions. **Severity: CRITICAL.**

12. **Asymmetric audit / actor attribution across events.**
    `RestoreCookieCommand` is the only command carrying `Actor`; Create/Update/Delete do not. `CookieCreatedEvent` has no `createdBy` field at all; `CookieUpdatedEvent.updatedBy` and `CookieDeletedEvent.deletedBy` default to `0` because handlers never populate them. `CookieStockChangedEvent` has no actor and no timestamp. `AuditMiddleware` resolves an actor for the `audit_log` table independently, papering over the gap there but leaving every domain-event consumer permanently without attribution. Audit columns (`created_by`, `updated_by`, `deleted_by`) exist in the migration but the repository never writes them. **Severity: CRITICAL/HIGH.**

---

## CRITICAL findings

1. **Tenant scoping absent across runtime; multi-tenant data leak surface.**
   Severity: CRITICAL. See cross-cutting theme #1 for touch-point list.
   Fix: introduce `TenantContext` service injected into repositories; scope every read/write/exists query by `tenant_id`. Make `tenant_id` `NOT NULL` once the resolver lands. Remove the misleading exception text "within the tenant" at `CookieRepository.php:108` until tenancy is wired.

2. **Read side never reads from the projection; projection never written by live events.**
   Severity: CRITICAL. Files: `app/Domain/Cookie/Projections/CookieReadModelProjection.php`, `app/Domain/Cookie/CookieServiceProvider.php:168-196`, `app/Infrastructure/Projections/ProjectionRegistry.php`, `app/Domain/Cookie/Queries/GetCookieById/GetCookieByIdHandler.php:54`, `app/Domain/Cookie/Queries/GetAllCookies/GetAllCookiesHandler.php:51`, `app/Domain/Cookie/Queries/GetCookiesPaginated/GetCookiesPaginatedHandler.php:53`, `app/Commands/RebuildProjections.php:80-90`.
   Sub-issues: (a) `ProjectionRegistry` is dead code, no Services factory, no caller; (b) `CookieRestoredEvent` has no handler subscription anywhere; (c) `onCreated/onUpdated/onRestored` re-load via `repository->findById()` instead of using event payload (stale-read race); (d) `onStockChanged` is UPDATE-only (cannot create row on out-of-order replay); (e) `upsertFromEntity` does `SELECT count → INSERT/UPDATE` (textbook race); (f) `truncate()` + paginated rebuild collides with live writes.
   Fix: add `DomainServiceProviderInterface::registerProjections(ProjectionRegistry)` and wire from boot; subscribe a `CookieRestoredEventHandler`; switch query handlers to a `CookieReadModelRepository`; drive projection writes from event payloads with `findById` as logged fallback; replace SELECT-count-then-INSERT with `INSERT ... ON DUPLICATE KEY UPDATE`; shadow-table-and-swap for rebuilds.

3. **Event dispatch split; transactional event guarantee is a lie.**
   Severity: CRITICAL. Files: `app/Domain/Cookie/Commands/{CreateCookie,UpdateCookie,DeleteCookie,RestoreCookie}/*Handler.php` (direct dispatch), `app/Domain/Cookie/Entities/Cookie.php:195-207,217-250,286-300` (mixed/silent), `app/Infrastructure/Bus/EventDispatcher.php:91-103` (swallows `\Throwable`), `app/Infrastructure/Bus/Middleware/TransactionMiddleware.php:21-24` (docs promise rollback on listener exception), `app/Models/Cookie/CookieRepository.php:130-142` (only path that pulls aggregate events; falls back to drain-without-dispatch on null dispatcher).
   Fix: move all lifecycle event raising into the entity (`create`, `update`, `softDelete`, `restore`, `activate`, `deactivate`); delete `eventDispatcher->dispatch()` from handlers; rely on `CookieRepository::dispatchPendingEvents()`. Decide event-on-transaction semantics and align docs+code (either rethrow in dispatcher OR remove the misleading promise from TransactionMiddleware docs).

4. **Optimistic locking not exercised; `restore()` bypasses it; `affectedRows()` produces false positives on MySQL.**
   Severity: CRITICAL. Files: `app/Domain/Cookie/Commands/UpdateCookie/UpdateCookieCommand.php:27-34` (no `expectedVersion`), `app/Domain/Cookie/Commands/UpdateCookie/UpdateCookieHandler.php:72-111` (re-loads, never compares caller's version), `app/Models/Cookie/CookieRepository.php:266-281` (restore via raw builder, no version/timestamp/audit), `app/Models/Cookie/CookieRepository.php:377-396` (`affectedRows()` ≠ `matchedRows()` on MySQL — idempotent updates throw `ConcurrentModification` false-positives).
   Fix: add `expectedVersion` to `UpdateCookieCommand` and pass through to the repo's `WHERE version = ?` clause. Route `restore()` through `updateWithOptimisticLock`. Replace `affectedRows()` with `MYSQLI_CLIENT_FOUND_ROWS` or post-update SELECT.

5. **MySQL composite UNIQUE never fires; duplicate-name protection is theatre.**
   Severity: CRITICAL. Files: `app/Database/Migrations/2025-01-21-000001_CreateCookiesTable.php:51-56,130` (`UNIQUE(tenant_id, name, deleted_at)`), `app/Models/Cookie/CookieRepository.php:100-119,343-372` (never writes `tenant_id`; falls through to NULL on `deleted_at`), `app/Models/Cookie/CookieRepository.php:122-128` (`isDuplicateKey` substring-matches English MySQL message; misses non-English locales, PostgreSQL, SQL Server).
   Fix: either (a) sentinel-not-null `deleted_at` (`'9999-12-31'`), (b) PostgreSQL partial unique `(tenant_id, name) WHERE deleted_at IS NULL`, or (c) drop the composite and use application-layer + DB partial unique. Translate duplicate-key by SQLSTATE / `getCode()`, not by message substring.

6. **Cookie aggregate raises events with `cookieId = null` for unpersisted entities.**
   Severity: CRITICAL. Files: `app/Domain/Cookie/Entities/Cookie.php:236-241,259-264` (`CookieStockChangedEvent::cookieId = $this->id` while `$this->id === null` for freshly-`create()`d cookies; `CookieStockChangedEvent::cookieId` is typed `?int` to permit this nonsense; `app/Domain/Cookie/Events/CookieStockChanged/CookieStockChangedEvent.php:19`). Event consumers cannot route nullable-id events.
   Fix: defer event raising until after `assignId()` runs, or stamp the id at drain time, or guard `raiseEvent` on `$this->id !== null`. Make `cookieId` non-nullable.

7. **Shared `Money` defaults to USD silently; not JSON-round-trippable.**
   Severity: CRITICAL. Files: `app/Domain/Shared/ValueObjects/Money.php:31-40,47,58,94,99-101` (USD default), `Money.php:33-34` (private `$amountMinor` + public `$currency` → `json_encode` drops the amount).
   Fix: make `Currency` required everywhere; remove `defaultCurrency()` fallback; implement `JsonSerializable` with a stable shape + `fromArray()`. Same audit applies to `CookiePrice` which uses `Currency::usd()` as default at `app/Domain/Cookie/ValueObjects/CookiePrice.php:55-68,235-238`.

8. **`DocumentNumber` and `AttachmentRef` constructable in invalid states.**
   Severity: CRITICAL. Files: `app/Domain/Shared/ValueObjects/DocumentNumber.php:20-26` (public ctor, zero validation; `formatted` may disagree with `value`), `app/Domain/Shared/ValueObjects/AttachmentRef.php:17-27` (public ctor, no checks on id/mime/size/checksum/attachable_type).
   Fix: make ctors private; add `fromService`/`fromRow`/`fromUpload` named factories with full invariant checks; constrain `attachableType` to an enum or FQCN-pattern.

9. **`DateTimeValue` ignores timezone; equality broken.**
   Severity: CRITICAL. Files: `app/Domain/Shared/ValueObjects/DateTimeValue.php:54-57,65-74` (implicit server tz via `new DateTimeImmutable('now')` and `createFromFormat('Y-m-d H:i:s', $datetime)`), `DateTimeValue.php:116-119` (`equals` uses `===` on objects — only true for identical instances), `DateTimeValue.php:65-74` (accepts `2025-02-30 00:00:00` silently → March 2).
   Fix: pass `DateTimeZone('UTC')` everywhere; replace `===` with timestamp comparison; check `DateTimeImmutable::getLastErrors()`.

10. **Document numbering is not gapless under concurrency.**
    Severity: CRITICAL. File: `app/Infrastructure/Numbering/DocumentNumberingService.php:106-151,114-118`. Docblock advertises `SELECT ... FOR UPDATE` but the code does plain SELECT then UPDATE — two concurrent allocators both read `N`, both write `N+1`, both return the same number (lost update). Compliance / tax-authority violation for any document where gapless uniqueness is required.
    Fix: `INSERT ... ON DUPLICATE KEY UPDATE current_value = current_value + 1` returning `LAST_INSERT_ID(current_value)`, or explicit `lockForUpdate()`.

11. **`EventOutboxWriter` is dead code; outbox guarantee is aspirational.**
    Severity: CRITICAL. File: `app/Infrastructure/Outbox/EventOutboxWriter.php`. No call sites; no Services factory; `TransactionMiddleware:22` admits "the simplest approximation of an outbox until we add one". Aggregates dispatch events synchronously via `EventDispatcher` whose listeners' exceptions are swallowed (theme #3). Relay drains an always-empty table. Atomicity is structurally absent.
    Sub-issue: `EventOutboxRelay::claim()` returns `$affected === true || affectedRows() === 1` (`EventOutboxRelay.php:142-151`) — `update()` returns `bool` on most drivers and `true` even when zero rows match, so a second worker dispatches the same event again. Double-delivery.
    Fix: wire writer into persistence path or remove until needed; gate claim on `affectedRows() === 1` only.

12. **JWT / auth — refresh-token replay window, file-cache blacklist, two admin bypasses.**
    Severity: CRITICAL (six related findings).
    a) `app/Infrastructure/Auth/Services/TokenBlacklistService.php:52` hardcodes 30-day TTL while `AUTH_REFRESH_TOKEN_TTL` is configurable — refresh token outlives its blacklist row.
    b) Blacklist is file-cache by default (`app/Config/Cache.php:45`) — `cache:clear` or deploy resurrects every logged-out token until natural expiry.
    c) `TokenBlacklistService::cleanupIfNeeded` (`:108,136-137`) wipes the counter, not the entries — capacity check is fake.
    d) `app/Infrastructure/Auth/Commands/RefreshToken/RefreshTokenHandler.php:50-93` never consults blacklist on inbound refresh; login (`LoginUserHandler:121`) writes to `sessions` but NOT to `refresh_tokens`, so the first refresh of the login-issued token cannot detect replay.
    e) `app/Infrastructure/Auth/Services/PermissionService.php:43-79` "transitional admin shim" short-circuits to `return true` on `role === 'admin'` before RBAC lookup, with no kill-switch, no audit log, no expiry.
    f) `app/Infrastructure/Auth/Services/PermissionService.php:38-41` returns `true` for `Actor::isSystem()`; `ActorResolver::resolve()` returns `Actor::system()` whenever it can't extract a user id from the request. PermissionMiddleware catches this at `:55-59`, but any direct caller (controllers, handlers, queries) of `PermissionService::allows` outside the middleware gets silent permission grant for unauthenticated requests.
    Fix: move blacklist to Redis with TTL derived from `AUTH_REFRESH_TOKEN_TTL`; insert into `refresh_tokens` at login; consult blacklist in refresh handler; gate the admin shim behind env flag and log every use; make ActorResolver/PermissionService default-deny for system on HTTP.

13. **Web admin user routes have no role gate; CSRF can be globally disabled.**
    Severity: CRITICAL. Files: `app/Config/Filters.php:139-148` (only `web_auth` on `admin/*`; no `role:admin`), `app/Config/Routes.php:48-58` (no inline filter). Any authenticated user (customer/guest) can list/create/update/delete users and reset passwords via the web UI. Comment "Only admins can change passwords (enforced by filter)" at `ChangeUserPasswordHandler.php:26` is false for the web path.
    `Filters.php:95` disables CSRF entirely when `ENVIRONMENT === 'testing'` — a single misconfigured deploy with `CI_ENVIRONMENT=testing` disables CSRF site-wide.
    Fix: add `role:admin` to `admin/users/*` route group; invert filters to allow-list (`globals.before` with `except` of public routes); runtime-assert non-testing in production.

14. **`AuditMiddleware` failure cascades into business-write rollback (contradicts contract).**
    Severity: CRITICAL. File: `app/Infrastructure/Bus/Middleware/AuditMiddleware.php:92-113`. Catches its own insert failure (docblock says it must not re-throw). But running INSIDE `TransactionMiddleware`, the CI4 builder's failed insert flips `transStatus` to `false` (`vendor/codeigniter4/.../BaseConnection.php:910-915`). When TransactionMiddleware checks `transStatus()` at `:59`, the entire business transaction rolls back. A flaky `audit_log` table takes down every command.
    Fix: `$db->resetTransStatus()` after caught audit failure, or write audit on a separate connection group, or move audit outside the transaction.

15. **`commandBus()` shared instance has no middleware.**
    Severity: CRITICAL. File: `app/Config/Services.php:90-113`. Middlewares (logging/transaction/audit) are pushed only on the non-shared path (`:105-110`). The shared path uses CI4's `getSharedInstance` which calls `new CommandBus()` with no middleware. In production the shared bus is used everywhere → logging/transaction/audit silently disabled.
    Fix: push middleware unconditionally; assert middleware list is non-empty after construction.

16. **CSP blocks Bootstrap; baseURL hard-coded HTTP; DB encrypt off.**
    Severity: CRITICAL.
    a) `app/Config/ContentSecurityPolicy.php:57,64` has `scriptSrc/styleSrc = 'self'` but `app/Views/layout.php:24,53` loads Bootstrap CSS/JS from `cdn.jsdelivr.net`. Production deploys will render unstyled / non-functional UI.
    b) `app/Views/layout.php:39` and `app/Views/partials/_sidebar.php:36` use inline `style="..."` attributes, blocked by `style-src 'self'`.
    c) `app/Config/App.php:43` `baseURL = 'http://localhost:8080/'` hard-coded HTTP; combined with `forceGlobalSecureRequests` produces mixed-content / broken absolute URLs in emails.
    d) `app/Config/Database.php:42` `'encrypt' => false` — credentials in cleartext to remote MySQL.
    Fix: vendor Bootstrap; remove inline styles; env-source baseURL; default `encrypt => true`.

17. **`CorrelationIdService` static state survives across requests.**
    Severity: CRITICAL. File: `app/Infrastructure/Logging/CorrelationIdService.php:33,60-66`. Process-static `$correlationId` never reset in production. `CorrelationIdMiddleware::after` (`:55-60`) sets the response header but does NOT call `clear()`. In FPM this works (one process per request); in Swoole/Roadrunner/queue workers, every job after the first inherits the first job's id. All logs across all jobs collapse to a single trace id. Tests call `clear()`; production has zero callers.
    Fix: call `CorrelationIdService::clear()` at end of `CorrelationIdMiddleware::after`; reset before each job/event dispatch.

---

## HIGH findings

1. **Cookie aggregate: silent state mutations and inconsistent invariant enforcement.**
   - `Cookie::update()` (`app/Domain/Cookie/Entities/Cookie.php:195-207`) mutates name/description/price/stock/isActive with no event and no invariant pipeline.
   - `Cookie::activate()` / `deactivate()` (`:286-300`) flip state with no event, no guard against `isDeleted()`.
   - `Cookie::decreaseStock` / `increaseStock` (`:217-264`) do not check `isDeleted()` or `isActive`.
   - `Cookie::reconstitute()` (`:132-152`) runs `setStock()` validator → corrupted rows with negative stock cannot be rehydrated to repair.
   - `Cookie::assignId()` and `bumpVersion()` are `public` with `@internal` doc only — handlers can defeat optimistic locking.
   Fix: introduce `assertInvariants()` called from ctor + `update()`; raise lifecycle events; tighten visibility via package-private trait.

2. **`CookiePrice` is mono-currency code in multi-currency clothing.**
   - `app/Domain/Cookie/ValueObjects/CookiePrice.php:37-38` `MIN_MINOR_UNITS = 1`, `MAX_MINOR_UNITS = 999_999` — USD-cents semantics enforced regardless of `Currency`.
   - `:210-229` `assertPositiveAndInRange` divides minor by 100 for error message — wrong for JPY (0 decimals) and BHD (3 decimals).
   - `:55-68,235-238` `fromString()` falls back to `Currency::usd()` when omitted (data corruption magnet).
   - `:190-203` `applyDiscount(float)` accepts float (boundary case: 100% discount produces 0 then throws `tooSmall`).
   Fix: per-currency bounds; required currency; typed `DiscountPercent` VO.

3. **`Money` arithmetic overflow uncaught.**
   `app/Domain/Shared/ValueObjects/Money.php:77-83,99-101,158-173` — `((int) $major) * $factor + (int) $minorPadded`, `add/subtract/multiply`, `fromFloat`: silent int → float promotion on overflow. No bound check against `PHP_INT_MAX`.

4. **`Actor::system($label)` accepts arbitrary multi-line input → log injection.**
   `app/Domain/Shared/ValueObjects/Actor.php:36-39`. No length cap, no charset whitelist. `Actor::system("admin\ninjection: forged-line")` flows into audit logs.
   Fix: validate non-empty, max 64 chars, `[a-z0-9_:.-]+`.

5. **Error code carrier broken across the board.**
   - Integer-only error codes with no central registry (`DomainException.php:38`, `ValidationException.php:41`).
   - Cookie 101 and User 101 collide (theme #7).
   - `User/ErrorCodes.php:33,36` literally aliases two constants to the same int.
   - PHP's native `$code` and the domain `$errorCode` co-exist; `parent::__construct($message, $code)` passes the PHP code → `getCode()` and `getErrorCode()` return different ints.
   - Missing factory shapes: `ValidationException::tooLarge`, `custom`, `notInSet`; `DomainException::alreadyExists`, `softDeleted`, `precondition`, `unauthorized`.
   - No common `DomainExceptionInterface`; no `InfrastructureException`.

6. **Cookie domain provider gaps multiply across clones.**
   - `CookieServiceProvider.php:168-196` does not register `CookieReadModelProjection` or `CookieRestoredEventHandler`.
   - `CookieServiceProvider.php:170` uses `LoggerFactory::create('cookie.events')` static factory, bypassing the injected logger.
   - `CookieServiceProvider.php:238-241` `getRepository()` does no key-existence check.

7. **Cookie repository defects (audit + builder).**
   - `created_by` / `updated_by` / `deleted_by` never written; audit columns are migration decoration (`CookieRepository.php:343-372,246-261,266-281`).
   - `existsByName` includes soft-deleted rows but the migration explicitly designs `UNIQUE(tenant_id, name, deleted_at)` to allow reuse-after-delete — handler is stricter than schema (`CookieModel.php:93-98`).
   - `CookieRepository.php:422-492` shares `$this->model->builder()` across calls — CI4 does NOT reset the builder; predicates leak between consecutive `findAll`/`findPaginated` calls.
   - Save+dispatch not transactional (`CookieRepository.php:85-120,130-142`) — projection handler failure leaves read model permanently desynced.
   - `dispatchPendingEvents` falls back to `pullEvents()` when `$eventDispatcher` is null, silently dropping aggregate events (`CookieRepository.php:130-142`).
   - `CookieModel::existsByName` uses `LOWER(name) = ?` which prevents index usage (`CookieModel.php:96`).
   - CI4 model `validationRules` duplicate Value Object validation (`CookieModel.php:56-79`).
   - `findById` mutates instance state on every read via `trackPopularCookie` (`CookieRepository.php:150-168`) — unbounded growth in long-lived workers.

8. **Cookie query handlers return entities, not `CookieView` DTOs.**
   - `GetCookieByIdHandler.php:54` returns `?Cookie`.
   - `GetAllCookiesHandler.php:51` returns `array<int, Cookie>`.
   - `GetCookiesPaginatedHandler.php:53` returns `array{data: array<int, Cookie>, …}`.
   - `CookieView` is dead code outside tests.
   - `CookieView::detail/summary` reads `getId() ?? 0` — unpersisted entity becomes id 0 in JSON.
   - `CookieView::$extra` is documented but `toArray()` silently drops it.
   - Search input is `trim()` only — no length cap, no `LIKE` wildcard escaping (`GetCookiesPaginatedQuery.php:44`).
   - `like('name', ...)` default side wildcards force full-table scans.
   - Pagination `page` has no upper bound — `LIMIT … OFFSET 19999999980` DoS surface.

9. **Outbox / Jobs operational gaps.**
   - No reaper for `in_flight` outbox rows or `reserved` job rows; worker crash leaves rows stuck forever.
   - `JobWorker::resolveHandler` uses zero-arg `new $class()` — handlers cannot receive DI, forced to service-locate `Config\Services::*` inside `handle()`.
   - No job-handler registry; typo / removed handler eats the entire retry budget.
   - Reflection-based event rehydrate (`EventOutboxRelay::rehydrate` `:153-189`) breaks on constructor signature change — no `event_version`.
   - `correlation_id` leaks across rows in relay and across jobs in worker (set, never restored).
   - No `SIGTERM` handler in `--watch` loops; rows stuck mid-flight.
   - `MAX_ATTEMPTS = 6` backoff array off-by-one — the advertised "24h" tier is unreachable.
   - `--watch` boolean parsing bug across all spark commands: `CLI::getOption('--flag') !== null` misbehaves with `--flag=false`.
   - Cleanup commands return `void` instead of `int` exit code (`CleanupExpiredSessions.php`, `CleanupPasswordResetTokens.php`).

10. **`AuditMiddleware` digest leaks new_password / refresh_token and serialises VOs as `{}`.**
    - `AuditMiddleware.php:162-166` sensitive-key list diverged from `RedactingProcessor.php:31-50` — missing `password_hash`, `new_password`, `old_password`, `current_password`, `refresh_token`, `access_token`. Digest input includes these.
    - `AuditMiddleware::extractPublicState` (`:145`) only reads PUBLIC properties — commands using private promoted properties or VOs assigned in ctor produce `{}` digests.
    - `normaliseForJson` (`:209-218`) collapses VOs/enums/DateTimeImmutable to bare class names — two different commands produce identical digests.
    Fix: extract shared `SensitiveKeys::LIST`; iterate all properties via reflection; proper normaliser handling `UnitEnum`, `Stringable`, `getValue()`.

11. **Storage path-traversal guard is incomplete; attachments unvalidated polymorphic type.**
    - `app/Infrastructure/Storage/LocalStorage.php:108-112` falls back to `$baseDir` when `realpath(dirname($path)) === false` (new directory case) — `str_starts_with($parent, $baseDir)` trivially true regardless of `$key`.
    - `LocalStorage.php:100` substring `..` rejects `report..final.pdf`; should split on separator and check segments.
    - Writes non-atomic (no `rename` from tmp); no locking; `@unlink` swallows failures.
    - `app/Infrastructure/Storage/AttachmentService.php:46-60` `attachableType` is unvalidated free-form string — polymorphic-deserialisation foothold.
    - `AttachmentService::attachTo` (`:62-83`) does `storage->put` before DB insert → orphan file on insert failure.
    - `read`/`delete`/`listFor` (`:102-154`) ignore `tenant_id` — cross-tenant data leak by integer id.

12. **`EmailService::sendTemplate` allows arbitrary `app/Views/` paths.**
    `app/Infrastructure/Email/EmailService.php:44-64` passes `$view` straight to CI4 `view()` helper. Any caller that forwards user input enables LFI within `app/Views/`. Headers logged on failure via `printDebugger(['headers'])` (`:149`) — leaks SMTP From/Reply-To and historically SMTPUser.
    Fix: enum/allow-list of templates; log `printDebugger([])` only.

13. **CurlHttpTransport SSRF surface; OutboundHttpClient retries non-idempotent POSTs.**
    - `app/Infrastructure/Http/Client/CurlHttpTransport.php:43` no `CURLOPT_PROTOCOLS` restriction — `file://`, `gopher://`, `dict://` etc reachable from user-influenced URLs.
    - `app/Infrastructure/Http/Client/OutboundHttpClient.php:154-156` auto-Idempotency-Key applied to all mutating methods including POST retries assuming remote service dedupes; no opt-out.
    - `:125` ignores `Retry-After` header.
    - `:194` `usleep($s * 1_000_000)` blocks worker; no jitter (thundering herd).

14. **`IdempotencyMiddleware` window + replay gaps.**
    - Cache row written AFTER handler runs; transient failure → double execution (`IdempotencyMiddleware.php:111-127`).
    - Re-lookup before insert (`:106-108`) — TOCTOU; unique-index rejects one, but handler already ran twice.
    - Replay restores only `Content-Type` (`:122-124`); drops `Location`, `ETag`, `Cache-Control`, custom headers.
    - `actorId()` instantiates `new ActorResolver()` directly (`:152`) — unauthenticated requests with Idempotency-Key error out.
    - Request hash ignores headers (`:155-163`) — `Accept` variations replay the wrong content type.

15. **Bulk CSV: writer not streaming despite "memory-friendly" claim; reader header-only mismatch.**
    - `CsvWriter::toString` (`CsvWriter.php:55-62,89`) writes to `php://temp` then `stream_get_contents` loads the entire file into memory. Docblock at `:8` is misleading.
    - No UTF-8 BOM; Excel-on-Windows mis-decodes UTF-8 exports.
    - `BulkImportRunner.php:105-106` header-only CSV throws if required columns exist, even if header is valid.

16. **JWT / session asymmetry; fingerprint salt missing.**
    - `app/Infrastructure/Auth/Middleware/SessionAuthMiddleware.php:46-73` validates only `user_id` + `User::isActive()` — no fingerprint, no idle timeout, no concurrent-session cap. Asymmetric to JWT tier; attacker targets the weaker channel.
    - `JwtAuthenticationMiddleware.php:394-398` device fingerprint is `sha256(ip|user_agent)` unsalted — attacker who knows IP+UA can forge.
    - `JwtAuthenticationMiddleware.php:214-219,325-330` per-request DB roundtrips without `limit(1)` — DoS surface.
    - `SessionManagementService.php:257-297` `enforceSessionLimit` count-then-insert race.
    - `RateLimitService.php:67-87` token-bucket read-modify-write not atomic (no CAS); FileHandler default makes "atomic" claim untrue.
    - Login uses per-IP rate limit only (`Routes.php:39,72`); no per-email lockout enforced before authenticate.

17. **Session.php / Filters.php production posture inverted.**
    - `Session.php:24` `FileHandler` default kills multi-server deployments.
    - `Filters.php:139-148` `web_auth` is allow-by-default-deny-by-listed-pattern — backwards from secure-by-default.
    - `Filters.php:89-112` `correlation` runs AFTER `csrf` — CSRF rejection logs have no correlation id.
    - `Services.php:163-201` `ensureProvidersRegistered` flag set AFTER `registerAll` returns — re-entrance window if any provider's constructor calls `service('commandBus')`.

18. **User domain parity / DDD violations.**
    - `UserRepositoryInterface` lives in `app/Infrastructure/Persistence/Repositories/` not `app/Domain/User/Ports/` (Cookie is correct).
    - `RegisterUserHandler.php:22`, `GetUserByIdHandler.php:15`, `GetUserByEmailHandler.php:16` depend on concrete `UserRepository` not the interface.
    - `UpdateUserCommand` has no `Actor` — role/status changes are unattributed.
    - `app/Domain/User/Ports/RateLimitInterface.php:7` imports `App\Infrastructure\Auth\ValueObjects\RateLimitResult` — Port → Infrastructure dependency.
    - Audit columns (`created_by`/`updated_by`/`deleted_by`) absent from `users` migration.
    - No `RestoreUserCommand` (Cookie has one).
    - User VOs throw `\InvalidArgumentException` (`UserName.php:67`) inconsistent with `Email`/`PasswordComplexity` which throw `ValidationException`.
    - `RegisterUserHandler` dummy-hash timing oracle is backwards (`:105` only on duplicate-email branch).
    - Password trim asymmetry: `HashedPassword::fromPlaintext` (`:96`) trims plaintext; login does not.
    - `PasswordComplexity` (`:65`) uses `strlen` not `mb_strlen`.
    - `UpdateUserHandler.php:64,81`, `ChangeUserPasswordHandler.php:70`, `DeleteUserHandler.php:73` throw `\RuntimeException` not `DomainException`.
    - `RestoreCookieHandler.php:47-49` similar issue.
    - `DeleteUserHandler.php:60` self-deletion throws with `USER_VALIDATION_NAME` (wrong code class).

19. **Logging infrastructure problems.**
    - `RedactingProcessor` (`app/Infrastructure/Logging/RedactingProcessor.php`) does not redact `$record->message` interpolations; does not scan values for JWT patterns; missing `pwd`, `pass`, `bearer`, `session_id`, `client_secret`, `pin`, `ssn`, `iban`, `account_number`.
    - `DomainLogger` (`app/Infrastructure/Logging/DomainLogger.php:43-50`) uses single shared channel `'domain.validation'` — CQRS context processor parses domain="domain", losing per-domain channel.
    - VOs and entities import `DomainLogger` directly (theme #6).

20. **Migration / schema inconsistencies.**
    - Mixed timestamp delimiters: `2025-01-21-…`, `2025-10-26-…`, `2026-05-…` vs `2025_10_27_…` — lexical ordering depends on `_` > `-`.
    - `users` table missing `UNIQUE(email, deleted_at)` — soft-delete blocks re-registration; missing `tenant_id`, `version`, `created_by/updated_by/deleted_by`.
    - `failed_login_attempts` is signed INT.
    - `notifications` table has separate `(user_id, read_at)` and `(user_id, created_at)` indexes instead of one composite `(user_id, read_at, created_at)`.
    - `cookie_read_model` lacks `tenant_id` cross-check vs write side.
    - `permissions_schema` join tables have no `FOREIGN KEY` constraints (laxer than the SQLite test environment).
    - `cookies.price DECIMAL(10,2)` not `unsigned` — defence-in-depth gap.

21. **Test infrastructure Cookie-coupled and partial.**
    - `IntegrationTestCase.php:60-62`, `FeatureTestCase.php:68-70` eagerly build `CookieRepository` in `setUp()`.
    - No factories for `Permission`, `Role`, `Notification`, `AuditLog`, `Setting`, `Attachment`.
    - `phpunit.xml.dist` forces SQLite `:memory:`; production is MySQL; collation/UNIQUE-with-NULL behave differently.
    - `bootstrap.php` references `tests/_support/...` but PSR-4 path is `tests/Support/` — case-sensitive-FS hazard.
    - `FeatureTestCase::loginAsAdmin()` writes a fake argon2id hash that no real login can verify.

22. **Views duplicate per-entity; permission gating missing.**
    - `app/Views/cookies/index.php:1-96` and `app/Views/admin/users/index.php:1-117` re-roll search/table/pagination; neither uses `partials/_pagination`.
    - Action buttons (Create, View, Edit, Reset-Password) render unconditionally without `can()` checks (Sidebar uses `can()`; index views do not).
    - Hard-coded English in `dashboard.php`, both index views, `auth/login.php`, `auth/register.php` — `lang()` is half-adopted.
    - `_user_menu.php:22-26` swallows all `Throwable` from `NotificationService`; `:23` `new NotificationService()` direct instantiation in a view.

---

## MEDIUM findings

1. **`CookieName` locale-broken case comparison.** `app/Domain/Cookie/ValueObjects/CookieName.php:124-127` uses `strtolower` — Turkish I, German ß, etc. mis-dedupe. Fix: `mb_strtolower($value, 'UTF-8')`. Same risk in `CookieReadModelProjection.php:205` `name_search`.

2. **`CookieName` accepts control chars, RTL overrides, emoji.** `CookieName.php:39-40` MIN=3/MAX=100 with no whitelist, no NFC normalisation.

3. **`Cookie::isAvailable()` packs three concerns into one bool.** `Cookie.php:309-312` — clients reimplement the logic externally.

4. **`Cookie::$version` defaults to 0; optimistic lock fails-open on legacy rows.** `Cookie.php:67,142`.

5. **`Cookie::create(bool $isActive = true)` contradicts lifecycle.** Cookies should always be created active; `activate/deactivate` are the only transitions.

6. **`Cookie::reconstitute()` runs invariants → cannot rehydrate corrupted rows.**

7. **CookieView read-DTO coupled to entity.** `CookieView.php:7,53,74` import `Cookie`; no `fromRow(array)` factory; `price` returned as ambiguous decimal string.

8. **`UpdateCookieCommand` forces full-state overwrite.** `:27-34` — no partial updates; cloned domains will inherit this hostile-by-default API.

9. **`CreateCookieHandler::handle` 75 lines, `UpdateCookieHandler::handle` 84 lines — both blow past the 20-line rule in CLAUDE.md.**

10. **`existsByName` race with TransactionMiddleware default isolation.** Redundant pre-check; rely on DB unique index but acknowledge it's best-effort.

11. **`CreateCookieHandler::determineErrorCode` matches exception messages with `str_contains`** (`:155-161`). Brittle to localisation. Fix: typed exception subclasses.

12. **`AuditMiddleware` and `TransactionMiddleware` connections implicitly shared.** `Services.php:106-110` constructs both without `$db`. Future "use analytics DB for audits" breaks atomicity.

13. **`TransactionMiddleware` does not reset transStatus before begin; silently nests when caller is already in a transaction.** `TransactionMiddleware.php:44`.

14. **`EventDispatcher::dispatch` listener order is registration order, no priorities.** `EventDispatcher.php:90`.

15. **`StateMachine` stringly-typed transition table; `State` interface decorative; no construction-time validation.** `StateMachine.php:50-52,58`; `State.php:19`.

16. **`StateMachine` recommended pattern doesn't memoise; every method call instantiates a fresh table.**

17. **`InvalidTransition` carries no error code; no accessors for `from`/`to`/`allowed`.**

18. **`AggregateRoot::raiseEvent(object)` accepts anything.** No `DomainEventInterface`. No `replayEvents` distinction for event-sourcing.

19. **`AggregateRoot` is a trait, not abstract class.** Cannot enforce `getId()` at type level. `pullEvents()` clears the buffer; rollback after pull loses events.

20. **`UpdateUserHandler` six adjacent `if ... $updatedFields[]` lines should extract `diffFields`.** `:91-102`.

21. **User pagination handlers return `array{data,total,page,perPage,totalPages}` instead of typed result object.** Same shape duplicated across domains.

22. **`SearchUsersHandler.php:62-66` passes `searchTerm: $query->email ?? ''` but repository matches against name OR email.** Misleading API.

23. **`UserRegisteredEvent` uses `\DateTimeImmutable`; `UserUpdatedEvent`, `UserDeletedEvent`, `PasswordChangedEvent` use string. Inconsistent.**

24. **`RegisterUserCommand.role` is dead weight — handler always overwrites to `Customer`.**

25. **`UserModel.$allowedFields` will need updating when audit columns are added.**

26. **`UserRepository::findById` etc. `$this->model->where('deleted_at IS NULL')` strings double-apply with `useSoftDeletes = true`.** `:233,255,280`.

27. **`HashedPassword::fromHash` reaches `User::reconstitute` without null-guard on `password_hash` column.**

28. **`PasswordComplexity` special-char regex limited to ASCII punctuation.**

29. **`ResetPasswordHandler` does not invalidate other web sessions on success** (only refresh tokens; `ChangeUserPasswordHandler` does both).

30. **`ResetPasswordHandler::findResetToken` SELECT by token_hash is not constant-time** but the docblock claims it is. Cosmetic but misleading.

31. **`PasswordResetToken::fromToken` accepts arbitrary length/charset.**

32. **`RequestPasswordResetHandler` user-enumeration via timing: null-branch is fast, hit-branch does DB + email.**

33. **`SessionAuthMiddleware` does not regenerate session id on role change** (only on login).

34. **`ActorResolver::extractFromRequest` accepts any object with `getId(): int > 0`** — bug elsewhere assigning the wrong object becomes the actor.

35. **`PermissionMiddleware::parseArgument` conflates 500-config-error with 403-denied.**

36. **`JwtService::isWeakSecret` is shape-only (32-char + static substring list)** — `"aaaa..."` passes.

37. **`RefreshTokenHandler::storeRefreshToken` ignores DB insert failure** (`:155-167`) — issued token cannot be revoked.

38. **JWT `iss`/`aud` claims set but never verified on decode.**

39. **`JwtAuthenticationMiddleware::checkIdleTimeout` opt-in via env; silent-skips legacy tokens.**

40. **`JwtAuthenticationMiddleware::isBearerTokenFormat` case-sensitive — violates RFC 6750.**

41. **Logout endpoint not rate-limited.**

42. **`RateLimitMiddleware::parseArguments` casts `(int)` silently; throws uncaught → 500.**

43. **CSRF policy is global to all routes; `/api/v1/*` JWT-stateless tier must juggle CSRF cookie flow.** `Filters.php:95`.

44. **`SecurityEventService::logEvent` synchronous DB writes — DoS amplifier.**

45. **`LoginUserHandler` `ipAddress ?? '0.0.0.0'`** pollutes `login_attempts` table.

46. **Refresh endpoint rate limit (`10,300`) weaker than login (`5,300`).**

47. **`ApiResponse::noContent()` 204 bypasses correlation_id envelope.** `:107-110`.

48. **`CurlHttpTransport` `$headerSize` correctly handles multiple header blocks but `parseHeaders` overwrites — minor diagnostic loss.**

49. **`OutboundHttpClient` exhausted-retries from network failure carries `lastResponse: null` even when earlier responses populated it.** `:104-109`.

50. **`OutboundHttpClient` backoff array smaller than maxAttempts → flat-line at last value; docblock says "exponential".**

51. **`CsvReader::fromString` buffers entire input.** `:45-55`.

52. **`CsvReader` "header-strict" only by column count.** `:80-87`.

53. **`BulkImportRunner::&$iterator` by-ref is misleading; no reassignment.**

54. **`LoggerFactory` fallback log path 4 levels up `__DIR__` (`:75-82`) fragile.**

55. **`LoggerFactory` no construction-time check for unwritable logs dir.**

56. **`DomainLogger` reset() called only by tests; no production reset hook.**

57. **`RedactingProcessor` doesn't redact `Throwable::getMessage()` or list-array values.**

58. **`Currency.php:44-52` regex `/^[A-Z]{3}$/` accepts `ZZZ`/`XXX`/`AAA` (non-existent codes).**

59. **`Currency` missing 4-decimal codes (CLF, UYW).**

60. **`Money` currency-symbol strip is partial** (`Money.php:204`); `R$`, `kr`, `₹` survive.

61. **`Money::equals` cross-currency returns false; `greaterThan`/`lessThan` throw. Inconsistent.**

62. **`Email` no length cap (RFC 5321 caps 254 octets).** `Email.php:45-54`.

63. **`Email` local-part case lost on normalisation.** `:47`.

64. **`Permission` no length cap on segments.** `:25-37`.

65. **All shared VOs missing canonical JSON serialisation** (no `JsonSerializable`, no `fromArray`).

66. **3 of 8 shared VOs lack `equals()`** (`Actor`, `DocumentNumber`, `AttachmentRef`).

67. **`AttachmentRef::attachableType` unconstrained free-form string.**

68. **`AttachmentService::buildKey` key entropy 64 bits via `bin2hex(random_bytes(8))` — UUIDv4 (122 bits) is standard.**

69. **`AttachmentRef::checksumSha256` computed but never re-verified on read.**

70. **`AttachmentService` `mime_type` defaults to `application/octet-stream`; no server-side `finfo_buffer` sniff.**

71. **`AttachmentService::delete` soft-deletes the row but destroys the bytes — worst of both worlds.**

72. **`NotificationService.type` free-form string** (should be enum like `NotificationLevel`).

73. **`NotificationService::listFor` no offset/cursor — page 2 unimplementable.**

74. **`NotificationService::markRead` conflates "not yours" with "already read".**

75. **`NotificationService::notify` synchronous DB insert — failure fails the surrounding command.**

76. **`NotificationService::hydrate` uses `NotificationLevel::from` not `tryFrom` — unknown levels 500.**

77. **`SettingsService::decodeValue` swallows `JsonException` and returns raw JSON string.**

78. **`SettingsService::set` upsert not transactional; concurrent first-time writes both INSERT.**

79. **`SettingsService::encodeValue` binary strings throw `JsonException` undocumented.**

80. **`SettingsService` no `getWithFallback` API for tenant→global cascade.**

81. **`SettingsService` per-instance cache; not memoised across DI instances.**

82. **`LocaleResolver::fromAcceptLanguage` ignores `q=0` (RFC 7231 violation).**

83. **`LocaleResolver::fromAcceptLanguage` q-parse only looks at `$tokens[1]`.**

84. **`LocaleResolver` matches full tag → primary subtag only; misses script/region cross-match (`zh-Hant` vs `zh-tw`).**

85. **`LocaleResolver::userPreferredLocale` hard-coded `null` with PHPStan ignore.**

86. **`EmailService::sendTemplate` rendering errors caught and returned as false** — caller can't distinguish "template missing" from "transport failed".

87. **No plain-text alternative emitted from `EmailService`.**

88. **`emails/layout.php` hard-codes `<html lang="en">` and `noreply@localhost` mailto fallback.**

89. **`EmailService::sendPasswordResetEmail` `expiresInMinutes` hard-coded to 60 — drift between auth config and email body silent.**

90. **`projections:rebuild` hardcodes `if ($name === 'cookie')` — every new domain edits this command.**

91. **`CookieReadModelProjection` `truncate()` is DDL → implicit commit on MySQL; bypasses transactions.**

92. **`CookieReadModelProjection::apply()` swallows unknown event classes** (`default => null`) — silent drift on rename.

93. **`onUpdated` ignores event payload and re-fetches — `previousState`/`newState` unused.**

94. **`CookieRestoredEvent.restoredAt` is `string`, not `DateTimeImmutable`.**

95. **`CookieStockChangedEvent.cookieId` is nullable — nonsense events constructible.**

96. **`CookieReadModelProjection::onDeleted` uses `date('Y-m-d H:i:s')` non-deterministic on replay; never updates `version`.**

97. **Events have no `eventId` / `schemaVersion` — replay deduplication impossible.**

98. **`SettingsService` LocaleMiddleware persisting query-param locale couples resolver+middleware.**

99. **`LocaleMiddleware::after` appends `Accept-Language` to `Vary` without dedup.**

100. **`Cookie.php:336-379` getters expose primitives (`description`, `stock`, `isActive`, timestamps as strings).** No `CookieDescription` VO; timestamps not `DateTimeImmutable`.

101. **`CookiePrice::getValue(): float` `@deprecated` in docblock only; no `#[\Deprecated]` attribute.**

102. **`CookiePrice` `toString()` + `__toString()` + `format()` — three near-identical methods.**

103. **`CookiePrice` cross-currency `add/subtract/equals/applyDiscount` throw `InvalidArgumentException` — undocumented.**

104. **`Cookie.php:107-115` `bool $isActive = true` parameter contradicts lifecycle model.**

105. **`Cookie` has no `implements AggregateRootInterface` / `EntityInterface` — generic typehinting impossible.**

106. **`Cookie::getIsActive()` naming inconsistent with PHP convention (`isActive()`).**

107. **Migration test parity: SQLite tests pass but MySQL prod will diverge on collation, `ENUM`, `TINYINT(1)`, `UNIQUE` NULL semantics.**

108. **Repository traits (`RepositoryLogging`, `BusinessMetricsLogging`) read `$this->logger`/`$this->loggingConfig` without interface contract.** Hardcoded business thresholds in reusable traits (stock=10, price-change=10%, popularity=100).

109. **`Cookie::reconstitute()` defaults `$version = 0` — legacy rows load as 0, first save's `WHERE version = 0` matches.**

---

## LOW findings

- `app/Domain/Cookie/Entities/Cookie.php:52` no interface implementations.
- `app/Domain/Cookie/Entities/Cookie.php:361-364` `getIsActive()` naming.
- `app/Domain/Cookie/ValueObjects/CookieName.php:53-72` no `fromTrustedString` factory for rehydration.
- `app/Domain/Cookie/ErrorCodes.php:25-49` per-domain numeric ranges collide cross-domain.
- `app/Domain/Cookie/ErrorCodes.php:43,38,39,42` several codes declared but never used.
- `app/Domain/Cookie/Entities/Cookie.php:223` `decreaseStock` could read as `if ($quantity > $this->stock)` — readability.
- `app/Domain/Shared/ValueObjects/Money.php:170-173` multiply allows zero/negative; inconsistent with `CookiePrice::multiplyBy`.
- `app/Domain/Shared/ValueObjects/Money.php:198-206` `cleanDecimalInput` regex symbol set incomplete.
- `app/Domain/Cookie/Events/CookieCreated/CookieCreatedEvent.php:40-45` no `createdBy`.
- `app/Domain/Cookie/Events/CookieRestored/CookieRestoredEvent.php:14-16` `restoredAt` is string.
- `app/Domain/Cookie/Commands/DeleteCookie/DeleteCookieHandler.php:81-86` INFO-level log for intermediate step doubles volume.
- `app/Domain/Cookie/Commands/CreateCookie/CreateCookieCommand.php:15-17` docblock claims "validated by their handlers" — actually by VOs.
- `app/Domain/Cookie/Queries/GetCookiesPaginated/GetCookiesPaginatedHandler.php:83` search analytics logs bypass operator's `queryLoggingLevel`.
- `*Handler.php` slow queries logged at `info`, not `warning`.
- `app/Infrastructure/Bus/CommandBus.php:96-105` pipeline composition relies on undocumented `array_reduce` semantics; add an order test.
- `app/Infrastructure/Bus/AuditMiddleware.php:53` actor captured BEFORE handler; pre-handler semantic but undocumented.
- `app/Infrastructure/Auth/Services/JwtService.php:106-109` 128-bit token ids OK.
- `app/Infrastructure/Auth/Services/PasswordHashingService.php:13` Argon2 default params (not pinned).
- `app/Infrastructure/Outbox/EventOutboxRelay` `--watch` busy-spins when work is found.
- `app/Infrastructure/Jobs/JobHandlerInterface::handle` no return type / no skip signal.
- `app/Infrastructure/Numbering/DocumentNumberingService::peek` not transactionally meaningful.
- `app/Infrastructure/Numbering/DocumentNumberingService` `padLength=20` silently truncates; no reset/rollover hook.
- `app/Infrastructure/Projections/ProjectionRegistry::register` closure capture; not a leak — minor.
- `app/Infrastructure/Storage/StorageInterface::put` no `create-only` mode; collision silently clobbers.
- `app/Infrastructure/Storage/LocalStorage::exists` swallows `StorageException` returning `false`.
- `app/Infrastructure/Notifications/NotificationService.notify` synchronous insert — no outbox.
- `app/Infrastructure/Settings/SettingsService::existingRowId` double SELECT per write.
- `app/Infrastructure/Settings/SettingsService::forget` silent no-op when no row.
- `app/Infrastructure/I18n/LocaleMiddleware` no session-id regeneration on `?locale=xx`.
- `app/Infrastructure/Http/ApiResponse.php` success envelope nests `correlation_id` under `meta`; problem+json at top level — inconsistent.
- `app/Infrastructure/Http/ApiResponse.php` CLI rendering lazily mints orphan correlation id.
- `app/Infrastructure/Http/Client/CurlHttpTransport.php:42` connect-timeout cap of 10s magic number.
- `app/Infrastructure/Http/Middleware/IdempotencyMiddleware.php` key regex `[A-Za-z0-9._-]+` slightly narrower than RFC draft.
- `app/Infrastructure/Bulk/CsvReader.php:108` BOM stripped on every row's first cell (wasted regex).
- `app/Infrastructure/Bulk/CsvWriter.php:110-112` bool → "1"/"0" opinionated; DateTime not handled.
- `app/Infrastructure/Logging/LoggerFactory.php:36-37` `LOG_FILENAME` constant.
- `app/Infrastructure/Logging/CorrelationIdService.php:43-50` UUID fallback path possibly dead if `ramsey/uuid` is hard dep.
- `app/Infrastructure/Logging/RedactingProcessor.php:31-50` `pass` substring matches `passenger` (false positive).
- `app/Domain/Cookie/Entities/Cookie.php` line counts vs project's own 20-line/200-line limits.
- `app/Views/cookies/index.php` raw `<i class="bi bi-...">` Bootstrap-Icons spans but icons stylesheet not loaded.
- `app/Views/auth/login.php:8` `auth/register.php:8` hardcoded lock/clip emoji.
- `app/Views/partials/_form_field.php` raw `$attributes` HTML pass-through.
- `app/Helpers/auth_ui_helper.php` `current_actor()` instantiates fresh `ActorResolver` on every `can()` call.
- `app/Database/Migrations/2025-10-26-151606_AddNameToUsersTable.php` `down()` is no-op.
- `tests/Support/Factories/CookieFactory.php:89` defaults `id => 1` (PK clash on two-call).
- `tests/Support/Factories/UserFactory.php:198` defaults `id => 999`.
- `tests/Support/UnitTestCase::assertExceptionMessage` catches `\Exception` not `\Throwable`.
- `tests/Support/UnitTestCase::assertArraysMatch` uses `sort()` (scalar arrays only).
- `tests/Support/Factories/CookieFactory::priceFromMixed` silent fallback to `CookiePrice::fromString('')`.
- `app/Config/App.php:147` `supportedLocales = ['en']` but `Services::localeResolver` declares `['en', 'pt-br']`.
- `app/Config/Session.php:43,92,72` reasonable defaults but `matchIP=false` + `regenerateDestroy=false` weaken hijack defence.
- `app/Config/Database.php:31,171` empty `password` defaults.
- `app/Config/App.php:240` HSTS `preload` requires registration.
- `app/Config/ContentSecurityPolicy.php:25,31` `reportOnly=false` + `reportURI=null`: violations silent.
- `app/Domain/Cookie/Entities/Cookie.php:160-163` `bumpVersion()` public with `@internal` only — handler can defeat optimistic locking.
- Several Cookie/User commands use `microtime(true)` vs `hrtime(true)` inconsistently for duration.
- `RestoreCookieHandler` missing start-time/structured-success-log pattern the other three handlers use.

---

## Cookie-as-template scorecard

The user asked specifically about Cookie's suitability as a clone source. Per-area:

### Entity + Value Objects — REJECT
Blocking issues:
- Event raised with `null` id on freshly-created entity (`Cookie.php:236-241,259-264`).
- `update()` mutates five fields wholesale with no event raised (`:195-207`).
- `activate()`/`deactivate()` silent (no event).
- `reconstitute()` runs invariants — corrupted rows unreadable.
- `assignId()`/`bumpVersion()` publicly callable.
- `CookiePrice` mono-currency in multi-currency clothing (`MIN/MAX` USD-cents).
- `CookiePrice` default-USD via `defaultCurrency()`.
- `CookieName::equalsIgnoreCase` uses `strtolower` (locale-broken).
Cloning this multiplies five CRITICAL and four HIGH bugs into every new domain.

### Commands + Handlers — REJECT
Blocking issues:
- Optimistic locking not exercised (no `expectedVersion` on `UpdateCookieCommand`).
- Direct event dispatch from handlers contradicting `AggregateRoot` model.
- Only `RestoreCookieCommand` carries `Actor`; events permanently lose attribution.
- 75-line `handle()` methods violate project's own 20-line rule.
- `determineErrorCode` uses brittle `str_contains` on exception messages.
- `RestoreCookieHandler` diverges from the other three (no try/catch, throws `\RuntimeException`).

### Queries + DTOs — REJECT
Blocking issues:
- Handlers return `Cookie` entities, not `CookieView` DTOs (DTO is dead code).
- Read path does not use `cookie_read_model` (the projection writes; nothing reads).
- No tenant scoping anywhere.
- `LIKE` search has no length cap, no wildcard escaping; pagination has no upper bound.
- `findById` mutates instance state via `trackPopularCookie`.

### Events + Projection — REJECT
Blocking issues:
- `CookieReadModelProjection` never wired in production (`ProjectionRegistry` dead code).
- `CookieRestoredEvent` has no handler subscription anywhere.
- Projection re-fetches from write repo (stale-read race).
- `onStockChanged` UPDATE-only (drops out-of-order events silently).
- `SELECT count → INSERT/UPDATE` race in `upsertFromEntity`.
- `truncate()` rebuild collides with live writes.
- Event payloads asymmetric (Created has no actor, Stock has no actor/timestamp).
- No `eventId`/`schemaVersion`.

### Repository + Model + Provider — REJECT
Blocking issues:
- `tenant_id` schema-only fiction; repo never writes or filters it.
- MySQL composite UNIQUE never fires (NULL distinct).
- `restore()` bypasses version + timestamps + audit.
- `affectedRows()` ≠ `matchedRows()` on MySQL — false-positive `ConcurrentModification`.
- Audit columns (`created_by/updated_by/deleted_by`) never written.
- `isDuplicateKey` substring-matches English MySQL messages.
- Builder state leakage between calls on same instance.
- `dispatchPendingEvents` silently drops events when `eventDispatcher` is null.
- Save+dispatch not transactional.
- Provider registration manually maintained — already missing `CookieRestoredEventHandler` and projection.

### Overall verdict
**Cookie is NOT safe to clone.** Every cloned domain will inherit:
- A tenant column that's never populated (cross-tenant leak on day one of multi-tenancy).
- A composite UNIQUE that doesn't fire in MySQL prod.
- Optimistic locking that throws false positives on idempotent updates AND isn't exercised by handlers.
- Mono-currency monetary VOs in multi-currency wrapping.
- A read-model story that's a no-op.
- Event raising that's inconsistent and partly silent.
- Error codes that collide with every other domain.

The structural pattern (port interface, AggregateRoot trait, separation of model/repo/entity, command/query/event folders) is correct. The implementation is not.

---

## Prioritized remediation plan

### Phase 1 — Deploy-blockers (must complete before any production deploy)

**Security**
1. Add `role:admin` filter to `admin/users/*` in `app/Config/Filters.php` and route group (theme #13).
2. Move blacklist to Redis with TTL derived from `AUTH_REFRESH_TOKEN_TTL`; consult blacklist on inbound refresh; insert into `refresh_tokens` at login; gate legacy admin shim behind env flag with audit logging (theme #12).
3. Make `ActorResolver`/`PermissionService` default-deny for `Actor::system()` on HTTP (theme #12 / item f).
4. Apply CSP fix: vendor Bootstrap or add CDN to `scriptSrc`/`styleSrc`; remove inline `style="..."` from layout + sidebar (theme #16).
5. Env-source `App::$baseURL`; default `Database::$encrypt = true`; force HSTS only when registered (theme #16).
6. Restrict `CurlHttpTransport` to `CURLPROTO_HTTP|CURLPROTO_HTTPS` (HIGH #13).
7. Lock `EmailService::sendTemplate` to an allow-list of templates (HIGH #12).
8. Fix `AuditMiddleware` cascading rollback: reset `transStatus` after caught audit insert failure OR move audit out of transaction (CRITICAL #14).
9. Push middleware on `commandBus()` shared path (CRITICAL #15).
10. Fix `CorrelationIdService` worker leak: call `clear()` in `CorrelationIdMiddleware::after` and at job/event boundaries (CRITICAL #17).

**Correctness**
11. Add `expectedVersion` to `UpdateCookieCommand` and pass through to repo; route `restore()` through `updateWithOptimisticLock`; replace `affectedRows()` with `MYSQLI_CLIENT_FOUND_ROWS` or post-update SELECT (CRITICAL #4).
12. Fix `DocumentNumberingService` gapless guarantee with `INSERT ... ON DUPLICATE KEY UPDATE ... LAST_INSERT_ID(current_value)` or explicit `lockForUpdate()` (CRITICAL #10).
13. Fix `EventOutboxRelay::claim()` to gate on `affectedRows() === 1` only (CRITICAL #11).
14. Fix `IdempotencyMiddleware`: write pending row in `before()` and update in `after()`; store all response headers; guard `actorId()` for unauthenticated (HIGH #14).

### Phase 2 — Correctness before any new domain is cloned

**Tenancy**
15. Introduce `TenantContext` service; inject into every repository, projection, notification service, attachment service, settings service; scope every read/write/exists; make `tenant_id` `NOT NULL` once resolver lands; remove the misleading "within the tenant" message at `CookieRepository.php:108` (theme #1).
16. Decide MySQL UNIQUE strategy for soft-deleted rows: sentinel `'9999-12-31'` or PostgreSQL partial unique or application-layer + DB partial (CRITICAL #5).
17. Add `UNIQUE(email, deleted_at)`, `tenant_id`, `version`, `created_by/updated_by/deleted_by` to `users` migration (HIGH #20).
18. Translate duplicate-key by SQLSTATE / `getCode()`, not message substring (CRITICAL #5).

**Cookie aggregate**
19. Move lifecycle event raising into the entity (`create`, `update`, `softDelete`, `restore`, `activate`, `deactivate`); add `assertInvariants()`; delete `eventDispatcher->dispatch()` from handlers; rely on repository drain (CRITICAL #3, HIGH #1).
20. Defer event raising until after `assignId()` or stamp id at drain; make `CookieStockChangedEvent.cookieId` non-nullable (CRITICAL #6).
21. Make `reconstitute()` invariant-tolerant (HIGH #1).
22. Tighten `assignId()`/`bumpVersion()`/`raiseEvent` visibility via a marker interface or package-private trait (HIGH #1).

**Read side**
23. Wire `CookieReadModelProjection` via `DomainServiceProviderInterface::registerProjections(ProjectionRegistry)`; subscribe `CookieRestoredEventHandler`; switch query handlers to return `CookieView` from a `CookieReadModelRepository`; drive projection writes from event payloads; replace `SELECT count → INSERT/UPDATE` with `INSERT ... ON DUPLICATE KEY UPDATE`; shadow-table-and-swap for rebuilds (CRITICAL #2).
24. Persist `created_by/updated_by/deleted_by` in repository `performSave`/`delete`/`restore` (HIGH #7).

**Shared foundations**
25. Make `Currency` required in `Money` constructors/factories; remove `defaultCurrency()` (CRITICAL #7).
26. Implement `JsonSerializable` + `fromArray()` on all shared VOs (CRITICAL #7).
27. Lock `DateTimeValue` to UTC; fix `equals()` to compare timestamps; check `getLastErrors()` on parse (CRITICAL #9).
28. Make `DocumentNumber` / `AttachmentRef` constructors private with named factories (CRITICAL #8).
29. Validate `Actor::system($label)` against charset/length whitelist (HIGH #4).
30. Per-currency bounds in `CookiePrice` or in a typed `Money` policy; required `Currency` (HIGH #2).
31. Promote `DomainEventInterface`, `DomainExceptionInterface`, `InfrastructureException`; constrain `AggregateRoot::raiseEvent` (theme #8).
32. Establish typed/string error-code registry (theme #7).
33. Add missing factory methods: `ValidationException::tooLarge/custom/notInSet`; `DomainException::alreadyExists/softDeleted/precondition`.

**Bus + middleware**
34. Decide event-on-transaction semantics: either rethrow in `EventDispatcher` or remove `TransactionMiddleware` promise (CRITICAL #3).
35. Extract `SensitiveKeys::LIST` shared by `AuditMiddleware` + `RedactingProcessor`; iterate all command properties; proper VO/enum/Stringable normaliser (HIGH #10).
36. Add a pipeline-order test pinning `Logging → Transaction → Audit → handler` order.
37. Inject the same `BaseConnection` into both transaction-aware middlewares from `Services.php`.

**Outbox + Jobs**
38. Wire `EventOutboxWriter` into the persistence path or remove until needed (CRITICAL #11).
39. Add `events:reap` and `jobs:reap` commands for stuck `in_flight`/`reserved` rows; add `pcntl_signal(SIGTERM, ...)` to `--watch` loops; restore `correlation_id` after each row/job; replace reflection rehydrate with `DomainEventInterface::toArray()/fromArray()` (HIGH #9).
40. Fix spark commands: return `int`, fix `--watch` boolean parsing.

**User domain parity**
41. Move `UserRepositoryInterface` to `app/Domain/User/Ports/`; rewire handlers to depend on interface; remove `RateLimitResult` import from `RateLimitInterface` (theme #6, HIGH #18).
42. Add `Actor` to `UpdateUserCommand`; enforce role-change authorization in handler.
43. Add `RestoreUserCommand`/handler.
44. Dedupe `User/ErrorCodes` constants; throw `DomainException` everywhere instead of `\RuntimeException`; `UserName` throw `ValidationException`.
45. Fix register-timing oracle (uniform dummy hash path).
46. Fix password trim symmetry; `mb_strlen` in `PasswordComplexity`.

### Phase 3 — Hygiene + ergonomics

47. Refactor `cookies/index.php` and `admin/users/index.php` to use `partials/_pagination` + a new `partials/_list_table`; add `can()` gating to all action buttons; finish `lang()` adoption (HIGH #22).
48. Rename `2025_10_27_*` migrations to dash delimiters; add `notifications(user_id, read_at, created_at)` composite index; widen `users.failed_login_attempts` to `UNSIGNED`; add FOREIGN KEY constraints to `permissions_schema` join tables (HIGH #20).
49. Generalise `FeatureTestCase`/`IntegrationTestCase`; add `RoleFactory`, `PermissionFactory`, `NotificationFactory`, `AttachmentFactory`, `SettingFactory`; add MySQL CI job to catch SQLite divergence (HIGH #21).
50. Drop CI4 model `validationRules` (duplicate of VOs); pull domain-specific thresholds out of reusable traits.
51. Split 75-line `handle()` methods into ≤ 20-line privates.
52. Add `equals()` to `Actor`, `DocumentNumber`, `AttachmentRef`.
53. Add length caps to `Email`, `Permission`; `mb_strtolower` everywhere; reject control chars / RTL overrides in name VOs.
54. Document `StateMachine` memoisation; add construction-time transition-table validator; return validated target string from `transition()`.
55. Add per-locale `<html lang="...">` and configured From-fallback in `emails/layout.php`; emit text alternative.
56. `LocaleResolver` honour `q=0`; scan all tokens; add script/region fallback.
57. `NotificationService`: enum the `type`; add cursor pagination; tenant-scope `listFor/markRead/markAllRead`; `tryFrom` for `NotificationLevel::hydrate`.
58. `SettingsService::set`: UNIQUE index + `ON DUPLICATE KEY UPDATE`; surface `JsonException` properly.
59. `AttachmentService`: validate `attachableType` against registry; tenant-scope `read/delete/list`; wrap storage + insert in a transactional pattern; UUIDv4 keys; finfo-buffer mime sniff.
60. `OutboundHttpClient`: honour `Retry-After`; add jitter; opt-out flag for non-idempotent POST retries.
61. `CsvWriter`: actually stream `toString`; emit UTF-8 BOM option.
62. `RedactingProcessor`: scan `$record->message`; add JWT value-pattern; expand sensitive list.
63. `DomainLogger`: parameterise channel by domain; deprecate or document coexistence with PSR-3 injection.
64. Wire `ProjectionRegistry` from boot; switch `RebuildProjections` to look projections up from the registry; add registration-completeness test asserting every event has at least one subscriber.
65. Decide partial-update policy (PATCH command vs nullable fields) and document.
66. Audit and prune `ErrorCodes`; partition numeric ranges per domain (theme #7).
67. Remove the misleading "within the tenant" wording at `CookieRepository.php:108` until tenancy is wired (or as part of Phase 2 #15).
68. Consolidate `shell.php` vs `layout.php` alias confusion; load `bootstrap-icons.css` or remove `<i>` tags.

---

## Notable disagreements / cross-report tension

- Report 04 says `CookieReadModelProjection` "subscribes to all 5 events including `CookieRestoredEvent`" via its `apply()` method, while report 05 confirms `CookieRestoredEvent` has no handler subscription anywhere in `CookieServiceProvider.php:168-196`. Both are correct: the projection's `apply()` includes a Restored case, but the projection itself is never registered against the dispatcher, AND `CookieRestoredEvent` has no per-event-handler class either. Net result: the Restored case is dead code on both paths.
- Report 02 flags the `existsByName` pre-check + DB unique catch as redundant; report 05 separately notes the DB unique catch is *dead code* in MySQL because the composite UNIQUE never fires under NULL semantics. Both are true; the second compounds the first.
- Report 07 catalogues `Cookie/ErrorCodes.php` orphan constants; report 01 separately catalogues the same. Both note the same underlying issue from different angles.
- Report 10 (Bus) and report 04 (Events) both flag `EventDispatcher` swallowing `\Throwable`, but from opposite ends — Bus says it makes `TransactionMiddleware` docs a lie; Events says it makes the relay's retry logic unreachable. Same root cause, two operational consequences.
- Report 13 says `DomainLogger` is "STILL ACTIVELY USED by User domain"; report 08 separately flags VOs importing `DomainLogger` as a Domain→Infrastructure dependency violation. Both are correct: the user-domain VOs use it; that use is also an architectural violation.
- Report 04 reports the projection re-fetches from write repo; report 03 reports that the read handlers never read the projection at all. These are independent — the projection writes are stale-prone *and* nobody reads them.

---

End of consolidated audit. Read individual reports for additional context not surfaced here (especially: shape of `CookieView`, ProvidersRegistry recursive-init guard, `LocaleResolver` token parsing details, and full per-VO accessor inventory).
