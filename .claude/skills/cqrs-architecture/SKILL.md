---
name: cqrs-architecture
description: Architectural reference for this CodeIgniter 4 / CQRS / DDD template — directory layout, command/query/event patterns, ubiquitous-language naming, auto-discovery, design decisions. Load when scaffolding a new domain or onboarding to the architecture.
allowed-tools: [Read, Glob, Grep]
---

# CQRS + DDD Architecture Reference

This skill captures the architecture rules that USED to live in `.claude/CLAUDE.md`
before it was trimmed for fast boot-time loading. Use it when you need the deep
reference: scaffolding a new domain, onboarding, or answering a "where does X
live?" question.

> **Snapshot:** This document reflects the architecture **after PRs #29-#39**
> (Phase 0 + Phase 1 + partial Phase 2: E07, E08, E05.5, E12.5, E17). It does
> **not** yet reflect E09/E10/E11/E12/E13/E14 — those land follow-up updates.

---

## CQRS pattern

### Commands (write operations)

- Represent intent to change system state.
- Named in imperative: `CreateCookieCommand`, `UpdateCookieCommand`,
  `DeleteCookieCommand`, `RestoreCookieCommand`.
- Handled by **exactly one** handler.
- Handlers may either:
  - implement `CommandHandlerInterface<TCommand, TResult>` directly (Cookie's
    current shape), **or**
  - extend `AbstractCommandHandler` to inherit the start/success/failure
    log template (E05 — bus typehint enforces the interface either way).
- Handlers persist via the write-side repository, then drain
  `$entity->pullEvents()` and forward to the `EventDispatcher`.
- Located in `app/Domain/{Domain}/Commands/{CommandName}/` — each command
  has its own folder containing the command DTO and its handler.

### Queries (read operations)

- Represent requests for data; never mutate state.
- Named as questions: `GetCookieByIdQuery`, `GetAllCookiesQuery`,
  `GetCookiesPaginatedQuery`.
- Handled by **exactly one** handler.
- Handlers implement `QueryHandlerInterface<TQuery, TResult>` (or extend
  `AbstractQueryHandler`).
- Use the **separate** read-side repository (`{Entity}QueryRepository`) so
  query handlers never reach into the write-side aggregate.
- Located in `app/Domain/{Domain}/Queries/{QueryName}/`.

### Events (domain events)

- Represent things that have happened. Named in past tense:
  `CookieCreatedEvent`, `CookieDeletedEvent`, `CookieRestoredEvent`,
  `CookieActivatedEvent`, `CookieDeactivatedEvent`, `CookieStockChangedEvent`.
- **MUST extend `\App\Domain\Shared\Events\AbstractDomainEvent`** (E04). The
  base carries the 5-field envelope:
  `eventId` (UUIDv7), `occurredAt` (UTC), `actorId`, `aggregateType`,
  `aggregateId`. This envelope is what the outbox writer + relay key
  dedupes on.
- Can have **multiple** subscribers.
- Immutable (`final readonly class`).
- Raised by the **aggregate**, not the handler — the handler drains the
  buffer post-persist via `pullEvents()`.
- Located in `app/Domain/{Domain}/Events/{EventName}/` — each event has its
  own folder with the event DTO and its event handler.

### Aggregate roots

- Implement `\App\Domain\Shared\Aggregate\AggregateRootInterface` (E06).
- Use the `\App\Domain\Shared\AggregateRoot` trait for event-bag plumbing
  (`pullEvents()`, `peekEvents()`, `hasPendingEvents()`, `recordEvent()`).
- Trusted mutators (`bumpVersion()`, `assignId()`) require an
  `\App\Domain\Shared\Aggregate\AggregateHydrator` key — the only way to
  obtain one is `AggregateHydrator::key()`, callable only from
  whitelisted namespaces (E06).
- Lifecycle methods raise events on the entity itself — handlers stay thin.

### Outbox + ProcessedEventStore double-guard (E12 / E12.5)

The template ships a transactional outbox (`app/Infrastructure/Outbox/`) so
events committed inside a domain transaction don't escape on rollback:

1. Handler persists the aggregate in a DB transaction.
2. `EventOutboxWriter` writes the event row in the **same** transaction.
3. After commit, `EventOutboxRelay` (background worker) reads pending rows
   and re-dispatches them.

The relay has its own dedup (outbox-side, keyed on `event_uuid`), but a
**handler-side `ProcessedEventStore`** (E12.5) catches the residual case
where the relay succeeded once, the worker died before ACK, and the same
event is re-dispatched on restart. Side-effect handlers (email, webhook,
search-index update) MUST consult that store keyed on `$event->getEventId()`
so the side effect is at-most-once across replays. The relay-side guard +
handler-side guard together form the "double guard".

---

## Directory layout

```
app/Domain/{Domain}/
├── {Domain}ServiceProvider.php              # auto-discovered #[DomainServiceProvider]
├── ErrorCodes.php                           # domain-scoped error code constants (E08)
├── Commands/
│   ├── Create{Entity}/                      #   { Command + Handler }
│   ├── Update{Entity}/
│   ├── Delete{Entity}/
│   └── Restore{Entity}/                     # NEW (E07) — paired with soft-delete
├── Queries/
│   ├── Get{Entity}ById/
│   ├── GetAll{Entities}/
│   └── Get{Entities}Paginated/
├── Events/
│   ├── {Entity}Created/                     # all events extend AbstractDomainEvent
│   ├── {Entity}Updated/
│   ├── {Entity}Deleted/
│   ├── {Entity}Restored/                    # NEW (E07)
│   ├── {Entity}Activated/                   # NEW (E07)
│   ├── {Entity}Deactivated/                 # NEW (E07)
│   └── {Entity}StockChanged/                # NEW (E07/E08, optional)
├── Entities/
│   ├── {Entity}.php                         # implements AggregateRootInterface (E06)
│   └── {Entity}StateAssertions.php          # invariant guards (E07)
├── ValueObjects/
│   ├── {Entity}Name.php
│   ├── {Entity}Price.php
│   ├── {Entity}Stock.php                    # optional
│   ├── {Entity}Snapshot.php                 # before/after diffs for events (E08)
│   └── StockChangeReason.php                # enum (E08, optional)
├── DTOs/
│   └── {Entity}DTO.php
├── ReadModels/
│   └── {Entity}View.php                     # legacy — collapsed in E10
├── Ports/
│   ├── {Entity}RepositoryInterface.php
│   └── {Entity}QueryRepositoryInterface.php
├── Repositories/
│   ├── {Entity}Repository.php               # #[AutoBind]
│   └── {Entity}QueryRepository.php
├── Services/                                # OPTIONAL domain-private utilities
└── Projections/
    └── {Entity}ReadModelProjection.php.example  # reference only — see PROJECTIONS.md
```

A domain has **60+ files / touchpoints** in total once controllers, models,
views, migrations, tests, and the Shared bases it depends on are counted.
The exhaustive list lives in
`.claude/documentation/COMPLETE_FILE_INVENTORY.md`.

### Shared bases (`app/Domain/Shared/`)

The cross-cutting building blocks that the Cookie domain (and every clone)
depends on:

```
app/Domain/Shared/
├── Aggregate/
│   ├── AggregateRootInterface.php           # E06 — contract every entity satisfies
│   └── AggregateHydrator.php                # E06 — trusted-mutator key
├── AggregateRoot.php                        # trait implementing the interface
├── Bus/
│   ├── CommandHandlerInterface.php          # E05 — generic <TCommand, TResult>
│   ├── QueryHandlerInterface.php            # E05 — generic <TQuery, TResult>
│   ├── AbstractCommandHandler.php           # E05 — template-method base
│   ├── AbstractQueryHandler.php             # E05 — template-method base
│   ├── ClockInterface.php                   # E05 — single timing source
│   ├── SystemClock.php                      # E05 — default ClockInterface impl
│   └── LogSampler.php                       # E05 — per-channel sampling decision
├── Events/
│   ├── DomainEventInterface.php             # E04 — minimal contract
│   ├── AbstractDomainEvent.php              # E04 — 5-field envelope base
│   ├── CookieChangeSet.php                  # E04 — change-set VO used by *Updated
│   ├── EventDispatcherInterface.php
│   └── ProcessedEventStoreInterface.php     # E12.5 (pending) — at-most-once guard
├── Exceptions/
│   ├── DomainException.php
│   └── ValidationException.php
├── Ports/
│   └── LogConfigPort.php
└── ValueObjects/                            # Money, Currency, Email, etc.
```

---

## Auto-discovery (zero-config domain registration)

Domains are registered automatically via a native PHP attribute:

```php
use App\Infrastructure\Attributes\DomainServiceProvider;

#[DomainServiceProvider]
final class OrderServiceProvider implements DomainServiceProviderInterface
{
    public function registerCommands(CommandBus $commandBus): void { … }
    public function registerQueries(QueryBus $queryBus): void { … }
    public function registerEvents(EventDispatcher $dispatcher): void { … }
    public function registerRoutes(RouteCollection $routes): void { … }
    // public function registerProjections(ProjectionRegistry $r): void { … }  // E13 pending
}
```

Drop the file into `app/Domain/{Order}/`, mark it with the attribute, and the
`ServiceProviderRegistry` will discover and wire it on boot. **You never edit
`app/Config/Services.php`** to register a domain.

Repositories are bound the same way: tag the concrete class with
`#[AutoBind]` and the registry resolves the interface→class mapping.

---

## Ubiquitous-language naming

| Concept | Pattern |
|---|---|
| Command | `Create{Entity}Command`, `Update{Entity}Command`, `Delete{Entity}Command`, `Restore{Entity}Command` |
| Query | `Get{Entity}ByIdQuery`, `GetAll{Entities}Query`, `Get{Entities}PaginatedQuery` |
| Event | `{Entity}CreatedEvent`, `{Entity}UpdatedEvent`, `{Entity}DeletedEvent`, `{Entity}RestoredEvent`, `{Entity}ActivatedEvent`, `{Entity}DeactivatedEvent` |
| Handler | `{Command/Query/Event}Handler` |
| Value Object | `{Entity}{Property}` (e.g., `CookieName`, `CookiePrice`, `CookieSnapshot`) |
| Entity | `{Entity}` (e.g., `Cookie`, `Order`) |
| State assertions | `{Entity}StateAssertions` |
| Repository (port) | `{Entity}RepositoryInterface`, `{Entity}QueryRepositoryInterface` in `app/Domain/{Domain}/Ports/` |
| Repository (adapter) | `{Entity}Repository`, `{Entity}QueryRepository` in `app/Domain/{Domain}/Repositories/` |
| Model | `{Entity}Model` in `app/Models/{Domain}/` |

For the singular/plural mapping between class names and URL/view/table forms,
see the `domain-scaffolding` skill's "Singular/plural convention" table.

---

## Code-quality rules enforced by the gate

**Method-level:**
- Max 20 lines per method (including braces).
- Max 3 parameters (use DTOs / commands for more).
- Early returns; no `else` after `return`.
- Guard clauses for validation.
- Descriptive names (verb + noun).

**Class-level:**
- Max 200 lines per class.
- Single Responsibility Principle.
- `final` by default unless designed for extension.
- `private` by default.

**Type safety:**
- `declare(strict_types=1)` in every file.
- All parameters and returns typed.
- No `mixed` unless genuinely necessary.
- `===` for all comparisons.
- `readonly` value objects and DTOs.

**CQRS / DDD:**
- Commands, queries, and events are `final readonly` DTOs.
- Every event extends `AbstractDomainEvent`.
- Value objects are immutable and self-validating.
- Entities use factory methods (`create()` / `reconstitute()`) and
  implement `AggregateRootInterface`.
- No business logic in controllers.
- Repository pattern for data access; write-side repositories return
  entities, read-side repositories return DTOs.

---

## Design decisions

1. **No business logic in controllers.** Controllers only orchestrate
   commands / queries via buses.
2. **Repository split (read vs write).** Write-side returns entities; read-side
   returns DTOs. Distinct ports keep the CQRS code-level separation visible
   even when both sides query the same physical table.
3. **Value objects for validation.** Any property with a validation rule
   (e.g., `CookieName`, `CookiePrice`) is a value object.
4. **Aggregate-owned lifecycle.** Soft-delete / restore / activate / deactivate
   raise events on the entity; handlers drain via `pullEvents()`.
5. **Trusted-mutator gating.** Persistence-only mutators take an
   `AggregateHydrator` key so they can't be called from a controller.
6. **Strict type safety.** PHPStan level 8 across the board.
7. **Soft deletes.** All entities support `deleted_at` and a paired
   `Restore{Entity}` command.
8. **Immutable commands / queries / events.** All DTOs are `final readonly`.
9. **5-field event envelope.** Every event carries `eventId`, `occurredAt`,
   `actorId`, `aggregateType`, `aggregateId` via `AbstractDomainEvent`.
10. **Transactional outbox + handler-side `ProcessedEventStore`.** Two
    independent dedup layers protect at-most-once side effects.

---

## Quick Q&A

**Q: Where is business logic?** In aggregate methods (lifecycle invariants)
+ command handlers under `app/Domain/{Domain}/Commands/`. Query handlers
under `app/Domain/{Domain}/Queries/` are projection-only.

**Q: Where is database access?** In repository adapters under
`app/Domain/{Domain}/Repositories/`, backed by CodeIgniter models in
`app/Models/{Domain}/`.

**Q: How do I add a new field to an entity?**
Use the `/add-property` command (or the `property-addition` skill).

**Q: How do I create a new domain?**
Use the `/add-domain` command (or the `domain-scaffolding` skill).

**Q: Where are routes defined?** In each domain's
`{Domain}ServiceProvider::registerRoutes()`. `app/Config/Routes.php` only
hosts global routes (auth, health).

**Q: How do I add validation?**
Create a value object in `app/Domain/{Domain}/ValueObjects/` with validation
in the constructor.

**Q: How do I add a denormalised read model / projection?**
See `.claude/documentation/PROJECTIONS.md`. Cookie ships a
`CookieReadModelProjection.php.example` reference; copy it, drop the
`.example` suffix, and register through `ProjectionRegistry` (until E13
introduces the per-provider `registerProjections()` hook).

---

## Reference domain

Use the **Cookie domain** (`app/Domain/Cookie/`) as the canonical template.
It demonstrates the complete CQRS implementation, DDD patterns, the abstract
handler bases, the `AggregateRoot` pattern, the `AbstractDomainEvent`
envelope, the outbox flow, the projection example, PHPStan Level 8
compliance, Slevomat compliance, and full logging integration.

The `bin/docs-cookie-sync` CI guard verifies that the file inventory in
this skill (and in `.claude/documentation/COMPLETE_FILE_INVENTORY.md`) stays
in lock-step with what `app/Domain/Cookie/` actually contains — drift fails
the build.

---

## Round-3 round-trip — which PRs reshaped Cookie

| Epic | What changed | Architectural impact |
|---|---|---|
| **E04** | `AbstractDomainEvent` + `CookieChangeSet` | Every event has the 5-field envelope; outbox can dedup on `eventId`. |
| **E05** | Bus interfaces + abstract handler bases | Handler boilerplate centralised; bus typehints prevent register-time mistakes. |
| **E06** | `AggregateRootInterface` + `AggregateHydrator` | Aggregates have a typed contract; persistence-only mutators are gated by a key. |
| **E07** | Cookie entity owns lifecycle | `softDelete/restore/activate/deactivate/changeStock` raise events; handlers stay thin; `CookieAccessors` trait deleted. |
| **E08** | `StockChangeReason` enum, `CookieSnapshot` VO, `ErrorCodes` | Reasoned stock movements + before/after diffs in events. |
| **E12.5** | `ProcessedEventStoreInterface` (pending) | At-most-once for side-effect handlers. |
| **E17** | PHP 8.3 idiom polish | `#[\Override]`, `Stringable`, `hrtime` timing. |

Pending PRs that will trigger the next architecture-docs refresh:
**E09** (multi-currency / `Money`), **E10** (`CookieView`→`CookieDTO`),
**E11** (repo hygiene), **E12** (outbox UNIQUE + relay hardening),
**E13** (`registerProjections()` hook), **E14** (view collapse).

---

## Related documentation

- `.claude/documentation/COMPLETE_FILE_INVENTORY.md` — exhaustive file list
- `.claude/documentation/PROJECTIONS.md` — projection lifecycle and reuse
- `.claude/documentation/DOMAIN_CREATION_PROTOCOL.md` — manual domain creation steps
- `.claude/documentation/PROPERTY_ADDITION_PROTOCOL.md` — manual property addition
- `.claude/documentation/BUSINESS_RULE_PROTOCOL.md` — rule-placement decision tree
- `.claude/documentation/ARCHITECTURE_DECISIONS.md` — full ADR set
- `.claude/documentation/LOGGING_BEST_PRACTICES.md` — PSR-3 patterns in handlers
