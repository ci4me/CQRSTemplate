# 05 — Domain Events & Dispatch Lifecycle

**Slice:** All Cookie events + handlers + projection wiring
**Reviewer:** cqrs-specialist
**Date:** 2026-05-22
**Source files reviewed:** 12 (5 event DTOs, 5 handlers, projection example, dispatcher, outbox relay, service provider, AggregateRoot trait, DomainEventInterface)

## TL;DR
Round 1's two CRITICALs are FIXED: `CookieRestoredEventHandler` now exists and is subscribed (`CookieServiceProvider.php:212-215`), and the projection was deliberately demoted to `.php.example` with a clear "how to re-enable" header — that defuses the "silently dead read model" trap. The dispatch lifecycle itself (in-process dispatch, transactional outbox via `EventOutboxWriter` → `EventOutboxRelay` with envelope versioning, rethrow-on-failure toggle for transactional flow, security-gated rehydrate via `DomainEventInterface`) is genuinely strong. Remaining defects are **event-shape inconsistencies** that will sed-clone into every new domain: payload asymmetry (no `eventId`, no `occurredAt`, no actor on some events), nullable `cookieId` on `CookieStockChangedEvent`, `string` timestamp on `CookieRestoredEvent`, unbounded `scalar|null` snapshot arrays, and zero idempotency keys on the handler side.

## Verdict
**READY-WITH-FIXES**

## Findings

### F1 — HIGH — Event payloads are asymmetric across the 5 Cookie events (no template for "what every event must carry")
- **Location:**
  - `app/Domain/Cookie/Events/CookieCreated/CookieCreatedEvent.php:42-47` — no actor, no timestamp
  - `app/Domain/Cookie/Events/CookieUpdated/CookieUpdatedEvent.php:28-35` — has `updatedBy`, no timestamp
  - `app/Domain/Cookie/Events/CookieDeleted/CookieDeletedEvent.php:25-30` — has `deletedBy`, no timestamp
  - `app/Domain/Cookie/Events/CookieRestored/CookieRestoredEvent.php:17-22` — has `restoredBy` + `restoredAt` (string)
  - `app/Domain/Cookie/Events/CookieStockChanged/CookieStockChangedEvent.php:23-28` — no actor, no timestamp
- **Observation:** Five sibling events, five different envelope shapes. None of them implement a common base offering `eventId`/`occurredAt`/`actorId`. `DomainEventInterface` is a pure marker (intentionally per the docblock).
- **Why this is a template defect:** A developer cloning Cookie for `Order`/`Invoice`/`Product` will pick whichever neighbour event they open first and inherit that event's omissions. Audit consumers, the outbox relay, and any downstream tracing will all see ragged metadata. The `CorrelationIdService` in `EventOutboxRelay::processRow()` partially compensates (line 137-140), but that only restores the dispatcher-side correlation_id — not who-did-what or when.
- **Suggested fix:** Either (a) abstract base `readonly` class `AbstractDomainEvent` exposing `public readonly string $eventId; public readonly \DateTimeImmutable $occurredAt; public readonly ?int $actorId;` extended by all events, or (b) extend `DomainEventInterface` with required getters. Mandate `Ramsey\Uuid` event ids for replay deduplication.

### F2 — HIGH — `CookieStockChangedEvent::$cookieId` is `?int` (nullable) — a stock-change without an aggregate id is semantically impossible
- **Location:** `app/Domain/Cookie/Events/CookieStockChanged/CookieStockChangedEvent.php:24`
- **Observation:** `public ?int $cookieId`. Forces every downstream consumer to null-check (the projection example does so at line 356-358) and lets the entity construct a nonsense event.
- **Why this is a template defect:** Round 1 flagged this as MEDIUM — still present. A cloner will copy this pattern (`?int $orderId`, `?int $invoiceId`) into every aggregate-id event. By the time stock can change, the aggregate must have an id; the nullable type lies about the contract.
- **Suggested fix:** `public int $cookieId`. Enforce in the entity (assert `$this->id !== null` before raising).

### F3 — MEDIUM — `CookieRestoredEvent::$restoredAt` is a raw `string` while siblings have no timestamp at all
- **Location:** `app/Domain/Cookie/Events/CookieRestored/CookieRestoredEvent.php:20`
- **Observation:** `public string $restoredAt`. No format documented, no `DateTimeImmutable`. The four other events do not carry a timestamp at all.
- **Why this is a template defect:** This is the only event with a timestamp, and it uses the worst possible type. A cloner copying `CookieRestoredEvent` will propagate "string timestamp" through every domain. The envelope already carries `occurred_at` (`EventOutboxWriter` docblock lines 26-31) — events should not be re-stamping it inconsistently in the payload itself.
- **Suggested fix:** Either drop the field (occurred_at lives in the envelope) or standardise as `\DateTimeImmutable` on the abstract base from F1.

### F4 — MEDIUM — Unbounded `array<string, scalar|null>` snapshots on Updated/Deleted invite PII leakage on clone
- **Location:**
  - `app/Domain/Cookie/Events/CookieUpdated/CookieUpdatedEvent.php:32-33` — `previousState`, `newState`
  - `app/Domain/Cookie/Events/CookieDeleted/CookieDeletedEvent.php:28` — `snapshot`
- **Observation:** No schema, no allow-list, no redaction. The current handlers log only `cookieId`/`cookieName`/`price` so the leak is latent today, but the event itself accepts any column. The outbox writer serialises the entire payload to JSON in `event_outbox` — that table becomes a regulatory liability the moment a cloner adds an `email`/`phone`/`cost` column.
- **Why this is a template defect:** Cookies have no PII, so the danger is invisible until someone clones to `Customer`/`Employee`. The shape signals "dump anything here". Combined with `LogContextSanitizer`-free handler logging, the outbox + log aggregators retain the data forever.
- **Suggested fix:** Replace the loose `array<string, scalar|null>` with a typed `CookieChangeSet` value object that whitelists which fields may be snapshotted; document this is a "public state" payload, not an entity dump.

### F5 — MEDIUM — No event has an `eventId`; handlers carry no idempotency guard
- **Location:** every event DTO + every handler (`CookieCreatedEventHandler.php:45-54`, `CookieUpdatedEventHandler.php:45-53`, `CookieDeletedEventHandler.php:48-56`, `CookieRestoredEventHandler.php:27-36`, `CookieStockChangedEventHandler.php:26-35`)
- **Observation:** Today every handler is a logger and so accidentally idempotent. But the outbox + relay design supports retries with backoff (`EventOutboxRelay::onDispatchFailure()` at line 359-389 — up to 6 attempts). Handlers have no `processed_event_ids` table to dedupe against. The "future extensions" comments inside each handler explicitly suggest webhooks/emails — which will double-send on retry.
- **Why this is a template defect:** The relay's retry contract is in tension with the handler template. A cloner adding "send notification email" to `CookieUpdatedEventHandler` per the in-source comment will produce duplicate emails on backoff retry. The template offers no helper, no `IdempotencyStore`, no contract docblock warning.
- **Suggested fix:** Either (a) add an `eventId` (UUID) to every event via the F1 abstract base + a `handler-side` idempotency table, OR (b) remove the "send notification" comments from handler stubs and replace with a CLAUDE.md note: "side-effect handlers MUST consume an at-most-once channel — never put them here without an idempotency key".

### F6 — MEDIUM — `CookieRestoredEvent` and `CookieStockChangedEvent` constructor docblocks read literally `__construct.` (placeholder text)
- **Location:**
  - `app/Domain/Cookie/Events/CookieRestored/CookieRestoredEvent.php:14-16`
  - `app/Domain/Cookie/Events/CookieStockChanged/CookieStockChangedEvent.php:20-22`
  - Same for `CookieRestoredEventHandler.php:16-18, 24-26`, `CookieStockChangedEventHandler.php:16-18, 23-25`
- **Observation:** Other events carry rich docblocks explaining each parameter (`CookieCreatedEvent.php:34-41`). These two and their handlers carry only the auto-generated `__construct.` / `__invoke.` stubs.
- **Why this is a template defect:** Inconsistent documentation density across "the reference template". A cloner sees half-documented events and concludes "minimal docblocks are fine".
- **Suggested fix:** Bring Restored + StockChanged up to the Created/Updated/Deleted standard.

### F7 — LOW — `EventDispatcher` is not `final` (explained in docblock) — but the comment cites only PHPUnit, not the production contract
- **Location:** `app/Infrastructure/Bus/EventDispatcher.php:42` and class docblock lines 39-41
- **Observation:** Class is non-final solely so PHPUnit can mock it. Tests "subclass to inject failing listeners". Production callers are told to depend on the interface.
- **Why this is a template defect:** Cloners reading "NOT final" will not understand this is a testing concession and may reasonably extend it for production behaviour. The marker for "test-only" subclassing should be an `@internal` tag or a sealed-with-allowlist comment.
- **Suggested fix:** Add `@internal Subclassing reserved for test doubles only` and consider Slevomat's `RequireFinalClass` exception.

### F8 — LOW — `dispatch()` short-circuits silently when an event has zero listeners
- **Location:** `app/Infrastructure/Bus/EventDispatcher.php:129-131`
- **Observation:** `if (!isset($this->listeners[$eventClass])) { return; }`. Round 1 found this combined with the missing Restored subscription to silently drop events. The Restored subscription is now wired, but the silent-drop behaviour remains and there is no `dev`/`debug` toggle that logs "event dispatched with no listeners".
- **Why this is a template defect:** A cloner adding a new event but forgetting to subscribe in their `FooServiceProvider::registerEvents()` will get zero feedback. The first symptom will be a missing audit row.
- **Suggested fix:** Optional `$dispatcher->setWarnOnNoListeners(true)` in dev/test environments that logs at `debug` level.

### F9 — LOW — `CookieCreatedEvent::$cookiePrice` is `string`, `CookieUpdatedEvent::$cookiePrice` is `string`, but the docblocks don't agree on format
- **Location:** `CookieCreatedEvent.php:39` ("Decimal price string"), `CookieUpdatedEvent.php:23` ("New decimal price string")
- **Observation:** Both call it a decimal string, but neither specifies the precision, currency, or `Money`/`CookiePrice` correspondence. `CookieReadModelProjection` (example) at line 425-427 stores `price_minor` (int) and `price_decimal` (string) separately from the event.
- **Why this is a template defect:** Stringly-typed money on an event boundary is a classic template smell. A cloner will inherit "decimal-string price" with no documented precision. If the entity uses `Money` minor-units, the event should carry both `priceMinor: int` and `currency: string`, not a freeform decimal string.
- **Suggested fix:** Either pass the `CookiePrice` VO directly (it is readonly and serialisable) or split into `priceMinor` + `currency` on the event.

### F10 — INFO — `CookieReadModelProjection.php.example` is the right call, and the deprecation header is exemplary
- **Location:** `app/Domain/Cookie/Projections/CookieReadModelProjection.php.example:1-40`
- **Observation:** The 40-line ASCII-bordered header explains exactly why it is inert, references the stabilization plan, lists the five steps to re-enable, and notes that `CookieRepository`/`CookieQueryRepository` collapsed onto a single table. This single-handedly defuses round 1's CRITICAL "projection silently dead" finding for cloners. Recommend other "reference-only" examples in the template follow this pattern.

## What is correct / praiseworthy
- **`DomainEventInterface` as security gate.** `EventOutboxRelay::rehydrate()` lines 315-321 refuse to instantiate any class that does not implement the marker — defeats the "hostile event_class column" attack. Mentioned explicitly in the interface docblock. Excellent.
- **Versioned envelope (SV-1).** `EventOutboxWriter`/`EventOutboxRelay::decodeEnvelope()` lines 214-269 handle legacy + v1 envelopes and dead-letter forward-incompat rows (`unsupported_schema` status). Schema-evolution playbook is in the writer docblock. Best-in-class for a template.
- **Transactional dispatch story.** `EventDispatcher::setRethrowOnListenerFailure()` paired with `TransactionMiddleware` lets synchronous handlers fail the whole command and roll back. Default "log-and-continue" preserved for CLI/test paths. Returning the previous value for `finally` restoration handles nested commands.
- **`AggregateRoot` trait** uses `pullEvents()` (drain-and-clear) and constrains `raiseEvent()` to `DomainEventInterface` — prevents accidental enqueue of random objects.
- **`CookieRestoredEventHandler` now exists and is subscribed.** Round 1's CRITICAL #2 is closed; the wiring comment at `CookieServiceProvider.php:208-214` documents the original bug for posterity.
- **`describeListener()` in dispatch error path** (lines 186-204) gives operators readable listener identity in failure logs, not just "Closure".

## Top 3 fixes before cloning
1. **Introduce `AbstractDomainEvent` (or extend `DomainEventInterface`) carrying `eventId` (UUID), `occurredAt` (DateTimeImmutable), and `actorId`** — eliminates F1, F3, F5 in one stroke and gives the outbox/handlers a real idempotency key.
2. **Make `CookieStockChangedEvent::$cookieId` non-nullable (`int`)** — F2. Trivial change, removes a nonsense state and a defensive null-check that will be sed-cloned into every aggregate-id event.
3. **Replace `array<string, scalar|null>` snapshots with a typed `CookieChangeSet` value object that whitelists allowed fields** — F4. Pre-empts PII leakage in the outbox + log aggregators the moment the template is cloned to a domain with personal data.

---

**Severity counts:** 0 CRITICAL, 2 HIGH, 4 MEDIUM, 3 LOW, 1 INFO
**Top finding:** F1 — event payload asymmetry across the 5 sibling events; no abstract base / required `eventId` + `occurredAt` + `actorId`, so every cloned domain will inherit the inconsistency and the relay's retry contract has no idempotency anchor.
