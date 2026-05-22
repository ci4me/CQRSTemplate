# 02 — Cookie Value Objects

**Slice:** CookieName, CookiePrice, CookieStock — immutability, validation, factories
**Reviewer:** ddd-specialist
**Date:** 2026-05-22
**Source files reviewed:** 5 files (3 Cookie VOs + Money + Currency), ~675 lines

## TL;DR

CookieName and CookieStock are clean, idiomatic templates that will clone well. CookiePrice is the elephant in the room: it advertises multi-currency support via `Money`/`Currency` but pins USD-cents bounds onto every currency (1..999,999 minor units), and silently defaults to `Currency::default()` (env-driven, USD-fallback). A JPY catalogue capped at "9,999.99 in 2-decimal currencies" is just `¥1`..`¥999,999` — a meaningful price ceiling lost in translation. Round 2 V3 flagged this; current source is unchanged. Secondary issues: `CookieName::equals()` is case-sensitive while `equalsIgnoreCase()` exists for "uniqueness" — repository uniqueness semantics are therefore split between two methods. Several `applyDiscount`/`multiplyBy`/`assertPositiveAndInRange` exceptions omit the price error code or pass float dollars where int minor units would be unambiguous. No `jsonSerialize` on the Cookie VOs themselves (Money has one) means projections will inconsistently serialize price vs. name vs. stock.

## Verdict
READY-WITH-FIXES

## Findings

### F1 — CRITICAL — CookiePrice bounds are USD-cents semantics applied to every currency
- **Location:** `app/Domain/Cookie/ValueObjects/CookiePrice.php:22-23,195-214`
- **Observation:** `MIN_MINOR_UNITS = 1` and `MAX_MINOR_UNITS = 999_999` are interpreted "as cents" in the comment ("9,999.99 in 2-decimal currencies"), but `assertPositiveAndInRange()` compares raw `$money->amountMinor()` regardless of `Currency::decimals`. For JPY (0 decimals) the bounds become ¥1..¥999,999 — caps the catalogue at roughly $6,700 in yen. For BHD (3 decimals) the bounds become 0.001..999.999 BHD — caps it at roughly $2,650. The error messages also hard-code division by 100 (`$minorUnits / 100`) when emitting "tooSmall"/"outOfRange" — for JPY this reports a price of "10000" as "100.00".
- **Why this is a template defect:** Round 2 V3 flagged this; current source still shows the same defect. Every cloned domain that touches money will inherit a multi-currency-shaped wrapper around a single-currency-bounded VO and a single-currency-shaped error message. The wrapper promises portability that the validator silently breaks.
- **Suggested fix:** Express bounds as a `Money` minimum/maximum that themselves carry a currency (e.g. `MIN_PRICE = Money::fromMinorUnits(1, $currency)`; `MAX_PRICE = Money::fromDecimalString('9999.99', $currency)`), recomputed per-call from `$money->currency->decimals`. Emit error values via `Money::toDecimalString()` so JPY says "9999" and BHD says "9999.999".

### F2 — HIGH — `defaultCurrency()` is an implicit env-read that silently falls back to USD
- **Location:** `app/Domain/Cookie/ValueObjects/CookiePrice.php:42-52,70-73,81-84,220-223` and `Currency.php:103-114`
- **Observation:** All three CookiePrice factories accept `?Currency $currency = null` and fall through to `Currency::default()`, which reads `DEFAULT_CURRENCY` env then falls back to USD on any failure (missing, non-string, or invalid ISO shape). A developer cloning this for a domain whose pricing is intrinsically multi-currency (e.g. `OrderLine`, `Invoice`) will copy the same "null → env → USD" cascade without noticing.
- **Why this is a template defect:** Money was deliberately built to *require* currency at every factory ("an implicit USD default would silently convert 1500 yen into $15.00…"). CookiePrice undoes that guarantee one layer up. Every cloned domain that copies the CookiePrice pattern re-introduces the very footgun Money was hardened against.
- **Suggested fix:** Make `Currency` a required parameter on all three factories (`fromString`, `fromMinorUnits`, `fromFloat`). Force callers (controllers / DTOs) to obtain currency from request, settings, or aggregate context. If a domain-level "tenant default currency" exists, it should be injected explicitly via a `SettingsService` at the use-case boundary, not pulled from `getenv()` inside a VO.

### F3 — HIGH — CookieName equality semantics split between `equals()` and `equalsIgnoreCase()`
- **Location:** `app/Domain/Cookie/ValueObjects/CookieName.php:111-127`
- **Observation:** `equals()` is strict `===`. `equalsIgnoreCase()` lowercases both sides and re-trims the *argument* (but the receiver's value is already trimmed, so the second `trim` is dead). The docblock says `equalsIgnoreCase()` is "useful for checking uniqueness" — implying name uniqueness is case-insensitive, but the canonical `equals()` is case-sensitive. The repository's uniqueness check therefore must remember to call the secondary method, or invariants drift.
- **Why this is a template defect:** Value-object equality should be total and unambiguous. A cloner will inevitably copy `equals()` thinking it's authoritative and end up with two records named "Chocolate Chip" and "chocolate chip". The split also forces every consumer to make a casing decision.
- **Suggested fix:** Decide once. Either (a) normalize on construction (lowercase or case-fold via `mb_convert_case`) and drop `equalsIgnoreCase()`; or (b) keep display casing but make `equals()` case-insensitive and document the choice. Repository uniqueness should call `equals()`, full stop.

### F4 — HIGH — CookieStock factory has no maximum; `incrementBy` can overflow PHP_INT_MAX
- **Location:** `app/Domain/Cookie/ValueObjects/CookieStock.php:39-46,71-76`
- **Observation:** `fromInt()` only rejects negative values. `incrementBy()` does `$this->value + $quantity` with no upper bound. A malicious or buggy caller (e.g. an "import 1B" CSV) can either pass an arbitrarily large `$value` directly, or accumulate via repeated increments until 64-bit signed wraparound makes stock go negative — at which point `isOutOfStock()` returns false and `decrementBy` permits debits.
- **Why this is a template defect:** Money.php went out of its way to guard PHP_INT_MAX overflow (`fromDecimalString` lines 89-104 and `fromFloat` 133-138). Stock has the same risk and zero guard. Every cloned domain that copies CookieStock for `Inventory`, `OrderQuantity`, `Allocation` inherits a silent integer-wraparound vulnerability.
- **Suggested fix:** Define a `MAX_STOCK` constant (e.g. 1_000_000_000) and reject in both `fromInt()` and `incrementBy()` (after computing the sum). Tag the violation with `COOKIE_VALIDATION_STOCK`.

### F5 — MEDIUM — Asymmetric error codes across CookiePrice exceptions
- **Location:** `app/Domain/Cookie/ValueObjects/CookiePrice.php:160-167,57-68,193-213`
- **Observation:** `multiplyBy()` throws `ValidationException::tooSmall('quantity', 1, $quantity)` with **no error code**. `applyDiscount()` correctly passes `COOKIE_VALIDATION_PRICE`. `parseMoneyOrFail()` re-wraps Money's unannotated exception with the price code. The `assertPositiveAndInRange` floats values (`$minorUnits / 100`) into the exception's "actual" parameter, losing semantic precision and currency context.
- **Why this is a template defect:** The DDD skill explicitly requires error codes on every domain exception. A cloned VO with this pattern will produce some throws with codes and some without, breaking the error-code contract that handlers and the HTTP layer depend on for routing.
- **Suggested fix:** Add `COOKIE_VALIDATION_PRICE` (or a dedicated `COOKIE_VALIDATION_QUANTITY` code) to `multiplyBy`. Replace `$minorUnits / 100` arithmetic with `Money::toDecimalString()` so error context is currency-correct.

### F6 — MEDIUM — `getValue(): float` on CookiePrice is deprecated yet still serializable; no `jsonSerialize` on any Cookie VO
- **Location:** `app/Domain/Cookie/ValueObjects/CookiePrice.php:101-108`; absence of `JsonSerializable` on CookieName.php, CookiePrice.php, CookieStock.php
- **Observation:** `getValue(): float` is documented `@deprecated` but still returns `$money->amountMinor() / (10 ** $decimals)` — a float division that defeats the whole point of minor-units arithmetic. No Cookie VO implements `JsonSerializable`, so projections that JSON-encode a Cookie aggregate get inconsistent shapes: `Money` produces `{amount_minor, currency, formatted}` (good), `CookieName` becomes a plain string via `__toString` only if explicitly cast, `CookieStock` becomes `{"value":N}` from public-property reflection.
- **Why this is a template defect:** Projections and events are the "wire format" of CQRS. Every cloned VO with this pattern will serialize differently depending on whether `json_encode` decides to introspect public props, call `__toString`, or call `jsonSerialize`. Reads will drift.
- **Suggested fix:** Either remove `getValue(): float` entirely now (it's a stale legacy escape hatch) or replace with `getDecimalString(): string`. Implement `JsonSerializable` on each VO with an explicit array contract so every clone is forced to think about wire format.

### F7 — MEDIUM — `CookieName::equalsIgnoreCase()` accepts raw `string` — asymmetric public surface
- **Location:** `app/Domain/Cookie/ValueObjects/CookieName.php:124-127`
- **Observation:** Public API mixes "compare to another VO" (`equals(CookieName)`) and "compare to a raw string" (`equalsIgnoreCase(string)`). The string path bypasses VO validation (the argument doesn't have to be a valid CookieName), so two `equalsIgnoreCase()` calls with arguments that *would not* survive `fromString()` (e.g. `""`, `"ab"`, 200-char strings) silently return false instead of catching the bug.
- **Why this is a template defect:** Cloned domains will copy this dual-shape API, and developers will increasingly call the string variant because it's cheaper, leaking unvalidated user input deep into business logic.
- **Suggested fix:** Either accept `CookieName` only (force the caller to build the VO and surface validation errors at the boundary), or have `equalsIgnoreCase()` route through `self::fromString($name)` first.

### F8 — LOW — `CookieStock::$value` is `public` while Cookie* siblings use private + getter
- **Location:** `app/Domain/Cookie/ValueObjects/CookieStock.php:32-34` vs `CookieName.php:45,90-93` and `CookiePrice.php` (no public props)
- **Observation:** `CookieStock` uses promoted `public int $value`. `CookieName` uses private + `getValue()`. `CookiePrice` uses private with multiple getters. Three VOs in the same directory present three encapsulation styles. Cloners pattern-match the first one they see.
- **Why this is a template defect:** Convention inconsistency in the reference domain becomes convention inconsistency in every cloned domain. Style review noise multiplies.
- **Suggested fix:** Pick one (the readonly-public-prop style is fine for trivially-typed VOs, the private-getter style is fine when invariants exist). Apply it consistently to all three Cookie VOs and document the rule.

### F9 — LOW — `CookiePrice::format()` deprecation note routes callers to a Service the VO knows about
- **Location:** `app/Domain/Cookie/ValueObjects/CookiePrice.php:120-131`
- **Observation:** The `@deprecated` docblock references `\App\Domain\Cookie\Services\PriceFormatter::format()`. The VO is now coupled (in documentation) to a Service it doesn't import — a soft inversion of the dependency that cloners may not notice. The `format()` method also still works, accepting an optional symbol override that *bypasses* the currency's own symbol — a stealth way to render USD prices with a "€" prefix.
- **Why this is a template defect:** Templates with "deprecated but still-functional" footguns get cloned and left in place because nobody runs grep across a new domain. The "deprecated symbol override" path will outlive the deprecation tag.
- **Suggested fix:** Delete `format()` outright now — it's a thin wrapper a `PriceFormatter` service replaces — rather than leaving a deprecated convenience trapdoor on the reference template.

### F10 — INFO — `CookieStock::fromInt` is the only "from" factory and is named after the primitive, not the source
- **Location:** `app/Domain/Cookie/ValueObjects/CookieStock.php:39-46`
- **Observation:** Naming convention across the three VOs is mixed: `CookieName::fromString`, `CookiePrice::fromString` / `fromMinorUnits` / `fromFloat`, `CookieStock::fromInt`. "Stock" has only one factory and it's named after the PHP type rather than a semantic origin (e.g. `fromCount`, `fromQuantity`). This is the kind of micro-naming choice that gets cloned verbatim.
- **Why this is a template defect:** Sets the precedent that "type-named" factories are fine. Cloners will produce `CustomerAge::fromInt`, `OrderId::fromInt`, etc., which all look the same and lose meaning.
- **Suggested fix:** Rename to `fromQuantity()` or just `of()` for the canonical creator; keep `fromInt` only if a meaningful integer-source distinction emerges later.

## What is correct / praiseworthy

- All three VOs are `final readonly class` with private constructors and named factories — textbook DDD shape, easy to clone.
- `CookieName` normalizes via `trim()` and uses `mb_strlen` (not `strlen`), correctly handling multibyte names.
- `CookieStock::decrementBy` raises `DomainException::businessRuleViolation` with the dedicated `COOKIE_BUSINESS_RULE_STOCK_NEGATIVE` code — clean separation of "validation" vs "business rule" exceptions; a good example for cloners.
- `CookiePrice` correctly uses `Money` as a shared building block rather than re-implementing minor-unit arithmetic; the wrapping pattern is the right shape even though F1/F2 break its execution.
- `Money` itself (out of scope but read for context) is exemplary: requires currency at every factory, guards `PHP_INT_MAX` overflow on both `fromDecimalString` and `fromFloat`, refuses to mix currencies on add/subtract/compare. This is the kind of rigour Cookie's own bounds should inherit.
- Each VO has an `equals()` for value semantics; `CookieName` and `CookiePrice` also implement `__toString()`. Consistent enough to clone.
- Constructor exceptions throughout use `ErrorCodes::COOKIE_VALIDATION_*` constants — the contract is mostly held.

## Top 3 fixes before cloning

1. **Fix CookiePrice bounds to respect currency decimals (F1) and remove the implicit-default-currency cascade (F2).** Until these land, every cloned monetary VO will silently mis-bound JPY/BHD prices and silently coerce missing-currency inputs to USD. This is the single most important change because *every future domain with money will inherit it*.
2. **Pick one canonical name-equality semantics (F3) and one canonical encapsulation style across the three VOs (F8).** A cloner copies what they see; they should see one pattern, not three.
3. **Add an overflow guard to `CookieStock::fromInt` and `incrementBy` (F4) and a missing error code to `CookiePrice::multiplyBy` (F5).** Both are tiny diffs that close real correctness gaps and align the Cookie VOs with the rigour `Money` already demonstrates.
