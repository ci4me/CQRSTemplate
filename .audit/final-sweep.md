# Final Security & Correctness Sweep

## Batch 1 — Controllers (FINAL #20)

✅ **BaseController** — Clean. Minimal, abstract, no direct DB or exception leakage.

✅ **HealthController** — Clean. Exception caught; message suppressed in catch block (line 70-74). Returns only "database unreachable", never leaks `$e->getMessage()`.

✅ **Home** — Clean. Simple auth check, no input handling, no exceptions.

✅ **AuthController** — Clean. 
- Uses `$this->request->getPost()` (not raw `$_POST`).
- Exception handling: DomainException and ValidationException caught and suppressed to flashdata only; generic "Login failed" message on Throwable (line 94-96).
- CSRF: Controllers dispatch through Illuminate/CodeIgniter bus pattern; form CSRF handled by framework.

✅ **CookieController** — Clean.
- Uses `$this->request->getGet()` and `$this->request->getPost()` (not raw superglobals).
- Input coercion is_string / is_numeric explicit (lines 52-56, 116-129).
- Exception handling: DomainException and ValidationException caught; message passed to flashdata (safe for redirect).
- No DB access; all goes through commandBus/queryBus.
- POST methods (store, update, delete) do not show CSRF tags in controller code inspection, but that is framework-level and handled in templates.

---

## Batch 2 — Views (FINAL #19)

❌ **XSS in /app/Views/admin/users/edit.php (lines 43, 52, 107–109)**

Lines 43, 52:
```php
value="<?= old('name', $user->name) ?>" required>
value="<?= old('email', $user->email) ?>" required>
```
Missing `esc(..., 'attr')` when falling back to `$user->name` and `$user->email`.

Lines 107–109:
```php
<strong>User ID:</strong> <?= $user->id ?><br>
<strong>Created:</strong> <?= $user->createdAt ?><br>
<strong>Last Updated:</strong> <?= $user->updatedAt ?>
```
Raw output of `$user->createdAt`, `$user->updatedAt` (DATETIME strings are safe, but precedent matters). `$user->id` is INT (safe).

**Fix:** 
- Line 43: `value="<?= esc(old('name', $user->name), 'attr') ?>"`
- Line 52: `value="<?= esc(old('email', $user->email), 'attr') ?>"`
- Lines 107–109: Wrap with `esc()` for consistency: `<?= esc($user->createdAt) ?>`

❌ **XSS in /app/Views/cookies/edit.php (lines 101–103)**

```php
<strong>ID:</strong> <?= $cookie->id ?><br>
<strong>Created:</strong> <?= $cookie->createdAt ?><br>
<strong>Updated:</strong> <?= $cookie->updatedAt ?>
```
Same as users/edit — DATETIME and ID are safe in this context, but `$cookie->updatedAt` rendered bare (not escaping time data is risky if later changed).

**Fix:** `<?= esc($cookie->createdAt) ?>` and `<?= esc($cookie->updatedAt) ?>`

✅ **cookies/create.php** — Clean. Uses `esc(old(...), 'attr')` correctly. csrf_field() present.

✅ **admin/users/create.php** — Clean. Uses `old()` without fallback to object properties; csrf_field() present.

✅ **errors/html/*.php** — Clean. All output properly escaped with `esc()`. SHOW_DEBUG_BACKTRACE gated to dev environment. Production.php is minimal/safe.

---

## Batch 3 — Migrations (FINAL #21)

❌ **Missing FOREIGN KEY constraints in 2026-05-19-200200_CreatePermissionsSchema.php**

- **role_permissions table (lines 109–127):** has `role_id` and `permission_id` but NO `addForeignKey()` calls. Should reference `roles(id)` and `permissions(id)`.
- **user_roles table (lines 129–151):** has `user_id` and `role_id` but NO `addForeignKey()` calls. Should reference `users(id)` and `roles(id)`.

**Fix:** After line 125 in `createRolePermissionsTable()`:
```php
$this->forge->addForeignKey('role_id', 'roles', 'id', 'CASCADE', 'CASCADE');
$this->forge->addForeignKey('permission_id', 'permissions', 'id', 'CASCADE', 'CASCADE');
```
After line 149 in `createUserRolesTable()`:
```php
$this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
$this->forge->addForeignKey('role_id', 'roles', 'id', 'CASCADE', 'CASCADE');
```

❌ **Missing FOREIGN KEY in 2026-05-20-100100_CreateNotificationsTable.php**

- **notifications.user_id (lines 40–44):** No `addForeignKey()` to `users(id)`.

**Fix:** After line 98, add:
```php
$this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
```

❌ **Missing FOREIGN KEY in 2026-05-20-100000_CreateAttachmentsTable.php**

- **attachments.uploaded_by (lines 86–90):** INT user ID but NO FOREIGN KEY to `users(id)`.
- **attachments.tenant_id (lines 92–96):** nullable INT but no FOREIGN KEY or index for querying by tenant (if needed).

**Fix:** After line 114, add:
```php
$this->forge->addForeignKey('uploaded_by', 'users', 'id', 'SET NULL', 'CASCADE');
```
(SET NULL because `uploaded_by = 0` for system actions; or handle separately if 0 is special.)

✅ **CreateSessionsTable** — Has FOREIGN KEY (line 91): `user_id` → `users(id)` CASCADE.

✅ **CreateRefreshTokensTable** — Has FOREIGN KEY (line 61): `user_id` → `users(id)` CASCADE.

✅ All timestamps DATETIME (not MySQL TIMESTAMP DEFAULT CURRENT_TIMESTAMP) — safe for SQLite.

✅ No UNIQUE on nullable columns (NULL semantics are correct).

---

**Summary:** 5 defects found across Batch 2 (Views: 2 XSS, 1 minor) and Batch 3 (Migrations: 3 missing ForeignKey constraints).
