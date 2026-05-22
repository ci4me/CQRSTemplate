# 14 — Config + spark commands

## Files audited
- `app/Config/Routes.php`
- `app/Config/Filters.php`
- `app/Config/Services.php`
- `app/Config/Events.php`
- `app/Config/App.php`
- `app/Config/ContentSecurityPolicy.php`
- `app/Config/Logging.php`
- `app/Config/Session.php`
- `app/Config/Database.php`
- `app/Config/Cookie.php` (referenced because it controls cookieSecure/HTTPOnly)
- `app/Commands/CleanupExpiredSessions.php`
- `app/Commands/CleanupPasswordResetTokens.php`
- `app/Commands/RebuildProjections.php`
- `app/Commands/RelayOutboxEvents.php`
- `app/Commands/WorkJobs.php`

## Routes findings

- **CRITICAL — Routes.php:8-10** `/`, `/dashboard`, `/health` are unauthenticated by route definition. `dashboard` is rescued only because `Filters.php:144` matches it under `web_auth`. `/` and `health` are intentionally public but there is no marker / comment distinguishing them from sensitive endpoints; future contributors can add operational pages here that bypass auth silently.
- **CRITICAL — Routes.php:17-25** Cookie domain web routes have NO `web_auth` filter at the route group level. Protection depends entirely on the URI-pattern match `cookies` / `cookies/*` in `Filters.php:142-143`. If anyone renames the segment or adds an alias (`/c`, `/cookie`), the filter silently breaks and the route stays open. Defence-in-depth would attach `['filter' => 'web_auth']` to the group.
- **HIGH — Routes.php:48-58** Same issue for `admin/users`. Relies on `admin/*` pattern in `Filters.php:144`. Also: admin operations are not gated by `role:admin`/`permission:*` on the WEB side — only the API group (line 91) enforces role. Any authenticated non-admin web user can hit `admin/users/*` and reach the controllers.
- **HIGH — Routes.php:35-41** Auth POST endpoints rely on global `csrf` filter (`Filters.php:95`). OK in production but the `else` branch sets `'except' => ['*']` for testing — fine — yet there is no per-environment runtime assertion that we are NOT in testing in production. A misconfigured `ENVIRONMENT` env var disables CSRF entirely.
- **HIGH — Routes.php:71-75** Public auth endpoints (`api/v1/auth/register|login|refresh|password/*`) have rate limiting but NO CSRF exemption documented. With the global CSRF filter active and these being POST endpoints with JSON bodies, browser-originated requests will fail unless the controller/Filter handles JSON specially (or CSRF is configured to skip JSON). Not visible from this file — needs verification in `Config\Security`.
- **MEDIUM — Routes.php:78-86** Protected JWT group only enforces `jwt` — no `role` or `permission` filter on session-revocation routes. A regular user can `DELETE sessions/all` (their own sessions only, presumably — but no guard here makes that explicit).
- **MEDIUM — Routes.php:91** `users` API group correctly chains `['jwt', 'role:admin', 'idempotency']` — but the order matters: `jwt` MUST run before `role` (Filters runs them in array order per route). OK as written.
- **LOW — Routes.php:9** `dashboard` route has no `(:num)` or validation — fine, but no rate limiting. Not security-critical.
- **LOW — Routes.php:10** `/health` is public. Standard. No info disclosure check possible without reading `HealthController`.
- **LOW — Routes.php** No explicit 404 / catch-all route. CI4 default handles it but if `permittedURIChars` (`App.php:108`) blocks a char the user gets a framework error, not a controlled 404.

## Filters findings

- **CRITICAL — Filters.php:139-148** The `filters` map for `web_auth` covers `cookies`, `cookies/*`, `admin/*`, `dashboard`. **Missing**: `auth/logout` (post-login action that should require an authenticated session), and any future top-level route. The pattern list is allow-by-default-deny-by-listed-pattern — backwards from a secure-by-default posture. Move `web_auth` to `globals.before` with an `except` clause listing the truly public routes (`/`, `health`, `auth/login`, `auth/register`, `auth/showLogin`, `auth/showRegister`, `api/v1/*`).
- **HIGH — Filters.php:95** CSRF skip rule `(ENVIRONMENT !== 'testing') ? [] : ['except' => ['*']]`. In testing the CSRF check is fully disabled. Security tests then need to bypass `ENVIRONMENT` to assert prod behaviour; the comment says they do but this still means a single misconfigured deploy with `CI_ENVIRONMENT=testing` disables CSRF site-wide. Belt-and-braces: add a runtime assert in `production` that `ENVIRONMENT !== 'testing'`.
- **HIGH — Filters.php:89-112** Order of globals.before: `csrf`, `correlation`, `locale`. **`correlation` should run FIRST** so the CSRF rejection path also carries a correlation id. As written, CSRF failures will produce logs with no correlation id — defeats observability for the exact case (CSRF attacks) you want to trace.
- **MEDIUM — Filters.php:139-148** `web_auth` only applies `before` — there is no `after` cleanup (session touch, last-active timestamp, idle-timeout enforcement). If `SessionAuthMiddleware` handles idle timeout only in `before`, a long-running request can outlive its session window.
- **MEDIUM — Filters.php:35-53** Aliases map declares `role`, `permission`, `web_auth` — but the global URI-pattern map only wires `web_auth`. `role` and `permission` are NEVER referenced as a global pattern — only inline per-route in `Routes.php`. That is fine for APIs but means web admin routes (`admin/users/*`) have NO role gate.
- **LOW — Filters.php:91-94** Commented out `honeypot` and `invalidchars`. Honeypot would catch form spam; `invalidchars` strips suspicious bytes. Document why they're disabled or enable them.
- **LOW — Filters.php:68-78** `required.before` has `forcehttps` and `pagecache`. Good. `forcehttps` requires `App::$forceGlobalSecureRequests = true` — which only happens in production via env check (App.php:17). In staging without `FORCE_HTTPS=true`, HTTPS is NOT forced.

## Services findings

- **HIGH — Services.php:163-201** `ensureProvidersRegistered` is called from inside `commandBus()/queryBus()/eventDispatcher()` when `$getShared=true`. It calls `static::getSharedInstance('commandBus')` again, which re-enters the same path. Saved only by the `$providersRegistered` flag. But the flag is set **AFTER** `ServiceProviderRegistry::registerAll(...)` returns; if any provider during registration calls `service('commandBus')` (very plausible — handlers may want the bus), it will recursively call `ensureProvidersRegistered`, NOT find the flag set, and re-enter forever. Need to set `self::$providersRegistered = true` BEFORE the call to `registerAll`, OR guard with a re-entrance bool.
- **HIGH — Services.php:90-113** `commandBus()` when `$getShared=true` returns the shared instance but **does not push middlewares** — middlewares are only pushed on the non-shared path (lines 105-110). On shared mode (the normal case) the bus is constructed by CI4's `getSharedInstance`, which calls `new CommandBus()` with NO middleware. That contradicts the comment block on lines 100-104 and means logging/transaction/audit middleware never run in production.
- **HIGH — Services.php:301, 316** `TokenBlacklistService` and `RateLimitService` are constructed with `\Config\Services::cache()` — calling the **framework's** cache service through the fully-qualified class. Inside `class Services extends BaseService`, `self::cache()` would route through the same container; using `\Config\Services::cache()` works but bypasses any local overrides and could cause double-instantiation if anyone overrides `cache()` here.
- **MEDIUM — Services.php:209-219, 227-238** `CookieRepository` and `UserRepository` are singletons. If a long-running CLI process (jobs:work, events:relay --watch) mutates internal state in the repo, subsequent calls reuse stale state. Verify these repos hold no per-request mutable state.
- **MEDIUM — Services.php:182-197** The `ServiceProviderRegistry::registerAll` is passed a flat associative array of dependencies. Adding a new service requires editing this map AND every provider. Not a security bug, but a circular-init landmine (next item).
- **MEDIUM — Services.php:181-197** Circular init risk: `eventDispatcher` is passed as both top-level argument AND inside the array. If any provider's constructor pulls another bus via `service(...)`, it re-enters `commandBus()` → `ensureProvidersRegistered()` → recursion guarded only by the late-set flag (see HIGH above).
- **LOW — Services.php:485-492** `LocaleResolver` is hard-coded `supported: ['en', 'pt-br']` — but `App::$supportedLocales = ['en']` (App.php:147). Drift between two sources of truth.
- **LOW — Services.php** No `idempotencyService` getter exists in this file but `IdempotencyMiddleware` is wired in `Filters.php:51`. Either the middleware constructs its store directly or relies on auto-wiring — verify outside this file.

## Other Config findings

### App.php
- **CRITICAL — App.php:43** `baseURL = 'http://localhost:8080/'` hard-coded with `http://`. If env doesn't override, `forceGlobalSecureRequests` will redirect to HTTPS but the configured baseURL is HTTP — causes mixed-content and broken absolute URLs in emails/CSP.
- **HIGH — App.php:14-19** `forceGlobalSecureRequests` only enabled when `CI_ENVIRONMENT=production` OR `FORCE_HTTPS=true`. Staging environments must explicitly set `FORCE_HTTPS=true`. Easy to forget.
- **MEDIUM — App.php:21-30** Trusted proxies parsed from env. No validation that `$proxyIP` is a valid IP/CIDR — a malformed string is silently set as the array key.
- **MEDIUM — App.php:147** `supportedLocales = ['en']` but `Services::localeResolver` declares `['en', 'pt-br']`. Inconsistent.
- **LOW — App.php:240** HSTS `max-age=31536000; includeSubDomains; preload`. Has `preload` — only valid if the domain is registered on the HSTS preload list; otherwise meaningless.
- **LOW — App.php:56** `allowedHostnames = []`. Any future multi-domain deployment will silently 404.

### ContentSecurityPolicy.php
- **CRITICAL — ContentSecurityPolicy.php:57** `scriptSrc = 'self'` — but `app/Views/layout.php:53` loads `cdn.jsdelivr.net/.../bootstrap.bundle.min.js`. **CSP will block bootstrap JS** in production. Either move bootstrap to local `/assets/` or add `https://cdn.jsdelivr.net` to `scriptSrc`.
- **CRITICAL — ContentSecurityPolicy.php:64** `styleSrc = 'self'` — same issue for `bootstrap.min.css` loaded from cdn.jsdelivr.net at `layout.php:24`. **CSP will block bootstrap CSS**.
- **HIGH — ContentSecurityPolicy.php:64 vs layout.php:39, _sidebar.php:36** Inline `style="..."` attributes used. With `styleSrc = 'self'` and `autoNonce = true` (line 175), inline `style` ATTRIBUTES require `'unsafe-inline'` or `style-src-attr` directive. They will be blocked. Need `styleSrcAttr = ["'unsafe-inline'"]` or remove the inline styles.
- **MEDIUM — ContentSecurityPolicy.php:25** `reportOnly = false` — CSP is enforce-mode without a `reportURI`. Violations are silently rejected; you'll never know what was blocked.
- **MEDIUM — ContentSecurityPolicy.php:31** `reportURI = null` — no monitoring endpoint at all.
- **LOW — ContentSecurityPolicy.php:50, 80** `defaultSrc` and `baseURI` not set — they default to `'self'` per CI4. Acceptable.
- **LOW — ContentSecurityPolicy.php:119** `frameAncestors` not set — relies on `X-Frame-Options` from `securityHeaders`. Modern browsers prefer CSP `frame-ancestors` over XFO.

### Cookie.php (cookieSecure/HTTPOnly)
- **MEDIUM — Cookie.php:14-19** `secure = true` only in production. Staging over HTTPS still uses insecure cookies unless explicitly fixed.
- **LOW — Cookie.php:104** `samesite = 'Lax'`. Reasonable default. Consider `'Strict'` for admin cookies.

### Session.php
- **HIGH — Session.php:24** `driver = FileHandler::class`. Files in `WRITEPATH . 'session'`. Multi-server deployments break (sessions not shared). For a CQRS template aimed at production this should default to `DatabaseHandler` or `RedisHandler` with a comment.
- **HIGH — Session.php:72** `matchIP = false`. Acceptable for mobile (NAT changes), but combined with non-regenerating sessions (line 92 `regenerateDestroy = false`) it weakens session hijack defence. The custom `SessionAuthMiddleware` may compensate — but Session.php itself is permissive.
- **MEDIUM — Session.php:43** `expiration = 7200` (2 hours). Reasonable.
- **MEDIUM — Session.php:92** `regenerateDestroy = false` — old session data persists after regeneration. After login, `regenerateID(true)` should be called by the controller; verify in `AuthController`.
- **LOW — Session.php** No `sessionCookieName` namespacing — `ci_session` is a default that fingerprints CI4.

### Database.php
- **CRITICAL — Database.php:42** `'encrypt' => false`. Production deployments connecting to a remote MySQL over the network will have credentials in cleartext. Comment acknowledges this but the default ships INSECURE.
- **HIGH — Database.php:36** `'DBDebug' => true` in `default`. In production this exposes query errors to clients on uncaught exceptions. CI4 normally guards via `ENVIRONMENT`, but the value is hard-set here regardless.
- **MEDIUM — Database.php:31, 171** Empty `password` defaults — relies on `.env` override. Acceptable but a forgotten env file silently connects with empty creds.
- **LOW — Database.php:174** `'DBPrefix' => 'db_'` in tests config — comment says "DO NOT REMOVE". OK.
- **LOW — Database.php:194-204** Test environment auto-switches to `tests` group. Good safety net.

### Logging.php
- **LOW — Logging.php:42** `queryLoggingLevel = 'errors'` is sensible production default. No issues.
- **LOW — Logging.php** Doc references env overrides (lines 22-27) but the class doesn't actually read them — properties are hard-set. The `BaseConfig` parent might apply env overrides; verify.

### Events.php
- **HIGH — Events.php:66-73** JWT secret presence check correctly gated on `production && !is_cli()`. **Testing IS skipped (good).** But: **CLI in production is skipped** — meaning `php spark` commands like `jobs:work` and `events:relay`, which DO need the JWT secret if they dispatch events that produce JWTs (unlikely but possible), will start with no validation. Document this expectation.
- **MEDIUM — Events.php:67** `getenv('JWT_SECRET_KEY')` — does not check `$_ENV` or `$_SERVER`. In containers using PHP-FPM with `clear_env=yes`, `getenv()` may miss vars set by the SAPI. Use `env('JWT_SECRET_KEY')` (CI4 helper) or both.
- **LOW — Events.php:45-54** Toolbar + HotReloader hooks are dev-only via `CI_DEBUG && !is_cli()`. Fine. Not documented in the file header — the only other "hook" beyond the toolbar is the JWT check.

### Secrets
- **HIGH — Database.php:31, 171** Password literal `''` — relies on env override. Empty-password connection is a known footgun.
- **OK — Events.php:67** `JWT_SECRET_KEY` read from env only. No literal secret.
- **OK — Cache.php:29** Redis password via `getenv('CACHE_REDIS_PASSWORD')`.
- **No literal secrets in `Services.php`** — all credential material flows through env or the env-aware Cache/Database configs.

## Spark command findings

### Shared bug across all 5 commands
- **HIGH — across all commands** `CLI::getOption('--flag') !== null` is used to detect a boolean switch. Per CI4 docs, `getOption()` returns `null` when the option is absent AND when the option is passed without a value (`--watch`); but in some versions `--watch` returns `true`. The check `!== null` is correct in most CI4 versions but will misbehave if a user passes `--watch=false` — that string `"false"` is truthy and the loop runs.
- **HIGH — across `RelayOutboxEvents.php`, `WorkJobs.php`** `--watch` loops have NO signal handling (`pcntl_signal(SIGTERM, ...)`). Under systemd / supervisord, `SIGTERM` will kill the process mid-batch, potentially leaving outbox rows or jobs in a half-claimed state. Add `pcntl_async_signals(true)` and a shutdown flag checked between passes.
- **MEDIUM — across all commands** No `set_time_limit(0)` for `--watch` modes. PHP CLI defaults to 0 normally, but php.ini overrides exist.
- **MEDIUM — across all commands** No structured logging on command start/end. Only `CLI::write(...)` to stdout — invisible to log aggregators.

### CleanupExpiredSessions.php
- **HIGH — CleanupExpiredSessions.php:53** `run(array $params): void` — returns void. CI4's `BaseCommand::run` signature expects `int` to signal exit code. Returns `void` means PHP returns `null`, which CI4 treats as exit 0 even on error paths. The "no expired sessions" path (line 73-75) returns early successfully — fine — but if `$db` connection fails an exception bubbles and never produces a clean non-zero exit.
- **HIGH — CleanupExpiredSessions.php:55** `$dryRun = CLI::getOption('dry-run') !== null` — same bug class as above.
- **MEDIUM — CleanupExpiredSessions.php:68-70** `countAllResults(false)` then `delete()` separately — race: another worker can delete rows between count and delete, so the displayed count and actual `$affectedRows` differ. Cosmetic.
- **LOW — CleanupExpiredSessions.php:65** `\Config\Database::connect()` — direct framework call. Inside a command this is fine.

### CleanupPasswordResetTokens.php
- **HIGH — line 53** Same `void` return type — should be `int`.
- **HIGH — line 55** Same `getOption` boolean bug.
- **MEDIUM — line 68-70** Same race-window between count and delete.
- **OK** Structurally identical to `CleanupExpiredSessions` — bugs are duplicated, not divergent.

### RebuildProjections.php
- **MEDIUM — RebuildProjections.php:50** `$target = (string) ($params[0] ?? '');` — accepts positional arg only. No `getOption` fallback. Documented usage is `projections:rebuild cookie` — fine.
- **MEDIUM — RebuildProjections.php:69-72** `rebuildFromSource(function () use (&$batches): void {...})` — callback uses by-ref `&$batches`. If the callback runs across PHP threads (it doesn't here, but defensively) the captured ref is unsafe. Fine for CLI.
- **MEDIUM — RebuildProjections.php:80-90** Hard-coded `if ($name === 'cookie')` — every new domain must edit this command. Acknowledged in the comment but flags a maintenance landmine.
- **LOW — RebuildProjections.php:62** `$projection->truncate()` followed by `rebuildFromSource()` — the projection is unavailable during the rebuild window. Comment on line 26-27 acknowledges. Could be done in a transactional swap (build into `_new` table, atomic rename) — out of scope.
- **OK** Returns `int` (0 / 1).

### RelayOutboxEvents.php
- **HIGH — RelayOutboxEvents.php:55** `$watch = CLI::getOption('watch') !== null` — same bug.
- **HIGH — RelayOutboxEvents.php:63-76** `do { ... } while ($watch);` with `sleep($sleep)` only when `processed === 0`. When processed > 0 the loop runs flat-out with no sleep — fine for throughput, but no backpressure on a runaway producer. Also no signal handler — `SIGTERM` mid-pass leaves rows claimed.
- **MEDIUM — RelayOutboxEvents.php:54** `$batch = (int) (CLI::getOption('batch') ?? 50)` — no lower bound. `--batch=0` would call `$relay->drain(0)`, infinite loop with no processed rows.
- **MEDIUM — RelayOutboxEvents.php:56** `$sleep = max(1, (int) (CLI::getOption('sleep') ?? 1));` — good, has lower bound.
- **OK** Returns `int 0`.

### WorkJobs.php
- **HIGH — WorkJobs.php:55** Same `--watch` boolean bug.
- **HIGH — WorkJobs.php:60-74** Same no-signal-handler issue as `RelayOutboxEvents`. Jobs claimed mid-execution could be stuck `processing`.
- **MEDIUM — WorkJobs.php:53** `$queue = (string) (CLI::getOption('queue') ?? 'default');` — no allowlist of queue names. A typo like `--queue emial` silently drains a non-existent queue forever (always 0 processed → sleep loop).
- **MEDIUM — WorkJobs.php:54** Same `--batch=0` infinite loop risk.
- **OK** Returns `int 0`.

## Verdict

CRITICAL items:
1. **Routes.php:17-58** — domain routes rely on URI-pattern filter rather than route-group-level `web_auth`. Defence-in-depth gap.
2. **Filters.php:139-148** — secure-by-default inversion (deny-listed instead of allow-listed) means new routes are auth-open by default.
3. **ContentSecurityPolicy.php:57, 64** — `scriptSrc`/`styleSrc = 'self'` conflicts with Bootstrap CDN in `layout.php:24, 53`. CSP will block all front-end styling/JS in production.
4. **App.php:43** — `baseURL` hard-coded HTTP.
5. **Database.php:42** — `encrypt => false` default ships insecure.

HIGH items:
- **Services.php:90-113** — middleware never pushed on shared (default) commandBus; logging/transaction/audit silently disabled.
- **Services.php:163-201** — re-entrance window in `ensureProvidersRegistered`.
- **Filters.php:89-112** — `correlation` runs after `csrf`; CSRF failures untraceable.
- **Routes.php:48-58** — web admin routes have no role gate.
- **Session.php:24** — FileHandler default kills multi-server deployments.
- **Events.php:67** — `getenv()` instead of `env()`; misses some SAPI configs.
- **All spark commands** — `--watch` loops lack signal handlers; cleanup commands return `void` instead of `int`.

The wiring layer is functional but brittle: the auth posture is "open by default, close listed paths" which is the opposite of what a CQRS template should ship with, and CSP/HTTPS defaults are set such that a vanilla deploy is either insecure (no HTTPS in staging, plaintext DB) or broken (CSP blocks Bootstrap). Spark commands share a duplicated boolean-flag parsing bug and lack signal handling for production daemons.
