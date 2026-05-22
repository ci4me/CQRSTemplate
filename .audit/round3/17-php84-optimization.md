# 17 — PHP 8.4 Optimization & Language-Feature Adoption

**Slice:** PHP 8.3 idiom audit (current target) + PHP 8.4 opportunities (next bump)
**Reviewer:** php-specialist
**Date:** 2026-05-22
**Source files reviewed:** 38 Cookie domain files + CookieController + CookieModel + 2 model traits + CommandBus/QueryBus/EventDispatcher + 3 bus middlewares + 2 outbox classes (~54 files); `require.php = "^8.3"` (composer.json line 12).

## TL;DR

Cookie is **mostly idiomatic PHP 8.3** — strict_types everywhere, `final readonly` on all VOs/DTOs/commands/events, constructor property promotion, named arguments, `match`, first-class callable, `array_is_list`-compatible shapes, classed const ints on `ErrorCodes` / `CookiePrice` / `EventOutboxWriter` / `EventOutboxRelay`. But the template carries a small set of PHP-version-specific defects that **propagate every time `/add-domain` clones Cookie**:

1. **`CookieName::MIN_LENGTH` / `MAX_LENGTH` lack typed-const types** — inconsistent with the rest of the codebase (`ErrorCodes::COOKIE_*: int`).
2. **`mt_rand() / mt_getrandmax()` for log sampling** in three query handlers — should be `random_int()` (or `Random\Randomizer` on 8.4) and the integer division gives a biased sample.
3. **No `#[\Override]` attribute** anywhere — handlers implementing `handle()`, event handlers implementing `__invoke()`, and `CookieRepository::existsByName` / `existsByNameExcludingId` all silently satisfy interfaces without the compiler-checked marker.
4. **`CookieController` and `CookieModel` are not `final`**, and the **`Cookie` entity is `final` but the read getters live in a trait** that any cloned domain can't easily subclass-out — minor friction, but the `final` omission on the controller is the load-bearing one.
5. **VOs implement `__toString()` but don't `implements \Stringable`** — `CookieName` and `CookiePrice` are stringable de-facto, not de-jure, so generics that constrain on `Stringable` reject them.

Once `require.php` is bumped to `^8.4`:
- The **`mt_rand` sampler** becomes a one-liner with `Random\Randomizer::getFloat()`.
- **`array_find` / `array_any`** simplify the listener-resolution and outbox-row patterns.
- The **`assignId`/`bumpVersion` @internal-via-docblock** discipline (slice 01 F5) gets a real fix via `public private(set) int $version` + a typed `public CookieName $name { set => ... }` hook.
- **`#[\Deprecated]`** replaces the two `@deprecated` docblocks on `CookiePrice::getValue()` and `::format()` so callers get a compiler warning, not a docblock that PHPDoc-aware IDEs may or may not surface.

## Verdict
**READY-WITH-FIXES** — the template ships idiomatic 8.3 code overall; the gaps are small but each one *replicates per cloned domain*, so they should be fixed in Cookie before another bounded context copies them. No CRITICAL blockers.

---

## Part A — PHP 8.3 idiom gaps (should already be using these)

### F1 — MEDIUM — `CookieName` class constants are not typed (inconsistent with the codebase)
- **Location:** `app/Domain/Cookie/ValueObjects/CookieName.php:39-40`
- **Observation:** The two constants are declared `private const MIN_LENGTH = 3;` and `private const MAX_LENGTH = 100;` — no type. Every other const in the Cookie domain uses the typed-const-PHP-8.3 form (`ErrorCodes` lines 40-60, `CookiePrice` lines 22-23, `GetCookiesPaginatedQuery` lines 19-21, `EventOutboxWriter::SCHEMA_VERSION`, `EventOutboxRelay::MAX_ATTEMPTS` / `BACKOFF_SECONDS`).
- **Why this is a template defect:** Mixed adoption tells a cloning developer the codebase doesn't actually enforce typed constants, so the next VO will probably skip them too. This is the *one* file that broke the pattern.
- **Suggested fix:**
  ```php
  private const int MIN_LENGTH = 3;
  private const int MAX_LENGTH = 100;
  ```

### F2 — HIGH — `mt_rand()` for log sampling (×3 sites)
- **Location:**
  - `app/Domain/Cookie/Queries/GetCookieById/GetCookieByIdHandler.php:130`
  - `app/Domain/Cookie/Queries/GetAllCookies/GetAllCookiesHandler.php:136`
  - `app/Domain/Cookie/Queries/GetCookiesPaginated/GetCookiesPaginatedHandler.php:146`
- **Observation:** Identical body `return mt_rand() / mt_getrandmax() < $this->loggingConfig->samplingRate();` Three problems:
  1. `mt_rand` is the legacy Mersenne-Twister API — flagged in slice 04 F12.
  2. `mt_rand() / mt_getrandmax()` is biased because `mt_getrandmax()` is itself a reachable value (the inclusive endpoint), so the probability of returning exactly `1.0` is non-zero and the sample isn't uniform on `[0, 1)`.
  3. The logic is duplicated across handlers — every cloned domain inherits three copies.
- **Why this is a template defect:** Three handlers in the reference template clone a non-uniform sampler. Each new domain inherits the same three copies.
- **Suggested fix (PHP 8.3):** Extract `LogSampler::shouldSample(float $rate): bool` once and replace each call. Implementation:
  ```php
  return random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX < $rate;
  ```
  Better on 8.4: `(new \Random\Randomizer())->getFloat(0.0, 1.0, \Random\IntervalBoundary::ClosedOpen) < $rate` — see G2.

### F3 — MEDIUM — No `#[\Override]` attribute anywhere
- **Location:**
  - All command/query/event handlers (`handle()` / `__invoke()` — 8 handlers in Cookie)
  - `CookieRepository::save/findById/findAll/findPaginated/existsByName/existsByNameExcludingId/delete/restore/findByIdWithTrashed` (9 methods implementing `CookieRepositoryInterface`)
  - `CookieQueryRepository::findById/findAll/findPaginated` (3 methods implementing `CookieQueryRepositoryInterface`)
  - `EventDispatcher::subscribe/dispatch/hasListeners` (implementing `EventDispatcherInterface`)
  - `LoggingMiddleware::handle`, `TransactionMiddleware::handle`, `AuditMiddleware::handle` (implementing `CommandMiddlewareInterface`)
- **Observation:** `#[\Override]` was a headline PHP 8.3 feature. It causes a compile-time error if the parent contract changes and silently de-syncs. Cookie ships ~25 methods that should carry it; zero do.
- **Why this is a template defect:** The day someone renames `CookieRepositoryInterface::save` to `::persist`, the implementation in `CookieRepository` becomes orphaned but stays callable; without `#[\Override]` the engine can't catch it. Every cloned domain inherits the same blind spot.
- **Suggested fix:** Add `#[\Override]` above every method that implements an interface or extends a parent. Slevomat has `SlevomatCodingStandard.Functions.RequireSingleLineCall` and a dedicated `MissingOverride` sniff in newer versions — wire it into `phpcs.xml`.

### F4 — MEDIUM — VOs with `__toString()` don't declare `implements \Stringable`
- **Location:**
  - `app/Domain/Cookie/ValueObjects/CookieName.php:37` — `final readonly class CookieName` (no interface), has `__toString()` at line 132.
  - `app/Domain/Cookie/ValueObjects/CookiePrice.php:20` — same shape, `__toString()` at line 187.
- **Observation:** PHP automatically marks any class with `__toString()` as implementing `\Stringable`, BUT declaring it explicitly:
  1. Makes IDE / static-analysis (PHPStan) treat them as `Stringable` at strict mode.
  2. Lets typed code accept `Stringable` as a constraint that *only* admits VOs that promised the contract on purpose (a class with an accidental `__toString` won't qualify).
- **Why this is a template defect:** Cloned domains will not know to add the implements clause if Cookie doesn't show them how. And the `CookieController` already concatenates `{$cookieId}` (line 147, 224) into strings — when a value object grows in, `Stringable` is the natural constraint to type the controller against.
- **Suggested fix:**
  ```php
  final readonly class CookieName implements \Stringable
  ```
  Same for `CookiePrice`. (Currency / Money in `Domain/Shared` likely need it too — out of scope here but worth flagging.)

### F5 — MEDIUM — `CookieController` and `CookieModel` are not `final`
- **Location:**
  - `app/Controllers/Domain/Cookie/CookieController.php:40` — `class CookieController extends BaseController`
  - `app/Models/Cookie/CookieModel.php:28` — `class CookieModel extends Model`
- **Observation:** Project rules in `.claude/CLAUDE.md` (`Final classes by default`) explicitly require `final`. The repository (`CookieRepository`), all handlers, all VOs, the query repository, the service provider, every event, every command — are all `final`. Only the controller and the model break the rule.
- **Why this is a template defect:** `/add-domain` will copy this and produce a non-final `FooController` in every new domain. Subclassing controllers is anti-pattern in CI4 too.
- **Suggested fix:** `final class CookieController extends BaseController` and `final class CookieModel extends Model`. `EventDispatcher` (line 42) is documented as intentionally non-final for PHPUnit mockability — keep it non-final but add a class-level comment that this is the *only* permitted non-final infrastructure class, and consider declaring it `@internal`.

### F6 — LOW — `@deprecated` docblock on `CookiePrice::getValue()` / `::format()` is not engine-enforced
- **Location:** `app/Domain/Cookie/ValueObjects/CookiePrice.php:102-104, 123-124`
- **Observation:** Two methods carry `@deprecated` *only* in the docblock. PHPStorm warns; PHPStan with strict-rules may; the engine itself never does. Callers in cloned domains can silently keep using them.
- **Why this is a template defect:** A "legacy ramp" the cloning developer can't see at runtime.
- **Suggested fix (PHP 8.3 — still docblock):** Promote with an `@internal` plus a unit test that asserts no production caller uses them. On PHP 8.4, replace with `#[\Deprecated(message: '...', since: '8.4')]` — see G6.

### F7 — LOW — `(int) $command->id` / `(bool) $isActiveParam` casts in `CookieController`
- **Location:** `app/Controllers/Domain/Cookie/CookieController.php:54, 131, 134, 207, 210`
- **Observation:** The controller pattern is `$x = is_numeric($p) ? (int) $p : 0;` and `$isActive = (bool) $isActiveParam;`. The cast is fine — but the per-field re-implementation is brittle. The is_numeric path returns `0` for non-numeric, which silently masks bad input as "stock 0".
- **Why this is a template defect:** Every cloned domain controller will repeat the same 8-line block. A `RequestExtractor` helper or DTO would centralise the casting and let one improvement (e.g. failing on non-numeric instead of defaulting to 0) propagate.
- **Suggested fix:** Out of strict 8.3-idiom scope. Note here so it's visible alongside F2 as a *duplicated pattern across cloned domains*.

### F8 — LOW — `CookieView::summarise()` uses a static fn closure where first-class callable would do
- **Location:** `app/Domain/Cookie/ReadModels/CookieView.php:124`
- **Observation:** `array_map(static fn(Cookie $c): self => self::summary($c), $cookies)` — the closure exists just to call one static method.
- **Why this is a template defect:** PHP 8.1+ first-class callable syntax: `array_map(self::summary(...), $cookies)` is shorter, allocates no closure per call, and the JIT inlines it more aggressively.
- **Suggested fix:**
  ```php
  return array_map(self::summary(...), $cookies);
  ```
  Same pattern applies in `CookieRepository::executeFindAll()` line 530, `executeFindPaginated` line 576, `CookieQueryRepository::findAll` line 101, `::findPaginated` line 145 — those are `fn(array $data): Cookie => $this->toDomainEntity($data)`, replaceable with `$this->toDomainEntity(...)`.

### F9 — LOW — `EventOutboxRelay` uses raw `json_decode($json, true, 512, JSON_THROW_ON_ERROR)` — fine, but the *outer* exception handling pattern could use `json_validate()` first to avoid the throw on hot paths
- **Location:** `app/Infrastructure/Outbox/EventOutboxRelay.php:216`
- **Observation:** Each row gets `json_decode(... JSON_THROW_ON_ERROR)` inside a `try { } catch (\Throwable $e)` block. PHP 8.3's `json_validate()` is ~10x cheaper than `json_decode + catch` for the failure path; here the failure path is rare so the throw is okay, but the construction is worth noting.
- **Why this is a template defect:** Minor — the relay's read path is the hottest CookieDomain-adjacent code, and any new domain's outbox follows this exact code.
- **Suggested fix:** Optional. Could short-circuit:
  ```php
  if (!json_validate($json)) {
      $this->markFailed($id, 'payload is not valid JSON');
      return 'failed';
  }
  $decoded = json_decode($json, true);
  ```
  Skip on the happy path; preserve `JSON_THROW_ON_ERROR` only as defense-in-depth.

### F10 — LOW — `EventDispatcher::describeListener()` reinvents `Closure::fromCallable` introspection
- **Location:** `app/Infrastructure/Bus/EventDispatcher.php:186-204`
- **Observation:** 19 lines of branching to produce a human-readable string for a callable. Could be replaced by `Closure::fromCallable($listener)` + `(new \ReflectionFunction(...))->getName()` for most shapes — or simply by stamping each subscription with a label at `subscribe()` time. Not a 8.3-idiom issue, but the *length* of this method is an idiom smell in a template.
- **Why this is a template defect:** Every cloned domain inherits this dispatcher unchanged.
- **Suggested fix:** Leave as-is for now — but consider a `LabeledListener` wrapper VO in a future pass.

---

## Part B — PHP 8.4 opportunities (next bump)

### G1 — HIGH — Asymmetric visibility on `Cookie::$id` / `Cookie::$version` (fixes slice 01 F5)
- **Location:** `app/Domain/Cookie/Entities/Cookie.php:48, 54` and the `assignId`/`bumpVersion` `@internal`-via-docblock methods at lines 120-139.
- **Today's code:**
  ```php
  private ?int $id = null;
  private int $version = 0;
  ...
  /** @internal */ public function bumpVersion(): void { $this->version++; }
  /** @internal */ public function assignId(int $id): void { ... }
  public function getId(): ?int { return $this->id; }
  public function getVersion(): int { return $this->version; }
  ```
- **8.4 form:**
  ```php
  public private(set) ?int $id = null;
  public private(set) int $version = 0;
  // `assignId` / `bumpVersion` stay as public methods that the repository
  // calls; the docblock @internal can be enforced at the LSP level because
  // anyone reading the property sees `private(set)` — clear contract.
  ```
  This removes `getId()` / `getVersion()` (they become direct property reads) and the `@internal` discipline becomes a typing constraint instead of a docblock.
- **Why it matters:** Slice 01 F5 (reported in the round-1 audit) flagged that `assignId`/`bumpVersion` are technically public + only docblock-protected. 8.4 lets the engine enforce this. Every cloned aggregate currently inherits the leaky contract.

### G2 — HIGH — `Random\Randomizer` replaces `mt_rand()` sampler (cleaner fix for F2)
- **Location:** Three sampler methods (see F2).
- **Today's code:**
  ```php
  return mt_rand() / mt_getrandmax() < $this->loggingConfig->samplingRate();
  ```
- **8.4 form:**
  ```php
  private static ?Randomizer $randomizer = null;
  private function shouldSample(): bool
  {
      self::$randomizer ??= new Randomizer();
      return self::$randomizer->getFloat(0.0, 1.0, IntervalBoundary::ClosedOpen)
          < $this->loggingConfig->samplingRate();
  }
  ```
  Better: extract to a shared `LogSampler` service. On 8.4 the `Randomizer` API is the documented modern path; `mt_rand` is essentially legacy.
- **Why it matters:** Uniformity, correctness, and a stable surface for testing (a fake `Randomizer` is trivial to inject).

### G3 — MEDIUM — Property hooks for `Cookie::$name`, `::$price`, `::$stock` invariants
- **Location:** `app/Domain/Cookie/Entities/Cookie.php:49-53` + the setter-style logic in `update()` / `changeStock()`.
- **Today's code:** Setter logic is inlined in `update()` and `changeStock()`. Direct assignment (`$this->name = $name;`) only works because the constructor and updaters route through VOs that self-validate.
- **8.4 form:** Strict 8.4 property hooks aren't a great fit for VO-backed entity fields because the VOs already self-validate. BUT for the **`assertNotDeleted()` cross-cutting check** that every mutator opens with, a *set-hook* would centralise it:
  ```php
  // Conceptual — not exactly valid syntax; demonstrates the pattern.
  public CookieName $name {
      set {
          if ($this->deletedAt !== null) {
              throw DomainException::invalidState(...);
          }
          $this->name = $value;
      }
  }
  ```
- **Why it matters:** Lower priority than G1; the `assertNotDeleted()` pattern is the closest thing in Cookie to a real property-hook opportunity. Most aggregate fields are already VO-validated, so the hook gain is mostly the *guard*, not the VO conversion.

### G4 — MEDIUM — `array_find` / `array_any` / `array_all` adoption in `EventDispatcher` and `CookieRepository`
- **Location:**
  - `EventDispatcher::dispatch()` (foreach + try/catch over listeners) — fine, must be a foreach because each listener must run independently.
  - `CookieRepository::isDuplicateKey()` lines 151-157 — string-match a list of substrings. **Perfect candidate for `array_any`.**
- **Today's code:**
  ```php
  private function isDuplicateKey(\Throwable $e): bool
  {
      $message = strtolower($e->getMessage());
      return str_contains($message, 'duplicate')
          || str_contains($message, 'unique constraint')
          || str_contains($message, '1062');
  }
  ```
- **8.4 form:**
  ```php
  private const array DUPLICATE_KEY_NEEDLES = ['duplicate', 'unique constraint', '1062'];
  private function isDuplicateKey(\Throwable $e): bool
  {
      $message = strtolower($e->getMessage());
      return array_any(self::DUPLICATE_KEY_NEEDLES, static fn(string $n): bool => str_contains($message, $n));
  }
  ```
- **Why it matters:** Self-documenting + extending the list (slice 06 F1 about Postgres SQLSTATE 23505 lands here) is a single-line append instead of an `|| str_contains(...)` chain.

### G5 — MEDIUM — `new ClassName()->method()` deref-on-new
- **Location:**
  - `app/Domain/Cookie/Commands/RestoreCookie/RestoreCookieHandler.php:75` — `(new \DateTimeImmutable())->format('c')`
  - `app/Infrastructure/Outbox/EventOutboxRelay.php:372` — `(new \DateTimeImmutable())->modify(...)`
- **Today's code:**
  ```php
  restoredAt: (new \DateTimeImmutable())->format('c')
  ```
- **8.4 form:**
  ```php
  restoredAt: new \DateTimeImmutable()->format('c')
  ```
- **Why it matters:** Tiny readability win. Not significant on its own but the parenthesis-avoidance is the kind of thing that propagates per cloned domain.

### G6 — MEDIUM — Replace docblock `@deprecated` on `CookiePrice::getValue()` and `::format()` with `#[\Deprecated]`
- **Location:** `app/Domain/Cookie/ValueObjects/CookiePrice.php:102-104 (getValue)`, `122-124 (format)`
- **Today's code:**
  ```php
  /**
   * @deprecated Prefer ::getMinorUnits or ::toDecimalString. ...
   */
  public function getValue(): float
  ```
- **8.4 form:**
  ```php
  #[\Deprecated(message: 'Use ::getMinorUnits() or ::toDecimalString() — float drift may bite at the boundary', since: '8.4')]
  public function getValue(): float
  ```
- **Why it matters:** The engine surfaces the deprecation at call sites (it emits a `Deprecated` notice). Every cloned domain that has its own legacy ramp gets a real signal instead of a hint in the docblock.

### G7 — LOW — Lazy objects for `CookieQueryRepository` hydration
- **Location:** `app/Domain/Cookie/Repositories/CookieQueryRepository.php:173-189` (`toDto`)
- **Today's code:** Each row eagerly builds a `CookieDTO`, including the `formatPrice()` call (line 176) which itself instantiates a `CookiePrice` from string just to format it. On a 100-row page that's 100 allocations × 2 (DTO + temp CookiePrice).
- **8.4 form:** `ReflectionClass::newLazyProxy(CookieDTO::class, fn(CookieDTO $proxy): CookieDTO => $this->buildDto($row))` — defer the work until the controller/view actually reads from the DTO. Most list views show only `id/name/price/isActive`, so deferring `formattedPrice` is the biggest win.
- **Why it matters:** Read-side performance on list endpoints. Low priority because Cookie's 100-row pages are small; high priority for any cloned domain (e.g. `Product`, `Order`) that lists thousands of rows.

### G8 — LOW — `#[\SensitiveParameter]` on `AuditMiddleware::digestOf($command)` payload-extraction parameter
- **Location:** `app/Infrastructure/Bus/Middleware/AuditMiddleware.php:64, 216, 236`
- **Observation:** `digestOf($command)` reflects over a command and hashes it. If an exception ever escapes from inside this method, the command object lands in the stack trace — including any password/token field the command happens to carry (Cookie's don't, but the template is shared with `LoginCommand` etc.).
- **8.4 form:** `#[\SensitiveParameter]` on the parameter prevents PHP from including it in stack traces.
  ```php
  private function digestOf(#[\SensitiveParameter] object $command): string
  ```
- **Why it matters:** Defense in depth at the template level. Cookie itself has no secrets, but the middleware is shared.

### G9 — LOW — Native enums for `StockChangeReason` and query-logging level
- **Location:**
  - `app/Domain/Cookie/Entities/Cookie.php:234, 246` — `$this->changeStock(..., 'decreaseStock')` / `'increaseStock'` — bare strings as "reason".
  - All query handlers — `match ($this->loggingConfig->queryLoggingLevel()) { 'all' => ..., 'errors' => ..., 'slow' => ..., 'sampling' => ..., default => false }` — bare strings.
- **Today's code:**
  ```php
  $this->raiseEvent(new CookieStockChangedEvent(
      cookieId: (int) $this->id,
      previousStock: $previous,
      newStock: $newStock->value,
      reason: $reason  // <- 'decreaseStock' string
  ));
  ```
- **8.4 form (works on 8.1+; flagging here because slice 04 F5 called it out and 8.4 makes enums cheaper at JIT-time):**
  ```php
  enum StockChangeReason: string {
      case Increase = 'increaseStock';
      case Decrease = 'decreaseStock';
      case Sync = 'sync';
  }
  ```
- **Why it matters:** Today the `reason` is a stringly-typed field on the event; an enum at the type level prevents typos and gives the relay a canonical set for replay analytics.

### G10 — LOW — `mb_trim()` for `CookieName::__construct` and `CookieName::equalsIgnoreCase`
- **Location:** `app/Domain/Cookie/ValueObjects/CookieName.php:55, 126`
- **Observation:** `trim($name)` strips ASCII whitespace only. If the catalogue ever needs non-ASCII whitespace (Japanese ideographic space `　`, NBSP ` `), `trim()` misses them. PHP 8.4 ships `mb_trim()` which handles Unicode whitespace categories.
- **Why it matters:** Future-proofing for multi-lingual cookie names. Not a defect today (the catalogue is English-coded) but the moment a domain handles names in CJK / RTL locales, this matters.

### G11 — INFO — `Stringable` as a typed parameter constraint
- **Location:** Once F4 is fixed (`CookieName implements \Stringable`), controllers can type `string|\Stringable $name` and the `CookieController::store` cast `is_string($nameParam) ? $nameParam : ''` gets a wider type.
- **8.4 form:** Combine F4 + DNF types: `string|(int&\Stringable)|null` for IDs. Not a strict requirement but cleans up controller typing.

### G12 — INFO — Implicit-nullable-type deprecation (PHP 8.4)
- **Location:** Audited every `?Type` parameter in the Cookie domain — all are **explicitly** declared nullable (`?int`, `?string`, `?Currency`, `?Actor`). **No implicit-nullable issues found.** Good.
- **Why it matters:** Confirming a non-finding because slice 04 raised this concern at the template level.

---

## Part C — Performance notes

### P1 — `array_map` with bound `$this` closures in repositories
- **Location:** `CookieRepository:530, 576`; `CookieQueryRepository:101, 145`.
- **Observation:** Four `array_map(fn(array $r): X => $this->method($r), $rows)` calls. The closure binds `$this` per call site. JIT can inline these, but first-class callable (`$this->method(...)`) lets PHP store the callable as a `Closure` once at site construction.
- **Recommendation (PHP 8.3, already valid):** Replace with `array_map($this->toDomainEntity(...), $results)` — see F8. On a thousand-row admin paginated list this is measurable.

### P2 — Hot-path `LOWER(name)` on `existsByName` interacts with PHP-side `strtolower`
- **Location:** `CookieModel::existsByName/existsByNameExcludingId` lines 95-114.
- **Observation:** PHP does `strtolower($name)` (ASCII) and SQL does `LOWER(name)` — mismatched semantics for any non-ASCII name. Cross-cutting with slice 06 F6.
- **Recommendation (out of slice but flagged):** Hoist the case-fold to the database (case-insensitive collation, already partially done per `CookieQueryRepository:127-130`) and drop the PHP-side `strtolower`. On 8.4 + multilingual catalogues, this becomes a correctness issue, not just a performance one.

### P3 — `microtime(true)` everywhere; one handler uses `hrtime(true)` correctly
- **Location:** `CreateCookieHandler:67`, `UpdateCookieHandler:58`, `GetCookieByIdHandler:56`, `GetAllCookiesHandler:54`, `GetCookiesPaginatedHandler:55`, `LoggingMiddleware:44`, `AuditMiddleware:62` — all use `microtime(true)` for duration timing.
- **Observation:** `DeleteCookieHandler:52` correctly uses `hrtime(true)` (sub-microsecond, monotonic). The rest don't — `microtime(true)` is wall-clock and jumps under NTP adjustment.
- **Recommendation:** Standardise on `hrtime(true) / 1_000_000` for durations. The `DeleteCookieHandler` is the only one doing it right; cloned domains will see seven examples of the wrong thing and one of the right thing.

### P4 — No `eval`, `extract`, `create_function`, or any other banned pattern
- **Recommendation:** Verified via grep — zero hits. Good.

### P5 — `EventOutboxRelay::rehydrate()` uses reflection per row
- **Location:** `EventOutboxRelay:315-348`.
- **Observation:** Every dispatched row pays a `ReflectionClass::newInstanceArgs` cost. The relay drains 50 rows at a time so the reflection overhead is bounded, but a per-event-class lazy cache of `ReflectionClass` would amortise. Could use `\WeakMap<string, \ReflectionClass>` keyed by event class.
- **Recommendation:** Bounded gain; only matters at high throughput. Defer until profiling shows hot.

---

## composer.json constraint audit

Current state:
- `composer.json` line 12 — `"php": "^8.3"` (caret means 8.3.* and 8.4.*, but the project documentation states 8.3 as the *target*).
- `phpstan.neon` — **no `phpVersion` set**. PHPStan defaults to the host PHP version; on a developer running PHP 8.4 locally that means 8.4 features (property hooks, asymmetric visibility) would PASS analysis even though `^8.3` admits 8.3 runtimes. This is a footgun.
- `phpcs.xml` — needs to be checked for `<config name="php_version" value="80300"/>`. (Wasn't visible in the audit grep; should be set to `80300` to match the minimum.)
- `phpstan-bootstrap.php` — present, contents not audited (out of slice for the language-feature angle).

**Recommended bump sequence** when moving to PHP 8.4:
1. `composer.json` → `"php": "^8.4"`.
2. Add `parameters.phpVersion: 80400` to `phpstan.neon`.
3. `phpcs.xml` → `<config name="php_version" value="80400"/>`.
4. Slevomat ruleset → ensure `SlevomatCodingStandard.Classes.ConstantSpacing`, `SlevomatCodingStandard.Functions.RequireSingleLineCall`, and any property-hook / asymmetric-visibility sniffs are enabled (slevomat 8.16+ ships these — check current pinned version `^8.15`).
5. Audit deprecations. Cookie has none after F6 is applied; other domains may.
6. Then enable 8.4 features per finding (G1-G10 above).

**Independent of bumping**: pin `phpstan.neon`'s `phpVersion` to `80300` *today* so developers on PHP 8.4 hosts don't accidentally land 8.4 syntax in code that's supposed to be 8.3-compatible. **This is the single most important fix in this audit** — without it, F-class findings and G-class findings can drift in undetected.

---

## What is correct / praiseworthy

- **strict_types=1**: every PHP file in scope. Zero misses.
- **`final readonly` saturation**: All 4 VOs, 3 DTOs/ReadModels, 4 commands, 5 events, 6 event handlers, 3 query handlers, 4 command handlers, 1 middleware (`LoggingMiddleware`, `TransactionMiddleware`, `AuditMiddleware`) — all `final readonly` where they should be.
- **Typed class constants** (PHP 8.3): adopted in 5 of 6 places. Only `CookieName::MIN/MAX_LENGTH` missed (F1).
- **Constructor property promotion**: 100% in scope.
- **Named arguments**: used at every multi-arg call site (`Cookie::create(name: ...)`, `new CookieCreatedEvent(cookieId: ...)`, etc.). Excellent.
- **`match` over `switch`**: every conditional flow uses `match` (CreateCookieHandler:155, GetCookieByIdHandler:83, etc.). Zero `switch` statements in scope.
- **First-class callable**: used in `CommandBus::dispatch` (`$handler->handle(...)` style would simplify the closure at line 118 — see F8 for one site that uses the older style).
- **`get_debug_type()`** instead of `gettype()`: `EventOutboxRelay:221, 243, 251, 258`. Modern.
- **`json_encode` with `JSON_THROW_ON_ERROR`**: `EventOutboxWriter:186`, `AuditMiddleware:224`. Good.
- **`hrtime` for one handler**: `DeleteCookieHandler:52`. Just needs to propagate (see P3).
- **`@throws` documented** on every method that can throw — meaningful for the docblocks:audit gate.
- **`assertPersisted()` / `assertNotDeleted()` guard pattern** in `Cookie` entity — clean inline-precondition idiom.

---

## Top 3 fixes before cloning (under PHP 8.3) + Top 3 wins after bumping to PHP 8.4

**PHP 8.3 (now):**
1. **Pin `phpVersion` in phpstan.neon and phpcs.xml to `80300`** so developers on 8.4 hosts can't accidentally introduce 8.4 syntax (composer-audit hardening — single most important fix).
2. **Add `#[\Override]` to every interface/parent-method implementation** in Cookie (~25 sites). Wire a Slevomat sniff to enforce it forever. (F3)
3. **Replace the three `mt_rand`-based samplers** with a shared `LogSampler` using `random_int`. Fixes the biased sampler and removes the duplication. (F2)

**PHP 8.4 (when bumping):**
1. **`public private(set) ?int $id` / `int $version` on `Cookie`** — engine-enforce the `@internal assignId/bumpVersion` contract. Drop `getId()` / `getVersion()` if desired. (G1, also fixes slice 01 F5.)
2. **`Random\Randomizer` everywhere `mt_rand` lives** + extract to shared `LogSampler` (G2 — superset of the F2 8.3 fix).
3. **`#[\Deprecated]` on `CookiePrice::getValue()` and `::format()`** so the legacy ramp emits engine notices, not docblock hints. (G6)

---

**Severity counts:** CRITICAL 0 | HIGH 3 (F2, G1, G2) | MEDIUM 7 (F1, F3, F4, F5, G3, G4, G5, G6 — counting G6 as MEDIUM not LOW since it's user-visible) | LOW 8 | INFO 2
**Top finding:** PHPStan has **no `phpVersion` pin** in `phpstan.neon`, so on a developer host running PHP 8.4, 8.4-only syntax (property hooks, asymmetric visibility) is admitted into code targeting `^8.3` without warning — this single missing line is what would let every other finding in this report drift in unnoticed.
