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

---

## CQRS pattern

### Commands (write operations)

- Represent intent to change system state.
- Named in imperative: `CreateCookieCommand`, `UpdateCookieCommand`.
- Handled by **exactly one** handler.
- May dispatch domain events.
- Located in `app/Domain/{Domain}/Commands/{CommandName}/` — each command
  has its own folder containing the command DTO and its handler.

### Queries (read operations)

- Represent requests for data; never mutate state.
- Named as questions: `GetCookieByIdQuery`, `GetAllCookiesQuery`.
- Handled by **exactly one** handler.
- Located in `app/Domain/{Domain}/Queries/{QueryName}/`.

### Events (domain events)

- Represent things that have happened. Named in past tense:
  `CookieCreatedEvent`, `CookieDeletedEvent`.
- Can have **multiple** listeners.
- Immutable (`readonly` class).
- Located in `app/Domain/{Domain}/Events/{EventName}/` — each event has its
  own folder with the event DTO and its event handler.

---

## Directory layout

```
app/Domain/{Domain}/
├── {Domain}ServiceProvider.php          # auto-discovered with #[DomainServiceProvider]
├── Commands/
│   ├── Create{Entity}/
│   │   ├── Create{Entity}Command.php
│   │   └── Create{Entity}Handler.php
│   ├── Update{Entity}/
│   └── Delete{Entity}/
├── Queries/
│   ├── Get{Entity}ById/
│   ├── GetAll{Entities}/
│   └── Get{Entities}Paginated/
├── Events/
│   ├── {Entity}Created/
│   ├── {Entity}Updated/
│   └── {Entity}Deleted/
├── Entities/
│   └── {Entity}.php
└── ValueObjects/
    ├── {Entity}Name.php
    └── {Entity}Price.php
```

A domain has **45+ files / touchpoints** in total once controllers, models,
views, migrations, seeds, and tests are counted. The exhaustive list lives in
`.claude/documentation/COMPLETE_FILE_INVENTORY.md`.

---

## Auto-discovery (zero-config domain registration)

Domains are registered automatically via a native PHP attribute:

```php
use App\Infrastructure\Attributes\DomainServiceProvider;

#[DomainServiceProvider]
final class OrderServiceProvider implements DomainServiceProviderInterface
{
    // registration methods…
}
```

Drop the file into `app/Domain/{Order}/`, mark it with the attribute, and the
`ServiceProviderRegistry` will discover and wire it on boot. **You never edit
`app/Config/Services.php`** to register a domain.

---

## Ubiquitous-language naming

| Concept | Pattern |
|---|---|
| Command | `Create{Entity}Command`, `Update{Entity}Command`, `Delete{Entity}Command` |
| Query | `Get{Entity}ByIdQuery`, `GetAll{Entities}Query`, `Get{Entities}PaginatedQuery` |
| Event | `{Entity}CreatedEvent`, `{Entity}UpdatedEvent`, `{Entity}DeletedEvent` |
| Handler | `{Command/Query/Event}Handler` |
| Value Object | `{Entity}{Property}` (e.g., `CookieName`, `CookiePrice`) |
| Entity | `{Entity}` (e.g., `Cookie`, `Order`) |
| Repository (port) | `{Entity}RepositoryInterface` in `app/Domain/{Domain}/Ports/` |
| Repository (adapter) | `{Entity}Repository` in `app/Infrastructure/Persistence/Repositories/` |
| Model | `{Entity}Model` in `app/Models/{Domain}/` |

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
- `readonly` value objects.

**CQRS / DDD:**
- Commands, queries, and events are `readonly` DTOs.
- Value objects are immutable and self-validating.
- Entities use factory methods (`create()` / `reconstitute()`).
- No business logic in controllers.
- Repository pattern for data access; repositories return entities, not arrays.

---

## Design decisions

1. **No business logic in controllers.** Controllers only orchestrate
   commands / queries via buses.
2. **Repository pattern.** Repositories abstract data access and return
   domain entities, not arrays.
3. **Value objects for validation.** Any property with a validation rule
   (e.g., `CookieName`, `CookiePrice`) is a value object.
4. **Early returns, no `else` statements.** All code uses guard clauses.
5. **Strict type safety.** PHPStan level 8 across the board.
6. **Soft deletes.** All entities support `deleted_at`.
7. **Immutable commands / queries / events.** All DTOs are `readonly`.

---

## Quick Q&A

**Q: Where is business logic?** In command / query handlers under
`app/Domain/{Domain}/Commands/` and `app/Domain/{Domain}/Queries/`.

**Q: Where is database access?** In repository adapters under
`app/Infrastructure/Persistence/Repositories/`, backed by CodeIgniter
models in `app/Models/{Domain}/` or `app/Infrastructure/Persistence/Models/`.

**Q: How do I add a new field to an entity?**
Use the `/add-property` command (or the `property-addition` skill).

**Q: How do I create a new domain?**
Use the `/add-domain` command (or the `domain-scaffolding` skill).

**Q: Where are routes defined?** `app/Config/Routes.php` (with per-domain
registration moving into `ServiceProvider::registerRoutes()` in Phase 3 of the
ongoing refactor).

**Q: How do I add validation?**
Create a value object in `app/Domain/{Domain}/ValueObjects/` with validation
in the constructor.

---

## Reference domain

Use the **Cookie domain** (`app/Domain/Cookie/`) as the canonical template.
It demonstrates the complete CQRS implementation, DDD patterns, 192 passing
tests, PHPStan Level 8 compliance, Slevomat compliance, and full logging
integration.

---

## Related documentation

- `.claude/documentation/COMPLETE_FILE_INVENTORY.md` — exhaustive 45+ file list
- `.claude/documentation/DOMAIN_CREATION_PROTOCOL.md` — manual domain creation steps
- `.claude/documentation/PROPERTY_ADDITION_PROTOCOL.md` — manual property addition
- `.claude/documentation/BUSINESS_RULE_PROTOCOL.md` — rule-placement decision tree
- `.claude/documentation/ARCHITECTURE_DECISIONS.md` — full ADR set
