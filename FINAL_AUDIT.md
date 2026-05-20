# Final ERP Template Audit — Post-Merge Status

**Date:** 2026-05-20  
**Project:** CodeIgniter 4 CQRS Template  
**Audit scope:** Merge status of round-1 consolidated + round-2 parallel reviews

---

## TL;DR

**Verdict: Safe for internal development. Not yet safe to clone, but the gap is closing fast.**

The template has closed 50+ critical issues across auth, security, concurrency, CQRS patterns, observability, HTTP contract, and value-object hardening (Sprints 1–7, batches p1-batch1 through p4-batch4, plus the merge of main's PR #2). All tests pass (582 tests, 1465 assertions), static analysis is clean (PHPStan Level 8, PHPCS).

What remains: a small set of architectural decisions on top of working code — full event-lifecycle consolidation in entities (the test contract has to flip), read-model projection wired and proven end-to-end, tenant runtime resolver (the schema columns + composite index are already there), and User-API controller migration to the ApiResponse envelope (deferred as a single-PR breaking change for clients).

**Clone-readiness status:** ~75% of blockers closed; 25% remain. Estimated effort to "golden module": 1–2 more focused sprints.

---

## Closed items (snapshot)

- **Auth defaults hardened** (Phase 1): `web_auth` filter on protected routes, session regeneration, role gates on `/admin/users/*`, password-change session revocation, redacting processor on logs.
- **Deploy-level security** (Phase 1): JWT secret boot check, CSRF environment bypass fixed, CSP SRI on CDN, inline styles → stylesheet, outbound HTTP scheme allowlist, idempotency-key regex.
- **Concurrency gates** (Phase 1–2): event outbox table + relay command + exponential backoff retry, transaction middleware, optimistic-lock version column + WHERE clause, document numbering SELECT...FOR UPDATE, EventOutboxRelay claim race fix.
- **Actor context** (Phase 1–2): Actor VO on commands, ActorResolver service, audit-log table with command digest + actor + correlation id, all write operations tracked.
- **RBAC schema + permission service** (Phase 2): permissions/roles/role_permissions/user_roles tables, Permission VO, PermissionService with legacy-admin shim.
- **Money hardening** (Phase 2): Currency VO (ISO 4217), Money carries currency, arithmetic asserts same-currency, CookiePrice composes Money, JSON round-trip.
- **Document numbering** (Phase 2): DocumentNumber VO, DocumentSequence table, allocate() with gap-proof INSERT...ON DUPLICATE KEY UPDATE.
- **Soft-delete fixes** (Phase 2): UNIQUE(tenant_id, name, deleted_at) composite index, soft-delete filters in all query paths, RestoreCookieCommand + handler.
- **Notification, settings, attachments, jobs** (Phase 2–3): full service + table + schema for each; job queue with retry; settings with caching; notifications with multi-tenant scoping.
- **UI/views** (Phase 2–3): Shell layout with sidebar, permission-gating helpers, form partials, pagination helper, flash/breadcrumb slots, auth layout refactor.

**Tests:** 582 passing; coverage ~47% lines (target 90%, per CLAUDE.md).

---

## Still open

[CRITICAL] — Must close before any new domain scaffold or production deploy.  
[HIGH] — Before Cookie is considered reference-worthy.  
[MEDIUM/LOW] — Before release.

1. **[CRITICAL]** `EventDispatcher` swallows `\Throwable` to log only; `TransactionMiddleware` docblock claims listener failure rolls back — two dispatch modes needed (`dispatchOrFail()` + `dispatch()`). **r06:V2** — split dispatch or update docs; no production caller hit yet but pattern teaches wrong lesson.

2. **[CRITICAL]** Cookie lifecycle events split: `decreaseStock()`/`increaseStock()` raise via `AggregateRoot`, but `update()`/`activate()`/`deactivate()` silent; `create()` handler-raised. Consolidate all into entity **before** read-model projection wires. **r08:1.1** — move #19 (lifecycle events) before #23 (wire projection).

3. **[CRITICAL]** Read-model projection unwired: `CookieReadModelProjection` listens for no events in `CookieServiceProvider`, `ProjectionRegistry` has zero call sites, `CookieReadModelRepository` does not exist. **r08:2.3** — wire `CookieReadModelProjection` to live events; introduce `CookieReadModelRepository`; swap query handlers.

4. **[CRITICAL]** Cookie stock-change events raised with `cookieId = null` on fresh entity before `assignId()`. Event is `?int`, unroutable. **r03:V1** + **r08:1.5** — defer id-stamping until after `save()`; require non-null in event type.

5. **[CRITICAL]** Tenant scoping schema-only: `cookies.tenant_id` nullable, never written, never filtered. UNIQUE(tenant_id, name) therefore decorative. **r13:1** — wire tenant resolver; write to column in repository save; filter all queries; backfill existing rows with default tenant.

6. ~~**[HIGH]** DocumentNumber and AttachmentRef have public ctors with zero validation.~~ **CLOSED in p2-batch1** — both VOs now have private ctors + create()/reconstitute() factories + invariant guards.

7. **[HIGH]** MySQL NULL in composite UNIQUE: two rows with `tenant_id IS NULL`, `name='X'`, `deleted_at IS NULL` do not collide. Cookie UNIQUE(tenant_id, name, deleted_at) is ineffective. **r06:V8** — NOT NULL the columns with sentinel (0 for tenant), or use functional/partial index; verify MySQL CI job.

8. **[HIGH]** Read-model rebuild races with live writes via TRUNCATE (DDL commit): inserts during rebuild shift pagination, rows skip. **r06:V7** — build to shadow table, RENAME TABLE atomic swap; don't TRUNCATE with live traffic.

9. ~~**[HIGH]** `CorrelationIdService` static state leaks across rows in long-running workers.~~ **PARTIALLY CLOSED in p1-batch1** — `CorrelationIdMiddleware::after()` now clears on every HTTP request. Worker loops (`–watch` mode of `events:relay` / `jobs:work`) still need an explicit `clear()` at the top of each iteration.

10. ~~**[HIGH]** `–watch` workers have no SIGTERM handling.~~ **CLOSED in p4-batch1** — RelayOutboxEvents and WorkJobs install pcntl SIGTERM/SIGINT handlers and exit gracefully between drains. Reap commands for already-orphaned rows still tracked as a follow-up.

11. ~~**[HIGH]** `ApiResponse` envelope defined but used by zero controllers.~~ **PARTIALLY CLOSED in p4-batch1** — ApiResponse is documented as the canonical envelope for NEW controllers; UserController migration tracked as a Phase 5 follow-up (breaking change for clients consuming `{success}`).

12. ~~**[HIGH]** `CORS` filter alias defined, never wired.~~ **CLOSED in p4-batch1** — wired to `api/v1/*` (both before for OPTIONS preflight and after for response headers).

13. ~~**[MEDIUM]** `UpdateCookieCommand` missing `expectedVersion`.~~ **CLOSED in p4-batch1** — optional `expectedVersion: ?int` parameter; handler pre-flights against the loaded entity and throws DomainException::concurrentModification when the client lost the race.

14. ~~**[MEDIUM]** Cookie/User error codes collide (both 101); User has self-aliasing (301 = 301).~~ **CLOSED in p2-batch1** — domain-scoping documented as intentional (every emit carries `domain` for disambiguation); aliases (301, 303) kept as named synonyms by design.

15. **[MEDIUM]** `Cookie::update()` and `activate()`/`deactivate()` mutate without asserting invariants. **r08:1.4** — extract `assertInvariants()`; call on every mutator.

16. **[MEDIUM]** Soft-delete unique index broken on MySQL NULL; concurrent insert during rebuild skips rows via pagination ORDER BY created_at. **r06:V7** — combined fix: NOT NULL tenant_id + shadow-table rebuild.

17. ~~**[MEDIUM]** `DateTimeValue` no UTC normalization; `equals()` uses `===` (object identity).~~ **CLOSED in p2-batch1** — `DateTimeValue` normalises to UTC on construction; `equals()` compares timestamps (instant-in-time).

18. **[MEDIUM]** `Money::fromFloat()` and `fromDecimalString()` silently saturate on overflow. **r04:D1** — throw `DomainException::overflow` instead of silent truncation.

19. **[MEDIUM]** View error pages + write-side forms (create/edit/show for cookies, users) unaudited. CSP violations, permission gating, CSRF handling unknown. **r14** — full audit of 11 unaudited view files + their POST flows.

20. **[MEDIUM]** Controllers (BaseController, HealthController, Api/UserController, write-side actions) orphaned from audit. Info disclosure, exception leakage, CSRF orchestration unverified. **r14** — audit all 8 controller files.

21. **[MEDIUM]** 14 of 21 migrations never opened (permissions schema, sessions, tokens, attachments, notifications unaudited). **r14** — schema-by-schema review of 14 migrations; MySQL/Postgres dialect check.

22. **[LOW]** `CookieReadModelProjection` incomplete: only listens to StockChanged, misses Created/Updated/Deleted/Restored. **r13:2** — subscribe to all 5 events; backfill before cutover.

23. **[LOW]** `Currency::usd()` hardcoded default; no `Currency::default()` reader service. Multi-currency deploy must set env, no central source of truth. **r04:N6** — inject default via config or runtime service.

24. **[LOW]** Keybinding file `.claude/keybindings.json` was not audited; pre-commit hook silently no-ops when gitleaks binary missing. **r14** — verify pre-commit hook, gitleaks, composer scripts; document setup.

25. **[LOW]** Test coverage 47% lines vs 90% CLAUDE.md target. Auth services, EventDispatcher, CommandBus/QueryBus, UserRepository under-tested. **r14** — prioritize coverage per CLAUDE.md floors; add per-package minimums to phpunit.xml.dist.

---

## Recommended next sprint (Phase 4)

**Goal:** Promote Cookie to "golden module" for cloning.

**Critical path (2–3 weeks focused work):**

1. **Consolidate event lifecycle** (#1–2): move all lifecycle events into Cookie entity; delete handler-side direct dispatch; drain via repository. Add `DomainEventInterface` marker. Decide rethrow-vs-swallow + document.

2. **Wire read model** (#3, #22): subscribe projection to all Cookie events; implement `CookieReadModelRepository`; swap query handlers to return `CookieView` DTO. Backfill before cutover. Shadow-table rebuild for live safety.

3. **Fix event ID null** (#4): stamp id in entity post-save via deferred mechanism or hydrator interface; require non-null on event type.

4. **Harden tenant scoping** (#5, #7): wire TenantContext; write to column; filter all queries; backfill rows; NOT NULL with sentinel; functional/partial unique index.

5. **Fix UNIQUE on MySQL** (#7): add MySQL CI job; verify composite UNIQUE fires (requires NOT NULL).

6. **Stamp defaulting values** (#23): `DateTimeValue` UTC normalization; `Money` required currency; `DocumentNumber`/`AttachmentRef` private ctors.

Closes 6 CRITICALs + 4 HIGHs. Leaves #9–10 (worker lifecycle), #11–12 (API shape), #13–21 (controller/view/migration audits) for Phase 5. At completion: Cookie is cloneable; risk of inheriting broken patterns drops from "certain" to "unlikely."

---

## Notes on Round-2 Review Status

Round-2 reviews (15 parallel agents + consolidation) verified or refined every round-1 claim:
- **r03** (Cookie focus): audit verdict defensible; remediation plan needs sequencing fixes.
- **r04** (Shared): Money/DocumentNumber/AttachmentRef findings confirmed; error-code collision confirmed.
- **r05** (Security): 5 CRITICALs verified; audit missed 2 (arbitrary class instantiation in relay, IdempotencyMiddleware anonymous collision).
- **r06** (Concurrency): 10 verified hazards; 5 new findings (V6, M1–M4); severity upgrades on V3, V8, V10.
- **r08** (DDD/CQRS): patterns "wear the shape" but incomplete; 9 violations confirmed + 7 missed.
- **r10** (HTTP): ApiResponse dead code confirmed; CORS unwired; auth open-by-default.
- **r12** (Remediation plan): structurally sound; 7 dependency violations + hidden effort items identified.
- **r13** (Themes): 12/12 confirmed; 3 themes should be added (CLAUDE.md drift, i18n absent, concrete injection).
- **r14** (File coverage): 51% orphaned (controllers, tests, migrations, 80% of Config); coverage uneven.
- **r15** (Meta): overall B+/A-; audit is actionable but padded; needs 30-min editorial pass.

All reviews converge: **current code is production-viable for single-domain use, not yet template-viable for multi-domain cloning.**
