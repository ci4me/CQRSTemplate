# Global AI Agent Instructions - Orchestrator Pattern

## Automatic Specialist Delegation

Claude Code **MUST** automatically use specialized subagents for all code operations. This is a **MANDATORY** requirement, not optional.

---

## Pre-Execution Validation

**BEFORE making ANY code changes**, you MUST:

1. **Invoke `phpstan-specialist`** to check current codebase state
2. **Invoke `slevomat-specialist`** to verify coding standards
3. **Invoke `test-specialist`** if modifying existing features

**If any specialist reports violations, STOP and fix violations before proceeding.**

---

## Delegation Rules by Task Type

### Reading/Analyzing Code

**PHP files** → Invoke `php-specialist`
**Domain layer** → Invoke `cqrs-specialist` + `ddd-specialist` in parallel
**Test files** → Invoke `test-specialist`
**Migrations** → Invoke `codeigniter4-specialist`

### Writing/Modifying Code

**ALWAYS use 2-3 specialists in PARALLEL** for all code changes:

**Examples:**

| Task | Specialists to Invoke |
|------|----------------------|
| Add new property to entity | `ddd-specialist` + `clean-code-specialist` + `test-specialist` |
| Create new command/handler | `cqrs-specialist` + `clean-code-specialist` + `test-specialist` |
| Refactor method | `clean-code-specialist` + `php-specialist` + `test-specialist` |
| Add value object | `ddd-specialist` + `php-specialist` + `test-specialist` |
| Create new domain | `cqrs-specialist` + `ddd-specialist` + `test-specialist` |
| Fix bug | `test-specialist` + `php-specialist` + `phpstan-specialist` |
| Create migration | `codeigniter4-specialist` + `php-specialist` |
| Update controller | `cqrs-specialist` + `clean-code-specialist` + `codeigniter4-specialist` |

### Post-Execution Validation

**AFTER making changes**, you MUST invoke in sequence:

1. **`phpstan-specialist`** → MUST pass Level 8 with **0 errors**
2. **`slevomat-specialist`** → MUST pass with **0 violations**
3. **`test-specialist`** → MUST maintain **90% coverage**

**If ANY specialist fails, changes MUST BE REJECTED and rolled back.**

---

## Rejection Policy

**REJECT and ROLL BACK** any code changes that:

- ❌ Fail PHPStan Level 8 (any errors)
- ❌ Fail Slevomat checks (any violations)
- ❌ Reduce test coverage below 90%
- ❌ Have methods exceeding 20 lines
- ❌ Violate CQRS/DDD patterns
- ❌ Lack tests for new features
- ❌ Use loose comparison (`==` instead of `===`)
- ❌ Missing type hints on parameters or returns
- ❌ Use `else` after `return` statement

---

## Complex Task Handling

For tasks requiring multiple steps:

1. **Break into subtasks** - Decompose complex operations
2. **Assign each subtask to appropriate specialist(s)** - Match expertise to task
3. **Execute specialists in PARALLEL when possible** - Maximize efficiency
4. **Validate with quality specialists before completion** - Ensure standards met

**Example Complex Task: "Add discount property to Cookie entity"**

**Subtasks:**
1. Add property to entity → `ddd-specialist`
2. Create value object for discount → `ddd-specialist` + `php-specialist`
3. Update command/handler → `cqrs-specialist` + `clean-code-specialist`
4. Update repository → `php-specialist`
5. Create migration → `codeigniter4-specialist`
6. Update views → `codeigniter4-specialist`
7. Create tests → `test-specialist`
8. Validate quality → `phpstan-specialist` + `slevomat-specialist`

**Parallel Execution Groups:**
- Group 1 (parallel): `ddd-specialist` + `php-specialist` (value object)
- Group 2 (parallel): `cqrs-specialist` + `clean-code-specialist` (handler)
- Group 3 (sequential): `test-specialist` (create tests)
- Group 4 (parallel): `phpstan-specialist` + `slevomat-specialist` (validate)

---

## Specialist Collaboration Patterns

### Pattern 1: Sequential Chaining

Use when output of one specialist informs another:

1. `test-specialist` → Identify missing tests
2. `cqrs-specialist` → Determine which handlers need tests
3. `test-specialist` → Create specific unit tests

### Pattern 2: Parallel Validation

Use when specialists validate different aspects simultaneously:

```
┌─ phpstan-specialist (type safety)
├─ slevomat-specialist (coding standards)  → Execute in parallel
└─ test-specialist (coverage)
```

### Pattern 3: Staged Review

Use for comprehensive code review:

**Stage 1 - Structure:**
- `cqrs-specialist` → Verify CQRS patterns
- `ddd-specialist` → Verify DDD patterns

**Stage 2 - Quality:**
- `clean-code-specialist` → Check method length, DRY
- `php-specialist` → Check PHP 8.3+ features

**Stage 3 - Compliance:**
- `phpstan-specialist` → Type safety
- `slevomat-specialist` → Coding standards
- `test-specialist` → Test coverage

---

## Status Reporting

After using specialists, **ALWAYS report:**

1. **Which specialists were invoked** - List all used
2. **What they validated/fixed** - Summary of actions
3. **Final quality check results** - PHPStan ✅ / Slevomat ✅ / Tests ✅
4. **Any remaining violations** - What still needs fixing

**Example Report:**
```
✅ Specialists Invoked:
- ddd-specialist: Reviewed value object pattern
- php-specialist: Verified PHP 8.3+ features and type safety
- test-specialist: Created 3 unit tests

✅ Quality Validation:
- PHPStan Level 8: ✅ 0 errors
- Slevomat: ✅ 0 violations
- Test Coverage: ✅ 92% (above 90% minimum)

✅ Ready for commit
```

---

## Proactive Specialist Usage

Specialists marked with **"Use PROACTIVELY"** in their descriptions MUST be invoked automatically without explicit user request:

- **`phpstan-specialist`** → Before every commit
- **`slevomat-specialist`** → Before every commit
- **`test-specialist`** → When modifying features
- **`php-specialist`** → For all PHP files
- **`clean-code-specialist`** → For all methods/classes

---

## Emergency Override

**Only in EXCEPTIONAL cases** where automated validation is impossible:

1. Document WHY automated validation cannot work
2. Get explicit user confirmation
3. Add TODO comments for future fixes
4. Create tracking issue

**This should be EXTREMELY RARE (<1% of cases).**

---

## Continuous Improvement

When specialists identify recurring violations:

1. **Update .claude/CLAUDE.md** with new patterns to avoid
2. **Create new examples** in specialist files
3. **Suggest new slash commands** for common fixes
4. **Report patterns to user** for documentation updates

---

## Default Behavior Summary

**Every code modification MUST follow this workflow:**

```
1. Pre-check → Invoke relevant specialists to assess current state
2. Parallel execution → Use 2-3 specialists for actual code changes
3. Post-validation → Run phpstan + slevomat + test specialists
4. Report → Summarize specialist findings
5. Commit/Reject → Only commit if ALL specialists pass
```

**This is NOT optional. This is the REQUIRED workflow for all code operations.**

---

## Integration with Skills and Commands

### When to Use Skills vs. Specialists

**Skills** → Complex multi-step workflows (domain scaffolding, property addition)
**Specialists** → Code review, validation, pattern enforcement

**Skills SHOULD invoke specialists internally** for validation at each step.

### When to Use Commands vs. Specialists

**Commands** → User-initiated quick tasks (/add-domain, /review-domain)
**Specialists** → Automatic delegation based on code context

**Commands SHOULD invoke specialists** for the actual work.

---

**This orchestrator pattern ensures ZERO non-compliant code reaches the repository.**
