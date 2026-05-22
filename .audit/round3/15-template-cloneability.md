# 15 — Template Cloneability

**Slice:** Cookie's fitness as the reference template — naming, generics, scaffolding alignment
**Reviewer:** general-purpose
**Date:** 2026-05-22
**Scope:** the whole Cookie domain plus scaffolding skill, controller, model, migrations, views, doc pointers

## TL;DR

Cookie is **structurally a credible template**: the folder shape (Commands/Queries/Events/Ports/DTOs/ReadModels/ValueObjects/Services), named-factory pattern, port placement (`app/Domain/Cookie/Ports/`), `#[DomainServiceProvider]` auto-discovery, and self-registered routes are all clone-friendly. But running a mental `sed s/Cookie/Foo/g s/cookies/foos/g` over the tree reveals several **non-trivial leaks**:

1. The reference projection is a `.php.example` file, so a cloner who copies "everything under `app/Domain/Cookie/`" gets a non-active scaffold and a self-contradicting migration history (`CreateCookieReadModelTable` immediately followed by `DropCookieReadModelTable`).
2. Three pieces of "shared infrastructure" — `BusinessMetricsLogging` trait, `RepositoryLogging` trait, and `PriceFormatter` service — all live under Cookie namespaces but are *named* and *typed* against Cookie, so they cannot be reused without copy-and-edit per clone.
3. `CookiePrice` bakes USD-cents bounds (`MIN_MINOR_UNITS = 1`, `MAX_MINOR_UNITS = 999_999`, `Currency::default()`) into a class whose top-of-file docblock claims it pins "the currency choice at the boundary" — a cloner producing `InvoiceTotal` or `WageRate` will silently inherit retail-catalogue limits.
4. The scaffolding skill (`/add-domain`) and the file inventory disagree with reality: inventory says the repository lives at `app/Infrastructure/Persistence/Repositories/`, the SKILL.md says the same, but **the actual Cookie repo is at `app/Domain/Cookie/Repositories/`**. A cloner who follows the doc gets a directory that doesn't exist and a layout that doesn't match Cookie.
5. The scaffolding skill omits Restore (Cookie has it), ReadModels (Cookie has `CookieView`), ErrorCodes, Services, the Projection-as-example pattern, the two logging traits, the accessors trait, and the query-side port — it materially under-specifies what Cookie actually is.

## Verdict
NOT-READY — the template tells contradictory stories about its own state; cloning it today produces a domain that is structurally correct but technically broken in 4-6 predictable ways.

## Cookie-specific magic that wouldn't generalize
| file:line | item | why it leaks Cookie-specific semantics |
|-----------|------|---------------------------------------|
| `app/Domain/Cookie/ValueObjects/CookiePrice.php:22-23` | `MIN_MINOR_UNITS = 1`, `MAX_MINOR_UNITS = 999_999` | Hard-coded retail-catalogue upper bound ($9,999.99 in 2-decimal currencies). `Invoice::total` / `Salary::amount` / `OrderTotal` will all silently cap at $9,999.99 if cloned. Bounds belong in the *price's policy*, not the VO. |
| `app/Domain/Cookie/ValueObjects/CookiePrice.php:220-223` | `defaultCurrency() => Currency::default()` | Comment says "becomes configurable via SettingsService when multi-currency catalogues land" — i.e. never. A cloned `WageRate` for a Brazilian payroll domain silently gets USD. |
| `app/Domain/Cookie/ValueObjects/CookieName.php:39-40` | `MIN_LENGTH = 3`, `MAX_LENGTH = 100` | Generic "name" constraints, but the migration column is `VARCHAR(100)` — coupled, undocumented. A cloned `Address::line1` (typically `VARCHAR(255)`) needs both numbers and the column changed together. |
| `app/Models/Cookie/Traits/BusinessMetricsLogging.php:47, 67, 98` | `metricsThresholds['cookie']`, default `lowStockUnits=10`, `priceChangePercent=10.0` | Cookie-business thresholds baked into a trait whose name claims it's reusable. Comment at line 41-50 hand-waves "safe fallback so cloned domains that haven't yet added their own slice still get a sensible default" — that "sensible default" *is* a Cookie value applied to `Customer`/`Order` indiscriminately. |
| `app/Models/Cookie/Traits/BusinessMetricsLogging.php:7-8` | `use App\Domain\Cookie\Entities\Cookie;` + `use App\Domain\Cookie\ValueObjects\CookiePrice;` | The "shared" trait imports Cookie symbols at the top and types method parameters as `Cookie`/`CookiePrice` (line 30). Not reusable as written; every cloned domain copy-edits the file. |
| `app/Domain/Cookie/Services/PriceFormatter.php:22, 32` | `PriceFormatter::format(CookiePrice $price, ...)` | Stateless formatter typed against `CookiePrice`. Cloned `Invoice::formatTotal` / `Wage::formatAmount` either re-implement or take a dependency on the Cookie namespace. The logic itself is generic and should live in `Domain/Shared` typed against `Money`. |
| `app/Domain/Cookie/CookieServiceProvider.php:181` | `$logger = LoggerFactory::create('cookie.events');` | Static call inside provider — every cloned provider hard-codes its log-channel name as a string literal at registration time. No registry abstraction. |
| `app/Domain/Cookie/CookieServiceProvider.php:226` | `$routes->group('cookies', ['namespace' => 'App\Controllers\Domain\Cookie'], ...)` | Plural string literal `'cookies'` next to singular namespace `Cookie` — cloners need two text edits, with no compile-time link between them. |
| `app/Views/cookies/index.php:6, 27, 50, 92` | Hard-coded English strings "Cookies", "No cookies found.", "cookies" pluralisation, `Total: X cookies` | No i18n surface — every clone changes copy in 4 views × 4 files. View paths are also plural (`cookies/index.php`); Cookie's nominal plural is the same as singular + "s" but `Person`/`Child`/`Tax` will break. |
| `app/Database/Migrations/2025-01-21-000001_CreateCookiesTable.php:70-72` | `'price' => ['type' => 'DECIMAL', 'constraint' => '10,2']` | Hard-coded 2-decimal money column. A cloned domain with crypto/JPY/precision-sensitive values inherits the wrong shape silently. |
| `app/Domain/Cookie/Entities/Cookie.php:48-57` | private state: `name/description/price/stock/isActive` | These five fields are conflated as the *aggregate state*. Every cloned aggregate inherits "name + description + price + stock + isActive" as the implied minimum — even when the new domain (e.g. `AuditLogEntry`) has none of them. |
| `app/Domain/Cookie/DTOs/CookieDTO.php:21-31, 26` | DTO ships `formattedPrice` field + `isOutOfStock()` method | Both are Cookie-business. Generic DTO would only ship raw state; cloned `CustomerDTO` doesn't need either but copies them. |
| `app/Domain/Cookie/Repositories/CookieRepository.php:132-137` | "Cookie name must be unique within the tenant." literal | Error message literal carries the domain term. Fine for Cookie, but a cloned `User` already has its own (which is why the existing User repo duplicates the same shape). No shared error-message helper exists. |

## Half-built / abandoned features

- **`app/Domain/Cookie/Projections/CookieReadModelProjection.php.example`** — disabled (extension is `.php.example`, line 1-40). File documents how to re-enable but is not active code. PHPStan/PHPCS skip it. Tests do not exercise it. A cloner copying "everything" gets a documented-but-dead artifact.
- **`app/Database/Migrations/2026-05-20-200000_CreateCookieReadModelTable.php`** + **`2026-05-21-120000_DropCookieReadModelTable.php`** — migration history that *creates* a `cookie_read_model` table and one day later *drops* it. Both files exist. Net effect of running migrations on a fresh DB: no `cookie_read_model` table. A new cloner reading the history is confused; the pattern (create+drop next-day) is also brittle if any deploy ran between them.
- **`app/Domain/Cookie/Projections/`** directory contains only the `.example` file — no `.gitkeep`, no active projection. Skill `domain-scaffolding/SKILL.md` does not mention projections at all (lines 22-302), so cloners aren't told this slot exists.
- **`app/Domain/Cookie/ReadModels/CookieView.php:31-35`** — `$extra = []` constructor parameter "reserved for tenant_id, audit fields when those land in the view". Half-built abstraction.
- **`app/Domain/Cookie/ValueObjects/CookiePrice.php:101-108, 121-131`** — `getValue()` and `format()` are `@deprecated`. Deprecated methods still wired into the DTO factory (`CookieDTO::fromEntity` at line 44 calls `$cookie->getPrice()->format()`). Cloners inherit deprecation-on-active-codepath.
- **`app/Domain/Cookie/Entities/Cookie.php:38-39`** docblock says "CookieCreatedEvent is dispatched by the create handler (not the entity) because the event payload needs the freshly-allocated id" — this is the V1 defect from round-2 r03 (event-id-null). Still present. The entity raises `CookieUpdatedEvent` + `CookieStockChangedEvent` from itself but *not* `CookieCreatedEvent`/`CookieDeletedEvent`/`CookieRestoredEvent` — split-dispatch model unresolved.

## Scaffolding-vs-reality gaps

The scaffolding skill (`.claude/skills/domain-scaffolding/SKILL.md`) and the file inventory (`.claude/documentation/COMPLETE_FILE_INVENTORY.md`) both describe a Cookie that **doesn't exist**:

| What the docs promise | What Cookie actually has |
|-----------------------|--------------------------|
| `app/Infrastructure/Persistence/Repositories/CookieRepository.php` (SKILL.md step 8, inventory item 26) | `app/Domain/Cookie/Repositories/CookieRepository.php` — Infrastructure repo dir is empty for Cookie |
| Step 4 mentions "Create, Update, Delete" — three commands | Cookie has FOUR commands: Create/Update/Delete/**Restore**. No mention in skill or inventory. |
| Step 5 mentions "ById, All, Paginated" queries with a single repository | Cookie has TWO ports: `CookieRepositoryInterface` (write) AND `CookieQueryRepositoryInterface` (read), with a distinct `CookieQueryRepository` returning DTOs |
| Step 6 lists "Created, Updated, Deleted" events | Cookie has SIX event types: Created/Updated/Deleted/**Restored**/**StockChanged**, plus the handlers + the disabled projection subscription |
| Step 8 — "Set $table, $allowedFields, timestamps, soft deletes" | Real model also has `version`, `tenant_id`, `created_by`, `updated_by`, `deleted_by` audit/tenancy fields. Plus `validationRules` and `validationMessages`. Plus `existsByName` / `existsByNameExcludingId` helpers. None mentioned. |
| Step 9 — "Add Repository to Services.php" | Cookie repo is auto-discovered via `#[AutoBind]` (see `CookieRepository.php:48-49`). Services.php hand-wire is *anti-pattern* per current architecture. Skill is two refactors behind. |
| Step 10 — migration template | Real migration carries `tenant_id`, `version`, `created_by`, `updated_by`, `deleted_by`, composite UNIQUE `(tenant_id, name, deleted_at)`, pinned `utf8mb4_unicode_ci` collation. Skill says only "id, all domain properties, timestamps, deleted_at". |
| No mention of `ReadModels/{Entity}View` | Cookie has `app/Domain/Cookie/ReadModels/CookieView.php` |
| No mention of `Services/PriceFormatter`-style domain services | Cookie has `app/Domain/Cookie/Services/PriceFormatter.php` |
| No mention of `ErrorCodes` class | Cookie has `app/Domain/Cookie/ErrorCodes.php` with a documented scoping contract |
| No mention of accessors trait | Cookie has `app/Domain/Cookie/Entities/CookieAccessors.php` (Phase 4 split per file header) |
| No mention of logging traits | Cookie has two: `BusinessMetricsLogging`, `RepositoryLogging` |
| No mention of Projections | Cookie has `.php.example` reference projection |
| No mention of outbox / `EventOutboxWriter` | Real `CookieRepository` accepts `?EventOutboxWriter` (line 92, 175) |
| No mention of `TenantContext` | Real `CookieRepository` accepts `?TenantContext` (line 93, 445) |
| Inventory total: 47 files | Real Cookie: ~40 production files + ~17 test files = ~57. Closer to 60. |

`.claude/documentation/ADDING_DOMAINS.md:13-15` instructs cloners to invoke `/add-domain Order` — this slash command runs the scaffolding skill, which scaffolds a 2024-vintage Cookie, not the actual current Cookie. A cloner running it today produces a domain that won't pass the project's own gates (no `version` column → optimistic locking can't work; no `tenant_id` → composite UNIQUE missing; no `ErrorCodes` class → handlers reference an undefined constant).

## Findings

### F1 — CRITICAL — Scaffolding skill produces a shape that no longer matches Cookie
- **Location:** `.claude/skills/domain-scaffolding/SKILL.md:22-302`, `.claude/documentation/COMPLETE_FILE_INVENTORY.md:1-234`, `.claude/documentation/ADDING_DOMAINS.md:1-60`
- **Observation:** Skill mentions 3 commands (Create/Update/Delete), 3 events, one repository, no Restore/StockChanged/Projection/ReadModels/Services/ErrorCodes/Accessors/Traits/OutboxWriter/TenantContext/QueryRepository. Cookie has all of these. The skill also instructs writing the repository to `app/Infrastructure/Persistence/Repositories/` (step 8) and adding it to `Services.php` (step 9) — Cookie's repository actually lives at `app/Domain/Cookie/Repositories/` and uses `#[AutoBind]` (no Services.php edit).
- **Why this is a template defect:** `/add-domain` and the inventory are the *single source of truth* for cloning. They are 2+ refactors out of date. A cloner who runs `/add-domain Order` does not get a Cookie-shaped Order — they get a skeleton that the project's own quality gates would reject.
- **Suggested fix:** Regenerate the skill *from* the current Cookie tree (use `find app/Domain/Cookie -type f` as the source). Add the missing slots: Restore command, StockChanged event, ReadModels, Services, ErrorCodes, accessors trait, query repository + port, two logging traits, projection-as-`.example` (with explanation). Switch step 8/9 to the auto-discovery flow (`#[AutoBind]`, no Services.php edit). Update the inventory to match.

### F2 — CRITICAL — Reference projection is disabled with confusing migration history
- **Location:** `app/Domain/Cookie/Projections/CookieReadModelProjection.php.example`, `app/Database/Migrations/2026-05-20-200000_CreateCookieReadModelTable.php`, `app/Database/Migrations/2026-05-21-120000_DropCookieReadModelTable.php`
- **Observation:** The template claims to demonstrate CQRS with a denormalised read side. The projection file is `.php.example` (disabled). The migration that creates `cookie_read_model` is followed exactly one day later by a migration that drops it. Net effect on a fresh deploy: table never exists. The `.example` file's "How to re-enable" comment (lines 18-39) is real, but a cloner cannot tell whether the template is *aspirationally* projection-based, *deliberately* projection-less, or *broken*.
- **Why this is a template defect:** Cookie is supposed to be exemplary. The current state says "look at the projection — wait, it's a file extension; ignore the migration that creates the table, also the one that drops it". A new domain that genuinely needs a denormalised projection has *no* working reference to copy from in this codebase.
- **Suggested fix:** Pick a story and tell it consistently. Either (a) restore the projection as active code with a real working migration, OR (b) collapse the two migrations into nothing (delete both files; a fresh `php spark migrate` should never create `cookie_read_model`) and turn the `.example` into a doc file under `.claude/documentation/PROJECTIONS.md`. The current "trail of breadcrumbs through git history" approach is hostile to cloners.

### F3 — HIGH — "Shared" logging traits and PriceFormatter are scoped under Cookie but reused by other domains
- **Location:** `app/Models/Cookie/Traits/BusinessMetricsLogging.php:1-100`, `app/Models/Cookie/Traits/RepositoryLogging.php`, `app/Domain/Cookie/Services/PriceFormatter.php:22-39`
- **Observation:** Three reusable abstractions sit in Cookie-namespaced directories with Cookie-imported types: `App\Models\Cookie\Traits\BusinessMetricsLogging` uses `Cookie` and `CookiePrice` directly, `PriceFormatter` only operates on `CookiePrice`. They cannot be reused as-is. Every cloned domain copy-pastes-and-renames.
- **Why this is a template defect:** A new contributor reading "BusinessMetricsLogging" trait thinks they can reuse it; they can't. The trait's threshold lookup keys the config map by literal `'cookie'` (line 47), so even sharing the trait would surface Cookie's stock=10 / 10% defaults silently when the cloned slice is missing.
- **Suggested fix:** Move `PriceFormatter` to `app/Domain/Shared/Services/MoneyFormatter.php` typed against `Money`. Move `RepositoryLogging` to `app/Domain/Shared/Logging/RepositoryLogging.php` (no Cookie types). Decompose `BusinessMetricsLogging`: extract a generic `MetricsThresholds` reader, leave the cookie-specific logging in `app/Domain/Cookie/Logging/CookieBusinessMetrics.php` (Cookie-scoped, not pretending to be shared).

### F4 — HIGH — USD-cents bounds and currency default inside the price VO
- **Location:** `app/Domain/Cookie/ValueObjects/CookiePrice.php:22-23, 220-223`
- **Observation:** `MIN_MINOR_UNITS = 1` and `MAX_MINOR_UNITS = 999_999` are 2-decimal-currency cents semantics. `defaultCurrency()` returns `Currency::default()`. The file's docblock claims it "pins the currency choice at the boundary" — the boundary is a single static call to `Currency::default()`, not a per-domain decision.
- **Why this is a template defect:** Round-2 r03 §V3 already flagged this. It is still present. Any cloned price VO (`InvoiceTotal`, `WageRate`, `SubscriptionFee`, `ShippingCost`) silently inherits a retail-catalogue $9,999.99 cap and a USD default. JPY (0 decimals) breaks the cents assumption entirely; BHD (3 decimals) does too.
- **Suggested fix:** Either (a) remove the bounds entirely and let the entity / a domain policy decide range, or (b) take them as constructor parameters when a domain genuinely needs them. The currency default should be injected from `SettingsService` at the factory boundary (`fromString($s, Currency $c)` with no default).

### F5 — HIGH — `ErrorCodes` collision contract is documented but not centrally enforced
- **Location:** `app/Domain/Cookie/ErrorCodes.php:17-28`, `app/Domain/User/ErrorCodes.php` (sibling)
- **Observation:** The file documents the *intentional* numeric collision with `User::ErrorCodes` (line 19-22): "101 is COOKIE_VALIDATION_NAME here and USER_VALIDATION_EMAIL there. That is intentional — every code is emitted alongside a `domain` field". This is fine for log queries but no shared infrastructure enforces that handlers always emit `domain` alongside the code. Cookie's own handlers (e.g. `CreateCookieHandler.php:69-76, 127-135`) put `'domain' => 'Cookie'` *as a string literal*. Every cloned handler copy-pastes the literal.
- **Why this is a template defect:** A cloner who forgets the `'domain'` key produces a log/API record where `error_code: 101` is ambiguous. The contract relies on per-handler discipline forever.
- **Suggested fix:** Promote a `DomainErrorCode` value object pairing the FQCN-suffix + integer, returned by `ErrorCodes::wrap(self::COOKIE_VALIDATION_NAME)`, that carries the `domain` field as part of its identity. Logger calls then take the wrapper and stamp the field automatically. Or, lighter: a shared `BaseHandler` that injects `domain` into all log context. Either kills the literal-copy pattern.

### F6 — HIGH — File-inventory and SKILL.md disagree about the repository's physical location
- **Location:** `.claude/documentation/COMPLETE_FILE_INVENTORY.md:55-56, 190-191`, `.claude/skills/domain-scaffolding/SKILL.md:144-150`
- **Observation:** Both docs say the repository belongs in `app/Infrastructure/Persistence/Repositories/`. Cookie's actual repository is at `app/Domain/Cookie/Repositories/CookieRepository.php`. The `app/Infrastructure/Persistence/Repositories/` directory is empty for Cookie (it doesn't even exist as a created folder per the `find` output — only `app/Infrastructure/Persistence/Migrations/` and similar exist).
- **Why this is a template defect:** A cloner following the documented path creates the file in a directory that doesn't exist, fails the deptrac boundary check (the Infrastructure layer is not where the port's adapter lives in this template), and ends up with a structure that diverges from Cookie. Mirrored asymmetry with User domain — round-2 r03 §D1 explicitly noted Cookie has port placement *right*. Now the doc says it wrong.
- **Suggested fix:** Move the file-inventory and SKILL.md repository path to `app/Domain/{Domain}/Repositories/{Entity}Repository.php`. Add an explicit note that the auto-discovery system finds adapters via `#[InfrastructureAdapter]` + `#[AutoBind]`, *not* via Services.php hand-wiring.

### F7 — MEDIUM — Plural/pluralisation literals are scattered across views, routes, controller, and table name
- **Location:** `app/Domain/Cookie/CookieServiceProvider.php:226` (`'cookies'`), `app/Models/Cookie/CookieModel.php:30` (`$table = 'cookies'`), `app/Views/cookies/*.php`, `app/Controllers/Domain/Cookie/CookieController.php:90, 147, 177, 224, 257` (redirect strings `/cookies` and `/cookies/{id}`)
- **Observation:** Cookie's plural is regular ("cookies"). `Person`/`Child`/`Tax`/`Medium` are not. A cloner must edit the table name, view directory name, route prefix, namespace plural in `BaseController` redirects, and view template paths — five independent literals with no compile-time link.
- **Why this is a template defect:** The template doesn't model the singular/plural split. There is no `EntityNaming` config or convention check. Cloning to `Person`/`Children` will produce a half-renamed domain unless the cloner reads carefully.
- **Suggested fix:** Either (a) standardise on singular-everywhere (`/cookie`, `cookies` table → `cookie`, etc. — controversial) or (b) document the plural rule in SKILL.md and add a grep-checklist of every literal a cloner must replace. The latter is cheap and immediate.

### F8 — MEDIUM — Hard-coded English copy in views with no i18n hook
- **Location:** `app/Views/cookies/index.php:6, 8, 15, 18, 27-28, 34-41, 58-60, 66, 84, 86, 89, 92`, similar in `show.php`/`create.php`/`edit.php`
- **Observation:** Strings "Cookies", "Create New Cookie", "Search cookies...", "Clear", "No cookies found.", "ID/Name/Description/Price/Stock/Status/Actions", "Active"/"Inactive", "View"/"Edit", "Previous"/"Page X of Y"/"Next", "Total: N cookies" are all hardcoded. CodeIgniter has `lang('Cookies.title')` available, but no `app/Language/en/Cookies.php` file exists.
- **Why this is a template defect:** Every cloned domain reinvents Bootstrap-CRUD copy with no localisation. The project's `LocaleResolver`/`LocaleMiddleware` infrastructure (visible at `app/Infrastructure/I18n/`) is unused on the example domain.
- **Suggested fix:** Convert one view to `lang('Cookies.fieldName')`, ship `app/Language/en/Cookies.php` with the strings, demonstrate the pattern. Cloners will copy the i18n surface as a matter of course.

### F9 — MEDIUM — `Cookie.php` is 288 lines (29 methods incl. accessors); breaches project's own ≤200-line cap
- **Location:** `app/Domain/Cookie/Entities/Cookie.php` (288 lines) + `app/Domain/Cookie/Entities/CookieAccessors.php` (74 lines, trait) + `app/Domain/Cookie/Repositories/CookieRepository.php` (586 lines!)
- **Observation:** Round-2 r03 §N3 measured 380 lines pre-refactor; Phase 4 split off accessors and stock VO, getting Cookie down to 288. Still > 200. The repository is at **586 lines** — almost triple the cap. CLAUDE.md (`.claude/CLAUDE.md` "Code quality" section) says "Classes ≤ 200 lines (Phase 4 will enforce mechanically)".
- **Why this is a template defect:** The reference domain breaks the rule the reference domain is supposed to embody. Cloners copy the shape and inherit the rule-break. Phase 4 enforcement (when it lands) will reject every cloned domain by default.
- **Suggested fix:** Split `CookieRepository` into `CookieReadRepository` (find*) and `CookieWriteRepository` (save/delete/restore) or extract the optimistic-locking and event-draining into separate collaborators. For `Cookie` entity, extract event-raising into a `CookieEventPublisher` collaborator.

### F10 — MEDIUM — DTO carries presentation concerns; ReadModel is a parallel structure
- **Location:** `app/Domain/Cookie/DTOs/CookieDTO.php:26, 56`, `app/Domain/Cookie/ReadModels/CookieView.php:36-51`
- **Observation:** The template has **two** read-side DTOs: `CookieDTO` (with `formattedPrice` and `isOutOfStock()`) used by some consumers, `CookieView` (with `detail`/`summary` static factories and `$extra`) used by others. The skill doesn't mention either pattern explicitly; cloners will guess.
- **Why this is a template defect:** Two abstractions with overlapping purpose. A cloner copies "the DTO" without knowing there's a second pattern, or vice versa.
- **Suggested fix:** Pick one (recommendation: `CookieView`-style with `detail`/`summary` factories — it's the more flexible shape and matches the read-model story). Delete or deprecate the other. Document the convention in `cqrs-architecture` skill.

### F11 — MEDIUM — Restore command/event present in Cookie but missing from scaffolding promise
- **Location:** `app/Domain/Cookie/Commands/RestoreCookie/`, `app/Domain/Cookie/Events/CookieRestored/`, `app/Domain/Cookie/Ports/CookieRepositoryInterface.php:71` (`restore` method)
- **Observation:** Round-2 r03 §V5 noted historically there was no `CookieRestoredEventHandler` registered. Today the handler exists and is registered (`CookieServiceProvider.php:212-215`). Good. But: the scaffolding skill only mentions Create/Update/Delete; no Restore. A cloner who follows the skill produces a domain *without* restore support — silently divergent from Cookie.
- **Why this is a template defect:** Soft-delete + restore is one of the three ERP-baseline behaviours the migration's docblock advertises (`CreateCookiesTable.php:13-22`). The scaffolding skill omits half of it.
- **Suggested fix:** Add Restore as a fourth standard command in `domain-scaffolding/SKILL.md` step 4. Add `CookieRestoredEvent` to step 6. Update file-inventory totals.

### F12 — LOW — Singular vs. plural inconsistency: `Cookie`/`cookies`, `Cookies` in views, `cookie.events` log channel
- **Location:** Across the tree
- **Observation:** Namespace singular (`App\Domain\Cookie`), entity singular (`Cookie`), table plural (`cookies`), view dir plural (`cookies/`), log channel lowercase singular (`cookie.events` in `CookieServiceProvider.php:181`), controller redirect plural (`/cookies` in `CookieController.php`).
- **Why this is a template defect:** Not wrong, just a lot of independent literals a cloner must update consistently. Combined with F7 above.
- **Suggested fix:** Document the convention table (Namespace/Entity/Table/Route/View/LogChannel) in `cqrs-architecture` skill with worked examples per domain.

### F13 — LOW — Project memory CLAUDE.md asserts "every pattern is fully exemplified" by Cookie — false today
- **Location:** `.claude/CLAUDE.md:13-15` ("Reference domain: app/Domain/Cookie/ — copy its structure for new domains. Every pattern (handlers, value objects, repository, projections, tests, logging) is fully exemplified there.")
- **Observation:** "projections" is not fully exemplified — the projection is a `.php.example` file with deferred migration history (F2). "logging" is not fully exemplified at the *shared* level — the traits are Cookie-scoped (F3).
- **Why this is a template defect:** The boot-time memory tells agents Cookie is the truth. Agents will copy from Cookie believing the doc, and inherit the half-built bits.
- **Suggested fix:** Either fix the half-built bits (F2, F3) or update the memory: "Cookie demonstrates the structure; for projections see `.claude/documentation/PROJECTIONS.md`; some logging traits are Cookie-specific and shouldn't be copied verbatim."

### F14 — LOW — `ErrorCodes` constants are not used uniformly inside the entity itself
- **Location:** `app/Domain/Cookie/Entities/Cookie.php:219` (uses `COOKIE_STATE_DELETED` for "requires a persisted entity" — wrong code, should be a separate state error)
- **Observation:** `assertPersisted()` throws with `ErrorCodes::COOKIE_STATE_DELETED` even though the actual condition is "id is null", not "deleted". A cloned domain will inherit the mis-coding.
- **Why this is a template defect:** The reference shows the wrong way to use error codes (recycle a vaguely-related code instead of adding a new one). Cloners copy the recycling pattern.
- **Suggested fix:** Add `COOKIE_STATE_NOT_PERSISTED = 403` and use it.

### F15 — INFO — `findByIdWithTrashed` is on the write port; query side has no equivalent
- **Location:** `app/Domain/Cookie/Ports/CookieRepositoryInterface.php:78`
- **Observation:** Read-side restoration check requires a `withDeleted` query. The query port (`CookieQueryRepositoryInterface`) doesn't expose it; the write port does. Cloners may put `findByIdWithTrashed` on the wrong side.
- **Why this is a template defect:** Subtle leak of CQRS read-write boundary into the entity port.
- **Suggested fix:** Either expose `findByIdWithTrashed` on the read port returning `CookieView`, or document explicitly why it stays on the write side (restore command needs the full entity to invoke entity methods on it).

## What is correct / praiseworthy

- **Folder shape is excellent and is the *positive* reference for the codebase.** Commands/Queries/Events/Ports/DTOs/ReadModels/ValueObjects/Services/Repositories all in their right places. The User domain copied this and Cookie remains the higher-fidelity example.
- **`#[DomainServiceProvider]` + `#[AutoBind]` auto-discovery works**. A new domain genuinely doesn't need to edit `Services.php`. (The scaffolding skill is what's stale, not the runtime.)
- **`registerRoutes()` on the provider** — domain owns its routes; `app/Config/Routes.php` doesn't need editing per domain. Clone-friendly.
- **Named-factory pattern** (`Cookie::create` / `Cookie::reconstitute`, `CookieName::fromString`, `CookiePrice::fromString`/`fromMinorUnits`) is consistent and copyable.
- **ErrorCodes file with explicit scoping contract** (`ErrorCodes.php:17-28`) — the documentation of intentional collision is excellent. Pattern is worth standardising.
- **`@internal` markers on `bumpVersion`/`assignId`** (`Cookie.php:118, 128`) communicate intent even if PHP can't enforce package-private.
- **Optimistic locking pattern in `CookieRepository::updateWithOptimisticLock`** (lines 460-482) is a complete, testable reference — `CookieOptimisticLockingTest.php` exists to back it.
- **Composite UNIQUE + collation pin in the migration** (`CreateCookiesTable.php:128-130, 63`) is a thoughtful inheritance for cloned domains.
- **The `Phase 4 split` comments** in `CookieStock`, `CookieAccessors`, `PriceFormatter` document the *why* of each split — cloners can read the history and understand which symbols are scaffolded vs. earned.
- **Outbox + tenant-context optional dependencies** on the repository (`CookieRepository.php:87-101`) — clone-friendly because they're nullable and the comments justify each fallback.

## Top 3 fixes before declaring Cookie the template

1. **Regenerate `domain-scaffolding/SKILL.md` and `COMPLETE_FILE_INVENTORY.md` from the actual Cookie tree.** Add Restore + StockChanged + ReadModels + Services + ErrorCodes + Accessors trait + QueryRepository + logging traits + projection-as-`.example` + tenant/outbox optional deps. Switch step 8/9 to `#[AutoBind]` (no Services.php edit). Reject any future PR that updates Cookie without updating these two docs — they are the cloning contract.

2. **Resolve the projection-as-`.example` ambiguity and the create/drop migration pair** (F2). Either restore the projection as active code with a working migration *and* register it in `CookieServiceProvider::registerEvents()`, OR collapse both Cookie-readmodel migrations to nothing and move the `.example` file to `.claude/documentation/PROJECTIONS.md` as pure reference. The current self-contradicting state poisons every clone.

3. **Move "shared" infrastructure out of Cookie namespaces** (F3): `PriceFormatter` → `app/Domain/Shared/Services/MoneyFormatter.php`, `RepositoryLogging` → `app/Domain/Shared/Logging/RepositoryLogging.php`, `BusinessMetricsLogging` split into a generic `MetricsThresholds` reader + a Cookie-scoped `CookieBusinessMetricsLogger`. Until this lands, cloners either copy-edit (bad) or take a hard dependency on the Cookie namespace from `Order`/`Customer`/etc. (worse).

---

**Severity counts:** CRITICAL 2 | HIGH 4 | MEDIUM 5 | LOW 3 | INFO 1
**Top finding:** F1 — the scaffolding skill, the file inventory, and `ADDING_DOMAINS.md` all describe a Cookie that hasn't existed for at least two refactors; `/add-domain Order` today produces a domain the project's own quality gates would reject.
**Verdict on "could a new domain be cloned from Cookie today?":** Not safely — manual copy-and-edit gets you ~80% there; the documented tooling (`/add-domain`, COMPLETE_FILE_INVENTORY) is 2 phases stale and will mis-place the repository, omit the Restore/StockChanged commands+events, the ReadModels, the QueryRepository, the ErrorCodes class, and the auto-discovery wiring.
