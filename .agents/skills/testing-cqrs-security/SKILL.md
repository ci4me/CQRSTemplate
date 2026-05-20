---
name: testing-cqrs-security
description: Test CQRSTemplate security features end-to-end. Use when verifying session auth, CSRF, CSP, DTO rendering, role-based access, registration, or logout behavior.
---

# Testing CQRSTemplate Security Features

## Prerequisites

### MySQL Setup
```bash
sudo systemctl start mysql
# Database: ci4_cqrs, User: ci4user/ci4pass (created during initial setup)
```

### Database Seeding
Seed the database with test accounts and sample data:
```bash
cd /home/ubuntu/repos/CQRSTemplate
php spark migrate
php spark db:seed DatabaseSeeder
```
This creates:
- `admin@example.com` / `password123` (admin role)
- `customer@example.com` / `password123` (customer role)
- Sample cookie records

**Note**: Seeded accounts bypass password complexity validation since the seeder hashes passwords directly with Argon2id.

### JWT Secret Key
The `JwtService` validates that `JWT_SECRET_KEY` is not weak. Use a strong hex key (48+ bytes):
```bash
STRONG_KEY=$(openssl rand -hex 48)
# In .env, use CI4 format with quotes:
# JWT_SECRET_KEY = '<key>'
```
**Gotcha**: Base64 keys with `=` signs may cause CI4's `.env` parser issues. Use hex encoding instead.

**Gotcha**: The JWT key must be set as an environment variable when starting the dev server, OR correctly formatted in `.env`. If using `php spark serve`, the `.env` file is read by CI4's DotEnv loader, but you may need to also `export JWT_SECRET_KEY=...` in the shell for reliability.

### Start Dev Server
```bash
export JWT_SECRET_KEY='<your-strong-key>'
cd /home/ubuntu/repos/CQRSTemplate
php spark serve --port 8080
```

### User Registration
The register form at `/auth/register` has three fields: Full Name, Email, Password.

**Password complexity requirements** (enforced by PasswordComplexity value object):
- Minimum 12 characters
- At least one uppercase letter
- At least one digit
- At least one special character

Example valid password: `TestPass123!@`

Alternatively, register users via curl:
```bash
CSRF=$(curl -s -c /tmp/reg.txt http://localhost:8080/auth/register | grep -oP 'name="csrf_test_name" value="\K[^"]+') 
curl -s -c /tmp/reg.txt -b /tmp/reg.txt -X POST http://localhost:8080/auth/register \
  -d "name=Test+User&email=testuser@example.com&password=TestPass123!@&csrf_test_name=${CSRF}"
```

## Key Test Flows

### 1. Session Auth Middleware (C-1)
- Navigate to `/cookies` or `/dashboard` without being logged in
- **Expected**: 302 redirect to `/auth/login` with flash "Please log in to continue."

### 2. CSP Headers and External CSS (M-1)
- Check response headers on any page for `Content-Security-Policy` containing `cdn.jsdelivr.net`
- Auth pages should link to `/css/auth.css` with no inline `<style>` blocks
- Layout pages should have SRI `integrity` attributes on Bootstrap CDN links

### 3. Login and Session Regeneration (C-2)
- Login with valid credentials (use seeded accounts or register a new user)
- **Expected**: Redirect to `/dashboard`, Bootstrap-styled layout renders correctly
- Navbar should show: ERP Template, Dashboard, Cookies links + Logout button on the right
- The "Users" link only appears for admin users

### 4. Cookie CRUD with DTO Rendering (H-5)
- Create a cookie via `/cookies/create`
- **Expected**: Cookie list and detail views render DTO properties (`$cookie->name`, `$cookie->formattedPrice`, `$cookie->stock`)
- If DTO transformation is broken, views would throw errors trying `->getName()->getValue()` on a DTO

### 5. Role-Based Access (SessionRoleMiddleware)
- Login as customer, navigate to `/admin/users`
- **Expected**: Redirect to `/dashboard` with "You do not have permission to access this resource."
- The "Users" link in the navbar is only visible to admin users.

### 6. Logout
- Click the **Logout** button in the navbar (right side, styled as `btn-outline-light btn-sm`)
- **Expected**: Redirected to `/auth/login`. Attempting to access `/cookies` while logged out returns redirect to `/auth/login` with "Please log in to continue." flash message.

### 7. API 401/403 Response (H-10)
- `curl http://localhost:8080/api/v1/users -H 'Accept: application/json'`
- **Expected**: JSON with `error` and `message` keys. No `your_role` or `required_role` fields.

### 8. CSRF Token Randomization (M-6, M-7)
- Load `/auth/login` twice, extract `csrf_test_name` values
- **Expected**: Tokens differ between loads

## Static Analysis
```bash
composer phpstan   # PHPStan Level 8, expect 0 errors
composer phpcs     # PHPCS, expect 0 violations
composer test      # PHPUnit (58 JWT_SECRET_KEY errors are pre-existing in integration tests)
```

## Devin Secrets Needed
No external secrets required. JWT_SECRET_KEY is generated locally for testing.
