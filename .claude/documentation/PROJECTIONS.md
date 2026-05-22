# Projections — Read-Model Lifecycle and Reuse

This document explains when to add a denormalised read model, how the
Cookie reference projection (`CookieReadModelProjection.php.example`)
is structured, and how the outbox + projection + dispatcher fit together.

> **Snapshot:** Reflects the architecture **after PRs #29-#39**. Pairing PR
> for the outbox-hardening tightening (UNIQUE on `event_uuid`, idempotent
> relay claim) is the **E12** epic — track via issue
> [ci4me/CQRSTemplate#22](https://github.com/ci4me/CQRSTemplate/issues/22).
> A dedicated `registerProjections()` hook on
> `DomainServiceProviderInterface` lands with **E13**; until then,
> projections are registered through
> `App\Infrastructure\Projections\ProjectionRegistry::register()`.

---

## When (not) to add a projection

A projection is a **second physical table** that the write side updates
in response to events. Use one when:

- Reads need a shape that joins are too expensive to produce on demand
  (cross-aggregate dashboards, search-friendly denormalisation).
- Reads need indexes the write table cannot afford (large analytical sorts).
- Reads must survive write-DB partition / outage (separate read DB).

**Do not** use a projection when:

- The read can be answered by querying the same table with a different
  index. The current Cookie domain ships with the projection example
  *disabled* on purpose because a single-aggregate template doesn't need
  the duplication overhead.
- The shape difference is just "fewer columns" — a DTO returned by the
  query repository handles that without a separate table.

---

## The Cookie reference projection

`app/Domain/Cookie/Projections/CookieReadModelProjection.php.example` is
the canonical reference. The `.example` suffix means PHP will not load it;
to enable, rename to `.php`. The header of the file walks through the
re-enable steps in detail; the summary:

1. Rename:
   `CookieReadModelProjection.php.example` → `CookieReadModelProjection.php`.
2. Reverse / replace the table-drop migration:
   `2026-05-21-120000_DropCookieReadModelTable.php` was rolled forward
   in Phase 2 of the stabilization refactor. Roll it back or write a
   fresh `CreateCookieReadModelTable` migration.
3. Wire the projection in `CookieServiceProvider::registerEvents()`
   through `ProjectionRegistry::register($projection)` (or, post-E13,
   inside `CookieServiceProvider::registerProjections($registry)`).
4. Repoint `CookieQueryRepository` at the projection table (or restore a
   dedicated `CookieReadModelRepository`).
5. Backfill with `php spark projections:rebuild cookie`.

---

## The full lifecycle: commit → outbox → relay → projection

```
┌────────────────────────────────────────────────────────────────────────┐
│ 1. Handler                                                            │
│    BEGIN TXN                                                          │
│    repo->save($entity)                # row INSERT/UPDATE             │
│    outboxWriter->record($events)      # event_outbox INSERT (same TXN)│
│    COMMIT                                                             │
│                                                                       │
│    dispatcher->dispatch($events)      # in-process subscribers,       │
│                                       # post-commit so they never     │
│                                       # see uncommitted state         │
└────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌────────────────────────────────────────────────────────────────────────┐
│ 2. Relay (background worker via `php spark events:relay`)             │
│    SELECT * FROM event_outbox WHERE relayed_at IS NULL LIMIT N        │
│    foreach event:                                                     │
│        dispatcher->dispatch($event)                                   │
│        UPDATE event_outbox SET relayed_at = NOW() WHERE id = ?        │
│                                                                       │
│    The relay dedups on event_uuid (E12 will add a DB UNIQUE here).    │
└────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌────────────────────────────────────────────────────────────────────────┐
│ 3. Projection subscriber                                              │
│    public function apply(DomainEventInterface $event): void           │
│        $row = $this->rowFor($event)                                   │
│        $this->db->upsert($this->table(), $row)                        │
│                                                                       │
│    Projection apply() is **idempotent**: re-applying the same         │
│    event_uuid does not change the row state.                          │
└────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌────────────────────────────────────────────────────────────────────────┐
│ 4. Side-effect handler (if registered)                                │
│    if ($processedEventStore->hasProcessed($event->getEventId(),       │
│                                            self::class)) { return; }  │
│    $emailer->send(...)                                                │
│    $processedEventStore->markProcessed($event->getEventId(),          │
│                                         self::class)                  │
│                                                                       │
│    Handler-side at-most-once guard (E12.5). The outbox dedup catches  │
│    relay-side replays; ProcessedEventStore catches the residual case  │
│    where the relay succeeded once and the worker died before ACK.     │
└────────────────────────────────────────────────────────────────────────┘
```

---

## What to copy when scaffolding a new domain's projection

If your new domain truly needs a projection:

1. Copy the example file into the new domain's `Projections/` directory
   (keep it `.example` until you're ready to enable).
2. Update the class name, namespace, and the events it subscribes to in
   `subscribesTo()`.
3. Define `rowFor(DomainEventInterface $event): array` — the row shape
   you'll upsert into the read table. Cookie's example produces:
   `id, name, price, stock, is_active, created_at, updated_at`.
4. Implement `truncate()` and `rebuildFromSource(callable $source)` so
   `php spark projections:rebuild {name}` can do a live-safe rebuild.
5. Add a migration to create the read table.
6. Add a unit test that asserts `apply()` is idempotent (replay an event
   twice; row state matches).
7. Register through `ProjectionRegistry::register()` (or the future
   `registerProjections()` hook).

---

## ProjectionInterface — the contract

```php
interface ProjectionInterface
{
    public function name(): string;                       // 'cookie'
    public function subscribesTo(): array;                // [CookieCreatedEvent::class, ...]
    public function apply(DomainEventInterface $event): void;
    public function truncate(): void;                     // for rebuild
    public function rebuildFromSource(callable $source): void;
}
```

`ProjectionRegistry::register($projection)` does two things:

1. Stores the projection by `name()` so the rebuild command can find it.
2. For every event class in `subscribesTo()`, attaches `apply()` to the
   `EventDispatcher`. After registration the normal command flow keeps
   the read model current — you do not poll, do not run a separate
   worker for the projection alone (the outbox relay is enough).

---

## Idempotency — the non-negotiable invariant

Projections **MUST** be idempotent. The same event may be applied more
than once because:

- The relay retries on transient failures.
- A worker may crash after writing to the projection but before ACKing
  the outbox row.
- A rebuild replays every event from the start.

Apply pattern that works:

- `INSERT … ON DUPLICATE KEY UPDATE` (MySQL upsert) keyed on the
  projection's natural key (`{entity}_id`).
- For state-machine fields, compute the target state from the event +
  current row (don't blindly overwrite — last-write-wins can break
  ordering invariants if events arrive out of order).
- Carry the source event's `eventId` on the row if cross-event ordering
  matters; compare on apply.

---

## Rebuild flow

```bash
php spark projections:rebuild cookie
```

The command:

1. Looks up the projection by `name()` via `ProjectionRegistry`.
2. Calls `truncate()` (or `rebuildFromSource()` for a shadow-table
   live-safe rebuild).
3. Streams every event from the source-of-truth (the write table or the
   event store, depending on the projection's `rebuildFromSource`
   implementation).
4. Calls `apply()` for each event in order.

For Cookie, the example demonstrates both forms:

- **In-place rebuild** — fast but takes the read table offline.
- **Shadow-table rebuild** — creates `cookie_read_model_shadow`,
  rebuilds into it, then atomic-renames. The read side never blocks.

---

## Cross-references

- `app/Infrastructure/Projections/ProjectionInterface.php` — contract.
- `app/Infrastructure/Projections/ProjectionRegistry.php` — wiring.
- `app/Infrastructure/Outbox/EventOutboxWriter.php` — transactional outbox writer.
- `app/Infrastructure/Outbox/EventOutboxRelay.php` — background relay.
- `app/Commands/RelayOutboxEvents.php` / `app/Commands/RebuildProjections.php`
  — spark commands.
- `app/Domain/Cookie/Projections/CookieReadModelProjection.php.example` —
  reference implementation.
- `.claude/skills/cqrs-architecture/SKILL.md` — broader architecture notes.
- `.claude/skills/domain-scaffolding/SKILL.md` — when to copy this
  scaffold into a new domain.
