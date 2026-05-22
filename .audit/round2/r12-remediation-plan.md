# R12 — Remediation plan executability review

Source: `.audit/round1-consolidated.md` (lines 662–756) cross-checked against the CRITICAL/HIGH inventories (lines 59–305) and the Cookie-as-template scorecard (lines 591–658).

Scope: is the three-phase remediation plan actually executable as ordered? Are dependencies honoured? Are blockers really blockers? Does Phase 2 actually unblock cloning?

---

## Items that are correctly placed

The following items sit in the right phase with no dependency issues and the effort estimate implied by the bullet matches reality:

- **Phase 1 #1** (`role:admin` filter on `admin/users/*`) — tiny edit, true deploy blocker.
- **Phase 1 #4–5** (CSP, baseURL, encrypt, HSTS) — config-only, blocks production.
- **Phase 1 #6** (`CURLPROTO_HTTP|HTTPS`) — one-line, true blocker.
- **Phase 1 #7** (EmailService template allow-list) — small, true blocker (LFI surface).
- **Phase 1 #8** (`AuditMiddleware` cascade) — surgical fix in one file.
- **Phase 1 #9** (shared CommandBus middleware) — `Services.php` only; true blocker because shared path is the production path.
- **Phase 1 #12** (`DocumentNumberingService` gapless fix) — one method, true correctness blocker.
- **Phase 1 #13** (`EventOutboxRelay::claim` affectedRows gate) — one expression, true blocker.
- **Phase 2 #15** (`TenantContext`) — correctly gated to before-cloning.
- **Phase 2 #19–22** (Cookie aggregate consolidation) — correctly ordered before #23 (read side wiring depends on lifecycle events being raised consistently).
- **Phase 2 #25–28** (shared VO invariants) — must precede any clone of Cookie that uses `Money`/`DateTimeValue`.
- **Phase 2 #31–33** (event/exception interfaces + factories) — correctly placed before #38–39 (outbox writer wiring depends on `DomainEventInterface::toArray/fromArray`).
- **Phase 3 #47–48, 52–56, 60–63** — true hygiene/ergonomics.

---

## Items in the wrong phase

### Phase 3 items that should be Phase 1 (deploy-blockers)

- **Phase 3 #67** ("Remove misleading 'within the tenant' wording at `CookieRepository.php:108`") — listed as Phase 3 hygiene but cited again as part of Phase 2 #15. Until tenancy is actually wired, the message is a *security-misleading log artefact* (gives ops the false sense that tenancy is enforced). Should be Phase 1 (one-line edit, removes a false-comfort signal) or folded into #15 unambiguously.
- **Phase 3 #64** ("Wire ProjectionRegistry from boot; add registration-completeness test") — the test ("assert every event has at least one subscriber") is the *only* mechanism that would have caught the `CookieRestoredEvent` orphan in the first place. This test must land in Phase 2 alongside #23, not Phase 3, otherwise the read-side fix is regression-prone from the moment it merges.

### Phase 2 items that should be Phase 1

- **Phase 2 #18** ("Translate duplicate-key by SQLSTATE / `getCode()`, not message substring") — this is paired with CRITICAL #5 in the findings, and the audit explicitly calls the substring match "dead code in MySQL prod." Until #18 lands, the application **cannot reliably reject duplicate names in production MySQL**. Either treat the entire UNIQUE story as a deploy-blocker (move #16+#18 to Phase 1) or accept that the deploy goes out with no duplicate-name protection at all. The plan currently obscures this.
- **Phase 2 #34** ("Decide event-on-transaction semantics: rethrow in EventDispatcher or remove TransactionMiddleware promise") — the *docs lie* about transactional rollback (`TransactionMiddleware.php:21-24`). This is a CRITICAL #3 sub-issue. Either the docs are corrected (trivial) or behaviour is changed (non-trivial). The decision is Phase 1 even if the implementation slips; otherwise the team continues to design against a false guarantee.

### Phase 1 items that could legitimately be deferred

- **Phase 1 #14** (`IdempotencyMiddleware` window + replay) — flagged as HIGH #14, not CRITICAL. The risk is "double-execution under transient failure for idempotency-keyed requests." If no production caller currently sends `Idempotency-Key`, this is not a deploy blocker. Candidate for Phase 2.
- **Phase 1 #10** (`CorrelationIdService` worker leak) — only manifests in Swoole/Roadrunner/queue-worker deployments. If the production target is PHP-FPM (one process per request), this is *latent*. The audit calls it CRITICAL #17 because the project ships background workers via spark commands (`--watch` loops, `EventOutboxRelay`, `JobWorker`). Confirm that production runs those workers; if it does, Phase 1 is correct. If not, Phase 2.

---

## Dependency violations

1. **#19 (move event raising into entity) depends on #31 (`DomainEventInterface`).** Without the interface, `AggregateRoot::raiseEvent(object)` still accepts anything; consolidating events into the entity is doable but the resulting code can't be type-pinned. Reorder: do #31 before #19, OR accept that #19 lands with `object` typehints and is tightened later.

2. **#23 (wire projection, switch query handlers to `CookieReadModelRepository`) depends on #19 (lifecycle events into entity) AND on #20 (non-nullable `cookieId`).** Currently listed in that order under "Read side" but the bullet phrasing makes #23 sound atomic when it is in fact the largest single item in Phase 2. Should be split:
   - 23a: wire `ProjectionRegistry`, subscribe `CookieRestoredEventHandler`.
   - 23b: introduce `CookieReadModelRepository` + switch query handlers to return `CookieView`.
   - 23c: drive projection writes from event payloads (depends on 19/20).
   - 23d: `INSERT ... ON DUPLICATE KEY UPDATE` + shadow-table rebuild.
   These have different blast radii and 23c is blocked on 19+20.

3. **#15 (TenantContext) depends on #18 (SQLSTATE translation) for sensible duplicate-name behaviour** — once `tenant_id` is populated, the composite UNIQUE finally fires, but the substring-based duplicate detection still only matches English. Order: #18 before #15 within Phase 2, or merge them.

4. **#39 (replace reflection rehydrate with `DomainEventInterface::toArray/fromArray`) depends on #31.** Phase 2 lists #31 in "Shared foundations" and #39 in "Outbox + Jobs". The plan groups them in the same phase but the implicit ordering inside the phase is not stated. Make it explicit.

5. **#41 (move `UserRepositoryInterface` to `Domain/User/Ports`) is a precondition for #43 (`RestoreUserCommand`) and several Phase 3 items.** Currently both in Phase 2; ordering inside Phase 2 should put #41 first.

6. **#23 (switch query handlers to `CookieReadModelRepository`) depends on the projection being populated for *existing rows* before the cutover.** This is a hidden migration step: between deploying the projection writer and switching query handlers, you need a backfill. Currently invisible in the plan. See "Missing remediations" below.

---

## Hidden effort items (much larger than the bullet suggests)

1. **#15 — TenantContext.** Bullet reads like one service class. Reality: every repository method (`save`, `delete`, `findById`, `findAll`, `findPaginated`, `existsByName`, `restore`, `updateWithOptimisticLock`), every query handler, every projection, `NotificationService`, `AttachmentService`, `SettingsService`, plus DB migrations to make `tenant_id` `NOT NULL`, plus a `TenantResolver` middleware, plus tests for every cross-tenant denial. Easily 30+ files touched and the change is irreversible (you cannot land tenancy halfway). True effort: a full sprint, not a bullet.

2. **#19 — move lifecycle event raising into the entity.** Bullet reads like one entity refactor. Reality: every command handler (`CreateCookieHandler`, `UpdateCookieHandler`, `DeleteCookieHandler`, `RestoreCookieHandler`) loses its direct dispatch path; `CookieRepository::dispatchPendingEvents` becomes the single source of truth; the `EventDispatcher` swallow-`Throwable` decision (#34) must be settled first because consolidating dispatch *amplifies* the consequences of swallowed listener exceptions. Add: the 75-line handler methods (MEDIUM #9, Phase 3 #51) become easier to split *after* this — there's a natural sequencing benefit if #51 is pulled into Phase 2.

3. **#23 — wire projection + switch query handlers.** Beyond what's listed: needs a backfill migration (existing `cookies` rows must be projected into `cookie_read_model` before the query cutover); needs the new `CookieReadModelRepository` interface in `app/Domain/Cookie/Ports/`; needs `CookieView::fromRow(array)` factory (MEDIUM #7 currently in unspecified phase); needs read-side feature tests parallel to existing query tests. Effort: comparable to #15.

4. **#31 — promote `DomainEventInterface` / `DomainExceptionInterface` / `InfrastructureException`.** Sounds like three new files. Reality: every existing event class needs `implements DomainEventInterface`; every `DomainException`/`ValidationException` callsite gets re-typed if you want a common base; `AggregateRoot::raiseEvent` signature changes ripple through all callers (entity tests included). PHPStan Level 8 with strict types means the cascade is real, not cosmetic.

5. **#32 — typed error-code registry.** Bullet says "establish". The audit shows constants collide across `Cookie/ErrorCodes` and `User/ErrorCodes` (101 means different things; some constants self-alias). Cleaning this up means renumbering, finding every `ErrorCodes::XXX` reference, and updating logs/clients that grep on numeric codes. Non-trivial.

6. **#41 — move `UserRepositoryInterface` to `Domain/User/Ports/` and rewire handlers.** Sounds like a namespace move. Reality: every handler (`RegisterUserHandler`, `GetUserByIdHandler`, `GetUserByEmailHandler`, `UpdateUserHandler`, `ChangeUserPasswordHandler`, `DeleteUserHandler`, `RestoreUserHandler` once #43 lands) must depend on the interface, not the concrete. Plus the `RateLimitInterface` → `Infrastructure` import (theme #6) is a separate port move with its own follow-on. Two ports, six+ handlers, the Services factory.

7. **#23 + #19 together** are the only path that makes the read side honest. They cannot ship independently; if either lands alone, the system gets worse than the current state (events flowing into a projection nobody reads, or projection wired but events asymmetric).

---

## Items addressing risk of introducing regressions

1. **#19 (events into entity) + #34 (rethrow vs swallow decision).** If `EventDispatcher::dispatch` starts rethrowing as part of #34, every existing handler-side direct dispatch suddenly propagates listener exceptions through the command bus. Even after #19 consolidates dispatch into the repository, a single misbehaving listener (e.g., the new projection) can roll back every write. Needs a circuit-breaker or per-listener error isolation policy before #34 lands, or the rollback semantics must be opt-in per listener.

2. **#15 (`tenant_id` NOT NULL once resolver lands).** The migration that flips NULL to NOT NULL will fail on any row written before the resolver was deployed. Needs a backfill step (set `tenant_id` to a default tenant for legacy rows) before the NOT NULL migration. Not stated.

3. **#16 (UNIQUE strategy for soft-deleted rows).** Switching from `UNIQUE(tenant_id, name, deleted_at)` to a sentinel `'9999-12-31'` or partial unique requires re-creating the index online; on MySQL with a large `cookies` table that's a lock + downtime. Plan doesn't note operational risk.

4. **#23d (`INSERT ... ON DUPLICATE KEY UPDATE` + shadow-table rebuild).** The shadow-table-and-swap is the right call, but `RENAME TABLE` is atomic only within a transaction-less DDL window; if anything during the swap fails, the read model can end up pointing at the empty shadow. Needs a documented rollback procedure.

5. **#25 (`Currency` required everywhere).** Removing `defaultCurrency()` will break every existing `Money` construction site that omits currency, including factories and tests. The migration path needs a deprecation cycle or a static analyser pass to enumerate callers first.

6. **#39 (replace reflection rehydrate).** Any event row already sitting in `event_outbox` was serialised under the old reflection scheme. After the switch, those rows will fail to rehydrate. Needs a one-shot migration or an `event_version` discriminator so both schemes coexist during cutover.

---

## Missing remediations

CRITICAL/HIGH findings without a corresponding remediation step:

1. **`SecurityEventService::logEvent` synchronous DB writes (MEDIUM #44).** Not addressed. Listed as MEDIUM, but cited as "DoS amplifier." Should at least be acknowledged in Phase 3.

2. **`AuditMiddleware` digest leaks (HIGH #10) covers sensitive-key list extraction (Phase 2 #35).** But the "VOs serialised as `{}`" sub-issue requires a normaliser change that the bullet covers — phrasing is OK, just verify both fixes ship together.

3. **`RegisterUserHandler` dummy-hash timing oracle (HIGH #18 sub-bullet) — Phase 2 #45 lists "uniform dummy hash path" but doesn't mention that `LoginUserHandler` has the symmetric issue.** Login-side timing parity is not in the plan.

4. **`JwtAuthenticationMiddleware` device fingerprint unsalted (HIGH #16).** Not in the plan. CRITICAL/HIGH security finding with no remediation step.

5. **`SessionAuthMiddleware` lacks fingerprint/idle/concurrent-cap (HIGH #16).** Asymmetric to JWT tier. Not addressed in the plan.

6. **`RateLimitService` token-bucket non-atomic (HIGH #16).** Not addressed.

7. **`Filters.php:95` CSRF disabled when `ENVIRONMENT === 'testing'` (CRITICAL #13 sub-bullet).** Phase 1 #1 covers `role:admin` but not the testing-environment CSRF bypass. Should be Phase 1.

8. **`AggregateRoot` is a trait not abstract class; `pullEvents()` clears buffer even on rollback (MEDIUM #19).** This interacts with #19/#34 dispatch consolidation. If pullEvents fires inside the transaction but the transaction rolls back, events are lost. Not in the plan.

9. **Backfill / cutover for read model (implicit dependency of #23).** Existing `cookies` rows must be projected before query handlers cut over. Not stated.

10. **`tenant_id` backfill for legacy rows (implicit dependency of #15).** Not stated.

11. **MySQL CI job (Phase 3 #49 mentions it as part of test infrastructure).** This should arguably be Phase 1: SQLite-only tests are masking the UNIQUE-NULL bug *right now* (#5). Without a MySQL CI job, the fix for #5 cannot be regression-tested.

12. **No remediation step for `Cookie::reconstitute()` defaulting `$version = 0` (MEDIUM #4 + #109).** Legacy rows reconstitute with version 0; first save matches `WHERE version = 0` regardless of concurrent edits. Phase 2 #21 makes reconstitute invariant-tolerant but doesn't fix the version-default issue.

13. **`CommandBus` shared-instance middleware (#9) doesn't mention asserting non-empty middleware list as a startup invariant.** The audit explicitly recommends "assert middleware list is non-empty after construction" — the bullet only covers pushing middleware.

---

## Over-remediations

I found no remediation step that addresses something never flagged as a finding. Every Phase 1–3 item maps to an item in CRITICAL/HIGH/MEDIUM or to a cross-cutting theme. **No over-remediation detected.**

The closest thing to scope creep is Phase 3 #50 ("Drop CI4 model `validationRules`") — this is in HIGH #7 sub-bullet ("CI4 model `validationRules` duplicate Value Object validation") but the bullet calls it a duplication, not a defect. Removing it is correct hygiene, not a blocker — Phase 3 placement is fine.

---

## Proposed phase re-ordering

### New Phase 1 (deploy-blockers)

Keep current items 1–13 with these adjustments:
- **Add**: `Filters.php:95` CSRF testing-environment bypass (currently missing).
- **Add**: doc fix for `TransactionMiddleware` (the rethrow-vs-swallow *decision* even if the code change slips) — i.e. land item #34 as a doc-only change immediately, defer the behavioural change to Phase 2.
- **Add**: MySQL CI job (currently Phase 3 #49) — without it, #5 cannot be verified.
- **Add**: assert non-empty middleware list in shared `commandBus()` factory (sub-bullet of #9).
- **Defer to Phase 2**: #14 (`IdempotencyMiddleware`) if no production caller uses `Idempotency-Key`.
- **Confirm or defer**: #10 (`CorrelationIdService`) depending on whether workers run in production.

### New Phase 2 (correctness before cloning)

Reorder within phase to honour dependencies:
1. #31 (`DomainEventInterface`/`DomainExceptionInterface`) — first, blocks #19, #39.
2. #18 (SQLSTATE translation) — first within tenancy block.
3. #16 (UNIQUE strategy) — with online-rebuild plan documented.
4. #15 (TenantContext) — with backfill migration documented.
5. #34 (rethrow-vs-swallow *behaviour* change) — with per-listener isolation policy.
6. #19 → #20 → #21 → #22 (Cookie aggregate, in that order).
7. Split #23 into 23a–23d as above; include a backfill step before 23b cutover.
8. #25–#28 (shared VO fixes); #25 needs a deprecation-cycle plan.
9. #41 → #43 → #42 → #44–#46 (User domain; port move first).
10. #39 with event-row migration plan; #38 (`EventOutboxWriter` wiring).

Pull into Phase 2 from Phase 3: #51 (split 75-line handlers — natural dependency on #19), #64 (registration-completeness test — needed alongside #23).

### Phase 3 unchanged except as above

Items 47–68 minus the three pulled into Phase 2 (#51, #64) and minus #49 (pulled to Phase 1 as MySQL CI job).

---

## Verdict

**The plan is structurally sound but not yet executable as written.**

Strengths:
- Phase boundaries correspond to real risk gates (deploy / clone / hygiene).
- The Cookie-as-template scorecard maps to Phase 2 items 1:1; completing Phase 2 (with the additions below) *does* unblock cloning.
- No over-remediation; no fabricated work.

Weaknesses:
- Multiple Phase 2 items have **hidden effort** (tenancy, read-side cutover, error-code registry) that the bullet phrasing under-sells by an order of magnitude.
- **Intra-phase ordering is implicit**: several Phase 2 items depend on each other (#31 before #19, #18 before #15, #19/#20 before #23c) and the plan doesn't say so.
- **Missing backfill/cutover steps** for tenancy (#15) and read model (#23) — both would break on first deploy as currently scoped.
- **Three CRITICAL/HIGH findings have no remediation step**: JWT fingerprint salt (HIGH #16), session-tier asymmetry (HIGH #16), `RateLimitService` atomicity (HIGH #16). Likely an oversight in the auth section.
- **One Phase 3 item belongs in Phase 1** (CSRF testing-env bypass; folded into the security #13 finding); **two Phase 3 items belong in Phase 2** (registration-completeness test #64, handler split #51).
- **Phase 1 #14 is plausibly Phase 2** depending on Idempotency-Key usage in prod; **Phase 1 #10 is plausibly Phase 2** depending on worker deployment model.

Does Phase 2 actually unblock cloning per the scorecard? **Yes**, conditional on:
- The backfill/cutover steps being added to #15 and #23.
- The missing items (JWT fingerprint, session parity, rate-limit atomicity, version-default reconstitute) being added.
- Intra-phase ordering being made explicit so #31 lands before #19, etc.

Without those additions, a team executing Phase 2 will produce a half-tenanted, half-projected system that's worse than the current state.

**Recommendation**: revise Phase 2 to split #23 into 23a–23d, hoist #31 and #18 to the top of Phase 2, document backfills, and add the four missing findings. Then re-issue the plan.
