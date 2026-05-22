# 08 — Service Provider & DI Wiring

**Slice:** CookieServiceProvider + Config/{Services,Events,Autoload,Cookie}.php + ServiceProviderRegistry / RegisterRoutesNoop / DomainServiceProviderInterface / AutoBind attribute
**Reviewer:** codeigniter4-specialist
**Date:** 2026-05-22
**Source files reviewed:** 13

## TL;DR

Cookie's DI wiring works in steady state but is riddled with sed-cloning footguns: the entity-to-route namespace is a hard-coded string literal, the `getRepository()` helper silently undefined-key-faults on typos, the provider hard-couples to `LoggerFactory` (static call) in `registerEvents`, and the auto-discovery key naming (`lcfirst(shortName)`) means renaming a repository class silently moves the key. The "freshly-fixed" `CookieRestoredEvent` subscription is present, but two more sibling design holes remain (no `registerProjections` hook on the interface; `getRepositories()` and `setRepositories()` are imperative coupling that defeats the point of attribute-based discovery). Three name collisions between the framework's `Config\Cookie` and the domain `Cookie` will confuse every cloner.

## Verdict
NOT-READY

## Findings

### F1 — CRITICAL — Provider's `registerRoutes()` namespace string is sed-hostile
- **Location:** `app/Domain/Cookie/CookieServiceProvider.php:226`
- **Observation:** The route group is registered with `['namespace' => 'App\Controllers\Domain\Cookie']` — a single-quoted *string literal* of the controllers namespace. A blanket `sed s/Cookie/Foo/g` will rewrite this string correctly, but any cloner that scaffolds via copy + IDE-rename (the common path documented in CLAUDE.md as "copy Cookie's structure for new domains") loses the rename on a quoted string. The same domain name is repeated four lines below in route paths (`cookies`, `(:num)`) — neither is derivable from the class. Compare with the controller method names (`CookieController::index` etc.) which are PSR-4-bound and *would* get auto-renamed.
- **Why this is a template defect:** The provider should derive both the URI segment and the controllers namespace from `::class` constants, e.g. `\App\Controllers\Domain\Cookie\CookieController::class` parsed into namespace + short-name, OR (simpler) declared via a `protected const URI_SEGMENT = 'cookies';` so the cloner has exactly one source of truth to override. As written, every clone must edit four string literals across one method and there's no compile-time check they're consistent.
- **Suggested fix:** Pull `URI_SEGMENT` + `CONTROLLER_NAMESPACE` into class constants (or even better, derive controller namespace via `(new \ReflectionClass(SomeController::class))->getNamespaceName()`), and reference `CookieController::class` to drive route targets. Document the contract in `DomainServiceProviderInterface`.

### F2 — CRITICAL — `getRepository()` silently UNDEFINED-INDEX on typos
- **Location:** `app/Domain/Cookie/CookieServiceProvider.php:280-283`
- **Observation:** `private function getRepository(string $name): object { return $this->repositories[$name]; }` — no `isset()` check, no `array_key_exists()` guard. With PHP 8.3 strict mode this throws `Undefined array key "..."` with the line number of `return`, NOT the call site that mistyped. A cloner who types `'fooRepository'` in `registerCommands()` gets a confusing error pointing at line 282, not at line 88. The `getRepositories()` declaration on lines 248-257 is the only "manifest" — but it's a free-form string array with no contract that the keys match what `getRepository()` will look up.
- **Why this is a template defect:** Every clone propagates the same un-guarded helper. The registry's `registerAll()` at `ServiceProviderRegistry.php:106-114` *does* throw a descriptive error when a `getRepositories()` entry isn't in the provided map — but the typo can be on the OTHER side (calling `getRepository('cookieRepostiory')` after declaring `'cookieRepository'`) and the registry will hand back the requested entries fine; only at call time does it blow up.
- **Suggested fix:** Two options — (a) extract `getRepository()` to a base class / trait that does `if (!isset($this->repositories[$name])) throw new RuntimeException(...)`; or (b) replace the string-keyed array with explicit typed setters (`setCookieRepository(CookieRepositoryInterface $r): void`) generated from `getRepositories()`. The current shape defeats both static analysis and runtime safety.

### F3 — CRITICAL — `registerEvents()` constructs its own logger via static factory, bypassing the injected `logger` repository
- **Location:** `app/Domain/Cookie/CookieServiceProvider.php:181`
- **Observation:** `$logger = LoggerFactory::create('cookie.events');` — direct static call into Infrastructure. Meanwhile `getRepositories()` already declares `'logger'` (line 254) AND that key IS resolved and injected for `registerCommands` / `registerQueries` to use. So the provider receives *one* logger via DI and *constructs another* via a hard-coded static factory. Every cloned provider inherits this pattern with a literal `'cookie.events'` channel string — a sed of `Cookie -> Foo` rewrites the channel to `'foo.events'` only if the cloner replaces the lower-case substring. The dependency on `LoggerFactory` is also hidden from the provider's public dependency manifest (`getRepositories()`), so tests cannot intercept the events logger by injecting a fake.
- **Why this is a template defect:** Static factories are the single biggest testability/DI footgun in PHP service providers. R03 in `.audit/round2/r03-cookie-template-focus.md` N5 already flagged this; it remains unfixed. Worse, it's inconsistent with the rest of the provider, which DOES go through `getRepository()` for its logger — so a cloner reading the file sees two completely different conventions in one class.
- **Suggested fix:** Either (a) use the already-injected `$logger = $this->getRepository('logger')` in `registerEvents()` like the other methods do, OR (b) add `'cookieEventsLogger'` to `getRepositories()` and have `Services::ensureProvidersRegistered()` supply a per-domain channel from a convention-derived name (`{snake_short_name}.events`). The current "half static, half injected" mix is the worst of both worlds.

### F4 — HIGH — `DomainServiceProviderInterface` has no `registerProjections()` hook
- **Location:** `app/Infrastructure/ServiceProvider/DomainServiceProviderInterface.php:67-118` (interface contract) + `app/Config/Services.php:181-188` (projection registry built but no per-domain hook)
- **Observation:** Phase 2 collapsed Cookie's read-model into the canonical table and the example projection file lives at `app/Domain/Cookie/Projections/CookieReadModelProjection.php.example` (verified). The comment in `Services::projectionRegistry()` says "New domains that genuinely need a denormalised read model register their projection from their own service provider" — but no method on `DomainServiceProviderInterface` exists for that. A cloned domain that DOES want projections has to (a) invent its own registration path through `Services.php` (the very thing the auto-discovery design avoids), or (b) cram projection-registration into `registerEvents()` (mixing concerns). The interface is one method short of its own design intent.
- **Why this is a template defect:** This is the same shape of defect round-2 identified for `CookieRestoredEvent` — the discovery surface is asymmetric to the patterns the domain needs. A cloner following the comment cannot do what the comment recommends.
- **Suggested fix:** Add `registerProjections(ProjectionRegistry $registry): void` to `DomainServiceProviderInterface` and call it from `Services::ensureProvidersRegistered()` (after the event-handler registration, before sealing `$providersRegistered`). Provide a `RegisterProjectionsNoop` trait mirroring `RegisterRoutesNoop`.

### F5 — HIGH — `setRepositories()` + `getRepositories()` are imperative coupling that defeats #[AutoBind]
- **Location:** `app/Domain/Cookie/CookieServiceProvider.php:248-269` + `app/Infrastructure/ServiceProvider/ServiceProviderRegistry.php:98-118`
- **Observation:** The provider declares dependencies twice: once in the constructor-less stringly-typed `getRepositories()` array, again implicitly in `registerCommands`/`registerQueries` via `instanceof` checks. The registry could resolve these via constructor reflection (already done for `#[AutoBind]`) but instead uses a setter-injection pattern that requires a `private array $repositories = []` mutable state on each provider. Five reasons this is fragile in a template: (1) the array is `array<string, object>` — no type safety; (2) the names are stringly-typed; (3) the `instanceof` checks in `registerCommands` lines 92-98 are essentially manual type-safety bolted on; (4) adding a new dependency requires editing both `getRepositories()` AND adding a fresh `getRepository(...)` call + `instanceof` line in the consumer method; (5) the contract that "keys returned by `getRepositories()` MUST exactly match keys used in `getRepository()`" has no test or enforcement.
- **Why this is a template defect:** Every clone copies the imperative setter-injection pattern. A constructor-injected provider (the modern PHP 8.3 pattern, mirroring `#[AutoBind]` itself) would let the registry instantiate via `new $className($cookieRepo, $queryRepo, $dispatcher, ...)` with full type safety. The current shape predates the Phase 3 `#[AutoBind]` discovery and was not refactored to match.
- **Suggested fix:** Migrate providers to constructor injection; the registry already does this for `#[AutoBind]` repositories — extending the same resolver to providers is straightforward. Mark `setRepositories()`/`getRepositories()` `@deprecated`.

### F6 — HIGH — Provider discovery class scan walks all of `app/Domain` AND `app/Infrastructure` on every cold start
- **Location:** `app/Infrastructure/ServiceProvider/ServiceProviderRegistry.php:60-63, 151-176, 381-403`
- **Observation:** Two separate `RecursiveDirectoryIterator` walks (one for `#[DomainServiceProvider]`, one for `#[AutoBind]`). Each walk: opens every `.php` file, tokenizes with `token_get_all()`, reflects the class, checks attributes. Cached per-process via `self::$providersCache` / `self::$autoBindCache` — fine inside one PHP-FPM request. But (a) there is no `opcache.preload`-friendly precomputed manifest; (b) every `php spark` invocation pays the cost from cold; (c) the registry has no production guard (e.g. `if (ENVIRONMENT === 'production' && !cached) load_from_dump()`).
- **Why this is a template defect:** Performance scales linearly with `app/Domain/*` and `app/Infrastructure/*` file count. The Cookie template will become 30+ domains; at that point the cold-start scan dominates spark-command latency and request boot in CLI-driven workloads. Worse, the auto-discovery is fundamentally non-deterministic in ordering because `RecursiveDirectoryIterator` order is filesystem-dependent (round-2 alluded to this in the consolidator's "cyclic init" notes). Two providers that double-register the same command class will throw at registration time (`CommandBus::register` throws), but the order in which they're discovered changes the error message and the registered handler.
- **Suggested fix:** Add a `cache/providers.php` dump command (e.g. `php spark providers:cache`) that writes a static manifest, plus an `if (file_exists(CACHE) && !ENVIRONMENT==='development')` short-circuit in `discoverProviders()` / `discoverRepositories()`. Sort the discovered providers by class name so registration order is deterministic across machines.

### F7 — HIGH — `Config\Cookie` (framework HTTP-cookie config) collides with the Cookie *domain* name
- **Location:** `app/Config/Cookie.php:3` (`namespace Config; class Cookie`) + every reference to "Cookie" in `app/Domain/Cookie/*`
- **Observation:** The reference domain is named after the framework's `Config\Cookie` (the HTTP-cookie-attribute config). Co-located in the doc string of the slice: "discover its purpose" — it is the standard CI4 HTTP-cookie config (prefix, expires, path, secure, httponly, samesite, raw), NOT the Cookie domain. The two share zero behaviour. Open the project as a new contributor and grep `Cookie`: every framework cookie reference, every domain entity reference, every test fixture lands in one giant intermingled bucket. The provider import block on lines 7-32 of `CookieServiceProvider.php` is fine because the namespace prefixes disambiguate, but any cloner thinking of *renaming* the domain has to manually filter framework occurrences from domain occurrences — `sed` can't do it.
- **Why this is a template defect:** A canonical CQRS reference domain MUST NOT share a name with a CodeIgniter framework class. Round-1 audit `.audit/round1/14-config-spark.md` lines 74-76 already noted `Config\Cookie` separately; round-1 did NOT call out the naming collision with the domain. Renaming the reference domain to e.g. `Product` (or `Recipe`, or `MenuItem`) would eliminate the collision entirely.
- **Suggested fix:** Rename the reference domain to a non-framework name (`Product`, `MenuItem`, `Recipe`). Until then, document the collision LOUDLY at the top of CookieServiceProvider, and add a CI grep guard that fails if a domain shares its short name with a class in `Config\`.

### F8 — HIGH — `Services::ensureProvidersRegistered()` is the documented re-entrance landmine (round-1 HIGH)
- **Location:** `app/Config/Services.php:199-251`
- **Observation:** Round-1 `.audit/round1/14-config-spark.md:45` flagged that the `$providersRegistered` flag is set AFTER `registerAll()` returns — meaning any provider whose registration code touches `service('commandBus')` or `service('eventDispatcher')` re-enters and recurses. Verified in source: the flag is still set on line 244, AFTER `ServiceProviderRegistry::registerAll()` on lines 237-242. The CookieServiceProvider itself doesn't trigger this today (handlers are constructed eagerly with already-resolved deps), but the design hazard remains for every cloner. Worse, `discoverRepositories()` is now called from `ensureProvidersRegistered()` (line 220), and `resolveKnownDependency()` at `ServiceProviderRegistry.php:541` can call `\Config\Services::eventDispatcher()` (when an `#[AutoBind]` repo has an `EventDispatcher` constructor param). That path is documented as nullable to dodge recursion (lines 494-502), but the docblock acknowledges it: "Resolving them here would recurse through Services::eventDispatcher() -> ensureProvidersRegistered() -> discoverRepositories() -> here."
- **Why this is a template defect:** The recursion guard is a comment, not a mechanism. The opt-out is "make the EventDispatcher param optional" — a convention every cloner must remember. Forgetting it on any new `#[AutoBind]` repository creates an infinite loop or a stack overflow at boot. This is a sed-cloning fault line.
- **Suggested fix:** Move `self::$providersRegistered = true` BEFORE the call to `registerAll()`, with an idempotency check in the body. Add a re-entrance bool that throws a clear `RuntimeException('Provider registration re-entered')` rather than recursing. Alternatively, switch the entire repository graph to lazy proxies so construction-time dependencies don't trigger boot.

### F9 — MEDIUM — `lcfirst(shortName)` repository key is a sed-fragile convention
- **Location:** `app/Infrastructure/ServiceProvider/ServiceProviderRegistry.php:434`
- **Observation:** `$shortName = lcfirst($reflection->getShortName());` — `CookieRepository` becomes `'cookieRepository'`. A cloner who renames `CookieRepository` to `CookieWriteRepository` (a defensible split during refactor) silently moves the key to `'cookieWriteRepository'`, breaking every `$this->getRepository('cookieRepository')` consumer with no static-analysis warning. The key is also opaque: a reader of `CookieServiceProvider::getRepositories()` cannot grep `'cookieRepository'` and find the class definition — they have to know the lcfirst convention.
- **Why this is a template defect:** Decoupling key from class is the textbook "stringly-typed" anti-pattern. The same problem affects every cloned domain; multi-class repositories (e.g. read + write split) silently change the key.
- **Suggested fix:** Use `::class` constants as keys (`CookieRepository::class => $instance`) and look them up by class. Where short-name keys are required for human readability, derive them from an explicit `#[AutoBind(name: 'cookieRepository')]` attribute parameter, NOT from `lcfirst()`.

### F10 — MEDIUM — `Autoload.php` declares `'App\\Domains'` PSR-4 mapping but the actual domain folder is `App\\Domain` (singular)
- **Location:** `app/Config/Autoload.php:43`
- **Observation:** `'App\\Domains' => APPPATH . 'Domains'` — but the on-disk folder is `app/Domain/` (singular), used by everything from `CookieServiceProvider`'s namespace declaration (`namespace App\Domain\Cookie`) to the `ServiceProviderRegistry::PROVIDER_PATHS` constant (`APPPATH . 'Domain'`). The `Domains` mapping is a dead PSR-4 entry pointing at a non-existent folder. It works only because the catch-all `APP_NAMESPACE => APPPATH` ahead of it on line 41 already maps `App\*` to `app/*` via the framework default. The dead entry is harmless at runtime but actively misleading: a cloner who reads `Autoload.php` first might create `app/Domains/Foo/` and find nothing works.
- **Why this is a template defect:** Dead config is worse than no config — it signals "this is the convention" and breaks expectations.
- **Suggested fix:** Delete the line, or rename the folder to `app/Domains/` and update every namespace in 20+ files. Pick one and document it.

### F11 — MEDIUM — `registerCommands()` 75-line method with three concerns mixed (resolve, validate, register)
- **Location:** `app/Domain/Cookie/CookieServiceProvider.php:86-123`
- **Observation:** Single method does (a) repository resolution (3 lines), (b) type validation via three `instanceof` (6 lines), (c) four `$commandBus->register()` calls (each spans 4 lines). Same structure repeated for `registerQueries()` (lines 133-169). The `instanceof` block exists *only* because the underlying typed accessor was thrown away in favor of stringly-typed lookups (F5). Eliminating F5 eliminates the `instanceof` block. As-is, every cloned domain reproduces the same 75-line method shape.
- **Why this is a template defect:** Project rules (CLAUDE.md: "Methods ≤ 20 lines") are blown by the method shape required to satisfy the discovery contract. The reference template literally cannot follow its own coding standards in this file.
- **Suggested fix:** Either fix F5 (constructor injection) or extract per-command-class private register methods (`private function registerCreateCookie(CommandBus $b, CookieRepositoryInterface $r, ...): void`).

### F12 — MEDIUM — `registerCommands()` constructs four handlers eagerly even if only one command is dispatched
- **Location:** `app/Domain/Cookie/CookieServiceProvider.php:101-122`
- **Observation:** `new CreateCookieHandler(...)`, `new UpdateCookieHandler(...)`, `new DeleteCookieHandler(...)`, `new RestoreCookieHandler(...)` — all four handlers are instantiated at boot. Each handler takes `($repository, $eventDispatcher, $logger)`, all already-resolved. Cost is small now, but as handlers grow (D2 / D3 / D11 trends elsewhere in the codebase) and as the number of domains scales to 30+, every HTTP request pays the cost of instantiating handlers it will never invoke. Same applies to `registerQueries()` (lines 153-168) and `registerEvents()` (lines 184-216).
- **Why this is a template defect:** The `CommandBus::register()` API accepts an `object` directly, not a factory. Lazy resolution would require changing the bus contract (`register(string $command, callable $factory)`). Round-2's "circular init" risk and round-1's "re-entrance" risk also stem from eager construction.
- **Suggested fix:** Change `CommandBus::register()` / `QueryBus::register()` / `EventDispatcher::subscribe()` to accept either an instance OR a `Closure(): object` factory. The bus invokes the factory once on first dispatch. Documented pattern; standard fix for service-locator-meets-DI tension.

### F13 — MEDIUM — `setRepositories()` overwrites instead of merging; tests cannot incrementally swap a single dependency
- **Location:** `app/Domain/Cookie/CookieServiceProvider.php:266-269`
- **Observation:** `public function setRepositories(array $repositories): void { $this->repositories = $repositories; }` — full replacement. A test that wants to swap out only the `cookieRepository` while keeping the production `eventDispatcher` has to reconstruct the entire dependency map. The class docblock claims "Easy to test: Can mock repositories via setRepositories()" (line 59) — but the API forces all-or-nothing replacement.
- **Why this is a template defect:** The most common test scaffolding pattern (replace one mock, keep the rest) is the least-supported scenario.
- **Suggested fix:** Either accept a merge semantics (`$this->repositories = [...$this->repositories, ...$repositories];`) or expose a `setRepository(string $name, object $instance): void` for single-key updates. Tests then call it incrementally.

### F14 — MEDIUM — `getRepository()` return type is `object`, defeating PHPStan L8 type narrowing
- **Location:** `app/Domain/Cookie/CookieServiceProvider.php:280` (signature)
- **Observation:** `private function getRepository(string $name): object` — return is loosely typed as `object`. The `instanceof` checks at lines 92-98 / 144-150 are forced by this opaque type. With a stronger contract (typed accessors per repo: `getCookieRepository(): CookieRepositoryInterface`) PHPStan would catch a typo at static-analysis time. The current shape silences the analyser.
- **Why this is a template defect:** PHPStan L8 is part of the gate (CLAUDE.md "PHPStan L8"). This method dodges the gate by widening its return type. Every cloned provider inherits the same dodge.
- **Suggested fix:** Replace the magic-string `getRepository()` helper with a small typed-accessor base class generated from `getRepositories()`. Or, again, switch to constructor injection (F5).

### F15 — LOW — `registerEvents()` channel name `'cookie.events'` does not match the convention `deriveLogChannel()` would generate
- **Location:** `app/Domain/Cookie/CookieServiceProvider.php:181` + `app/Infrastructure/ServiceProvider/ServiceProviderRegistry.php:560-565`
- **Observation:** `deriveLogChannel('CookieRepository')` returns `'cookie.repository'`. `deriveLogChannel('CookieQueryRepository')` returns `'cookie.repository.query'`. But the provider's events channel is `'cookie.events'`, hand-written. A cloner sees the lowercase short name + `.events` and copies it as `'foo.events'`; they have no way to discover the *convention* unless they read `deriveLogChannel` first.
- **Why this is a template defect:** Two different conventions for two different layers (repository auto-derives, events hand-writes).
- **Suggested fix:** Use `deriveLogChannel(static::class . '.events')` or equivalent. Or — preferred — eliminate the local logger (F3) and let the centrally-configured one carry the channel.

### F16 — LOW — `Config\Events.php` is the framework default + JWT check; not domain-aware
- **Location:** `app/Config/Events.php`
- **Observation:** This file is the CodeIgniter4 framework event hook configurator, not a DI/event-bus configurator. The JWT presence check at lines 66-73 is the only domain-relevant addition. There is no link from this file to `EventDispatcher` — they are different concepts that happen to share the word "event" (framework hot-reload / debug-toolbar / pre-system hooks vs. DDD domain events). A new contributor reading slice 08 might mistake `Config\Events.php` for the event-dispatcher config and conclude domain event handlers should be wired here.
- **Why this is a template defect:** Naming overload. Round-1 14-config-spark.md already covered this file for security; no domain-event-dispatcher concerns belong here.
- **Suggested fix:** Add a top-of-file comment explicitly disambiguating: "This file configures CI4 framework hooks (pre_system, DBQuery toolbar). Domain events are configured in CookieServiceProvider::registerEvents() / EventDispatcher. Do NOT add domain event hooks here."

### F17 — LOW — `Routes.php` auto-mount loop has no error handling
- **Location:** `app/Config/Routes.php:34-36`
- **Observation:** `foreach (ServiceProviderRegistry::discovered() as $provider) { $provider->registerRoutes($routes); }` — if any provider throws in `registerRoutes()`, the routing layer is partially configured and CI4 errors out at the first un-matched URL. No try/catch, no logging, no fail-fast at boot.
- **Why this is a template defect:** A typo in one cloned provider's route definition takes down the entire app's routing layer instead of just that domain.
- **Suggested fix:** Wrap each provider's `registerRoutes` call in try/catch with structured logging and a clear "domain X failed to register routes" message. Decide policy: fail boot, or fail just that domain.

### F18 — LOW — `getRepositories(): array` returns `array<mixed>` instead of `list<string>`
- **Location:** `app/Domain/Cookie/CookieServiceProvider.php:248`
- **Observation:** Docblock says `@return array<mixed>` but the actual return is a `list<string>`. The interface (line 91 of `DomainServiceProviderInterface.php`) is also typed `array<mixed>`. PHPStan can't narrow.
- **Why this is a template defect:** Type imprecision propagates per clone.
- **Suggested fix:** Tighten interface + implementation to `@return list<string>`.

### F19 — INFO — `RegisterRoutesNoop` trait is referenced in the interface docblock but not actually used by any current provider
- **Location:** `app/Infrastructure/ServiceProvider/RegisterRoutesNoop.php` + `app/Domain/Cookie/CookieServiceProvider.php:224`
- **Observation:** Trait exists; UserServiceProvider, AuthServiceProvider, CookieServiceProvider all implement `registerRoutes` themselves. The trait is presented as "default for providers without routes" but the reference template has zero examples of it being applied. A cloner who decides their domain has no HTTP routes has no in-repo example of `use RegisterRoutesNoop;`.
- **Why this is a template defect:** Unreferenced affordance.
- **Suggested fix:** Add an in-repo example, OR remove the trait and require every provider to implement explicitly (it's three lines).

## What is correct / praiseworthy

- The `#[DomainServiceProvider]` attribute + `ServiceProviderRegistry::discoverProviders()` flow is the right idea: zero-configuration domain addition via PHP 8 attributes. The token_get_all-based class extraction (lines 250-292) is robust against the anonymous-class / multi-line-namespace cases that a regex would miss.
- The `CookieRestoredEvent` handler subscription is correctly present (lines 212-215) with a self-aware comment explaining the round-2 fix. Compare with the pre-fix state described in `.audit/round2/r03-cookie-template-focus.md`.
- All four commands (Create/Update/Delete/Restore) and all three queries (GetById/GetAll/GetPaginated) and all five events (Created/Updated/Deleted/StockChanged/Restored) are accounted for and registered — completeness audit passes.
- The split between write-side (`CookieRepositoryInterface`) and read-side (`CookieQueryRepositoryInterface`) ports is correctly enforced at the registration boundary: command handlers receive only the write port; query handlers receive only the read port. The docblock on lines 135-139 even explains the post-Phase-2 collapsed-table compromise.
- `RegisterRoutesNoop` trait is a clean affordance for route-less providers; the interface docblock cross-references it.
- `Services::resetProviders()` exists (line 74) explicitly for test cleanup — round-1 raised re-entrance risk and this is the recovery mechanism.
- `ServiceProviderRegistry::clearCache()` resets both provider and repository caches together (lines 574-578) — single point of cache invalidation.
- The `TransactionMiddleware` is wired with a lazy resolver `static fn (): EventDispatcher => self::eventDispatcher()` (Services.php:111) to dodge the recursion-during-cold-boot trap — explicitly commented.

## Top 3 fixes before cloning

1. **Replace `getRepositories()`/`setRepositories()` + `getRepository()` magic-string lookup with constructor injection** — same pattern `#[AutoBind]` already uses. Eliminates F2/F5/F11/F14 in one move and brings PHPStan L8 narrowing back. Hardest fix, biggest payoff.
2. **Eliminate the static `LoggerFactory::create('cookie.events')` call in `registerEvents()`** (F3) — use the injected `logger` repository like the other methods do. Two-line fix, removes the testability/sed footgun on every cloned provider. Bonus: drop the hard-coded `'cookie.events'` channel and derive it via convention (F15).
3. **Add `registerProjections(ProjectionRegistry $registry): void` to the interface** (F4) AND **rename the reference domain off of `Cookie`** to a non-framework name (F7). The interface gap means cloners cannot follow the documented "register your projection from your provider" instruction; the naming collision with `Config\Cookie` means every cloner has to navigate ambiguous grep results forever.

---

**Severity counts:** CRITICAL 3 | HIGH 5 | MEDIUM 6 | LOW 4 | INFO 1
**Top finding:** Provider hard-codes its controllers namespace as a string literal AND silently undefined-index-faults on repository typos — a sed-clone leaves both as quiet landmines no PHPStan run can catch.
