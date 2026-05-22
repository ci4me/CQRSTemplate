# 09 — Auth infrastructure

## Files audited

- app/Infrastructure/Auth/Middleware/JwtAuthenticationMiddleware.php
- app/Infrastructure/Auth/Middleware/PermissionMiddleware.php
- app/Infrastructure/Auth/Middleware/RateLimitMiddleware.php
- app/Infrastructure/Auth/Middleware/RoleAuthorizationMiddleware.php
- app/Infrastructure/Auth/Middleware/SessionAuthMiddleware.php
- app/Infrastructure/Auth/Services/ActorResolver.php
- app/Infrastructure/Auth/Services/JwtService.php
- app/Infrastructure/Auth/Services/LoginAttemptTracker.php
- app/Infrastructure/Auth/Services/PasswordHashingService.php
- app/Infrastructure/Auth/Services/PermissionService.php
- app/Infrastructure/Auth/Services/RateLimitService.php
- app/Infrastructure/Auth/Services/SecurityEventService.php
- app/Infrastructure/Auth/Services/SessionManagementService.php
- app/Infrastructure/Auth/Services/TokenBlacklistService.php
- app/Infrastructure/Auth/Adapters/Jwt/FirebaseJwtAdapter.php
- app/Infrastructure/Auth/Commands/LoginUser/{LoginUserCommand,LoginUserHandler}.php
- app/Infrastructure/Auth/Commands/LogoutUser/{LogoutUserCommand,LogoutUserHandler}.php
- app/Infrastructure/Auth/Commands/RefreshToken/{RefreshTokenCommand,RefreshTokenHandler}.php
- app/Infrastructure/Auth/Commands/RequestPasswordReset/{RequestPasswordResetCommand,RequestPasswordResetHandler}.php
- app/Infrastructure/Auth/Commands/ResetPassword/{ResetPasswordCommand,ResetPasswordHandler}.php
- app/Infrastructure/Auth/ValueObjects/{AuthenticationResult,PasswordResetToken,RateLimitResult}.php
- app/Infrastructure/Auth/AuthServiceProvider.php
- app/Infrastructure/Security/TimingSafeComparison.php
- app/Controllers/Domain/Auth/AuthController.php (context)
- app/Controllers/Api/AuthController.php (context)
- app/Config/Filters.php, Routes.php, Cache.php, Security.php (context)

## Critical security findings

**CRITICAL — JwtService::validateToken decodes without enforcing algorithm allow-list / type-confusion via legacy tokens**
`app/Infrastructure/Auth/Services/JwtService.php:171` and `:181`. `JWT::decode` is called with a single `Key($secret, 'HS256')`. firebase/php-jwt v6 pins the alg from the `Key` so `alg:none` / `RS256→HS256` attacks are blocked **only if the Key argument's alg is honored**. However, `getTokenPayload()` at `:226` decodes the payload with **no signature check** and is used by `RefreshTokenHandler::handle` at `:100` to derive `jti`/`exp` that get persisted into `refresh_tokens`. An attacker who can submit a chosen payload through any path that funnels into `getTokenPayload` controls those values. Also: there is no `iss`/`aud` validation anywhere — the `iss: 'cqrs-auth'` claim is set but never verified on decode.

**CRITICAL — Refresh token replay still possible: blacklist not consulted, rotation table allows unknown JTIs**
`app/Infrastructure/Auth/Commands/RefreshToken/RefreshTokenHandler.php:50-93`. The handler:
1. Does NOT call `TokenBlacklistService::isBlacklisted()` for the inbound refresh token — a logged-out (blacklisted) refresh token is still accepted (logout puts it in cache blacklist but refresh never queries it).
2. `isRefreshTokenRevoked()` only matches rows where `revoked = true`. A refresh token whose `jti` was **never inserted** into `refresh_tokens` (i.e. the one minted at login — login never inserts; see `LoginUserHandler:121` only writes to `sessions`) is treated as fresh, and rotation creates a row for the *new* token only. So the very first replay of the original refresh token passes the "revoked" check on every attempt because no row exists. The "token-theft → revoke all" branch is unreachable for the login-issued refresh token.
3. After rotation, `revokeRefreshToken($jti)` does `update` on a row that does not exist for login-issued tokens, silently no-op — replay protection broken.

**CRITICAL — TokenBlacklistService::cleanup wipes the counter, not the entries; capacity check is fake**
`app/Infrastructure/Auth/Services/TokenBlacklistService.php:108`, `:136-137`. When count ≥ 9000 (90%), `cleanupIfNeeded()` deletes only the counter key. Real cache entries remain until their 30-day TTL expires. After cleanup the counter resets to 0, so the size cap **never actually triggers eviction** — the cache backend can fill without bound and is then trusted to "auto-expire". With `FileHandler` (default in `Config/Cache.php:45`) under high churn (e.g. forced-logout-all), this is a memory/disk DoS vector and also defeats the documented "10,000 tokens max" invariant. Worse: `getStats()` reports `total_entries` from the just-zeroed counter, masking the issue from monitoring.

**CRITICAL — Token blacklist storage is non-durable file cache by default; logouts evaporate on cache flush**
`app/Config/Cache.php:45` `public string $handler = 'file';` (only switches to Redis when explicitly enabled via env). `TokenBlacklistService` stores `token_blacklist_*` keys via `CacheInterface`. With FileHandler, `php spark cache:clear`, a deploy, or a writable-dir wipe drops every blacklisted token, re-activating every logged-out access **and** refresh token until natural expiry. Token revocation MUST be durable storage for the JWT lifetime.

**CRITICAL — `validateToken` rejection logic discards the real exception**
`app/Infrastructure/Auth/Services/JwtService.php:173-196`. When the **current** secret throws (e.g. expired token), control flows into the `oldSecretKey` branch. If old-secret decode also fails, `throw $currentSecretException` is re-thrown — but the original exception may be `ExpiredException` for a *valid* expired token; consumers (JwtAuthenticationMiddleware `:107-115`) map that to "invalid_token_signature" / 401 which is OK. The dangerous case is the reverse: a token signed with `oldSecret` that has expired now succeeds at `:181` and is accepted as valid because the expiry check happens inside `JWT::decode` — fine — but the `oldSecretException` path on a malformed token causes `$currentSecretException` to be re-thrown shadowing the actual failure reason and losing observability. Minor in isolation, but combined with finding above (no `iss`/`aud` check) makes diagnosing key-confusion attacks harder.

**CRITICAL — PermissionService legacy admin shim grants ALL permissions to anyone with `users.role = 'admin'`, with no RBAC override**
`app/Infrastructure/Auth/Services/PermissionService.php:43-79`. The "transitional" shim runs **before** RBAC lookup and short-circuits on `role === 'admin'`. There is no:
- Expiry / kill-switch for the shim (no env flag like `AUTH_LEGACY_ADMIN_SHIM=false`).
- Audit log when the shim grants access (RBAC lookup never reached).
- Fail-secure on `legacyAdminCheck` catch (`:78` returns false — OK), but `rbacCheck` also catches all `\Throwable` and returns false silently (`:95-97`), meaning a DB outage produces 403 for non-admins and 200 for legacy admins. Inconsistent failure mode.
- Defence against tampered `actor->id` if upstream resolver is bypassed (see ActorResolver finding below).

This is a privilege-escalation surface: the `role` column write path must be locked down, and the shim should fail loud (log every use) so it can be removed.

**CRITICAL — PermissionService also bypasses checks for "system" actor**
`app/Infrastructure/Auth/Services/PermissionService.php:38-41`. `Actor::isSystem()` short-circuits to `return true`. `ActorResolver::resolve()` at `app/Infrastructure/Auth/Services/ActorResolver.php:25-34` returns `Actor::system()` whenever it can't extract a user id from the request — i.e. on **any HTTP request without an attached `$request->user`**. The `PermissionMiddleware` does catch this case at `:55-59` (rejects with 401 when actor is system on HTTP). But other call sites that use `PermissionService::allows` directly (controllers, command handlers, queries) will get **silent permission grant** for unauthenticated requests. Anyone using `$permissions->allows($actorResolver->resolve($request), …)` outside the middleware is exposed. Fail-secure default should be "deny on system actor unless explicitly invoked from CLI".

**CRITICAL — RefreshTokenHandler::handle calls `$this->jwtService->validateToken($command->refreshToken)` WITHOUT `expectedType='refresh'`**
`app/Infrastructure/Auth/Commands/RefreshToken/RefreshTokenHandler.php:52`. Type enforcement happens at `:55` against `$payload['type']`, but `validateToken` was called with `null` expected-type, so the type check on the **JwtService** side is skipped. The handler-level check at `:55` does cover this, so currently safe — but the pattern is fragile: anyone refactoring the handler can lose the manual check and revert to access-token-as-refresh-token confusion. Use `validateToken($token, 'refresh')` for defence-in-depth.

## High-priority findings

**HIGH — Login: rate limit per IP only; permits low-and-slow credential stuffing across many emails**
`app/Config/Routes.php:39,72` `ratelimit:5,300`. The identifier in `RateLimitMiddleware::getIdentifier` (`:122-132`) is `method:path|ip`. There is no per-email lockout / global rate limit. `LoginAttemptTracker::isBruteForceDetected` (`:83`) is also strictly per-IP. Attacker rotating through 10 emails from one IP gets 5 attempts per email — the brute-force detector counts `success=false` rows by IP, so it still trips at 5; OK, but proxy/Tor rotation defeats both layers. No per-account lockout (`User::incrementFailedLoginAttempts` exists but never short-circuits at handler level — see next finding).

**HIGH — `User::isLockedOut` honoured by adapter, but no global lockout enforced before authenticate**
`app/Infrastructure/Auth/Adapters/Jwt/FirebaseJwtAdapter.php:34-36` checks `isLockedOut()` only **after** `verify()` succeeds. So a locked account whose attacker happens to guess the correct password is still rejected — but a wrong-password attempt against a locked account performs full Argon2id verification (slow) and reveals nothing — OK for security but DoS amplifier (each wrong guess costs ~100 ms of compute regardless of lock state). Move the `isLockedOut` check before `verify()`.

**HIGH — LoginUserHandler treats `null` user via dummy Argon2 hash but uses `bin2hex(random_bytes(8))` as plaintext seed**
`app/Infrastructure/Auth/Commands/LoginUser/LoginUserHandler.php:59`. `HashedPassword::fromPlaintext('dummy_password_' . bin2hex(random_bytes(8)))` then `verify($command->password)`. Hashing a *new* random password on every miss means each lookup pays the **full Argon2id cost** (intended) but the timing now also includes RNG and string concat, which is fine. Real issue: there is no early rate-limit on email-not-found versus email-found — an attacker can still enumerate by timing the *outer* request because DB lookup + dummy hash + log writes will differ measurably from cache-hit `findByEmail` + real hash. Not a clean timing channel but not eliminated either.

**HIGH — RoleAuthorizationMiddleware grants admin universal access; no permission scoping**
`app/Infrastructure/Auth/Middleware/RoleAuthorizationMiddleware.php:192-197` `if ($userRole === 'admin') return true;`. This hard-codes superuser semantics in the middleware **and** duplicates the legacy admin shim in PermissionService. Two different paths to the same admin-bypass, neither logged. If you adopt RBAC, kill this branch — `permission:foo.bar` already covers it.

**HIGH — SessionAuthMiddleware does NO session-side fingerprinting / idle-timeout**
`app/Infrastructure/Auth/Middleware/SessionAuthMiddleware.php:46-73`. JWT path enforces device fingerprint + idle-timeout (`JwtAuthenticationMiddleware:200-385`); web path validates only `user_id` presence and `User::isActive()`. A stolen `ci_session` cookie is valid until the framework session TTL with no IP/UA check, no idle revocation, no concurrent-session cap. Asymmetric security posture between tiers is a bypass — attacker targets the weaker channel.

**HIGH — `JwtAuthenticationMiddleware::checkIdleTimeout` is opt-in via env var and silent-skips for legacy tokens**
`app/Infrastructure/Auth/Middleware/JwtAuthenticationMiddleware.php:306-319`. `AUTH_IDLE_TIMEOUT_SECONDS=0` disables it entirely (intentional but error-prone). Backward-compat skip at `:316-319` means any token without `jti`/`user_id` bypasses idle timeout AND fingerprint check (`:205-208`). With JWT secret rotation in place, "old tokens" can keep circulating without these protections for the full TTL.

**HIGH — Device fingerprint grace-period default 300s with `AUTH_DEVICE_FINGERPRINT_GRACE_PERIOD=0` semantics inverted**
`app/Infrastructure/Auth/Middleware/JwtAuthenticationMiddleware.php:241-251`. `if ($gracePeriodSeconds > 0)` — setting `AUTH_DEVICE_FINGERPRINT_GRACE_PERIOD=0` does the right thing (no grace). But setting it to a negative or non-numeric value casts to 0 silently and disables grace, while setting to `"86400"` allows fingerprint changes for 24h — that's effectively no fingerprint enforcement for the first day. Document and clamp.

**HIGH — Device fingerprint is just `sha256(ip|user_agent)`; both attacker-controllable, neither salted with server secret**
`app/Infrastructure/Auth/Middleware/JwtAuthenticationMiddleware.php:394-398`, `app/Infrastructure/Auth/Services/SessionManagementService.php:244-248`. SHA-256 is unsalted, so an attacker stealing the access token PLUS knowing the original IP+UA (e.g. from web access logs) can forge the matching fingerprint by setting `X-Forwarded-For` and UA. CodeIgniter `getIPAddress()` honours proxy headers if `proxyIPs` is configured; an attacker behind a misconfigured proxy can spoof. Use HMAC keyed with an env secret, or include a server-issued nonce stored in the session row.

**HIGH — Session table queries lack composite index visibility; SELECT without bounded LIMIT in fingerprint+idle checks**
`app/Infrastructure/Auth/Middleware/JwtAuthenticationMiddleware.php:214-219`, `:325-330`. Two DB roundtrips on *every* authenticated request. No `limit(1)`. On a busy table this is a perf cliff that can be weaponised (DoS). Add `limit(1)` and require `(access_token_jti, user_id, revoked)` composite index.

**HIGH — `SessionManagementService::enforceSessionLimit` race condition**
`app/Infrastructure/Auth/Services/SessionManagementService.php:257-297`. Count-then-insert with no transaction. Two simultaneous logins both observe count < 5 and both insert, exceeding the cap. Wrap in `beginTransaction` + `SELECT ... FOR UPDATE` or rely on a DB-side trigger.

**HIGH — `RateLimitService` token-bucket state is read-modify-write without atomic CAS**
`app/Infrastructure/Auth/Services/RateLimitService.php:67-87`. Doc comment claims "atomic operations / no race conditions" (`:30`, `:230`), but `CacheInterface::get` then `save` is not atomic for FileHandler (default). Two concurrent requests both read `tokens=1`, both decrement to 0, both pass. Under attack this means burst > limit. Atomic only with Redis `INCR` semantics, which the code does not use. The CI cache adapter does not expose CAS.

**HIGH — Logout endpoint not rate-limited; access-token blacklisting is a write-amplifier**
`app/Config/Routes.php` — `/api/v1/auth/logout` is in the `jwt` group (`:78`) but has no `ratelimit:` filter. An attacker with a valid token can hammer logout to fill the blacklist (each request writes a cache key). Combined with the broken cleanup (finding above), this is a DoS.

**HIGH — Token TTL alignment regression risk: blacklist hardcoded 30d (2592000), refresh TTL configurable**
`app/Infrastructure/Auth/Services/TokenBlacklistService.php:52` hardcodes `2592000` (30d). `JwtService` reads `AUTH_REFRESH_TOKEN_TTL` (`:51-52`). If ops sets refresh TTL > 30d (e.g. for long-lived service tokens), blacklisted tokens come back to life after 30d while the refresh token is still valid. Compute from `JwtService` config, not hard-coded.

## Medium/Low findings

**MEDIUM — `JwtService::isWeakSecret` only checks 32-char minimum and a static substring list**
`app/Infrastructure/Auth/Services/JwtService.php:66-90`. A 32-char low-entropy secret like `"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"` passes. No entropy check (e.g. distinct-character count). 256 bits is the NIST minimum for HS256 — measure actual entropy or require base64-decoded length ≥ 32 bytes.

**MEDIUM — `RefreshTokenHandler::storeRefreshToken` ignores DB insert failure**
`app/Infrastructure/Auth/Commands/RefreshToken/RefreshTokenHandler.php:155-167`. If insert fails (constraint, disk full) handler still returns success and the issued refresh token will never be tracked → cannot be revoked later. Check return value.

**MEDIUM — `ResetPasswordHandler` does not invalidate password-reset tokens for other sessions on success**
`app/Infrastructure/Auth/Commands/ResetPassword/ResetPasswordHandler.php:104` revokes refresh tokens but does **not** call `SessionManagementService::revokeAllUserSessions` (vs `ChangeUserPasswordHandler:112` which does). The web session row in `sessions` table is left intact — only the refresh side is killed. JWT access tokens are not blacklisted either. Mirror the change-password path.

**MEDIUM — `ResetPasswordHandler::findResetToken` SELECT by token_hash is direct equality, not constant-time**
`app/Infrastructure/Auth/Commands/ResetPassword/ResetPasswordHandler.php:128`. SHA-256 lookup is fine for DB indexing but the database driver's string compare is not guaranteed constant-time. Mitigated because token is 64-hex random bytes — practically not exploitable, but the doc comment at `:23` advertises "Constant-time token comparison" which is misleading; the code performs a DB lookup, not a comparison.

**MEDIUM — `PasswordResetToken::generate` produces 64-char hex (32 bytes entropy) — fine, but `fromToken` accepts arbitrary length**
`app/Infrastructure/Auth/ValueObjects/PasswordResetToken.php:44-48`. No length / charset validation on the user-supplied token. Combined with no rate limit at the controller (`Routes.php:75` is `ratelimit:5,300`), an attacker can attempt token-collision against the SHA-256 column with arbitrary inputs. Validate `ctype_xdigit($token) && strlen($token) === 64`.

**MEDIUM — `RequestPasswordResetHandler` swallows all exceptions; user enumeration via timing remains**
`app/Infrastructure/Auth/Commands/RequestPasswordReset/RequestPasswordResetHandler.php:46-103`. When user is null, the handler returns immediately (`:57`); when user exists, it does DB delete + DB insert + email send. Wall-clock difference reveals enumeration. Make both branches do equivalent work (or always defer email send to a queue).

**MEDIUM — `SessionAuthMiddleware` reads `user_id` from session but does not validate session age or fingerprint**
`app/Infrastructure/Auth/Middleware/SessionAuthMiddleware.php:46-73`. See HIGH above — also: no `session_regenerate_id` on privilege change, only on login (`AuthController::login:81`). After role change the same session id keeps the new role.

**MEDIUM — `ActorResolver::extractFromRequest` accepts any object with `getId(): int > 0`**
`app/Infrastructure/Auth/Services/ActorResolver.php:52-67`. No type check that `$request->user` is actually a `User` entity. A bug elsewhere that assigns the wrong object (a `Cookie` with `getId()`) silently becomes the actor.

**MEDIUM — `PermissionMiddleware::parseArgument` returns `null` (→ 403) when given an unknown-format permission**
`app/Infrastructure/Auth/Middleware/PermissionMiddleware.php:87-109`. Filter mis-configured with typo (e.g. `permission:cookies-update` instead of `cookies.update`) silently rejects with 403 "requires a permission name argument" — but the message is the same as "Permission::fromString threw". Caller cannot tell mis-config from real denial. Distinguish 500-config-error from 403-denied.

**MEDIUM — Web AuthController::login swallows all `\Throwable` as "Login failed"**
`app/Controllers/Domain/Auth/AuthController.php:94-97`. Masks underlying errors (DB outage, command bus mis-wiring) as user-facing auth failure. Log + 500 distinctly.

**MEDIUM — `JwtAuthenticationMiddleware::isBearerTokenFormat` accepts case-sensitive "Bearer " only**
`app/Infrastructure/Auth/Middleware/JwtAuthenticationMiddleware.php:421-424`. RFC 6750 says scheme is case-insensitive. Reject is a UX issue, not security — but minor.

**LOW — `JwtService::generateUniqueTokenId` uses 16 bytes (128 bits) hex**
`app/Infrastructure/Auth/Services/JwtService.php:106-109`. 128 bits is fine for collision resistance. No issue, noted.

**LOW — `PasswordHashingService` uses `PASSWORD_ARGON2ID` with default parameters**
`app/Infrastructure/Auth/Services/PasswordHashingService.php:13`. PHP default params (m=65536, t=4, p=1) are below current OWASP guidance (m≥19456 KiB, t≥2, p≥1 minimum). PHP defaults are higher, acceptable, but pin them explicitly so a PHP upgrade doesn't silently weaken hashing.

**LOW — `SecurityEventService::logEvent` writes to DB on every event; no async / no batching**
`app/Infrastructure/Auth/Services/SecurityEventService.php:52-71`. Under DoS, the DB write itself becomes attack surface. Consider async insert via outbox.

**LOW — `LoginUserHandler` records `ipAddress ?? '0.0.0.0'`**
`app/Infrastructure/Auth/Commands/LoginUser/LoginUserHandler.php:31`. Falling back to a non-routable address pollutes the `login_attempts` table and could cause `isBruteForceDetected('0.0.0.0')` false positives across unrelated tenants. Throw instead.

**LOW — Refresh endpoint has weaker rate limit than login (`ratelimit:10,300` vs `5,300`)**
`app/Config/Routes.php:73`. Refresh should be at least as restrictive as login — a leaked refresh token attacker can hit it twice as hard.

**LOW — `TokenBlacklistService::incrementCounter` race**
`app/Infrastructure/Auth/Services/TokenBlacklistService.php:165-169`. `get() + 1 + save()` is not atomic; concurrent logouts undercount. Minor (counter is advisory).

**LOW — CSRF policy is global "all routes" — API tier (`/api/v1/*`) is forced to send CSRF tokens despite being JWT-stateless**
`app/Config/Filters.php:95`. Means real API clients (mobile, integrations) cannot call POST endpoints without juggling the framework's cookie-CSRF flow. Either configure CSRF `except: ['api/*']` (relying on JWT only) or document the workflow. As written, every JSON POST will 403 unless the client first GETs to obtain `csrf_cookie_name`. The web-side CSRF is correctly enabled; the API side should use a different stance (CORS + bearer + same-site cookie).

**LOW — `TimingSafeComparison::equalsHash` discards result of branch-equal-length-but-padded comparison**
`app/Infrastructure/Security/TimingSafeComparison.php:165-168`. The intent is "burn time to mask length" but discarding the result still leaves the early `if ($knownLength !== $userLength)` length-branch observable — `str_pad` + `hash_equals` time depends on `$knownLength`. Marginal; documented as best-effort.

**LOW — `RateLimitMiddleware::parseArguments` casts `(int)` silently**
`app/Infrastructure/Auth/Middleware/RateLimitMiddleware.php:103-104`. `ratelimit:abc,300` produces `maxAttempts=0` → `RateLimitService::validateParameters` throws `InvalidArgumentException` (`:117`) → uncaught at middleware level → 500 to caller. Catch and 500-log.

## Verdict

The Auth infrastructure shows clear effort and many correct defences (Argon2id, jti, fingerprint, idle-timeout, session table, blacklist, rotation scaffolding, brute-force detection, no user-enumeration on reset). **But it is NOT production-ready.** Six CRITICAL issues compound:

1. Blacklist storage is file cache by default and self-resets — token revocation is effectively unreliable.
2. Refresh-token rotation has a hole at the seam between login (no row inserted) and refresh (row required for revoke detection), so the very first refresh-token replay is undetectable.
3. Refresh handler never consults the blacklist, so logout does not invalidate refresh tokens through the refresh path.
4. PermissionService has TWO admin-bypass paths (legacy shim + middleware fallback) with no telemetry, no kill-switch, and both default-allow on edge cases.
5. ActorResolver + PermissionService fail open for "system" actor when called outside the middleware.
6. Web session tier has no fingerprint / idle / concurrent-session enforcement — asymmetric to JWT tier and the weaker target.

Fix order: (a) wire blacklist consult into RefreshTokenHandler, (b) insert into `refresh_tokens` at login, (c) move blacklist to Redis with TTL derived from `AUTH_REFRESH_TOKEN_TTL`, (d) gate the legacy admin shim behind an env flag and log every use, (e) align SessionAuthMiddleware to JWT-tier protections, (f) make ActorResolver/PermissionService default-deny for system on HTTP.

Do not deploy until items 1-3 above are resolved.
