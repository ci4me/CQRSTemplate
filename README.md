# CodeIgniter 4 CQRS Template

[![CI](https://github.com/ci4me/CQRSTemplate/actions/workflows/ci.yml/badge.svg)](https://github.com/ci4me/CQRSTemplate/actions/workflows/ci.yml)
[![CodeQL](https://github.com/ci4me/CQRSTemplate/actions/workflows/codeql.yml/badge.svg)](https://github.com/ci4me/CQRSTemplate/actions/workflows/codeql.yml)
[![OpenSSF Scorecard](https://api.securityscorecards.dev/projects/github.com/ci4me/CQRSTemplate/badge)](https://securityscorecards.dev/viewer/?uri=github.com/ci4me/CQRSTemplate)
[![Conventional Commits](https://img.shields.io/badge/Conventional%20Commits-1.0.0-yellow.svg)](https://conventionalcommits.org)
[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-Level%208-brightgreen.svg)](phpstan.neon)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

A production-ready CodeIgniter 4 project template implementing **CQRS** (Command Query Responsibility Segregation) with **Domain-Driven Design** principles, **AI-agent-enforced quality gates**, and **hardened git tooling** (signed commits, server-side ruleset, supply-chain scanning).

## Features

✅ **CQRS Architecture** - Clear separation between reads and writes
✅ **Domain-Driven Design** - Business logic in domain layer
✅ **Type-Safe** - PHPStan Level 8 compliant
✅ **Clean Code** - PSR-12 + Slevomat coding standards
✅ **90%+ Test Coverage** - Comprehensive test suite, gated in CI
✅ **AI-Optimized** - Extensive documentation for AI agents
✅ **Value Objects** - Strong validation and type safety
✅ **Event-Driven** - Domain events for side effects
✅ **Automated Quality Enforcement** - 15 AI agent definitions (10 core quality agents + 5 utility/MCP agents)
✅ **Auto-Discovery** - Zero-configuration domain registration
✅ **Hardened Git Workflow** - Conventional Commits + signed commits + 3-layer enforcement
✅ **Supply-Chain Security** - Gitleaks · CodeQL · OpenSSF Scorecard · Dependabot · Dependency Review

## AI Agent System

This template includes a comprehensive AI agent system for **autonomous code quality enforcement**:

### 15 AI Agent Definitions

**Core quality agents:**
- **php-specialist** - PHP 8.3+ features, type safety, modern patterns
- **phpstan-specialist** - Level 8 static analysis enforcement
- **clean-code-specialist** - SOLID, DRY, max 20 lines/method
- **cqrs-specialist** - Command/Query/Event pattern enforcement
- **ddd-specialist** - Entity, Value Object, Aggregate patterns
- **test-specialist** - Test pyramid, 90% coverage enforcement
- **slevomat-specialist** - Coding standards enforcement
- **codeigniter4-specialist** - CI4 best practices
- **git-specialist** - Conventional Commits + signed commits + branch hygiene
- **claude-code-specialist** - Creates new agents/skills/commands

**Utility/MCP agents:**
- **serena-code-assistant** - Symbol-level code navigation and editing
- **chrome-devtools-expert** - Browser debugging and performance analysis
- **playwright-automation** - End-to-end browser automation
- **context7-docs** - Current library documentation retrieval
- **markitdown-converter** - Document conversion to Markdown

### Quick Commands
```bash
/add-domain Order           # Create complete 45+ file/touchpoint domain
/add-property Cookie flavor string  # Add property across all layers
/add-business-rule Cookie   # Add business rule with tests
/review-domain Cookie       # Comprehensive code review
/enforce-quality            # Run all quality checks
```

### Strategic Planning
For complex tasks (5+ steps), Claude Code automatically uses the **strategic-planner** skill with:
- 🌳 **Tree-of-Thought** - Explores multiple solution approaches
- 💭 **Chain-of-Thought** - Shows transparent reasoning
- ⚛️ **SMART-E Atomic Tasks** - Generates precise, executable tasks
- 🐍 **Python Analysis** - Validates atomicity and optimizes dependencies
- 📊 **Critical Path** - Calculates optimal execution order (25-50% time savings)

**Example:**
```
User: "Add payment system"
→ Explores: Stripe vs PayPal vs Custom
→ Selects: Stripe (explained reasoning)
→ Generates: 47 SMART-E atomic tasks
→ Optimizes: 42 min (vs 85 min sequential)
→ Validates: All tasks pass atomicity rules
```

### Reusable Skills

Claude-specific skills live in `.claude/skills/` (10 total); Devin-specific reusable skills live in `.agents/skills/` (4 total).

- **domain-scaffolding** - Generate complete domain (45+ files/touchpoints)
- **property-addition** - Add property (20+ files modified)
- **business-rule-addition** - Add rules with correct placement
- **code-review** - Multi-specialist parallel review

### Automatic Enforcement
All code changes automatically:
1. Invoke appropriate specialists (2-3 in parallel)
2. Validate against PHPStan Level 8
3. Validate against Slevomat standards
4. Maintain 90%+ test coverage
5. **Reject** non-compliant code before commit

**See `.claude/CLAUDE.md` for complete agent documentation.**

## Requirements

- PHP 8.3+
- MySQL 8.0+
- Composer
- Node.js (for frontend assets, optional)

## Quick Start

### 1. Clone & Install

```bash
git clone <repository-url> your-project
cd your-project
composer install
```

### 2. Configure Database

Copy `.env` and configure database:

```bash
cp env .env
```

Edit `.env`:

```ini
database.default.hostname = localhost
database.default.database = ci4_cqrs
database.default.username = root
database.default.password = root
database.default.DBDriver = MySQLi
```

### 3. Create Database

```bash
mysql -u root -proot -e "CREATE DATABASE ci4_cqrs"
```

### 4. Run Migrations & Seed

```bash
php spark migrate --all
php spark db:seed DatabaseSeeder
```

### 5. Start Development Server

```bash
php spark serve
```

Visit: `http://localhost:8080/cookies`

## Project Structure

```
app/
├── Domain/              # Business logic (framework-agnostic)
│   ├── Cookie/          # Cookie domain (template example)
│   │   ├── CookieServiceProvider.php  # Auto-discovery with #[DomainServiceProvider]
│   │   ├── Commands/    # Each command in separate folder
│   │   ├── Queries/     # Each query in separate folder
│   │   ├── Events/      # Each event + handler in same folder
│   │   ├── Entities/    # Domain entities
│   │   └── ValueObjects/  # Value objects (separate from entities)
│   └── Shared/          # Shared domain code
├── Infrastructure/      # Technical implementation
│   ├── Attributes/      # Native PHP attributes for auto-discovery
│   ├── Bus/             # Command/Query buses, EventDispatcher
│   ├── Persistence/     # Repository implementations and infrastructure models
│   └── ServiceProvider/  # Auto-discovery system
├── Models/              # CodeIgniter models and persistence traits
│   └── Cookie/          # CookieModel + logging traits
├── Controllers/         # HTTP layer (thin controllers)
│   └── Domain/
│       └── Cookie/      # Controllers organized by domain
├── Views/              # UI templates
└── Database/           # Migrations, Seeds
```

## Architecture

### CQRS Pattern

**Commands** - Write operations that change state
- `CreateCookieCommand` → `CreateCookieHandler`
- Located in: `app/Domain/{Domain}/Commands/{CommandName}/`
- Each command in its own folder

**Queries** - Read operations that return data
- `GetCookieByIdQuery` → `GetCookieByIdHandler`
- Located in: `app/Domain/{Domain}/Queries/{QueryName}/`
- Each query in its own folder

**Events** - Things that happened in the domain
- `CookieCreatedEvent`, `CookieDeletedEvent`
- Located in: `app/Domain/{Domain}/Events/{EventName}/`
- Each event in its own folder

### Cookie Domain (Template)

The Cookie domain serves as a **template** for creating new domains. It includes:

- **Entities**: Cookie, CookieName (Value Object), CookiePrice (Value Object)
- **Commands**: Create, Update, Delete
- **Queries**: GetById, GetAll, GetPaginated
- **Events**: Created, Updated, Deleted
- **Repository**: CookieRepository
- **Controller**: CookieController
- **Views**: index, show, create, edit

## Development

### Run Tests

```bash
composer test                 # Run all tests
composer test:coverage       # Generate coverage report
```

### Code Quality

```bash
composer phpstan             # Static analysis (Level 8)
composer phpcs               # Code style check
composer phpcbf              # Auto-fix code style
composer check               # Run all checks
```

### Database

```bash
php spark migrate --all      # Run all namespaced migrations
php spark migrate:rollback   # Rollback last migration
php spark db:seed DatabaseSeeder # Seed users and cookie data
```

## Adding a New Domain

### Zero-Configuration Auto-Discovery

Thanks to native PHP attributes, adding a new domain is incredibly simple:

1. Create `app/Domain/YourDomain/` folder structure
2. Create `YourDomainServiceProvider.php` with `#[DomainServiceProvider]` attribute
3. Implement `DomainServiceProviderInterface`
4. **Done!** System automatically discovers and registers everything

**No editing Services.php or any central files!**

See **ADDING_DOMAINS.md** for complete step-by-step guide with examples.

## Modifying Entities

When adding/removing properties from an entity, see **MODIFYING_ENTITIES.md** for a complete checklist of files to update.

## Code Standards

### No Else Statements

❌ **Bad:**
```php
if ($valid) {
    return $result;
} else {
    throw new Exception();
}
```

✅ **Good:**
```php
if (!$valid) {
    throw new Exception();
}

return $result;
```

### Full Type Hints

```php
public function handle(CreateCookieCommand $command): int
{
    // ...
}
```

### Value Objects for Validation

```php
// Instead of:
$name = trim($_POST['name']);
if (strlen($name) < 3) throw new Exception();

// Use:
$name = CookieName::fromString($_POST['name']); // Validates automatically
```

### AI-Optimized Docblocks

```php
/**
 * Handles cookie creation.
 *
 * Business Rules:
 * - Name must be unique
 * - Price must be > 0
 * - Stock cannot be negative
 *
 * Process:
 * 1. Validate data
 * 2. Check uniqueness
 * 3. Create entity
 * 4. Persist
 * 5. Dispatch event
 *
 * @param CreateCookieCommand $command
 * @return int Cookie ID
 */
```

## Testing

### Unit Tests
Test individual components:
- Command Handlers
- Query Handlers
- Value Objects
- Entities

### Integration Tests
Test database operations:
- Repositories
- Models

### Feature Tests
Test HTTP flows:
- Controllers
- Full CRUD operations

## Configuration

### Dependency Injection

All services registered in `app/Config/Services.php`:

```php
Services::commandBus()  // Command dispatcher
Services::queryBus()    // Query dispatcher
Services::cookieRepository()  // Data access
```

### Routes

Defined in `app/Config/Routes.php`:

```php
$routes->group('cookies', static function ($routes) {
    $routes->get('', 'CookieController::index');
    $routes->post('', 'CookieController::store');
    // ...
});
```

## Documentation

### For Humans
- **README.md** - This file (project overview)
- **ADDING_DOMAINS.md** - Step-by-step guide for adding domains
- **MODIFYING_ENTITIES.md** - Checklist for modifying entities
- **SETUP.md** - Initial project setup
- **TESTING.md** - Testing guidelines
- **XDEBUG_SETUP.md** - Xdebug configuration

### For AI Agents
- **.claude/CLAUDE.md** - Project memory (auto-loads on startup)
- **.claude/instructions.md** - Orchestrator pattern rules
- **.claude/agents/** - 15 agent definitions
- **.claude/skills/** - 10 Claude-specific workflow skills
- **.agents/skills/** - 4 Devin-specific reusable skills
- **.claude/commands/** - 6 slash commands
- **.claude/documentation/** - 7 comprehensive protocol documents
  - COMPLETE_FILE_INVENTORY.md
  - DOMAIN_CREATION_PROTOCOL.md
  - PROPERTY_ADDITION_PROTOCOL.md
  - BUSINESS_RULE_PROTOCOL.md
  - TESTING_GUIDELINES.md
  - ARCHITECTURE_DECISIONS.md
  - TEMPLATE_MODIFICATION_PROTOCOL.md

## Contributing

1. Follow PSR-12 + Slevomat coding standards
2. Write tests for new features (90%+ coverage)
3. Run `composer check` before committing
4. Use early returns, avoid else statements
5. Add AI-optimized docblocks

## License

MIT License

## Support

For issues and questions:
- Check **CLAUDE.md** for architecture questions
- See **MODIFYING_ENTITIES.md** for entity modifications
- Review Cookie domain as reference implementation

---

**Built with CodeIgniter 4, CQRS, and Domain-Driven Design**
