# /add-property

Adds a new property to an existing domain entity, updating all 20+ required files.

## Usage

```
/add-property {Domain} {propertyName} {type}
```

## Arguments

- **Domain**: Existing domain name (e.g., Cookie, Order)
- **propertyName**: camelCase property name (e.g., flavor, isGlutenFree)
- **type**: Property type (string, int, bool, float)

## Examples

```
/add-property Cookie flavor string
/add-property Order isPaid bool
/add-property Product weight float
```

## Implementation

1. Validate domain exists
2. Validate property name (camelCase, not existing)
3. Invoke `property-addition` skill
4. Skill will prompt for:
   - Validation rules
   - Default value
   - Whether value object needed
5. Updates all required files:
   - Value object (if needed)
   - Entity
   - Commands & handlers
   - Repository & model
   - Migration
   - Views
   - Tests
6. Runs quality checks

## Expected Output

```
✅ Property Added: Cookie.flavor (string)

Files Modified: 22
- Created CookieFlavor value object
- Updated Cookie entity
- Updated 2 commands
- Updated 2 handlers
- Updated repository
- Updated model
- Created migration
- Updated 4 views
- Created/updated 8 tests

Quality Metrics:
- PHPStan Level 8: ✅ 0 errors
- Slevomat: ✅ 0 violations
- Test Coverage: ✅ 93% (maintained)

Migration run: ✅
Property ready to use!
```

## Quality Gates

- All specialists approve changes
- PHPStan passes
- Slevomat passes
- Coverage maintained at 90%+
- Migration runs successfully

## See Also

- `.claude/skills/property-addition/SKILL.md`
- `.claude/documentation/PROPERTY_ADDITION_PROTOCOL.md`
