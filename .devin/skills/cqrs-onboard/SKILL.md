---
name: cqrs-onboard
description: >
  Onboard to the CQRSTemplate codebase. Reads .claude/CLAUDE.md, README,
  the existing Cookie domain as reference, and the quality gates. Produces a
  structured briefing covering CQRS architecture, DDD patterns, the 10
  specialist agents, and the quality enforcement pipeline. Use when the user
  asks "what is this", "explain the codebase", "how does this work", or
  any onboarding variant.
---

# cqrs-onboard

Give someone a fast, accurate tour of the CQRSTemplate project so they can
answer "where should this change go?" without re-reading the entire repo.

## Step 1 — Read the foundational docs

In this order:

1. **`.claude/CLAUDE.md`** — project memory, code patterns, git conventions,
   mandatory agent usage, orchestrator pattern, and rejection policy.

2. **`README.md`** — features, requirements, quick start, architecture
   overview, the 10 specialized agents, and quick commands.

3. **`app/Domain/Cookie/`** — the reference domain. Read at minimum:
   - `Entities/Cookie.php` (entity pattern)
   - `ValueObjects/CookieName.php` (value object pattern)
   - `Commands/CreateCookie/CreateCookieCommand.php` (command DTO)
   - `Commands/CreateCookie/CreateCookieHandler.php` (handler pattern)
   - `CookieServiceProvider.php` (auto-discovery registration)

4. **`composer.json`** — scripts section for `check`, `phpstan`, `phpcs`,
   `test`, `test:coverage`.

## Step 2 — Produce a briefing

### What CQRSTemplate is

A production-ready CodeIgniter 4 project template implementing CQRS with
DDD principles, PHPStan Level 8, 90%+ test coverage, and hardened git
tooling.

### Architecture in five bullets

- **CQRS** — writes go through Command → CommandHandler → Repository;
  reads go through Query → QueryHandler → DTO. Never return entities
  from query handlers.
- **DDD** — domain logic lives in `app/Domain/{Name}/`. Entities,
  value objects, commands, queries, events, handlers, ports (interfaces),
  and service providers per domain.
- **Value Objects** — `final readonly class` with private constructor,
  static factory, `getValue()`, `equals()`. Self-validating.
- **Auto-Discovery** — each domain has a `{Name}ServiceProvider.php`
  that registers commands/queries with the bus. No manual wiring in
  `Config/Services.php`.
- **Three-layer git enforcement** — local `.githooks/`, Claude Code
  hook, and GitHub server-side ruleset. Conventional Commits required.

### Quality gates

```bash
composer check     # PHPStan L8 + PHPCS (PSR-12 + Slevomat) + PHPUnit
composer phpstan   # PHPStan Level 8 only
composer phpcs     # PHPCS only
composer test      # PHPUnit only
```

CI enforces:
- PHPStan Level 8 with 0 errors
- PSR-12 + Slevomat coding standards with 0 violations
- 90%+ test coverage
- Conventional Commits on all commit messages
- Gitleaks secret scanning
- Dependency review (CVE + license)
- CodeQL SAST

### Folder cheat sheet

```
app/
  Config/           CI4 configuration (Routes, Services, Filters)
  Controllers/
    Api/            REST API controllers (JWT auth)
    Domain/         Web controllers (session auth, CSRF)
  Domain/
    {Name}/         One folder per bounded context
      Commands/     Write operations (Command + Handler pairs)
      Queries/      Read operations (Query + Handler pairs)
      Events/       Domain events
      Entities/     Domain entities
      ValueObjects/ Immutable validated types
      DTOs/         Data transfer objects (for query responses)
      Ports/        Repository interfaces
      {Name}ServiceProvider.php  Auto-discovery registration
  Infrastructure/
    Bus/            Command bus, query bus, event dispatcher
    Persistence/    Repository implementations
  Models/           CI4 database models
  Views/            Blade-style CI4 views
tests/
  Unit/             Fast, isolated tests
  Integration/      Database + repository tests
  Feature/          Full HTTP request tests
  Support/          Factories, test helpers
```

### Key conventions

- Commands/Queries are `final readonly` DTOs
- Handlers implement `HandlerInterface`
- No business logic in controllers (thin controllers)
- Methods < 20 lines preferred, < 50 max
- `declare(strict_types=1)` in every PHP file
- Type hints for all parameters and returns
- Test accounts: `admin@example.com` / `customer@example.com` (password: `password123`)

## Step 3 — Stop

Don't volunteer to implement anything. This skill is for understanding.
