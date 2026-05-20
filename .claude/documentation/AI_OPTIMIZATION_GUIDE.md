# AI Optimization Guide - How This Project Works with Claude Code

**Version:** 1.0
**Last Updated:** 2025-10-22

This document explains how the CodeIgniter 4 CQRS Template is optimized for AI agents and Claude Code, including the architecture, workflows, and integration patterns.

---

## Table of Contents

1. [Overview](#overview)
2. [Core Architecture](#core-architecture)
3. [The 9 Specialized Agents](#the-9-specialized-agents)
4. [The Orchestrator Pattern](#the-orchestrator-pattern)
5. [Automation Workflows (Skills)](#automation-workflows-skills)
6. [User-Facing Commands](#user-facing-commands)
7. [Documentation Structure](#documentation-structure)
8. [Cookie Domain as Template](#cookie-domain-as-template)
9. [Quality Enforcement System](#quality-enforcement-system)
10. [Autonomous Behavior](#autonomous-behavior)
11. [Claude Code Best Practices Applied](#claude-code-best-practices-applied)
12. [Workflow Examples](#workflow-examples)
13. [References](#references)

---

## Overview

This project is designed from the ground up to be **AI Agent-Optimized**, meaning it leverages Claude Code's capabilities to:

- **Automate repetitive tasks** (domain creation, property addition)
- **Enforce code quality** (PHPStan, Slevomat, test coverage)
- **Provide intelligent guidance** (specialized agents for different concerns)
- **Reduce errors** (automated validation and rejection of non-compliant code)
- **Accelerate development** (45+ file/touchpoint domain creation in 2-3 minutes vs 30+ minutes manual)

### Key Optimization Strategies

1. **Specialized Agents** - 9 focused agents for different aspects (PHP, PHPStan, Clean Code, CQRS, DDD, Tests, Slevomat, CodeIgniter, Claude Code)
2. **Orchestrator Pattern** - Automatic delegation to multiple specialists in parallel
3. **Reusable Skills** - Multi-step workflows for complex tasks
4. **Slash Commands** - Quick access to common operations
5. **Comprehensive Protocols** - Step-by-step guides for all common tasks
6. **Reference Implementation** - Cookie domain as AI-readable template
7. **Autonomous Behavior** - Proactive quality enforcement without asking

---

## Core Architecture

### File Organization

```
.claude/
├── CLAUDE.md                    # Auto-loads on startup (project memory)
├── instructions.md              # Global orchestrator rules
├── settings.json                # Team-shared permissions
├── agents/                      # 15 agent definitions
│   ├── php-specialist.md
│   ├── phpstan-specialist.md
│   ├── clean-code-specialist.md
│   ├── cqrs-specialist.md
│   ├── ddd-specialist.md
│   ├── test-specialist.md
│   ├── slevomat-specialist.md
│   ├── codeigniter4-specialist.md
│   ├── git-specialist.md
│   ├── claude-code-specialist.md
│   ├── serena-code-assistant.md
│   ├── chrome-devtools-expert.md
│   ├── playwright-automation.md
│   ├── context7-docs.md
│   └── markitdown-converter.md
├── skills/                      # 10 Claude-specific reusable workflows
│   ├── domain-scaffolding/
│   ├── property-addition/
│   ├── business-rule-addition/
│   ├── code-review/
│   ├── serena-code-generator/
│   ├── strategic-planner/
│   ├── chrome-devtools-expert/
│   ├── playwright-automation/
│   ├── context7-docs/
│   └── markitdown-converter/
├── commands/                    # 6 slash commands
│   ├── add-domain.md
│   ├── add-property.md
│   ├── add-business-rule.md
│   ├── review-domain.md
│   ├── enforce-quality.md
│   └── update-docs.md
└── documentation/               # comprehensive protocols and reports
    ├── COMPLETE_FILE_INVENTORY.md
    ├── DOMAIN_CREATION_PROTOCOL.md
    ├── PROPERTY_ADDITION_PROTOCOL.md
    ├── BUSINESS_RULE_PROTOCOL.md
    ├── TESTING_GUIDELINES.md
    ├── ARCHITECTURE_DECISIONS.md
    ├── TEMPLATE_MODIFICATION_PROTOCOL.md
    └── AI_OPTIMIZATION_GUIDE.md (this file)
```

### Design Principles

1. **Auto-Discovery** - Claude Code automatically loads `.claude/CLAUDE.md` on startup
2. **Separation of Concerns** - Agents validate, Skills execute workflows, Commands provide UX
3. **Single Source of Truth** - Cookie domain is the reference, documentation derives from it
4. **Fail Fast** - Quality checks reject non-compliant code immediately
5. **Parallel Execution** - Multiple specialists work simultaneously for speed

---

## The 9 Specialized Agents

### Agent Architecture

Each agent follows Claude Code best practices:
- **YAML frontmatter** with `name`, `description`, `tools`
- **Concise description** with "Use PROACTIVELY" or "MUST BE USED" keywords
- **Max 200 lines** (focused responsibility)
- **Minimal tools** (only what's necessary)

### Agent Roster

#### 1. php-specialist
**Purpose:** Enforces PHP 8.3+ features and type safety

**Key Responsibilities:**
- `declare(strict_types=1)` in all files
- Full type hints on parameters and returns
- Readonly properties for immutability
- Named parameters for clarity
- Use `===` for all comparisons

**Invocation:**
```
Use PROACTIVELY when reviewing or writing PHP code
```

**Location:** `.claude/agents/php-specialist.md`

---

#### 2. phpstan-specialist
**Purpose:** Enforces PHPStan Level 8 compliance with zero errors

**Key Responsibilities:**
- Run static analysis before commits
- Ensure 0 errors at Level 8
- Validate array type annotations
- Check return type coverage
- Verify no mixed types unless necessary

**Command:**
```bash
vendor/bin/phpstan analyse --level=8
```

**Rejection Criteria:** ANY errors → code MUST be fixed before commit

**Location:** `.claude/agents/phpstan-specialist.md`

---

#### 3. clean-code-specialist
**Purpose:** Enforces SOLID principles, DRY, max 20 lines per method

**Key Responsibilities:**
- **Method-Level:** Max 20 lines, max 3 parameters, no else after return
- **Class-Level:** Single responsibility, max 200 lines
- **Readability:** Descriptive names, early returns, guard clauses

**Invocation:**
```
Use PROACTIVELY when reviewing methods or classes
```

**Common Violations:**
- Methods exceeding 20 lines
- Nested conditionals (use early returns)
- Duplicate code (extract to methods)
- Poor naming

**Location:** `.claude/agents/clean-code-specialist.md`

---

#### 4. cqrs-specialist
**Purpose:** Enforces CQRS patterns (Commands, Queries, Events, Handlers)

**Key Responsibilities:**
- **Commands:** Immutable (readonly class), imperative names, return void or IDs
- **Queries:** Immutable (readonly class), question names, NEVER modify state
- **Events:** Past tense, immutable, can have multiple handlers
- **Handlers:** One handler per command/query, dependency injection

**Invocation:**
```
Use when creating or reviewing commands, queries, events, or handlers
```

**Pattern Validation:**
```php
// ✅ Good Command
final readonly class CreateCookieCommand {
    public function __construct(
        public string $name,
        public float $price
    ) {}
}

// ❌ Bad Command (not readonly)
final class CreateCookieCommand {
    public string $name;
    public float $price;
}
```

**Location:** `.claude/agents/cqrs-specialist.md`

---

#### 5. ddd-specialist
**Purpose:** Enforces Domain-Driven Design patterns (Entities, Value Objects, Aggregates)

**Key Responsibilities:**
- **Value Objects:** Readonly, self-validating, named factories, equality by value
- **Entities:** Private constructor, factory methods (create/reconstitute), protect invariants
- **Aggregates:** Enforce boundaries, protect invariants
- **Ubiquitous Language:** Consistent naming across layers

**Invocation:**
```
Use when designing or reviewing entities or value objects
```

**Pattern Validation:**
```php
// ✅ Good Value Object
final readonly class CookieName {
    private string $value;

    private function __construct(string $name) {
        if (strlen($name) < 3) {
            throw ValidationException::tooShort('name', 3);
        }
        $this->value = trim($name);
    }

    public static function fromString(string $name): self {
        return new self($name);
    }
}

// ✅ Good Entity
final class Cookie {
    private function __construct(...) {}

    public static function create(...): self {}
    public static function reconstitute(...): self {}
}
```

**Location:** `.claude/agents/ddd-specialist.md`

---

#### 6. test-specialist
**Purpose:** Enforces test pyramid and 90% coverage minimum

**Key Responsibilities:**
- **Test Pyramid:** 70% unit, 20% integration, 10% feature
- **Coverage:** Minimum 90% (enforced before commit)
- **Test Quality:** AAA pattern (Arrange, Act, Assert), descriptive names
- **Test Organization:** Unit/Integration/Feature folders

**Invocation:**
```
MUST BE USED when adding or modifying features
```

**Commands:**
```bash
vendor/bin/phpunit                  # Run all tests
vendor/bin/phpunit --coverage-text  # Check coverage
vendor/bin/phpunit --testdox        # Readable output
```

**Rejection Criteria:** Coverage below 90% → MUST add tests

**Location:** `.claude/agents/test-specialist.md`

---

#### 7. slevomat-specialist
**Purpose:** Enforces Slevomat coding standards

**Key Responsibilities:**
- PSR-12 compliance
- Slevomat custom rules (strict types, unused variables, etc.)
- Auto-fix violations with `phpcbf`
- Zero violations policy

**Commands:**
```bash
vendor/bin/phpcs      # Check violations
vendor/bin/phpcbf     # Auto-fix violations
```

**Invocation:**
```
Use PROACTIVELY before any code commit
```

**Rejection Criteria:** ANY violations → code MUST be fixed

**Location:** `.claude/agents/slevomat-specialist.md`

---

#### 8. codeigniter4-specialist
**Purpose:** Enforces CodeIgniter 4 best practices

**Key Responsibilities:**
- Controller patterns (thin controllers, no business logic)
- Migration best practices
- Routing conventions
- Service layer usage
- Auto-discovery patterns

**Invocation:**
```
Use when creating controllers, migrations, routes, or CI4-specific code
```

**Location:** `.claude/agents/codeigniter4-specialist.md`

---

#### 9. claude-code-specialist
**Purpose:** Meta-agent for creating new agents, skills, and commands

**Key Responsibilities:**
- Research Claude Code documentation
- Apply best practices for agent creation
- Follow YAML frontmatter patterns
- Create skills with proper structure
- Design slash commands with good UX

**Invocation:**
```
Use when creating or modifying Claude Code extensions
```

**Process:**
1. WebFetch relevant Claude Code docs
2. Extract patterns from examples
3. Follow established conventions
4. Test with simple examples first

**Location:** `.claude/agents/claude-code-specialist.md`

---

## The Orchestrator Pattern

### What is the Orchestrator Pattern?

The orchestrator pattern automatically delegates tasks to **multiple specialists in parallel** without user intervention.

**Defined in:** `.claude/instructions.md`

### Delegation Rules

| Task | Specialists Invoked (Parallel) |
|------|-------------------------------|
| Add new property to entity | `ddd-specialist` + `clean-code-specialist` + `test-specialist` |
| Create new command/handler | `cqrs-specialist` + `clean-code-specialist` + `test-specialist` |
| Refactor method | `clean-code-specialist` + `php-specialist` + `test-specialist` |
| Add business rule | `ddd-specialist` + `test-specialist` + `phpstan-specialist` |
| Fix bug | `test-specialist` + `php-specialist` + `phpstan-specialist` |
| Create new domain | `cqrs-specialist` + `ddd-specialist` + `test-specialist` |

### Execution Phases

#### Phase 1: Pre-Execution Validation
BEFORE making ANY code changes:
1. Invoke `phpstan-specialist` to check current state
2. Invoke `slevomat-specialist` to verify coding standards
3. Invoke `test-specialist` if modifying existing features

#### Phase 2: Parallel Execution
DURING code changes:
- 2-3 specialists work simultaneously
- Each validates their concern in real-time
- Violations reported immediately

#### Phase 3: Post-Execution Validation
AFTER making changes (sequential):
1. `phpstan-specialist` → MUST pass Level 8 with **0 errors**
2. `slevomat-specialist` → MUST pass with **0 violations**
3. `test-specialist` → MUST maintain **90% coverage**

### Rejection Policy

Code is **automatically rejected** if:
- ❌ Fails PHPStan Level 8
- ❌ Fails Slevomat checks
- ❌ Reduces test coverage below 90%
- ❌ Has methods exceeding 20 lines
- ❌ Violates CQRS/DDD patterns
- ❌ Lacks tests for new features

**No user confirmation needed** - specialists reject autonomously.

---

## Automation Workflows (Skills)

Skills are multi-step workflows that orchestrate multiple specialists and tools to complete complex tasks.

### Skill Architecture

Each skill has:
- `SKILL.md` with YAML frontmatter
- Supporting files in same directory
- Step-by-step execution plan
- Quality validation checkpoints

**Location:** `.claude/skills/`

---

### 1. domain-scaffolding

**Purpose:** Create a complete 45+ file/touchpoint domain from scratch

**Triggered by:** `/add-domain {DomainName}` command

**Steps:**
1. Create directory structure
2. Create Value Objects (using `ddd-specialist`)
3. Create Entities (using `ddd-specialist`)
4. Create Commands + Handlers (using `cqrs-specialist`)
5. Create Queries + Handlers (using `cqrs-specialist`)
6. Create Events + Handlers (using `cqrs-specialist`)
7. Create Repository (using `codeigniter4-specialist`)
8. Create Model (using `codeigniter4-specialist`)
9. Create Migration (using `codeigniter4-specialist`)
10. Create ServiceProvider with auto-discovery attribute
11. Create Controller (using `codeigniter4-specialist`)
12. Create Views (using `codeigniter4-specialist`)
13. Update Routes
14. Create comprehensive tests (using `test-specialist`)
15. Final validation (all specialists)

**Output:**
- 22 Domain files
- 2 Infrastructure files
- 1 Controller
- 4 Views
- 1 Migration
- 14 Test files
- 1 ServiceProvider
- Routes updated

**Quality Gates:**
- PHPStan Level 8: 0 errors
- Slevomat: 0 violations
- Test Coverage: 90%+

**Location:** `.claude/skills/domain-scaffolding/SKILL.md`

---

### 2. property-addition

**Purpose:** Add a property across all layers (20+ files modified)

**Triggered by:** `/add-property {Domain} {property} {type}` command

**Steps:**
1. Determine if Value Object is needed (using `ddd-specialist`)
2. Create Value Object with validation (if needed)
3. Update Entity (constructor, create, reconstitute, update, getter)
4. Update Model (allowedFields, validation rules)
5. Update Repository (save, toDomainEntity)
6. Update Commands (Create, Update)
7. Update Command Handlers
8. Update Controller (store, update methods)
9. Update Views (create, edit, show, index)
10. Create Migration
11. Run Migration
12. Create/Update tests for all layers
13. Update Test Factory
14. Validate with all specialists

**Files Modified:** 20+ files across all layers

**Quality Gates:**
- PHPStan Level 8: 0 errors
- Slevomat: 0 violations
- Test Coverage: Maintained or improved
- All new code tested

**Location:** `.claude/skills/property-addition/SKILL.md`

---

### 3. business-rule-addition

**Purpose:** Add business rule with correct placement (value object, entity, or handler)

**Triggered by:** `/add-business-rule {Domain}` command

**Steps:**
1. Prompt user for rule description
2. Determine correct placement (using `ddd-specialist`):
   - **Value Object:** Single value validation (e.g., price > 0)
   - **Entity Method:** Entity consistency (e.g., cannot discount already discounted)
   - **Handler:** Cross-aggregate rules (e.g., unique name check)
3. Implement rule with AI-optimized docblock
4. Create comprehensive tests:
   - Happy path
   - Each violation scenario
   - Boundary conditions
   - Edge cases
5. Validate with specialists

**Output:**
- Rule implemented in correct location
- 4+ tests created
- Docblock explains rule clearly

**Quality Gates:**
- Rule placement approved by `ddd-specialist`
- Implementation reviewed by `clean-code-specialist`
- All violation paths tested
- PHPStan and Slevomat pass

**Location:** `.claude/skills/business-rule-addition/SKILL.md`

---

### 4. code-review

**Purpose:** Multi-specialist parallel code review

**Triggered by:** `/review-domain {Domain}` command

**Execution:**
- **Analysis Phase:** Gather current quality metrics
- **Parallel Review Phase:** Invoke all 8 specialists simultaneously
- **Consolidation Phase:** Organize findings by severity
- **Auto-Fix Phase:** Fix violations automatically where possible
- **Manual Fix Guidance Phase:** Provide recommendations
- **Validation Phase:** Re-run quality checks
- **Report Phase:** Generate comprehensive summary

**Output:**
```
# Code Review Summary: Cookie Domain

## Scope
- Files Reviewed: 35
- Specialists Involved: 8
- Review Time: 2 minutes

## Findings by Severity
- Critical (MUST FIX): 0
- High Priority: 2
- Medium Priority: 5 (auto-fixed)
- Low Priority: 3 (tech debt backlog)

## Quality Metrics
- PHPStan Errors: 0 ✅
- Slevomat Violations: 0 ✅
- Test Coverage: 93% ✅

## Action Items
- [ ] Split CookieController::store() method
- [ ] Add array shape to CookieRepository::toDomainEntity()
```

**Location:** `.claude/skills/code-review/SKILL.md`

---

## User-Facing Commands

Slash commands provide quick access to skills and workflows.

**Location:** `.claude/commands/`

### Command Roster

#### 1. /add-domain {DomainName}

**Purpose:** Create complete new domain with all standard files and touchpoints

**Example:**
```
/add-domain Order
```

**Delegates to:** `domain-scaffolding` skill

**Time:** 2-3 minutes

**Location:** `.claude/commands/add-domain.md`

---

#### 2. /add-property {Domain} {property} {type}

**Purpose:** Add property across all layers (20+ files)

**Example:**
```
/add-property Cookie flavor string
```

**Delegates to:** `property-addition` skill

**Time:** 1-2 minutes

**Location:** `.claude/commands/add-property.md`

---

#### 3. /add-business-rule {Domain}

**Purpose:** Add business rule with correct placement

**Example:**
```
/add-business-rule Cookie
```

**Delegates to:** `business-rule-addition` skill

**Interactive:** Prompts for rule details

**Location:** `.claude/commands/add-business-rule.md`

---

#### 4. /review-domain {Domain}

**Purpose:** Comprehensive code review with all specialists

**Example:**
```
/review-domain Cookie
/review-domain all
```

**Delegates to:** `code-review` skill

**Output:** Detailed report with findings and recommendations

**Location:** `.claude/commands/review-domain.md`

---

#### 5. /enforce-quality [path]

**Purpose:** Run all quality checks (PHPStan, Slevomat, Tests)

**Example:**
```
/enforce-quality
/enforce-quality app/Domain/Cookie
```

**Process:**
1. Run PHPStan Level 8
2. Run Slevomat coding standards
3. Auto-fix with `phpcbf`
4. Run tests with coverage
5. Report results (READY FOR COMMIT or CANNOT COMMIT)

**Location:** `.claude/commands/enforce-quality.md`

---

#### 6. /update-docs {change-type}

**Purpose:** Update documentation after template changes

**Example:**
```
/update-docs pattern
/update-docs file-structure
/update-docs all
```

**Follows:** `TEMPLATE_MODIFICATION_PROTOCOL.md`

**Updates:**
- ARCHITECTURE_DECISIONS.md
- COMPLETE_FILE_INVENTORY.md
- Protocol documents
- Affected agents
- .claude/CLAUDE.md
- Root documentation

**Location:** `.claude/commands/update-docs.md`

---

## Documentation Structure

### 8 Comprehensive Protocols

All located in `.claude/documentation/`:

#### 1. COMPLETE_FILE_INVENTORY.md
**Purpose:** Documents all standard files and touchpoints created per domain

**Structure:**
- Domain Layer (22 files)
- Infrastructure Layer (2 files)
- Application Layer (1 file)
- Presentation Layer (4 files)
- Database Layer (1 file)
- Testing Layer (14 files)
- Configuration (1 file)

**Used by:** `domain-scaffolding` skill, documentation references

---

#### 2. DOMAIN_CREATION_PROTOCOL.md
**Purpose:** Step-by-step mandatory protocol for domain creation

**15 Steps:**
1. Create directory structure
2. Create Value Objects (with `ddd-specialist`)
3. Create Entities (with `ddd-specialist`)
4. Create Commands (with `cqrs-specialist`)
5. Create Command Handlers
6. Create Queries (with `cqrs-specialist`)
7. Create Query Handlers
8. Create Events (with `cqrs-specialist`)
9. Create Event Handlers
10. Create Repository
11. Create Model
12. Create ServiceProvider (auto-discovery)
13. Create Migration & run
14. Create Controller & Views
15. Final Validation (all specialists)

**Used by:** `domain-scaffolding` skill

---

#### 3. PROPERTY_ADDITION_PROTOCOL.md
**Purpose:** Checklist for adding properties (20+ files to modify)

**Steps:**
1. Decide Value Object vs Simple Property
2. Update Entity (constructor, create, reconstitute, getter)
3. Update Model (allowedFields, validation)
4. Update Repository (save, toDomainEntity)
5. Update Commands & Handlers
6. Update Controller
7. Update Views (create, edit, show, index)
8. Create Migration
9. Create/Update Tests
10. Update Test Factory

**Used by:** `property-addition` skill

---

#### 4. BUSINESS_RULE_PROTOCOL.md
**Purpose:** Rule placement decision tree

**Decision Tree:**
```
Is rule about a single value?
├─ Yes → Value Object (e.g., price > 0)
└─ No → Is rule about entity consistency?
    ├─ Yes → Entity Method (e.g., cannot discount already discounted)
    └─ No → Handler (e.g., unique name check across database)
```

**Includes:**
- Examples for each placement type
- Testing strategies
- AI-optimized docblock patterns

**Used by:** `business-rule-addition` skill

---

#### 5. TESTING_GUIDELINES.md
**Purpose:** Test pyramid, coverage requirements, examples

**Structure:**
- Test Pyramid (70/20/10 split)
- Minimum 90% coverage
- AAA pattern (Arrange, Act, Assert)
- Test organization (Unit/Integration/Feature)
- Naming conventions
- Test factories

**Used by:** `test-specialist`, all skills

---

#### 6. ARCHITECTURE_DECISIONS.md
**Purpose:** ADR-style documentation of key decisions

**Format:**
```markdown
## ADR #1: No Business Logic in Controllers

**Status:** Accepted
**Date:** 2024-01-15

**Context:**
Controllers should be thin and only orchestrate.

**Decision:**
All business logic goes in Command/Query Handlers.

**Consequences:**
- Controllers are easy to test
- Business logic is reusable
- Clear separation of concerns
```

**Used by:** All specialists, documentation references

---

#### 7. TEMPLATE_MODIFICATION_PROTOCOL.md
**Purpose:** What to update when Cookie domain changes

**Process:**
1. Identify what changed in Cookie domain
2. Update Cookie domain (source of truth)
3. Update Specialists (pattern descriptions, examples)
4. Update Documentation (in order):
   - ARCHITECTURE_DECISIONS.md
   - COMPLETE_FILE_INVENTORY.md
   - Protocol Documents
   - .claude/CLAUDE.md
   - Root Documentation
5. Update Skills (file counts, steps)
6. Verify consistency (grep for old references)

**Used by:** `/update-docs` command

---

#### 8. AI_OPTIMIZATION_GUIDE.md
**Purpose:** Complete guide to AI optimization (this file)

**Covers:**
- Architecture overview
- All 9 agents
- Orchestrator pattern
- All 4 skills
- All 6 commands
- Documentation structure
- Workflow examples
- References to Claude Code docs

---

## Cookie Domain as Template

The Cookie domain serves as the **AI-readable reference implementation**.

**Location:** `app/Domain/Cookie/`

### Why Cookie Domain?

1. **Complete Implementation** - All CQRS/DDD patterns demonstrated
2. **Quality Compliant** - PHPStan Level 8, Slevomat, 100% test passing
3. **AI-Optimized Docblocks** - Every class has educational comments
4. **Multiple Examples** - Shows Create, Update, Delete commands; GetById, GetAll, GetPaginated queries
5. **Value Object Examples** - CookieName, CookiePrice demonstrate validation patterns
6. **Event Examples** - CookieCreated, CookieUpdated, CookieDeleted with handlers

### How It's Used

- **`domain-scaffolding` skill** copies patterns from Cookie domain
- **All specialists** reference Cookie domain for examples
- **Documentation** derives from Cookie domain structure
- **New developers** study Cookie domain to understand patterns

### Quality Metrics

```
Cookie Domain Stats:
- Files: 45
- Test Coverage: 100%
- PHPStan Errors: 0
- Slevomat Violations: 0
- Tests: 192 (all passing)
- Lines of Code: ~2,500
```

---

## Quality Enforcement System

### Three-Phase Validation

#### Pre-Execution (Prevention)
```
User requests: "Add flavor property to Cookie"
↓
System automatically:
1. phpstan-specialist checks current state
2. slevomat-specialist verifies standards
3. test-specialist checks existing coverage
↓
Baseline established
```

#### During Execution (Real-Time)
```
Skills/Agents execute in parallel:
- ddd-specialist validates Value Object creation
- clean-code-specialist checks method lengths
- test-specialist ensures tests are created
↓
Violations caught immediately
```

#### Post-Execution (Verification)
```
After changes complete:
1. phpstan-specialist → MUST be 0 errors
2. slevomat-specialist → MUST be 0 violations
3. test-specialist → MUST be 90%+ coverage
↓
PASS → Accept changes
FAIL → Reject changes + provide fix guidance
```

### Rejection Examples

**Example 1: PHPStan Failure**
```
❌ CANNOT COMMIT - PHPStan Failed

Error: app/Domain/Cookie/ValueObjects/CookiePrice.php:42
- preg_replace() might return null
- Fix: Add null check or use assertion

Specialist: phpstan-specialist
Fix Required: Yes
Guidance: Add `assert($result !== null)` after preg_replace
```

**Example 2: Test Coverage Drop**
```
❌ CANNOT COMMIT - Coverage Below 90%

Current Coverage: 87%
Minimum Required: 90%

Missing Tests:
- CookieCategory::fromString() validation
- Cookie::update() with category change

Specialist: test-specialist
Fix Required: Yes
Guidance: Add 2 unit tests for new category property
```

---

## Autonomous Behavior

### What is Autonomous Behavior?

Claude Code operates **without asking for permission** for routine tasks, quality fixes, and specialist invocations.

**Defined in:** `.claude/CLAUDE.md` → "Autonomous Behavior" section

### Do NOT Ask Permission For:

✅ **Reading Files**
- Use `Read` tool for any file exploration
- Use `Glob` tool for pattern matching
- Use `Grep` tool for code search

✅ **Editing Files**
- Use `Edit` tool for modifications
- Use `Write` tool for new files

✅ **Invoking Specialists**
- See PHP file → Invoke `php-specialist` automatically
- See long method → Invoke `clean-code-specialist` automatically
- See missing tests → Invoke `test-specialist` and create them

✅ **Quality Fixes**
- Run `phpcbf` to auto-fix Slevomat violations
- Add missing type hints
- Extract long methods
- Add missing tests

✅ **Parallel Execution**
- Execute multiple specialists simultaneously
- Run quality checks in parallel

### Do Ask Permission For:

❌ **Destructive Operations**
- Deleting domains
- Dropping database tables
- Force-pushing to git

❌ **Architectural Changes**
- Changing CQRS patterns
- Modifying auto-discovery system
- Changing quality requirements (90% coverage, PHPStan Level 8)

### Proactive Behaviors

**Scenario 1: See PHP File**
```
User: "Show me the Cookie entity"
Claude: [Uses Read tool automatically]
Claude: [Invokes php-specialist automatically]
Claude: "Here's the Cookie entity. The php-specialist confirms it follows
         PHP 8.3+ best practices with strict types, readonly properties,
         and full type coverage."
```

**Scenario 2: See Method Exceeding 20 Lines**
```
Claude: [Reads CookieController.php]
Claude: [Invokes clean-code-specialist automatically]
Clean Code Specialist: "⚠️ CookieController::store() is 35 lines (max 20)"
Claude: [Automatically extracts to private methods]
Claude: "I've refactored CookieController::store() to comply with the
         20-line limit by extracting validation and event dispatching
         to private methods."
```

**Scenario 3: See Missing Tests**
```
Claude: [Creates new CookieCategory value object]
Claude: [Invokes test-specialist automatically]
Test Specialist: "❌ No tests found for CookieCategory"
Claude: [Automatically creates CookieCategoryTest.php]
Claude: "Created CookieCategory value object with 4 comprehensive tests:
         - Valid category creation
         - Invalid category rejection
         - Lowercase normalization
         - Immutability"
```

---

## Claude Code Best Practices Applied

This section documents which Claude Code best practices were researched and applied.

### Research Sources

1. **Claude Code Documentation Map**
   - URL: `https://docs.claude.com/en/docs/claude-code/claude_code_docs_map.md`
   - Used WebFetch to read comprehensive documentation

2. **Agent Creation Best Practices**
   - YAML frontmatter with name, description, tools
   - Concise descriptions (max 200 lines)
   - "Use PROACTIVELY" keyword for autonomous invocation
   - Minimal tool grants (principle of least privilege)

3. **Skill Creation Best Practices**
   - SKILL.md with YAML frontmatter
   - Supporting files in same directory
   - Multi-step workflows
   - Examples and usage documentation

4. **Command Creation Best Practices**
   - Markdown format
   - Clear syntax and arguments
   - Interactive prompts when needed
   - Delegation to skills or agents

5. **Project Memory (CLAUDE.md)**
   - Auto-loads on Claude Code startup
   - Establishes context and rules
   - Provides quick reference
   - Lists available agents/skills/commands

6. **Global Instructions (instructions.md)**
   - Enforces orchestrator pattern
   - Mandatory specialist usage
   - Pre/during/post execution rules
   - Rejection policy

### Patterns Applied

#### 1. Agent YAML Frontmatter
```yaml
---
name: php-specialist
description: Use PROACTIVELY when reviewing or writing PHP code. Enforces PHP 8.3+ features, strict types, readonly properties.
tools: Read, Edit, Bash
---
```

**Why:**
- Claude Code parses frontmatter for metadata
- `name` enables invocation via `@php-specialist` or programmatically
- `description` with "PROACTIVELY" triggers automatic usage
- `tools` restricts permissions (security)

**Reference:** Claude Code Agent Documentation

---

#### 2. Skill Directory Structure
```
.claude/skills/domain-scaffolding/
├── SKILL.md              # Main skill definition
├── templates/            # Template files
└── examples/             # Usage examples
```

**Why:**
- Isolates skill in single directory
- SKILL.md contains workflow definition
- Supporting files keep skill self-contained
- Easy to version and share

**Reference:** Claude Code Skill Documentation

---

#### 3. Orchestrator Pattern in instructions.md
```markdown
## Delegation Rules by Task Type
| Task | Specialists to Invoke |
|------|----------------------|
| Add new property | ddd-specialist + test-specialist + clean-code-specialist |
```

**Why:**
- Global instructions apply to ALL Claude Code sessions
- Ensures consistency across different contexts
- Enforces parallel delegation automatically
- Documents expected behavior

**Reference:** Claude Code Global Instructions

---

#### 4. Settings for Team Permissions
```json
{
  "permissions": {
    "allow": [
      "Bash(composer:*)",
      "Read(*)"
    ],
    "deny": [
      "Bash(rm -rf:*)"
    ]
  }
}
```

**Why:**
- Shared settings across team
- Prevents destructive operations
- Allows safe automation
- Security by default

**Reference:** Claude Code Settings Documentation

---

#### 5. Documentation in .claude/documentation/
```
.claude/documentation/
├── COMPLETE_FILE_INVENTORY.md
├── DOMAIN_CREATION_PROTOCOL.md
└── ...
```

**Why:**
- Claude Code looks in .claude/ for project-specific docs
- Isolates AI documentation from human documentation
- Auto-discovery by Claude Code
- Consistent location across projects

**Reference:** Claude Code Documentation Best Practices

---

## Workflow Examples

### Example 1: Creating a New Domain

**User Request:**
```
/add-domain Order
```

**Execution Flow:**

1. **Command Invocation**
   - `.claude/commands/add-domain.md` is triggered
   - Validates domain name ("Order")
   - Delegates to `domain-scaffolding` skill

2. **Skill Execution**
   - `.claude/skills/domain-scaffolding/SKILL.md` begins execution
   - Creates directory: `app/Domain/Order/`

3. **Parallel Specialist Invocation (Step 2)**
   - `ddd-specialist` → Creates OrderName, OrderTotal value objects
   - `clean-code-specialist` → Validates naming, method lengths
   - `test-specialist` → Creates OrderNameTest, OrderTotalTest

4. **Sequential Steps (Steps 3-14)**
   - Step 3: Create Order entity
   - Step 4: Create CreateOrder command + handler
   - Step 5: Create UpdateOrder command + handler
   - Step 6: Create DeleteOrder command + handler
   - Step 7: Create GetOrderById query + handler
   - Step 8: Create GetAllOrders query + handler
   - Step 9: Create GetOrdersPaginated query + handler
   - Step 10: Create OrderCreated, OrderUpdated, OrderDeleted events
   - Step 11: Create OrderRepository
   - Step 12: Create OrderModel
   - Step 13: Create OrderServiceProvider (auto-discovery)
   - Step 14: Create Migration

5. **Presentation Layer (Steps 15-17)**
   - `codeigniter4-specialist` → Creates OrderController
   - Creates Views (index, show, create, edit)
   - Updates Routes

6. **Testing (Step 18)**
   - `test-specialist` → Creates 14 test files
   - Unit tests (value objects, entities, handlers)
   - Integration tests (repository)
   - Feature tests (controller)

7. **Final Validation (Step 19)**
   - `phpstan-specialist` → PHPStan Level 8: ✅ 0 errors
   - `slevomat-specialist` → Slevomat: ✅ 0 violations
   - `test-specialist` → Coverage: ✅ 92%

8. **Report**
```
✅ Order Domain Created Successfully

Files Created: 45
- Domain: 22 files
- Infrastructure: 2 files
- Application: 1 file
- Presentation: 4 files
- Database: 1 file
- Tests: 14 files
- Configuration: 1 file

Quality Metrics:
- PHPStan Level 8: ✅ 0 errors
- Slevomat: ✅ 0 violations
- Test Coverage: ✅ 92%

Time: 2 minutes 34 seconds

Next Steps:
- Run migration: php spark migrate --all
- Seed data: php spark db:seed OrderSeeder
- Visit: http://localhost:8080/orders
```

**Specialists Involved:**
- `ddd-specialist` (value objects, entities)
- `cqrs-specialist` (commands, queries, events, handlers)
- `clean-code-specialist` (all classes)
- `codeigniter4-specialist` (controller, migration, routes)
- `test-specialist` (all tests)
- `phpstan-specialist` (final validation)
- `slevomat-specialist` (final validation)

**Time:** 2-3 minutes
**Manual Time:** 30-60 minutes

---

### Example 2: Adding a Property

**User Request:**
```
/add-property Cookie category string
```

**Execution Flow:**

1. **Command Invocation**
   - `.claude/commands/add-property.md` is triggered
   - Parses: Domain=Cookie, property=category, type=string
   - Delegates to `property-addition` skill

2. **Decision Phase**
   - `ddd-specialist` → "Does category need validation?"
   - User prompt: "Should category be validated? (chocolate, fruit, nut, seasonal)"
   - User: "Yes, restrict to those 4 values"
   - Decision: CREATE VALUE OBJECT

3. **Value Object Creation**
   - `ddd-specialist` → Creates CookieCategory.php
   - Readonly class with validation in constructor
   - Named factory: `fromString()`
   - Creates CookieCategoryTest.php (4 tests)

4. **Parallel Layer Updates (Steps 3-8)**

   **Domain Layer:**
   - Updates `Cookie` entity:
     - Add property: `private ?CookieCategory $category`
     - Update constructor
     - Update `create()` factory
     - Update `reconstitute()` factory
     - Update `update()` method
     - Add getter: `getCategory()`

   **Infrastructure Layer:**
   - Updates `CookieModel`:
     - Add to `$allowedFields`
     - Add validation rule
   - Updates `CookieRepository`:
     - Update `save()` method
     - Update `toDomainEntity()` method

   **Application Layer:**
   - Updates `CreateCookieCommand` (add property)
   - Updates `CreateCookieHandler` (use property)
   - Updates `UpdateCookieCommand` (add property)
   - Updates `UpdateCookieHandler` (use property)

   **Presentation Layer:**
   - Updates `CookieController`:
     - `store()` method
     - `update()` method
   - Updates Views:
     - `create.php` (add dropdown)
     - `edit.php` (add dropdown with selected value)
     - `show.php` (display category)
     - `index.php` (add table column)

5. **Database Migration**
   - `codeigniter4-specialist` → Creates migration
   - Runs migration: `php spark migrate`

6. **Test Updates (Steps 9-10)**
   - `test-specialist` → Updates/creates tests:
     - `CookieCategoryTest.php` (NEW - 4 tests)
     - `CookieTest.php` (update 6 tests)
     - `CreateCookieHandlerTest.php` (update 2 tests)
     - `UpdateCookieHandlerTest.php` (update 2 tests)
     - `CookieRepositoryTest.php` (update 4 tests)
     - `CookieCrudTest.php` (update 6 tests)
   - Updates `CookieFactory.php` (support category override)

7. **Final Validation**
   - `phpstan-specialist` → PHPStan Level 8: ✅ 0 errors
   - `slevomat-specialist` → Slevomat: ✅ 0 violations
   - `test-specialist` → Coverage: ✅ 94% (maintained)

8. **Report**
```
✅ Property Added Successfully: Cookie::category

Type: Value Object (CookieCategory)
Validation: Restricted to [chocolate, fruit, nut, seasonal]

Files Modified: 22
- Domain: 3 files (CookieCategory, Cookie, tests)
- Infrastructure: 2 files (CookieModel, CookieRepository)
- Application: 4 files (commands, handlers)
- Presentation: 5 files (controller, 4 views)
- Database: 1 file (migration)
- Tests: 7 files (new + updated)

Quality Metrics:
- PHPStan Level 8: ✅ 0 errors
- Slevomat: ✅ 0 violations
- Test Coverage: ✅ 94% (maintained)

Time: 1 minute 47 seconds
```

**Specialists Involved:**
- `ddd-specialist` (value object decision, entity updates)
- `clean-code-specialist` (all modifications)
- `codeigniter4-specialist` (migration, controller, views)
- `test-specialist` (test creation/updates)
- `phpstan-specialist` (final validation)
- `slevomat-specialist` (final validation)

**Time:** 1-2 minutes
**Manual Time:** 20-30 minutes

---

### Example 3: Code Review

**User Request:**
```
/review-domain Cookie
```

**Execution Flow:**

1. **Command Invocation**
   - `.claude/commands/review-domain.md` is triggered
   - Validates domain exists
   - Delegates to `code-review` skill

2. **Analysis Phase**
   - Gather current quality metrics:
     ```bash
     vendor/bin/phpstan analyse app/Domain/Cookie
     vendor/bin/phpcs app/Domain/Cookie
     vendor/bin/phpunit tests/Unit/Domain/Cookie --coverage-text
     ```
   - Baseline established

3. **Parallel Review Phase (All 8 Specialists Simultaneously)**

   **php-specialist:**
   - ✅ All files have `declare(strict_types=1)`
   - ✅ Full type hints on all parameters/returns
   - ✅ No `mixed` types
   - ✅ Use `===` for comparisons

   **phpstan-specialist:**
   - ❌ 1 error: `CookieRepository::toDomainEntity()` missing array shape
   - Recommendation: Add `@param array{id: int, name: string, ...} $data`

   **clean-code-specialist:**
   - ⚠️ `CookieController::store()` is 35 lines (max 20)
   - ✅ No duplicate code
   - ✅ Early returns used
   - Recommendation: Extract validation and event dispatching

   **cqrs-specialist:**
   - ✅ Commands/queries/events are readonly
   - ✅ One handler per command
   - ⚠️ `CreateCookieHandler` is complex (15 lines of logic)
   - Recommendation: Consider splitting validation to separate service

   **ddd-specialist:**
   - ✅ All value objects immutable
   - ✅ Entities use factory methods
   - ✅ Business logic properly placed

   **test-specialist:**
   - ✅ 192 tests passing
   - ✅ Coverage: 93%
   - ✅ Test pyramid maintained (70/20/10)

   **slevomat-specialist:**
   - ❌ 2 violations:
     - `CookiePrice.php:42` unused variable
     - `Cookie.php:15` missing declare strict

   **codeigniter4-specialist:**
   - ✅ Controller follows CI4 patterns
   - ✅ Migration best practices
   - ✅ Routes properly defined

4. **Consolidation Phase**
   - Organize findings by severity:
     - **Critical (MUST FIX):** 0
     - **High Priority:** 3 (PHPStan, Slevomat violations)
     - **Medium Priority:** 2 (method length, handler complexity)
     - **Low Priority:** 0

5. **Auto-Fix Phase**
   - Slevomat violations: Run `phpcbf`
   - Result: 2 violations fixed automatically

6. **Manual Fix Guidance Phase**
   - **High Priority Remaining:**
     - PHPStan: Add array shape annotation
     ```php
     /**
      * @param array{id: int, name: string, description: string|null, price: float, stock: int, is_active: int, created_at: string, updated_at: string, deleted_at: string|null} $data
      */
     private function toDomainEntity(array $data): Cookie
     ```

   - **Medium Priority:**
     - CookieController::store() refactoring:
     ```php
     public function store(): RedirectResponse
     {
         $command = $this->buildCreateCommand();
         $errors = $this->validateCommand($command);

         if ($errors) {
             return $this->redirectWithErrors($errors);
         }

         $cookieId = $this->commandBus->dispatch($command);

         return $this->redirectWithSuccess($cookieId);
     }
     ```

7. **Validation Phase**
   - Apply fixes
   - Re-run quality checks:
     - PHPStan: ✅ 0 errors
     - Slevomat: ✅ 0 violations
     - Tests: ✅ 192 passing, 93% coverage

8. **Report Phase**
```
# Code Review Summary: Cookie Domain

## Scope
- Files Reviewed: 35
- Specialists Involved: 8
- Review Time: 2 minutes

## Findings by Severity

### Critical (MUST FIX) - 0
✅ No critical issues found

### High Priority - 3 → Fixed
- ✅ CookieRepository.php:78 - Missing array shape annotation (fixed)
- ✅ CookiePrice.php:42 - Unused variable (auto-fixed)
- ✅ Cookie.php:15 - Missing declare strict (auto-fixed)

### Medium Priority - 2
- [ ] CookieController.php:25 - Method too long (35 lines)
      Recommendation: Extract validation and event dispatching
- [ ] CreateCookieHandler.php:18 - Complex logic (15 lines)
      Recommendation: Consider validation service

### Low Priority - 0

## Quality Metrics

### Before Review
- PHPStan Errors: 1
- Slevomat Violations: 2
- Test Coverage: 93%

### After Fixes
- PHPStan Errors: 0 ✅
- Slevomat Violations: 0 ✅
- Test Coverage: 93% ✅

## Specialist Recommendations

### php-specialist
✅ All PHP 8.3+ best practices followed

### phpstan-specialist
✅ Level 8 compliance achieved (after array shape fix)

### clean-code-specialist
⚠️ 2 methods exceed 20 lines (not blocking)
✅ No duplicate code
✅ Early returns used

### cqrs-specialist
✅ Commands/queries/events readonly
✅ One handler per command
⚠️ Consider splitting complex handler (not blocking)

### ddd-specialist
✅ All value objects immutable
✅ Entities use factory methods
✅ Business logic properly placed

### test-specialist
✅ 192 tests passing
✅ 93% coverage (above 90% minimum)
✅ Test pyramid maintained

### slevomat-specialist
✅ 0 violations (after auto-fix)

### codeigniter4-specialist
✅ All CI4 best practices followed

## Action Items (Optional - Not Blocking)
- [ ] Refactor CookieController::store() to comply with 20-line limit
- [ ] Consider extracting validation to CookieValidationService

## Approval Status
✅ APPROVED

All critical and high-priority issues resolved.
Medium-priority items are recommendations for future improvement.
Cookie domain meets all quality standards for production.
```

**Specialists Involved:** All 8 specialists in parallel

**Time:** 2 minutes
**Manual Time:** 20-30 minutes (per specialist, sequentially)

---

## References

### Claude Code Documentation

1. **Documentation Map**
   - URL: `https://docs.claude.com/en/docs/claude-code/claude_code_docs_map.md`
   - Comprehensive index of all Claude Code documentation

2. **Agent Creation**
   - YAML frontmatter structure
   - Description keywords ("PROACTIVELY", "MUST BE USED")
   - Tool restrictions
   - Max 200 lines guideline

3. **Skill Creation**
   - SKILL.md structure
   - Supporting files organization
   - Multi-step workflows
   - Example patterns

4. **Command Creation**
   - Markdown format
   - Argument parsing
   - Interactive prompts
   - Delegation patterns

5. **Project Memory (CLAUDE.md)**
   - Auto-loads on startup
   - Context establishment
   - Quick reference
   - Agent/skill/command registry

6. **Global Instructions (instructions.md)**
   - Orchestrator pattern
   - Mandatory rules
   - Rejection policy

### Internal Documentation

All located in `.claude/documentation/`:

- **COMPLETE_FILE_INVENTORY.md** - 45+ files/touchpoints per domain
- **DOMAIN_CREATION_PROTOCOL.md** - 15-step creation process
- **PROPERTY_ADDITION_PROTOCOL.md** - 20+ file modification checklist
- **BUSINESS_RULE_PROTOCOL.md** - Rule placement decision tree
- **TESTING_GUIDELINES.md** - Test pyramid, coverage, examples
- **ARCHITECTURE_DECISIONS.md** - ADR-style key decisions
- **TEMPLATE_MODIFICATION_PROTOCOL.md** - What to update when templates change
- **AI_OPTIMIZATION_GUIDE.md** - This file

### Root Documentation

- **README.md** - Project overview, AI agent system introduction
- **ADDING_DOMAINS.md** - Manual domain creation guide (with automated option)
- **MODIFYING_ENTITIES.md** - Manual property addition guide (with automated option)
- **SETUP.md** - Initial project setup
- **TESTING.md** - Testing guidelines
- **XDEBUG_SETUP.md** - Xdebug configuration

---

## Conclusion

This CodeIgniter 4 CQRS Template is fully optimized for AI agents and Claude Code through:

1. **9 Specialized Agents** - Focused validation and guidance
2. **Orchestrator Pattern** - Automatic parallel delegation
3. **4 Automation Skills** - Complex multi-step workflows
4. **6 User Commands** - Quick access to common tasks
5. **8 Protocol Documents** - Comprehensive step-by-step guides
6. **Cookie Domain Template** - AI-readable reference implementation
7. **Quality Enforcement** - Automatic rejection of non-compliant code
8. **Autonomous Behavior** - Proactive quality fixes without asking

**Result:**
- 45+ file/touchpoint domain creation: **2-3 minutes** (vs 30+ minutes manual)
- 20+ file property addition: **1-2 minutes** (vs 20+ minutes manual)
- Comprehensive code review: **2 minutes** (vs 20-30 minutes manual per specialist)
- **Zero quality compromises** - PHPStan Level 8, Slevomat compliance, 90%+ test coverage

**For Teams:**
- Consistent code quality across all developers
- Reduced onboarding time (AI teaches patterns)
- Faster feature development
- Fewer bugs in production (quality gates)

**For AI Agents:**
- Clear patterns to follow (Cookie domain)
- Comprehensive documentation
- Autonomous decision-making (when to invoke specialists)
- Parallel execution for speed

---

**This project demonstrates best practices for AI-optimized software development using Claude Code.**
