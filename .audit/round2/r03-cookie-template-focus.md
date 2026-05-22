# r03 — Cookie-as-template focus review

Date: 2026-05-20
Reviewer scope: validate the consolidated audit's Cookie-as-template assessment against the five source reports and the actual source code.

---

## TL;DR

The consolidator's headline verdict ("Cookie is NOT safe to clone") is **defensible and well supported**. The five clone-multiplying defects it elevates are real, accurately located, and each is independently visible in the Cookie source files. The per-area scorecard's blanket REJECT is fair on the evidence — every area has at least one CRITICAL that propagates per clone.

However, the consolidator **drops or under-weights several Cookie-specific defects** that the source reports caught and that materially affect cloning. The remediation plan, while structurally correct, has two omissions that mean "follow the plan" still leaves a cloner with broken templates.

---

## 1. Verified clone-multiplying defects

The consolidator highlights five "biggest clone-multiplying defects" in the Cookie-as-template scorecard section (line 12 of the consolidated, plus the per-area scorecard at lines 591-658). Verifying each against source:

### V1. Event-id null on freshly-created entity — CONFIRMED
`Cookie.php:56` declares `private ?int $id = null;`. `Cookie.php:236-241` and `:259-264` raise `CookieStockChangedEvent(cookieId: $this->id, ...)` where `$this->id` is the same nullable. `CookieStockChangedEvent.php:19` types it `?int`, so the nullability is locked in at the contract. A handler that calls `Cookie::create()` then `decreaseStock()` before `repository->save()` produces an event with `cookieId === null`. Source report 01 catches this; consolidator preserves it under CRITICAL #6.

**Extra evidence the consolidator didn't surface:** `Cookie::create()` cannot raise a `CookieCreatedEvent` itself for the same reason — there is no id yet at construction. This forced the project to create lifecycle events in handlers (CRITICAL #3), which is the *root cause* of the split-dispatch model. The two CRITICALs are not independent — they share a single design flaw: the entity has no post-persist hook. The consolidator treats them as separate items; they should be linked in the remediation order.

### V2. Lifecycle events bypass AggregateRoot — CONFIRMED
`Cookie.php:195-207` `update()` mutates five fields and raises **zero** events (verified line-by-line in source). `Cookie.php:288-300` `activate()`/`deactivate()` flip `$isActive` silently. Report 01 #2/#7 and report 02 (CRITICAL "Handlers dispatch domain events outside the persistence boundary") both flag this. Consolidator preserves it under CRITICAL #3 and HIGH #1.

### V3. Mono-currency bounds on multi-currency VO — CONFIRMED
Report 01 #10/#11/#12 are correctly elevated. `CookiePrice::MIN_MINOR_UNITS = 1`, `MAX_MINOR_UNITS = 999_999` are USD-cents semantics applied regardless of currency; `defaultCurrency()` returns `Currency::usd()`. Consolidator preserves under CRITICAL #7 and HIGH #2.

### V4. Composite UNIQUE never fires under MySQL NULL semantics — CONFIRMED
Report 05 C1/C2/H3 documents this with file:line precision. Consolidator preserves under CRITICAL #5. The "USD silent default" + "broken composite UNIQUE" combination on a single domain is genuinely catastrophic for cloning.

### V5. Read-side is fiction (projection never wired) — CONFIRMED
`CookieServiceProvider.php:168-196` (verified): registers four event handlers (Created/Updated/Deleted/StockChanged) and **no projection**, **no CookieRestoredEvent handler**. The `Events/CookieRestored/` directory contains only `CookieRestoredEvent.php` — no handler file exists. Report 04 CRITICAL #1/#2 and report 05 H5 both flag this. Consolidator preserves under CRITICAL #2.

**All five claimed clone-multipliers are real and accurately located.**

---

## 2. Defects DROPPED from sources that should be in the consolidated audit

The consolidator condenses ~150 source findings into the consolidated report. Several Cookie-specific items are dropped or compressed in ways that hurt the cloning thesis.

### D1. Cookie ports vs User ports asymmetry not surfaced in the Cookie scorecard
Report 05 mentions in passing that `CookieRepositoryInterface` lives correctly at `app/Domain/Cookie/Ports/` whereas `UserRepositoryInterface` lives at `app/Infrastructure/Persistence/Repositories/`. The consolidator surfaces this only in HIGH #18 (User domain parity), not in the Cookie scorecard. But the cloning question is "if I copy Cookie, do I get the right structure?" — and **Cookie is structurally correct** on this dimension. The scorecard's blanket "REJECT" obscures that Cookie is the *positive* reference for port placement. Worth a callout: "Cookie is the correct shape; User isn't; clone *from Cookie*, not from User."

### D2. `Cookie` has no `implements` clause
Report 01 #24 and consolidated MEDIUM #105 note it, but in the Cookie scorecard the implication is hidden. Without `AggregateRootInterface` / `EntityInterface`, no shared base type can be enforced at the type level. Every cloned domain inherits the same trait-only pattern. This is a *template-level structural* defect, not a Cookie-only one — it belongs in the scorecard's Entity bullet, not buried as a LOW.

### D3. `Cookie::reconstitute()` defaults `$version = 0`
Report 01 #16 + report 05 mention this in different sections. The consolidator surfaces it as MEDIUM #4. But it interacts with CRITICAL #4 (optimistic locking): a legacy row loaded as `version = 0` and an in-flight write with `version = 0` will collide silently. The consolidator does not call out the *interaction* between these two findings. Cloned domains will inherit both, and the interaction will not be obvious.

### D4. `bumpVersion()`/`assignId()` enforcement gap is acknowledged but the deeper issue ducked
Source 01 #5/#6 + source 05 raise this. Consolidator surfaces as HIGH #1 ("tighten visibility"). The deeper issue: there is no language-level mechanism in PHP to enforce "package-private". The only options are (a) reflection-based runtime check (slow), (b) `@internal` + PHPStan rule (already failing — these are `public`), or (c) an `EntityHydrator` interface that the repo implements and the entity accepts as a key. The consolidator says "tighten visibility via package-private trait" but PHP traits don't enable that. **The remediation step as written is not actionable.** A cloner cannot follow it.

### D5. Cookie/User error-code collision is in the consolidator but not in Cookie scorecard
Theme #7 / HIGH #5 cover collision generally. The Cookie scorecard lists "Error codes that collide with every other domain" in the overall verdict — but does not connect it to a specific Cookie fix. A cloner reading the Cookie section gets no actionable handle on what to change in `Cookie/ErrorCodes.php`. The fix needs to land in shared infrastructure (a global registry), not in Cookie.

### D6. `Cookie::isAvailable()` packing three concerns
Source 01 #14 — surfaced as MEDIUM #3. Acceptable to relegate, but combined with "no whyUnavailable()" this is the canonical case where cloned domains will reimplement the predicate externally (in controllers, in views) and drift. Worth a sentence in the scorecard's Entity bullet.

### D7. `BusinessMetricsLogging` hardcoded thresholds in a reusable trait
Source 05 M5 — stock=10, price-change=10%, popularity=100. Consolidator surfaces as MEDIUM #108. But the Cookie scorecard lists `findById` mutation as a defect without naming the *root cause*: the trait was designed for reuse but is not actually reusable because it bakes in Cookie business thresholds. A cloner who uses the trait inherits Cookie's "low-stock = 10" threshold for `Order` / `Customer` etc. **This is a structural template defect** the consolidator buries.

### D8. `Cookie::reconstitute()` cannot rehydrate corrupted rows
Source 01 #4 — CRITICAL in source. Consolidator demotes to MEDIUM #6 and HIGH #1 sub-bullet. This is a recovery footgun (the system cannot read its own broken data to repair it). For an ERP being cloned 30+ times, every cloned domain loses operability the moment a single bad row exists. The MEDIUM tier under-states the operational impact.

---

## 3. New Cookie defects discovered during this review

Visible in source but not in any of the five source reports.

### N1. `Cookie::create(bool $isActive = true)` allows non-default at construction; no parity in `reconstitute()`
`Cookie.php:107-113` accepts `$isActive` parameter. `Cookie.php:132-143` `reconstitute()` requires it (no default). The *factory* accepts a default but the *rehydrator* does not. This is inverted: factory should be opinionated (always active on create), rehydrator should be permissive (read whatever was stored). Source 01 #13 flags the factory parameter but misses that the asymmetry with `reconstitute()` makes the lifecycle even more confused.

### N2. `decreaseStock`/`increaseStock` raise events with `reason: 'decreaseStock'` / `'increaseStock'` — stringly typed
`Cookie.php:240, 263`. The `reason` is a free-form string with values that mirror the method name. This will be propagated to every cloned domain (`Order::decreaseQuantity`, etc.). No `StockChangeReason` enum exists. Cross-domain analytics on "why stock moved" cannot use a typed column. Not surfaced in any source report.

### N3. `Cookie` violates its own class-line limit
The project's CLAUDE.md mandates "Max 200 lines per class". `Cookie.php` is 380 lines. The class houses 22 methods, half of which are trivial getters. The scorecard does not call this out; source 02 only flags 75-line `handle()` methods. The cloning impact: every domain entity will be ~400 lines and consistently breach the project's own rule.

### N4. `Cookie::raiseEvent` is `protected`, but the trait that provides it accepts plain `object`
Verified: `AggregateRoot.php:55 protected function raiseEvent(object $event): void`. `Cookie.php:236` raises `CookieStockChangedEvent` (which has no marker interface; it's a plain class with `public readonly ?int $cookieId` etc.). Anything passes type-check. Source 01 #23 catches the trait issue; the Cookie scorecard does not connect it to the entity's `raiseEvent` call sites and so a cloner reading "raise events from the entity" has no compile-time guidance on what to raise.

### N5. `CookieServiceProvider.php:170` uses static `LoggerFactory::create('cookie.events')` instead of injected logger
Verified in source. Source 05 M2 flags this for testability. The deeper cloning issue: `LoggerFactory` is in `Infrastructure/Logging` — the provider has a hard dependency on the Infrastructure layer when it could receive a `LoggerInterface` from the registry. Every cloned provider will inherit this static call. Consolidator surfaces in HIGH #6 but not in the Cookie scorecard's "Repository + Model + Provider" bullet.

### N6. `Cookie::reconstitute()` accepts `int $version = 0` (default value) but the parameter is logically required
`Cookie.php:142`. A repo that forgets to pass `version` produces a silently zeroed lock token (see D3). The default is hostile to safety; making it required forces every cloned repo to think about it.

### N7. Public mutating methods on the aggregate (`update`, `activate`, `deactivate`, `decreaseStock`, `increaseStock`, `bumpVersion`, `assignId`) are all `void`-returning with no return-type-annotated guard for "deleted" state
`Cookie.php` has `isDeleted()` (line 329) but only `decreaseStock`/`increaseStock` are mentioned by source 01 #8. In source, `update()` (line 195), `activate()` (288), `deactivate()` (297) also do not guard. Consolidator preserves only the decrease/increase case under HIGH #1. The cloning risk is broader: cloned `Order::cancel()` / `Product::deactivate()` etc. will all run on soft-deleted aggregates by default.

---

## 4. Critique of the scorecard

The per-area REJECT is **defensible on the evidence** — each area has at least one CRITICAL that affects cloning. But the scorecard has weaknesses:

### S1. No green checkmarks
Cookie is the positive reference for several patterns: port placement (D1), folder-per-command/query/event, named-static-factory pattern (`Cookie::create`/`reconstitute`), readonly DTO commands, named handler classes. A cloner reading the scorecard gets the impression "everything is broken" — and may abandon the template wholesale instead of fixing it. A "what's correct vs what's broken" breakdown would let the team scope fixes without throwing out the structural decisions.

### S2. No severity ranking inside each area
The Entity scorecard lists eight blocking issues without indicating which one is the *single* most important. For prioritisation: V1 (event-id null) and V3 (USD default) are catastrophic; the `getIsActive()` naming is not. Lumping them together loses signal.

### S3. The Repository scorecard understates the schema/runtime contract gap
"`tenant_id` schema-only fiction" is one bullet. But it has three orthogonal sub-failures: (a) repository doesn't write it, (b) repository doesn't filter it, (c) migration's `UNIQUE(tenant_id, name, deleted_at)` is structurally broken under MySQL NULL even if the runtime *were* fixed. Fixing (a)+(b) alone leaves (c). The scorecard implies one fix; three are needed.

### S4. No "blast radius" estimate
Each defect propagates with cloning velocity ~= 1 (one bug per clone). 30 cloned domains = 30 copies of each defect. The scorecard doesn't quantify. A simple "if you clone Cookie N times, you ship N×5 CRITICAL defects" sentence would communicate stakes better.

**Overall scorecard verdict: fair but lossy. The REJECT is right; the framing leaves a reader with insufficient prioritisation guidance.**

---

## 5. Critique of the remediation plan

Phase 1/2/3 ordering is broadly correct: deploy-blockers first, correctness-before-cloning second, hygiene third. Specific issues:

### R1. Step 19 (move event raising into entity) cannot work until V1 (id-null) is fixed
The remediation plan puts "move lifecycle event raising into the entity" at Phase 2 step 19 and "defer event raising until after assignId() or stamp id at drain" at step 20. These are sequenced in the wrong order. You cannot move `CookieCreatedEvent` raising into `Cookie::create()` until you have a deferred-stamping mechanism. **Step 20 must precede step 19, or be combined with it.**

### R2. Step 22 (tighten `assignId`/`bumpVersion` visibility) is not actionable in PHP
See D4. The plan says "tighten visibility via a marker interface or package-private trait". PHP has no package-private. The plan must commit to a specific mechanism: (a) reflection-based runtime check on caller class, (b) PHPStan custom rule + `@internal` (and configure CI to fail), or (c) accept `EntityHydrator` interface as a "key" the repo passes to prove its identity. Without picking one, every cloner picks differently.

### R3. Step 23 conflates seven independent sub-fixes
"Wire `CookieReadModelProjection` via `DomainServiceProviderInterface::registerProjections`; subscribe `CookieRestoredEventHandler`; switch query handlers to return `CookieView`; drive projection writes from event payloads; replace `SELECT count → INSERT/UPDATE`; shadow-table-and-swap; ..." — seven distinct units of work crammed into one step. The plan's stated atomicity policy (CLAUDE.md "≤3 files | <30 min | Binary done") is violated. Each sub-step needs to be its own task.

### R4. No step for "introduce a `StockChangeReason` enum / typed change reasons"
See N2. A cloner copying `decreaseStock(reason: 'decreaseStock')` will reproduce the same stringly-typed pattern in `Order::cancel(reason: 'cancel')`.

### R5. No step for "extract `Cookie` into ≤ 200-line files"
See N3. The 380-line entity blows the project's own class-size rule; every clone inherits the same shape. The plan doesn't address it.

### R6. No step for "pull business thresholds out of `BusinessMetricsLogging` trait"
See D7. Plan mentions in passing under step 50 ("pull domain-specific thresholds out of reusable traits") but in Phase 3 (Hygiene). Cloning before Phase 3 means every new domain inherits Cookie's stock=10 / price-change=10% / popularity=100. This belongs in Phase 2, not Phase 3.

### R7. No "block /add-domain until Phase 2 complete" gate
The plan does not enforce ordering. A team could complete Phase 1 (security deploy-blockers) and continue running `/add-domain` while Phase 2 is in flight. The consolidator says "Block all new domain scaffolding" in the executive summary but the plan has no corresponding step. **Add a tracked "freeze cloning" gate at the top of Phase 2 and release it only when Phase 2 acceptance criteria are met.**

### R8. No verification step
SMART-E atomic tasks (per CLAUDE.md) require verification commands. The plan has no "how do you know each step is done" criterion. For V1 (event-id null), a test asserting `CookieStockChangedEvent::cookieId !== null` on a freshly-created cookie's decreaseStock would verify; no such test is enumerated.

**Following the remediation plan as written: the deploy-blockers will likely be fixed; the cloning-blockers will be partially fixed (steps 19/20 may not work, step 23 is too coarse to execute reliably, the cloning freeze is not enforced, no verification gates).** A cloner who reads "Phase 2 complete" cannot trust that cloning is now safe.

---

## 6. Verdict: is the Cookie verdict defensible?

**Yes, the verdict is defensible.** Every one of the five clone-multiplying defects is real, accurately located, and individually sufficient to block cloning. The per-area REJECT is fair given that every area has at least one CRITICAL. The consolidator's executive summary call — "Block all new domain scaffolding" — is the right operational decision.

**But the verdict's framing is incomplete:**

1. The scorecard doesn't credit Cookie for the patterns it *gets right* (port placement, named factories, folder structure, readonly DTOs). A cloner needs to know what to preserve.
2. Several Cookie-specific defects (D2, D7, D8, N1–N7) are either buried in lower tiers or absent. They will be inherited at full strength by every clone.
3. The remediation plan has sequencing errors (R1), unactionable steps (R2), over-large steps (R3), and missing steps (R4–R8). Following it as-written does not actually unblock cloning.

**Recommended actions for the audit team:**

1. Add a "what Cookie gets right" subsection so the team knows the structural pattern is salvageable.
2. Promote D7, D8, N2, N3, N6, N7 into the Cookie scorecard at appropriate tiers.
3. Re-sequence steps 19/20 (V1 must be fixed before lifecycle events can move into the entity).
4. Commit to a specific mechanism for `assignId`/`bumpVersion` enforcement (R2).
5. Split step 23 into 7 atomic SMART-E tasks.
6. Add an explicit "freeze /add-domain" gate at start of Phase 2 with release criteria.
7. For every step in Phase 1 and 2, add a verification command (test name, grep target, or assertion).

With those changes, "fix Cookie before cloning" becomes followable. Without them, the plan is directionally correct but operationally incomplete.

**Cookie verdict: REJECT as canonical template — confirmed and defensible.**
**Plan verdict: structurally right, operationally incomplete — needs the seven changes above before it can be executed with confidence.**
