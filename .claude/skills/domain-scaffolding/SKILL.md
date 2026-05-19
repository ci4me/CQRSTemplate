---
name: domain-scaffolding
description: Scaffolds a complete new domain from scratch with full CQRS structure (45 files: entities, value objects, commands, queries, events, handlers, repository, controller, views, tests). Use when user requests to create a new domain/module/bounded context. References Cookie domain as template.
allowed-tools: [Read, Write, Edit, Glob, Grep, Bash, Task]
---

# Domain Scaffolding Skill

Automates creation of a complete domain with all 45 required files.

---

## Prerequisites

Before starting, confirm with user:
1. Domain name (PascalCase, singular): e.g., "Order", "Product"
2. Primary value objects needed (e.g., OrderNumber, ProductSKU)
3. Additional properties beyond standard (name, description, etc.)

---

## Step 1: Create Directory Structure

```bash
mkdir -p app/Domain/{Domain}/{Commands,Queries,Events,Entities,ValueObjects}
mkdir -p app/Models/{Domain}
mkdir -p app/Controllers/Domain/{Domain}
mkdir -p app/Views/{entities}
mkdir -p tests/Unit/Domain/{Domain}/{ValueObjects,Entities,Commands,Queries,Events}
mkdir -p tests/Integration/Repositories
mkdir -p tests/Feature/{Domain}
mkdir -p tests/Support/Factories
```

---

## Step 2: Create Value Objects

**For EACH value object:**

Use `ddd-specialist` to create value objects following this pattern:

**Reference:** `app/Domain/Cookie/ValueObjects/CookieName.php`
**Reference:** `app/Domain/Cookie/ValueObjects/CookiePrice.php`

Key points:
- Readonly class
- Private constructor with validation
- Static factory method (fromString, fromFloat, etc.)
- getValue() method
- equals() method for comparison

**Invoke:** `ddd-specialist` + `php-specialist` to review

---

## Step 3: Create Entity

**Reference:** `app/Domain/Cookie/Entities/Cookie.php`

Use `ddd-specialist` to create entity with:
- Private constructor
- Static create() method (for new entities)
- Static reconstitute() method (from database)
- Getters for all properties
- Business methods (not setters!)

**Invoke:** `ddd-specialist` + `clean-code-specialist` to review

---

## Step 4: Create Commands (Create, Update, Delete)

**For EACH command:**

**Command (readonly DTO):**
- Reference: `app/Domain/Cookie/Commands/CreateCookie/CreateCookieCommand.php`
- Readonly class with public properties

**Handler:**
- Reference: `app/Domain/Cookie/Commands/CreateCookie/CreateCookieHandler.php`
- Inject repository and event dispatcher
- handle() method with business logic
- Dispatch events

**Invoke:** `cqrs-specialist` + `clean-code-specialist` to review

---

## Step 5: Create Queries (ById, All, Paginated)

**For EACH query:**

**Query (readonly DTO):**
- Reference: `app/Domain/Cookie/Queries/GetCookieById/GetCookieByIdQuery.php`

**Handler:**
- Reference: `app/Domain/Cookie/Queries/GetCookieById/GetCookieByIdHandler.php`
- Inject repository
- handle() method returns entities

**Invoke:** `cqrs-specialist` to review

---

## Step 6: Create Events (Created, Updated, Deleted)

**For EACH event:**

**Event (readonly DTO):**
- Reference: `app/Domain/Cookie/Events/CookieCreated/CookieCreatedEvent.php`
- Past tense name
- Readonly with public properties

**Event Handler:**
- Reference: `app/Domain/Cookie/Events/CookieCreated/CookieCreatedEventHandler.php`
- __invoke() method
- Log event occurrence

**Invoke:** `cqrs-specialist` to review

---

## Step 7: Create Service Provider

**Reference:** `app/Domain/Cookie/CookieServiceProvider.php`

Critical:
- Add #[DomainServiceProvider] attribute
- Implement DomainServiceProviderInterface
- Register all commands, queries, events

**Invoke:** `cqrs-specialist` + `php-specialist` to review

---

## Step 8: Create Repository and Model

**Model:**
- Reference: `app/Models/Cookie/CookieModel.php`
- Extend CodeIgniter Model
- Set $table, $allowedFields, timestamps, soft deletes

**Repository:**
- Reference: `app/Models/Cookie/CookieRepository.php`
- save(), findById(), delete() methods
- toDomainEntity() private method with array shape annotation

**Invoke:** `codeigniter4-specialist` + `phpstan-specialist` to review

---

## Step 9: Add Repository to Services.php

Add method to `app/Config/Services.php`:

```php
public static function {entity}Repository(bool $getShared = true): {Entity}Repository
{
    if ($getShared) {
        return static::getSharedInstance('{entity}Repository');
    }
    return new {Entity}Repository();
}
```

---

## Step 10: Create Migration

```bash
php spark make:migration Create{Entities}Table
```

**Reference:** Cookie migration for structure

Include:
- id (auto_increment)
- All domain properties
- created_at, updated_at, deleted_at (nullable)

**Invoke:** `codeigniter4-specialist` to review

Run: `php spark migrate`

---

## Step 11: Create Controller

**Reference:** `app/Controllers/Domain/Cookie/CookieController.php`

Thin controller:
- Delegate to command/query buses
- Handle validation errors
- Return responses

**Invoke:** `cqrs-specialist` + `codeigniter4-specialist` + `clean-code-specialist`

---

## Step 12: Add Routes

**Reference:** Routes for Cookie in `app/Config/Routes.php`

Standard CRUD routes:
- GET '/' - index
- GET '/create' - create form
- POST '/' - store
- GET '/:num' - show
- GET '/:num/edit' - edit form
- POST '/:num' - update
- POST '/:num/delete' - delete

---

## Step 13: Create Views (4 files)

**References:**
- `app/Views/cookies/index.php`
- `app/Views/cookies/show.php`
- `app/Views/cookies/create.php`
- `app/Views/cookies/edit.php`

Use Bootstrap 5 with:
- Form validation feedback
- Flash messages
- Responsive design

---

## Step 14: Create ALL Tests

**Use `test-specialist` to create:**

**Unit Tests:**
- Value object tests (validation, immutability, methods)
- Entity tests (create, reconstitute, business methods)
- Command handler tests (with mocks)
- Query handler tests (with mocks)
- Event tests

**Integration Tests:**
- Repository tests (save, find, update, delete)

**Feature Tests:**
- CRUD tests (all controller actions)

**Test Factory:**
- Create{Entity}() method for test data

**Invoke:** `test-specialist` for comprehensive coverage

---

## Step 15: Final Validation

Run ALL quality checks:

```bash
vendor/bin/phpstan analyse --level=8
vendor/bin/phpcs
vendor/bin/phpunit --coverage-text
```

**Invoke in sequence:**
1. `phpstan-specialist` - MUST pass with 0 errors
2. `slevomat-specialist` - MUST pass with 0 violations
3. `test-specialist` - MUST achieve 90%+ coverage

If ANY check fails, fix violations before completing.

---

## Completion Checklist

- [ ] All 22 domain layer files created
- [ ] All 2 infrastructure files created
- [ ] Controller created
- [ ] 4 views created
- [ ] Migration created and run
- [ ] Routes added
- [ ] All 14 test files created
- [ ] Repository added to Services.php
- [ ] PHPStan Level 8: 0 errors
- [ ] Slevomat: 0 violations
- [ ] Tests: 90%+ coverage, all passing

---

## Success Criteria

✅ 45 files created
✅ All specialists invoked for their expertise
✅ All quality checks passing
✅ Domain functional and tested

**Report completion to user with file count and quality metrics.**
