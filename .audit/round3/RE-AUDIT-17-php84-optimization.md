# RE-AUDIT 17 — PHP 8.4 Optimization (Cookie slice)

**Re-reviewer:** php-specialist
**Date:** 2026-05-23
**Original audit:** `.audit/round3/17-php84-optimization.md`
**Local branch (`stabilization/erp-foundation`) carries:** E04 + E05 + E06 + E07
**PRs reviewed for closure intent (still OPEN, not merged into local):** #29 (E02 — phpVersion/Slevomat pin) and #39 (E17 — `#[\Override]` + `\Stringable` + `final` on Controller/Model + `CookieName` typed consts + `CookieStock` private setters)
**Scope:** the same ~54 files in slice 17 plus the new `App\Domain\Shared\Bus\LogSampler` shared service (E05).

---

## TL;DR

The original audit (round 3) reported 20 findings (F1–F10 Part A + G1–G12 Part B + P1–P5 Part C). Reconciliation against the **local working tree** plus the two staged-but-unmerged PRs that target this slice yields:

| Bucket | Count | Closed locally | Closed via OPEN PR | Still OPEN |
|--------|-------|----------------|--------------------|------------|
| Part A (8.3 idiom gaps) | 10 (F1–F10) | 1 (F2) | 4 (F1, F3, F4, F5 controller half) | 5 (F5 model half, F6, F7, F8, F9, F10) |
| Part B (8.4 opportunities) | 12 (G1–G12) | 0 | 0 | 12 (all gated on E16 — PHP 8.4 bump) |
| Part C (perf) | 5 (P1–P5) | 0 | 1 (P1 via F8 in PR #39 — partial) | 4 (P2, P3, P4 is a non-finding, P5) |

The **load-bearing single fix** flagged by the original audit (pin `phpstan.neon: phpVersion: 80300` so PHP-8.4-only syntax cannot land in code declared `^8.3`) is **NOT yet in the local tree**; it lives in OPEN PR #29 and is correctly scoped there. Until #29 merges, the F-class and G-class findings can still drift in undetected on developer hosts running PHP 8.4.

## Verdict shift

Original: **READY-WITH-FIXES** (0 CRITICAL / 3 HIGH / 7 MEDIUM / 8 LOW / 2 INFO).

Re-audit: **READY-WITH-FIXES (improving — gated on PRs #29 + #39)**.

The slice has not regressed. The 8.3-idiom-gap closures are queued and reviewed (PR #39), and the toolchain-pin closures are queued (PR #29). Local-only E05 already shipped the highest-severity item from Part A (F2 — biased mt_rand sampler) by extracting `App\Domain\Shared\Bus\LogSampler` and routing all three query handlers through it. The verdict therefore moves from "READY-WITH-FIXES (untouched)" to "READY-WITH-FIXES (improving)" — but it is **not yet GREEN** because the phpVersion pin (the audit's #1 fix) is not in the trunk.

## Status per finding

### Part A — PHP 8.3 idiom gaps

| ID | Severity | Status | Closure path | Notes |
|----|----------|--------|--------------|-------|
| **F1** — `CookieName::MIN/MAX_LENGTH` lack typed-const types | MEDIUM | **OPEN locally, CLOSED in PR #39** | E17 | `app/Domain/Cookie/ValueObjects/CookieName.php:39-40` still reads `private const MIN_LENGTH = 3;` / `private const MAX_LENGTH = 100;` on local — verified. PR #39 promotes both to `private const int`. Wait for PR #39 merge. |
| **F2** — `mt_rand()/mt_getrandmax()` sampler in 3 query handlers (biased) | HIGH | **CLOSED locally (E05)** | merged | All three query handlers now call `(new LogSampler($this->loggingConfig->samplingRate()))->shouldSample()`. `App\Domain\Shared\Bus\LogSampler` uses `random_int(1, 10_000)` against a basis-points int — unbiased, single source of truth. `mt_rand`/`mt_getrandmax` no longer reachable in Cookie. |
| **F3** — no `#[\Override]` anywhere (~25 sites) | MEDIUM | **OPEN locally, CLOSED in PR #39** | E17 | `grep -rn '#\[\\Override\]' app/` returns 0 hits on local; PR #39 diff contains 17 `#[\Override]` additions across handlers + middlewares + Cookie repository implementations. Coverage is at the right files. Wire a Slevomat sniff in a follow-up so cloned domains can't regress. |
| **F4** — VOs missing `implements \Stringable` | MEDIUM | **OPEN locally, CLOSED in PR #39** | E17 | `CookieName` and `CookiePrice` still lack the explicit `implements` clause on local. PR #39 addresses both. |
| **F5** — `CookieController` + `CookieModel` not `final` | MEDIUM | **Controller: CLOSED in PR #39. Model: deliberately OPEN** | PR #39 (controller half) | `app/Controllers/Domain/Cookie/CookieController.php:40` still reads `class CookieController extends BaseController` locally; PR #39 makes it `final`. `app/Models/Cookie/CookieModel.php:28` stays non-final on purpose: integration tests mock `CookieModel` (carve-out documented in slice 12). Confirm a class-level comment is added when E17 lands so the carve-out doesn't read as oversight. |
| **F6** — `@deprecated` docblock on `CookiePrice::getValue()`/`::format()` is not engine-enforced | LOW | **OPEN** | gated on PHP 8.4 (G6) | Both docblocks present at `app/Domain/Cookie/ValueObjects/CookiePrice.php:102` and `:123`. Correct call once `require.php` is `^8.4`: replace with `#[\Deprecated]` (see G6). |
| **F7** — per-field `(int) $command->id` / `(bool) $isActive` casts in controller | LOW | **OPEN** | template-wide refactor | Out of scope for this slice; flagged here as a duplicated pattern that propagates per cloned domain. No closure path scheduled. |
| **F8** — `array_map(static fn ...)` where first-class callable would do | LOW | **OPEN** | small follow-up | Five call sites still present: `CookieView.php:124`, `CookieQueryRepository.php:101`, `:145`, `CookieRepository.php:531`, `:577`. Each replaceable with `self::summary(...)` / `$this->toDto(...)` / `$this->toDomainEntity(...)`. Cross-cuts P1. Trivial follow-up PR. |
| **F9** — `EventOutboxRelay` could short-circuit with `json_validate()` before `json_decode(... JSON_THROW_ON_ERROR)` | LOW | **OPEN** | optional | Defense-in-depth + minor perf gain on the (cold) failure path. Not a defect; defer. |
| **F10** — `EventDispatcher::describeListener()` reinvents callable introspection (~19 lines of branching) | LOW | **OPEN** | optional | A `LabeledListener` wrapper would shrink it; cosmetic, not a defect. Defer. |

### Part B — PHP 8.4 opportunities

All twelve (G1–G12) remain **correctly deferred to E16** (the PHP 8.4 bump epic — gated on user decision per the round-3 plan). No code change should land for any G-finding until `composer.json: require.php` flips to `^8.4` AND `phpstan.neon: phpVersion` flips to `80400` in the same commit. `phpcs.xml: php_version` must follow in the same PR.

Verified the staging is correct:
- G12 (implicit-nullable deprecation) is already a non-finding in Cookie — every `?Type` parameter is explicitly nullable. Confirmed.
- G1 (asymmetric visibility on `Cookie::$id` / `$version`) — local code still uses `private ?int $id` + `assignId(int $id): void` (line 49 of the entity). This is the right shape for 8.3; G1's `public private(set)` rewrite waits for E16.

### Part C — Performance

| ID | Status | Notes |
|----|--------|-------|
| **P1** — closures bound to `$this` in 4 `array_map` repository sites | **PARTIALLY ADDRESSED** via F8 fix (PR #39 does not touch these — still TODO). Replace with first-class callable in the F8 follow-up. |
| **P2** — `LOWER(name)` SQL vs PHP-side `strtolower` mismatch | **OPEN** — cross-cuts slice 06 F6. Out of slice-17 scope. |
| **P3** — `microtime(true)` for duration timing in 7 handlers/middlewares, `hrtime(true)` correctly in 1 (`DeleteCookieHandler`) | **OPEN** — still asymmetric on local: `CreateCookieHandler:70/122/133`, `UpdateCookieHandler:60/132` use `microtime`; only `DeleteCookieHandler:50/76` uses `hrtime`. Standardise on `hrtime(true) / 1_000_000` in a follow-up PR so cloned domains see one consistent pattern. |
| **P4** — no `eval`/`extract`/`create_function` | **Non-finding** — re-verified. |
| **P5** — per-row reflection in `EventOutboxRelay::rehydrate()` | **OPEN** — bounded gain (50-row drain); defer until profiling shows hot. |

## composer.json / phpstan.neon / phpcs.xml audit (today)

| Check | Local (`stabilization/erp-foundation`) | After PR #29 merges | Slice expectation |
|-------|----------------------------------------|---------------------|-------------------|
| `composer.json` `require.php` | `^8.3` (line 12) | `^8.3` (unchanged) | `^8.3` until E16 — **OK** |
| `composer.json` `slevomat/coding-standard` | `^8.15` (line 26) | `^8.18` | bumped by E02 to unlock 8.3-aware sniffs — **PR #29 closes** |
| `phpstan.neon` `phpVersion` | **absent** | `phpVersion: 80300` | **MUST PIN** — PR #29 closes. *This is the audit's load-bearing fix.* |
| `phpcs.xml` `<config name="php_version">` | **absent** | `<config name="php_version" value="80300"/>` | **MUST PIN** — PR #29 closes. |

Local `grep -n "phpVersion\|php_version" phpstan.neon phpcs.xml` returns **zero hits**, confirming the pin gap is real on trunk. The single change with the highest leverage in this entire slice — pinning PHPStan/PHPCS to PHP 8.3 so 8.4 syntax cannot drift in — is **still gated on merging PR #29**.

## Biggest residual

**The `phpstan.neon: phpVersion` and `phpcs.xml: php_version` pins are not yet in trunk.** Until PR #29 merges, every other 8.3 idiom gap in this slice (and every other slice's PHP gate) is fixable but the *guard against re-introduction* is missing. A developer on a PHP 8.4 host can land 8.4-only syntax in code targeting `^8.3` and neither static analysis nor PHPCS will flag it. The audit called this out as "the single most important fix" — that judgement still stands.

Secondary residual: the `microtime(true)` vs `hrtime(true)` asymmetry in command handlers (P3) survives untouched. Every cloned domain currently inherits 7 examples of the wrong pattern and 1 of the right one.

## Severity counts (re-audit)

CRITICAL **0** | HIGH **2 still open** (G1, G2 — both gated on E16) | MEDIUM **2 still open locally** (F1, F3, F4, F5-controller all OPEN locally but CLOSED in PR #39; once #39 merges only F5-model-carve-out + the remaining LOW set survive) | LOW **8 open** | INFO **2 open**

## Recommended next steps (no new findings introduced)

1. **Merge PR #29.** This is the highest-leverage change in the slice — pins PHP version everywhere it matters and bumps Slevomat to ^8.18 so 8.3-aware sniffs are available.
2. **Merge PR #39.** Closes F1, F3, F4, and the controller half of F5 in one shot.
3. **Open a small follow-up PR for F8 + P1.** Five mechanical edits: replace `static fn ... => self::summary($x)` and `fn(...) => $this->method($x)` with `self::summary(...)` and `$this->method(...)` at the five known call sites. JIT-friendlier and removes the duplicated-per-clone closure shape.
4. **Open a P3 follow-up PR.** Replace `microtime(true)` in command/query handlers + bus middlewares with `hrtime(true) / 1_000_000`. `DeleteCookieHandler` is the existing template.
5. **Leave Part B (G1–G12) untouched.** Reopen this slice when E16 (PHP 8.4 bump) ships.

## What is correct / praiseworthy (delta vs original)

Re-confirmed from the original audit and **still true on local**:
- `strict_types=1` saturation across all 54 files — re-verified.
- `final readonly` saturation on VOs/DTOs/commands/events/handlers — re-verified.
- 100 % constructor property promotion + named arguments + `match` adoption — re-verified.
- `JSON_THROW_ON_ERROR` + `get_debug_type()` adoption preserved.

**New since the original audit (positive delta from E05/E07):**
- `App\Domain\Shared\Bus\LogSampler` — clean, single-purpose, basis-points int + `random_int` — closes F2 with a better abstraction than the original audit suggested (the audit asked for `random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX`; the implementation uses basis-points integer math, which avoids the float comparison entirely). Idiomatic 8.3, and trivially swappable for `Random\Randomizer` when E16 lands.
- `CookieActivatedEvent` / `CookieDeactivatedEvent` + handlers (E07) — clean PHP 8.3 shape (`final readonly`, named arg construction, registered in `CookieServiceProvider`). No new PHP-idiom findings introduced.
- Entity `Cookie::softDelete()` / `Cookie::restore()` lifecycle methods (E07) — well-shaped, no 8.3-idiom regressions.

End of re-audit.
