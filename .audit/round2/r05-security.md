# Round 2 — Security review (r05)

Reviewer scope: confirm or refute every CRITICAL/HIGH security claim in
`.audit/round1-consolidated.md` and the underlying agent reports
(`09-auth-infrastructure.md`, `12-storage-notif-settings-i18n-email.md`,
`13-http-bulk-logging.md`). Spot-checked the actual files cited and looked
for security holes the audit missed.

Verdict up front: the consolidated audit's security findings are
**substantially correct** — none of the CRITICAL/HIGH items I checked is a
false positive. The audit also **missed two genuinely critical issues**
(arbitrary class instantiation in the outbox relay, and a web-tier
mass-assignment + privilege-escalation chain to which the missing
`role:admin` filter is the gateway). Do not ship.

---

## Verified critical security holes (top 5)

### C1. Web `admin/users/*` routes have no role gate → IDOR + self-promotion to admin

Real, and worse than the audit framed it.

- `app/Config/Routes.php:48-58` declares the `admin/users` group with **no
  inline filter** at all.
- `app/Config/Filters.php:139-148` only applies `web_auth` (session check)
  to `admin/*`. There is no `role` filter and no `permission` filter on
  this pattern.
- `app/Controllers/Domain/User/UserController.php:198-225` (`update`) reads
  `role` and `status` straight from POST and forwards them to
  `UpdateUserCommand`. Any authenticated `customer` can POST
  `/admin/users/{their-own-id}` with `role=admin&status=active` and
  self-promote. That is straight **mass assignment + vertical privilege
  escalation**, gated only by CSRF.
- `UserController::storePassword` (`:307-338`) and `::delete` (`:249-271`)
  are reachable by the same population.

The audit called the route gate missing (consolidated #13) but did not
trace it through to the mass-assignment in `UpdateUserCommand`. That chain
makes C1 the single most exploitable hole in the codebase.

Fix correctness: adding `role:admin` to the route group (as the audit
proposes) closes the IDOR. To also close mass assignment, `UpdateUserCommand`
needs to drop `role`/`status` from the web payload entirely, or the handler
must authorise the actor as admin before applying those fields. Just adding
the role filter is necessary but a defence-in-depth fix at the command layer
should follow.

### C2. `EventOutboxRelay::rehydrate` instantiates **any class** by name (arbitrary class instantiation / gadget surface)

Audit missed the severity.

- `app/Infrastructure/Outbox/EventOutboxRelay.php:155-188`. `$eventClass`
  is `(string) $row['event_class']`. The relay calls `class_exists($eventClass)`
  (which triggers autoload of arbitrary classes), then
  `new \ReflectionClass($eventClass)`, then `$reflection->newInstanceArgs($args)`
  where `$args` come from `json_decode($row['payload'], true)`.
- There is no allow-list of event classes, no `is_subclass_of(..., DomainEventInterface::class)`
  check, no namespace constraint (e.g. `App\Domain\*\Events\*`).
- Today `event_outbox.event_class` is only written by `EventOutboxWriter`,
  which itself is dead code (consolidated #11). But the moment ANY path
  starts writing this column (e.g. an admin importer, a replay tool, a
  cross-service inbox) attacker control of one cell becomes:
  - autoload of any class in the project — side effects in static
    initialisers / constructors of "interesting" classes (the
    `JwtService` ctor, for example, runs validation that throws — harmless
    here, but the pattern is dangerous).
  - construction of any class whose constructor parameters happen to
    match the JSON payload by reflection name matching. Classes with
    side-effecting constructors (loggers, factories, anything that
    touches the filesystem or DB during `__construct`) become a gadget.

The audit listed this row as a "reflection rehydrate is brittle to
constructor changes" reliability issue (theme #10, HIGH item 9). The
**security** angle — arbitrary class instantiation — was not called out.

Fix: validate `class_exists` AND `is_subclass_of($eventClass, DomainEventInterface::class)`
AND a namespace allow-list before reflecting. Better still, map class FQCN
to a small registry at relay boot and reject anything not in the registry.

### C3. JWT refresh tokens are not rotated correctly — first replay is undetectable

Real, exactly as the audit describes.

- `app/Infrastructure/Auth/Commands/LoginUser/LoginUserHandler.php:121-128`
  creates a `sessions` row but **does not** insert into `refresh_tokens`.
- `app/Infrastructure/Auth/Commands/RefreshToken/RefreshTokenHandler.php:131-141`
  detects "revoked" by `where('jti', $jti)->where('revoked', true)->countAllResults() > 0`.
  Because login never inserted the row, the first refresh always sees zero
  matches and passes the check. The `revokeRefreshToken($jti)` at `:143-153`
  then updates zero rows. Replay of the same login-issued refresh token
  succeeds N times in a row.
- The handler also does **not** consult `TokenBlacklistService::isBlacklisted`
  for the inbound refresh token. Logout puts the token into the blacklist
  (`TokenBlacklistService::blacklist`, `:43-56`) but refresh never reads
  from it. So logout is not honoured at the refresh seam either.

Audit's fix is correct: insert at login, consult blacklist on refresh, and
either rotate via the same table or use a single source of truth.

### C4. Token blacklist storage is non-durable + capacity cap is theatre

Real.

- `app/Config/Cache.php:45` defaults `$handler = 'file'`. Blacklist keys
  live in `writable/cache/`. `php spark cache:clear`, a deploy that wipes
  `writable/`, or container restart re-activates every blacklisted token
  for the remainder of its 30-day TTL.
- `app/Infrastructure/Auth/Services/TokenBlacklistService.php:108,136-137`
  — `cleanup()` and `cleanupIfNeeded()` delete only the counter key
  (`COUNTER_KEY`), not any of the actual `token_blacklist_*` entries. The
  comment at `:106-107` admits this explicitly ("we rely on automatic TTL
  expiration and reset the counter"). The next blacklist write sees
  counter=0 and merrily keeps writing — so the 10,000-entry cap **never
  fires**. `getStats()` (`:77-92`) reports the lying counter as
  `total_entries`, masking the leak from monitoring.

Fix correctness: moving to Redis with TTL derived from
`AUTH_REFRESH_TOKEN_TTL` (as proposed) closes both — Redis gives durability
and `EXPIRE` does the cap by TTL not by count. The capacity-counter logic
should be **deleted**, not "fixed".

### C5. `PermissionService` has two admin-bypass paths + system-actor short-circuit

Real, and the surface is bigger than just the middleware-protected paths.

- `app/Infrastructure/Auth/Services/PermissionService.php:38-41` —
  `Actor::isSystem()` → `return true`. `Actor::SYSTEM_ID = 0`
  (`app/Domain/Shared/ValueObjects/Actor.php:17`), so any actor with
  `id === 0` gets every permission.
- `app/Infrastructure/Auth/Services/ActorResolver.php:25-34` — when the
  request has no `$request->user` and no session `user_id`, `resolve()`
  returns `Actor::system()`. `PermissionMiddleware::before` does catch
  this and returns 401 (`PermissionMiddleware.php:55-59`).
- BUT every other caller of `$permissions->allows(...)` skips that 401
  gate. Today `IdempotencyMiddleware::actorId()` (`IdempotencyMiddleware.php:152`)
  also calls `(new ActorResolver())->resolve($request)->id` directly and
  uses `0` as the cache key for all unauthenticated callers, which means
  anonymous attackers share one idempotency namespace (replay each
  other's cached responses). This is a NEW finding the audit did not
  call out — see N1 below.
- `PermissionService::legacyAdminCheck` (`:60-79`) returns `true` for any
  user with `users.role = 'admin'`, with **no kill switch, no audit log,
  no expiry**. The `try/catch(Throwable)` (`:76-78`) returns `false` on
  DB failure — fail-secure for non-admins, but `rbacCheck` (`:81-98`)
  also swallows all `Throwable` and returns `false`. So a DB blip
  produces `legacy-admin → 200`, `rbac-user → 403`. Inconsistent failure
  mode, undetectable to the operator.

Fix correctness: gate the shim behind `AUTH_LEGACY_ADMIN_SHIM=true`, log
every grant, fail loud (throw, not return false) when both legacy and rbac
checks throw. Make `PermissionService::allows()` reject `Actor::isSystem()`
unless the call is invoked from CLI / a typed "system context" wrapper —
the current "system can do anything" default is fail-open.

---

## False-positive security findings

None of the CRITICAL/HIGH items I sampled is a clean false positive. The
following are over-stated or framed in a way that exaggerates the
exploitable surface:

- **Consolidated HIGH 13 / 13-http-bulk-logging HIGH item — "SSRF via
  `CurlHttpTransport`":** real but currently theoretical.
  `app/Infrastructure/Http/Client/CurlHttpTransport.php:36-43` indeed does
  not set `CURLOPT_PROTOCOLS`, so `file://`, `gopher://`, `dict://` etc.
  are reachable. **However**, there is no caller in the codebase today
  that forwards user-controlled URLs to `OutboundHttpClient`. The audit
  reads as if SSRF is live; the right framing is "latent SSRF, fix
  before the first webhook/integration ships". The fix
  (`CURLOPT_PROTOCOLS = CURLPROTO_HTTP | CURLPROTO_HTTPS`) is correct and
  cheap, and should land before any caller exists.

- **Consolidated CRITICAL 16(d) / DB encrypt off:** real but listed under
  "security." `app/Config/Database.php:42` `'encrypt' => false` matters
  only on remote MySQL. On a local socket / unix domain this is not a
  vulnerability. Severity is environment-dependent; calling it CRITICAL
  is correct only for production deployments with the DB on another host.

- **09-auth CRITICAL — "JwtService::validateToken decodes without
  enforcing algorithm allow-list":** half false. `JWT::decode($token, new Key($secret, 'HS256'))`
  at `JwtService.php:171,181` DOES pin the alg via the `Key` object —
  firebase/php-jwt v6 will reject any header that doesn't match the
  Key's algorithm. So the "alg:none / RS256→HS256 confusion" is **not**
  exploitable here. The genuine issue in the same finding (no
  `iss`/`aud` verification, plus `getTokenPayload()` returns unverified
  claims) survives; the alg-confusion framing does not. The audit
  itself walks this back two sentences later, which makes the CRITICAL
  label misleading.

- **12-storage HIGH on `EmailService::sendTemplate` LFI:** real but
  conditional — `EmailService.php:44-53` forwards `$view` to CI4's
  `view()` helper without an allow-list, but **no caller forwards
  request input**. The two production callers are `sendPasswordResetEmail`
  (hard-coded view `'emails/auth/password_reset'`) and nothing else.
  The risk is "if a future caller forwards user input"; not a live
  exploit. Fix proposal (enum/allow-list) is correct and should land
  before the next caller is added.

- **13-http "OutboundHttpClient retries non-idempotent POSTs assuming
  remote dedupes":** correctness/reliability issue, not a security
  finding. The auto-Idempotency-Key generation is sound (CSPRNG,
  `bin2hex(random_bytes(16))`, 128 bits); the failure mode is "remote
  service double-creates" not "attacker double-creates." Don't list this
  as a security CRITICAL.

---

## New security findings (audit missed)

### N1. `IdempotencyMiddleware` namespaces anonymous callers under one shared `actor_id = 0`

`app/Infrastructure/Http/Middleware/IdempotencyMiddleware.php:150-153`
calls `(new ActorResolver())->resolve($request)->id`. For unauthenticated
requests this returns `0` (`Actor::SYSTEM_ID`). The cache row is keyed by
`(id_key, actor_id)`, so:

- Two anonymous callers picking the same Idempotency-Key collide. The
  second caller gets the first caller's response body replayed, including
  any per-session info embedded in JSON (CSRF tokens, validation errors
  with leaked field values, sometimes a freshly-generated reset link
  echoed back in a problem+json).
- An attacker who knows or guesses the victim's anonymous
  Idempotency-Key (e.g. predictable from a mobile client) can replay the
  victim's response from another IP, with no auth.

This is a **horizontal info-disclosure** on the idempotency table for any
mutating route that is unauthenticated + idempotent (`/api/v1/auth/*` is
not in the `idempotency` group today, so the live surface is small, but
any future "guest checkout"-style API that opts into idempotency inherits
this).

Fix: skip the idempotency cache entirely when `actor->isSystem()` and the
route is anonymous; OR namespace the cache by IP for anonymous users; OR
return 401 when an anonymous request carries an Idempotency-Key on a route
that requires one.

### N2. `EventOutboxRelay::rehydrate` is an arbitrary-class-instantiation gadget

Covered above in C2. The audit listed this as a "reliability" finding
(reflection brittleness, no `event_version`). The security framing — the
relay will autoload and instantiate **any FQCN** in the project given
a single column write — was not in the audit. Listed here so it is not
lost in the reliability bucket.

### N3. `AuditMiddleware::redact` sensitive-key list diverges from `RedactingProcessor`; password commands serialise plaintext into `audit_log.digest`

The audit flagged "AuditMiddleware sensitive-key list diverged from
RedactingProcessor" (consolidated HIGH 10) but understated the
exploitability.

- `app/Infrastructure/Bus/Middleware/AuditMiddleware.php:140-151` —
  `extractPublicState` enumerates **public** properties via reflection.
- `app/Infrastructure/Bus/Middleware/AuditMiddleware.php:157-193` — local
  `$sensitive` list is `[password, token, jwt, authorization, api_key,
  secret, private_key, credit_card, card_number, cvv, plaintext]`. It is
  **missing** `new_password`, `old_password`, `current_password`,
  `password_hash`, `refresh_token`, `access_token`, `password_confirm`.
- The match is `str_contains($needle, $marker)`, so `new_password` is
  caught by the substring `password`. OK there. But `refresh_token`,
  `access_token`, `password_hash` are caught by `token`, `token`,
  `password` respectively — substring matching saves it.
- `current_password` is also caught by `password`. So in fact, the list
  IS sufficient for the audit's listed command shapes. The audit's
  framing "missing password_hash / new_password / refresh_token" is
  **incorrect** — substring matching catches all of them.
- BUT: `plaintext` is only caught when the key literally contains
  "plaintext". `HashedPassword::fromPlaintext($command->password)` is
  invoked from handlers; the command property is named `password`, which
  is caught. OK.
- The real residual hole is `RedactingProcessor` does **not** redact
  `$record->message` interpolations (consolidated MEDIUM 19, RedactingProcessor
  finding in 13-http). A logger call `$logger->info("Login attempted with password=$pw")`
  leaks plaintext. None of the audited handlers does this, but the
  protection against future regressions is missing — value-pattern JWT
  redaction and message-string scanning are not implemented in
  `app/Infrastructure/Logging/RedactingProcessor.php:52-58`.

Net: this is **not** a "audit list is broken" issue; it is a "message
interpolation is not scanned" issue. The audit's framing should be
corrected; the underlying gap is real but lower-severity than stated.

### N4. `PermissionService::rbacCheck` and `legacyAdminCheck` both swallow `\Throwable` → silent fail-secure for non-admins, fail-open for legacy admins on DB outage

Already enumerated under C5 but worth restating: the combination at
`PermissionService.php:76-78` (legacy returns `false` on throw) +
`:95-97` (rbac returns `false` on throw) yields inconsistent failure
modes when the DB is briefly unavailable. Legacy admins keep working
(because the legacy check happens first and short-circuits), RBAC users
all get 403. Operationally this looks like "the new RBAC system broke"
while admins remain unaffected — diagnosis is harder than fail-loud.

### N5. `LocalStorage::resolveKey` and `AttachmentService::buildKey` together permit cross-tenant prefix collision

Storage finding from the audit (12-storage HIGH on `LocalStorage::resolveKey:108-112`)
is correct. Additionally:

- `app/Infrastructure/Storage/AttachmentService.php:173-187` —
  `$attachableType` is an unvalidated free-form string. `buildKey`
  derives the directory prefix from it: `{type-slug}/{id-slug}/{uuid}-{name}`.
- A caller passing `attachableType='Invoice'` for tenant A and
  `attachableType='invoice'` for tenant B produces the **same** disk
  prefix (`invoice/...`). Without tenant scoping on disk
  (`tenant_id` is stored in the DB row but never appears in the storage
  key), `LocalStorage::get(storage_key)` returns the wrong tenant's
  bytes if `attachable_id` collides. Combined with the missing tenant
  scoping in `AttachmentService::read/delete/listFor` (audit 12-storage
  HIGH), this is a **cross-tenant file disclosure** chain.

Fix: prefix every storage key with `t{tenantId}/` and validate
`attachableType` against an enum. The audit caught both halves of this
chain separately but did not connect them.

### N6. `RedactingProcessor` and `AuditMiddleware::redact` substring matching false-negative for `ssn`, `iban`, `account_number`, `pin`, `bearer`, `client_secret`

Audit MEDIUM 19 / 13-http RedactingProcessor MEDIUM finding lists most of
these. Restating as security because in a multi-tenant ERP the absence of
`ssn`, `tax_id`, `account_number`, `iban` from the SENSITIVE list (`RedactingProcessor.php:31-50`)
will leak PII into logs the moment a domain that handles them is added.
This is not theoretical — `DocumentNumber` is already in the shared VOs.
Add these to the list before any domain that uses them ships.

### N7. `JwtAuthenticationMiddleware` device fingerprint is `sha256(ip|user_agent)` with no server-side secret

Audit HIGH covered this (`JwtAuthenticationMiddleware.php:394-398`).
Restating as confirmed: the fingerprint is not HMAC-keyed, IP comes from
`getIPAddress()` which honours `X-Forwarded-For` when `proxyIPs` is
configured. An attacker who can read web access logs (typical
mis-configured CDN log bucket scenario) plus has stolen the access token
can forge the matching fingerprint trivially. Fix proposal (HMAC with
server secret + per-session nonce stored in `sessions` row) is correct.

---

## Fix-correctness check

| Audit fix | Closes the hole? | Notes |
|---|---|---|
| `role:admin` on `admin/users/*` (consolidated #13) | **Partial.** Closes IDOR. Does NOT close mass assignment — a future admin user can still set `role=admin` on others, but that is intended. The genuine residual is that `UpdateUserCommand` accepts `role`/`status` from the web form regardless of caller. Add a separate "change role" command/permission, or drop those fields from the web update payload. | C1 |
| `class_exists` + reflection in outbox (theme #10 listed as reliability) | Audit did not propose a security fix. Required: namespace allow-list + `is_subclass_of(DomainEventInterface::class)` + a registered event class map. | C2 |
| Insert refresh into `refresh_tokens` at login + consult blacklist on refresh (09-auth) | Yes, closes both replay seams. Confirm `revokeRefreshToken` returns rows-affected so an unknown jti fails loud, not silently. | C3 |
| Move blacklist to Redis with TTL derived from `AUTH_REFRESH_TOKEN_TTL` | Yes. Also DELETE the counter / cleanup-counter logic in `TokenBlacklistService.php:108,136-137,165-169` — it is wrong and stays wrong on Redis (Redis already does TTL eviction). | C4 |
| Gate legacy admin shim behind env flag + audit log + remove `Actor::isSystem() → return true` default-allow | Yes for the shim. The system-actor default-allow needs an additional change: `PermissionService::allows()` should require an explicit `SystemContext` token from CLI bootstrap, not "any actor with id=0". | C5 |
| `CURLOPT_PROTOCOLS = HTTP|HTTPS` on CurlHttpTransport | Yes for known schemes. Also add DNS rebinding defence (resolve once + pin) if any caller forwards user URLs. | latent SSRF |
| `LocalStorage::resolveKey` — segment-based `..` check + post-mkdir realpath | Yes, closes the realpath-of-nonexistent-parent bypass AND `report..final.pdf` false rejects. Worth also adding tenant prefix to the storage key (N5). | storage traversal |
| `EmailService::sendTemplate` enum/allow-list of templates | Yes. Should also strip `\r\n` from `$to`/`$subject` defensively (12-storage MEDIUM). | latent LFI / header injection |
| IdempotencyMiddleware "write before run + lock on key" | Yes for double-execution. Does NOT address N1 (anonymous actor namespace collision). | reliability + N1 |

---

## SQL injection / IDOR / mass-assignment / session / CSRF spot-checks

- **SQLi via search/pagination/settings APIs:** No SQL injection found.
  `CookieRepository::executeFindPaginated` (`:447-492`), `UserRepository::findPaginated`
  (`:157-224`), and `SettingsService::fetchRow` (`:129-149`) all use the
  CI4 builder's `->where()`, `->like()`, `->limit()` with bound parameters.
  `like('name', $searchTerm)` and `orLike('email', $searchTerm)` correctly
  parameterise the value; the only LIKE-wildcard concern is the consolidated
  HIGH 8 about no wildcard escaping (`%`, `_` in user input force full
  scans — DoS, not injection). The two raw-string predicates
  (`->where('deleted_at IS NULL')` at `CookieRepository.php:425,454` and
  `UserRepository.php:172,234,256,280`) are constant strings, not
  user-influenced. **No SQLi surface confirmed.**
- **IDOR via `/api/v1/users/{id}`:** correctly gated by the
  `role:admin` filter on the route group (`Routes.php:91`). Closed.
- **IDOR via `/admin/users/{id}`:** **OPEN** — see C1.
- **Mass assignment in `RegisterUserCommand` (web /admin/users):**
  `UserController::store` (`:121-162`) accepts `role` from POST and
  forwards it. With `web_auth` but no role gate, a logged-in customer can
  create new admins via POST `/admin/users` with `role=admin`. C1's
  partner hole.
- **Mass assignment in JSON API:** `app/Controllers/Api/UserController.php`
  not read here, but the route group is `['jwt', 'role:admin', 'idempotency']`,
  so only admins reach the controller. Safe by route gate.
- **CSRF:** correctly enabled globally in non-testing environments
  (`Filters.php:95`). The "globally off in testing" is a real footgun
  (consolidated CRITICAL 13 second half) — a single mis-configured
  `CI_ENVIRONMENT=testing` deploy turns CSRF off site-wide. Fix proposal
  (runtime-assert env != testing in production) is correct.
- **Session fixation:** `LoginUserHandler::handle` calls
  `session_regenerate_id(true)` on success (`LoginUserHandler.php:94-96`).
  Closed for login. NOT closed for privilege change (audit MEDIUM under
  09-auth-infrastructure SessionAuthMiddleware finding) — a role flip
  during an active session keeps the old session id. Real but lower
  severity.
- **Token replay / timing oracle:** see C3 for token replay.
  `LoginUserHandler.php:57-85` does run a dummy Argon2 verify on
  user-not-found to equalise CPU time — that part is correct (audit's
  "timing oracle is backwards" claim, consolidated #18, refers to
  `RegisterUserHandler` not login). The DB lookup + log writes still
  differ measurably on the outer request (audit 09-auth HIGH on
  `LoginUserHandler`), so enumeration via wall-clock is reduced but not
  eliminated.
- **Permission bypass paths:** confirmed two — `Actor::isSystem() → return true`
  and the legacy admin shim. Both are documented above (C5).
- **Path traversal in `LocalStorage`:** confirmed (12-storage HIGH +
  N5). The `realpath`-on-nonexistent-parent fallback to `$baseDir`
  (`LocalStorage.php:108-112`) is exactly as the audit described.
  Substring `..` rejection (`:100`) is too coarse (rejects
  `report..final.pdf`) AND too narrow (does not split on separators).
- **Logging of secrets:** `RedactingProcessor` covers all common keys
  via substring match; `AuditMiddleware::redact` is aligned with it
  (the audit's claim of divergence is overstated — N3). The real gap
  is no scan of `$record->message`, no JWT-pattern matching in values
  under non-sensitive keys, and missing PII keys (`ssn`, `iban`,
  `account_number`) — N6.

---

## Overall security posture verdict

**Do not deploy.** The auth tier and the admin web tier each have at
least one fail-open default that turns "logged in as a customer" into
"administrative access to all users":

1. C1 — web admin routes missing `role:admin` + `UpdateUserCommand`
   mass-assigning `role` → self-promotion to admin, today, with CSRF
   token in hand.
2. C3 — refresh token replay at the login seam → stolen refresh tokens
   never invalidated.
3. C5 — `Actor::isSystem() → return true` makes every non-middleware
   caller of `PermissionService::allows` fail-open for unauthenticated
   actors.

Plus two CRITICALs the audit listed (C2 outbox arbitrary class
instantiation reframed from "reliability" to "security"; C4 non-durable
blacklist + fake capacity counter) and two HIGHs the audit missed (N1
anonymous idempotency namespace collision; N5 cross-tenant storage
prefix collision).

The audit's other security findings (no `iss`/`aud` verification,
unsalted device fingerprint, `LocalStorage` traversal-guard bypass,
`AuditMiddleware`-inside-`TransactionMiddleware` cascade, CSRF-off-in-testing)
are all real and all need fixing, but they are second-order — the three
above are the ones that turn an authenticated low-privilege user into
an attacker.

Fix order I would actually ship:

1. Block `admin/users/*` behind `role:admin` and drop `role`/`status`
   from `UpdateUserCommand` web inputs (C1).
2. Insert into `refresh_tokens` at login + consult blacklist on refresh
   + return rows-affected so unknown jti fails loud (C3).
3. Add `is_subclass_of(DomainEventInterface::class)` + namespace
   allow-list to `EventOutboxRelay::rehydrate` (C2). Either delete the
   relay code path entirely until you actually wire the writer, or gate
   it behind a registry.
4. Move blacklist to Redis and delete the counter/cleanup logic (C4).
5. Default-deny `Actor::isSystem()` in `PermissionService::allows`;
   gate the legacy admin shim behind an env flag with audit logging
   (C5).
6. Then the second-order list (HTTP `CURLOPT_PROTOCOLS`, `LocalStorage`
   segment-based traversal check, `EmailService` template allow-list,
   CSRF env-assertion, AuditMiddleware/Transaction cascade,
   IdempotencyMiddleware anonymous namespace).

Until items 1-5 are merged, "is the template safe to clone" is
unambiguously **no** on security grounds, independent of any of the
correctness/CQRS findings in the rest of the audit.
