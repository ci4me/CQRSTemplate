---
name: testing-cqrs-security
description: Test CQRSTemplate security features end-to-end. Use when verifying session auth, CSRF, CSP, DTO rendering, role-based access, or logout behavior.
---

# Testing CQRSTemplate Security Features

## Prerequisites

### MySQL Setup
```bash
sudo systemctl start mysql
# Database: ci4_cqrs, User: ci4user/ci4pass (created during initial setup)
```

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
**Known issue**: The register form (`/auth/register`) is missing a `name` field, but `RegisterUserCommand` requires one. Register users via curl instead:
```bash
CSRF=$(curl -s -c /tmp/reg.txt http://localhost:8080/auth/register | grep -oP 'name="csrf_test_name" value="\K[^"]+') 
curl -s -c /tmp/reg.txt -b /tmp/reg.txt -X POST http://localhost:8080/auth/register \
  -d "name=Test+User&email=testuser@example.com&password=SecurePass123!&role=customer&csrf_test_name=${CSRF}"
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
- Login with valid credentials
- **Expected**: Redirect to `/dashboard`, Bootstrap-styled layout renders correctly

### 4. Cookie CRUD with DTO Rendering (H-5)
- Create a cookie via `/cookies/create`
- **Expected**: Cookie list and detail views render DTO properties (`$cookie->name`, `$cookie->formattedPrice`, `$cookie->stock`)
- If DTO transformation is broken, views would throw errors trying `->getName()->getValue()` on a DTO

### 5. Role-Based Access (SessionRoleMiddleware)
- Login as customer, navigate to `/admin/users`
- **Expected**: Redirect to `/dashboard` with "You do not have permission to access this resource."

### 6. Logout (C-3)
- POST to `/auth/logout` with valid CSRF token
- **Gotcha**: There's no logout button in the UI. Get CSRF token from a page with a form (e.g., `/cookies/create`), then POST to `/auth/logout`
- **Expected**: 303 redirect to `/auth/login`. Old session returns 302 on protected routes.

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
