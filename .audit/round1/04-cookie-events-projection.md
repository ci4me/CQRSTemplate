# 04 — Cookie events + projection

## Files audited

- `app/Domain/Cookie/Events/CookieCreated/CookieCreatedEvent.php`
- `app/Domain/Cookie/Events/CookieCreated/CookieCreatedEventHandler.php`
- `app/Domain/Cookie/Events/CookieUpdated/CookieUpdatedEvent.php`
- `app/Domain/Cookie/Events/CookieUpdated/CookieUpdatedEventHandler.php`
- `app/Domain/Cookie/Events/CookieDeleted/CookieDeletedEvent.php`
- `app/Domain/Cookie/Events/CookieDeleted/CookieDeletedEventHandler.php`
- `app/Domain/Cookie/Events/CookieRestored/CookieRestoredEvent.php`
- `app/Domain/Cookie/Events/CookieStockChanged/CookieStockChangedEvent.php`
- `app/Domain/Cookie/Events/CookieStockChanged/CookieStockChangedEventHandler.php`
- `app/Domain/Cookie/Projections/CookieReadModelProjection.php`
- `app/Infrastructure/Bus/EventDispatcher.php` (contract context)
- `app/Infrastructure/Projections/ProjectionInterface.php` (contract context)
- `app/Infrastructure/Projections/ProjectionRegistry.php` (contract context)
- `app/Domain/Cookie/CookieServiceProvider.php` (wiring context, lines 160-196)
- `app/Commands/RebuildProjections.php` (rebuild path context)

## Findings

### CRITICAL — Projection is never wired to the EventDispatcher in production
`app/Domain/Cookie/CookieServiceProvider.php:168-196` only subscribes the four event-handler classes; it never instantiates `CookieReadModelProjection` and never invokes `ProjectionRegistry::register()`. A repo-wide grep finds `ProjectionRegistry` referenced only in `tests/Integration/Projections/CookieReadModelProjectionTest.php:185` and the doc-block in `ProjectionInterface.php:17`. No bootstrap, no `Services::*`, no service provider registers projections. Net effect: live writes do not update `cookie_read_model`. The table only ever populates via `php spark projections:rebuild cookie`. The whole "D15 read model" is silently dead in production.

### CRITICAL — `CookieRestoredEvent` has no event-handler subscription at all
`app/Domain/Cookie/CookieServiceProvider.php:168-196` registers Created/Updated/Deleted/StockChanged but never `CookieRestoredEvent`. Combined with the projection-not-wired bug above, dispatching `CookieRestoredEvent` from `RestoreCookieHandler.php:60` produces a no-op in `EventDispatcher::dispatch()` (`EventDispatcher.php:86-88` short-circuits when no listeners). No audit log, no projection update, no side effects.

### HIGH — Created/Updated/Restored projection paths re-load from the write repository → stale-read & race window
`CookieReadModelProjection.php:113-128, 144-150` call `$this->repository->findById($event->cookieId)` to rebuild the row instead of using the event payload. Problems:
1. Stale read under high concurrency — if a second write commits between the dispatch and the projection's findById, the projection will store the *newer* state under the *older* event, silently re-ordering history relative to the event stream.
2. If the projection ever moves to an async consumer (queue, separate DB connection), `findById` may not see the just-committed write (replica lag / read-your-writes violation).
3. Defeats the point of putting `previousState`/`newState` in `CookieUpdatedEvent.php:30-31` and the snapshot in `CookieDeletedEvent.php:26` — those payloads are unused.
4. The handlers `return` silently on `findById === null` (`CookieReadModelProjection.php:116-118, 125-127, 147-149`) — a deleted-then-restored race or an out-of-order replay will simply drop the projection update with no log line and no error.

### HIGH — `onStockChanged` cannot create a row; depends on prior Created event having succeeded
`CookieReadModelProjection.php:159-167` runs a bare `UPDATE ... WHERE cookie_id = ?`. If a `CookieStockChangedEvent` is replayed before `CookieCreatedEvent` (out-of-order delivery, partial rebuild, late-arriving event), the update touches zero rows and is dropped silently. There is no idempotent upsert and no log. Combined with the no-op on missing entity in `onCreated`, the projection has no safety net.

### HIGH — `truncate()` + `rebuildFromSource()` is not safe to run concurrently with live writes
`CookieReadModelProjection.php:74-77, 79-111` truncates the entire `cookie_read_model` table then pages through the repository. During the rebuild window:
- Read traffic sees an empty table (200 OK with no cookies, not an error).
- Any event dispatched mid-rebuild that lands in `apply()` will collide: `onCreated/Updated/Restored` will `findById` and insert/update a row that the subsequent rebuild page may overwrite with potentially older data (because `findPaginated` snapshots rows page-by-page without a single transaction).
- `RebuildProjections.php:62-72` does not wrap the work in a transaction and does not pause the dispatcher.
- The class docblock at `CookieReadModelProjection.php:28-30` and `RebuildProjections.php:23-24` acknowledge "production-safe with caveats" / "schedule outside of read traffic peaks" — that is a workaround, not a fix. A blue/green rebuild (build into a shadow table, swap atomically) or `DELETE WHERE updated_at < $rebuildStart` would be safe; `TRUNCATE` is not.

### HIGH — `upsertFromEntity` race: `SELECT count` then `INSERT` is not atomic
`CookieReadModelProjection.php:179-191` does `countAllResults() > 0` → branch to UPDATE or INSERT. Under concurrent event delivery (two replays, or a live event arriving during a rebuild), two PHP processes can both observe `exists === false` and both INSERT — duplicate-key error or duplicated row depending on table constraints. Should use `INSERT ... ON DUPLICATE KEY UPDATE` (MySQL) keyed on `cookie_id`.

### MEDIUM — Projection `apply()` swallows unknown events
`CookieReadModelProjection.php:64-72` `match` `default => null`. Subscribing the projection to a new event class (or a rename) and forgetting to add a branch fails silently. Should throw or log a warning.

### MEDIUM — `onUpdated` ignores the payload and re-fetches → no diff-based denormalisation possible
`CookieUpdatedEvent.php:30-31` carries `previousState`/`newState` arrays specifically for "audit consumers" — but `CookieReadModelProjection.php:122-128` ignores them and re-fetches. If event ordering ever drifts or an out-of-order update lands, the projection cannot tell whether the event's `newState` matches the current row. The projection cannot detect lost-update conflicts.

### MEDIUM — `CookieCreatedEvent` payload lacks actor + timestamps; inconsistent with Updated/Deleted/Restored
- `CookieCreatedEvent.php:40-46`: no `createdBy`, no `createdAt`.
- `CookieUpdatedEvent.php:26-33`: has `updatedBy`.
- `CookieDeletedEvent.php:23-28`: has `deletedBy`.
- `CookieRestoredEvent.php:12-17`: has `restoredBy` + `restoredAt`.
- `CookieStockChangedEvent.php:18-23`: no actor, no timestamp, only `reason`.

The asymmetry breaks auditing — creation cannot be attributed, stock changes cannot be attributed. For consistency, every domain event should carry `actorId` + `occurredAt`.

### MEDIUM — `CookieStockChangedEvent::cookieId` is nullable
`CookieStockChangedEvent.php:19`: `public ?int $cookieId`. An event "stock changed" with no aggregate ID is meaningless; the nullable type allows constructing nonsense events and forces every consumer to add a null check (`CookieReadModelProjection.php:155-157` does this). The aggregate must have an ID by the time stock can change — type should be `int` and the entity should assert before recording.

### MEDIUM — `CookieRestoredEvent::restoredAt` is `string`, not `DateTimeImmutable`
`CookieRestoredEvent.php:15`. Mixing string timestamps with the rest of the system invites format drift. Should be `DateTimeImmutable` or at minimum carry ISO-8601 contractually documented. The other events do not carry timestamps at all (see above).

### MEDIUM — Event payloads carry potentially leakable data
`CookieUpdatedEvent.php:30-31` and `CookieDeletedEvent.php:26` accept arbitrary `array<string, scalar|null>` `previousState`/`newState`/`snapshot`. There is no schema, no allow-list, no redaction. If a future column carries PII (customer email on an order, supplier cost), it lands in logs verbatim via `CookieUpdatedEventHandler.php:47-53` / `CookieDeletedEventHandler.php:50-56`. The handler currently logs only the public fields (name + price) so it's fine *today*, but the event itself accepts anything — a footgun for cloning.

### MEDIUM — Event handlers depend on transient logger state; no idempotency safeguards
`CookieCreatedEventHandler.php:45-54`, `CookieUpdatedEventHandler.php:45-53`, `CookieDeletedEventHandler.php:48-56`, `CookieStockChangedEventHandler.php:20-30` all only log. They are idempotent by accident (a duplicated log line is harmless). But:
- None document idempotency requirements.
- The "future extensions" comments (e.g., `CookieCreatedEventHandler.php:56-60` "send email", "clear cache") would all break idempotency on replay. A cloner adding email/webhook here will produce duplicate sends on replay.
- No deduplication key (event id, message id) is on any event — replays cannot be filtered.

### LOW — Soft-delete handling on the projection is correct but minimal
`CookieReadModelProjection.php:131-142` flips `deleted_at` + `available = 0`. Idempotent: re-applying the same event yields the same row state. Acceptable. However:
- It uses `date('Y-m-d H:i:s')` (`CookieReadModelProjection.php:133`) — non-deterministic. On replay, the `deleted_at` differs from the original. Use `$event` payload timestamp if/when it exists.
- It does not update `version`. The read row's `version` will drift from the write row after every delete.

### LOW — Read model `projected_at` is `now()` on every write — not the event time
`CookieReadModelProjection.php:200, 218` `'projected_at' => $now`. On rebuild this is correct (when the row was projected), but a clone using this template for time-travel queries will be confused. Document it.

### LOW — `name_search` is `strtolower` only
`CookieReadModelProjection.php:205`: `strtolower($cookie->getName()->getValue())`. No accent stripping, no collation. Cookie has "Beijinho", "Brigadeiro" — a user typing "brigadeiro" without diacritics won't match "Brigadeiró". Fine if search uses a column collation that handles it, but the projection itself bakes in a transformation that the index can't undo.

### LOW — `tenant_id` hardcoded to `null`
`CookieReadModelProjection.php:203`. The migration has the column, but no multi-tenancy is plumbed through. Either remove the column from the migration or pass the tenant via the event payload.

### LOW — Events carry no event id / event version
None of the events have an `eventId` (UUID) or `schemaVersion`. Required for:
- Deduplication on replay
- Schema evolution
- Distributed tracing beyond correlation_id
Acceptable for an in-process synchronous bus today; a footgun the moment events go through a queue.

### LOW — `EventDispatcher::dispatch` does not guarantee listener order vs registration order is meaningful for projections
`EventDispatcher.php:90-104` calls listeners in registration order with no priority. If projections (read-model updates) are registered after audit-log handlers, an exception inside the audit log will be caught (`EventDispatcher.php:93-103`) and the projection will still run — good. But there's no documented contract that projections run before/after handlers, and a cloner may rely on order without realising.

## Template-cloning risks

1. **The projection wiring trap.** A team cloning this template will copy the projection class, the rebuild command, and the migration — and not realise the projection is never registered. They will write feature tests that pass (because `CookieReadModelProjectionTest.php:185` does the wiring locally), then deploy and discover the read model is stale. The fix is either (a) introduce a `registerProjections(ProjectionRegistry $r)` hook on `DomainServiceProviderInterface` analogous to `registerEvents`, or (b) wire it in `Services` and call `ProjectionRegistry::register()` for every projection at boot. Currently neither exists.

2. **The "re-fetch from the write repo" pattern is duplicated three times** (`onCreated`, `onUpdated`, `onRestored`). A cloner will copy it for every new event, propagating the stale-read race indefinitely. The right primitive is `upsertFromEvent(CookieCreatedEvent|CookieUpdatedEvent ...)` working off the payload, with `findById` as a fallback only.

3. **Asymmetric event payloads.** Created has no actor; Stock has no actor; Updated/Deleted/Restored do. A cloner adding a new event will pick whichever neighbouring event they look at first and inherit its inconsistency.

4. **No event id / no idempotency key.** The moment a clone hooks up an async queue or webhook, they will produce duplicates on retry. There is no template enforcement.

5. **Truncate-then-rebuild as the only rebuild strategy.** A cloner with a larger production dataset will discover the read model is unavailable for minutes during a rebuild. No shadow-table swap is offered; the pattern propagates.

6. **`scalar|null` snapshot arrays** in `CookieUpdatedEvent`/`CookieDeletedEvent` invite cloners to dump arbitrary entity state into events, eventually including PII.

7. **`onStockChanged` UPDATE-only.** Cloning this for any partial-update event will silently drop events when the row doesn't exist yet (out-of-order delivery, mid-rebuild).

8. **`SELECT count → INSERT/UPDATE`** in `upsertFromEntity` is a textbook race; cloning it across other domains multiplies the bug.

## Verdict

**FAIL — must fix before clone.**

Two CRITICALs:
- The projection is never subscribed in production (D15 is dead code outside the rebuild command and the integration test).
- `CookieRestoredEvent` has no handler subscription.

Five HIGHs that turn the projection from "denormalised read model" into "best-effort approximation":
- Re-fetching from the write repo introduces stale reads.
- `onStockChanged` cannot create rows.
- `truncate()` rebuild collides with live writes.
- `upsertFromEntity` SELECT-then-INSERT race.

Minimum bar to ship the template:
1. Add `DomainServiceProviderInterface::registerProjections(ProjectionRegistry)` and call it from boot; wire `CookieReadModelProjection` there.
2. Subscribe a `CookieRestoredEventHandler` (even if it only logs).
3. Drive projection writes from event payloads, not from `repository->findById()`; the `findById` fallback should only run on hydration gaps and emit a warning log.
4. Replace `SELECT count → INSERT/UPDATE` with `INSERT ... ON DUPLICATE KEY UPDATE`.
5. Replace `truncate()` rebuild with shadow-table-and-swap, or document loudly that the read model is unavailable during rebuild and gate the command behind a maintenance flag.
6. Normalise event payloads: every event gets `eventId` (UUID), `occurredAt` (DateTimeImmutable), `actorId` (int, nullable only for system). Make this the template contract.
7. Make `apply()` throw on unknown event classes; add `$this->logger->warning` on `findById === null` rather than silent return.

Until those are addressed, anyone cloning Cookie as the "reference domain" will inherit a broken read-model story.
