# r08 — DDD + CQRS adherence review

Scope: review of round-1 audit claims against the actual source. Focus is whether
the code applies the DDD/CQRS patterns it advertises, or merely wears their
shape.

Files spot-checked:
- `app/Domain/Cookie/Entities/Cookie.php`
- `app/Domain/Cookie/Commands/{CreateCookie,UpdateCookie,DeleteCookie,RestoreCookie}/*Handler.php`
- `app/Domain/Cookie/Queries/*/Handler.php`
- `app/Domain/Cookie/Projections/CookieReadModelProjection.php`
- `app/Domain/Cookie/ReadModels/CookieView.php`
- `app/Domain/Shared/AggregateRoot.php`
- `app/Domain/Shared/ValueObjects/{Money,Currency,DocumentNumber,Actor}.php`
- `app/Infrastructure/Bus/EventDispatcher.php`
- `app/Models/Cookie/CookieRepository.php`
- `app/Infrastructure/Persistence/Repositories/UserRepository*`
- `app/Domain/User/Ports/*`

---

## 1. Verified DDD/CQRS violations

### 1.1 Two dispatch models, both wired, neither owned by the aggregate (CONFIRMED — CRITICAL)

The audit's "two competing dispatch models" claim is fair. Source proof:

- `Cookie.php:236-241,259-264` — `decreaseStock`/`increaseStock` raise
  `CookieStockChangedEvent` through `AggregateRoot::raiseEvent`.
- `Cookie.php:195-207` — `update()` mutates five fields and raises nothing.
- `Cookie::activate()` / `deactivate()` (`:288-300`) — silent state flips.
- `Cookie::create()` — no `CookieCreated` raised from the factory.
- `CreateCookieHandler.php:105-110`, `UpdateCookieHandler.php:114-120`,
  `DeleteCookieHandler.php:92-96`, `RestoreCookieHandler.php:59-65` — all four
  handlers `new` lifecycle events by hand and call
  `$this->eventDispatcher->dispatch(...)` directly.
- `CookieRepository.php:130-142` drains via `pullEvents()`, but in practice
  the buffer only contains stock events.

| Event class             | Where raised  | Where dispatched              |
| ----------------------- | ------------- | ----------------------------- |
| CookieStockChangedEvent | Entity        | Repository (`pullEvents`)     |
| CookieCreated/Updated/Deleted/Restored | Handler (new) | Handler (direct dispatch) |

Consequences: an outbox layered behind `pullEvents()` (advertised at
`CookieRepository.php:92-96`) will see stock events only; lifecycle 4/5 fly
direct. `Cookie::update()` is silent w.r.t. events at the aggregate. Every
cloned `/add-domain` inherits the split. Critical.

### 1.2 EventDispatcher swallows listener failures (CONFIRMED — CRITICAL)

`EventDispatcher.php:90-104` wraps every listener call in `catch (\Throwable)`
and logs-and-continues. `TransactionMiddleware`'s docblock promises that
listener exceptions roll back the write. They do not — the exception never
reaches `TransactionMiddleware` because the dispatcher consumed it. This is a
direct CQRS contract violation: the documented atomicity of
"command + event side-effects" is a lie at the code level.

Note: separately defensible as "events are best-effort post-commit" — but the
docs claim the opposite. Pick one.

### 1.3 Optimistic locking is exposed by the aggregate but never used (CONFIRMED — CRITICAL)

- `Cookie::$version` (line 67), `getVersion()` (181), `bumpVersion()` (160-163).
- `UpdateCookieCommand` carries no `expectedVersion`.
- `UpdateCookieHandler.php:72-111` reloads the entity, mutates it, saves it.
  The `WHERE version = ?` clause compares the freshly-loaded value against
  itself; the caller's stale view never enters the equation.

This is DDD theatre: optimistic-lock infrastructure exists, the aggregate
exposes the right surface, but the use-case never threads the version through.

### 1.4 `update()` skips invariant pipeline (CONFIRMED — HIGH)

`Cookie::__construct` (84-96) calls `setStock($stock)` for validation but
assigns `name`, `description`, `price`, `isActive` raw. `Cookie::update()`
repeats the same partial pipeline: `setStock` is called, the other four are
assigned. There is no `assertInvariants()` hook. Any cross-field rule (e.g.
"price ≥ cost") has no canonical place to live; every cloned entity will
inherit this gap.

### 1.5 Aggregate-event ID-null window (CONFIRMED — CRITICAL footgun)

`decreaseStock`/`increaseStock` raise `CookieStockChangedEvent(cookieId: $this->id, ...)`
before `assignId()` runs. For a freshly `create()`d Cookie, `$this->id === null`.
The event class types `cookieId` as `?int`, papering over the bug at the type
system. Any cloned domain that calls `decreaseStock` between `create()` and
`save()` will emit an unroutable event.

### 1.6 Ports leaking into Infrastructure (CONFIRMED — HIGH)

- `app/Domain/User/Ports/RateLimitInterface.php:7` imports
  `App\Infrastructure\Auth\ValueObjects\RateLimitResult` — a Domain port that
  imports an Infrastructure type. Direct DIP inversion: the Domain is supposed
  to define the contract and let Infrastructure adapt, not the other way
  round.
- `Cookie` and `User` entities and User VOs import
  `App\Infrastructure\Logging\DomainLogger`. Domain → Infrastructure import.
  Cookie's entity isn't logging today, but several User entity / VO files
  reach into Infrastructure to log. Same DIP inversion.

### 1.7 User repository interface lives in Infrastructure (CONFIRMED — HIGH)

- Cookie does it right: `app/Domain/Cookie/Ports/CookieRepositoryInterface.php`.
- User does it wrong: `app/Infrastructure/Persistence/Repositories/UserRepositoryInterface.php`
  (and `UserRepository.php` next to it).
- Worse, `RegisterUserHandler`, `GetUserByIdHandler`, `GetUserByEmailHandler`
  depend on the *concrete* `UserRepository`, not the interface
  (`UpdateUserHandler` does use the interface — inconsistent within the
  domain).

Per hexagonal architecture, the **port** belongs in the Domain (or Application
layer); the **adapter** (`UserRepository`) belongs in Infrastructure. The
Cookie convention is canonical for this codebase, User is the outlier.

### 1.8 Aggregate-root contract is a trait, not a type (CONFIRMED — HIGH)

`AggregateRoot.php` is a trait. Consequences:
- `raiseEvent(object $event)` accepts any object — no `DomainEventInterface`
  marker, no compile-time guarantee.
- Domain services can't typehint "an aggregate" generically.
- The promise "repositories MUST drain after every save" is documentation, not
  enforceable.

This is salvageable (mark the trait `@internal` to subclasses of an
`AggregateRootInterface`, add a `DomainEventInterface` marker) but currently
the contract exists only as folklore.

### 1.9 Value object discipline is mixed (CONFIRMED — see §7 for full table)

`Money` accepts `?Currency` and silently defaults to USD; `DocumentNumber`
and `AttachmentRef` have **public constructors with zero validation**;
`Actor::system($label)` accepts arbitrary string. Three of seven shared VOs
leak.

---

## 2. Disputed violations (where the audit overshoots)

### 2.1 "Query handlers return entities, not DTOs" → ACTUAL violation, but low severity

The audit flags `GetCookieByIdHandler::handle(): ?Cookie`,
`GetAllCookiesHandler: array<int, Cookie>`, etc. (round1 HIGH §8). This *is*
technically incorrect for textbook CQRS — the read side should return
projection-friendly DTOs (`CookieView`).

But context softens it:

- `CookieView` exists (`app/Domain/Cookie/ReadModels/CookieView.php`) and is
  even referenced by docblocks — so the team knows the right shape.
- `Cookie` entity is a `final` class with read-only accessors; in practice it
  serves as a poor-man's DTO. There is no command/state surface usable by
  consumers (no public setters; constructor is private).
- The actual CQRS violation is that **the write-side aggregate** is being used
  as the read-side projection, breaking separation. But the *failure mode*
  (mutability leaking to read consumers) is closed off by the entity being
  read-only after construction.

Verdict: real CQRS smell, but "violation" overstates the operational risk.
Worth tightening before scaling. Call it MEDIUM, not HIGH.

### 2.2 "Repository in app/Models violates DDD" → mostly cosmetic

CookieRepository lives at `app/Models/Cookie/CookieRepository.php`; `User`'s
adapter lives at `app/Infrastructure/Persistence/Repositories/`. The audit
treats this as a serious DDD violation.

It is *not* the location of the implementation that matters — implementations
of ports are Infrastructure regardless of which folder they live in. CodeIgniter's
auto-discovery convention puts CI-Model-backed repositories under `app/Models/`.
The actually problematic facts are:

1. The **port** (`CookieRepositoryInterface`) is correctly in
   `app/Domain/Cookie/Ports/`. That is what hexagonal architecture requires.
2. The User port is misplaced — that *is* a real violation (see §1.7).
3. The Cookie convention is reasonable IF documented; today it is not.

Verdict: User has a real DDD violation; Cookie's choice is a convention
mismatch, not a DDD failure. Sloppy organisation, not a structural defect.

### 2.3 "Read model never read = structurally pointless" → fair complaint, but misnamed

`CookieReadModelProjection` writes to `cookie_read_model`; no query handler
reads from it (`GetCookieByIdHandler.php:54` goes through
`CookieRepository::findById` → write table). `ProjectionRegistry` is dead
code (only test references — confirmed by grep). `RebuildProjections` is the
only writer outside the never-wired event subscription.

The audit calls this "fiction"; that is overheated. It is more honestly:

- **Working scaffolding**: the projection class, the registry, the rebuild
  command, the read-model migration all exist and are individually correct.
  They are not "fake"; they are *unwired*.
- **Structurally pointless TODAY** for runtime queries, yes. Reading from the
  write table defeats the point of the projection.
- **Useful as a template** *if* the next clone fixes the wiring. The shape
  is right.

Practical verdict: not safe to ship as the canonical example of "how to do
read models" — every clone will faithfully reproduce the wiring gap. But
"structurally pointless" misreads the situation: it is structurally *correct
and incomplete*. The fix is two lines (`registerProjections` hook in
ServiceProvider) plus swapping query handlers to a read-model repo.

### 2.4 "Handler-side dispatch is pragmatic for create" → real tension, but inconsistent solution

The pragmatic argument: the entity has no `id` until after `save()`, and
`CookieCreatedEvent.cookieId` needs to be non-null. Raising from the entity
at `create()` time forces a deferred-id mechanism. Fair.

But the chosen solution is worse than either alternative: `update()`,
`activate()`, `deactivate()` raise nothing (no id problem there), and stock
events still suffer the null-id bug anyway (§1.5). Not "pragmatic" — just
inconsistent. Pick entity-side (with id-stamp on drain) or handler-side
(uniformly). Today is neither.

---

## 3. Missed violations

### 3.1 `Cookie::reconstitute()` cannot rehydrate broken rows

`reconstitute()` (`Cookie.php:132-152`) calls the private constructor, which
calls `setStock()`. A negative-stock row from a legacy import or a
half-committed migration cannot even be *loaded* to be repaired — the repo
throws on hydration. Round 1 mentions this (01-Finding #4 HIGH). I confirm
it is real and underestimated: the *only way out* of a corrupted row is
direct SQL surgery. For an ERP template that will live decades, this is a
data-recovery nightmare.

Add a `forceSet` / `replay` path that bypasses invariants.

### 3.2 `Cookie::reconstitute()` raises events on re-load

Today it does not (the private ctor doesn't `raiseEvent`, only `setStock`
which is silent). But there is no enforcement; a future contributor adding
"raise CookieReactivated when reconstitute sees `isActive=true`" would
poison every replay. The trait has no `replayEvents` / `recordEvent`
distinction (07-Finding §AggregateRoot.MEDIUM). Add one before event-sourcing
is added.

### 3.3 No `DomainEventInterface`, no `AggregateRootInterface`

The audit flags this. I want to underline it: this is the single highest-ROI
fix in the codebase. Two empty marker interfaces would:

- Constrain `raiseEvent` parameter from `object` to `DomainEventInterface`.
- Let dispatchers/outbox/audit components type their listeners.
- Allow `instanceof` checks for "is this aggregate?".
- Cost zero runtime, zero behaviour change.

The fact that they are absent after 15 round-1 reports suggests they will
ship.

### 3.4 `CookieRepository` is also responsible for event dispatch

Round 1 treated `dispatchPendingEvents` (`CookieRepository.php:130-142`) as a
positive. Inverted: it violates single responsibility. The repository persists;
a `UnitOfWork` (or the bus) should drain *after* commit. Today's `save()`
dispatches inside the transaction window — listeners can see rows about to
be rolled back, and any listener exception is silently swallowed (§1.2).
Worst-of-both-worlds position.

### 3.5 `RestoreCookieHandler` is the only handler carrying an `Actor`

This is in the audit, but its DDD implication isn't:

The asymmetry signals that the team thought about actor attribution while
writing the restore command, then forgot when adding the others. This is a
*template* problem — the next domain to be scaffolded will copy whichever
command the author looks at first, and the inconsistency will spread.

The right fix is to mandate `Actor` on the base `CommandInterface` (or via a
trait), so it cannot be forgotten.

### 3.6 `assignId` and `bumpVersion` are `public`

`@internal` in DocBlock is decorative. Any handler can call
`$cookie->bumpVersion()` and defeat optimistic locking; any malicious code
path can reassign id pre-save. Visibility is the wrong layer for this — a
package-private trait, or a dedicated `EntityHydrator` interface owned by
the Infrastructure layer, would close it.

### 3.7 The "Ports under Domain" pattern is enforced in one place and broken in another

Cookie: `app/Domain/Cookie/Ports/CookieRepositoryInterface.php` ✓
User: `app/Infrastructure/Persistence/Repositories/UserRepositoryInterface.php` ✗

Per Alistair Cockburn hexagonal arch: **ports = interfaces that the application/domain depends on**.
They belong in the layer doing the depending, i.e. Domain (or Application).
Adapters (concrete implementations using DB drivers, HTTP clients, queue
libraries) belong in Infrastructure.

The Cookie placement is canonical. User violates this. The template's
inconsistency means a future domain author will pick whichever convention
they read first. **The User layout is wrong; do not preserve it as
"reasonable convention".**

---

## 4. Value object discipline — per-VO scoring

| VO              | Immutable? | Self-validating? | Notes                                                                  |
| --------------- | ---------- | ---------------- | ---------------------------------------------------------------------- |
| `CookieName`    | ✓          | ✓                | `equalsIgnoreCase` uses `strtolower` (not `mb_`)                       |
| `CookiePrice`   | ✓          | partial          | Bounds are USD-cents regardless of currency; defaults to USD silently  |
| `Money`         | ✓          | partial          | Currency optional → silent USD default; `json_encode` drops `amountMinor` (private) |
| `Currency`      | ✓          | ✓                | Clean; symbol overrides table is right                                 |
| `Email`         | ✓          | ✓                | Imports Infrastructure `DomainLogger` (DIP inversion)                  |
| `Actor`         | ✓          | partial          | `system($label)` accepts arbitrary unbounded string (log injection)    |
| `DateTimeValue` | ✓          | partial          | No `DateTimeZone('UTC')`; `equals()` uses `===` object identity        |
| `DocumentNumber`| readonly   | ✗                | **Public ctor, zero validation**; `formatted` may disagree with `value`|
| `AttachmentRef` | readonly   | ✗                | **Public ctor, zero validation**                                       |
| `Permission`    | ✓          | ✓                | (not deeply reviewed)                                                  |

VOs that properly leak: `Money` (currency default), `Actor` (label),
`DateTimeValue` (timezone, equality), `DocumentNumber` (public ctor),
`AttachmentRef` (public ctor), `CookiePrice` (bounds vs currency).

VOs that hold the line: `CookieName`, `Currency`, `Email` (modulo the
Infrastructure import).

The shared layer is weaker than the Cookie layer. Since shared VOs are
imported by every future domain, **the leakier ones cause the most
multiplied harm**.

---

## 5. Verdict on pattern-adherence story

The codebase **wears the shape** of CQRS+DDD but **does not consistently
apply the patterns**.

Specifically:

- **Aggregate Root**: defined as a trait, not a type. Used inconsistently in
  Cookie itself (stock = yes; create/update/activate/deactivate = no). The
  reference domain teaches the wrong lesson by example.
- **Domain Events**: split between aggregate-raised and handler-constructed.
  No marker interface. Dispatcher swallows failures. No idempotency, no
  versioning. Outbox writer exists but is dead code.
- **CQRS read side**: projection class is correct in isolation, but never
  subscribed to events and never read by queries. Reads go straight through
  the write-side repository. "Scaffolding-grade", not "operational".
- **CQRS write side**: commands and handlers are clean (one handler per
  command, readonly DTOs, type-safe). Optimistic locking is exposed but
  not threaded through `UpdateCookieCommand`. Actor attribution is on one
  command of four.
- **Repository pattern**: Cookie does it right (port in Domain, adapter in
  Models). User does it wrong (interface in Infrastructure, handlers
  depending on concrete adapter). Inconsistency between the two reference
  domains is itself a violation — the next clone has two contradictory
  templates to copy from.
- **Ports under Domain**: this is canonical hexagonal. Cookie does it; User
  does not. **The template should pick Domain/Ports and enforce it via the
  `/add-domain` scaffolder.**
- **Value Objects**: 6/10 properly disciplined; 4/10 leak in ways that will
  multiply across cloned domains (Money default currency, DateTimeValue
  timezone, DocumentNumber + AttachmentRef public ctors, Actor label).

The audit's verdict ("not safe to clone") is correct, but its framing
("structural rot") overstates it. The actual problem is **incompleteness**:
the patterns are in the code, but not fully wired, not consistently applied,
and not enforced by the type system. Each of these is a small fix in
isolation; together they form a pattern of "we wrote the file but didn't
finish the contract".

Specific recommended fixes, ordered by ROI:

1. Introduce `DomainEventInterface` + `AggregateRootInterface` (2 files, no
   behaviour change).
2. Move all lifecycle events into the entity; delete handler-side `dispatch`
   calls; rely on `pullEvents()`.
3. Decide event-on-transaction semantics (rethrow vs swallow) and align
   `EventDispatcher` + `TransactionMiddleware` docs.
4. Add `expectedVersion` to `UpdateCookieCommand`; thread through the
   `WHERE version = ?` clause.
5. Move `UserRepositoryInterface` to `app/Domain/User/Ports/`. Fix
   handlers to depend on the interface.
6. Wire `CookieReadModelProjection` to live events; swap query handlers to
   a read-model repository.
7. Lock down VO constructors: `DocumentNumber`, `AttachmentRef` private +
   named factories; `Money`/`CookiePrice` require explicit `Currency`;
   `Actor::system` validate label.
8. Visibility of `assignId` / `bumpVersion` → package-private.
9. Mandate `Actor` on every command (base trait or interface).

None of these are research-grade. All of them are mechanical. The verdict
on the pattern-adherence story: **architecturally sound, operationally
unfinished**. Block clone until items 1-6 ship.
