---
name: add-domain
description: >
  Scaffold a complete new domain (bounded context) in the CQRSTemplate
  project. Creates the full CQRS structure: entities, value objects,
  commands, queries, events, handlers, repository, controller, views,
  migration, and tests. References the Cookie domain as template. Use when
  the user asks to "add a domain", "create a module", "new bounded context",
  or "scaffold Order/Product/etc."
---

# add-domain

Create a complete new domain with all required files following the CQRS +
DDD patterns established by the Cookie reference domain.

## Step 1 — Gather requirements

Ask the user for:
- **Domain name** (PascalCase, singular): e.g., `Order`, `Product`, `Invoice`
- **Primary value objects**: e.g., `OrderNumber`, `ProductSKU`
- **Properties**: beyond the standard `id`, `name`, `created_at`, `updated_at`
- **Business rules**: any validation constraints

## Step 2 — Create directory structure

```bash
mkdir -p app/Domain/{Domain}/{Commands/Create{Domain},Commands/Update{Domain},Commands/Delete{Domain}}
mkdir -p app/Domain/{Domain}/{Queries/Get{Domain},Queries/List{Domain}s}
mkdir -p app/Domain/{Domain}/{Events,Entities,ValueObjects,DTOs,Ports}
mkdir -p app/Models/{Domain}
mkdir -p app/Infrastructure/Persistence/Repositories
mkdir -p app/Controllers/Domain/{Domain}
mkdir -p app/Views/{domain}
mkdir -p tests/Unit/Domain/{Domain}/{ValueObjects,Entities,Commands,Queries,Events}
mkdir -p tests/Integration/Repositories
mkdir -p tests/Feature/{Domain}
mkdir -p tests/Support/Factories
```

## Step 3 — Create Value Objects

Reference: `app/Domain/Cookie/ValueObjects/CookieName.php`

Every value object:
- `final readonly class` (PHP 8.3+)
- Private constructor with validation
- Static factory method (`fromString`, `fromFloat`, etc.)
- `getValue()` method
- `equals()` method
- Throws `\InvalidArgumentException` on invalid input

## Step 4 — Create Entity

Reference: `app/Domain/Cookie/Entities/Cookie.php`

- Protected constructor (use static factory `create()`)
- Named static factory methods
- Getter methods for all properties (no public properties)
- Business rule methods that validate before state change
- Records domain events via `recordEvent()`

## Step 5 — Create Commands + Handlers

Reference: `app/Domain/Cookie/Commands/CreateCookie/`

For each operation (Create, Update, Delete):
- `{Action}{Domain}Command.php` — `final readonly class` DTO
- `{Action}{Domain}Handler.php` — implements `HandlerInterface`
  - Constructor injects repository + event dispatcher + logger
  - `handle()` method: validate → create/update entity → persist → dispatch events

## Step 6 — Create Queries + Handlers

Reference: `app/Domain/Cookie/Queries/`

- `Get{Domain}Query.php` — `final readonly class` with ID
- `Get{Domain}Handler.php` — returns DTO, never entity
- `List{Domain}sQuery.php` — with pagination params
- `List{Domain}sHandler.php` — returns array of DTOs

## Step 7 — Create DTO

Reference: `app/Domain/Cookie/DTOs/CookieDTO.php`

- `final readonly class`
- `fromEntity()` static factory
- Public properties for all displayable fields

## Step 8 — Create Repository

1. Port (interface): `app/Domain/{Domain}/Ports/{Domain}RepositoryInterface.php`
2. Implementation: `app/Infrastructure/Persistence/Repositories/{Domain}Repository.php`
3. CI4 Model: `app/Models/{Domain}/{Domain}Model.php`

## Step 9 — Create ServiceProvider

Reference: `app/Domain/Cookie/CookieServiceProvider.php`

Register all commands and queries with the buses. The auto-discovery
system picks this up automatically.

## Step 10 — Create Migration

```bash
php spark make:migration Create{Domain}sTable
```

Fill in the migration with the schema matching your entity properties.

## Step 11 — Create Controller + Views + Routes

Reference: `app/Controllers/Domain/Cookie/CookieController.php`

- Thin controller — only dispatches commands/queries
- CRUD views in `app/Views/{domain}/`
- Routes in `app/Config/Routes.php`

## Step 12 — Create Tests

Write tests at all three levels:
- **Unit**: Value objects, entity creation, command/query handlers
- **Integration**: Repository persistence
- **Feature**: HTTP request lifecycle

## Step 13 — Verify

```bash
composer check
```

All gates must pass:
- PHPStan Level 8 (0 errors)
- PHPCS PSR-12 + Slevomat (0 violations)
- PHPUnit (90%+ coverage maintained)
