# RE-AUDIT — Slice 02 — Cookie Value Objects

**Reviewer:** ddd-specialist
**Date:** 2026-05-23
**PRs reviewed:** #39, #41
**Original slice:** `.audit/round3/02-value-objects.md`

## Summary

Of the original 10 findings, **none are fully closed in source** at HEAD. PR #39
(E17) and PR #41 (E11) were merged but their advertised effects on the VO files
are not visible in the current tree:

- **PR #39 advertised:** `Stringable` on `CookieName` + `CookiePrice`,
  `CookieStock::$value` flipped from `public` to `private`, typed consts on
  `CookieName`.
  - **Actual state:** `CookieName.php` does **not** `implements Stringable`
    (line 37: `final readonly class CookieName`) and its consts on lines 39-40
    are still untyped (`private const MIN_LENGTH = 3;`). `CookiePrice.php` does
    **not** `implements Stringable` (line 20). `CookieStock::$value` is still
    `public int $value` (line 32). The only PR #39 effect that landed is the
    typed const pair on `CookiePrice.php:22-23`
    (`private const int MIN_MINOR_UNITS = 1;`).
- **PR #41 advertised:** `CookieName::fromTrusted` factory.
  - **Actual state:** `CookieName.php` exposes only `fromString` (line 80). No
    `fromTrusted` method exists. Either the PR landed in a different file (e.g.
    a repository hydrator) or the change reverted.

Net: the v3 plan's expectation that E09 still owns F1/F2/F3 holds; nothing in
E17/E11 should have touched them and (with the caveat above) nothing did. The
flagged regression risk on F8 from E17 did NOT materialise because E17's
encapsulation change is not present in source.

## Closure matrix

| F# | Sev | Title | Status | Evidence |
|----|-----|-------|--------|----------|
| F1 | CRIT | CookiePrice bounds are USD-cents semantics applied to every currency | OPEN (E09 pending) | `CookiePrice.php:22-23` still `MIN_MINOR_UNITS=1`/`MAX_MINOR_UNITS=999_999` with USD-cents comment; `assertPositiveAndInRange` lines 195-214 still compares raw `$money->amountMinor()` and divides by 100 in error context (lines 200-201, 208-210) regardless of `$money->currency->decimals`. |
| F2 | HIGH | `defaultCurrency()` implicit env-read, USD silent fallback | OPEN (E09 pending) | `CookiePrice.php:42,70,81` all three factories still accept `?Currency $currency = null`; `CookiePrice.php:220-223` `defaultCurrency()` still delegates to `Currency::default()`. No required-currency enforcement landed. |
| F3 | HIGH | CookieName equality split between `equals()` and `equalsIgnoreCase()` | OPEN (E09 pending) | `CookieName.php:111-114` `equals()` still strict `===`; `CookieName.php:124-127` `equalsIgnoreCase()` still present accepting raw `string` and re-trimming the argument. No normalisation on construction. |
| F4 | HIGH | `CookieStock::fromInt` and `incrementBy` have no max / overflow guard | OPEN | `CookieStock.php:39-46` only rejects negative; `CookieStock.php:71-76` `incrementBy` does unguarded `$this->value + $quantity`. No `MAX_STOCK` constant exists in the file. |
| F5 | MED | Asymmetric error codes across `CookiePrice` exceptions | OPEN | `CookiePrice.php:163-164` `multiplyBy` still throws `ValidationException::tooSmall('quantity', 1, $quantity)` with **no error code**. `assertPositiveAndInRange` still emits values as `$minorUnits / 100` floats (lines 200-201, 208-210). `applyDiscount` correctly passes `COOKIE_VALIDATION_PRICE` (line 180) — partial compliance only. |
| F6 | MED | Deprecated `getValue(): float` retained; no `JsonSerializable` on any Cookie VO | OPEN | `CookiePrice.php:101-108` `getValue(): float` still present, still doing `$money->amountMinor() / (10 ** $decimals)`. None of the three Cookie VOs implement `JsonSerializable` (CookieName.php:37, CookiePrice.php:20, CookieStock.php:30 — class headers carry no interface list). E17/E11/E10 did not add it. |
| F7 | MED | `CookieName::equalsIgnoreCase()` accepts raw `string` — asymmetric surface | OPEN | `CookieName.php:124-127` signature unchanged: `public function equalsIgnoreCase(string $name): bool`. No `self::fromString()` re-validation path inserted. Conjoined with F3. |
| F8 | LOW | `CookieStock::$value` `public` while sibling VOs use private + getter | OPEN (PR #39 claim not in source) | `CookieStock.php:32` still `private function __construct(public int $value)`. PR #39 was advertised to flip this to private + add `value()` getter; the source at HEAD shows no such change. The advertised regression risk (callers reading `$stock->value` directly) is therefore moot, but the original asymmetry persists. |
| F9 | LOW | `CookiePrice::format()` `@deprecated` but still functional with symbol-override footgun | OPEN | `CookiePrice.php:120-131` `format(?string $currencySymbol = null)` still present; still concatenates an arbitrary `$currencySymbol` with `toDecimalString()` (line 130), enabling "render USD with €" misuse. Docblock still points to `PriceFormatter::format()` (line 123). |
| F10 | INFO | `CookieStock::fromInt` named after primitive, not source | OPEN | `CookieStock.php:39` factory still named `fromInt`. No `fromQuantity` / `of` alias added. |

## New issues

### N1 — LOW — Typed-const inconsistency between sibling VOs

- **Location:** `CookiePrice.php:22-23` vs `CookieName.php:39-40`.
- **Observation:** `CookiePrice` now uses PHP 8.3 typed class constants
  (`private const int MIN_MINOR_UNITS = 1;`). `CookieName` still uses untyped
  (`private const MIN_LENGTH = 3;`). Adds a third style-inconsistency dimension
  on top of F8's encapsulation split.
- **Suggested fix:** Apply typed consts uniformly on `CookieName` (and
  `CookieStock` once a `MAX_STOCK` lands per F4) so cloners see one pattern.

### N2 — LOW — `CookieSnapshot` (new VO) constructor is public; breaks the Cookie VO factory-only contract

- **Location:** `app/Domain/Cookie/ValueObjects/CookieSnapshot.php:39-42`.
- **Observation:** New VO has `public function __construct(public CookieChangeSet $changeSet)`.
  Both `fromArray` factory and public constructor are exposed. Every other
  Cookie VO uses **private constructor + named factory**. Cloners will pattern
  match against `CookieSnapshot` and ship public constructors elsewhere.
- **Suggested fix:** Make `__construct` private and route all creation through
  `fromArray` plus a `fromChangeSet(CookieChangeSet)` factory.

### N3 — INFO — `StockChangeReason` enum lives under `ValueObjects/` but has no VO scaffolding

- **Location:** `app/Domain/Cookie/ValueObjects/StockChangeReason.php`.
- **Observation:** Pure backed enum, no `equals()`, no `__toString()`, no
  `fromString` alias for tolerant input parsing at boundaries. Fine as-is for
  internal-only use, but cloners may copy this skeleton for taxonomies that
  *do* need boundary parsing and end up with `from()` throwing
  `\ValueError` instead of a domain `ValidationException`.
- **Suggested fix:** Add a `fromStringOrFail(string $value): self` static
  helper that wraps `tryFrom` and rethrows as
  `ValidationException::invalidFormat(...)` with a `COOKIE_VALIDATION_*` code,
  so the enum participates in the same error-code contract as the rest of the
  domain.

### N4 — INFO — `CookieSnapshot::fromArray` throws `\InvalidArgumentException`, not `ValidationException`

- **Location:** `CookieSnapshot.php:50-53` (delegates to
  `CookieChangeSet::fromArray`).
- **Observation:** Docblock declares `@throws \InvalidArgumentException` —
  inconsistent with every other Cookie VO factory which throws
  `ValidationException` with a `COOKIE_VALIDATION_*` code. Read-side
  consumers will need a different catch.
- **Suggested fix:** Either re-throw as `ValidationException` inside
  `CookieSnapshot::fromArray`, or normalise `CookieChangeSet` itself.

## Verdict shift

Was: READY-WITH-FIXES
Now: READY-WITH-FIXES (unchanged — no F# closed, no severity escalation)

The v3 plan correctly assigns F1/F2/F3 to E09. E09 had not landed at the time
of PRs #39 and #41 and is still pending; F1 and F2 remain CRITICAL/HIGH
correctness defects for any clone that touches money. The advertised PR #39
changes (Stringable + private `$value`) are not present in source — either
the diff merged elsewhere or the file was reverted; either way, F8 is still
open and N1/N2 widen the style-inconsistency surface.

## Top 3 still-open items

1. **F1 (CRIT) + F2 (HIGH)** — CookiePrice bounds & implicit USD default
   currency. E09 must (a) re-express `MIN/MAX_MINOR_UNITS` per-currency via
   `Money` constructors that consult `$currency->decimals`, (b) make
   `?Currency $currency = null` required on all three factories, (c) replace
   the `/100` arithmetic in `assertPositiveAndInRange` error context with
   `Money::toDecimalString()`. Until this lands, every cloned monetary VO
   inherits a JPY/BHD-broken validator and a silent USD coercion.
2. **F3 (HIGH) + F7 (MED)** — collapse `equals()` and `equalsIgnoreCase()` into
   one canonical equality semantics; if a string overload remains, route it
   through `self::fromString()` so it cannot leak unvalidated input. Repository
   uniqueness should consume `equals()` only.
3. **F4 (HIGH)** — add `MAX_STOCK` constant + guard on both
   `CookieStock::fromInt` and `incrementBy` (post-sum). This matches Money's
   PHP_INT_MAX rigour and closes the silent-wraparound vulnerability in
   every cloned inventory-style VO.
