---
name: fix-bug
description: >
  Fix a bug in the CQRSTemplate codebase. Identifies the correct layer
  (domain, infrastructure, controller, view), writes a failing test first,
  implements the fix, and verifies all quality gates pass. Use when the user
  reports a bug, asks to "fix X", or describes unexpected behavior.
---

# fix-bug

Fix a bug following the CQRS + DDD layered architecture.

## Step 1 — Identify the layer

| Symptom | Where to look |
|---|---|
| Validation error / business rule | `app/Domain/{Domain}/ValueObjects/` or `Entities/` |
| Command not executing | `app/Domain/{Domain}/Commands/{Action}/{Action}Handler.php` |
| Query returning wrong data | `app/Domain/{Domain}/Queries/{Query}/{Query}Handler.php` |
| API response wrong | `app/Controllers/Api/{Domain}Controller.php` |
| Web form not working | `app/Controllers/Domain/{Domain}/` + `app/Views/` |
| Database mapping error | `app/Infrastructure/Persistence/Repositories/` + `app/Models/` |
| Auth / session issue | `app/Config/Filters.php` + `app/Controllers/Auth/` |
| CSRF failure | Check `<?= csrf_field() ?>` in form views |
| JWT issue | `app/Infrastructure/Auth/JwtAdapter.php` |
| Event not firing | `app/Domain/{Domain}/{Domain}ServiceProvider.php` event registration |
| Migration failure | `app/Database/Migrations/` |

## Step 2 — Read the relevant code

Before editing:
1. Read the file(s) where the bug likely lives
2. Read the existing tests for that module
3. Check the domain's ServiceProvider for registration issues

## Step 3 — Write a failing test first

Add a test case that reproduces the bug:

- **Value object bug** → `tests/Unit/Domain/{Domain}/ValueObjects/`
- **Entity bug** → `tests/Unit/Domain/{Domain}/Entities/`
- **Handler bug** → `tests/Unit/Domain/{Domain}/Commands/` or `Queries/`
- **Repository bug** → `tests/Integration/Repositories/`
- **HTTP bug** → `tests/Feature/{Domain}/`

Run `composer test` to confirm it fails.

## Step 4 — Implement the fix

Constraints:
- `declare(strict_types=1)` in every PHP file
- Methods < 20 lines preferred
- Type hints for all parameters and returns
- No business logic in controllers
- Value objects are `final readonly`
- Commands/queries are `final readonly`
- DocBlocks on all public APIs

## Step 5 — Verify

```bash
composer check
```

All three must pass:
- `composer phpstan` — PHPStan Level 8, 0 errors
- `composer phpcs` — PSR-12 + Slevomat, 0 violations
- `composer test` — PHPUnit, 90%+ coverage maintained

## Step 6 — Create PR

Commit with conventional-commit format: `fix(scope): subject`

The PR should reference the bug symptom and explain the root cause.
Pre-commit hooks will auto-run PHPStan + PHPCS on staged PHP files.
