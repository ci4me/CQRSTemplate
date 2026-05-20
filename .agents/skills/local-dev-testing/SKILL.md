---
name: local-dev-testing
description: Set up and test the CQRSTemplate app locally. Use when starting the dev server, creating test accounts, or running manual tests.
---

# Local Development & Testing

## Prerequisites

- PHP 8.4, MySQL 8.0, Composer installed
- Database `ci4_cqrs` created with user access

## Quick Start

```bash
# 1. Start MySQL
sudo systemctl start mysql

# 2. Install dependencies (also wires git hooks)
composer install

# 3. Configure .env
cp -n env .env
# Edit .env with database credentials and JWT key:
#   database.default.hostname = localhost
#   database.default.database = ci4_cqrs
#   database.default.username = ci4user
#   database.default.password = ci4pass
#   database.default.DBDriver = MySQLi
#   JWT_SECRET_KEY = <output of: openssl rand -hex 48>

# 4. Run migrations and seed
php spark migrate --all
php spark db:seed DatabaseSeeder

# 5. Start dev server
php spark serve --port 8080
```

## Test Accounts

Seeded by `UserSeeder` (`php spark db:seed UserSeeder`):

| Email | Password | Role |
|-------|----------|------|
| admin@example.com | password123 | admin |
| customer@example.com | password123 | customer |

## Key URLs

| URL | Description |
|-----|-------------|
| http://localhost:8080/auth/register | Register new account |
| http://localhost:8080/auth/login | Login |
| http://localhost:8080/dashboard | Dashboard (requires auth) |
| http://localhost:8080/cookies | Cookie CRUD (requires auth) |
| http://localhost:8080/admin/users | User management (admin only) |

## Running Quality Checks

```bash
composer check      # PHPStan + PHPCS + PHPUnit (run before PRs)
composer phpstan     # PHPStan Level 8 only
composer phpcs       # PHPCS (PSR-12 + Slevomat) only
composer test        # PHPUnit only
```

## Common Gotchas

- **JWT_SECRET_KEY**: Must be 48+ bytes hex. Use `openssl rand -hex 48`. Do NOT use base64 (= signs break CI4 .env parser).
- **CSRF**: Session-based with token randomization. All forms need `<?= csrf_field() ?>`.
- **Session auth**: Web routes (/cookies, /dashboard, /admin/*) require login. Unauthenticated requests redirect to /auth/login.
- **Admin routes**: /admin/* requires admin role. Customer accounts get 403.
- **Pre-commit hooks**: Auto-installed by `composer install`. Located in `.githooks/`. Run PHPStan + PHPCS on staged files.
- **Coverage threshold**: CI enforces 90% coverage. Run `composer test:coverage` to check locally.
