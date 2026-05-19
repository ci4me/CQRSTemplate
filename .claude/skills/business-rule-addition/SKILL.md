---
name: business-rule-addition
description: Adds a new business rule/validation rule/business constraint/invariant/domain logic to a domain, determining correct placement (value object, entity, or handler) and creating comprehensive tests. Use when user requests to add business logic, validation rules, constraints, or invariants. Provides placement guidance.
allowed-tools: [Read, Write, Edit, Glob, Grep, Bash, Task]
---

# Business Rule Addition Skill

Guides adding business rules with correct placement and testing.

---

## Step 1: Understand the Rule

Ask user:
1. What is the business rule? (describe in plain language)
2. When should this rule be enforced?
3. What data does the rule need to evaluate?
4. What should happen if rule is violated?

---

## Step 2: Determine Correct Placement

**Use decision tree:** See `patterns/placement-decision-tree.md`

**Quick reference:**
- **Single value validity?** → Value Object
- **Single entity consistency?** → Entity Method
- **Multiple entities or cross-aggregate?** → Handler (Domain Service)

**Invoke `ddd-specialist` for placement guidance.**

---

## Step 3: Implement in Value Object (If Applicable)

**Pattern:**
```php
private function __construct(private type $value)
{
    // Validate rule
    if (/* violation */) {
        throw ValidationException::ruleViolated(/* details */);
    }
}
```

**Examples:**
- Price must be >= $0.01
- Name length 3-100 characters
- Email must match format
- Date must be in future

**Invoke:** `ddd-specialist` + `php-specialist`

---

## Step 4: Implement in Entity (If Applicable)

**Pattern:**
```php
public function businessOperation(params): void
{
    // Check invariant
    if (/* violation */) {
        throw ValidationException::ruleViolated(/* details */);
    }

    // Apply change
    $this->property = newValue;
}
```

**Examples:**
- Cannot decrease stock below zero
- Cannot activate without stock
- Cannot apply discount to discounted item
- Cannot change status in certain states

**Detailed Example:** See `examples/complex-rule-example.md`

**Invoke:** `ddd-specialist` + `clean-code-specialist`

---

## Step 5: Implement in Handler (If Applicable)

**Pattern:**
```php
public function handle(Command $command): ReturnType
{
    // Check cross-aggregate rule
    if (/* violation */) {
        throw ValidationException::ruleViolated(/* details */);
    }

    // Proceed with operation
}
```

**Examples:**
- Cannot create duplicate names
- Cannot exceed system limits
- Cannot violate referential integrity
- Cannot conflict with existing records

**Invoke:** `cqrs-specialist` + `clean-code-specialist`

---

## Step 6: Create Comprehensive Tests

**For EACH rule, create tests:**
- [ ] Happy path (rule passes)
- [ ] Each violation condition
- [ ] Boundary values (min, max, zero, negative)
- [ ] Edge cases (null, empty, extreme values)

**Test naming:**
```php
public function test_throws_exception_when_{specific_violation}(): void
{
    $this->expectException(ValidationException::class);
    // Code that violates rule
}
```

**Complete test examples:** See `examples/complex-rule-example.md`

**Invoke:** `test-specialist` for comprehensive coverage

---

## Step 7: Document the Rule

Add AI-optimized docblock:

```php
/**
 * {Action description}.
 *
 * @ai-context Business Rule: {Rule name}
 *             Enforces: {What constraint}
 *             Reason: {Why rule exists}
 *
 * @ai-pattern {Pattern name}
 *
 * @ai-example
 * ```php
 * ${entity}->{method}({params});
 * // Throws if {violation condition}
 * ```
 *
 * @param {Type} ${param} {Description}
 *
 * @return {Type}
 *
 * @throws ValidationException If {violation description}
 *
 * @debugPoint Inspect {what to check} during debugging
 */
```

---

## Step 8: Validate Implementation

Run checks:
```bash
vendor/bin/phpstan analyse --level=8
vendor/bin/phpcs
vendor/bin/phpunit --filter={RuleTest}
```

**Invoke:**
1. `phpstan-specialist` (type safety)
2. `slevomat-specialist` (code style)
3. `test-specialist` (coverage maintained)

---

## Completion Checklist

- [ ] Rule placement determined correctly
- [ ] Rule implemented in appropriate location
- [ ] Exception messages are descriptive
- [ ] All violation paths tested
- [ ] Boundary conditions tested
- [ ] AI-optimized docblock added
- [ ] PHPStan passes
- [ ] Slevomat passes
- [ ] Test coverage maintained at 90%+

---

## Supporting Documentation

**Patterns:**
- `patterns/placement-decision-tree.md` - Complete decision flowchart with examples

**Examples:**
- `examples/complex-rule-example.md` - Entity method example with full test suite

**References:**
- `.claude/documentation/BUSINESS_RULE_PROTOCOL.md` - Complete protocol
- Cookie domain - Reference implementation

---

**Business rules are the CORE of the domain. Implement and test thoroughly.**
