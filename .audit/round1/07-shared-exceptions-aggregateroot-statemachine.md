# 07 — Shared exceptions, AggregateRoot, StateMachine

## Files audited

- `app/Domain/Shared/Exceptions/DomainException.php`
- `app/Domain/Shared/Exceptions/ValidationException.php`
- `app/Domain/Shared/AggregateRoot.php`
- `app/Domain/Shared/StateMachine/State.php`
- `app/Domain/Shared/StateMachine/StateMachine.php`
- `app/Domain/Shared/StateMachine/InvalidTransition.php`
- `app/Domain/Cookie/Entities/Cookie.php` (consumer)
- `app/Domain/Cookie/ErrorCodes.php`, `app/Domain/User/ErrorCodes.php` (registry evidence)

## Findings

### Exception hierarchy

- **HIGH** — `DomainException.php:36` extends `RuntimeException`; `ValidationException.php:32` extends `InvalidArgumentException`. They share no common base. Application/HTTP layers cannot catch "any domain-layer fault" with one `catch` — they must list both. Add an `App\Domain\Shared\Exceptions\DomainExceptionInterface` (or abstract base) that both implement. Right now `catch (\Throwable)` is the only safe net.
- **HIGH** — No dedicated `InfrastructureException` (or even an interface). Repositories throwing PDO/DB exceptions will bubble up indistinguishable from domain rule violations. CLAUDE.md claims "clear separation between domain rule violations and infrastructure failures" — not honoured by these files. Need at minimum `Shared/Exceptions/InfrastructureException` and a documented rule: repos translate driver exceptions into it.
- **MEDIUM** — `DomainException` is non-`abstract` (`DomainException.php:36`). Callers will `throw new DomainException(...)` directly instead of subclassing per concern. Either mark abstract or document the convention. `InvalidTransition.php:17` is the only subclass — pattern is not established.
- **LOW** — `ValidationException`'s base `InvalidArgumentException` is `\LogicException`. Catching `LogicException` to handle user input feels wrong, but the choice is defensible — flag, don't block.

### ValidationException factories

- **MEDIUM** — Missing `tooLarge` factory. `ValidationException.php:163` has `tooSmall` but no symmetric `tooLarge` for numeric ceilings. `fieldTooLong` covers strings only. Add `tooLarge(string $field, float|int $max, float|int $actual, int $errorCode = 0)`.
- **MEDIUM** — No `custom(string $field, string $message, int $errorCode = 0)` factory. Domains will reinvent this constantly (one-off rules that don't match any other factory). The audit prompt specifically calls it out.
- **LOW** — No `notInSet(string $field, array $allowed, mixed $actual, int $errorCode = 0)` for enum-style validation — common shape, will be hand-rolled in every value object.
- **LOW** — `outOfRange` (`ValidationException.php:146`) accepts `float|int`; `min`/`max`/`actual` can mix (a `float $min` vs `int $actual` produces a confusing `between 1.5 and 10` vs `got 5` message). Consider documenting or normalising.
- **LOW** — `withErrors` (`ValidationException.php:194`) does not validate that the array shape matches `array<string, array<string>>`. A mistake here yields silent corruption of `$this->errors`. Add a runtime assertion or use PHPStan to enforce.

### Error code carrying

- **HIGH** — Integer-only error codes (`DomainException.php:38`, `ValidationException.php:41`) with **no central registry**. `Cookie/ErrorCodes.php:28` defines `COOKIE_VALIDATION_NAME = 101` and `User/ErrorCodes.php:21` defines `USER_VALIDATION_NAME = 100`, `USER_VALIDATION_EMAIL = 101` — codes already collide across domains. There is no way for a log aggregator to ask "what is error 101?" without also knowing the domain.
  - **Fix:** assign each domain a numeric prefix (e.g. Cookie = `1_xxx`, User = `2_xxx`, future Order = `3_xxx`) or carry a `string $errorCode` ("COOKIE.VALIDATION.NAME"). Document the registry in `.claude/documentation/ERROR_CODES.md`.
- **HIGH** — `User/ErrorCodes.php:33` and `:36` literally define duplicate constants (`USER_BUSINESS_RULE_LOCKED = 301`, `USER_BUSINESS_RULE_ACCOUNT_LOCKED = 301`) — aliasing without an enum makes drift inevitable. Use a backed enum (`enum ErrorCode: int implements DomainErrorCode`) so collisions are compiler-detectable.
- **MEDIUM** — `$errorCode = 0` default (`DomainException.php:47`, `ValidationException.php:55`) means "no code". Consumers cannot distinguish "code intentionally 0" from "code not set". Either make `$errorCode` required for the factory methods or use `?int`.
- **MEDIUM** — PHP's native `$code` and the domain `$errorCode` co-exist (`DomainException.php:47`). `parent::__construct($message, $code)` passes the *PHP* code, not the domain one — `getCode()` and `getErrorCode()` return different ints. Devs will reach for `getCode()` and silently get `0`. Document or unify.
- **LOW** — Error codes typed as `int` but the constants use `const int` (PHP 8.3+ typed consts) — fine, but the exception field is plain `int`. Tighten with `readonly` (set in ctor) for safety.

### DomainException factories — completeness

- `invalidState` (`DomainException.php:70`) ✓
- `businessRuleViolation` (`DomainException.php:85`) ✓
- `notFound` (`DomainException.php:100`) ✓
- `concurrentModification` (`DomainException.php:119`) ✓
- **MEDIUM** — Missing common shapes for an ERP template:
  - `unauthorized(string $entityName, string $action, int $errorCode = 0)` (or move to a future `AuthorizationException`)
  - `alreadyExists(string $entityName, string $identifier, int $errorCode = 0)` — distinct from "validation" because it is a runtime collision, not a format problem; today domains throw either `DomainException::businessRuleViolation` or rely on `notFound`'s inverse.
  - `softDeleted(string $entityName, int|string $id, int $errorCode = 0)` — Cookie has `deletedAt`, Customer will too. Worth promoting to shared.
  - `precondition(string $rule, string $details, int $errorCode = 0)` — for guard-clause failures distinct from business rules (e.g. "must be in state X before Y").

### AggregateRoot trait

- `raiseEvent` / `pullEvents` / `peekEvents` / `hasPendingEvents` are correct (`AggregateRoot.php:55-86`). Pull-then-clear is idempotent: second call returns `[]`. ✓
- **MEDIUM** — Memory is bounded only by the lifetime of the entity object. Long-running processes (queue workers, batch jobs) that mutate one aggregate hundreds of times without calling `pullEvents` will accumulate events indefinitely. Document the contract: "repositories MUST drain after every save; long-lived aggregates outside a repository round-trip are not supported." No code-level cap is appropriate.
- **MEDIUM** — `raiseEvent(object $event)` accepts *any* `object` (`AggregateRoot.php:55`). There is no `DomainEvent` marker interface. The dispatcher cannot distinguish a domain event from any random object; tests cannot constrain `peekEvents(): list<DomainEventInterface>`. Add `App\Domain\Shared\Events\DomainEventInterface` and tighten the param.
- **MEDIUM** — No `recordEvent` / `replayEvent` distinction. For future event-sourcing support, reconstitution should NOT raise events (otherwise rehydrating an entity from history re-emits). Right now `Cookie::reconstitute` (`Cookie.php:132`) does not raise, but nothing in the trait prevents misuse. Consider a `replayEvents(array $events)` helper that fills internal state without queuing.
- **LOW** — `pendingEvents` is `private` in the trait (`AggregateRoot.php:47`). If a subclass needs to clear after a failed save attempt mid-handler, there's no escape hatch. Add a `protected function clearPendingEvents(): void`.

### AggregateRoot adoption in Cookie

- **MEDIUM** — Inconsistent usage. `Cookie.php:217` (`decreaseStock`) and `:250` (`increaseStock`) raise `CookieStockChangedEvent`, but:
  - `update()` (`Cookie.php:195`) mutates `name`, `description`, `price`, `stock`, `isActive` with NO event — a `CookieUpdated` should be raised, and a stock change inside `update` should arguably re-route through `setStock`/raise the same event.
  - `activate()` (`Cookie.php:288`) and `deactivate()` (`Cookie.php:297`) flip state silently — no `CookieActivated`/`CookieDeactivated` event. The audit prompt asks specifically: yes, several Cookie methods are still hand-rolling events (or rather, hand-rolling state without events).
  - There is no `CookieCreated` raised from `create()` (`Cookie.php:107`). Convention in DDD: aggregates raise creation events; the repository drains them on first save.
- **LOW** — `Cookie.php:236` raises an event with `$this->id` which may be `null` on a freshly created (unsaved) aggregate. `CookieStockChangedEvent` must accept `?int` or the entity must defer raising until id is assigned. Worth verifying in the event class.

### StateMachine

- **MEDIUM** — Stringly-typed transition table (`StateMachine.php:50-52`). The PHPDoc says `array<string, list<string>>`. If a typo creeps in (`'aproved'` vs `'approved'`) the machine silently treats it as a never-reachable state — there is no validator at construction time. Add `validate(): void` to assert that every target string is also a key (terminal states explicitly mapped to `[]`).
- **MEDIUM** — `State` interface (`State.php:19`) is decorative. The machine accepts `State|string` (`StateMachine.php:58`) and immediately `stateName()`s it back to a string — but the transition table itself is `array<string, list<string>>`. If a domain implements `State` via an enum, the enum cases never appear in the table; comparisons collapse to strings anyway. Either:
  - Make the machine generic over `T extends State` (PHP doesn't natively support, but PHPStan generics do), OR
  - Drop `State` and document: "the transition table is always stringly-typed; enums are convenience for callers."
- **MEDIUM** — Construction is `new StateMachine(...)` per call (`StateMachine.php:18-29` example). The PHPDoc says "the same machine instance is shared across all entities of the same type" but the recommended pattern (`private static function machine(): StateMachine` returning a fresh `new`) does NOT share. Either memoise with a static or change the docs. Today every method call instantiates a fresh table.
- **LOW** — `transition()` (`StateMachine.php:58`) returns `void`. For domains that want to "transition and capture", they have to call `transition` then assign manually (`$this->status = 'approved'`). A `transition(...): string` returning the validated target name would let callers do `$this->status = self::machine()->transition($this->status, 'approved');` — cleaner, single source of truth.
- **LOW** — No support for guarded transitions (e.g. "only allow `posted` → `paid` if total > 0"). Callers will hand-roll guards before calling `transition()`. Acceptable as a v1 scaffold but worth documenting as a known limitation.
- **LOW** — `canTransition` uses `try/catch` on the exception path (`StateMachine.php:73-81`). Functional, but allocating an exception for a boolean check is wasteful in hot loops. Extract a pure `isAllowed(string $from, string $to): bool` and have `transition`/`canTransition` both call it.
- **LOW** — `isTerminal` (`StateMachine.php:91`) returns `true` for any state NOT in the transitions table (because `allowedFrom` returns `[]` for unknown states via the `??` fallback). An unknown state is reported as terminal — silently false-positive. Either throw on unknown state in `isTerminal` or distinguish "unknown" from "terminal".

### InvalidTransition

- `InvalidTransition.php:17` extends `DomainException` ✓ — clean.
- **LOW** — Message format `"Invalid Invoice transition: draft -> aproved. Allowed from draft: approved, cancelled."` (`InvalidTransition.php:31-38`) repeats `$from` twice ("draft -> aproved. Allowed from draft:"). Acceptable for clarity, but slightly noisy. Consider `"... Allowed targets: approved, cancelled."`.
- **MEDIUM** — Carries no error code. `DomainException`'s `$errorCode` (`DomainException.php:38`) stays at `0`. A cross-domain state-transition violation cannot be filtered in logs. `InvalidTransition::create` should accept and forward an `int $errorCode = 0`.
- **LOW** — No accessor for `from` / `to` / `allowed` on the exception object. Callers see the message only — programmatic handling (e.g. a controller suggesting the next valid action) needs to regex-parse the message. Add `getFrom()`, `getTo()`, `getAllowed(): list<string>`.

## Template adoption notes

- Promote a `DomainEventInterface` (or marker) under `app/Domain/Shared/Events/` and constrain `AggregateRoot::raiseEvent` to it. Update Cookie's `CookieStockChangedEvent` to implement it; new domains will follow.
- Introduce `App\Domain\Shared\Exceptions\DomainExceptionInterface` implemented by both `DomainException` and `ValidationException`. Document in `CLAUDE.md` that infrastructure-layer faults must NOT implement it.
- Establish a typed error-code system before more domains are scaffolded — every new domain currently creates a new local `ErrorCodes.php` and collisions are guaranteed (Cookie 101 vs User 101 already). Either:
  - Convert per-domain `ErrorCodes` to backed enums implementing a shared `DomainErrorCode` interface (compile-time uniqueness within a domain), and assign each domain a 4-digit prefix (Cookie 1xxx, User 2xxx) — OR —
  - Move to string error codes ("COOKIE.VALIDATION.NAME"), which scale better and self-document in logs.
- Add the missing `ValidationException` factories (`tooLarge`, `custom`, `notInSet`) and `DomainException` factories (`alreadyExists`, `softDeleted`, `precondition`) before the next domain is scaffolded — otherwise every new domain reinvents them differently.
- Update the `domain-scaffolding` skill so that every entity raises Created/Updated/Deleted/Activated/Deactivated events through `AggregateRoot::raiseEvent` and the matching event class lives in `Events/{EventName}/`. Cookie itself needs a follow-up to add `CookieCreated`, `CookieUpdated`, `CookieActivated`, `CookieDeactivated`.
- Memoise the `StateMachine` instance per entity class (private static cache in the machine() factory) and document the pattern in the skill — otherwise every transition allocates a fresh table.
- Add a validator on `StateMachine` construction that asserts every referenced target state is a key; ship it as a one-time check, optionally only in `ENVIRONMENT !== 'production'`.

## Verdict

The scaffolding is **structurally sound but unfinished**. The trait and the state machine are small, correct, and easy to consume. The exception layer is the weakest piece: no shared interface across the two exception classes, no infrastructure exception type, no central error-code registry, missing common factory shapes, and an active code-collision bug between Cookie and User registries. The AggregateRoot trait is fine in isolation but is under-adopted by Cookie itself (silent state changes in `update`, `activate`, `deactivate`, and no creation event) — the reference domain demonstrates the trait inconsistently, which means scaffolded domains will inherit the same drift.

**Blocking before next domain scaffold:** introduce `DomainExceptionInterface`, `InfrastructureException`, `DomainEventInterface`, fix Cookie's missing events, and resolve the error-code registry collision. Everything else is incremental.
