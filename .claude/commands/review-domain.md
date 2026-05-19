# /review-domain

Performs comprehensive code review of a domain using all specialists in parallel.

## Usage

```
/review-domain {Domain}
```

## Arguments

- **Domain**: Domain name to review (e.g., Cookie, Order, or "all")

## Examples

```
/review-domain Cookie
/review-domain Order
/review-domain all
```

## Implementation

1. Identify scope (specified domain or all domains)
2. Invoke `code-review` skill
3. Skill executes in phases:
   - **Analysis**: Gather current quality metrics
   - **Parallel Review**: Invoke all 8 specialists simultaneously
   - **Consolidation**: Organize findings by severity
   - **Auto-Fix**: Fix violations automatically where possible
   - **Manual Fix Guidance**: Provide fix recommendations
   - **Validation**: Re-run quality checks
   - **Report**: Generate comprehensive summary

## Expected Output

```
# Code Review Summary: Cookie Domain

## Scope
- Files Reviewed: 35
- Specialists Involved: 8
- Review Time: 2 minutes

## Findings by Severity

### Critical (MUST FIX) - 0
✅ No critical issues found

### High Priority - 2
- [ ] CookieController.php:25 - Method too long (35 lines)
- [ ] CookieRepository.php:78 - Missing array shape annotation

### Medium Priority - 5
- Auto-fixed with phpcbf

### Low Priority - 3
- Listed in tech debt backlog

## Quality Metrics

### Before Review
- PHPStan Errors: 2
- Slevomat Violations: 5
- Test Coverage: 92%

### After Fixes
- PHPStan Errors: 0 ✅
- Slevomat Violations: 0 ✅
- Test Coverage: 93% ✅

## Specialist Recommendations

### DDD Specialist
✅ All value objects immutable
✅ Entities use factory methods
✅ Business logic properly placed

### CQRS Specialist
✅ Commands/queries/events readonly
✅ One handler per command
⚠️ Consider splitting complex handler

### Clean Code Specialist
⚠️ 2 methods exceed 20 lines
✅ No duplicate code
✅ Early returns used

### PHPStan Specialist
⚠️ 1 missing array shape annotation
✅ All types declared
✅ Strict comparisons used

### Test Specialist
✅ 93% coverage
✅ All layers tested
✅ Test pyramid maintained

## Action Items
- [ ] Split CookieController::store() method
- [ ] Add array shape to CookieRepository::toDomainEntity()

## Approval Status
✅ APPROVED (after action items completed)
```

## Quality Gates

All specialists must approve or provide actionable feedback

## See Also

- `.claude/skills/code-review/SKILL.md`
