# Contributing

Thanks for your interest. This repo is a heavily-AI-tooled CodeIgniter 4
CQRS/DDD template; contributions are welcome under the rules below.

## Getting set up (3 minutes)

```bash
git clone https://github.com/ci4me/CQRSTemplate.git
cd CQRSTemplate
composer install     # runs bin/setup-hooks → wires .githooks/ automatically
cp env .env          # then edit DB credentials
php spark migrate
composer test
```

## The rules

The hooks installed by `composer install` enforce most of this; the rest is
verified in CI on every PR. Don't fight the hooks — fix the code.

### 1. Branch naming

`<type>/<scope>[-<issue-number>]-<short-description>`

Examples:
- `feat/cookie-add-stock`
- `fix/order-123-negative-quantity`
- `chore/tooling-bump-phpstan`

The `prepare-commit-msg` hook auto-prefixes your commit subject from this.

### 2. Conventional Commits 1.0 — required

```
<type>(<scope>)[!]: <subject>

<body>

<footer>
```

Allowed types: `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`,
`build`, `ci`, `chore`, `revert`.

The `commit-msg` hook rejects non-conforming subjects. The Commitlint GitHub
Action re-validates server-side, so `--no-verify` won't save you on a PR.

### 3. Quality gates (all enforced by hooks + CI)

| Gate              | Local hook       | CI workflow            |
|-------------------|------------------|------------------------|
| PHP syntax (`-l`) | pre-commit       | `ci.yml > quality`     |
| PHPCS (PSR-12)    | pre-commit       | `ci.yml > quality`     |
| PHPStan Level 8   | pre-commit       | `ci.yml > quality`     |
| PHPUnit           | pre-push         | `ci.yml > tests`       |
| Coverage ≥ 90%    | —                | `ci.yml > tests`       |
| Gitleaks          | pre-commit       | `ci.yml > secrets`     |
| Conventional Commits | commit-msg    | `commitlint.yml`       |
| Dep CVEs/licenses | —                | `dependency-review.yml`|
| CodeQL            | —                | `codeql.yml`           |
| Scorecard         | —                | `scorecard.yml` (weekly) |

### 4. Code patterns

- `declare(strict_types=1);` on every new PHP file.
- Methods ≤ 20 lines, classes ≤ 200 lines.
- Commands/queries/events are `final readonly` immutable DTOs.
- Value Objects validate in the constructor and expose factory methods.
- No business logic in controllers.
- See `.claude/SERENA_CODE_OPTIMIZATION.md` for the full Serena-friendly style guide.

### 5. AI-agent commits

If a commit was authored with significant AI assistance, include a
`Co-Authored-By:` trailer. Treat AI output the same as any other contribution:
it doesn't get a free pass on review, tests, or quality gates.

## PR flow

1. Branch from `main`.
2. Push when local hooks pass.
3. CI runs everything again, plus extras (CodeQL, dep review, gitleaks server-side).
4. Address PR comments; the merge queue (if enabled) serializes merges.
5. `release-please` automatically opens a release PR as you accumulate `feat:`/`fix:` commits.

## Reporting bugs

Issues use forms: <https://github.com/ci4me/CQRSTemplate/issues/new/choose>.
Include the relevant slice from `writable/logs/app-YYYY-MM-DD.json` if any.

## Security issues

See [SECURITY.md](SECURITY.md). **Don't** open a public issue.
