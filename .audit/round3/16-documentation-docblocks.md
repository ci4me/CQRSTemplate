# 16 — Documentation, Comments & Docblocks

**Slice:** Class/method/inline documentation across the Cookie domain + project-level docs that describe Cookie
**Reviewer:** general-purpose
**Date:** 2026-05-22
**Source files reviewed:** 67 (38 domain PHP + 3 model PHP + 1 controller + 3 migrations + 1 seeder + 21 test PHP + 6 project docs/skills/CI configs)

## TL;DR

Cookie carries genuinely good prose at the class level (the Entity, the value objects, the repository contract, the Service Provider, and the migration are well-explained), but the surface is **pockmarked with auto-generated placeholder method docblocks** (`__construct.`, `handle.`, `findById.`, `detail.`, …) and the **project-level docs that the scaffolder copies — `COMPLETE_FILE_INVENTORY.md`, `domain-scaffolding/SKILL.md`, `SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md` — describe a 2-to-3-phase-old Cookie** (wrong repository path, missing 4th command, missing query side, references to `Routes.php` that no longer apply, references to `Services.php` registration that auto-discovery replaced). The `composer docblocks:audit` script exists and is wired into `composer check`, but **CI does NOT run it**, so the placeholder backlog is invisible to the gate that the README claims protects the tree. A `sed s/Cookie/Foo/g` cloner today inherits a domain that *looks* exemplary at class level but ships ~26 method docblocks that say nothing, plus skill docs that point at file paths that no longer exist.

## Verdict

**NOT-READY** — the class-level prose is template-worthy, but the placeholder method docblocks and the **stale project-level scaffolding docs** would silently propagate to every clone. Documentation defects are exactly the kind of thing the template is *supposed* to be a reference for; the docblocks tooling exists, but the gate that would catch this isn't wired to CI.

## Quantitative survey

| Metric | Count |
| --- | --- |
| PHP files in Cookie scope (domain + model + controller + DB + tests) | 67 |
| PHP files in scope WITH a class-level docblock | 67 / 67 (incl. tests; some tests are policy-excluded) |
| PHP files in scope WITHOUT a class-level docblock | 0 production, ~10 tests (test class-comments are policy-excluded in phpcs.xml:306) |
| Placeholder/stub method docblocks (`* MethodName.` or `* __construct.`) in Cookie scope | **26** (see F1 for the full list) |
| Auto-generated TODO markers (`@todo Auto-generated docblock`) in Cookie scope | **0** (older marker — `docblocks:audit` script only checks for stale markers, NOT for empty stubs) |
| `TODO` / `FIXME` / `XXX` / `HACK` markers in Cookie production code | **0** (clean) |
| `TODO` / `FIXME` markers in Cookie tests | **0** (clean) |
| `@deprecated` tags in Cookie scope | **2** (`CookiePrice::getValue`, `CookiePrice::format` — both well-described) |
| `@internal` tags in Cookie scope | **2** (`Cookie::bumpVersion`, `Cookie::assignId` — appropriate use) |
| `@since` tags | **0** |
| `@template` / `@phpstan-template` tags | **0** (none needed — no shared generic bases in Cookie) |
| Estimated `@throws` coverage on intentional throws | **~70 %** (Cookie::update, decreaseStock OK; some handlers omit `@throws DomainException` for the not-found path; `RestoreCookieHandler::handle` has @throws but the docblock body is a 1-word stub) |
| Wrong / drifted `@package` tags | **1** (`CookieRepository::@package App\Models\Cookie` — repo actually in `App\Domain\Cookie\Repositories`) |
| `composer docblocks:audit` referenced in `composer check` | YES |
| `composer docblocks:audit` referenced in CI (`.github/workflows/ci.yml`) | **NO** — see F8 |
| `composer deptrac` referenced in CI | **NO** (also a gap, but out of this slice) |
| Cookie commands present today | 4 (Create / Update / Delete / Restore) |
| Cookie commands documented in `COMPLETE_FILE_INVENTORY.md` | 3 (Create / Update / Delete) |
| Cookie events present today | 5 (Created, Updated, Deleted, Restored, StockChanged) |
| Cookie events documented in `COMPLETE_FILE_INVENTORY.md` | 3 (Created, Updated, Deleted) |
| Cookie file inventory total claimed by docs | **45–47** files |
| Cookie file inventory actual today | **67** files (38 + 3 + 1 + 4 + 21) |

## Findings

### F1 — HIGH — 26 placeholder "* MethodName." docblocks across the Cookie surface

- **Location:** the following 26 lines (full list):
  - `app/Domain/Cookie/Commands/UpdateCookie/UpdateCookieHandler.php:152` — `* determineErrorCode.`
  - `app/Domain/Cookie/Commands/UpdateCookie/UpdateCookieCommand.php:30` — `* __construct.`
  - `app/Domain/Cookie/Commands/CreateCookie/CreateCookieCommand.php:21` — `* __construct.`
  - `app/Domain/Cookie/Commands/DeleteCookie/DeleteCookieCommand.php:21` — `* __construct.`
  - `app/Domain/Cookie/Commands/RestoreCookie/RestoreCookieCommand.php:18` — `* __construct.`
  - `app/Domain/Cookie/Commands/RestoreCookie/RestoreCookieHandler.php:15` — `* RestoreCookieHandler.` (class-level)
  - `app/Domain/Cookie/Commands/RestoreCookie/RestoreCookieHandler.php:20` — `* __construct.`
  - `app/Domain/Cookie/Commands/RestoreCookie/RestoreCookieHandler.php:30` — `* handle.`
  - `app/Domain/Cookie/DTOs/CookieDTO.php:19` — `* __construct.`
  - `app/Domain/Cookie/DTOs/CookieDTO.php:35` — `* fromEntity.`
  - `app/Domain/Cookie/DTOs/CookieDTO.php:53` — `* isOutOfStock.`
  - `app/Domain/Cookie/Ports/CookieRepositoryInterface.php:31` — `* findById.`
  - `app/Domain/Cookie/Ports/CookieRepositoryInterface.php:51` — `* existsByName.`
  - `app/Domain/Cookie/Ports/CookieRepositoryInterface.php:56` — `* existsByNameExcludingId.`
  - `app/Domain/Cookie/Ports/CookieRepositoryInterface.php:61` — `* delete.`
  - `app/Domain/Cookie/Ports/CookieQueryRepositoryInterface.php:30` — `* findById.`
  - `app/Domain/Cookie/Repositories/CookieRepository.php:149` — `* isDuplicateKey.`
  - `app/Domain/Cookie/Repositories/CookieRepository.php:160` — `* dispatchPendingEvents.`
  - `app/Domain/Cookie/Repositories/CookieRepository.php:485` — `* raiseConcurrentModification.`
  - `app/Domain/Cookie/Repositories/CookieQueryRepository.php:58` — `* findById.`
  - `app/Domain/Cookie/ReadModels/CookieView.php:54` — `* detail.`
  - `app/Domain/Cookie/Events/CookieStockChanged/CookieStockChangedEvent.php:21` — `* __construct.`
  - `app/Domain/Cookie/Events/CookieStockChanged/CookieStockChangedEventHandler.php:17` — `* __construct.`
  - `app/Domain/Cookie/Events/CookieStockChanged/CookieStockChangedEventHandler.php:24` — `* __invoke.`
  - `app/Domain/Cookie/Events/CookieRestored/CookieRestoredEvent.php:15` — `* __construct.`
  - `app/Domain/Cookie/Events/CookieRestored/CookieRestoredEventHandler.php:17` — `* __construct.`
  - `app/Domain/Cookie/Events/CookieRestored/CookieRestoredEventHandler.php:25` — `* __invoke.`
- **Observation:** `bin/docblocks-generate` deliberately emits these single-word stubs as a way to satisfy Squiz.Commenting.FunctionComment's "must have a docblock" rule without inventing prose (see the comment in `bin/docblocks-generate` lines 7-22). The companion `bin/docblocks-audit` script **only flags the older `@todo Auto-generated docblock` marker, which the generator no longer emits** (see `bin/docblocks-generate:31-36`). The result: these 26 stubs satisfy PHPCS, are not flagged by `docblocks:audit`, and ship with the template.
- **Why this is a template defect:** these are the highest-frequency docblocks an IDE will show on hover. They are the first prose a junior or AI agent sees when reading `CookieRepository::findById` or `CookieDTO::fromEntity`. "Just restates the method name" is the precise anti-pattern the audit brief calls out. For interfaces (`CookieRepositoryInterface::findById.`, `existsByName.`, `delete.`), the docblock should describe the *contract*, especially:
  - what `findById` returns when the row is soft-deleted (it returns null — the model's `useSoftDeletes = true` hides those rows; that's load-bearing behaviour a cloner needs to know)
  - whether `existsByName` is case-insensitive (`CookieModel::existsByName:96` does `LOWER(name) = LOWER(?)` and includes soft-deleted — likewise load-bearing)
  - what `delete` returns when the id is missing
- **Suggested fix:** treat single-word placeholder docblocks as a hard gate. Two options, in priority order:
  1. Extend `bin/docblocks-audit` to also scan for `\* (\w+)\.$\n\s*\*/` (a one-line block where the only content is the method name with a period) and exit 1. Then fix the 26 instances. This is the smallest possible delta.
  2. Or: switch `bin/docblocks-generate` to emit a `@todo Auto-generated docblock — review and replace this description.` line on every new stub (the marker the audit script already greps for). That re-arms the audit as the generator originally intended; the generator comment block at lines 32–36 documents that this is exactly what was removed.
  Either way, the 26 instances must be replaced with real prose before cloning. For interfaces specifically, document the soft-delete contract and the case-insensitive contract.

### F2 — HIGH — `COMPLETE_FILE_INVENTORY.md` is 2 phases stale: wrong repository path, missing fourth command, missing query side

- **Location:** `.claude/documentation/COMPLETE_FILE_INVENTORY.md`
- **Observation:** The "Example: Cookie Domain" tree at lines 144–234 lists:
  - `app/Infrastructure/Persistence/Repositories/CookieRepository.php` (line 191) — but the file actually lives at `app/Domain/Cookie/Repositories/CookieRepository.php`. There is no `app/Infrastructure/Persistence/Repositories/` directory in the tree at all.
  - **No mention of**: `CookieQueryRepositoryInterface`, `CookieQueryRepository`, `ReadModels/CookieView`, `Entities/CookieAccessors` (trait), `ErrorCodes`, the `Services/PriceFormatter`, the `CookieStock` value object, the **fourth command** (`RestoreCookie`), or **two of the five events** (`Restored`, `StockChanged`).
  - The "Models" section lists `app/Models/Cookie/CookieModel.php` but omits `app/Models/Cookie/Traits/BusinessMetricsLogging.php` and `app/Models/Cookie/Traits/RepositoryLogging.php`, which the Repository now depends on via `use` statements.
  - "Migrations: 1 file" — but Cookie has 3 (`CreateCookiesTable`, `CreateCookieReadModelTable`, `DropCookieReadModelTable`).
  - Line 121: "45. Add routes to `app/Config/Routes.php`" — but Cookie routes live in `CookieServiceProvider::registerRoutes()` (`app/Domain/Cookie/CookieServiceProvider.php:224-235`). The `Routes.php` file does not contain a Cookie group at all.
  - Line 238-253 "Services.php Update": tells the reader to edit `app/Config/Services.php` to add a repository factory method — but `CookieRepository` is auto-discovered via the `#[AutoBind]` + `#[InfrastructureAdapter]` attributes (`app/Domain/Cookie/Repositories/CookieRepository.php:48-50`).
  - Line 3 + 136: "45+ files" / "47+" — the real Cookie surface today is closer to 67 (38 domain + 3 model + 1 controller + 4 DB + 21 tests).
- **Why this is a template defect:** this file is the **canonical inventory** the scaffolding skill references (`cqrs-architecture/SKILL.md:195`). A developer running `/add-domain` (which calls `domain-scaffolding`) is told to use this list as the checklist. They will:
  - skip the Query side (`CookieQueryRepositoryInterface` + `CookieQueryRepository`), shipping a domain whose query handlers fail to register.
  - put the repository under `app/Infrastructure/Persistence/Repositories/` where the auto-discovery doesn't look, then waste hours debugging "why doesn't my repo bind".
  - try to register routes in `app/Config/Routes.php` and then wonder why the domain ignores the route file.
  - try to edit `Services.php` (which now contains a comment saying don't, see slice 08 findings) and produce a merge conflict on every other clone.
- **Suggested fix:** rewrite `COMPLETE_FILE_INVENTORY.md` from a fresh `find app/Domain/Cookie -type f -name '*.php'` walk, paired with:
  - canonical paths for repository (`app/Domain/Cookie/Repositories/`) and routes (`CookieServiceProvider::registerRoutes()`)
  - Read/Query side (port + impl + DTO + ReadModel) called out as a separate sub-section
  - the 4 commands and 5 events listed correctly with the Restore + StockChanged variants
  - "Services.php is no longer touched — `#[AutoBind]` handles repository binding" callout
  - update the file count to ≥60 (or split into "production" vs "test" counts)

### F3 — HIGH — `domain-scaffolding/SKILL.md` points at non-existent paths and pre-auto-discovery workflow

- **Location:** `.claude/skills/domain-scaffolding/SKILL.md`
- **Observation:**
  - line 146: "Repository: Reference: `app/Infrastructure/Persistence/Repositories/CookieRepository.php`" — the file is at `app/Domain/Cookie/Repositories/CookieRepository.php`.
  - lines 154–169 "Step 9: Add Repository to Services.php" — obsolete. Auto-bind via attributes replaces this; the SKILL still walks the reader through editing `Services.php` and writing a `cookieRepository()` factory method.
  - line 207: "Reference: Routes for Cookie in `app/Config/Routes.php`" — Cookie routes live in `CookieServiceProvider::registerRoutes()`.
  - line 26: `mkdir -p app/Models/{Domain}` — implies one Model file. The real Cookie surface has `app/Models/Cookie/CookieModel.php` PLUS `app/Models/Cookie/Traits/{BusinessMetricsLogging,RepositoryLogging}.php`, which are required by the repository.
  - line 3: "45+ files/touchpoints" — real number is closer to 67.
  - Skill omits: the **CookieAccessors trait** pattern (entity getters extracted into a trait, see `app/Domain/Cookie/Entities/CookieAccessors.php`), the **ReadModels/CookieView** detail/summary pattern, the **ErrorCodes** class with domain-scoped numeric ranges and the explicit "collisions are intentional" contract, the **Services/PriceFormatter** post-Phase-4 split, the **CookieQueryRepository** and its DTO mapping, and the entire Restore/StockChanged command-event pair.
- **Why this is a template defect:** the scaffolding skill is the user-facing entry point for `/add-domain`. Following it today produces a domain that is missing the read side, mis-located on disk, and registered via a file the template no longer expects you to edit. Every step labelled "Reference: app/...Cookie...php" is a path the user is expected to open and copy from; mis-pointed references send the cloner to read either dead code or nonexistent code.
- **Suggested fix:** rewrite the skill to mirror what Cookie *actually* looks like in 2026-05-22:
  - Step 8 (Repository): point at `app/Domain/Cookie/Repositories/CookieRepository.php`, mention the `#[AutoBind]` + `#[InfrastructureAdapter]` attributes and that they replace manual Services.php registration.
  - Step 8 (Model + Traits): include the two trait files and what each does.
  - Step 9 (Services.php): DELETE or rewrite as "Step 9: Verify auto-discovery picked up your repo".
  - Step 11 (Routes): "Add a `registerRoutes()` method to your ServiceProvider. Do NOT edit `app/Config/Routes.php`."
  - Add new steps for: Query Repository + Port + DTO, ReadModels, ErrorCodes, optional Projections example.

### F4 — MEDIUM — `CookieRepository.php` carries a wrong `@package` tag

- **Location:** `app/Domain/Cookie/Repositories/CookieRepository.php:46` — `* @package App\Models\Cookie`
- **Observation:** The class is in namespace `App\Domain\Cookie\Repositories` (line 5). The `@package` tag says `App\Models\Cookie`, which is the namespace of `CookieModel`. Likely a copy-paste relic from when the repository was inside `app/Models/`.
- **Why this is a template defect:** `@package` is the canonical "which logical package owns this symbol?" annotation that IDEs and `phpDocumentor` group by. Cloning Cookie into `Foo` and running `sed s/Cookie/Foo/g` produces `FooRepository` with `@package App\Models\Foo` — but the file is at `app/Domain/Foo/Repositories/FooRepository.php`. The mistake propagates verbatim.
- **Suggested fix:** change to `@package App\Domain\Cookie\Repositories` (or, better, drop `@package` tags entirely — PSR-12 / PHP-FIG considers them redundant alongside `namespace`; the project already only emits them sporadically).

### F5 — MEDIUM — `RestoreCookieHandler` class-level docblock is a one-word stub on the class with the most non-obvious orchestration in Cookie

- **Location:** `app/Domain/Cookie/Commands/RestoreCookie/RestoreCookieHandler.php:14-16`
- **Observation:** The class docblock is literally:
  ```
  /**
   * RestoreCookieHandler.
   */
  ```
  But this handler is the *only* handler that:
  - calls `findByIdWithTrashed()` instead of `findById()`,
  - emits a different exception when the row is alive (`businessRuleViolation` "not deleted; nothing to restore"),
  - performs the restore via a direct builder UPDATE (not via the repository's `save()`),
  - dispatches `CookieRestoredEvent` *manually* (not via the entity's event bag), and
  - uses `throw new \RuntimeException` (not `DomainException::*`) for the "restore returned false" path — an inconsistency with every other handler in the domain.
  Every one of those design choices deserves a sentence in the class docblock.
- **Why this is a template defect:** RestoreCookieHandler is the worst class-level docblock in Cookie *and* the one a cloner most needs to understand. Slice 03 (commands) flagged the `\RuntimeException` choice as a wart; without docblock prose, a cloner has no idea whether to repeat the pattern or fix it.
- **Suggested fix:** rewrite the class docblock to ~6 lines explaining: "calls findByIdWithTrashed to locate soft-deleted rows; flips `deleted_at`/`deleted_by` to null via a direct builder UPDATE (the repository's `save()` cannot, because save assumes a non-deleted row); raises `CookieRestoredEvent` manually because the entity has no `restore()` lifecycle method today. Throws `DomainException::notFound` when the id has no row at all, `businessRuleViolation` when the row exists but is alive, and `\RuntimeException` when the UPDATE returned false (which would indicate a torn DB)." Then either fix the `\RuntimeException` (slice 03) or leave the comment that justifies it.

### F6 — MEDIUM — `CookieRepositoryInterface::findById` interface docblock says nothing about the soft-delete contract

- **Location:** `app/Domain/Cookie/Ports/CookieRepositoryInterface.php:30-33`
- **Observation:** The docblock is just `* findById.` (placeholder, see F1). The signature `findById(int $id): ?Cookie` doesn't reveal that:
  - the implementation uses CI4's `useSoftDeletes = true` model behaviour, so soft-deleted rows are hidden;
  - the separate `findByIdWithTrashed()` method (line 78) is the only way to see them;
  - the read-side `CookieQueryRepositoryInterface::findById` *also* hides soft-deleted rows but goes through `WHERE deleted_at IS NULL` explicitly (`CookieQueryRepository:64`), which is a parallel but separately-coded behaviour.
  Three parallel implementations of "hide soft-deleted unless you explicitly ask"; zero of them are documented at the port.
- **Why this is a template defect:** the soft-delete contract is the single most load-bearing piece of repository behaviour the template offers. A cloner reading the port has *no way* to learn that soft-deleted rows are invisible by default. They will write tests that load a deleted row, get null, and silently misinterpret it.
- **Suggested fix:** replace every port-method placeholder docblock with a real contract description. For `findById`: "Returns the cookie or null. Soft-deleted rows are invisible: call `findByIdWithTrashed()` to see those." For `existsByName`: "Case-insensitive. Soft-deleted rows count: a name that exists only as a deleted row STILL returns true, by design — preserves historical ERP/audit references." For `delete`: "Soft delete. Returns false if no row exists. The caller MUST pass an `Actor` when the operation originates from a real user; null is reserved for system contexts."

### F7 — MEDIUM — `SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md` is dated 2025-10-26 and analyses a 23-file Cookie that no longer exists

- **Location:** `.claude/documentation/SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md` (whole file)
- **Observation:** Header reads "Total Files Analyzed: 23 PHP files". Real domain count today is 38 PHP files. The file lists 3 commands and 3 events (lines 43–98); reality is 4 commands and 5 events. Grade is reported as "A (95/100)" — that grade refers to a Cookie domain that hasn't existed since at least Phase 2.
- **Why this is a template defect:** the doc carries an authoritative tone ("Grade: A (95/100)", "Excellent reference implementation") that will discourage a cloner from auditing further. Following its "structure" copies a pre-Restore, pre-StockChanged, pre-Phase-4 Cookie.
- **Suggested fix:** either re-run the analysis against today's tree, or add a top-of-file disclaimer "**STALE — this analysis predates Phase 2/3/4 refactors; see `.audit/round3/` for the current state**".

### F8 — HIGH — `composer docblocks:audit` is in `composer check` but NOT in CI

- **Location:** `.github/workflows/ci.yml` (no `docblocks:audit` step), `composer.json` (scripts.check contains `@docblocks:audit`), `.claude/CLAUDE.md` (Tests section, line ~42: "Reject your own work if PHPStan L8, PHPCS+Slevomat, deptrac, or `docblocks:audit` fail").
- **Observation:** The CI job `quality` (ci.yml:22-76) runs:
  - `composer validate`
  - `php -l` syntax check
  - `vendor/bin/phpcs`
  - `vendor/bin/phpstan analyse`
  And the `tests` job runs PHPUnit. **It does NOT call `composer docblocks:audit`, `composer check`, `composer ci`, or `vendor/bin/deptrac analyse`.** The `composer ci` script (composer.json:62) is the one that would execute the full gate, but ci.yml doesn't invoke it.
- **Why this is a template defect:** CLAUDE.md describes a quality bar that includes the docblock audit and deptrac; CI enforces a different (smaller) bar. A cloner reads CLAUDE.md, assumes the gate is real, ships a clone whose CI is silently missing two of the four claimed gates. The placeholder-docblock backlog in F1 exists today specifically because nothing fails on it.
- **Suggested fix:** replace the per-tool steps in `ci.yml:65-76` with a single `composer ci` step (which runs `@docblocks:audit`, `@phpcs`, `@phpstan`, `@deptrac`, `@test:clover`). Alternatively, add explicit `- name: Docblocks audit \n run: composer docblocks:audit` and `- name: Deptrac \n run: vendor/bin/deptrac analyse --no-progress` steps. Also see F1 for tightening what `docblocks:audit` actually checks.

### F9 — LOW — `CookieAccessors` trait carries an `@property` block listing private members it accesses

- **Location:** `app/Domain/Cookie/Entities/CookieAccessors.php:18-26`
- **Observation:** The trait's class docblock declares `@property ?int $id`, `@property CookieName $name`, etc., for the 9 properties it reads from the host class. This is functionally correct (PHPStan and IDEs need it to type-check the `$this->name` reads inside the trait), but a cloner sees the `@property` block and may misread it as "the trait declares these properties" rather than "the trait expects the using class to declare these". The trait DOES NOT declare the properties — they live in `Cookie.php:48-57`.
- **Why this is a template defect:** when a cloner copies `CookieAccessors.php` to `FooAccessors.php`, they need to know the `@property` block is a *contract on the host* and that they must keep it in lock-step with the entity's typed-property declarations. A one-line note in the docblock would prevent that.
- **Suggested fix:** add a sentence above the `@property` block: "The `@property` tags below describe properties the host class is REQUIRED to declare. Keep them in lock-step with the typed properties on the using entity (e.g. `Cookie.php:48-57`)."

### F10 — LOW — Cookie ports and DTOs lean on Cookie-domain-specific semantics in prose docblocks ("a cookie product", "case-insensitive uniqueness for cookies")

- **Location:** `app/Domain/Cookie/Entities/Cookie.php:18-32`, `app/Domain/Cookie/ValueObjects/CookieName.php:11-36`, `app/Domain/Cookie/ValueObjects/CookieStock.php:13-29`, `app/Domain/Cookie/Repositories/CookieRepository.php:25-46`, `app/Domain/Cookie/Events/CookieCreated/CookieCreatedEvent.php:18-28` (use-cases like "send notification to inventory system").
- **Observation:** Each is well-written *for Cookie*, but the prose is bound to Cookie's bakery-catalog metaphor. A `sed s/Cookie/Foo/g` produces:
  - "This entity represents a Foo product and orchestrates the foo lifecycle..." (works grammatically, but "a foo product" is vacuous)
  - "Send notification to inventory system" (vestigial — useful for Cookie, meaningless for User or Tenant)
  - "Cookie sale price (D7). Thin domain-specific wrapper around Money. Self-validates against COOKIE_VALIDATION_PRICE (must be > 0, must fit a retail catalogue)" — `CookiePrice:14-18` calls out "retail catalogue" which only makes sense for products.
- **Why this is a template defect:** the audit brief calls this out explicitly: "Are there docblocks that hard-code 'Cookie' semantics?" Yes — multiple. They survive sed cleanly (no syntax error) but the resulting prose is empty.
- **Suggested fix:** in each class docblock, separate the **pattern** (which the template wants the cloner to read and adopt) from the **example** (which the cloner replaces). For example, in `CookieName`:
  ```
  /**
   * Value Object representing a {Entity} name.
   *
   * Pattern: validate format/length invariants in the constructor; expose
   * the validated string via getValue(). Replace the 3-100 character bounds
   * with whatever your entity's name range is.
   *
   * Cookie-specific instance:
   * - Name must be between 3 and 100 characters
   * - Name is trimmed of whitespace
   * - Name cannot be empty after trimming
   * ...
   * ```
  Keep the Cookie example concrete, but call out which paragraph is "the pattern" and which is "Cookie's specifics".

### F11 — LOW — `CookieSeeder` docblock omits the columns now required by the migration

- **Location:** `app/Database/Seeds/CookieSeeder.php:9-21` (docblock) + lines 25-116 (seed data)
- **Observation:** The class docblock advertises "10 sample cookies with realistic data for testing and demonstration" but does not mention that the seed bypasses several columns the migration added in May 2026 (`tenant_id`, `version`, `created_by`, `updated_by`). The seeder inserts via `$this->db->table('cookies')->insertBatch($data)` so MySQL fills the missing columns with their defaults — `tenant_id = NULL`, `version = 0`, which collides with the optimistic-locking contract (CookieRepository starts new rows at `version = 1`, see `CookieRepository::performSave:430-433`). Slice 05/06/11 may have already noted the schema drift; the docblock issue is that the seeder doesn't *say* it doesn't follow the standard write path.
- **Why this is a template defect:** the docblock makes the seeder look like a safe demo. A cloner reading "10 sample cookies with realistic data" copies the file, runs `db:seed`, and lands with rows that don't go through the aggregate factory and have `version=0` — first update through the handler will throw concurrent-modification because expectedVersion `1` ≠ actual `0`.
- **Suggested fix:** rewrite the docblock to:
  ```
  /**
   * Seeder for the cookies table.
   *
   * NOTE: bypasses the Cookie aggregate and writes directly to the
   * `cookies` table via insertBatch. Skips the optimistic-locking
   * version bump, the tenant_id stamp, and the created_by audit
   * column. Suitable for local demos / smoke tests only; never run
   * in environments where rows will then be updated through the
   * write-side handlers, because the version=0 rows will trigger
   * a concurrent-modification exception on first update.
   * ...
   */
  ```
  And, separately, fix the seeder to populate `tenant_id`, `version`, and `created_by` so the docblock can stay short.

### F12 — LOW — `CookieReadModelProjection.php.example` docblock survives, but its instructions reference migrations that no longer line up

- **Location:** `app/Domain/Cookie/Projections/CookieReadModelProjection.php.example:21-39`
- **Observation:** The file-level comment block tells a re-enabling cloner to: "Reverse the drop migration so the projection table exists again: `php spark migrate:rollback` for the DropCookieReadModelTable migration, OR add a fresh `CreateCookieReadModelTable` migration if you have already moved past it." That's accurate as of today, but the docblock at lines 55-68 describes the projection as "feeding the `cookie_read_model` table (D15)" — yet the same comment block above also says the table no longer exists in the current schema. The discrepancy isn't *wrong*, but a future reader pulling only the class docblock (e.g. via Serena's `find_symbol`) sees "feeds the cookie_read_model table" with no mention that the table is dropped.
- **Why this is a template defect:** small but real — the kind of contradiction that erodes confidence in the docs.
- **Suggested fix:** prepend "PHASE 2 STATUS: cookie_read_model table is currently dropped; see file-level comment for re-enable steps." to the class docblock.

### F13 — INFO — `CookieController` controller docblock predates the actor-resolver wiring

- **Location:** `app/Controllers/Domain/Cookie/CookieController.php:18-39`
- **Observation:** Class docblock lists responsibilities ("dispatch commands and queries via buses", "handle validation errors", etc.) but doesn't mention that:
  - the controller resolves `Actor` via `Services::actorResolver()->resolve($this->request)` and threads it into every write command (`store:141`, `update:219`, `delete:253`)
  - this is the contract for audit trail (B10 in the migration docblock)
  A cloner reading the controller might leave the actor argument out when scaffolding their own controller and silently lose the audit trail.
- **Why this is a template defect:** minor — every clone needs the actor wiring to remain consistent; the docblock should call it out as a non-negotiable.
- **Suggested fix:** add one bullet under "Responsibilities": "Resolve the request actor via `Services::actorResolver()` and pass it as `createdBy` / `updatedBy` / `deletedBy` on every write command (audit-trail contract, see migration B10)."

### F14 — INFO — `@throws` annotations are inconsistent across handlers

- **Location:** various handlers
- **Observation:**
  - `CreateCookieHandler::handle:65` declares `@throws DomainException` but the try/catch re-throws *any* throwable (`catch (\Throwable $e)`), so PHPStan/Intelephense will under-document the surface.
  - `DeleteCookieHandler::handle:50` declares `@throws DomainException If cookie not found`. Same `\Throwable` catch-and-rethrow.
  - `RestoreCookieHandler::handle:34` declares `@throws DomainException` AND `@throws \RuntimeException`. The placeholder body still says `* handle.`.
  - `UpdateCookieHandler::handle:56` declares `@throws DomainException If cookie not found or business rules violated`. Same pattern.
  None of the handler `handle()` methods declare `@throws \Throwable` even though they re-throw everything.
- **Why this is a template defect:** mild but propagates — PHPStan's `throws` rule will let a cloner skip a `@throws` on a re-thrown framework exception (e.g. a `DatabaseException` from the repository).
- **Suggested fix:** either narrow the catch to only the exception types the handler can sensibly handle (and let other throwables propagate without the catch), or document `@throws \Throwable` explicitly. The repository already does the translation (`DatabaseException` → `DomainException::businessRuleViolation` in CookieRepository:131), so most handlers can in practice tighten the catch to `DomainException | ValidationException | DatabaseException`.

### F15 — INFO — `LOGGING_BEST_PRACTICES.md` and `GIT_WORKFLOW.md` documentation existence

- **Location:** `.claude/documentation/`
- **Observation:** Both files exist and are read by CLAUDE.md as authoritative. Did NOT review them in detail in this slice — flagging only that they are referenced from CLAUDE.md and should be cross-checked against Cookie's actual logging shape (slice 14 covered logging; the docs side is in scope here but not exhaustively audited). At a glance, neither references Cookie incorrectly.
- **Suggested fix:** none here; flagging for completeness.

## Docblock-tooling gate audit

What `composer docblocks:audit` actually checks today:

- `bin/docblocks-audit` greps the tree for a single magic string: `@todo Auto-generated docblock — review and replace this description.`
- That marker is **NOT emitted by `bin/docblocks-generate` anymore** — see the comment at `bin/docblocks-generate:31-36`. The generator removed the marker in favour of "the structured @param/@return tags are documentation enough".
- Consequence: `composer docblocks:audit` exits 0 today even though the tree contains **26 single-word placeholder method docblocks** (F1) and **1 wrong @package** (F4). The script is wired into `composer check` and `composer ci` but doesn't actually catch the problems it was designed to catch.
- CI does NOT invoke `composer check` or `composer ci` — see F8 — so even if the audit script were tightened, it wouldn't fail PR builds today.

What `phpcs.xml` actually enforces, from inspection:

- `Squiz.Commenting.FunctionComment` — **every method must have a docblock** with @param/@return. Excluded for `tests/*`, `Database/Migrations/*`, `Database/Seeds/*`, `app/Models/*` (line 261+).
- `Squiz.Commenting.ClassComment` — **every class must have a docblock** describing purpose. Excluded for `tests/*`, `Database/*`, `app/Models/*` (line 305+).
- `Squiz.Commenting.VariableComment` — `@var` on every property. **Excluded for `app/Domain/*`** (line 326) on the grounds that native typed properties feed Intelephense — so VOs/DTOs/aggregates skip the `@var` check.
- `Squiz.Commenting.FunctionCommentThrowTag` — present, but the per-statement count enforcement is disabled (`Missing`, `WrongNumber` excluded at 343-344) on the grounds that re-throws and polymorphic throws are unreliable to detect. **PHPStan's `throws` rule is the fallback**.
- `SlevomatCodingStandard.Commenting.{InlineDocCommentDeclaration,UselessInheritDocComment,DocCommentSpacing}` — present.

What `phpstan.neon` allows:

- Level 8 with `ignoreErrors` for `missingType.parameter`, `missingType.return`, and a couple of `return.type` / `return.unusedType` patches (phpstan.neon:74-150). No suppression of docblock-specific rules I could see in the limited snippet I read.

**Gaps between documented bar and enforced bar:**

1. CLAUDE.md says `docblocks:audit` must exit 0; tree has 26 placeholder docblocks that the audit script doesn't catch.
2. CLAUDE.md says deptrac is part of the gate; CI doesn't run it (out of slice but cited because the F8 fix would address both).
3. The `Squiz.Commenting.VariableComment` exclusion for `app/Domain/*` is reasonable (native types are documentation) but means `@var` tags carrying PHPStan-friendly generics (e.g. `@var list<CookieDTO>`) on a property only have force as PHPStan hints — they're never policed by PHPCS. Worth a note in the policy.
4. **No rule enforces "class docblock must do more than restate the class name"** — neither Squiz nor Slevomat has a "Description.LengthMinimum"-style sniff. Custom enforcement would have to come from the docblocks-audit script (see F1 fix option #1).

## Scaffolding / CLAUDE.md alignment

Direct discrepancies between project docs that describe Cookie and Cookie's reality:

| Doc | Line(s) | Says | Cookie reality |
| --- | --- | --- | --- |
| `COMPLETE_FILE_INVENTORY.md` | 56, 191 | Repo at `app/Infrastructure/Persistence/Repositories/CookieRepository.php` | `app/Domain/Cookie/Repositories/CookieRepository.php` |
| `COMPLETE_FILE_INVENTORY.md` | 26-48 (commands/queries/events block) | 3 commands, 3 queries, 3 events | 4 commands (Create/Update/Delete/Restore), 3 queries, 5 events (… + Restored + StockChanged) |
| `COMPLETE_FILE_INVENTORY.md` | 121 | "Add routes to `app/Config/Routes.php`" | Routes registered in `CookieServiceProvider::registerRoutes()` |
| `COMPLETE_FILE_INVENTORY.md` | 238-253 | Edit `app/Config/Services.php` for new repo | Auto-discovered via `#[AutoBind]` attribute |
| `COMPLETE_FILE_INVENTORY.md` | 3, 136 | "45+ files / 47+" | ~67 files (38 + 3 + 1 + 4 + 21) |
| `domain-scaffolding/SKILL.md` | 146 | Repo reference points at `app/Infrastructure/Persistence/Repositories/...` | Same path defect as inventory |
| `domain-scaffolding/SKILL.md` | 154-169 (Step 9) | Add to `Services.php` | Auto-discovered |
| `domain-scaffolding/SKILL.md` | 207 | "Routes for Cookie in `app/Config/Routes.php`" | Routes in ServiceProvider |
| `domain-scaffolding/SKILL.md` | 26 | `mkdir -p app/Models/{Domain}` (single file implied) | Plus `Traits/BusinessMetricsLogging.php` + `Traits/RepositoryLogging.php` |
| `cqrs-architecture/SKILL.md` | 174-176 | "routes defined in `app/Config/Routes.php` (with per-domain registration moving into `ServiceProvider::registerRoutes()` in Phase 3 of the ongoing refactor)" | Phase 3 is DONE — see `CookieServiceProvider:224`. The "moving into" phrasing is stale. |
| `cqrs-architecture/SKILL.md` | 186-189 | "Use the Cookie domain ... It demonstrates the complete CQRS implementation, DDD patterns, 192 passing tests" | Test count is now higher (slice 12/13 reported ~250+ tests); claim is stale but not wrong. |
| `SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md` | header + body | "23 PHP files", 3 commands, 3 events, "A (95/100)" | 38 PHP files, 4 commands, 5 events. Predates Phase 2/3/4. |
| `CLAUDE.md` | "Tests" section | "docblocks:audit must exit 0" | Script under-enforces; CI doesn't run it |
| `CLAUDE.md` | "Tests" section | "deptrac fails reject your own work" | CI doesn't run deptrac |
| `CLAUDE.md` | "Reference domain" | "Cookie domain ... Every pattern (handlers, value objects, repository, projections, tests, logging) is fully exemplified there." | Projections is now `.example` (preserved but inactive). Fine, but the bare claim suggests an active projection. |

## What is correct / praiseworthy

- **Class-level prose on the value objects is genuinely template-grade.** `CookieName` (lines 11-36), `CookiePrice` (lines 12-19), and `CookieStock` (lines 11-29) each explain invariants, immutability, and the rationale for being a value object. They are the best class docblocks in the slice.
- **`Cookie` entity docblock** (lines 17-42) explicitly documents the event-emission convention (lines 34-39), the value-object delegation pattern (lines 21-26), and the soft-delete contract. This is exactly the level the brief asks for.
- **`CookieRepository` constructor** (lines 73-86) writes a real paragraph about why the outbox writer is optional and what the tenant context default is. This is the kind of "why, not what" the brief calls out.
- **`CreateCookiesTable` migration** (lines 9-38) is exemplary — calls out the ERP-baseline columns (tenant_id, version, created_by, updated_by, deleted_by) with reasons, names the composite UNIQUE strategy with the B16 reference, and lists the indexes.
- **`ErrorCodes`** (lines 7-36) carries an explicit "scoping contract" paragraph that documents why numeric collisions across domains are intentional. This is the kind of architectural decision that absolutely needs a docblock.
- **`@deprecated`** on `CookiePrice::getValue` and `::format` is used correctly: each tag has a one-sentence justification AND points to the replacement (`getMinorUnits`, `PriceFormatter::format`).
- **`@internal`** on `Cookie::bumpVersion` and `::assignId` is correctly applied — these methods are part of the persistence contract, not the public API.
- **The `bin/docblocks-generate` script itself** carries a thorough file-level docblock explaining what the generator does and does NOT do (lines 7-26). The honesty about "we don't invent prose" is exactly what the script needs to advertise.
- **`CookieDeletedEvent`** (lines 9-15) and **`CookieUpdatedEvent`** (lines 9-15) are good models for event-docblock prose: name the fact ("Event fired when ..."), name the consumer payload contract ("Carries the full final snapshot so an audit consumer can reconstruct the row"), name the payload-shape constraint ("Fields are limited to scalar/null types to keep the payload serialisable").

## Top 3 fixes before cloning

1. **Tighten the docblock audit and re-route CI through it.** Extend `bin/docblocks-audit` to fail on single-word placeholder method docblocks (`* MethodName.\n  */` pattern); then either replace the per-tool steps in `.github/workflows/ci.yml:65-76` with a single `composer ci` call, or add explicit `composer docblocks:audit` and `vendor/bin/deptrac analyse` steps. Without the audit running in CI, every subsequent fix in this list rots over time. Then fix the 26 placeholder instances enumerated in F1.

2. **Rewrite the scaffolding inventory and skill to match Cookie's 2026-05-22 shape.** Single-source-of-truth: regenerate `.claude/documentation/COMPLETE_FILE_INVENTORY.md` from `find app/Domain/Cookie -type f -name '*.php'`. Then update `.claude/skills/domain-scaffolding/SKILL.md` so every "Reference: app/...Cookie...php" line points at a path that actually exists, Step 9 (Services.php) is deleted or rewritten for auto-discovery, and Step 12 (Routes) tells the user to use `ServiceProvider::registerRoutes()`. Add the missing Read side, the `Restore` + `StockChanged` pair, the `Traits/`, the `ReadModels/CookieView`, the `Services/PriceFormatter`, and the `ErrorCodes`. Also archive or refresh `SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md`.

3. **Fix the wrong `@package` in `CookieRepository` and write real prose on the port interface methods.** The `@package App\Models\Cookie` mistake will sed-propagate; the placeholder docblocks on `CookieRepositoryInterface::{findById,existsByName,delete}` actively hide the soft-delete and case-insensitive contracts that are the load-bearing parts of the port. A cloner reading the interface gets zero signal about either; fix both in one pass.

---

**Severity counts:** CRITICAL 0 | HIGH 4 | MEDIUM 4 | LOW 4 | INFO 3
**Top finding:** CI doesn't actually run `composer docblocks:audit` (CLAUDE.md says it must), and the audit script itself doesn't catch the 26 single-word placeholder method docblocks shipped across Cookie's port/handlers/DTO/events — so the "docblock quality" gate the template advertises is silently a no-op today, and a cloner inherits both the gap and the placeholder backlog.
