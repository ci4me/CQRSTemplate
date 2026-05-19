# Architecture Decision Records

**Key architectural decisions and rationale for this CQRS/DDD template.**

---

## ADR 1: CQRS Pattern

**Status:** Accepted

**Context:**
Traditional CRUD applications mix read and write concerns, leading to complex models that try to serve both purposes poorly.

**Decision:**
Separate read (queries) and write (commands) operations with distinct models.

**Consequences:**

**Positive:**
- Optimized read models (can query database directly)
- Optimized write models (rich domain logic)
- Easier to scale reads vs. writes independently
- Clear separation of concerns
- Better suited for event sourcing in future

**Negative:**
- More boilerplate code initially
- Learning curve for developers unfamiliar with CQRS
- More files to maintain (2 per operation)

**Alternatives Considered:**
- Traditional CRUD repositories (rejected - mixed concerns)
- Full event sourcing (too complex for template)

---

## ADR 2: Domain-Driven Design Tactical Patterns

**Status:** Accepted

**Context:**
Need to prevent anemic domain models and ensure business logic lives in domain layer.

**Decision:**
Use DDD tactical patterns: Value Objects, Entities, Aggregates, Repository Pattern.

**Consequences:**

**Positive:**
- Business rules co-located with data
- Ubiquitous language in code
- Self-validating value objects prevent invalid state
- Clear boundaries (aggregates)
- Testable domain logic in isolation

**Negative:**
- More files (value objects for simple properties)
- Requires understanding of DDD concepts
- Private constructors feel unnatural initially

**Alternatives Considered:**
- Active Record pattern (rejected - mixes persistence with domain)
- Data Mapper without DDD (rejected - anemic models)

---

## ADR 3: PHP 8.4 with Strict Typing

**Status:** Accepted

**Context:**
PHP 8+ offers significant type safety improvements.

**Decision:**
Require PHP 8.4+, use `declare(strict_types=1)` in all files, readonly properties, constructor promotion.

**Consequences:**

**Positive:**
- Catch type errors at runtime (strict mode)
- PHPStan Level 8 compliance possible
- Readonly value objects prevent mutation bugs
- Constructor promotion reduces boilerplate
- Better IDE autocomplete and refactoring

**Negative:**
- Requires PHP 8.4+ (cannot use on older servers)
- Strict types can feel verbose initially
- Readonly prevents some flexibility

**Alternatives Considered:**
- PHP 7.4 (rejected - missing key features)
- Loose typing (rejected - too many runtime errors)

---

## ADR 4: Auto-Discovery with Attributes

**Status:** Accepted

**Context:**
Manually registering handlers in Services.php is error-prone and violates Open/Closed Principle.

**Decision:**
Use PHP 8+ attributes (#[DomainServiceProvider]) for automatic discovery and registration.

**Consequences:**

**Positive:**
- Zero edits to Services.php when adding domains
- Cannot forget to register handlers
- Clear marking of service providers
- Follows Open/Closed Principle

**Negative:**
- Magic behavior (less explicit)
- Requires understanding of attribute scanning
- Slight performance overhead on first request (cached)

**Alternatives Considered:**
- Manual registration (rejected - error-prone, violates OCP)
- Convention-based discovery (rejected - too implicit)

---

## ADR 5: Immutable Commands, Queries, Events

**Status:** Accepted

**Context:**
DTOs passed through message buses should not be modified.

**Decision:**
All commands, queries, and events are readonly classes.

**Consequences:**

**Positive:**
- Thread-safe (if using async in future)
- Clear data flow (cannot be modified)
- Easier to reason about
- Prevents accidental mutations

**Negative:**
- Cannot modify command after creation
- Requires creating new instance for changes

**Alternatives Considered:**
- Mutable DTOs (rejected - prone to bugs)

---

## ADR 6: One Handler Per Command/Query

**Status:** Accepted

**Context:**
CQRS pattern suggests separation, but how granular?

**Decision:**
Exactly one handler per command/query. No shared handlers.

**Consequences:**

**Positive:**
- Single Responsibility Principle
- Easy to test in isolation
- Clear 1:1 mapping
- Easy to find code

**Negative:**
- More files
- Some code duplication between similar handlers

**Alternatives Considered:**
- Generic CRUD handler (rejected - violates SRP)
- Handler per aggregate (rejected - too coarse)

---

## ADR 7: Soft Deletes by Default

**Status:** Accepted

**Context:**
Hard deletes lose data and break audit trails.

**Decision:**
All entities support soft deletion (deleted_at timestamp).

**Consequences:**

**Positive:**
- Data recovery possible
- Audit trail maintained
- Foreign key relationships preserved

**Negative:**
- Queries must filter deleted records
- Database grows over time
- Soft-deleted records can cause unique constraint issues

**Alternatives Considered:**
- Hard deletes (rejected - data loss)
- Archive tables (rejected - more complex)

---

## ADR 8: Repository Returns Domain Entities

**Status:** Accepted

**Context:**
Repositories can return arrays or domain entities.

**Decision:**
Repositories always return domain entities, never arrays.

**Consequences:**

**Positive:**
- Type safety (PHPStan can verify)
- Domain logic available on returned objects
- Consistent API

**Negative:**
- Performance overhead (mapping arrays to objects)
- Cannot return partial data easily

**Alternatives Considered:**
- Return arrays (rejected - loses type safety)
- Return DTOs (rejected - disconnected from domain)

---

## ADR 9: CodeIgniter 4 as Infrastructure

**Status:** Accepted

**Context:**
Need PHP framework for routing, ORM, migrations.

**Decision:**
Use CodeIgniter 4 but isolate it to infrastructure layer.

**Consequences:**

**Positive:**
- Lightweight framework
- Good ORM and migration tools
- Easy to learn
- Fast performance

**Negative:**
- Domain layer couples to CI4 interfaces
- Not as feature-rich as Laravel
- Smaller ecosystem

**Alternatives Considered:**
- Laravel (rejected - too opinionated, heavier)
- Symfony (rejected - too complex for template)
- Framework-agnostic (rejected - too much boilerplate)

---

## ADR 10: PHPStan Level 8 + Slevomat Standards

**Status:** Accepted

**Context:**
Need to enforce code quality automatically.

**Decision:**
Require PHPStan Level 8 and Slevomat Coding Standard compliance.

**Consequences:**

**Positive:**
- Catch bugs before runtime
- Enforce strict type safety
- Consistent code style
- Better IDE support

**Negative:**
- Strict requirements slow initial development
- Learning curve for annotations
- Some false positives requiring workarounds

**Alternatives Considered:**
- PHPStan Level 6 (rejected - not strict enough)
- Psalm (rejected - less popular)
- No static analysis (rejected - too many runtime errors)

---

## ADR 11: Test Pyramid: 70/20/10

**Status:** Accepted

**Context:**
How to distribute test effort?

**Decision:**
70% unit tests, 20% integration tests, 10% feature tests.

**Consequences:**

**Positive:**
- Fast test suite (unit tests are fast)
- Good coverage of business logic
- Some database testing
- Some full-stack testing

**Negative:**
- Integration/feature tests are slower
- May miss some integration issues

**Alternatives Considered:**
- Equal distribution (rejected - too slow)
- Only unit tests (rejected - misses integration bugs)

---

## ADR 12: AI Agent Optimization

**Status:** Accepted

**Context:**
This template is designed for AI agent collaboration.

**Decision:**
Add AI-optimized docblocks, specialized subagents, and orchestrator patterns.

**Consequences:**

**Positive:**
- AI agents understand code better
- Automated quality enforcement
- Consistent code generation
- Self-documenting architecture

**Negative:**
- More verbose docblocks
- Requires Claude Code or compatible tool
- Learning curve for humans reading AI annotations

**Alternatives Considered:**
- Standard docblocks only (rejected - less context for AI)
- No automation (rejected - misses opportunity)

---

**These decisions shape the template. Understand them before modifying architecture.**
