# RE-AUDIT 07 — DTOs & Read Models (Round 3, second pass)

**Slice:** CookieDTO, CookieView, PriceFormatter
**Reviewer:** cqrs-specialist
**Original audit:** `.audit/round3/07-dto-readmodels.md` (2026-05-22)
**Re-audit date:** 2026-05-23
**PRs that touched these files since round 3:** none. E10 (the closing epic) has not been opened.

## TL;DR

Zero forward motion on this slice. `CookieDTO.php`, `CookieView.php`, and `PriceFormatter.php` are byte-identical to the round-3 capture. Templates (`app/Views/cookies/show.php`, `index.php`) still consume `CookieDTO::isOutOfStock()` directly. `CookieView` references in `app/` remain 1 (its own file). `PriceFormatter::format()` references in `app/` remain 0 (only docblock-example mentions in its own file). `CookiePrice::format()` is still `@deprecated`-pointing-to-PriceFormatter while still being the function both `CookieDTO::fromEntity()` (line 44) and `CookieQueryRepository::formatPrice()` (line 197) actually call. All 14 findings stand.

## Verdict

**NOT-READY** (unchanged from round 3).

## Closure matrix

| ID  | Severity | Status | Note |
|-----|----------|--------|------|
| F1  | CRITICAL | OPEN   | (E10 not yet opened) — `CookieView` still dead code; only its own file references it inside `app/` |
| F2  | CRITICAL | OPEN   | (E10 not yet opened) — `PriceFormatter::format()` invoked nowhere in `app/`. Real callers still hit `@deprecated` `CookiePrice::format()` |
| F3  | HIGH     | OPEN   | (E10 not yet opened) — `PriceFormatter` still `final` (not `final readonly`), no Intl, still does symbol-prefix concatenation |
| F4  | HIGH     | OPEN   | (E10 not yet opened) — `CookieDTO::isOutOfStock()` still present; still called from both views |
| F5  | HIGH     | OPEN   | (E10 not yet opened) — `public ?int $id` still nullable |
| F6  | HIGH     | OPEN   | (E10 not yet opened) — `CookieDTO` still without `toArray()` / `JsonSerializable`; `CookieView::toArray()` still snake_case |
| F7  | HIGH     | OPEN   | (E10 not yet opened) — `CookieView::detail()`/`summary()` still take `Cookie` entity; no `fromRow`/`fromDto` factory |
| F8  | MEDIUM   | OPEN   | (E10 not yet opened) — soft-delete fields still split between View (has) and DTO (doesn't) |
| F9  | MEDIUM   | OPEN   | (E10 not yet opened) — `CookieView::$extra` still constructor-only; never in `toArray()` |
| F10 | MEDIUM   | OPEN   | (E10 not yet opened) — folder split (`DTOs/` + `ReadModels/` + `Services/`) intact |
| F11 | MEDIUM   | OPEN   | (E10 not yet opened) — `CookieDTO::fromEntity` vs `CookieView::detail/summary/summarise` asymmetry intact |
| F12 | LOW      | OPEN   | (E10 not yet opened) — `CookieView` still `private __construct`; `CookieDTO` still `public __construct` |
| F13 | LOW      | OPEN-as-noted | Safe — no defensive-copy issue |
| F14 | LOW      | OPEN   | (E10 not yet opened) — no lift of `PriceFormatter` to shared `MoneyFormatter` |

**Status totals:** OPEN 13 (CRITICAL 2 / HIGH 5 / MEDIUM 4 / LOW 2) + OPEN-as-noted 1.

## Side-effect cross-checks performed

- `Grep CookieView` inside `app/`: 1 hit (its own file). Confirms F1.
- `Grep PriceFormatter::` inside `app/`: only docblock examples in its own file; zero production callers. Confirms F2.
- `Grep isOutOfStock` across `app/`: still used by both view templates.
- `Grep CookieDTO` inside `app/`: 6 files — DTO, three query handlers, query repository, port interface.
- `CookiePrice::format()` deprecation annotation still present pointing at unused `PriceFormatter`. Direct call still happens at the DTO/repo paths.

## What is correct / praiseworthy (unchanged)

Same as the original audit. `final readonly` + typed-properties + meaningful docblocks remain. Handler→DTO boundary (no entity leaks from query handlers) is still in place. No regressions.

## Top 3 fixes before cloning (unchanged from round 3)

1. Pick a single read-side DTO. Either retire `CookieView` or retire `CookieDTO`; do not ship both.
2. Resolve the `PriceFormatter` vs `CookiePrice::format()` contradiction.
3. Establish a shared serialization contract (`toArray()` + `JsonSerializable`, snake_case JSON, ISO-8601 dates).

---

**Severity counts:** CRITICAL 2 | HIGH 5 | MEDIUM 4 | LOW 3 (unchanged).
**Top finding:** F1 — `CookieView` is dead code while `CookieDTO` is the canonical read DTO; both still ship.
