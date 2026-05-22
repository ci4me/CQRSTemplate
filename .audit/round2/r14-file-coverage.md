# Round-2 Review · r14 — File Coverage

Reviewer focus: did the 15 Round-1 agents collectively audit every important file in `app/` + `tests/`? Which files were left orphaned, which got audited twice, and what dense areas should have been split.

---

## Totals (PHP files)

| Tree | Count |
|---|---|
| `app/**/*.php` | 285 |
| `app/Views/**` (PHP templates) | 31 |
| `app/Database/Migrations/**` | 21 |
| `app/Config/**` | 43 |
| `tests/**/*.php` | 103 (of which 26 under `tests/_skipped/`) |
| **Total `app/` + `tests/` PHP** | **388** |
| Non-PHP audit-relevant (root) | `.githooks/{commit-msg,pre-commit,prepare-commit-msg,pre-push}`, `composer.json`, `phpunit.xml.dist`, `phpstan.neon*`, `phpcs.xml*`, `preload.php`, `phpstan-bootstrap.php` |

`app/Filters/` and `app/Libraries/` exist but are **empty** (no files to audit; the framework's default Filters live in `app/Config/Filters.php`).

---

## Round-1 coverage by report (what each agent actually opened)

| # | Report | Scope | Notes |
|---|---|---|---|
| 01 | `01-cookie-entity-vos.md` | `Cookie/Entities/Cookie.php`, `CookieName`, `CookiePrice`, `Cookie/ErrorCodes.php`, `Shared/AggregateRoot.php`, `Shared/ValueObjects/Money.php`, `Currency.php` | AggregateRoot, Money, Currency also touched by 02, 06, 07 |
| 02 | `02-cookie-commands.md` | 4 Cookie command/handler pairs + context (`CookieRepositoryInterface`, `CommandBus`, `Cookie.php`, `AggregateRoot`, `TransactionMiddleware`, `AuditMiddleware`, `CookieRepository`, `Actor`, every `Events/Cookie*Event.php`) | "Context" files re-read but not deeply audited |
| 03 | `03-cookie-queries-dto.md` | 3 Cookie query/handler pairs, `CookieView`, `QueryBus`, supporting `CookieRepository`, `CookieModel`, `CookieReadModelProjection`, `CookieController` | First and only report opening `CookieController.php`, but only for the read-side; create/update/delete actions of the controller were not audited |
| 04 | `04-cookie-events-projection.md` | 5 Cookie events + 4 event handlers, `CookieReadModelProjection`, `EventDispatcher`, `ProjectionInterface`, `ProjectionRegistry`, `CookieServiceProvider`, `RebuildProjections` | |
| 05 | `05-cookie-repository.md` | `CookieRepository`, `CookieModel`, `RepositoryLogging`, `BusinessMetricsLogging`, `CookieRepositoryInterface`, `CookieServiceProvider`, 2 migrations (`CreateCookiesTable`, `CreateCookieReadModelTable`) | |
| 06 | `06-shared-value-objects.md` | All 8 `Domain/Shared/ValueObjects/*` (Money, Currency, Actor, Permission, DocumentNumber, Email, DateTimeValue, AttachmentRef) | Money/Currency overlap with 01 |
| 07 | `07-shared-exceptions-aggregateroot-statemachine.md` | `Shared/Exceptions/{DomainException,ValidationException}`, `AggregateRoot`, all 3 `StateMachine/*`, consumer `Cookie.php`, both `ErrorCodes.php` registries | AggregateRoot overlap with 01, 02 |
| 08 | `08-user-domain.md` | Entire `Domain/User/**` (entity, 8 VOs, 4 cmd pairs, 4 query pairs, 4 events + handlers, 5 Ports), plus `Infrastructure/Persistence/{Models/UserModel.php,Repositories/{UserRepository,UserRepositoryInterface,PasswordHistoryRepository}.php}`, `UserServiceProvider`, `User/ErrorCodes.php`, migration `CreateUsersTable`, `Domain/User/UserController.php` (web), Routes+Filters context. **Two VOs explicitly "referenced, not opened"**: `AccessToken.php`, `Domain/User/ValueObjects/AuthenticationResult.php`. | Densest single report — see "should have been split" |
| 09 | `09-auth-infrastructure.md` | Entire `Infrastructure/Auth/**` (5 middleware, 10 services, 1 adapter, 5 cmd pairs, 3 VOs, `AuthServiceProvider`), `Infrastructure/Security/TimingSafeComparison`, `Controllers/Domain/Auth/AuthController` + `Controllers/Api/AuthController` (both "context"), `Config/{Filters,Routes,Cache,Security}` context | Auth controllers only "context"-grade |
| 10 | `10-bus-middleware.md` | `Bus/{CommandBus,QueryBus,EventDispatcher,CommandMiddlewareInterface}`, 3 `Bus/Middleware/*`, `CreateAuditLogTable` migration, `Services.php` (cross-ref), `RedactingProcessor` (cross-ref) | Services.php main audit happens in 14; small overlap |
| 11 | `11-outbox-jobs-numbering-projections.md` | `Outbox/{EventOutboxWriter,EventOutboxRelay}`, `Jobs/{JobHandlerInterface,JobQueue,JobWorker}`, `Numbering/DocumentNumberingService`, `Projections/{ProjectionInterface,ProjectionRegistry}`, all 3 spark commands for these (`RelayOutboxEvents`, `WorkJobs`, `RebuildProjections`), 3 migrations (`event_outbox`, `document_sequences`, `jobs`) | ProjectionInterface/Registry/RebuildProjections also touched by 04, 14 |
| 12 | `12-storage-notif-settings-i18n-email.md` | All 4 `Storage/*`, all 3 `Notifications/*`, `Settings/SettingsService`, both `I18n/*`, `Email/EmailService`, `Views/emails/layout.php` + `Views/emails/auth/password_reset.php` | |
| 13 | `13-http-bulk-logging.md` | `Http/ApiResponse`, all 5 `Http/Client/*`, `Http/Middleware/IdempotencyMiddleware`, all 5 `Bulk/*`, all 6 `Logging/*` | |
| 14 | `14-config-spark.md` | 10 `Config/*` (Routes, Filters, Services, Events, App, ContentSecurityPolicy, Logging, Session, Database, Cookie), all 5 `app/Commands/*` (incl. `CleanupExpiredSessions`, `CleanupPasswordResetTokens`) | Other ~33 Config files NOT opened |
| 15 | `15-views-helpers-migrations-tests.md` | 14 view files (layout, layouts/shell, layouts/auth, 6 partials, dashboard, cookies/index, admin/users/index, auth/login, auth/register), `Helpers/auth_ui_helper.php`, `public/assets/{auth.css,delete-confirm.js}`, **the 21 migrations "listed"** (treated as a flat batch — only the migration *list* is enumerated; individual schemas not audited except for `users` in report 08 and 2 cookie tables in report 05), `tests/Support/{UnitTestCase,IntegrationTestCase,FeatureTestCase}.php`, `tests/Support/Factories/{Cookie,User}Factory.php`, `tests/bootstrap.php`, `phpunit.xml.dist` | |

---

## ORPHANED FILES (no Round-1 report audited them)

### Controllers — partial coverage, large gaps

- **`app/Controllers/BaseController.php`** — orphaned. The parent of every domain controller; sets up helper loading, `$session`, security defaults. A defect here taints every request.
- **`app/Controllers/Home.php`** — orphaned. The default landing page handler (`/` route). Public, unauthenticated.
- **`app/Controllers/HealthController.php`** — orphaned. Report 14 explicitly punted: *"No info disclosure check possible without reading HealthController."* `/health` is public — needs an audit of what it returns (DB creds? version? internal service status?).
- **`app/Controllers/Api/UserController.php`** — orphaned. The JWT-protected REST surface for `users` (Routes line 91, `['jwt','role:admin','idempotency']`). Report 08 audited only the web `Domain/User/UserController.php`, never the API one.
- **`app/Controllers/Api/AuthController.php`** — listed as "context" by report 09 but no findings recorded against it. Effectively orphaned for findings purposes.
- **`app/Controllers/Domain/Auth/AuthController.php`** — same status: "context" in report 09, two findings drive-by (`:94-97`, `:81`) but no systematic audit.
- **`app/Controllers/Domain/Cookie/CookieController.php`** — only the read-path methods (`index`, `show`) were touched in report 03. Create/update/delete/restore actions, CSRF handling, validation orchestration in the controller, redirect logic — never audited.
- **`app/Controllers/Domain/User/UserController.php`** — report 08 listed it but findings concern only routing/filter behaviour. The controller's action methods, view/Redirect orchestration, CSRF on POST endpoints — not audited.

### Database/Seeds

- **`app/Database/Seeds/CookieSeeder.php`** — orphaned. No report referenced it. The only seeder in the project. Defects propagate as cloned domain seed templates.

### Migrations

Report 15 "listed" the 21 migrations but did not audit individual schema choices. Report 05 audited the two cookie tables, report 08 audited `users`, report 10 audited `audit_log`, report 11 audited `event_outbox` / `document_sequences` / `jobs`. **The following migrations are orphaned:**

- `2025-10-26-135201_CreateRateLimitAttemptsTable.php`
- `2025-10-26-151606_AddNameToUsersTable.php` (small, but indexing / collation choices unverified)
- `2025_10_27_102358_CreatePasswordHistory.php` (referenced by 08 only via repository)
- `2025_10_27_104000_CreateRefreshTokensTable.php`
- `2025_10_27_104100_CreateTokenBlacklistTable.php`
- `2025_10_27_104200_CreatePasswordResetTokensTable.php`
- `2025_10_27_105000_CreateSessionsTable.php`
- `2025_10_27_105100_CreateLoginAttemptsTable.php`
- `2025_10_27_105200_CreateSecurityEventsTable.php`
- `2026-05-19-200100_CreateIdempotencyKeysTable.php`
- `2026-05-19-200200_CreatePermissionsSchema.php` (the RBAC schema — wholly unaudited despite being central to authorization findings in 08/09)
- `2026-05-19-200500_CreateSettingsTable.php`
- `2026-05-20-100000_CreateAttachmentsTable.php`
- `2026-05-20-100100_CreateNotificationsTable.php`

### Config — 33 of 43 files orphaned

Report 14 audited 10 Config files (`Routes`, `Filters`, `Services`, `Events`, `App`, `ContentSecurityPolicy`, `Logging`, `Session`, `Database`, `Cookie`). **The other 33 are orphaned**, including notable ones:

- `Config/Cache.php` — referenced once in report 09 (`:45 handler = file`) but never audited. Token blacklist, rate limiter, idempotency all sit on the cache backend.
- `Config/Encryption.php` — orphaned. Encryption key / cipher configuration.
- `Config/Email.php` — orphaned. SMTP defaults, transport, from-address.
- `Config/Validation.php` — orphaned. CI4 validation rule classes.
- `Config/Security.php` — listed as "context" in 09; report 14 punts to it for CSRF/JSON behaviour (`Routes.php:71-75` finding). No audit recorded.
- `Config/Mimes.php`, `Config/Format.php` — orphaned. Both relevant to attachment/upload finding (report 12) and API content-negotiation findings.
- `Config/Boot/{development,production,testing}.php` — orphaned. Per-environment bootstrap; affects display_errors and exception traces.
- `Config/Autoload.php` — orphaned. PSR-4 mappings, helpers loaded globally.
- `Config/Migrations.php`, `Config/Generators.php`, `Config/Modules.php` — orphaned.
- `Config/Honeypot.php`, `Config/Pager.php`, `Config/Images.php`, `Config/Toolbar.php`, `Config/Optimize.php`, `Config/CURLRequest.php`, `Config/Cors.php`, `Config/UserAgents.php`, `Config/View.php`, `Config/DocTypes.php`, `Config/Publisher.php`, `Config/Feature.php`, `Config/Routing.php`, `Config/Paths.php`, `Config/Exceptions.php`, `Config/ForeignCharacters.php`, `Config/Kint.php`, `Config/Logger.php`, `Config/Constants.php` — orphaned (most are CI4 defaults but should still get a "no deviations from stock" sign-off).

### Infrastructure — small gaps

- **`app/Infrastructure/Cache/Services/CacheHealthCheck.php`** — orphaned. Only `Infrastructure/Cache/*` file; never opened. Likely backs HealthController.
- **`app/Infrastructure/Attributes/DomainServiceProvider.php`** — orphaned. The PHP 8.4 attribute that drives auto-discovery. Findings in 04/14 reference the *registry* (`ServiceProviderRegistry`) but never the attribute class itself.
- **`app/Infrastructure/ServiceProvider/DomainServiceProviderInterface.php`** — orphaned. The interface itself; report 04 proposes adding a method to it but never audits its current shape.
- **`app/Infrastructure/ServiceProvider/ServiceProviderRegistry.php`** — orphaned at code level. Report 14 audits *consumers* (`Services.php:163-201`) but never opens the registry file itself. Two tests exist (`ServiceProviderRegistryTest`, `ServiceProviderRegistryTokenizerTest`) confirming non-trivial logic.
- **`app/Common.php`** — orphaned. Global helper bootstrap; auto-loaded.
- **`app/Domain/User/ValueObjects/AccessToken.php`** — report 08 explicitly says *"referenced, not opened"*.
- **`app/Domain/User/ValueObjects/AuthenticationResult.php`** — same: *"referenced, not opened"*. Note: there is a second `AuthenticationResult.php` under `Infrastructure/Auth/ValueObjects/` that **was** audited by 09 — the two-VO duplication is itself a flag report 08 hinted at but did not state outright.

### Views — partial

Report 15 audited 14 of 31 view files. **Orphaned views:**

- `Views/cookies/{create,edit,show}.php` (3 files)
- `Views/admin/users/{create,edit,show,reset_password}.php` (4 files)
- `Views/welcome_message.php`
- `Views/errors/html/{error_400,error_404,error_exception,production}.php` (4 files)
- `Views/errors/cli/{error_404,error_exception,production}.php` (3 files)

Report 15 found CSP-violating inline styles and missing permission gating on index views — the unaudited create/edit views are likely to repeat the same patterns and need a separate pass.

### Language

- **`app/Language/en/App.php`** — orphaned.
- **`app/Language/en/Validation.php`** — orphaned.
- **`app/Language/pt-BR/App.php`** — orphaned. Report 14 (`Services.php:485-492`) and 15 noted locale-switcher coverage gaps but no report verified the message catalogues themselves (missing keys, untranslated strings, ICU plural rules).

### Tests — large gap

Report 15 audited only `tests/Support/` and `tests/bootstrap.php`. **Every `Unit/`, `Integration/`, and `Feature/` test file is orphaned.** This includes:

- 33 Unit tests (Cookie, User, Shared, Infrastructure)
- 15 Integration tests (Auth, Jobs, Notifications, Numbering, Outbox, Projections, Repositories, Security, Settings, Storage)
- 6 Feature tests (`CookieCrudTest`, `EmailTemplatedTest`, `HealthEndpointTest`, `AuthLayoutTest`, `ShellLayoutTest`)
- The `tests/_skipped/AbiSageIntacct/**` tree (26 files) — never mentioned in any report. Worth a single line: "is this dead code we should delete, or paused work?"
- `tests/_support/{bootstrap_libraries,Database/Seeds/ExampleSeeder,Libraries/ConfigReader,Models/ExampleModel}.php` — orphaned. Report 15 only mentions the directory-casing mix (`_support` vs `Support`) but does not audit contents.
- Lowercase test dirs `tests/unit/HealthTest.php` and `tests/session/ExampleSessionTest.php` — orphaned and likely the casing-collision flagged in report 15.
- `tests/Integration/Outbox/OutboxTestEvent.php`, `tests/Integration/Jobs/TestJobHandler.php` — small test fixtures, orphaned.

### Root / tooling

- **`phpstan-bootstrap.php`** — orphaned. Bootstraps PHPStan analysis; PHPStan-level-8 claim depends on it.
- **`preload.php`** — orphaned. OPcache preload script; affects every production request.
- **`.githooks/{commit-msg,pre-commit,prepare-commit-msg,pre-push}`** — orphaned. The pre-commit hook is wired by `composer setup-hooks` and is the developer's only local quality gate.
- **`composer.json` `scripts` section** — orphaned. Defines `check`, `ci`, `gitleaks`, `setup-hooks`. The `gitleaks` script silently passes when the binary is absent (`gitleaks-not-installed-skipping`) — a secret-detection bypass surface.
- **`phpunit.xml.dist`** — only listed by report 15, no findings.
- `phpcs.xml*`, `phpstan.neon*` — orphaned (not in `app/` but central to the quality claims).

---

## OVERLAPPED FILES (audited by two or more reports — wasted effort or warranted cross-check?)

| File | Reports | Verdict |
|---|---|---|
| `Domain/Cookie/Entities/Cookie.php` | 01 (primary), 02 (context), 07 (consumer) | Justified — entity is consumed by handlers and exceptions |
| `Domain/Shared/AggregateRoot.php` | 01, 02 (context), 07 | Some overlap; 07 was the deeper read |
| `Domain/Shared/ValueObjects/Money.php`, `Currency.php` | 01, 06 | Mild waste — both go deep on Money. 06 is the canonical pass |
| `Domain/Cookie/ErrorCodes.php`, `Domain/User/ErrorCodes.php` | 01, 07, 08 | Three readers; only 07 + cross-cutting theme #7 captured the duplicate-constant defect |
| `Infrastructure/Bus/EventDispatcher.php` | 04, 10, 11 | Three reports; same finding (swallows `\Throwable`) restated. Could have been a single owner |
| `Infrastructure/Bus/Middleware/TransactionMiddleware.php` | 02 (context), 10 (primary), 11 (cross-ref) | Justified — central to transactional guarantee debate |
| `Models/Cookie/CookieRepository.php`, `CookieModel.php` | 02 (context), 03 (supporting), 05 (primary) | Some redundancy; 05 is the deep dive |
| `Domain/Cookie/Projections/CookieReadModelProjection.php` | 03, 04, 11 | Three angles (read consumer, projection wiring, ProjectionRegistry). All three add value |
| `Infrastructure/Projections/{ProjectionInterface,ProjectionRegistry}.php` | 04, 11 | Justified — different concerns (wiring vs registry mechanics) |
| `Domain/Cookie/CookieServiceProvider.php` | 04, 05 | Justified — events vs repository wiring |
| `Commands/RebuildProjections.php` | 04, 11, 14 | Triple coverage; 11 is canonical |
| `Config/Routes.php`, `Config/Filters.php` | 08 (context), 09 (context), 14 (primary) | Justified — auth/filter findings need route context |
| `Config/Services.php` | 10 (cross-ref), 14 (primary) | Mild overlap |
| `Domain/Shared/Exceptions/{DomainException,ValidationException}.php` | 01 (consumer), 07 (primary) | Justified |
| `Controllers/Domain/Cookie/CookieController.php` | 03 only (read methods) | **Under-covered** despite multiple "supporting" mentions |

Net: roughly 8–10 files received 2–3 passes without clear delineation of concern. None of the overlap caused contradictory findings — usually the deepest report had the canonical finding and the others restated it. The cost is reviewer time, not correctness.

---

## Files that should have been SPLIT across multiple reports

1. **Report 08 (`User domain`)** is 17,591 bytes covering: entity + 8 VOs + 4 commands + 4 queries + 4 events + 5 ports + repository layer + service provider + error codes + migration + the **web UserController** + Routes/Filters context. That is essentially the entire "User domain" plus the entire "User UI" plus the "Auth-adjacent persistence layer". Two explicit VOs were even punted (`AccessToken`, `AuthenticationResult` — "referenced, not opened"). It should have been **08a (User entity/VOs/commands/queries), 08b (User events/ports/repository/provider), 08c (User controller/views/routes)**.

2. **Report 09 (`Auth infrastructure`)** is 24,833 bytes covering 5 middleware + 10 services + 1 adapter + 5 command/handler pairs + 3 VOs + service provider + `TimingSafeComparison`. That is ≈ 26 substantive files in one report. The two auth controllers were demoted to "context" and never properly audited. Should have been **09a (Auth services + adapter), 09b (Auth middleware + filter wiring), 09c (Auth commands + flows + controllers)**.

3. **Report 15 (`Views/Helpers/Migrations/Tests`)** is the omnibus dumping-ground: views + helpers + assets + 21 migrations *listed but not audited* + `tests/Support`. The migrations alone deserve a dedicated audit (only 6 of 21 received any detailed schema pass across all reports). Should have been **15a (Views + helpers + assets), 15b (Migrations — schema-by-schema), 15c (Test infrastructure)**.

4. **Report 11 (`Outbox/Jobs/Numbering/Projections`)** bundles four unrelated infrastructure subsystems. Each is non-trivial; each got 1–2 critical findings. A more focused split (Outbox+Projections | Jobs | Numbering) would have allowed deeper drill-down (e.g. `JobWorker` retry semantics, `DocumentSequences` schema check vs other databases).

5. **Report 14 (`Config/Spark`)** audited 10 of 43 config files. The other 33 should have been a separate "Config — stock CI4 diff" audit even if the answer is "no deviations from default".

---

## Recommended additional Round-2 audits (priority order)

1. **Controllers — full pass.** All 8 controllers, with emphasis on:
   - `Controllers/BaseController.php` (parent class — sets defaults for all)
   - `Controllers/HealthController.php` (public endpoint; info-disclosure)
   - `Controllers/Api/UserController.php` (admin-only REST surface)
   - The write-side methods of `Controllers/Domain/Cookie/CookieController.php`
   - Full audit of both `AuthController.php` (web + API) — only drive-by findings exist
   - `Controllers/Home.php`
2. **Migrations — schema-by-schema audit.** 14 of 21 migrations were never opened. Critical ones to start with: `CreatePermissionsSchema` (drives all RBAC), `CreateIdempotencyKeysTable`, `CreateSessionsTable`, `CreateRefreshTokensTable`, `CreateTokenBlacklistTable`, `CreateAttachmentsTable`, `CreateNotificationsTable`.
3. **Tests — every test file.** 88 of 103 test files are orphaned. Coverage and correctness of tests is the only safety net Round-1 raised. Focus first on the security/integration tests (`PenetrationTest`, `AuthenticationSecurityTest`, `PermissionServiceTest`) which the consolidated report cited as the only existing defence against the CRITICAL findings.
4. **Database/Seeds/CookieSeeder.php** — single file, but seeds clone-multiply.
5. **Config — stock-vs-modified diff.** 33 unaudited config files. A quick "diff vs `spark generate:config`" pass would suffice for most; `Cache.php`, `Encryption.php`, `Email.php`, `Security.php`, `Validation.php`, `Mimes.php`, `Boot/*.php` deserve a real read.
6. **Infrastructure micro-orphans.** `Common.php`, `CacheHealthCheck`, `DomainServiceProvider` attribute, `DomainServiceProviderInterface`, `ServiceProviderRegistry` (the implementation, not just consumers).
7. **`AccessToken.php` + both `AuthenticationResult.php` files.** Report 08 punted both; the duplication across `Domain/User/ValueObjects/` and `Infrastructure/Auth/ValueObjects/` is itself worth a finding.
8. **Views — create/edit/show forms + error pages.** Round 1 audited only the index/list views; the form views (7 files) are where CSRF, permission gating, and validation-error rendering live. Error pages (7 files) are where uncaught exceptions surface to end users.
9. **Language catalogues.** `App.php` (en + pt-BR) consistency, missing keys, ICU plural support.
10. **Root tooling: `.githooks/pre-commit`, `composer.json` scripts, `phpstan-bootstrap.php`, `preload.php`.** These define the quality gate the rest of the audit assumed was working. `gitleaks` script silently no-ops when the binary is missing — a real finding.
11. **`tests/_skipped/AbiSageIntacct/`.** 26 files. Either delete or document. Currently dead weight in the repo, polluting code-search and confusing new contributors.

---

## Summary

- Out of 388 PHP files in `app/` + `tests/`, Round-1 reports give **deep coverage to roughly 165 files** (≈ 43%), **mention as "context" without findings to roughly 25 files** (≈ 6%), and **leave roughly 198 files orphaned** (≈ 51%).
- The orphan rate is highly uneven: `Domain/Cookie`, `Domain/User`, `Domain/Shared`, `Infrastructure/Auth`, `Infrastructure/Bus`, `Infrastructure/Outbox`, `Infrastructure/Jobs` are well-covered. Controllers, tests, migrations, and 80% of `app/Config/` are under-covered. The audit is a domain-and-infrastructure audit; it is *not* yet a delivery audit.
- No orphan changes the Round-1 verdict ("NOT safe to clone"); several orphans likely *worsen* it (e.g. Permissions schema, write-side controllers, error pages, idempotency-keys schema), since Round-1 already flagged systemic problems in those areas and the corresponding files have not yet been opened.
- The 3 highest-leverage Round-2 audits, in order: (a) all controllers, (b) the 14 unaudited migrations — `CreatePermissionsSchema` first, (c) the security/integration test suite as Round-1's-only-safety-net validation.
