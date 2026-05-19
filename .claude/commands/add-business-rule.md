# /add-business-rule

Adds a new business rule with correct placement (value object, entity, or handler) and tests.

## Usage

```
/add-business-rule {Domain}
```

## Arguments

- **Domain**: Existing domain name (e.g., Cookie, Order)

## Examples

```
/add-business-rule Cookie
/add-business-rule Order
```

## Implementation

1. Validate domain exists
2. Invoke `business-rule-addition` skill
3. Skill prompts for:
   - Rule description in plain language
   - When rule should be enforced
   - What data rule needs
   - What happens if violated
4. Determines correct placement:
   - Value object (single value validation)
   - Entity method (entity consistency)
   - Handler (cross-aggregate rules)
5. Implements rule with AI-optimized docblock
6. Creates comprehensive tests:
   - Happy path
   - Each violation
   - Boundary conditions
   - Edge cases
7. Validates with specialists

## Expected Output

```
✅ Business Rule Added: Cookie - Cannot apply discount to already discounted cookie

Placement: Entity method (Cookie::applyDiscount)

Rule Details:
- Type: Invariant protection
- Enforcement: Before discount application
- Exception: ValidationException::alreadyDiscounted

Files Modified: 3
- app/Domain/Cookie/Entities/Cookie.php (added method)
- tests/Unit/Domain/Cookie/Entities/CookieTest.php (4 new tests)

Tests Created:
- test_can_apply_valid_discount
- test_throws_exception_when_already_discounted
- test_throws_exception_when_discount_exceeds_price
- test_final_price_meets_minimum

Quality Metrics:
- PHPStan Level 8: ✅ 0 errors
- Slevomat: ✅ 0 violations
- Test Coverage: ✅ 94%

Rule implemented and tested!
```

## Quality Gates

- Rule placement approved by ddd-specialist
- Implementation reviewed by clean-code-specialist
- All violation paths tested
- PHPStan passes
- Slevomat passes
- Coverage maintained

## See Also

- `.claude/skills/business-rule-addition/SKILL.md`
- `.claude/documentation/BUSINESS_RULE_PROTOCOL.md`
