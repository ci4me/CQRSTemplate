# RE-AUDIT 08 — Service Provider & DI Wiring (Round 3)

**Slice:** CookieServiceProvider + Config/{Services,Events,Routes}.php + ServiceProviderRegistry / DomainServiceProviderInterface / RegisterRoutesNoop / AutoBind attribute
**Reviewer:** codeigniter4-specialist
**Date:** 2026-05-23
**Original audit:** `.audit/round3/08-provider-wiring.md` (19 findings — C3 H5 M6 L4 I1)
**Branch under re-audit:** `stabilization/erp-foundation` (HEAD)
**PRs claimed to touch this slice:** #35 (E07 — Activated/Deactivated handlers), #38 (E12.5 — `processedEventStore()` factory + EventDispatcher wiring), #41 (E11 — repository signature ripple)

## TL;DR

E07's two new event subscriptions (`CookieActivatedEvent`, `CookieDeactivatedEvent`) are wired correctly in `CookieServiceProvider::registerEvents()` at lines 236-243 — the only piece of the original slice 08 surface that visibly improved. EVERYTHING else is unchanged: every CRITICAL (F1 hard-coded namespace string, F2 silent undefined-index `getRepository`, F3 hard `LoggerFactory::create('cookie.events')` static call) is byte-identical to round 3; the `DomainServiceProviderInterface` has no `registerProjections()` hook (F4); `setRepositories()`/`getRepositories()` is still imperative + stringly-typed (F5); the four CommandHandlers + three QueryHandlers + seven EventHandlers are still all instantiated eagerly at first bus access (F12); the `array<mixed>` interface signature on `getRepositories()` is unchanged (F18). The `Services::processedEventStore()` factory and the matching `EventDispatcher::setProcessedEventStore()` setter — described in the audit prompt as landed via PR #38 (E12.5) — are NOT present on `stabilization/erp-foundation`; that work lives on the unmerged `epic/e12-5-processed-event-store` branch (commit `d862247`). One regression-shaped surprise: the audit prompt says "E13 (Provider DI overhaul) has NOT been opened", so all three CRITICALs are explicitly KNOWN-DEFERRED — that matches the source.

## Verdict

**NOT-READY (unchanged from round 3).**

Verdict shift: **none.** Round 3 already called this slice NOT-READY because of F1–F3; E07 closed zero of those findings, and the E12.5 work that would have touched `Services.php` was not merged into the audit branch. The reference template still cannot be cloned safely.

## Per-finding status

| ID  | Sev      | Round-3 status | Re-audit status | Evidence |
| --- | -------- | -------------- | --------------- | --- |
| F1  | CRITICAL | OPEN           | **OPEN**        | `CookieServiceProvider.php:254` — `['namespace' => 'App\Controllers\Domain\Cookie']` still a single-quoted string literal; no `::class` derivation, no `URI_SEGMENT` constant. Route URI `'cookies'` on the same line also still a literal. |
| F2  | CRITICAL | OPEN           | **OPEN**        | `CookieServiceProvider.php:308-311` — `private function getRepository(string $name): object { return $this->repositories[$name]; }` unchanged; still no `isset()` / `array_key_exists()` guard. PHP 8.3 strict mode throws `Undefined array key` at the `return` line, not the call site. |
| F3  | CRITICAL | OPEN           | **OPEN**        | `CookieServiceProvider.php:195` — `$logger = LoggerFactory::create('cookie.events');` static-factory call unchanged; the injected `logger` from `getRepositories()` is still ignored inside `registerEvents()` while `registerCommands()` / `registerQueries()` use the injected one. The two new E07 handlers on lines 238 + 242 are constructed with this static-factory logger, so the cloning footgun *spreads* to two extra handlers. |
| F4  | HIGH     | OPEN           | **OPEN**        | `DomainServiceProviderInterface.php:59-119` — interface contract unchanged: four registration methods (`Commands`, `Queries`, `Events`, `Routes`) + `getRepositories` / `setRepositories`; no `registerProjections(ProjectionRegistry $r)`. Round-3 note about PROJECTIONS.md being added (per `d3e52c1 docs: refresh file inventory, add PROJECTIONS.md, fix CLAUDE.md template claim`) is documentation-only — interface gap remains. |
| F5  | HIGH     | OPEN           | **OPEN**        | `CookieServiceProvider.php:80, 276-297` — still `private array $repositories = []` + `setRepositories(array)` overwrite + stringly-typed `getRepositories()` returning `['cookieRepository', 'cookieQueryRepository', ...]`. The three `instanceof` runtime checks (lines 102-108 + 158-164) still exist precisely because the typed accessor was never built. |
| F6  | HIGH     | OPEN           | **OPEN**        | No precomputed-manifest cache file, no `providers:cache` spark command, no production guard. `ServiceProviderRegistry` (per the original audit) still does two `RecursiveDirectoryIterator` walks; nothing in the recent commit history (`d3e52c1` through HEAD) touches the discovery scan path. |
| F7  | HIGH     | OPEN           | **OPEN**        | `Config\Cookie` (framework HTTP-cookie config) is still co-located with the `App\Domain\Cookie` reference domain. No rename, no top-of-file disambiguator. Adding to the confusion: the project recently renamed its reference scaffolding docs in `1a1f4d2 docs(skills): rewrite scaffolding + cqrs-architecture for post-#29-#39 Cookie` *without* shipping the rename. |
| F8  | HIGH     | OPEN           | **OPEN**        | `Services.php:199-251` — `ensureProvidersRegistered()` still sets `self::$providersRegistered = true` on line 244, *after* `ServiceProviderRegistry::registerAll()` on lines 237-242. No re-entrance throw, no lazy-proxy refactor. The `TransactionMiddleware` lazy resolver on line 111 (`static fn (): EventDispatcher => self::eventDispatcher()`) remains the only re-entrance dodge in the file, and it is undocumented except by a code comment. |
| F9  | MEDIUM   | OPEN           | **OPEN**        | `ServiceProviderRegistry::lcfirst()` short-name keying convention unchanged. The five string literals in `CookieServiceProvider::getRepositories()` (`cookieRepository`, `cookieQueryRepository`, `eventDispatcher`, `logger`, `loggingConfig`) are still the canonical, undocumented, sed-fragile interface. |
| F10 | MEDIUM   | OPEN           | **OPEN**        | `Autoload.php` still maps `App\\Domains` to a non-existent `APPPATH . 'Domains'` folder. Reference domain remains under `app/Domain/` (singular). The dead PSR-4 entry persists. |
| F11 | MEDIUM   | OPEN           | **OPEN**        | `registerCommands()` is now 38 lines (96-133); `registerQueries()` is 37 lines (147-183); `registerEvents()` is 52 lines (193-244) — the new E07 subscriptions pushed `registerEvents()` further past the project's "≤ 20 lines / method" rule. CLAUDE.md gate is silently violated by the canonical reference. |
| F12 | MEDIUM   | OPEN           | **OPEN; WORSE** | Round-3 counted four eager command handlers, three eager query handlers, four eager event handlers. With E07 added, `registerEvents()` now eagerly instantiates **seven** handlers (`Created`, `Updated`, `Deleted`, `StockChanged`, `Restored`, `Activated`, `Deactivated`) at first bus access. The bus contract (`subscribe(string, callable)`) still accepts an instance, not a `Closure(): object` factory. The cost-of-cold-boot trend went the wrong direction. |
| F13 | MEDIUM   | OPEN           | **OPEN**        | `setRepositories()` line 294-297 — still full-replacement (`$this->repositories = $repositories;`), no merge semantics, no `setRepository($name, $instance)` accessor. Class docblock line 63 still falsely advertises "Easy to test: Can mock repositories via setRepositories()". |
| F14 | MEDIUM   | OPEN           | **OPEN**        | `getRepository(string $name): object` still returns the opaque `object` type at line 308. PHPStan L8 can't narrow; the three `instanceof` blocks still exist as runtime smoke detectors for what should be compile-time errors. |
| F15 | LOW      | OPEN           | **OPEN**        | `'cookie.events'` channel name still hand-written on line 195. `deriveLogChannel` convention divergence unchanged. The two new E07 handlers (`CookieActivatedEventHandler`, `CookieDeactivatedEventHandler`) inherit the same hand-written channel, doubling the surface for the naming-divergence drift. |
| F16 | LOW      | OPEN           | **OPEN**        | `Config\Events.php` unchanged: still framework hooks + the A11 JWT secret check (lines 66-73), no top-of-file disambiguator that distinguishes framework events from the CQRS `EventDispatcher`. |
| F17 | LOW      | OPEN           | **OPEN**        | `Routes.php:34-36` foreach loop unchanged — still no try/catch around `$provider->registerRoutes($routes);`. Any cloned provider with a route-registration typo crashes the entire routing layer. |
| F18 | LOW      | OPEN           | **OPEN**        | `getRepositories(): array` docblock still `@return array<mixed>` on `CookieServiceProvider.php:274` and `DomainServiceProviderInterface.php:91`. PHPStan still can't narrow to `list<string>`. |
| F19 | INFO     | OPEN           | **OPEN**        | `RegisterRoutesNoop` trait still unused by any concrete provider. CookieServiceProvider implements `registerRoutes()` itself (lines 252-263), as do UserServiceProvider and AuthServiceProvider. |

**Totals:** CRITICAL 3 (0 closed) | HIGH 5 (0 closed) | MEDIUM 6 (0 closed, 1 *worsened* — F12) | LOW 4 (0 closed) | INFO 1 (0 closed). **Net closed: 0/19.**

## What the recent PRs actually changed

### PR #35 / E07 — Cookie lifecycle event handlers (commits `5b60239` + `3890eef` + `b70db32`)

**Verified additions in `CookieServiceProvider.php`:**

- New imports lines 15-20 for `CookieActivatedEvent` / `CookieActivatedEventHandler` and `CookieDeactivatedEvent` / `CookieDeactivatedEventHandler`. Verified on disk:
  - `app/Domain/Cookie/Events/CookieActivated/CookieActivatedEvent.php` — present
  - `app/Domain/Cookie/Events/CookieActivated/CookieActivatedEventHandler.php` — present
  - `app/Domain/Cookie/Events/CookieDeactivated/CookieDeactivatedEvent.php` — present
  - `app/Domain/Cookie/Events/CookieDeactivated/CookieDeactivatedEventHandler.php` — present
- New subscriptions lines 236-243 — both events register correctly with the dispatcher. The associated comment correctly explains the round-2 fix lineage and the entity-side raise pattern.
- Net effect on the slice-08 findings: **none of the 19 closed.** E07 added two new handler lines without touching the underlying contract or refactoring the provider plumbing. The improvement is event-coverage parity (the entity now raises Activated/Deactivated and the dispatcher has subscribers), not provider-shape correction. The new lines inherit every cloning footgun (F3 LoggerFactory leak, F11 method-too-long, F12 eager construction, F15 hand-written channel) that round-3 flagged for the pre-existing subscriptions.

### PR #38 / E12.5 — ProcessedEventStore wiring (commits `f941908` + `d862247` + `482c8a0`)

**NOT MERGED INTO `stabilization/erp-foundation`.** Verified via:

- `git branch --contains d862247` → returns only `epic/e12-5-processed-event-store`.
- `git diff d862247 HEAD -- app/Config/Services.php` shows the `processedEventStore()` factory method (34 lines) being REMOVED relative to that commit's state — meaning `stabilization/erp-foundation` predates the E12.5 work entirely, not that the work was reverted.
- `grep -rn "ProcessedEventStore" app/` returns zero matches on HEAD.
- `Services.php` does NOT declare a `processedEventStore()` factory; `EventDispatcher.php` (251 lines on HEAD) does NOT have `setProcessedEventStore()`, does NOT have the `private ?ProcessedEventStoreInterface $processedEventStore` property, and does NOT have the `isProcessed → invoke → markProcessed` bracket in `dispatch()`.

**Implication:** The audit prompt's claim that "PR #38 (E12.5) — `Services::processedEventStore()` factory added; EventDispatcher wiring" landed on the audit branch is incorrect. On `stabilization/erp-foundation`, slice 05/F5 (round-3 handler-side at-most-once dedup) is also still OPEN by extension — there is no factory to wire even if a domain provider wanted to opt in. This affects slice 08 only indirectly (it does not introduce or close any of the 19 findings), but it is a material discrepancy worth flagging because the audit-prompt narrative assumed the wiring existed.

### PR #41 / E11 — repository signature ripple

No PR #41 commits visible in the recent log under that label. Searching the commit history surfaces several Cookie-repository hardenings (`b89e3f3 refactor(cookie): single-statement delete + version-bumping restore + purge() + trusted reconstitute`, `35a26c5 refactor(cookie): port existsByName takes CookieName VO + add purge() + fromTrusted`, `0832185 fix(cookie): drop withDeleted/LOWER in existsByName + escape LIKE wildcards`). None of these changed:

- The repository's class name or namespace (still `App\Domain\Cookie\Repositories\CookieRepository`, still tagged `#[AutoBind]`).
- The `lcfirst(shortName)` discovery key (`cookieRepository`).
- The `CookieServiceProvider::getRepositories()` declared string `'cookieRepository'`.

So the provider wiring did not ripple. Findings F5 / F9 / F14 (the stringly-typed `getRepository('cookieRepository')` chain) remain identical to round 3.

## Newly observable issues (not in original 19)

### F20 — INFO — `Services.php` line 250 still triggers `static::getSharedInstance('projectionRegistry')` for its side-effect even though no projection registers there

`Services::ensureProvidersRegistered()` ends with `static::getSharedInstance('projectionRegistry');` (line 250) — a discarded return whose only purpose is to force registry construction. Comments on lines 246-249 explain this is meant to register projections *after* domain providers wire their handlers, but the current `projectionRegistry()` method body (lines 171-189) just constructs an empty registry and explicitly notes "the pilot projection is no longer registered here." The side-effecting call is therefore vestigial — `php spark projections:rebuild` would still work without it, because the spark command resolves the registry on its own. Worth removing or replacing with a `// projection registration deferred until F4 is closed` comment so the next cloner doesn't add a side-effect to that line by mistake. Not a regression; the line existed in round 3 too but wasn't called out because slice 08 focused on the provider, not the registry's tail call.

## What is correct / praiseworthy

- E07's `CookieActivatedEvent` and `CookieDeactivatedEvent` subscriptions exist and target real handler classes that exist on disk — the slice-01/F2 "silent toggle" loop is closed at the dispatcher layer (even if the provider plumbing carrying them is still flawed).
- `CookieRestoredEvent` subscription (round-3 round-2-fix verification) remains in place at lines 226-229 with its self-explanatory comment.
- `Routes.php` still uses the auto-discovery loop (lines 34-36) — no regression to manual route registration during the E07 churn.
- `DomainServiceProviderInterface` still enforces the `registerRoutes` signature (line 118) at the type level, so a forgotten implementation fails at boot rather than at first 404.
- The `#[AutoBind]` discovery path for repositories continues to keep the `cookieRepository` / `cookieQueryRepository` keys synchronized with class names without manual edits to `Services.php` — Phase 3 Group B's gain is intact.

## Biggest residual / top fixes before cloning (unchanged from round 3)

1. **E13 must open.** Three CRITICALs (F1 hard-coded namespace, F2 silent undefined-index, F3 static `LoggerFactory::create('cookie.events')`) are all single-class fixes that block safe cloning. None were touched by E07; round 3's "Top 3 fixes" list still applies verbatim.
2. **Add `registerProjections(ProjectionRegistry)` to `DomainServiceProviderInterface` (F4).** The docs commit `d3e52c1` shipped a `PROJECTIONS.md` that explains the per-provider registration intent; the interface that would let a cloner actually do it is still one method short. Documentation and contract have drifted.
3. **Rename the reference domain off of `Cookie` (F7).** The naming collision with `Config\Cookie` remains every cloner's first grep frustration; the `docs(skills): rewrite scaffolding` commit `1a1f4d2` is a missed opportunity to ship the rename alongside its own renaming-friendly playbook.

## Cross-slice notes

- Slice 05 (`event-dispatcher`) finding F5 (handler-side at-most-once) was claimed CLOSED via E12.5 — that claim does NOT hold on `stabilization/erp-foundation` per the evidence above. Re-auditors of slice 05 should verify against this branch specifically before marking F5 closed.
- Slice 01 (`entity-lifecycle`) F2 (silent activate/deactivate) was correctly closed by E07 *at the entity + dispatcher edges*. Slice 08 is the bystander that benefits without being fixed.

---

**Severity counts (re-audit):** CRITICAL 3 open | HIGH 5 open | MEDIUM 6 open (1 worse) | LOW 4 open | INFO 1 open + 1 new (F20).
**Net closed since round 3:** 0/19.
**Top residual:** Three round-3 CRITICALs remain byte-identical at provider source, AND the E12.5 `processedEventStore()` factory the audit prompt assumed was wired is absent from this branch — the canonical reference still cannot be cloned without inheriting four sed-cloning landmines plus a missing infrastructure adapter.
