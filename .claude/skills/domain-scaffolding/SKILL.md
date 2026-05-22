---
name: domain-scaffolding
description: Scaffolds a complete new domain from scratch with full CQRS structure (60+ files/touchpoints: entities, value objects, DTOs, ports, commands, queries, events, handlers, repository, controller, views, tests). Use when user requests to create a new domain/module/bounded context. References Cookie domain as template.
allowed-tools: [Read, Write, Edit, Glob, Grep, Bash, Task]
---

# Domain Scaffolding Skill

Automates creation of a complete domain with all standard files and touchpoints
based on the **Cookie reference domain**.

> **Snapshot:** This doc reflects Cookie's shape **after PRs #29-#39** land
> (Phase 0 + Phase 1 + the partial Phase 2 epics E07, E08, E05.5, E12.5, E17).
> It does **NOT** yet reflect E09 (multi-currency / `Money` migration),
> E10 (read-DTO consolidation), E11 (repo hygiene), E12 (outbox hardening),
> E13 (provider DI / `registerProjections()` hook), E14 (views). Those land
> a follow-up E15 sweep when those PRs merge.

---

## Canonical Cookie shape (template to clone)

When you run `/add-domain Foo`, the result must mirror the file tree under
`app/Domain/Cookie/` listed below. The `bin/docs-cookie-sync` CI guard
enforces that this list stays in lock-step with the real Cookie domain — if
Cookie grows a file, this list must grow too, otherwise CI fails.

```
app/Domain/{Domain}/
├── {Domain}ServiceProvider.php                # auto-discovered via #[DomainServiceProvider]
├── ErrorCodes.php                             # domain-scoped error codes (E08)
├── Commands/
│   ├── Create{Entity}/
│   │   ├── Create{Entity}Command.php          # final readonly DTO
│   │   └── Create{Entity}Handler.php          # implements CommandHandlerInterface
│   ├── Update{Entity}/
│   │   ├── Update{Entity}Command.php
│   │   └── Update{Entity}Handler.php
│   ├── Delete{Entity}/
│   │   ├── Delete{Entity}Command.php
│   │   └── Delete{Entity}Handler.php
│   └── Restore{Entity}/                       # NEW (E07): paired with soft-delete
│       ├── Restore{Entity}Command.php
│       └── Restore{Entity}Handler.php
├── Queries/
│   ├── Get{Entity}ById/
│   │   ├── Get{Entity}ByIdQuery.php
│   │   └── Get{Entity}ByIdHandler.php         # implements QueryHandlerInterface
│   ├── GetAll{Entities}/
│   │   ├── GetAll{Entities}Query.php
│   │   └── GetAll{Entities}Handler.php
│   └── Get{Entities}Paginated/
│       ├── Get{Entities}PaginatedQuery.php
│       └── Get{Entities}PaginatedHandler.php
├── Events/
│   ├── {Entity}Created/
│   │   ├── {Entity}CreatedEvent.php           # extends AbstractDomainEvent (E04)
│   │   └── {Entity}CreatedEventHandler.php    # PSR-3 logger only by default
│   ├── {Entity}Updated/
│   │   ├── {Entity}UpdatedEvent.php
│   │   └── {Entity}UpdatedEventHandler.php
│   ├── {Entity}Deleted/
│   │   ├── {Entity}DeletedEvent.php
│   │   └── {Entity}DeletedEventHandler.php
│   ├── {Entity}Restored/                      # NEW (E07)
│   │   ├── {Entity}RestoredEvent.php
│   │   └── {Entity}RestoredEventHandler.php
│   ├── {Entity}Activated/                     # NEW (E07)
│   │   ├── {Entity}ActivatedEvent.php
│   │   └── {Entity}ActivatedEventHandler.php
│   ├── {Entity}Deactivated/                   # NEW (E07)
│   │   ├── {Entity}DeactivatedEvent.php
│   │   └── {Entity}DeactivatedEventHandler.php
│   └── {Entity}StockChanged/                  # NEW (E07/E08): stock-aware domains only
│       ├── {Entity}StockChangedEvent.php
│       └── {Entity}StockChangedEventHandler.php
├── Entities/
│   ├── {Entity}.php                           # implements AggregateRootInterface (E06)
│   └── {Entity}StateAssertions.php            # extracted invariant guards (E07)
├── ValueObjects/
│   ├── {Entity}Name.php
│   ├── {Entity}Price.php
│   ├── {Entity}Stock.php                      # optional: stock-aware domains
│   ├── {Entity}Snapshot.php                   # NEW (E08): before/after diff for change events
│   └── StockChangeReason.php                  # NEW (E08): enum for reasoned stock movements
├── DTOs/
│   └── {Entity}DTO.php                        # read DTO; pre-E10 still co-exists with CookieView
├── ReadModels/
│   └── {Entity}View.php                       # to be collapsed into DTO by E10 — kept for now
├── Ports/
│   ├── {Entity}RepositoryInterface.php
│   └── {Entity}QueryRepositoryInterface.php
├── Repositories/
│   ├── {Entity}Repository.php                 # write side; AutoBind to interface (E13 still pending)
│   └── {Entity}QueryRepository.php            # read side; returns DTOs
├── Services/
│   └── PriceFormatter.php                     # OPTIONAL: domain-private utilities
└── Projections/
    └── {Entity}ReadModelProjection.php.example  # template only — see PROJECTIONS.md
```

### Application + presentation + persistence (outside `app/Domain/`)

```
app/Controllers/Domain/{Domain}/{Entity}Controller.php
app/Models/{Domain}/{Entity}Model.php           # thin CI4 Model
app/Views/{entities}/
├── index.php
├── show.php
├── create.php
└── edit.php

app/Database/Migrations/
└── YYYY-MM-DD-HHMMSS_Create{Entities}Table.php  # ISO date prefix; tested format
```

### Test layer

```
tests/Unit/Domain/{Domain}/
├── ValueObjects/{Entity}NameTest.php
├── ValueObjects/{Entity}PriceTest.php
├── ValueObjects/{Entity}SnapshotTest.php
├── ValueObjects/StockChangeReasonTest.php     # if applicable
├── Entities/{Entity}Test.php
├── DTOs/{Entity}DTOTest.php
├── ReadModels/{Entity}ViewTest.php
├── {Domain}ServiceProviderTest.php
├── Commands/Create{Entity}HandlerTest.php
├── Commands/Update{Entity}HandlerTest.php
├── Commands/Delete{Entity}HandlerTest.php
├── Commands/Restore{Entity}HandlerTest.php    # NEW (E07)
├── Queries/Get{Entity}ByIdHandlerTest.php
├── Queries/GetAll{Entities}HandlerTest.php
├── Queries/Get{Entities}PaginatedHandlerTest.php
├── Events/{Entity}EventsTest.php
├── Events/{Entity}EventHandlersTest.php
├── Events/{Entity}Activated/{Entity}ActivatedEventTest.php
└── Events/{Entity}Deactivated/{Entity}DeactivatedEventTest.php

tests/Integration/Repositories/
├── {Entity}RepositoryTest.php
├── {Entity}QueryRepositoryTest.php
└── {Entity}OptimisticLockingTest.php           # required: entity uses version field

tests/Feature/{Domain}/
├── {Entity}CrudTest.php
└── {Entity}QueryE2ETest.php

tests/Support/Factories/{Entity}Factory.php
```

---

## Prerequisites

Before starting, confirm with user:

1. **Domain name** (PascalCase, singular): e.g., `Order`, `Product`.
2. **Plural form** for URL/view/migration: e.g., `orders`, `products`.
   See the [Singular/plural convention](#singularplural-convention) table.
3. **Primary value objects** needed (e.g., `OrderNumber`, `ProductSKU`).
4. **Additional properties** beyond `name + price` (description, stock, etc.).
5. **Side-effect handlers** planned? If yes, plan now for an opt-in
   `ProcessedEventStore` consumer (idempotency requires the at-most-once
   guard documented in `PROJECTIONS.md` and `LOGGING_BEST_PRACTICES.md`).
   *Note:* `ProcessedEventStore` lands in E12.5 — the interface is reserved
   in this skill so handlers can be written ready for the merge.

---

## Singular/plural convention

Picking one convention up front avoids the slice 15/F7 + 15/F12 friction
where Cookie ships `Domain/Cookie/` (singular) but views as `Views/cookies/`
(plural) and tests as `Feature/Cookie/` (singular).

| Surface | Form | Example |
|---|---|---|
| Namespace under `app/Domain/` | Singular | `app/Domain/Cookie/` |
| Entity class | Singular | `Cookie` |
| Value-object prefix | Singular | `CookieName`, `CookiePrice` |
| Repository / model class | Singular | `CookieRepository`, `CookieModel` |
| Controller class | Singular | `CookieController` |
| Controller namespace | Singular | `app/Controllers/Domain/Cookie/` |
| Model namespace | Singular | `app/Models/Cookie/` |
| Test namespace | Singular | `tests/Unit/Domain/Cookie/` |
| Route prefix / URL path | **Plural** | `/cookies`, `/cookies/(:num)` |
| Views directory | **Plural** | `app/Views/cookies/` |
| Migration table name | **Plural** | `cookies`, `orders`, `products` |
| Migration class name | **Plural** | `CreateCookiesTable` |

---

## Step 1: Create directory structure

```bash
mkdir -p app/Domain/{Domain}/{Commands,Queries,Events,Entities,ValueObjects,DTOs,Ports,Repositories,Services,ReadModels,Projections}
mkdir -p app/Models/{Domain}
mkdir -p app/Controllers/Domain/{Domain}
mkdir -p app/Views/{entities}
mkdir -p tests/Unit/Domain/{Domain}/{ValueObjects,Entities,Commands,Queries,Events,DTOs,ReadModels}
mkdir -p tests/Integration/Repositories
mkdir -p tests/Feature/{Domain}
mkdir -p tests/Support/Factories
```

---

## Step 2: Create value objects

For each property requiring validation:

- Reference: `app/Domain/Cookie/ValueObjects/CookieName.php`,
  `app/Domain/Cookie/ValueObjects/CookiePrice.php`,
  `app/Domain/Cookie/ValueObjects/CookieStock.php`,
  `app/Domain/Cookie/ValueObjects/CookieSnapshot.php`.
- `final readonly class` with private constructor and `fromString()` /
  `fromFloat()` / etc. factories.
- `getValue()` accessor + `equals()` comparator.
- `CookieSnapshot` is special: it freezes the entity's projected state so
  events like `CookieUpdatedEvent` can carry a before/after diff without
  exposing the entity itself. Required for every property-changing event.

**Invoke:** `ddd-specialist` + `php-specialist`.

---

## Step 3: Create entity (`AggregateRoot`)

**Reference:** `app/Domain/Cookie/Entities/Cookie.php`.

The entity **MUST**:

- Implement `\App\Domain\Shared\Aggregate\AggregateRootInterface` (E06).
- Use the `\App\Domain\Shared\AggregateRoot` trait for the event-bag plumbing.
- Expose `pullEvents()` (single-shot drain) + `peekEvents()` (test-only).
- Lifecycle methods (E07) **raise events on the entity itself**; handlers
  call them and drain pending events afterwards:
  - `softDelete()` → `{Entity}DeletedEvent`
  - `restore()` → `{Entity}RestoredEvent`
  - `activate()` / `deactivate()` → `{Entity}Activated/Deactivated`
  - `changeStock($delta, StockChangeReason $reason)` → `{Entity}StockChanged`
- Require an `AggregateHydrator` key for any internal mutator that should
  only be called by trusted persistence code (`bumpVersion()`, `assignId()`):
  the only way to obtain a hydrator key is `AggregateHydrator::key()`
  (E06 — prevents accidental external misuse).
- Extract complex invariants into a co-located helper:
  `{Entity}StateAssertions::*` (Cookie does this for soft-delete + active
  assertions).
- The legacy `CookieAccessors` trait was **DELETED in E07**. Do not clone
  it into the new domain — accessors that need lifecycle context belong on
  the entity directly.

**Invoke:** `ddd-specialist` + `clean-code-specialist`.

---

## Step 4: Create commands

For every command (CRUD + Restore at minimum):

**Command DTO:**
- Reference: `app/Domain/Cookie/Commands/CreateCookie/CreateCookieCommand.php`.
- `final readonly class` with typed public properties.

**Handler:**
- Reference: `app/Domain/Cookie/Commands/CreateCookie/CreateCookieHandler.php`.
- `final readonly class … implements CommandHandlerInterface<TCommand, TResult>` (E05).
- **OR** `extends AbstractCommandHandler` for the template-method base if
  you want the start/success/failure-log boilerplate done for you (also E05).
  The Cookie handlers currently implement the interface directly; the
  abstract base is available for new domains that prefer the template form.
- Constructor injects: repository port + `EventDispatcherInterface` + PSR-3 logger.
- **MUST** call `pullEvents()` on the aggregate after a successful persist and
  forward the drained events to the dispatcher.
- Use `ErrorCodes` enum (created in Step 11) for any thrown `DomainException`.

**Restore handler** (E07) is the soft-delete inverse: it loads the
soft-deleted entity (`$repo->findByIdIncludingDeleted($id)`), calls
`$entity->restore()`, persists, then drains events. Must round-trip via the
same repository the deletion used.

**Invoke:** `cqrs-specialist` + `clean-code-specialist`.

---

## Step 5: Create queries

For each query:

**Query DTO:** `final readonly class` with the filter parameters.

**Handler:**
- `final readonly class … implements QueryHandlerInterface<TQuery, TResult>` (E05).
- Constructor injects: `{Entity}QueryRepositoryInterface` + PSR-3 logger
  + `LogConfigPort`.
- Returns `{Entity}DTO` or `{Entity}DTO[]` — never an entity.

**Invoke:** `cqrs-specialist`.

---

## Step 6: Create events (`AbstractDomainEvent` envelope)

**Reference:** `app/Domain/Cookie/Events/CookieCreated/CookieCreatedEvent.php`.

Every event **MUST**:

- `extend AbstractDomainEvent` (E04). This gives every event the five-field
  envelope: `eventId` (UUIDv7), `occurredAt` (UTC), `actorId`,
  `aggregateType`, `aggregateId`.
- Override `jsonSerialize()` by merging the parent envelope with the
  domain-specific payload:
  `return array_merge(parent::jsonSerialize(), ['stockBefore' => …]);`.
- Live in its own directory:
  `Events/{Entity}{PastVerb}/{Entity}{PastVerb}Event.php`.

**Event handler:**
- Reference: `app/Domain/Cookie/Events/CookieCreated/CookieCreatedEventHandler.php`.
- `__invoke({Entity}CreatedEvent $event): void`.
- Default behaviour: log the event with the channel-specific PSR-3 logger.
- If the handler performs an external side effect (email, webhook, search
  index update) it **MUST** consult `ProcessedEventStoreInterface` (E12.5;
  interface lands in that epic) keyed on `$event->getEventId()` to make the
  side effect at-most-once across outbox replays.

**Invoke:** `cqrs-specialist`.

---

## Step 7: Create the service provider

**Reference:** `app/Domain/Cookie/CookieServiceProvider.php`.

The provider:

- Carries `#[DomainServiceProvider]` so `ServiceProviderRegistry` auto-finds it.
- Implements `DomainServiceProviderInterface`.
- Declares `getRepositories()` returning the keys it needs
  (`['{entity}Repository', '{entity}QueryRepository', 'eventDispatcher',
  'logger', 'loggingConfig']`).
- Implements `registerCommands()` / `registerQueries()` / `registerEvents()`
  with one `bus->register()` / `dispatcher->subscribe()` line per
  command/query/event.
- Implements `registerRoutes(RouteCollection $routes)` — domain owns its
  routes; you do **NOT** edit `app/Config/Routes.php`.
- (Future) `registerProjections(ProjectionRegistry $registry)` lands in
  E13. Until then, add projection wiring in the `.php.example` file's
  re-enable comment block.

**Invoke:** `cqrs-specialist` + `php-specialist`.

---

## Step 8: Create repository (write side) + query repository (read side)

**Write-side repository** (`app/Domain/{Domain}/Repositories/{Entity}Repository.php`):

- Implements `{Entity}RepositoryInterface` (the port).
- Methods: `save($entity)`, `findById($id)`, `findByIdIncludingDeleted($id)`
  (needed for restore), `delete($id)`, `existsByName(…)`.
- Returns domain entities (or `null`), never arrays.
- `toDomainEntity()` helper hydrates the entity using `AggregateHydrator::key()`
  + the entity's `reconstitute()` factory.
- Optimistic locking via the `version` column — increment on each save,
  reject mismatches.
- Tagged with `#[AutoBind]` so `ServiceProviderRegistry` resolves the
  interface→class mapping without a `Services.php` edit.

**Query-side repository** (`app/Domain/{Domain}/Repositories/{Entity}QueryRepository.php`):

- Implements `{Entity}QueryRepositoryInterface`.
- Returns `{Entity}DTO` instances — never entities. Different shape from the
  write side because reads are about presentation, not invariants.
- Queries the **same physical table** as the write side by default. A
  dedicated projection table only exists if you re-enable the projection
  example (see Step 13).

**Model:** thin `app/Models/{Domain}/{Entity}Model.php` — extends CI4 Model,
sets `$table`, `$allowedFields`, soft-delete columns.

**Invoke:** `codeigniter4-specialist` + `phpstan-specialist`.

---

## Step 9: Repository wiring via `#[AutoBind]`

You do **NOT** edit `app/Config/Services.php` for a domain repository.
Tag the concrete repository class with the `#[AutoBind]` attribute (matched
on the interface name); `ServiceProviderRegistry` resolves it on boot.

The `Services.php` edit that used to be required here was removed in Phase 3
Group B. If your domain needs an *external* shared service (a Money formatter,
a tax engine), add it under `app/Domain/Shared/Services/` and register it
through `Config\Services` only if it must be a CI4-managed singleton.

---

## Step 10: Create migration

```bash
php spark make:migration Create{Entities}Table
```

**Filename convention:** CI4 produces `YYYY-MM-DD-HHMMSS_Description.php`.
Keep the ISO date prefix; do **not** rename to underscore-style dates. Cookie's
canonical migration is `2025-01-21-000001_CreateCookiesTable.php`.

**Columns required by the template:**

- `id` `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- domain properties
- `version` `INT UNSIGNED NOT NULL DEFAULT 0` — optimistic locking
- `created_at` / `updated_at` — `DATETIME`, application-managed (CI4 timestamps)
- `deleted_at` — `DATETIME NULL` — soft-delete column (required)

Run: `php spark migrate`.

**Invoke:** `codeigniter4-specialist`.

---

## Step 11: Create `ErrorCodes` (E08)

`app/Domain/{Domain}/ErrorCodes.php` — `final class` (or `enum` post-E16)
holding the integer error codes thrown by the domain's commands. Reference:
`app/Domain/Cookie/ErrorCodes.php`.

Every `DomainException` raised in a handler **MUST** carry one of these codes
so the `AbstractCommandHandler::determineErrorCode()` resolver picks it up
without `str_contains` on the message text.

---

## Step 12: Create controller + routes

**Controller** (`app/Controllers/Domain/{Domain}/{Entity}Controller.php`):

- Reference: `app/Controllers/Domain/Cookie/CookieController.php`.
- Constructor-inject `CommandBus` + `QueryBus`.
- Thin actions: build command/query, dispatch, return view or JSON.
- No business logic; no direct repository or model access.

**Routes:** declared inside `{Domain}ServiceProvider::registerRoutes()` —
**not** in `app/Config/Routes.php`. Pattern:

```php
$routes->group('{entities}', ['namespace' => 'App\Controllers\Domain\{Domain}'], …);
```

**Invoke:** `cqrs-specialist` + `codeigniter4-specialist` + `clean-code-specialist`.

---

## Step 13: (Optional) Set up the projection scaffold

If your domain genuinely needs a denormalised read table (different indexes,
join-free reads), copy the Cookie projection example as a starting point:

- Copy `app/Domain/Cookie/Projections/CookieReadModelProjection.php.example`
  to `app/Domain/{Domain}/Projections/{Entity}ReadModelProjection.php` (note
  the `.php` extension — `.example` files are inert).
- Adapt the table name, subscribed events, and `rowFor()` shape.
- Add a `Create{Entities}ReadModelTable` migration.
- Register the projection through `ProjectionRegistry::register()` from
  `{Domain}ServiceProvider`. (The dedicated `registerProjections()` hook
  on `DomainServiceProviderInterface` is scheduled for E13.)
- See `.claude/documentation/PROJECTIONS.md` for the full lifecycle.

For a single-aggregate template like Cookie, keep the file as `.example` —
the canonical `{entities}` table services both reads and writes.

---

## Step 14: Create views (4 files)

References:
- `app/Views/cookies/index.php`
- `app/Views/cookies/show.php`
- `app/Views/cookies/create.php`
- `app/Views/cookies/edit.php`

Bootstrap 5 + flash messages + validation feedback. View collapse to a
single shared partial set is planned for E14.

---

## Step 15: Create ALL tests

Use `test-specialist`. Required coverage = **90 %** (`composer test`
enforces this through Clover).

The full test layout is enumerated above under "Test layer" — every Cookie
test directory must have an equivalent in your new domain.

**Critical tests:**

- `Entities/{Entity}Test.php` — every lifecycle method (`softDelete`,
  `restore`, `activate`, `deactivate`, `changeStock`) round-trips an event
  through `pullEvents()`.
- `Integration/Repositories/{Entity}OptimisticLockingTest.php` — concurrent
  updates with mismatched `version` fail.
- `Feature/{Domain}/{Entity}QueryE2ETest.php` — end-to-end through the
  query bus.

---

## Step 16: Final validation

```bash
composer check                                  # docblocks:audit, phpcs, phpstan, deptrac, phpunit
composer docs:cookie-sync                       # checks scaffolding docs vs Cookie reality
```

All must return 0. The `docs:cookie-sync` step is the forcing function
that keeps **this skill file** in lock-step with the Cookie domain: if
Cookie grows a new file and you forget to list it here, CI fails.

**Invoke in sequence:**
1. `phpstan-specialist` — Level 8, zero errors.
2. `slevomat-specialist` — zero violations.
3. `test-specialist` — ≥ 90 % coverage.

---

## Completion checklist

- [ ] Domain layer files match the canonical Cookie layout (see top of file).
- [ ] Every event extends `AbstractDomainEvent`.
- [ ] Entity implements `AggregateRootInterface`.
- [ ] Trusted mutators take `AggregateHydrator` keys.
- [ ] Handlers implement `CommandHandlerInterface` / `QueryHandlerInterface`
      (or extend `AbstractCommandHandler` / `AbstractQueryHandler`).
- [ ] Restore command + lifecycle events (Activated/Deactivated) wired.
- [ ] `ErrorCodes` class exists and every `DomainException` uses it.
- [ ] Service provider registers commands, queries, events, routes.
- [ ] Repository tagged `#[AutoBind]` — no `Services.php` edit.
- [ ] Migration ISO-prefixed; table has `version` + `deleted_at`.
- [ ] All test directories created and populated.
- [ ] `composer check` → 0.
- [ ] `composer docs:cookie-sync` → 0.

---

## Round-3 round-trip — which PRs reshaped Cookie

The Cookie domain looks the way it does today because of these PRs landed
into `stabilization/erp-foundation` during the round-3 remediation:

| Epic | PR | What it added/changed |
|---|---|---|
| **E04** | #31 | `AbstractDomainEvent` envelope + `CookieChangeSet` VO. Every event got a 5-field envelope (`eventId`, `occurredAt`, `actorId`, `aggregateType`, `aggregateId`). |
| **E05** | #34 | `CommandHandlerInterface` / `QueryHandlerInterface`, `AbstractCommandHandler` / `AbstractQueryHandler` template bases, `ClockInterface` + `SystemClock`, `LogSampler`. |
| **E06** | #33 | `AggregateRootInterface` + `AggregateHydrator` key. Cookie entity implements the interface; private mutators are gated by the hydrator. |
| **E07** | #35 | Cookie entity owns its lifecycle. Added `softDelete()`/`restore()`/`activate()`/`deactivate()`/`changeStock()`, Restore command + handler, and four new events. `CookieAccessors` trait deleted. |
| **E08** | (in-tree) | `StockChangeReason` enum, `CookieSnapshot` VO, domain-scoped `ErrorCodes`. |
| **E12.5** | pending | `ProcessedEventStoreInterface` for at-most-once side-effect handlers — interface contract documented above; concrete adapter lands with E12. |
| **E17** | (in-tree) | PHP 8.3 idiom polish: `#[\Override]`, `Stringable`, `final` on controller/model, `hrtime`-based timing. |

Pending PRs that will trigger the **next** E15 sweep (and require
re-running `bin/docs-cookie-sync`):

- **E09** — multi-currency, `Money` VO, deprecate `CookiePrice` shortcuts.
- **E10** — collapse `CookieView` into `CookieDTO`.
- **E11** — repository hygiene (split `CookieRepository` if it grew past 200 LOC).
- **E12** — outbox UNIQUE on `event_uuid` + relay hardening.
- **E13** — provider DI + `registerProjections()` hook on `DomainServiceProviderInterface`.
- **E14** — view collapse into shared partials.

---

## Success criteria

- Cookie file inventory matches this skill (enforced by `docs:cookie-sync`).
- All specialists invoked.
- All gates green: `composer check` + `composer docs:cookie-sync`.
- New domain compiles, tests, and boots without editing `app/Config/Services.php`.
