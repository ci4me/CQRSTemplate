# Template Modification Protocol

**What to do when changing the templates/patterns used for creating or modifying domains.**

---

## When Templates Change

When you modify the Cookie domain (the reference template) or change architectural patterns, you MUST update all related documentation and automation.

---

## Files That Reference Templates

### 1. Documentation Files (.claude/documentation/)

**COMPLETE_FILE_INVENTORY.md**
- Update if number of files changes
- Update file structure examples
- Update Cookie domain file tree

**DOMAIN_CREATION_PROTOCOL.md**
- Update code examples if patterns change
- Update step-by-step instructions
- Update file structure

**PROPERTY_ADDITION_PROTOCOL.md**
- Update if entity modification pattern changes
- Update migration examples
- Update view examples

**BUSINESS_RULE_PROTOCOL.md**
- Update if rule placement guidelines change
- Update code examples

**TESTING_GUIDELINES.md**
- Update if test patterns change
- Update test examples
- Update coverage requirements

**ARCHITECTURE_DECISIONS.md**
- Add new ADR if architectural decision changes
- Update status of existing ADRs if reversed

### 2. Agent Definitions (.claude/agents/)

Update agent if their rules change:

**ddd-specialist.md**
- Update value object pattern
- Update entity pattern
- Update examples

**cqrs-specialist.md**
- Update command/query/event patterns
- Update handler patterns
- Update directory structure

**clean-code-specialist.md**
- Update method length limits
- Update complexity rules
- Update examples

**test-specialist.md**
- Update test pyramid ratios
- Update coverage requirements
- Update test examples

### 3. Skills (.claude/skills/)

**domain-scaffolding/SKILL.md**
- Update references to Cookie domain files
- Update step-by-step process
- Update file counts

**property-addition/SKILL.md** (when created)
- Update checklist of files to modify
- Update code examples

### 4. Project Memory (.claude/CLAUDE.md)

**Update sections:**
- Architecture Quick Reference
- Common Commands
- Project Conventions
- Code Quality Rules

### 5. Root Documentation

**README.md**
- Update architecture diagrams
- Update feature list
- Update quick start if process changes

**ADDING_DOMAINS.md**
- Update all code examples
- Update step-by-step instructions
- Update Cookie domain references

**MODIFYING_ENTITIES.md**
- Update checklist
- Update examples
- Update file lists

---

## Modification Process

### Step 1: Identify What Changed

**Pattern Changes:**
- Value object structure changed?
- Entity factory method signature changed?
- Command/Query pattern modified?
- Handler structure changed?
- Repository interface changed?
- Test structure changed?

**File Structure Changes:**
- New files added to standard domain?
- Files removed from standard domain?
- Directory structure reorganized?

**Quality Standard Changes:**
- PHPStan level changed?
- Slevomat rules updated?
- Test coverage requirement changed?
- Method length limit changed?

---

### Step 2: Update Cookie Domain (Reference Implementation)

The Cookie domain is the **SOURCE OF TRUTH** for all templates.

**Before updating documentation, update Cookie domain first:**

1. Modify Cookie domain files to reflect new pattern
2. Run all quality checks:
   ```bash
   vendor/bin/phpstan analyse
   vendor/bin/phpcs
   vendor/bin/phpunit --coverage-text
   ```
3. Ensure ALL checks pass with new pattern
4. Verify pattern is improvement over old one

**Cookie domain MUST always be 100% compliant and serve as working example.**

---

### Step 3: Update Specialists

**For each affected specialist:**

1. Update pattern description
2. Update code examples (❌ bad → ✅ good)
3. Update validation rules
4. Update common violations section
5. Test specialist with new pattern

**Use `claude-code-specialist` to review agent modifications.**

---

### Step 4: Update Documentation

**Update in this order:**

1. **ARCHITECTURE_DECISIONS.md**
   - Add new ADR if architectural decision made
   - Document rationale for change

2. **COMPLETE_FILE_INVENTORY.md**
   - Update file count if changed
   - Update Cookie domain file tree
   - Update file structure examples

3. **Protocol Documents**
   - DOMAIN_CREATION_PROTOCOL.md
   - PROPERTY_ADDITION_PROTOCOL.md
   - BUSINESS_RULE_PROTOCOL.md
   - TESTING_GUIDELINES.md
   - Update step-by-step instructions
   - Update all code examples
   - Update references to Cookie domain

4. **.claude/CLAUDE.md**
   - Update conventions
   - Update code quality rules
   - Update quick reference

5. **Root Documentation**
   - README.md
   - ADDING_DOMAINS.md
   - MODIFYING_ENTITIES.md

---

### Step 5: Update Skills

**For each skill that uses templates:**

1. **domain-scaffolding**
   - Update references to Cookie domain
   - Update step descriptions
   - Update file counts
   - Update validation requirements

2. **property-addition**
   - Update checklist
   - Update code examples

3. **business-rule-addition**
   - Update placement guidelines
   - Update examples

4. **code-review**
   - Update review checklist
   - Update violation patterns

---

### Step 6: Update Commands

**For each command that generates code:**

1. Update generation templates
2. Update examples
3. Update validation steps

---

### Step 7: Validation

**After all updates, verify consistency:**

```bash
# Check all .md files reference same pattern
grep -r "old-pattern" .claude/
grep -r "old-pattern" *.md

# Verify Cookie domain compliance
vendor/bin/phpstan analyse app/Domain/Cookie
vendor/bin/phpcs app/Domain/Cookie
vendor/bin/phpunit tests/Unit/Domain/Cookie tests/Integration/Repositories/CookieRepositoryTest.php tests/Feature/Cookie

# Test domain creation with new pattern
/add-domain TestDomain
# Then delete TestDomain and verify it followed new pattern
```

---

## Examples of Template Changes

### Example 1: Changing Value Object Pattern

**What Changed:**
From: `public static function fromString()` and `public static function fromFloat()`
To: Single `public static function from(string|float $value)`

**Files to Update:**

1. **Cookie domain value objects**
   - CookieName.php
   - CookiePrice.php

2. **Specialists**
   - ddd-specialist.md (update value object section)
   - php-specialist.md (update examples)

3. **Documentation**
   - DOMAIN_CREATION_PROTOCOL.md (update Step 2 examples)
   - PROPERTY_ADDITION_PROTOCOL.md (update Step 1 examples)
   - BUSINESS_RULE_PROTOCOL.md (update value object examples)

4. **Skills**
   - domain-scaffolding/SKILL.md (update Step 2)

5. **Root Docs**
   - ADDING_DOMAINS.md (update value object examples)
   - MODIFYING_ENTITIES.md (update value object section)

---

### Example 2: Adding New Standard File

**What Changed:**
Added: `app/Domain/{Domain}/Specifications/{Entity}Specification.php` to all domains

**Files to Update:**

1. **Cookie domain**
   - Create: `app/Domain/Cookie/Specifications/CookieSpecification.php`
   - Create: `tests/Unit/Domain/Cookie/Specifications/CookieSpecificationTest.php`

2. **Documentation**
   - COMPLETE_FILE_INVENTORY.md
     - Update file count (45 → 47)
     - Add Specifications section to file list
     - Update Cookie domain file tree
   - DOMAIN_CREATION_PROTOCOL.md
     - Add Step for creating Specifications
     - Update completion checklist
   - TESTING_GUIDELINES.md
     - Add specification test examples

3. **Skills**
   - domain-scaffolding/SKILL.md
     - Add step for creating Specifications
     - Update file counts
     - Update completion checklist

4. **.claude/CLAUDE.md**
   - Update "Complete File List" count

---

### Example 3: Changing Test Coverage Requirement

**What Changed:**
From: 90% minimum coverage
To: 95% minimum coverage

**Files to Update:**

1. **Specialists**
   - test-specialist.md
     - Update minimum coverage to 95%
     - Update validation commands

2. **Documentation**
   - TESTING_GUIDELINES.md
     - Update "Minimum Coverage" to 95%
     - Update all references to 90%
   - DOMAIN_CREATION_PROTOCOL.md
     - Update Step 15 quality gate to 95%
   - PROPERTY_ADDITION_PROTOCOL.md
     - Update validation section to 95%
   - ARCHITECTURE_DECISIONS.md
     - Update ADR about test coverage

3. **.claude/CLAUDE.md**
   - Update "Rejection Policy" to 95%
   - Update "Test Coverage" section

4. **.claude/instructions.md**
   - Update "Post-Execution Validation" to 95%

---

## Checklist for Template Changes

- [ ] Cookie domain updated with new pattern
- [ ] Cookie domain passes all quality checks
- [ ] New pattern tested and verified as improvement
- [ ] ARCHITECTURE_DECISIONS.md updated with ADR
- [ ] COMPLETE_FILE_INVENTORY.md updated
- [ ] All protocol documents updated
- [ ] All affected specialists updated
- [ ] .claude/CLAUDE.md updated
- [ ] .claude/instructions.md updated (if quality standards changed)
- [ ] All skills updated
- [ ] All commands updated
- [ ] Root documentation updated (README, ADDING_DOMAINS, MODIFYING_ENTITIES)
- [ ] Tested domain creation with new pattern
- [ ] No references to old pattern remain (grep verification)
- [ ] All examples consistent across documentation

---

## Automation

**Use specialists to verify changes:**

1. `grep -r "old-pattern" .claude/ *.md` → Ensure no old references
2. `claude-code-specialist` → Review agent/skill modifications
3. `test-specialist` → Verify test patterns updated
4. `phpstan-specialist` → Verify new pattern passes Level 8

---

**Remember: Cookie domain is the SOURCE OF TRUTH. Update it first, documentation second.**
