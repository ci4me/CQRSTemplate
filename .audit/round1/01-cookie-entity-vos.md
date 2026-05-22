# 01 — Cookie entity + Value Objects

## Files audited
- `app/Domain/Cookie/Entities/Cookie.php`
- `app/Domain/Cookie/ValueObjects/CookieName.php`
- `app/Domain/Cookie/ValueObjects/CookiePrice.php`
- `app/Domain/Cookie/ErrorCodes.php`
- `app/Domain/Shared/AggregateRoot.php`
- `app/Domain/Shared/ValueObjects/Money.php`
- `app/Domain/Shared/ValueObjects/Currency.php`

## Findings

1. **CRITICAL — `app/Domain/Cookie/Entities/Cookie.php:236-241, 259-264`** — `CookieStockChangedEvent` is raised with `cookieId: $this->id` *before* a new entity is persisted. For a freshly `create()`d Cookie, `$this->id === null`, so `decreaseStock`/`increaseStock` invoked pre-save yields an event with `cookieId === null`. Event consumers (inventory dashboards, alerts) cannot route the event. Fix: either guard event raising on `$this->id !== null`, or defer events until after `assignId()` runs, or make the event carry the entity reference and let the repo stamp the id when draining. Cloning this footgun into every domain will silently corrupt event streams.

2. **CRITICAL — `app/Domain/Cookie/Entities/Cookie.php:195-207`** — `update()` mutates all five fields wholesale and raises **no event**. Compare with `decreaseStock`/`increaseStock` which do raise. The contract is inconsistent: a bulk update through `update()` will not produce `CookieStockChangedEvent` even though stock changed, will not produce a `CookieUpdatedEvent`, etc. The doc on `CookieStockChangedEvent.php:13-15` claims `CookieUpdatedEvent` is emitted by the bulk-replace path, but the entity does not raise it — that must be happening in the handler, which violates the aggregate-root principle (events should be raised by the aggregate, not the handler). Fix: have `update()` diff old/new state and raise the appropriate events itself. Otherwise every cloned entity will leak the same gap.

3. **CRITICAL — `app/Domain/Cookie/Entities/Cookie.php:84-96`** — Private constructor calls `$this->setStock($stock)` (a validator) but assigns `name`, `description`, `price`, `isActive` directly with no validation hook. There is no `setName/setPrice/setIsActive` invariant pipeline, and `update()` (line 195) bypasses `setStock`-style guards entirely except for stock. Pattern is asymmetric; a cloned domain that *does* need cross-field invariants (e.g. "price must be ≥ cost") has no canonical place to put them. Fix: introduce an `assertInvariants()` method called from constructor and `update()`.

4. **HIGH — `app/Domain/Cookie/Entities/Cookie.php:132-152`** — `reconstitute()` runs the constructor, which calls `setStock()`, which throws `ValidationException` if stock is negative. If a corrupted row exists in the DB with negative stock, the repo will be unable to rehydrate it (cannot even *see* the broken row to repair it). Reconstitution from persistence must be invariant-tolerant. Fix: bypass invariants in `reconstitute()` by setting `$cookie->stock = $stock` directly, or add a separate `forceSet` path. This is a footgun on every cloned entity.

5. **HIGH — `app/Domain/Cookie/Entities/Cookie.php:171-179`** — `assignId()` allows reassignment if `$this->id === null`, but refuses reassignment to a *different* id. It silently allows assigning the *same* id twice. Mostly harmless, but combined with finding #1, it means events fired between `create()` and `assignId()` are orphaned. Also: `assignId` is `public` and marked `@internal` only via DocBlock — nothing enforces it. Any application-layer caller can hijack identity. Fix: enforce visibility via package-private trait or a dedicated `EntityHydrator` interface.

6. **HIGH — `app/Domain/Cookie/Entities/Cookie.php:160-163`** — `bumpVersion()` is also `public` with `@internal` doc only. A handler could `$cookie->bumpVersion()` and defeat optimistic locking. Same fix as #5.

7. **HIGH — `app/Domain/Cookie/Entities/Cookie.php:286-300`** — `activate()` / `deactivate()` are idempotent state transitions but raise **no events**. Cloned domains will need "entity activated" / "entity deactivated" signals (audit log, search reindex, customer notifications). Also no guard against `deactivate()` on a deleted cookie. Fix: raise events; guard against `isDeleted()`.

8. **HIGH — `app/Domain/Cookie/Entities/Cookie.php:217-242`** — `decreaseStock` does not check `isDeleted()` or `isActive`. A soft-deleted cookie can still have its stock decreased, which is a business-rule contradiction (a deleted product shouldn't be selling). The doc at line 21-26 lists "deleted cookies are soft-deleted" but does not enforce read/write side-effects. Fix: guard `decreaseStock` with `if ($this->isDeleted()) { throw ... COOKIE_STATE_DELETED }`. `COOKIE_STATE_DELETED = 401` already exists in `ErrorCodes.php:42` but is never used.

9. **HIGH — `app/Domain/Cookie/ValueObjects/CookiePrice.php:190-203`** — `applyDiscount(float $discountPercent)` accepts a `float`, despite the file's own doc on lines 51-54 explicitly warning against floats at boundaries. `100.0` boundary case: `(100 - 100) / 100 = 0`, then `round(... * 0)` = `0`, then `Money::fromMinorUnits(0)` then the constructor calls `assertPositiveAndInRange(0)` which throws `tooSmall` — so a 100% discount throws an exception rather than returning a zero price. Whether 100% discount should be permitted is a business decision, but currently it's *silently* forbidden. Also, a discount that produces fractional cents is hard-rounded via `(int) round(...)`. Fix: take a typed `DiscountPercent` VO, decide explicit policy on 100% discount, and document rounding direction.

10. **HIGH — `app/Domain/Cookie/ValueObjects/CookiePrice.php:210-229`** — `assertPositiveAndInRange` does `$minorUnits / 100` to convert to a major-unit display in the error message. This is **hard-coded to 2-decimal currencies**. For JPY (0 decimals) or BHD (3 decimals), the displayed range is wrong by factors of 10–100. The CookiePrice class advertises itself as multi-currency (constructor accepts `?Currency`) but the bounds checking is monocurrency. Fix: divide by `10 ** $this->money->currency->decimals` or store bounds as Money. Cloned `OrderPrice`/`ProductPrice` will inherit this bug.

11. **HIGH — `app/Domain/Cookie/ValueObjects/CookiePrice.php:37-38`** — `MIN_MINOR_UNITS = 1` and `MAX_MINOR_UNITS = 999_999` are USD-cents semantics, but no currency is involved at construction time. A JPY ¥1,000,000 cookie (perfectly reasonable for, say, a luxury product) is rejected because it exceeds `999_999` minor units. Same problem as #10 but on the bounds themselves. Fix: per-currency bounds, or bounds expressed as decimal major units multiplied at runtime by `currency->decimals`.

12. **HIGH — `app/Domain/Cookie/ValueObjects/CookiePrice.php:55-68`** — `fromString()` accepts an optional `?Currency`. When `null` it falls through to `self::defaultCurrency()` which always returns `Currency::usd()` (line 237). This means a CSV import or HTTP boundary that *forgets* to pass a currency silently gets USD, and the price is interpreted in USD-cents bounds. For a multi-tenant ERP being cloned across domains, that is a data corruption magnet. Fix: make currency required, or have the default come from request/tenant context, not a hard-coded `usd()`.

13. **MEDIUM — `app/Domain/Cookie/Entities/Cookie.php:107-115`** — `Cookie::create()` accepts `bool $isActive = true`. The factory is called by `CreateCookieHandler` (presumably), but allowing `isActive = false` at creation time means "create an inactive cookie", which contradicts the lifecycle model (`activate()`/`deactivate()` exist as separate transitions). Fix: drop the parameter; new cookies are always active, and dedicated transitions move them inactive.

14. **MEDIUM — `app/Domain/Cookie/Entities/Cookie.php:309-312`** — `isAvailable()` returns `$this->isActive && $this->deletedAt === null && $this->stock > 0`. Three concerns wedged into one predicate is fine for now, but querying *why* something is unavailable from outside the entity is impossible — clients will reach for `getIsActive`/`getStock`/`getDeletedAt` and reimplement the logic. Fix: expose `whyUnavailable(): ?UnavailabilityReason` if cross-cutting consumers need this.

15. **MEDIUM — `app/Domain/Cookie/Entities/Cookie.php:336-379`** — Getter surface is large and grants raw access to `description` (`string`), `stock` (`int`), `isActive` (`bool`), `createdAt/updatedAt/deletedAt` (`string|null`). `description` is exposed as a primitive — there's no `CookieDescription` VO, so length / sanitation rules can't be enforced. `createdAt`/`updatedAt`/`deletedAt` are `string|null` (not `DateTimeImmutable`), which encourages stringy-typed handlers. Fix: introduce `CookieDescription` VO (or document the conscious decision to leave it primitive); type timestamps as `DateTimeImmutable`.

16. **MEDIUM — `app/Domain/Cookie/Entities/Cookie.php:67`** — `private int $version = 0;` is initialized to `0`. After persist + `bumpVersion()`, version becomes 1. But `reconstitute()` defaults `$version = 0` (line 142), so a row with no version column (legacy data) loads as `version=0`, and the first save's `WHERE version = 0` may match. Means the optimistic-locking guard fails open for legacy rows. Fix: load `1` as the floor or make `version` required in `reconstitute()`.

17. **MEDIUM — `app/Domain/Cookie/ValueObjects/CookieName.php:124-127`** — `equalsIgnoreCase` uses `strtolower` which is locale-broken for non-ASCII (Turkish dotted I, German ß, etc.). Real-world product catalogues with i18n names will mis-deduplicate. Fix: `mb_strtolower($value, 'UTF-8')`.

18. **MEDIUM — `app/Domain/Cookie/ValueObjects/CookieName.php:39-40`** — `MIN_LENGTH = 3`, `MAX_LENGTH = 100`. No whitelist of allowed characters; control characters, zero-width joiners, RTL overrides, emoji all accepted. For a name that ends up rendered in URLs / receipts / search, this is a footgun. Also no normalization (NFC). Fix: define a character policy; `Normalizer::normalize($name, Normalizer::FORM_C)`.

19. **MEDIUM — `app/Domain/Cookie/ValueObjects/CookiePrice.php:118-121`** — `getValue(): float` is `@deprecated` in docblock only. PHPStan can be made to flag deprecated calls; without `#[\Deprecated]` attribute (PHP 8.4) it's invisible to most tooling. Fix: add `#[\Deprecated]`.

20. **MEDIUM — `app/Domain/Cookie/ValueObjects/CookiePrice.php:128-131`** — `toString()` and `__toString()` both exist and both return decimal string. `format()` returns the currency-prefixed variant. Three near-identical methods with subtly different output is confusing in handlers. Fix: drop `toString()`; keep `__toString` + `format()`.

21. **MEDIUM — `app/Domain/Cookie/ValueObjects/CookiePrice.php:147-170`** — `equals`/`greaterThan`/`lessThan` delegate to `Money` which `assertSameCurrency` throws on mismatch. From a CookiePrice caller it looks like a pure comparison, but `$usdPrice->greaterThan($eurPrice)` throws `InvalidArgumentException`. Fine if intentional, but undocumented. The same applies to `add`/`subtract`/`applyDiscount`. Fix: document explicitly in DocBlocks that cross-currency operations throw.

22. **MEDIUM — `app/Domain/Shared/AggregateRoot.php:42-87`** — Trait, not abstract class. Two concrete drawbacks for an ERP being cloned 30+ times:
    - No way to enforce "every aggregate must implement `getId()`" at the type level (trait composition isn't a type).
    - `pullEvents()` clears the buffer; if the persist transaction *rolls back* after pullEvents has been called, events are lost. There's no compensating `restoreEvents(array $events)` API. Fix: convert to abstract base class or interface + trait, and add `restoreEvents` / "events are pulled after commit, not before".

23. **MEDIUM — `app/Domain/Shared/AggregateRoot.php:55-58`** — `raiseEvent(object $event)` accepts `object`, not a `DomainEventInterface`. Anything can be raised — `new \stdClass()` would silently work. Fix: declare a `DomainEventInterface` (even an empty marker) and constrain the parameter.

24. **LOW — `app/Domain/Cookie/Entities/Cookie.php:52`** — Class is `final`, good. But `Cookie` has no implements clause (`AggregateRootInterface`, `EntityInterface`). Domain services can't typehint "an aggregate" generically. Fix: introduce marker interfaces.

25. **LOW — `app/Domain/Cookie/Entities/Cookie.php:361-364`** — Getter named `getIsActive()` is awkward; convention is either `isActive()` (boolean accessor) or rename the property. Right now the property `isActive` clashes with method naming. Pick a style.

26. **LOW — `app/Domain/Cookie/ValueObjects/CookieName.php:53-72`** — Constructor is `private`, only `fromString` is exposed. There's no `fromTrustedSource` factory for reconstitution. For names already validated and stored in DB, repos still re-run validation on every load, which is wasted work for cold-path reads at scale (millions of rows). Fix: add a `fromTrustedString` factory that skips validation, used only by the repo.

27. **LOW — `app/Domain/Cookie/ValueObjects/CookiePrice.php:235-238`** — `defaultCurrency()` is `private static`, hard-codes USD. Tied to finding #12.

28. **LOW — `app/Domain/Cookie/ErrorCodes.php:25-49`** — `final class` of pure `public const` is fine, but a domain-namespace enum would be safer (the constants are `int`, so they can collide across domains: every other domain will define their own `*_VALIDATION_NAME = 101`). Cross-domain error log aggregation will be ambiguous unless ranges are partitioned per-domain. Fix: define a global numbering scheme (e.g., Cookie = 1xxx, Order = 2xxx) or use a per-domain enum.

29. **LOW — `app/Domain/Cookie/ErrorCodes.php:43, 38, 39, 42`** — Several codes are declared but never used in any of the audited files: `COOKIE_BUSINESS_RULE_INACTIVE` (302), `COOKIE_BUSINESS_RULE_NAME_DUPLICATE` (303), `COOKIE_STATE_DELETED` (401), `COOKIE_STATE_CONCURRENT_MODIFICATION` (402). Either wire them up (e.g., #8) or remove them — orphan codes drift out of sync with reality.

30. **LOW — `app/Domain/Cookie/Entities/Cookie.php:223`** — `$newStock = $this->stock - $quantity;` then `if ($newStock < 0)`. The error message says "decrease stock by %d when only %d available". Fine. But the same check would be more elegantly expressed as `if ($quantity > $this->stock)`. No behavioural bug; readability only.

31. **LOW — `app/Domain/Shared/ValueObjects/Money.php:170-173`** — `multiply(int $multiplier)` allows zero and negative multipliers without comment. `Money(amount=500) * 0 → Money(0)`; `* -1 → Money(-500)`. Acceptable, but `CookiePrice::multiplyBy` at line 184 *does* reject `<= 0`, and the methods have nearly the same name. Inconsistency invites bugs in cloned code. Fix: align semantics or rename one.

32. **LOW — `app/Domain/Shared/ValueObjects/Money.php:198-206`** — `cleanDecimalInput` strips a leading currency symbol but only the regex set `[\$£€¥]`. R$ (BRL), kr (SEK/NOK), ₹ (INR), ¢ are not stripped — they fall through to `fromDecimalString` and produce `invalidFormat`. For an ERP claiming multi-currency support, this is incomplete. Fix: strip by `Currency::symbol` of the target currency, not a magic regex.

## Cookie-as-template risks

If a developer runs `/add-domain Product` and copy-pastes the Cookie pattern verbatim, the following propagate:

- **Event ID corruption (Finding #1)**: every new domain will raise its state-change events with `null` id pre-persist. Every event consumer will mis-route.
- **Silent event omission on bulk update (Finding #2)**: every domain's `update()` becomes a black hole for stock/state/price-change events. Auditability gone.
- **Hard reconstitute failure on corrupted rows (Finding #4)**: any data drift makes the row unreadable. Every cloned domain inherits this brittleness.
- **Hard-coded USD + 2-decimal bounds (Findings #10, #11, #12)**: every domain's monetary VO will silently mishandle JPY, BHD, BRL real-world amounts. Catalogue, order, invoice domains all break simultaneously.
- **Locale-broken case-insensitive comparison (Finding #17)**: every "Name" VO mis-deduplicates non-ASCII text. Compounds in customer-facing search.
- **Public `assignId`/`bumpVersion` (Findings #5, #6)**: every domain leaks identity/version controls to handlers. Optimistic-locking guarantees are advisory only.
- **Trait-based aggregate (Findings #22, #23)**: every domain re-uses the same untyped event bag. No central enforcement of `DomainEventInterface`.
- **Error-code collisions (Finding #28)**: every domain redefines `*_VALIDATION_NAME = 101`. Centralized logging cannot disambiguate.
- **Orphaned/unused error codes (Finding #29)**: get copied to every new domain — dead code grows linearly with domain count.
- **Inconsistent invariant placement (Finding #3)**: every cloned entity will have an `update()` that bypasses validators except for stock.

These are not surface defects; they encode anti-patterns into the template at a structural level. Each new domain multiplies the maintenance cost.

## Verdict

**REJECT as canonical template.**

Cookie demonstrates the CQRS/DDD shape correctly at the surface level but has 5 CRITICAL/HIGH defects (Findings #1, #2, #3, #4, #8, #9, #10, #11, #12) that will be copy-pasted into every cloned domain. The currency/bounds handling in `CookiePrice` is the highest-risk area — it is mono-currency code wearing a multi-currency interface. The event-raising contract is inconsistent across mutation paths and breaks for unpersisted aggregates.

Recommended before any cloning:
1. Fix event-id null window (defer or stamp on drain).
2. Make `update()` diff state and raise events from the entity itself.
3. Move bounds + default currency to a per-currency / per-tenant policy.
4. Make `reconstitute()` invariant-tolerant.
5. Tighten visibility of `assignId` / `bumpVersion` / `raiseEvent`.
6. Introduce `DomainEventInterface` and `AggregateRootInterface`.
7. Replace `strtolower` with `mb_strtolower` everywhere.
8. Audit and prune `ErrorCodes`; partition numeric ranges across domains.
