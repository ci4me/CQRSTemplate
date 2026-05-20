---
name: code-review
description: Performs comprehensive code review using all specialized agents in parallel, generating detailed violation report with fixes. Use when user requests code review, audit code quality, check quality standards, review changes, find violations, review pull requests, or wants parallel specialist execution.
allowed-tools: [Read, Glob, Grep, Bash, Task]
---

# Code Review Skill

Comprehensive multi-specialist code review workflow.

---

## Step 1: Identify Scope

Ask user:
1. Review entire codebase or specific domain?
2. Focus areas (quality, performance, security, tests)?
3. Priority (critical violations only or all issues)?

**Default:** Review specified domain or changed files.

---

## Step 2: Initial Analysis

Gather context:
```bash
# Find PHP files
Glob: app/Domain/{Domain}/**/*.php

# Check current quality status
vendor/bin/phpstan analyse app/Domain/{Domain}
vendor/bin/phpcs app/Domain/{Domain}
vendor/bin/phpunit tests/Unit/Domain/{Domain} --coverage-text
```

**Create baseline:** Note current errors/violations/coverage.

---

## Step 3: Parallel Specialist Review

**Invoke ALL specialists in PARALLEL:**

### Domain Layer Review
- `ddd-specialist` → Review entities, value objects, aggregates
- `cqrs-specialist` → Review commands, queries, events, handlers
- `php-specialist` → Review PHP 8.4 usage, types, modern features
- `clean-code-specialist` → Review method length, complexity, DRY
- `phpstan-specialist` → Review type safety, annotations
- `test-specialist` → Review test coverage, test quality

### Infrastructure Layer Review
- `codeigniter4-specialist` → Review models, migrations, controllers

### Code Standards Review
- `slevomat-specialist` → Review coding standards compliance

**Each specialist provides:**
- List of violations found
- Severity (critical, high, medium, low)
- Specific file locations
- Recommended fixes

---

## Step 4: Consolidate Findings

Create violation report organized by:
1. **Critical Issues** (breaks, security, data loss)
2. **High Priority** (quality violations, missing tests)
3. **Medium Priority** (style issues, minor optimizations)
4. **Low Priority** (suggestions, nice-to-haves)

**Format:**
```markdown
## Critical Issues (MUST FIX)

### DDD Specialist
- [ ] app/Domain/Cookie/Entities/Cookie.php:45 - Anemic domain model
  - Issue: Public setter bypasses business rules
  - Fix: Change to command method: updatePrice(CookiePrice $newPrice)

### PHPStan Specialist
- [ ] app/Infrastructure/Persistence/Repositories/CookieRepository.php:78 - Type safety violation
  - Issue: Mixed type returned from database
  - Fix: Add array shape annotation

## High Priority (SHOULD FIX)

### Clean Code Specialist
- [ ] app/Controllers/Domain/Cookie/CookieController.php:25 - Method too long
  - Issue: store() method has 35 lines
  - Fix: Extract to private methods

...
```

---

## Step 5: Generate Fix Plan

For each violation:
1. Identify responsible specialist
2. Determine fix complexity (simple, moderate, complex)
3. Estimate impact (low, medium, high)
4. Suggest fix approach

**Prioritize:**
- Critical: Fix immediately
- High: Fix before merge
- Medium: Fix in next sprint
- Low: Tech debt backlog

---

## Step 6: Auto-Fix Where Possible

**Automatically fix:**
```bash
# Auto-fix coding standards
vendor/bin/phpcbf app/Domain/{Domain}

# Re-check
vendor/bin/phpcs app/Domain/{Domain}
```

**Report:** "Auto-fixed X violations, Y remain manual."

---

## Step 7: Manual Fix Guidance

For each manual fix needed:

1. **Show violation:**
   ```php
   // ❌ Current code (violation)
   ```

2. **Show fix:**
   ```php
   // ✅ Corrected code
   ```

3. **Explain why:**
   - What pattern/rule is violated
   - Why it matters
   - What the fix achieves

---

## Step 8: Validation After Fixes

After fixes applied:

```bash
vendor/bin/phpstan analyse --level=8
vendor/bin/phpcs
vendor/bin/phpunit --coverage-text
```

**Quality gates:**
- [ ] PHPStan: 0 errors
- [ ] Slevomat: 0 violations
- [ ] Tests: 90%+ coverage, all passing

---

## Step 9: Generate Summary Report

**Final report includes:**

```markdown
# Code Review Summary

## Scope
- Domain: {Domain}
- Files Reviewed: {count}
- Specialists Involved: {list}

## Findings
- Critical Issues: {count} (ALL FIXED)
- High Priority: {count} ({fixed} fixed, {remaining} remaining)
- Medium Priority: {count}
- Low Priority: {count}

## Quality Metrics

### Before Review
- PHPStan Errors: {count}
- Slevomat Violations: {count}
- Test Coverage: {percentage}%

### After Review
- PHPStan Errors: 0 ✅
- Slevomat Violations: 0 ✅
- Test Coverage: {percentage}% ✅

## Specialist Recommendations

### DDD Specialist
{Key findings and suggestions}

### CQRS Specialist
{Key findings and suggestions}

### Clean Code Specialist
{Key findings and suggestions}

...

## Action Items
- [ ] {Remaining high-priority fix}
- [ ] {Remaining medium-priority fix}

## Approval Status
{APPROVED / CHANGES REQUESTED / BLOCKED}
```

---

## Review Checklist

**Domain Layer:**
- [ ] Value objects are immutable and self-validating
- [ ] Entities use factory methods (create/reconstitute)
- [ ] Business logic in domain, not controllers
- [ ] Ubiquitous language used
- [ ] Invariants protected

**CQRS Layer:**
- [ ] Commands are immutable readonly DTOs
- [ ] Queries are immutable readonly DTOs
- [ ] Events are past tense, immutable
- [ ] One handler per command/query
- [ ] Handlers delegate to entities
- [ ] Events dispatched after state changes

**Code Quality:**
- [ ] All methods <= 20 lines
- [ ] No else after return
- [ ] Early returns used
- [ ] No duplicate code (DRY)
- [ ] Magic numbers extracted to constants

**Type Safety:**
- [ ] declare(strict_types=1) in all files
- [ ] All parameters typed
- [ ] All return types declared
- [ ] Array shapes documented
- [ ] === used for comparisons

**Tests:**
- [ ] All value objects tested
- [ ] All entities tested
- [ ] All handlers tested (with mocks)
- [ ] Repository tested (with database)
- [ ] Controller tested (feature tests)
- [ ] 90%+ coverage maintained

---

## Integration with CI/CD

Add to CI/CD pipeline:
```yaml
code-review:
  script:
    - composer check  # Runs PHPStan + Slevomat + PHPUnit
  allow_failure: false  # Block merge if fails
```

---

**Use this skill for pull request reviews and periodic code quality audits.**
