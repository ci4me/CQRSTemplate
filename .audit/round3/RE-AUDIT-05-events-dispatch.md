# RE-AUDIT 05 — Domain Events & Dispatch Lifecycle

**Slice:** All Cookie events + handlers + dispatcher + shared envelope/changeset
**Reviewer:** cqrs-specialist (re-audit)
**Date:** 2026-05-23
**Round-3 baseline:** `.audit/round3/05-events-dispatch.md` (2 H / 4 M / 3 L / 1 I)
**PRs since baseline:** #32 (E04), #35 (E07). **PR #38 (E12.5 — handler-side `ProcessedEventStore`) is NOT present on the audited branch.**
**Source files reviewed:** 16 — 7 Cookie event DTOs + 7 handlers + `AbstractDomainEvent` + `CookieChangeSet` + `DomainEventInterface` + `EventDispatcher` + entity raise-sites in `Cookie.php`.

## TL;DR

E04 is the structural win: every Cookie event now extends `AbstractDomainEvent` carrying `{eventId(UUIDv7), occurredAt(UTC DateTimeImmutable), actorId, aggregateType, aggregateId}`, implements `\JsonSerializable`, and merges its payload over `parent::jsonSerialize()`. `CookieStockChangedEvent::$cookieId` is now `int` (was `?int`); `CookieRestoredEvent` dropped the stringly-typed timestamp; the two snapshot-bearing events now consume the whitelisted `CookieChangeSet` VO; the entity raises lifecycle events on `activate()`/`deactivate()`; `EventDispatcher::setWarnOnNoListeners(bool)` opt-in hook converts F8's silent zero-listener short-circuit into a `debug` diagnostic. **Residual gap is F5 (b):** the handler-side idempotency table (`ProcessedEventStore` + `DatabaseProcessedEventStore` + migration) is not on this branch — the eventId envelope is in place but consumers cannot yet dedupe.

## Verdict

**READY** — clone-safe today for log-only handlers; one informational follow-up (F5-residual) to land before any side-effect handler (email/webhook) is added.

## Findings

### F1 — RESOLVED — Event payload asymmetry (was HIGH)
- **Evidence:** `AbstractDomainEvent` at `app/Domain/Shared/Events/AbstractDomainEvent.php:51-71` carries the 5-field envelope and is extended by all 7 Cookie events. Each forwards via `parent::__construct(...)` using named arguments. `AbstractDomainEvent::newId()` centralises UUIDv7 generation. Every event overrides `jsonSerialize()` via `array_merge(parent::jsonSerialize(), [...])` for deterministic on-the-wire shape.

### F2 — RESOLVED — `CookieStockChangedEvent::$cookieId` (was HIGH)
- **Evidence:** `CookieStockChangedEvent.php:47` — `public int $cookieId` (non-nullable). The entity guards via `CookieStateAssertions::ensurePersisted()` and re-asserts inside `changeStock()` (`\assert($this->id !== null, ...)`) — defense in depth.

### F3 — RESOLVED — `CookieRestoredEvent::$restoredAt` stringly-typed timestamp (was MED)
- **Evidence:** The `string $restoredAt` field is gone; the envelope's `\DateTimeImmutable $occurredAt` is canonical, mirrored in the handler at `CookieRestoredEventHandler.php:38`. Docblock cites slice 05/F3 for the deliberate removal.

### F4 — RESOLVED — Unbounded snapshot arrays / PII risk (was MED)
- **Evidence:** `CookieChangeSet` enforces a 9-key whitelist (`id, name, description, price_minor, price_currency, stock, is_active, version, deleted_at`) at construction. Unknown keys throw `\InvalidArgumentException`. `CookieUpdatedEvent` and `CookieDeletedEvent` consume `CookieChangeSet` directly.

### F5 — PARTIALLY RESOLVED (residual) — eventId / handler-side idempotency (was MED)
- **Evidence (resolved half):** Every event has a UUIDv7 `eventId`. Every Cookie handler logs `'event_id' => $event->eventId`.
- **Evidence (residual gap):** **`ProcessedEventStoreInterface`, `DatabaseProcessedEventStore`, and the `processed_events` migration are NOT present in the tree** (verified via `Grep ProcessedEventStore` — only `.audit/` markdown matches). PR #38 (E12.5) is open but not merged into the audited branch.
- **Severity downgraded LOW → MED-residual:** Three older handlers still carry "Future extensions could include: Email notification / webhooks" comments. Without the dedupe table, a cloner who replaces the `info(...)` body per the suggestion will double-send on `EventOutboxRelay` retry.
- **Suggested fix:** Land E12.5 OR strike the "send notification" lines and replace with an inline doc-note.

### F6 — RESOLVED — Placeholder `__construct.` docblocks (was MED)
- **Evidence:** `Grep '__construct\.|__invoke\.' app/Domain/Cookie/Events` returns zero matches. Per-parameter docblocks are now on par with `CookieCreatedEvent`.

### F7 — OPEN (LOW, unchanged) — `EventDispatcher` not `final`, comment cites only PHPUnit
- **Evidence:** Still non-final. Class docblock still frames the carve-out as "PHPUnit's mock generator cannot double a final class" without an `@internal` tag.
- **Suggested fix:** Add `@internal Subclassing reserved for test doubles only`.

### F8 — RESOLVED — Silent zero-listener dispatch (was LOW)
- **Evidence:** `EventDispatcher.php:124-129` introduces `setWarnOnNoListeners(bool)` that returns the previous value. Dispatcher short-circuit emits a `debug` line with `event_class` + `correlation_id` when the flag is on.

### F9 — OPEN (LOW, unchanged) — Stringly-typed `cookiePrice` on Created/Updated events
- **Evidence:** `CookieCreatedEvent.php:58` `public string $cookiePrice`; `CookieUpdatedEvent.php:44` same. Docblocks describe as "decimal price string" without precision/currency. The brief explicitly notes E09 is pending.
- **Suggested fix:** Pass `CookiePrice` VO directly OR split into `priceMinor: int` + `currency: string`.

### F10 — OPEN (INFO, unchanged) — `CookieReadModelProjection.php.example` is the right call
- **Evidence:** Retains the 40-line ASCII-bordered "REFERENCE-ONLY" header. `Glob app/Domain/Cookie/Projections/*` returns only the `.example` file.

## What is correct / praiseworthy (delta since round 3)

- **`AbstractDomainEvent` is exemplary.** Public-static `newId()` centralises UUIDv7 generation. `\JsonSerializable` + sibling `toArray()` give the outbox writer a clean serialisation contract.
- **`CookieChangeSet::ALLOWED_KEYS` is a clone-safety speed bump.** The whitelist enforcement is `\InvalidArgumentException` at construction.
- **`Cookie::buildLifecycleEvent()` factory** collapses Activated/Deactivated/Restored construction into one place.
- **`EventDispatcher::setWarnOnNoListeners()` matches the `setRethrowOnListenerFailure()` pattern** — both return the previous value for `finally`-block restoration.

## Top fixes before next slice

1. **F5-residual — land E12.5.** Either merge the planned `ProcessedEventStore` + migration, OR strike the "send notification" comments and replace with an inline note.
2. **F7 — annotate `EventDispatcher` non-finalness** with `@internal Subclassing reserved for test doubles only`.
3. **F9 — replace stringly-typed `cookiePrice`** with `priceMinor: int` + `currency: string`.

---

**Severity counts (re-audit):** 0 CRITICAL, 0 HIGH, 1 MEDIUM (F5-residual), 2 LOW (F7, F9), 1 INFO (F10). Net delta vs round 3: −2 HIGH, −3 MEDIUM, −1 LOW.
**Top finding:** F5-residual — the eventId envelope is in place but the handler-side `ProcessedEventStore` is not.
