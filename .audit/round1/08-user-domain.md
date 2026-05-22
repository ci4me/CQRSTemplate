# 08 — User domain

## Files audited

### Entity
- `app/Domain/User/Entities/User.php`

### Value Objects
- `app/Domain/User/ValueObjects/Email.php`
- `app/Domain/User/ValueObjects/HashedPassword.php`
- `app/Domain/User/ValueObjects/UserName.php`
- `app/Domain/User/ValueObjects/UserRole.php`
- `app/Domain/User/ValueObjects/UserStatus.php`
- `app/Domain/User/ValueObjects/PasswordComplexity.php`
- `app/Domain/User/ValueObjects/AccessToken.php` (referenced, not opened)
- `app/Domain/User/ValueObjects/AuthenticationResult.php` (referenced, not opened)

### Commands
- `app/Domain/User/Commands/RegisterUser/{RegisterUserCommand,RegisterUserHandler}.php`
- `app/Domain/User/Commands/UpdateUser/{UpdateUserCommand,UpdateUserHandler}.php`
- `app/Domain/User/Commands/ChangeUserPassword/{ChangeUserPasswordCommand,ChangeUserPasswordHandler}.php`
- `app/Domain/User/Commands/DeleteUser/{DeleteUserCommand,DeleteUserHandler}.php`

### Queries
- `app/Domain/User/Queries/GetUserById/{GetUserByIdQuery,GetUserByIdHandler}.php`
- `app/Domain/User/Queries/GetUserByEmail/{GetUserByEmailQuery,GetUserByEmailHandler}.php`
- `app/Domain/User/Queries/GetAllUsers/{GetAllUsersQuery,GetAllUsersHandler}.php`
- `app/Domain/User/Queries/SearchUsers/{SearchUsersQuery,SearchUsersHandler}.php`

### Events
- `app/Domain/User/Events/UserRegistered/{UserRegisteredEvent,UserRegisteredEventHandler}.php`
- `app/Domain/User/Events/UserUpdated/{UserUpdatedEvent,UserUpdatedEventHandler}.php`
- `app/Domain/User/Events/UserDeleted/{UserDeletedEvent,UserDeletedEventHandler}.php`
- `app/Domain/User/Events/PasswordChanged/{PasswordChangedEvent,PasswordChangedEventHandler}.php`

### Ports
- `app/Domain/User/Ports/AuthenticationServiceInterface.php`
- `app/Domain/User/Ports/PasswordHasherInterface.php`
- `app/Domain/User/Ports/RateLimitInterface.php`
- `app/Domain/User/Ports/TokenBlacklistInterface.php`
- `app/Domain/User/Ports/TokenGeneratorInterface.php`

### Repository / Persistence
- `app/Infrastructure/Persistence/Repositories/UserRepository.php`
- `app/Infrastructure/Persistence/Repositories/UserRepositoryInterface.php`
- `app/Infrastructure/Persistence/Repositories/PasswordHistoryRepository.php`
- `app/Infrastructure/Persistence/Models/UserModel.php`

### Wiring / cross-cutting
- `app/Domain/User/UserServiceProvider.php`
- `app/Domain/User/ErrorCodes.php`
- `app/Database/Migrations/2025-10-26-110000_CreateUsersTable.php`
- `app/Controllers/Domain/User/UserController.php` (web)
- `app/Config/Routes.php`, `app/Config/Filters.php`

## Findings

### CRITICAL

- **`app/Domain/User/Ports/RateLimitInterface.php:7`** — domain port imports `App\Infrastructure\Auth\ValueObjects\RateLimitResult`. A Port may not depend on Infrastructure; this inverts the hexagonal arrow. `RateLimitResult` belongs in `Domain/User/ValueObjects` (or `Domain/Shared`).
- **Authorization is missing on the web admin user CRUD.** `app/Config/Filters.php:139-148` only applies `web_auth` to `admin/*`; no `role:admin` (or equivalent) filter. The API route group has `role:admin` (`Routes.php:91`), but `app/Config/Routes.php:48` for `admin/users` does **not**. Any authenticated user (including `customer`/`guest`) can list, create, update, delete and reset passwords via the web UI. Comment "Only admins can change passwords (enforced by filter)" (`ChangeUserPasswordHandler.php:26`) is false for the web path.
- **`app/Domain/User/Commands/RegisterUser/RegisterUserHandler.php:22` and `:46`** — handler `save()` is called before `User::create()` returns, then password hashing happens BEFORE email-uniqueness check returns successful (actually checked first at `:42`, OK), BUT: the dummy `password_hash('dummy...', PASSWORD_ARGON2ID)` at `:105` runs inside `checkEmailUniqueness` **only** in the duplicate-email branch. The normal-success path skips the dummy hash, so a timing oracle still exists (duplicate email = 2x argon2 work, fresh email = 1x). The mitigation is effectively backwards.
- **`app/Domain/User/Commands/RegisterUser/RegisterUserHandler.php:22`** — depends on concrete `UserRepository`, not `UserRepositoryInterface`. Same in `GetUserByIdHandler.php:15`, `GetUserByEmailHandler.php:16`. Breaks DIP, prevents test mocking against the interface, and is inconsistent with `UpdateUserHandler`, `DeleteUserHandler`, `ChangeUserPasswordHandler`, `GetAllUsersHandler`, `SearchUsersHandler`, which all use the interface.
- **`app/Domain/User/Commands/DeleteUser/DeleteUserHandler.php:60`** — self-deletion throws with `ErrorCodes::USER_VALIDATION_NAME` (100). Wrong error code; should be a business-rule violation code (e.g. `USER_BUSINESS_RULE_INVALID_ROLE_ASSIGNMENT`-class, or a new `USER_BUSINESS_RULE_SELF_DELETE`).
- **`app/Domain/User/Commands/UpdateUser/UpdateUserHandler.php`** — no admin check, and `UpdateUserCommand` has no `Actor` field (compare `DeleteUserCommand`, `ChangeUserPasswordCommand` which carry `Actor $deletedBy/$changedBy`). Any caller can change another user's `role` or `status` to `admin/active` with zero audit trail. Comment "Only admins can change roles (checked in controller/filter)" (`UpdateUserHandler.php:27`) is unenforced (see web filter finding above).

### HIGH

- **Repository location inconsistency with Cookie.** Cookie repository: `app/Models/Cookie/CookieRepository.php` + interface at `app/Domain/Cookie/Ports/CookieRepositoryInterface.php`. User repository: `app/Infrastructure/Persistence/Repositories/UserRepository.php` + interface in the SAME infrastructure namespace. Both layouts are wrong in different ways. The interface (port) should live in `app/Domain/User/Ports/UserRepositoryInterface.php`; only the implementation belongs in Infrastructure (or Models). Pick one (Infrastructure is cleaner) and apply uniformly — current state guarantees nobody knows where to look.
- **Audit columns absent.** `app/Database/Migrations/2025-10-26-110000_CreateUsersTable.php` has `created_at`/`updated_at`/`deleted_at` only. Cookie has `tenant_id`, `created_by`, `updated_by`, `deleted_by` (`2025-01-21-000001_CreateCookiesTable.php:94-106`). User events `UserDeletedEvent.deletedBy` and `PasswordChangedEvent.changedBy` carry the actor but it is **never persisted** to the row — only logged. Repository `toArray()` (`UserRepository.php:310-321`) omits these columns even if they existed.
- **`app/Domain/User/Entities/User.php:182` (and `:65`)** — `setRepositories`/`getRepository` indirection in `UserServiceProvider` returns `object` and the provider throws "Invalid dependencies injected" at runtime instead of using type-hinted DI. `RegisterUserHandler` is wired with concrete `UserRepository` (`UserServiceProvider.php:73`) so the abstraction in `UserRepositoryInterface` is meaningless.
- **No "RestoreUser" command.** Cookie has `RestoreCookieCommand`/`RestoreCookieHandler`. Users are soft-deleted, but nothing un-deletes them — the only way to recover is direct DB write. Either add the command or document that soft delete is permanent.
- **`app/Domain/User/UserServiceProvider.php:93`** — `Config\Services::sessionManagementService()` is fetched at handler-construction time from a static factory inside `registerCommands`. Replaces DI with a service-locator call and bypasses the `setRepositories` mechanism the provider was designed around.
- **`app/Domain/User/Commands/UpdateUser/UpdateUserHandler.php:64`, `:81`** — uses raw `\RuntimeException` for "user not found" and "email in use", instead of `DomainException::businessRuleViolation` (as RegisterUserHandler does at `:107`). Inconsistent exception taxonomy; clients can't distinguish 404 vs 409. Same in `ChangeUserPasswordHandler.php:70` and `DeleteUserHandler.php:73`.
- **`app/Domain/User/ValueObjects/UserName.php:67`** — throws plain `\InvalidArgumentException`, while `Email` and `PasswordComplexity` throw `ValidationException`. Inconsistent — callers cannot catch a single VO-validation exception.
- **`app/Domain/User/ErrorCodes.php:32-36`** — duplicate constant values for `USER_BUSINESS_RULE_ACCOUNT_LOCKED` / `USER_BUSINESS_RULE_LOCKED` (both `= 301`) and `USER_BUSINESS_RULE_ACCOUNT_SUSPENDED` / `USER_BUSINESS_RULE_SUSPENDED` (both `= 303`). Aliases marked in comments — pick one name and delete the other (will cause PHPCS / Slevomat duplicate-constant violations).
- **`app/Infrastructure/Persistence/Repositories/UserRepository.php:299`** — `HashedPassword::fromHash($row['password_hash'])` will reach `User::reconstitute` for soft-deleted/locked rows just fine, but `password_hash` column nullability is not asserted. If a row ever has NULL (e.g. SSO user), `fromHash` would receive NULL → fatal. No defensive guard.
- **`app/Infrastructure/Persistence/Repositories/UserRepository.php:233`, `:255`, `:280`** — `$this->model->where('deleted_at IS NULL')` strings combined with the model's `useSoftDeletes = true`. CodeIgniter's softDeletes already applies `deleted_at IS NULL` to `find()`/`countAllResults()`. The explicit string `where('deleted_at IS NULL')` likely double-applies, but more importantly is at risk of SQL injection wording bugs (not parameterised). Use the builder's `where('deleted_at', null)`.

### MEDIUM

- **`app/Infrastructure/Persistence/Models/UserModel.php:17-25`** — `$allowedFields` omits `created_at`/`updated_at`/`deleted_at` (timestamps managed by model), which is correct, but also omits any future `created_by`/`updated_by`/`deleted_by` columns. When audit columns are added, this list must be updated — flag for parity work.
- **`app/Domain/User/Entities/User.php:65-75`** — entity directly imports `App\Infrastructure\Logging\DomainLogger`. Domain → Infrastructure dependency. Cookie does the same, so this is template-wide, but it's still an architecture violation; entity should emit a domain event or take a logger port.
- **`app/Domain/User/ValueObjects/Email.php:9`, `PasswordComplexity.php:9`** — same Domain → Infrastructure (`DomainLogger`) issue inside VOs. VOs should be pure.
- **`app/Domain/User/ValueObjects/HashedPassword.php:96`** — `fromPlaintext` validates complexity then trims. `password_hash` is called on the trimmed value; if the user typed leading/trailing spaces in their password by intent, those are silently dropped — login will then fail because `password_verify` is called on the un-trimmed input from login. Asymmetric trim across registration/verify is a real bug.
- **`app/Domain/User/ValueObjects/PasswordComplexity.php:65`** — `strlen($trimmed)` instead of `mb_strlen`. Multi-byte passwords undercount; `MIN_LENGTH=12` UTF-8 password may be rejected even when displayed length is 12+.
- **`app/Domain/User/ValueObjects/PasswordComplexity.php:36`** — special-char regex limited to ASCII punctuation. Excludes common high-entropy chars (£, €, smart quotes, accented chars). Acceptable but documented OWASP is "any non-alphanumeric" — narrower than spec.
- **`app/Domain/User/Commands/UpdateUser/UpdateUserHandler.php:91-102`** — six adjacent `if (...) $updatedFields[] = '...'` lines; should extract to private `diffFields(User, command): array` per "max 20 lines / method" rule.
- **`app/Domain/User/Queries/GetAllUsersHandler.php`, `SearchUsersHandler.php`** — both return `array{data, total, page, perPage, totalPages}` instead of a typed result object. Cookie likely has the same shape; flag for cross-domain Pagination value object.
- **`app/Domain/User/Queries/SearchUsers/SearchUsersHandler.php:62-66`** — passes `searchTerm: $query->email ?? ''`. `SearchUsersQuery` documents "email partial match" but repository's `findPaginated` uses the term against name OR email (`UserRepository.php:176-179`). So "search by email" actually also matches names — misleading API.
- **`app/Domain/User/Queries/GetUserByIdHandler.php:8`, `GetUserByEmailHandler.php:9`, `RegisterUserHandler.php:16`** — type-hint concrete `UserRepository`, not interface. (Duplicates the CRITICAL DIP finding from another angle.)
- **`app/Domain/User/Events/PasswordChangedEventHandler.php:37`** — log message is `'Password changed'` but the rest of the codebase uses past-tense matching event names ("Cookie created", "User updated"). Minor consistency.
- **`app/Domain/User/Events/UserUpdated/UserUpdatedEvent.php:33` and `UserDeletedEvent.php:33`, `PasswordChangedEvent.php:39`** — `updatedAt` / `deletedAt` / `changedAt` are typed `string` (ISO8601). `UserRegisteredEvent.php:23` uses `\DateTimeImmutable`. Inconsistent event-payload typing.
- **`app/Domain/User/Commands/RegisterUser/RegisterUserCommand.php:47`** — accepts `string $role` only to have the handler reject `admin` via string compare at handler:51 and always overwrite to `UserRole::Customer` at handler:59. The `role` field is dead weight; either remove it from the command or honour valid non-admin values.

### LOW

- `app/Domain/User/Entities/User.php` is 395 lines, over the 200-line class limit declared in `.claude/CLAUDE.md`. Cookie's `Entities/Cookie.php` likely the same — flag.
- `app/Domain/User/Entities/User.php:62-63` — `MAX_FAILED_LOGIN_ATTEMPTS` and `LOCKOUT_DURATION_MINUTES` are private consts. Should be configurable via `Config\Auth`-style.
- `app/Domain/User/Ports/AuthenticationServiceInterface.php` — referenced VOs `AccessToken`, `AuthenticationResult` exist but were not opened in this audit; flag as a follow-up to verify they belong in `Domain/User/ValueObjects` and don't leak infra.
- `app/Domain/User/UserServiceProvider.php:166-169` — `getRepositories()` lists `'passwordHasher'` but `passwordHasher` is never used in any wired handler (Argon2 is hard-coded inside `HashedPassword::fromPlaintext`). Dead dependency.
- `app/Domain/User/Commands/UpdateUser/UpdateUserHandler.php:113` — `$this->repository->update($user)` return value ignored. If returns false, command silently "succeeds" and the event still fires.

### Hardcoded `userId = 1` survival check

No literal `userId = 1` or `'userId' => 1` was found in the audited surface. `Actor::user($userId)` (`app/Domain/Shared/ValueObjects/Actor.php:25`) enforces `$userId > 0`, and controllers resolve via `Services::actorResolver()->resolve($this->request)` (`UserController.php:258`, `:320`). No surviving hardcode found in this slice — but `Actor::system()` defaults to ID `0`, and `DeleteUserHandler.php:51` short-circuits the self-deletion check when `isSystem()` — meaning a "system" actor can delete *anyone* including admins. Probably intentional, but undocumented as a privilege.

## Parity with Cookie template

| Aspect | Cookie | User | Gap |
|---|---|---|---|
| Repository location | `app/Models/Cookie/` | `app/Infrastructure/Persistence/Repositories/` | HIGH inconsistency |
| Repository interface location | `app/Domain/Cookie/Ports/` (correct port placement) | `app/Infrastructure/Persistence/Repositories/` (wrong layer) | CRITICAL |
| Restore command | yes (`RestoreCookieCommand`) | no | HIGH |
| `Projections/` + `ReadModels/` | yes | none | MEDIUM (User CQRS read side is bare repo) |
| `Validators/` folder | yes | none | LOW |
| Audit columns (`tenant_id`, `created_by`, `updated_by`, `deleted_by`) | yes (migration + entity) | none | HIGH |
| Soft delete | yes | yes (UserModel `useSoftDeletes`) | parity |
| Repository traits (`BusinessMetricsLogging`, `RepositoryLogging`) | yes | inline logging | MEDIUM duplication |
| Tests | 18 files | 11 files | MEDIUM — no Register/GetUserBy{Id,Email} handler tests, no Repository integration test |
| StockChanged-like granular events | yes (CookieStockChanged) | partial (PasswordChanged exists; no Suspended/Locked events) | MEDIUM |
| `final readonly class` for repo | (CookieRepository `class`, non-final, non-readonly) | `readonly class` (not final) | minor; both non-final |
| ServiceProvider DI style | uses constructor-injected handlers | uses runtime `getRepository(string)` indirection + `instanceof` checks | HIGH — User is brittler |

User domain is roughly equivalent in coverage but **markedly weaker** in (a) DDD layering discipline (Ports importing Infrastructure, handlers depending on concrete repo, repo interface in wrong layer), (b) audit-column persistence, (c) authorization at web routes, and (d) restore/recovery flow.

## Verdict

**NOT a safe template clone.** Cookie is the canonical pattern; copying User as a starting point will propagate at least four architecture violations (Domain→Infra deps, repo-interface misplacement, missing role filter on web, missing audit columns) plus two real security bugs (web-route authz gap, password trim asymmetry).

Recommended remediation order:
1. CRITICAL — Add `role:admin` filter to `admin/users/*` in `app/Config/Filters.php`.
2. CRITICAL — Move `UserRepositoryInterface` to `app/Domain/User/Ports/` and rewire all handlers + provider to inject the interface. Remove the `RateLimitResult` import from `RateLimitInterface`.
3. CRITICAL — Add `Actor` to `UpdateUserCommand`; enforce role-change authorization in handler.
4. CRITICAL — Fix register-timing oracle (move dummy hash to the success path or apply to both paths uniformly).
5. HIGH — Audit columns (`created_by`, `updated_by`, `deleted_by`) migration + persistence + entity.
6. HIGH — Add `RestoreUserCommand`, dedupe ErrorCodes constants, replace `\RuntimeException` with `DomainException`, fix `UserName` to throw `ValidationException`.
7. MEDIUM — Strip `DomainLogger` from VOs/entity; align event payload types; extract `diffFields`; fix password trim symmetry and `mb_strlen` length check.

Once 1–4 land, User is acceptable as a clone-template alongside Cookie.
