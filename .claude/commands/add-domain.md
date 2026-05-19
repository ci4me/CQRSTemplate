# /add-domain

Creates a complete new domain with all 45 files using the domain-scaffolding skill.

## Usage

```
/add-domain {DomainName}
```

## Arguments

- **DomainName**: PascalCase singular name (e.g., Order, Product, Customer)

## Examples

```
/add-domain Order
/add-domain Product
/add-domain Customer
```

## Implementation

1. Validate domain name (PascalCase, singular, not existing)
2. Invoke `domain-scaffolding` skill with domain name
3. Skill will:
   - Create all 45 files
   - Use specialists for validation (ddd, cqrs, php, test, phpstan, slevomat)
   - Run quality checks
4. Report completion with file count and metrics

## Expected Output

```
✅ Domain Created: Order

Files Created: 45
- 22 domain layer files
- 2 infrastructure files
- 1 controller
- 4 views
- 1 migration
- 14 test files
- 1 routes update

Quality Metrics:
- PHPStan Level 8: ✅ 0 errors
- Slevomat: ✅ 0 violations
- Test Coverage: ✅ 92%

Ready to use!
```

## Quality Gates

- PHPStan Level 8 must pass
- Slevomat must pass
- Tests must achieve 90%+ coverage
- Migration must run successfully

## See Also

- `.claude/skills/domain-scaffolding/SKILL.md`
- `.claude/documentation/DOMAIN_CREATION_PROTOCOL.md`
- Cookie domain (reference implementation)
