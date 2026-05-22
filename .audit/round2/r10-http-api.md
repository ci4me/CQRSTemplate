# Round 2 — r10: HTTP / API (inbound + outbound)

Scope verified against the actual code in:
- app/Config/Routes.php
- app/Config/Filters.php
- app/Config/Security.php
- app/Config/Cors.php
- app/Infrastructure/Http/ApiResponse.php
- app/Infrastructure/Http/Middleware/IdempotencyMiddleware.php
- app/Infrastructure/Http/Client/OutboundHttpClient.php
- app/Infrastructure/Http/Client/CurlHttpTransport.php
- app/Controllers/Api/UserController.php
- app/Controllers/Api/AuthController.php
- app/Controllers/Home.php
- app/Controllers/HealthController.php
- app/Infrastructure/Auth/Services/ActorResolver.php
- app/Infrastructure/Auth/Middleware/RateLimitMiddleware.php
- app/Domain/Shared/ValueObjects/Actor.php

Round 1 references: `.audit/round1/13-http-bulk-logging.md`, `.audit/round1/14-config-spark.md`.

---

## Verified HTTP/API findings (round 1)

| # | Round-1 claim | Status | Notes |
|---|---|---|---|
| 1 | `CURLOPT_PROTOCOLS` unset → `file://` reachable (SSRF) | **VERIFIED** | `CurlHttpTransport.php:36–48` has no `CURLOPT_PROTOCOLS`/`CURLOPT_REDIR_PROTOCOLS`. libcurl default includes `file`, `dict`, `gopher`, `smb`, `ldap`. Any user-controlled URL is an SSRF vector. |
| 2 | `CURLOPT_FOLLOWLOCATION=false` → 3xx surfaces silently | **VERIFIED** | `CurlHttpTransport.php:40`. `OutboundHttpClient::request` does not flag 3xx; callers of `postJson()`/`request()` receive an unfollowed redirect with body empty. |
| 3 | SSL verification on | **VERIFIED — PASS** | `CurlHttpTransport.php:47–48` sets `CURLOPT_SSL_VERIFYPEER=true`, `CURLOPT_SSL_VERIFYHOST=2`. |
| 4 | `Retry-After` header ignored on 429/503 | **VERIFIED** | `OutboundHttpClient.php:125–128` uses only the fixed `backoffSeconds` schedule; `$response->headers['retry-after']` is never read. |
| 5 | Auto Idempotency-Key on retried POST | **VERIFIED** | `OutboundHttpClient.php:154–156`. Same key reused across retries (good for honoring servers), but POST is retried unconditionally with no opt-out, so a non-honoring remote service will double-create on transient 5xx. |
| 6 | Timeout enforced | **VERIFIED — PASS** | `CurlHttpTransport.php:41–42` sets `CURLOPT_TIMEOUT_MS` and `CURLOPT_CONNECTTIMEOUT_MS`. |
| 7 | `usleep` blocks worker; no jitter | **VERIFIED** | `OutboundHttpClient.php:185–195`. |
| 8 | IdempotencyMiddleware: lookup → execute → store (TOCTOU) | **VERIFIED** | `IdempotencyMiddleware.php:69–85, 106–127`. Two concurrent retries both miss `lookup`, both run the handler, second `insert` is rejected by the unique index. Idempotency guarantee is broken on the concurrent path. |
| 9 | IdempotencyMiddleware: replay loses headers (only Content-Type) | **VERIFIED** | `IdempotencyMiddleware.php:122–124, 211–219`. `Location:` (the *whole* reason for POST→201 replay), `ETag`, `Cache-Control` are all dropped. |
| 10 | Idempotency-Key regex restrictive | **VERIFIED — by design** | `IdempotencyMiddleware.php:147` matches `^[A-Za-z0-9._-]+$`, 8..128 chars. Safer than RFC, acceptable. |
| 11 | CSRF skip in testing → `*` | **VERIFIED** | `Filters.php:95`. A misconfigured `CI_ENVIRONMENT=testing` in prod silently disables CSRF site-wide. |
| 12 | `web_auth` filter is deny-listed (open-by-default) | **VERIFIED — CRITICAL** | `Filters.php:139–148` only protects `cookies`, `cookies/*`, `admin/*`, `dashboard`. Any new operational route is anonymous-accessible unless its segment is appended here. |
| 13 | Web `admin/users/*` has no `role:admin` gate | **VERIFIED — HIGH** | `Routes.php:48–58` is grouped only with `namespace`, no `filter` argument. Filtered globally only by URI pattern → web-tier admin endpoints are reachable by any authenticated user. |
| 14 | Auth POST endpoints rate-limited | **VERIFIED — PASS** | `Routes.php:37, 39, 71–75` apply `ratelimit:5,300` to login/register and tighter limits to password reset (3/300) and refresh (10/300). |
| 15 | `correlation` runs after `csrf` | **VERIFIED** | `Filters.php:90–102`. CSRF-rejected requests are logged without a correlation id. |

## New HTTP/API findings missed by round 1

### CRITICAL — every API controller bypasses `ApiResponse`
- `ApiResponse.php:46` claims: "Controllers should never hand-craft JSON; route everything through this helper".
- Reality: `grep -rln "ApiResponse" app/Controllers/` returns **zero matches**. Only `IdempotencyMiddleware` and `PermissionMiddleware` use it. Every controller (`Api\UserController`, `Api\AuthController`, the web `Domain\Cookie\CookieController`, etc.) hand-rolls `{success, message, data}` via `ResourceController::respond()` / `fail*()` helpers.
- Consequences:
  - The **RFC 7807 problem+json** envelope is never emitted on API errors. Clients get `{messages:{error:"..."}}` (CI4 default), not `{type, title, status, detail, errors}`.
  - **No `correlation_id` in any API response body.** It's only present in headers (set by `CorrelationIdMiddleware::after`). Round 1's audit of ApiResponse is correct in isolation but irrelevant to the deployed surface.
  - The pagination shape in `UserController::index` (`pagination: {total, page, perPage, totalPages}`) does not match the `ApiResponse::paginated` shape (`meta.pagination: {page, per_page, total, last_page}`) — clients cannot rely on either.
- **Fix**: Either (a) delete `ApiResponse` and document the actual envelope, or (b) refactor every controller to use it. The current setup is the worst of both worlds.

### HIGH — error responses leak exception messages
`Api\UserController.php:127, 176, 236, 296, 337, 392` all interpolate `$e->getMessage()` straight into the response body via `failServerError('… ' . $e->getMessage())`. Caught at top-level `catch (\Throwable $e)`.
- A SQL constraint violation surfaces the table name and column.
- A file-not-found in a template leaks the absolute path.
- A PHPStan-typed `TypeError` reveals internal class names.
- This is silent information disclosure on every 500 path. Web `Domain\User\UserController` does the same into flash messages, but at least those go to authenticated admins.

### HIGH — no CORS filter is actually wired
- `Cors.php` defines a thorough config (allowed origins, methods, credentials=false) and `Filters.php:41` aliases `'cors' => Cors::class`.
- But the alias is **never referenced** by `globals`, route-group `filter:`, or `filters:` URI pattern.
- API clients on `https://app.cqrstemplate.com` will receive no `Access-Control-Allow-Origin` header → browsers block the response. Preflight `OPTIONS` requests have no matching route and return 404. The config is dead.

### HIGH — `Idempotency-Key` reused across anonymous actors
- `IdempotencyMiddleware::actorId()` (line 150–153) delegates to `ActorResolver::resolve()`, which returns `Actor::system()` (id=0) for unauthenticated requests (verified `ActorResolver.php:33`, `Actor.php:36–39`).
- All anonymous users share `actor_id = 0`. Two unrelated anonymous clients reusing the same Idempotency-Key (e.g. a hard-coded library default, or a collision in a short key) will replay each other's responses. Round 1 worried `ActorResolver` would throw — it does not — but missed this cross-actor collision risk.
- Mitigation: either require an authenticated actor for idempotency, or include the client IP / a request-bound nonce in the scoping key.

### MEDIUM — CSRF is enforced globally but never exempted for `api/v1/*`
- `Filters.php:95` enables CSRF as a `before` global. There is no `except` clause for API routes and `Security.php` defines no `exceptUris`.
- For browser clients posting JSON to `/api/v1/auth/login` from a non-origin page (legit CORS scenario), CI4's CSRF filter will reject the request unless they include the `X-CSRF-TOKEN` header. Mobile / server-to-server clients cannot get this token without first issuing a GET.
- Either (a) exempt `api/v1/*` from CSRF and rely on bearer-token semantics (correct posture for JWT APIs), or (b) document and enforce double-submit cookie pattern. Currently the API works "by accident" because CSRF cookie auto-issues on GET — but a pure API client never issues a GET.
- Compounded by `Security.php:18` `csrfProtection = 'cookie'` and `Cors.php:65` `supportsCredentials = false`: with credentials disabled, the CSRF cookie cannot be sent cross-origin, so the legit CORS path is broken too.

### MEDIUM — `CurlHttpTransport` accepts non-finite timeouts
- `CurlHttpTransport.php:41–42`: `(int) round($timeoutSeconds * 1000)`. A caller passing `0.0`, `-1.0`, or `INF` will get `0`, `0`, or PHP warning + 0 respectively. `CURLOPT_TIMEOUT_MS=0` means **no timeout**.
- `OutboundHttpClient` defaults to `timeoutSeconds = 10.0`, but the field is public-constructor-injectable. Any consumer building the client with a misconfigured env value silently disables timeouts. Add a `max(0.1, …)` guard or reject ≤0 early.

### MEDIUM — `parseHeaders` collapses duplicates
`CurlHttpTransport.php:86–103` builds a flat `array<string, string>` keyed by lowercased name. Multiple `Set-Cookie` headers (common) collapse to the last one. Same for `WWW-Authenticate` on 401s with multiple challenges. `HttpResponse::header()` will return only the final value. Round 1 noted parseHeaders is "correct" for the trailing-header-block case but did not flag list semantics.

### MEDIUM — `OutboundHttpClient::request` logs full URL with query string
`OutboundHttpClient.php:115–123` logs `url => $url`. URLs commonly carry tokens (`?api_key=…`, `?access_token=…`). The `RedactingProcessor` (per round 1 finding) only redacts by key name, so a sensitive value embedded in the URL string survives. Log-side redaction must scan URL strings too, OR the client should sanitise queries before logging.

### MEDIUM — `Idempotency-Replayed` header is a leakage signal
`IdempotencyMiddleware.php:221` sets `Idempotency-Replayed: true` on replays — useful for clients but also tells unauthenticated probers that a key was previously valid for this actor, enabling key-enumeration. Acceptable trade-off but worth a docblock note; combined with `actor_id=0` bucketing for anonymous (above), the enumeration surface is real.

### MEDIUM — `noContent()` and other ApiResponse helpers bypass status semantics
- `ApiResponse::noContent()` returns a `Services::response()->setStatusCode(204)` — but CI4 `setStatusCode(204)` does NOT clear the body or content-type set by prior calls. If a controller already called `setJSON([])` then `ApiResponse::noContent()`, the body remains. Not exercised here (no controller calls it) but the API contract is fragile.
- `ApiResponse::created()` sets `Location` (line 73) but the `Location` value is not validated. A controller could pass an external URL → open redirect on 201. Defence-in-depth: reject scheme/host outside the app.

### LOW — `/health` is unauthenticated and unrate-limited
- `Routes.php:10` exposes `/health` to the world. `HealthController` returns `database: ok|fail` and `time`. No version, hostname, env leak.
- However: the endpoint **opens a DB connection on every hit** (`HealthController.php:64–69`). An attacker can drive DB-connection exhaustion by flooding `/health`. Add a small in-memory TTL cache (e.g. 5s) on the DB probe.
- Also returns `correlation_id` in body — fine, but enables passive trace correlation by any caller (probe).

### LOW — `dashboard` route is not protected at the route level
`Routes.php:9` `dashboard` relies on the `web_auth` URI-pattern match (`Filters.php:145`). If the route is renamed (e.g. `home/dashboard`) the URI pattern silently breaks. Same defence-in-depth gap as Cookie/admin (round 1) but worth listing for completeness.

### LOW — `auth/logout` (web) has NO `web_auth`
`Routes.php:40` POST `auth/logout` is inside the `auth` group, which has no filter, and the URI pattern in `Filters.php:139–148` does not match `auth/*`. An unauthenticated POST to `/auth/logout` will execute the controller (likely a no-op session-destroy) but exposes a CSRF-bait surface and emits unnecessary log noise. Add the route to `web_auth` or wrap the controller in a guard.

---

## Outbound HTTP security assessment

| Area | Status |
|---|---|
| Scheme allowlist (`CURLOPT_PROTOCOLS`) | **FAIL** — defaults; `file://`, `dict://`, etc. reachable. SSRF surface. |
| Redirect following | Disabled (`CURLOPT_FOLLOWLOCATION=false`). Safe-by-default for SSRF; but consumers receive opaque 3xx and may misinterpret. |
| SSL verify peer + host | **PASS**. |
| Timeout enforcement | Set, but `<=0` not guarded. **PARTIAL**. |
| Retry-After honour on 429/503 | **FAIL**. |
| Retry on non-idempotent POST without caller opt-in | **FAIL** (assumes remote honours Idempotency-Key). |
| Backoff jitter | **FAIL** (no jitter; thundering herd). |
| Correlation propagation (`X-Correlation-Id`) | **PASS** (auto-injected). |
| Sensitive data in logs | **PARTIAL** — URL with query string logged unredacted. |
| Body/headers leakage to log | URL is logged; body is not. **OK** for body; **leaky** for URL. |

Net: the outbound stack is **production-unsafe** for arbitrary URLs. Acceptable only for tightly controlled, internal-DNS-resolved upstreams. Lock to HTTP/HTTPS, enforce a host allowlist, sanitise URL query before logging.

---

## Inbound HTTP security assessment

| Area | Status |
|---|---|
| Auth posture | **Open by default** (`Filters.php:139–148` deny-lists known segments). |
| Route-level `web_auth` on `cookies/*`, `admin/*`, `dashboard` | **None at route level** — relies entirely on URI-pattern match. |
| Route-level `role:admin` on web admin routes | **MISSING** (`Routes.php:48–58`). Any authenticated user can reach. |
| API auth gating (`jwt`) on `users/*`, sessions | **PASS** (`Routes.php:78, 91`). |
| Rate limiting on login/register/reset/refresh | **PASS** (5/300, 3/300, 10/300). |
| CSRF on web POST | **PASS** (global) — but disabled wholesale in `testing` env. |
| CSRF exempt for `api/v1/*` JWT routes | **MISSING** — global CSRF applies to all POSTs. |
| CORS wired into filters | **MISSING** — `Cors` alias defined, never applied. |
| `ApiResponse` envelope used by controllers | **NEVER USED**. |
| RFC 7807 `problem+json` on errors | **NEVER EMITTED**. |
| `$e->getMessage()` leakage into responses | **YES**, across all API controllers and web flash messages. |
| `/health` rate-limited / cached | **NO**. |
| `auth/logout` (web) gated | **NO**. |

Net: the inbound surface ships with **secure-by-listing** auth (the inverse of the documented intent), broken CORS, dead-code envelope helper, and exception-message echo. A vanilla deploy is functional but exposes more than it intends.

---

## Verdict

The HTTP edges of this template are functional but **misaligned with the documented security model**.

**The single most important finding** is that `ApiResponse`, the documented "every API response goes through this" envelope, is **used by zero controllers**. Everything related to it from round 1 (correlation id in body, problem+json on errors, paginated shape) is moot until controllers are refactored.

Critical fixes (priority order):

1. **Switch `web_auth` to `globals.before` with an `except` clause** for `/`, `health`, `auth/login`, `auth/register`, `auth/showLogin`, `auth/showRegister`, `auth/password/*`, `api/v1/auth/{register,login,refresh,password/*}`. Move from deny-by-pattern to allow-by-explicit-exception.
2. **Add `role:admin` filter to the web `admin/users/*` route group**.
3. **Add `CURLOPT_PROTOCOLS = CURLPROTO_HTTP | CURLPROTO_HTTPS`** in `CurlHttpTransport` (one line — fixes SSRF for `file://`/`gopher://`/`dict://`).
4. **Refactor every `Api\*Controller` to use `ApiResponse`** OR delete `ApiResponse` and document the `ResourceController` shape as canonical. The current ambiguity guarantees envelope drift.
5. **Strip `$e->getMessage()` from controller error responses** in production. Pass a stable, redacted message; log the original with correlation id.
6. **Wire the `cors` filter** to `api/v1/*` route group, OR add it to `globals.before` and configure `supportsCredentials` consistently with `Security.csrfProtection`.
7. **Exempt `api/v1/*` from CSRF** (Bearer-token APIs don't need it, and the global rule currently blocks legitimate JSON-only clients).
8. **Honour `Retry-After` in `OutboundHttpClient`** for 429/503, and add jitter to `sleepBackoff`.
9. **Fix `IdempotencyMiddleware`**: insert a pending row in `before()` and update it in `after()` — eliminates the lookup→execute→store TOCTOU and the partial-header replay. Store ALL response headers (or at least `Location`, `ETag`, `Cache-Control`) in the JSON blob.
10. **Cache `/health` DB probe** for 5s to prevent connection-exhaustion via probing.

Mid-priority hardening: timeout floor in `CurlHttpTransport`; URL-query redaction before logging in `OutboundHttpClient`; `Set-Cookie` list semantics in `parseHeaders`; require non-anonymous actor for `IdempotencyMiddleware` OR scope by IP for `actor_id=0`.

Round 1 captured the structural issues accurately; the gap is in the wiring layer (what's actually called from controllers vs filters vs config), which is where the deployable risk lives.
