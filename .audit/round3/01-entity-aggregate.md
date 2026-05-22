# 01 — Cookie Entity & Aggregate Root

**Slice:** Entity invariants, factory methods, event-raising, lifecycle
**Reviewer:** ddd-specialist
**Date:** 2026-05-22
**Source files reviewed:** 5 files, ~640 lines (`Cookie.php` 288 LOC, `CookieAccessors.php` 75, `ErrorCodes.php` 62, `AggregateRoot.php` 96, plus shared exceptions cross-checked)

## TL;DR
The Cookie aggregate has materially improved since round 2: `update()`, `activate()`, `deactivate()` now guard soft-delete and `update()` raises a `CookieUpdatedEvent`; `raiseEvent()` is typed `DomainEventInterface`; `assignId()` rejects reassignment; class is now within the 200-line guideline neighbourhood. However, three template-multiplying defects remain — pre-persist mutators silently swallow events, the missing soft-delete/restore command methods on the entity push delete semantics into the repository, and `reconstitute()` accepts a defaulted `version = 0` that can silently neuter optimistic locking on cloned domains.

## Verdict
READY-WITH-FIXES

## Findings

### F1 — HIGH — `update()` silently drops its event when called pre-persist
- **Location:** `app/Domain/Cookie/Entities/Cookie.php:152-179`
- **Observation:** When `$this->id === null`, `update()` mutates state and then `return`s without raising `CookieUpdatedEvent`. Mutation is permanent; the event is lost. Same logical hole exists implicitly in `activate()`/`deactivate()` which raise no event at all (see F2).
- **Why this is a template defect:** Every cloned domain inherits the "mutate-then-skip-event" pattern. A caller composing `create()` → `update()` → `save()` (a legitimate command flow) produces a row whose history is missing the update event entirely. Audit trails will be silently incomplete across the ERP.
- **Suggested fix:** Either (a) refuse pre-persist `update()` via `assertPersisted('update')` symmetric to `decreaseStock`, or (b) queue the event and rewrite `cookieId` at persist time. Document the choice in the trait.

### F2 — HIGH — `activate()` / `deactivate()` raise no event at all
- **Location:** `app/Domain/Cookie/Entities/Cookie.php:262-272`
- **Observation:** Both mutators flip `$isActive` and assert-not-deleted, but neither calls `raiseEvent`. There is no `CookieActivatedEvent` / `CookieDeactivatedEvent` in the domain.
- **Why this is a template defect:** The event-emission convention documented in the entity's own class docblock (lines 34-39) promises that "the entity raises … events through the AggregateRoot trait." Active/inactive transitions are exactly the kind of business state change downstream consumers (inventory, catalog, search index) need to react to. Cloned domains will inherit the same silent-toggle and re-discover the gap one consumer at a time.
- **Suggested fix:** Introduce `CookieActivatedEvent` / `CookieDeactivatedEvent` (or a single `CookieAvailabilityChangedEvent`) and raise from these mutators. Make the omission impossible to repeat by codifying "every public mutator raises ≥ 1 event" in the cloning skill.

### F3 — HIGH — Soft-delete / restore are not entity methods; lifecycle is leaking
- **Location:** `app/Domain/Cookie/Entities/Cookie.php:48-57, 199-208, 284-287`
- **Observation:** The entity exposes the `?string $deletedAt` field, an `assertNotDeleted()` guard, and `isDeleted(): bool`, but no `softDelete()` / `restore()` mutators. Setting `deletedAt` is therefore the repository's job (verified by inspecting `CookieUpdatedEvent` / `CookieRestoredEvent` directory layout). This breaks the "command method, not setter" rule documented in the project skill.
- **Why this is a template defect:** Every cloned domain will repeat the asymmetry — the entity guards against the deleted state but cannot itself transition into or out of it. Repositories will continue to be responsible for invariant changes that ought to live in the aggregate, and `CookieDeletedEvent` / `CookieRestoredEvent` will keep being raised from handlers (the same anti-pattern as the round-2 finding on `CookieCreatedEvent`).
- **Suggested fix:** Add `softDelete(): void` and `restore(): void` to the entity; raise the corresponding events from inside them. Repository's `delete()` calls `$cookie->softDelete()` then persists.

### F4 — HIGH — `reconstitute()` defaults `int $version = 0` is wrong direction
- **Location:** `app/Domain/Cookie/Entities/Cookie.php:93-113`
- **Observation:** `version` has no default in the parameter list (good — round-2 finding N6 is fixed), but the *field* default is `0` (line 54) and `reconstitute()` is the only way to rehydrate. A repo that reads a legacy / corrupted row whose `version` column is `NULL` or missing will silently produce a `version = 0` entity and the next save will look valid to the optimistic-lock check.
- **Why this is a template defect:** Combined with the entity's `bumpVersion()` (line 120) being public, the version surface is permissive in two directions: external mutation and silent defaults. Cloned domains inherit the same loophole.
- **Suggested fix:** (a) Require `version >= 1` in `reconstitute()` (any persisted row has had at least one write); (b) throw if `bumpVersion()` is called before `assignId()`; (c) consider an `EntityHydrator` sealed interface so only the repository can hydrate.

### F5 — MEDIUM — `assignId()` and `bumpVersion()` are public; `@internal` is documentation, not enforcement
- **Location:** `app/Domain/Cookie/Entities/Cookie.php:115-139`
- **Observation:** Both are `public function … @internal`. Nothing prevents a controller, command handler, or test from calling them.
- **Why this is a template defect:** PHP has no package-private. Every cloned aggregate will expose the same hydration handle. A junior dev fixing a flaky test by calling `$cookie->assignId(99)` will not be stopped by the docblock alone.
- **Suggested fix:** Add a PHPStan custom rule that blocks calls to `@internal` methods from outside the entity's namespace, OR (preferred) introduce an `AggregateHydrator` interface the repository implements and pass it as a "key" parameter to `assignId`/`bumpVersion`. Either way, codify in the `domain-scaffolding` skill so cloned domains inherit the fix.

### F6 — MEDIUM — `assertPersisted()` uses the wrong error code
- **Location:** `app/Domain/Cookie/Entities/Cookie.php:213-222`
- **Observation:** Throws `DomainException::invalidState(... ErrorCodes::COOKIE_STATE_DELETED)`. "Persisted" and "deleted" are different states; reusing `COOKIE_STATE_DELETED` for "id is null" pollutes logs and breaks any alerting filter that splits on the code.
- **Why this is a template defect:** A cloner copy-pasting this body will replicate the wrong-code-for-wrong-state mismatch into every domain. Worse, the error-code class has no `COOKIE_STATE_NOT_PERSISTED` to point them at the right value.
- **Suggested fix:** Add `COOKIE_STATE_NOT_PERSISTED = 403` to `ErrorCodes`, use it in `assertPersisted`, and add a row to the State (400-499) range comment.

### F7 — MEDIUM — `changeStock` casts `$this->id` to `(int)` instead of relying on the guard
- **Location:** `app/Domain/Cookie/Entities/Cookie.php:249-260`
- **Observation:** `new CookieStockChangedEvent(cookieId: (int) $this->id, …)`. The `(int)` cast exists because `$this->id` is `?int`. A `null` cast silently becomes `0`, producing an event with `cookieId === 0` if the persist-guard is ever removed or bypassed.
- **Why this is a template defect:** The cast advertises that the function trusts the caller to have asserted, but a copy-paste into a cloned domain that omits `assertPersisted` will compile and ship corrupt events. The event itself accepts `?int $cookieId` (verified in `CookieStockChangedEvent.php:24`), so the cast is gratuitous and dangerous.
- **Suggested fix:** Drop the cast and tighten the event's `cookieId` to `int` (non-nullable). The guard upstream is the right place to enforce non-null; the cast is a downstream paper-over.

### F8 — MEDIUM — `CookieAccessors` trait uses `@property` to satisfy phpstan but creates a brittle dependency
- **Location:** `app/Domain/Cookie/Entities/CookieAccessors.php:18-27`
- **Observation:** The trait declares `@property` annotations for state it does not define. PHPStan reads these as a promise; rename one of the fields on the entity (`$isActive` → `$active`) and the trait silently keeps working at runtime but PHPStan will flag — or worse, depending on rule config, won't.
- **Why this is a template defect:** Every cloned domain will have a parallel `FooAccessors` trait carrying a duplicated property contract. Rename drift between the two files is invisible at runtime until a getter is called.
- **Suggested fix:** Inline the accessors into the entity (they are 10 trivial getters, ~25 LOC) OR move the accessor trait next to the entity and convert the `@property` block to a sealed interface the entity implements. The current trait-with-phantom-properties pattern is the worst of both worlds.

### F9 — MEDIUM — `decreaseStock` / `increaseStock` use the method name as the event `reason` (stringly-typed)
- **Location:** `app/Domain/Cookie/Entities/Cookie.php:230-260`
- **Observation:** `$this->changeStock(..., 'decreaseStock')` and `'increaseStock'` are passed verbatim. `CookieStockChangedEvent::reason` is a plain string. Round-2 N2 was not addressed.
- **Why this is a template defect:** Cross-domain analytics ("why does stock move?") cannot rely on a typed column. Cloned `Order::cancel()` etc. will repeat the pattern. The reason is not the *operation* (which is implicit in the method name) but should be a domain-meaningful enum value (`SALE`, `RESTOCK`, `RETURN`, `ADJUSTMENT`).
- **Suggested fix:** Introduce a `StockChangeReason` enum in shared, change `changeStock` to accept it, and require callers (handlers) to pass the *business* reason rather than the entity inferring from method name.

### F10 — LOW — Missing `implements` clause on `Cookie`
- **Location:** `app/Domain/Cookie/Entities/Cookie.php:43`
- **Observation:** `final class Cookie` implements no interface. There is no `AggregateRootInterface` / `EntityInterface` either in `app/Domain/Shared/`. The `AggregateRoot` trait is structural duck-typing.
- **Why this is a template defect:** No shared type can be enforced at the type level (e.g., repository ports cannot be parameterised over an aggregate). Every cloned domain inherits the same trait-only pattern.
- **Suggested fix:** Add `AggregateRootInterface` (with `pullEvents`, `peekEvents`, `hasPendingEvents`, `getId`) and a marker `EntityInterface` to `app/Domain/Shared/`. Make the entity implement them.

### F11 — LOW — `snapshot()` returns `'is_active' => bool` but `'stock' => int`; type representation is inconsistent
- **Location:** `app/Domain/Cookie/Entities/Cookie.php:184-194`
- **Observation:** The snapshot mixes booleans, ints, decimal strings (`price`), and nullables. Downstream `CookieUpdatedEvent::previousState` consumers see a heterogeneous map.
- **Why this is a template defect:** Audit consumers comparing two snapshots cross-domain will need per-key type knowledge. Cloned domains will repeat the heterogeneity.
- **Suggested fix:** Either commit to "all strings" (serialisable, log-friendly) or introduce a typed `CookieSnapshot` value object. Document the choice in the cloning skill.

### F12 — INFO — Class docblock says `CookieCreatedEvent` is dispatched by the handler "because the event payload needs the freshly-allocated id"
- **Location:** `app/Domain/Cookie/Entities/Cookie.php:34-39`
- **Observation:** Honest documentation of a real constraint, but it encodes a workaround as canon. The split-dispatch model (some events raised by entity, some by handler) is the root cause of multiple round-2 findings.
- **Why this is a template defect:** Cloners read this and conclude split dispatch is intentional. It is, but it stems from the `?int $id = null` lifecycle and could be fixed by either (a) ID generation pre-persist (UUIDv7), (b) deferred-event rewriting at save time, or (c) a `whenPersisted()` callback on the aggregate.
- **Suggested fix:** Either pick one of the three architectural exits and document it, or explicitly call out in the cloning skill that "if your domain needs `XxxCreatedEvent` from the entity, switch to UUIDv7 ids."

## What is correct / praiseworthy
- `raiseEvent(DomainEventInterface $event)` typing closes round-1's "accepts plain `object`" hole.
- `assignId()` refuses reassignment via `LogicException` (round-1 finding addressed).
- `update()`, `activate()`, `deactivate()`, `decreaseStock`, `increaseStock` all gate on `assertNotDeleted()` — soft-delete is honoured uniformly across mutators.
- `pullEvents()` / `peekEvents()` / `hasPendingEvents()` triple is the right shape for both the relay and tests.
- `private function __construct` + named factories (`create`, `reconstitute`) is correct DDD shape.
- `final class` + `final readonly` value objects make immutability defaults right.
- The class-level docblock (lines 17-42) is exemplary — it documents *why* the split-dispatch model exists, which is the kind of "ubiquitous-language record" a cloner needs.
- `ErrorCodes` scoping contract (lines 17-27) explicitly addresses the cross-domain numeric collision — clear, copy-friendly.

## Top 3 fixes before cloning
1. **F2 + F3 together:** Add `softDelete()`, `restore()`, `activate()`/`deactivate()` events. Codify "every public mutator raises ≥ 1 event" in `domain-scaffolding`. This is the single change that unblocks correct cloning of lifecycle semantics.
2. **F1:** Decide whether `update()` is allowed pre-persist. Either guard with `assertPersisted` or queue and rewrite the event id at save. Document the choice in the entity docblock so cloners do not re-derive it.
3. **F4 + F5:** Tighten the hydration surface — require non-zero `version` in `reconstitute()`, and replace `@internal public` on `assignId` / `bumpVersion` with a hydrator-key parameter. This protects optimistic locking from silent failure across every cloned domain.

---

**Severity counts:** CRITICAL 0 | HIGH 4 (F1–F4) | MEDIUM 5 (F5–F9) | LOW 2 (F10–F11) | INFO 1 (F12). Verdict: READY-WITH-FIXES. **Top finding:** F2/F3 combined — `activate`/`deactivate` raise no events and the entity has no `softDelete()`/`restore()` mutators, so lifecycle transitions leak into the repository and audit trails will be silently incomplete in every cloned domain.
