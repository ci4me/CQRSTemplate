---
name: property-addition
description: Adds a new property/field/column to an existing domain entity, updating all 20+ required files (value object, entity, commands, handlers, repository, migration, database, views, tests). Use when user requests to add field, new column, entity property, or update existing entity with new attribute.
allowed-tools: [Read, Write, Edit, Glob, Grep, Bash, Task]
---

# Property Addition Skill

Automates adding a property to an existing entity across all layers.

---

## Step 1: Gather Information

Ask user:
1. Domain name (e.g., Cookie)
2. Property name (camelCase, e.g., flavor, isGlutenFree)
3. Property type (string, int, bool, float)
4. Validation rules (min/max, required, format)
5. Default value (if any)

---

## Step 2: Decide Value Object vs. Simple Property

**Create Value Object if:**
- Has validation rules (min/max, format, regex)
- Has business logic
- Represents domain concept

**Use Simple Property if:**
- Simple boolean flag
- No validation beyond type

**Invoke `ddd-specialist` for guidance if unsure.**

---

## Step 3: Create Value Object (If Needed)

**Reference:** `.claude/documentation/PROPERTY_ADDITION_PROTOCOL.md` Step 1

Use `ddd-specialist` + `php-specialist` to create value object following Cookie value object patterns.

---

## Step 4: Update Entity

**Reference:** `.claude/documentation/PROPERTY_ADDITION_PROTOCOL.md` Step 2

Update:
- Constructor
- create() method
- reconstitute() method
- Add getter
- Add setter if mutable

**Invoke:** `ddd-specialist` + `clean-code-specialist`

---

## Step 5: Update Commands and Handlers

**Reference:** `.claude/documentation/PROPERTY_ADDITION_PROTOCOL.md` Step 3

Update:
- CreateCommand (add property)
- CreateHandler (convert to value object, pass to entity)
- UpdateCommand (add property)
- UpdateHandler (convert to value object, pass to entity)

**Invoke:** `cqrs-specialist` + `clean-code-specialist`

---

## Step 6: Update Repository

**Reference:** `.claude/documentation/PROPERTY_ADDITION_PROTOCOL.md` Step 4

Update:
- Model $allowedFields
- Repository save() method
- Repository toDomainEntity() method with proper array shape

**Invoke:** `phpstan-specialist` for array shape validation

---

## Step 7: Create Migration

**Reference:** `.claude/documentation/PROPERTY_ADDITION_PROTOCOL.md` Step 5

```bash
php spark make:migration Add{Property}To{Entities}Table
```

Add column with proper type, constraints, defaults.

Run: `php spark migrate`

**Invoke:** `codeigniter4-specialist`

---

## Step 8: Update Views

**Reference:** `.claude/documentation/PROPERTY_ADDITION_PROTOCOL.md` Step 6

Update:
- create.php (add form field)
- edit.php (add form field with old value)
- show.php (display value)
- index.php (if shown in list)

Use Bootstrap 5 with validation feedback.

---

## Step 9: Create/Update Tests

**Reference:** `.claude/documentation/PROPERTY_ADDITION_PROTOCOL.md` Step 7

Create/update:
- Value object test (if created)
- Entity test (test new property)
- Handler tests (test with new property)
- Repository test (test persistence)
- Feature test (test form submission)
- Test factory (add to defaults)

**Invoke:** `test-specialist` to ensure 90% coverage maintained

---

## Step 10: Validate Quality

Run checks:
```bash
vendor/bin/phpstan analyse --level=8
vendor/bin/phpcs
vendor/bin/phpunit --coverage-text
```

**Invoke in sequence:**
1. `phpstan-specialist` (0 errors required)
2. `slevomat-specialist` (0 violations required)
3. `test-specialist` (90%+ coverage required)

---

## Completion Checklist

Follow checklist in `.claude/documentation/PROPERTY_ADDITION_PROTOCOL.md`

Minimum 20+ files modified:
- [ ] Value object created (if needed)
- [ ] Entity updated
- [ ] Commands updated (2)
- [ ] Handlers updated (2)
- [ ] Repository updated
- [ ] Model updated
- [ ] Migration created and run
- [ ] Views updated (3-4)
- [ ] Tests updated (6-8)
- [ ] Factory updated
- [ ] All quality checks passing

---

**Report completion with file count and quality metrics.**
