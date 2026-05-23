# RE-AUDIT — Slice 01 — Cookie Entity & Aggregate Root

**Reviewer:** ddd-specialist
**Date:** 2026-05-23
**PRs reviewed:** #32, #33, #35, #39
**Original slice:** `.audit/round3/01-entity-aggregate.md`
**Files re-read:**
- `app/Domain/Cookie/Entities/Cookie.php` (348 LoC, up from 288)
- `app/Domain/Cookie/Entities/CookieStateAssertions.php` (new, 67 LoC)
- `app/Domain/Cookie/Entities/CookieAccessors.php` (DELETED — confirmed)
- `app/Domain/Cookie/ErrorCodes.php`
- `app/Domain/Cookie/ValueObjects/{CookieSnapshot,CookieStock,StockChangeReason}.php`
- `app/Domain/Cookie/Events/{CookieActivated,CookieDeleted,CookieStockChanged}/*.php`
- `app/Domain/Shared/Aggregate/{AggregateRootInterface,AggregateHydrator}.php`
- `app/Domain/Shared/{AggregateRoot.php,Events/AbstractDomainEvent.php}`

## Closure matrix

| F# | Sev | Title | Status | Evidence |
|----|-----|-------|--------|----------|
| F1 | HIGH | `update()` drops event when called pre-persist | PARTIAL | `Cookie.php:200-220` — still `return`s silently when `id === null`; docblock now acknowledges the choice (lines 34-37) but no `assertPersisted` guard; mutation persists, event lost. Rationale: cloners using `create()`→`update()`→`save()` still silently lose the update event. |
| F2 | HIGH | `activate()`/`deactivate()` raise no event | CLOSED | `Cookie.php:255-279` — both methods funnel through `setActive()` which raises `CookieActivatedEvent` / `CookieDeactivatedEvent` (PR #35). Idempotent no-op when already at target state. |
| F3 | HIGH | Soft-delete / restore are not entity methods | CLOSED | `Cookie.php:223-252` — `softDelete()` + `restore()` raise `CookieDeletedEvent` / `CookieRestoredEvent`; `restore()` rejects `not deleted` via new `COOKIE_STATE_NOT_DELETED = 404`. (PR #35) |
| F4 | HIGH | `reconstitute()` defaults `version = 0` | CLOSED | `Cookie.php:101-106` — explicit `if ($version < 1) throw \InvalidArgumentException`; no default on parameter. (PR #33) |
| F5 | MEDIUM | `assignId()`/`bumpVersion()` are publicly callable | PARTIAL | `Cookie.php:172-187` — both now require `AggregateHydrator $key`; only `AggregateHydrator::key()` produces one. The `@internal` on `key()` still relies on convention (line 54 of `AggregateHydrator.php`); the planned PHPStan custom rule (E05.5) is NOT yet in place. Improvement is substantial but not yet enforced. |
| F6 | MEDIUM | `assertPersisted()` uses wrong error code | CLOSED | `ErrorCodes.php:56` adds `COOKIE_STATE_NOT_PERSISTED = 403`; `CookieStateAssertions::ensurePersisted()` line 62 uses it. |
| F7 | MEDIUM | `changeStock` casts `$this->id` to `(int)` | CLOSED | `Cookie.php:332` uses `\assert($this->id !== null, ...)` and `$this->id` is passed straight; `CookieStockChangedEvent::cookieId` is now `int` (non-nullable, line 47). |
| F8 | MEDIUM | `CookieAccessors` trait with `@property` ghosts | CLOSED | `CookieAccessors.php` deleted; accessors inlined `Cookie.php:117-168` with comment "(slice 01/F8)". (PR #35) |
| F9 | MEDIUM | Stock reason is stringly-typed method name | CLOSED | `StockChangeReason` enum (`Sale|Restock|Return_|Adjustment|InitialStock`); `decreaseStock`/`increaseStock` default to `Sale`/`Restock`; event field is enum-typed. (PR #35) |
| F10 | LOW | No `implements` clause on `Cookie` | CLOSED | `Cookie.php:45` — `final class Cookie implements AggregateRootInterface`; interface defines `pullEvents`/`peekEvents`/`hasPendingEvents`/`getId`. (PR #33) |
| F11 | LOW | `snapshot()` returns heterogeneous array | CLOSED | `Cookie.php:313-326` returns `CookieSnapshot` VO wrapping `CookieChangeSet`; both `final readonly` with whitelisted keys. (PR #35) |
| F12 | INFO | Docblock encodes split-dispatch workaround as canon | OPEN | `Cookie.php:34-37` still documents the split (`CookieCreatedEvent stays handler-side`). No architectural exit (UUIDv7 / deferred rewrite / `whenPersisted()` hook) chosen. INFO-grade; carries forward. |

**Counts:** CLOSED 9 (F2, F3, F4, F6, F7, F8, F9, F10, F11) — PARTIAL 2 (F1, F5) — OPEN 1 (F12) — REGRESSED 0.

## New issues

### N1 — MEDIUM — `update()` still allows pre-persist call without raising, contradicting the new "every public mutator raises ≥ 1 event" convention
- **Location:** `Cookie.php:200-220` (and docblock lines 34-37 that promises the convention).
- **Why it matters:** The class docblock (PR #35) now states *every* public mutator raises ≥ 1 event. `update()` violates that promise when `id === null` — silent mutation, no event. Cloners will read the docblock as a guarantee and write projections that depend on it.
- **Suggested fix:** Either gate `update()` with `CookieStateAssertions::ensurePersisted($this->id, 'update')` (turning pre-persist `update()` into a `DomainException`, consistent with `softDelete`/`restore`/`activate`/stock), or rework the factory so pre-persist mutation goes via `create()` re-invocation. Document the choice in the docblock.

### N2 — LOW — `AggregateHydrator $key` is unused in body; relies on `phpcs:ignore` comments
- **Location:** `Cookie.php:171-187` (two `phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter` directives).
- **Why it matters:** The "key" pattern is a runtime-typeless guard. PHP doesn't verify the *origin* of the `AggregateHydrator` instance — only its type. Any caller who can `new` an `AggregateHydrator`... can't (constructor is private). Good. But any code in any namespace can call `AggregateHydrator::key()` today; the docblock `@internal` is the only fence. Until the promised PHPStan rule (E05.5) lands the "security parameter" is documentation-grade.
- **Suggested fix:** Land the E05.5 PHPStan rule before more domains are scaffolded; right now Cookie is the only consumer, but cloners will replicate the pattern under the impression it is enforced.

### N3 — LOW — `bumpVersion()` lacks `assertPersisted()` guard
- **Location:** `Cookie.php:172-175`.
- **Why it matters:** A repository misuse that bumps version before assigning id silently increments from `0` to `1`. Combined with `reconstitute()`'s `version >= 1` check, the next reload would *think* this is a persisted entity. Currently exploitable only from inside hydration paths (need a hydrator key), so severity is low, but symmetric guarding with `assignId` would close the loophole.
- **Suggested fix:** `if ($this->id === null) throw \LogicException('cannot bump version on unpersisted aggregate');` (or accept this as the hydrator's responsibility and document it in `AggregateHydrator`).

### N4 — INFO — `Cookie.php` LoC trended UP, not down
- **Location:** `Cookie.php` whole file.
- **Detail:** Was 288 LoC at audit time; **now 348 LoC** (+60). E07 added 4 lifecycle methods + `setActive()` + `buildLifecycleEvent()` helper; E06 added the hydrator-key parameters; inlining `CookieAccessors` (F8) added ~37 LoC. The "200-line guideline" mentioned in the original slice's TL;DR is now further away than before, despite the F8 extraction of `CookieStateAssertions` (which removed ~25 LoC). Suggest tracking this in slice 15 / class-length re-audit; the entity is now meaningfully over the cap.
- **Suggested fix:** Acceptable trade-off for richer lifecycle, but the next clone template should explicitly call out the LoC budget — otherwise cloners will use Cookie as the ceiling, not the target.

### N5 — INFO — `nowUtc()` is called 2–3 times per mutator
- **Location:** `Cookie.php:228, 231` (`softDelete`) and similar pattern in `setActive`/`changeStock` indirectly via `buildLifecycleEvent`.
- **Detail:** Each call constructs a fresh `DateTimeImmutable`. `softDelete` calls it twice (once for `deletedAt`, once for the event's `occurredAt`), so the persisted `deletedAt` and the event `occurredAt` are NOT guaranteed identical timestamps (microsecond drift). Trivial in practice but conceptually the *same* domain moment.
- **Suggested fix:** Compute once per mutator: `$now = $this->nowUtc(); $this->deletedAt = $now->format(...); $this->raiseEvent(..., occurredAt: $now, ...)`.

## Compatibility gaps

- `AggregateRootInterface` promises `pullEvents`/`peekEvents`/`hasPendingEvents`/`getId`. Cookie satisfies all four via the `AggregateRoot` trait + own `getId()`. NO mismatch.
- The `#[\Override]` sweep claimed by PR #39 is NOT visible on `Cookie::getId()` (which technically overrides the interface contract, though PHP doesn't require `#[\Override]` for interface implementations); also not on `pullEvents`/`peekEvents`/`hasPendingEvents`. Acceptable per PHP semantics, but if the sweep aimed to mark interface implementations the entity was missed. Flag only.
- `Stringable` claim from PR #39 is not on the entity itself (Cookie has no `__toString()`). Likely applies only to value objects — confirm in slice 02.

## Verdict shift

**Was:** READY-WITH-FIXES (4 HIGH, 5 MEDIUM, 2 LOW, 1 INFO)
**Now:** READY-WITH-FIXES (1 MEDIUM-residual + 1 MEDIUM-new + 2 LOW-new + 1 INFO carry-over)

Substantial progress: 9 of 12 original findings CLOSED, including all 3 HIGH lifecycle/event findings that were "template defects" for cloned domains. Residual issues are scoped and well-understood; entity is closer to clone-ready than after round 3.

## Top 3 still-open items

1. **F1 / N1 — `update()` pre-persist silently skips event** despite the new docblock guarantee. Single 1-line fix: add `ensurePersisted($this->id, 'update')` and remove the `if ($id === null) return;` short-circuit.
2. **F5 / N2 — `AggregateHydrator` key is enforced by convention only** until the E05.5 PHPStan rule lands. Ship the rule before scaffolding the next domain so cloners inherit a real fence.
3. **N4 — Cookie.php is 348 LoC (target ≤ 200)** and trending upward. Consider extracting `CookieLifecycle` (activate/deactivate/softDelete/restore) into a sibling class symmetric to `CookieStateAssertions` to bring the entity back under the ceiling before cloning.
