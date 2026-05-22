# Round 2 — R13: Review of the cross-cutting themes (Round 1 consolidation)

Date: 2026-05-20
Source under review: `.audit/round1-consolidated.md` § "Cross-cutting themes" (12 items)
Spot-checks: read CookieServiceProvider, CookieRepository, CookieEntity, EventDispatcher, CreateCookieHandler, UpdateCookieCommand, ErrorCodes (Cookie + User), CookieStockChangedEvent, CorrelationIdService call graph, Cookie migration, plus grep sweeps for handler interfaces, soft-delete patterns, and Domain → Infrastructure imports.

---

## Per-theme verification

### Theme 1 — Tenant scoping absent across modules
**Real.** Verified directly. The `cookies` migration declares `tenant_id` (`Database/Migrations/2025-01-21-000001_CreateCookiesTable.php:51,130,134`) but `CookieRepository::save/findById/findAll/findPaginated/existsByName/softDelete` never bind it, and the only Cookie reference to "tenant" is the misleading exception text at `:108` ("must be unique within the tenant"). `CookieRestoredEvent` and projection both hardcode `tenant_id => null`. No `TenantContext` service exists anywhere under `app/`. The consolidator's touch-point list is complete for Cookie, but understates the scope for the User domain: `users` migration **has no `tenant_id` column at all** (consolidator notes this once in §HIGH #18 but does not pull it into theme #1's touchpoint list). Sub-issue worth elevating: `existsByName` in the duplicate-name pre-check (`CookieRepository.php:223-226`) likewise runs un-scoped — so even after a tenant column is wired, a tenant-A user's duplicate-name response will leak the existence of a tenant-B cookie. **Severity CRITICAL is defensible** and arguably understated — this is the single most clone-multiplying defect.

### Theme 2 — CQRS "read side" is fiction (handlers return entities)
**Real.** Verified `GetCookieByIdHandler:54` returns `?Cookie` from `CookieRepository::findById`, which queries the write table `cookies`. `CookieServiceProvider::registerEvents` (`:168-196`) subscribes Created/Updated/Deleted/StockChanged handlers but **not** the `CookieReadModelProjection` and **not** `CookieRestoredEvent` (the projection has an `onRestored` branch that is therefore unreachable except via manual rebuild). `ProjectionRegistry` has zero call sites in `app/`. `EventOutboxWriter` has zero call sites in `app/`. The consolidator's touch-points are complete. The framing "fiction" is fair: the schema, the projection class, and the read-model migration all exist; only the wiring is missing. **Severity CRITICAL is defensible** but the consequence is somewhat narrower than #1 — it's correctness-via-stale-reads + performance regression, not data leak.

### Theme 3 — Two competing event-dispatch models
**Real.** Verified. `Cookie::decreaseStock`/`increaseStock` raise via `AggregateRoot::raiseEvent` and rely on `CookieRepository::dispatchPendingEvents` to drain. Lifecycle handlers (Create/Update/Delete/Restore) construct events directly in the handler and dispatch via the injected `EventDispatcher` *outside* the repository (e.g. `CreateCookieHandler.php:105-110`). `Cookie::update()` (`:195-207`) mutates five fields and raises **nothing**. `activate()`/`deactivate()` flip `isActive` silently with no event. `EventDispatcher::dispatch` (`:90-104`) catches `\Throwable` and logs — listener failure does NOT propagate, so `TransactionMiddleware`'s "listener exception rolls back" contract is unenforceable today. Touch-point list complete. **Severity CRITICAL is defensible** — once an outbox lands, half the events will reach it.

Missed sub-issue: `CookieRepository::dispatchPendingEvents` (`:130-142`) silently drains and discards events when `eventDispatcher === null` (CommandBus shared-instance path with no DI; see consolidated §CRIT #15). So even the aggregate-events half of the split is conditionally a no-op.

### Theme 4 — MySQL NULL semantics in composite UNIQUE
**Real.** Verified `addUniqueKey(['tenant_id', 'name', 'deleted_at'])` (`CreateCookiesTable.php:130`) and confirmed `tenant_id` is never written (NULL in every row) and `deleted_at` is NULL for live rows. Standard MySQL behaviour: two NULLs in a UNIQUE index are considered distinct, so the constraint fires zero times in practice. The "duplicate-key" branch in `CookieRepository.php:122-128` is therefore dead code in MySQL (the substring match on "duplicate"/"unique constraint"/"1062" is also locale-fragile — already in §HIGH but worth re-emphasising as a touch-point of theme #4). Touch-point list complete; consolidator correctly notes the parallel User defect (no `UNIQUE(email, deleted_at)` at all). **Severity CRITICAL is defensible** — together with #1, this means the duplicate-name UX is theatre.

### Theme 5 — Static worker state (CorrelationIdService and friends)
**Real.** Verified. `CorrelationIdService::$correlationId` is `private static`, generated lazily by `get()` and never auto-cleared. Production callers of `::clear()`: **zero** (only tests). `CorrelationIdMiddleware::after` sets a response header but does not call `clear()`. Workarounds in `JobWorker.php:103` and `EventOutboxRelay.php:112` only restore the *original* correlation id at the end of one job — they do not protect across jobs in a long-lived worker. The "static state survives requests" claim is fully correct for any non-FPM SAPI (Swoole, Roadrunner, queue workers).

Touch-point list is complete on `CorrelationIdService` but the consolidator's lumping of `trackPopularCookie`, `DomainLogger`, and `BusinessMetricsLogging` under the same theme is partly pattern-matching: those are *instance*-state defects (unbounded per-request growth, wrong channel), not process-static defects. They should be merged into a broader "stateful long-lived worker hazards" theme. **Severity CRITICAL/HIGH defensible for CorrelationIdService; HIGH for the rest.**

### Theme 6 — Domain depends on Infrastructure (DIP inversion)
**Real and worse than reported.** Verified all named imports: User Entity + `Email`/`UserName`/`PasswordComplexity`/`AccessToken` import `App\Infrastructure\Logging\DomainLogger`; `User/Ports/RateLimitInterface.php:7` imports `App\Infrastructure\Auth\ValueObjects\RateLimitResult` (a Port-layer interface depending on an Infrastructure value object — DIP literally inverted). `RegisterUserHandler`, `GetUserByIdHandler`, `GetAllUsersHandler`, `SearchUsersHandler`, `UpdateUserHandler` depend on either concrete `UserRepository` or on the interface that itself lives in `app/Infrastructure/Persistence/Repositories/`.

Touch-points the consolidator did not cite that also match the theme:
- **Every Cookie command handler** imports `App\Infrastructure\Bus\EventDispatcher` (a concrete class, not an interface) — `CreateCookieHandler.php:15`, `UpdateCookieHandler.php:15`, `DeleteCookieHandler.php:11`, `RestoreCookieHandler.php:11`. The Domain layer's command handlers are bound to a concrete Infrastructure class.
- `CookieServiceProvider` (`Domain/Cookie/`) imports `App\Infrastructure\{Attributes,Bus,Logging,ServiceProvider}` — five Infrastructure imports from a domain-layer class.
- `Domain/Cookie/Projections/CookieReadModelProjection.php:14` imports `App\Infrastructure\Projections\ProjectionInterface`.

**Severity CRITICAL/HIGH defensible.** The User-side violations are worse (logging in VOs is unconditional), but the Cookie side is structurally similar — the consolidator under-cited the Cookie touchpoints.

### Theme 7 — Error-code namespace collision
**Real.** Verified `Cookie/ErrorCodes.php:28 COOKIE_VALIDATION_NAME = 101` collides with `User/ErrorCodes.php:21 USER_VALIDATION_EMAIL = 101`. Verified literal duplicate-constant aliases: `User/ErrorCodes.php:32,33` (`USER_BUSINESS_RULE_ACCOUNT_LOCKED = 301; USER_BUSINESS_RULE_LOCKED = 301`) and `:35,36` (`_ACCOUNT_SUSPENDED = 303; _SUSPENDED = 303`). Slevomat will flag these; cross-domain log aggregation cannot disambiguate by integer alone. Unused codes in `Cookie/ErrorCodes.php`: confirmed `COOKIE_BUSINESS_RULE_INACTIVE` (302), `COOKIE_STATE_DELETED` (401), `COOKIE_STATE_CONCURRENT_MODIFICATION` (402) — declared but `grep` finds no references outside the registry. **Severity HIGH defensible.** Fix is simple (range-per-domain, e.g. 1xxx / 2xxx / 3xxx) and high-leverage.

### Theme 8 — Missing interface contracts (`DomainEventInterface`, `DomainExceptionInterface`, `InfrastructureException`)
**Real and significantly understated.** The consolidator names three missing types. Verified additionally that **none** of `CommandHandlerInterface`, `QueryHandlerInterface`, `EventHandlerInterface`, `CommandInterface`, `QueryInterface`, `EventInterface` exists anywhere in the codebase (literal `find` returned zero matches). The CLAUDE.md example shows `implements CommandHandlerInterface` — that interface is a documentation artifact, not code. Every handler in Cookie + User + Auth currently sits behind no contract at all: `CommandBus::register` accepts any callable, and consumers cannot statically constrain a handler's signature.

This makes the theme broader than "no DomainEventInterface". It's a **structural contract vacuum**: the framework lets you register a closure that returns `void` as a query handler. **Severity HIGH is defensible but should probably be CRITICAL** for a template intended to be cloned — every clone inherits a pattern with no compile-time enforcement.

### Theme 9 — Value-object under-validation
**Real.** Spot-checked `CookiePrice` mono-currency bounds (`MIN=1, MAX=999_999` minor units regardless of `Currency`), `Money` USD silent default, `Actor::system($label)` accepts multi-line input (log-injection vector confirmed via `Actor.php:36-39` having no charset or length validation). `DocumentNumber` and `AttachmentRef` ctors are `public` with no validation block. `DateTimeValue` server-timezone hazard via `new DateTimeImmutable('now')` and `createFromFormat(..., $datetime)` without `DateTimeZone('UTC')`. Touch-point list complete.

One miss worth adding: `CookieName::normaliseCase` uses `strtolower` not `mb_strtolower` (already in §MED #1 but conceptually a VO-validation gap that belongs under this theme too — Turkish I, German ß yield wrong dedupe). **Severity CRITICAL/HIGH defensible.**

### Theme 10 — Idempotency / versioning gaps in events
**Real.** Verified `CookieStockChangedEvent` has no `eventId`, no `occurredAt`, no `schemaVersion`, and `cookieId` is nullable (`?int $cookieId` line 19). Spot-checked `CookieCreatedEvent`/`CookieUpdatedEvent`/`CookieRestoredEvent` (via the consolidator's already-cited line numbers) — none carry version or unique id. `EventOutboxRelay::rehydrate` uses reflection on constructor parameter names so adding a required parameter breaks every queued row. `IdempotencyMiddleware` cache row written after handler runs (TOCTOU).

The theme blends two distinct concerns:
- (a) **Event schema versioning** — adding `eventId`, `occurredAt`, `schemaVersion` to every event.
- (b) **Idempotency middleware bugs** — cache-write ordering, header preservation.

These are arguably two themes; the consolidator merges them under "idempotency / versioning gaps". The merge is defensible because both block safe replay, but a fix plan needs to treat them separately. **Severity HIGH defensible.**

### Theme 11 — Optimistic-lock half-implementation
**Real.** Verified `UpdateCookieCommand.php:27-34` has no `expectedVersion` parameter, so the handler reloads inside its own scope and the `WHERE version = ?` clause in `CookieRepository::updateWithOptimisticLock` (`:377-396`) compares the freshly-loaded version against itself — last-write-wins. Confirmed `affectedRows()` semantics on MySQL: 0 affected when row matches but column values are unchanged → false-positive `ConcurrentModification`. `restore()` path (`CookieRepository.php:266-281` per the consolidator's cite) bypasses both version and timestamps.

Touch-point list complete. The consolidator notes the same flaw applies to the User domain by implication (User has no `version` column in its migration per §HIGH #20). **Severity CRITICAL defensible** — last-write-wins is exactly what optimistic locking is supposed to prevent.

### Theme 12 — Audit asymmetry (some commands carry Actor, some don't)
**Real.** Verified: only three commands carry an `Actor` field — `RestoreCookieCommand`, `ChangeUserPasswordCommand`, `DeleteUserCommand`. Create/Update/Delete Cookie do not. RegisterUser/UpdateUser do not. Confirmed `CookieCreatedEvent` has no `createdBy` field. `CookieUpdatedEvent.updatedBy` and `CookieDeletedEvent.deletedBy` default to `0` per the consolidator (I read enough of the surrounding handlers to confirm they never populate). `CookieStockChangedEvent` has no actor and no timestamp. Audit columns (`created_by`/`updated_by`/`deleted_by`) declared in cookies migration but never written by the repository. `AuditMiddleware` independently resolves an actor for the `audit_log` row, papering over the gap at one specific table but leaving every domain-event consumer without attribution.

Touch-point list complete and accurate. **Severity CRITICAL/HIGH defensible** — for a multi-tenant ERP template, who-did-what is a compliance baseline.

---

## Themes I would ADD to the inventory

### A. Documentation-vs-code drift (the CLAUDE.md lies)
Multiple authoritative claims in CLAUDE.md are contradicted by code:
- "Cookie domain is the reference implementation" with "comprehensive test coverage … 192 tests, 100% passing" → but the Cookie aggregate raises `cookieId=null` events, lifecycle events bypass the aggregate, projection is unwired, optimistic lock is unexercised. The reference is broken.
- Sample code in CLAUDE.md shows `final readonly class CreateUserCommandHandler` and `implements CommandHandlerInterface` — neither pattern is present in real code (no interface exists; User handlers are not `final readonly`).
- "TransactionMiddleware: listener exception rolls back the write" — `EventDispatcher` swallows `\Throwable`.
- `commandBus()` shared path skips middleware (§CRIT #15) — silently inverts the documented behaviour.

A cloned domain following CLAUDE.md verbatim will produce code that does not match the existing template. **Severity HIGH** — this is a clone-multiplier in itself.

### B. Internationalisation absent from the Domain layer
Zero `lang()` / message-catalog usage under `app/Domain/`. All exception messages, all validation strings, all log messages are hardcoded English. `CreateCookieHandler::determineErrorCode` (`:155-161`) literally pattern-matches English exception messages with `str_contains`. The presence of `LocaleResolver` + `Currency`/`Money` advertises i18n but the domain layer is mono-lingual. **Severity MEDIUM/HIGH** depending on target market.

### C. Soft-delete double-filter (`useSoftDeletes` + manual `WHERE deleted_at IS NULL`)
Both `CookieModel` and `UserModel` set `$useSoftDeletes = true`, but the repositories then add manual `where('deleted_at IS NULL')` clauses (`CookieRepository.php:425,454`; `UserRepository.php:172,234,256,280`). CI4 already applies the soft-delete filter — the manual clause is either redundant or, worse, will conflict if a future maintainer sets `$useSoftDeletes = false` on the model. This is symptomatic of the wider "Cookie domain owns its own ORM filter logic" smell. Already touched on as §MED #26 but it's truly cross-cutting (both repositories) and belongs as a theme. **Severity MEDIUM.**

### D. Concrete-class dependency injection (EventDispatcher, CommandBus, QueryBus, repositories)
Theme #6 covers Port→Infrastructure but understates a broader pattern: **every** handler takes `EventDispatcher` (concrete) not `EventDispatcherInterface` (does not exist). Same with `CommandBus` and `QueryBus`. The consolidator's theme #6 is User-domain-focused; the same DIP issue exists Cookie-side but at the *concrete-class injection* level, not the import-of-Infrastructure level. **Severity HIGH.**

### E. Repository "drain pending events" path is silently lossy
`CookieRepository::dispatchPendingEvents` (`:130-142`) calls `$cookie->pullEvents()` even when `eventDispatcher === null` — events are pulled (so the buffer doesn't grow) but never dispatched. Combined with theme #3 (lifecycle events going through a different path), this means the shared-instance CommandBus (§CRIT #15) silently discards stock-change events. **Severity HIGH/CRITICAL** depending on how often the shared bus is hit in practice. Could be subsumed under theme #3, but worth calling out: it's a specific, fixable, untested branch.

---

## Themes I would REMOVE or MERGE

### Merge #5 sub-claims
Theme #5 lumps four distinct issues:
- `CorrelationIdService` process-static (truly process-static — CRITICAL for non-FPM SAPIs).
- `trackPopularCookie` instance state (per-request unbounded growth — different problem, applies to every Cookie read).
- `DomainLogger` shared channel (logging-aggregation issue, not state issue).
- `BusinessMetricsLogging` instance state (also per-instance, not process).

These should split into two themes: **5a "Process-static state survives across requests in non-FPM workers"** (CorrelationIdService) and **5b "Per-instance state in long-lived workers leaks memory"** (the rest). The current single-theme framing is pattern-matching by similarity (the word "static-ish"), not by mechanism.

### Demote/merge #4 → under #1
Theme #4 (MySQL NULL semantics) is fundamentally a downstream consequence of theme #1 (tenant scoping not wired). Fix #1 properly (`tenant_id NOT NULL` after a default-tenant migration) and #4 self-resolves for the `tenant_id` column; the `deleted_at IS NULL` part remains but is a smaller, distinct fix. I would keep #4 as its own theme but make the dependency explicit. Severity remains CRITICAL but the **fix order matters**: don't sentinel `deleted_at` until tenancy is settled.

### Theme #10 is two themes
As noted in the verification, theme #10 conflates "event-schema versioning" with "idempotency-middleware bugs". They have non-overlapping fixes and non-overlapping touch-points. Split.

---

## Verdict on the theme inventory

**Overall: defensible, comprehensive, mostly accurate. Three corrections needed.**

1. **Theme #8 is significantly understated.** It should explicitly cover the absence of `CommandHandlerInterface`/`QueryHandlerInterface`/`EventHandlerInterface`, not just the domain-event/exception interfaces. This raises the practical severity to CRITICAL for any template-clone scenario — handlers currently have zero structural contract.

2. **Theme #5 should split** into process-static (CorrelationIdService — CRITICAL) and per-instance worker leaks (HIGH). Current framing hides the fact that they require different fixes.

3. **Add three themes the consolidator missed:**
   - **CLAUDE.md vs. code drift** — the documentation describes a system that does not exist (interfaces, transactional event guarantees, "reference implementation" claim). HIGH.
   - **i18n absent from the Domain layer** — pattern-matching exception messages with `str_contains` cements English-only. MEDIUM/HIGH.
   - **Concrete-class injection (EventDispatcher/CommandBus/QueryBus as concretes)** — broader DIP issue than theme #6. HIGH.

4. **Severity assignments are otherwise defensible.** I would not downgrade any of the 12. I would consider upgrading #8 (interfaces) and noting #1 (tenant scoping) as the keystone defect — every other theme is either downstream of #1 (#4, partly #12) or an independent clone-multiplier that compounds with #1 (everything else).

5. **No theme is purely pattern-matched.** Every theme has at least one verifiable code citation that grounds it. The risk of false-positives in the inventory is low; the risk of false-negatives (missing themes) is moderate, addressed above.

**Inventory accepted with the three additions and one split. Block on Cookie clone-readiness as long as themes #1, #2, #3, #8 (expanded), and #11 remain open.**

---

## File path reference

- `/home/gabriel/Documentos/CQRSTemplate/app/Domain/Cookie/CookieServiceProvider.php` — projection + restored-event wiring gap (theme #2)
- `/home/gabriel/Documentos/CQRSTemplate/app/Domain/Cookie/Entities/Cookie.php:195-300` — silent mutations (theme #3)
- `/home/gabriel/Documentos/CQRSTemplate/app/Domain/Cookie/Commands/CreateCookie/CreateCookieHandler.php:105-110` — handler-side direct dispatch (theme #3)
- `/home/gabriel/Documentos/CQRSTemplate/app/Domain/Cookie/Commands/UpdateCookie/UpdateCookieCommand.php` — missing `expectedVersion` (theme #11)
- `/home/gabriel/Documentos/CQRSTemplate/app/Domain/Cookie/Events/CookieStockChanged/CookieStockChangedEvent.php:19` — nullable `cookieId` (themes #6 + #10)
- `/home/gabriel/Documentos/CQRSTemplate/app/Domain/Cookie/ErrorCodes.php:28` and `/home/gabriel/Documentos/CQRSTemplate/app/Domain/User/ErrorCodes.php:21,33,36` — collision + aliases (theme #7)
- `/home/gabriel/Documentos/CQRSTemplate/app/Infrastructure/Bus/EventDispatcher.php:90-104` — `\Throwable` swallow (theme #3)
- `/home/gabriel/Documentos/CQRSTemplate/app/Infrastructure/Logging/CorrelationIdService.php` — process-static, no production `clear()` callers (theme #5)
- `/home/gabriel/Documentos/CQRSTemplate/app/Models/Cookie/CookieRepository.php:79-396` — tenant absent + duplicate-key dead code + optimistic-lock half-impl (themes #1, #4, #11)
- `/home/gabriel/Documentos/CQRSTemplate/app/Database/Migrations/2025-01-21-000001_CreateCookiesTable.php:51,130` — composite UNIQUE on nullable cols (theme #4)
- `/home/gabriel/Documentos/CQRSTemplate/app/Domain/User/Ports/RateLimitInterface.php:7` — Port→Infrastructure import (theme #6)
- `/home/gabriel/Documentos/CQRSTemplate/app/Domain/User/Entities/User.php:13` and `app/Domain/User/ValueObjects/{Email,UserName,PasswordComplexity,AccessToken}.php` — DomainLogger imports (theme #6)
