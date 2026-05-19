# /enforce-quality

Runs all quality checks (PHPStan, Slevomat, Tests) and fixes violations automatically.

## Usage

```
/enforce-quality [path]
```

## Arguments

- **path** (optional): Specific path to check (default: entire project)

## Examples

```
/enforce-quality
/enforce-quality app/Domain/Cookie
/enforce-quality tests/Unit/Domain/Cookie
```

## Implementation

1. Run PHPStan Level 8
2. Run Slevomat coding standards
3. Auto-fix with phpcbf
4. Run tests with coverage
5. Report results
6. If failures, provide fix guidance

## Expected Output

```
🔍 Running Quality Checks...

1. PHPStan Level 8
   ✅ 0 errors

2. Slevomat Coding Standards
   ⚠️ 5 violations found
   🔧 Auto-fixing with phpcbf...
   ✅ 5 violations fixed

3. PHPUnit Tests
   ✅ 192/192 tests passing (100%)
   ✅ Coverage: 93% (above 90% minimum)

---

✅ ALL QUALITY CHECKS PASSED

Project Status: READY FOR COMMIT

Quality Metrics:
- PHPStan Level 8: ✅ 0 errors
- Slevomat: ✅ 0 violations
- Tests: ✅ 192 passing, 93% coverage

🎉 Code meets all quality standards!
```

## If Failures Occur

```
❌ QUALITY CHECKS FAILED

1. PHPStan Level 8
   ❌ 3 errors found

   app/Domain/Cookie/ValueObjects/CookiePrice.php:42
   - Error: preg_replace() might return null
   - Fix: Add null check

   app/Models/Cookie/CookieRepository.php:78
   - Error: Missing array shape annotation
   - Fix: Add @param array{id: int, ...} $data

2. Slevomat
   ✅ 0 violations

3. Tests
   ❌ 2 tests failing
   ❌ Coverage: 87% (below 90% minimum)

---

❌ CANNOT COMMIT - FIX VIOLATIONS FIRST

Use specialists to fix:
- phpstan-specialist - Fix type safety issues
- test-specialist - Fix failing tests and improve coverage
```

## Specialists Invoked

1. `phpstan-specialist` - Type safety validation
2. `slevomat-specialist` - Coding standards validation & auto-fix
3. `test-specialist` - Test execution & coverage validation

## Quality Gates

- PHPStan Level 8: 0 errors
- Slevomat: 0 violations
- Tests: All passing, 90%+ coverage

## See Also

- `.claude/instructions.md` - Rejection policy
- `.claude/CLAUDE.md` - Quality standards
