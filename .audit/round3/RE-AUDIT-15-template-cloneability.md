# RE-AUDIT 15 — Template Cloneability (meta)

**Slice:** Cookie's fitness as the reference template — naming, generics,
scaffolding alignment, docs↔reality lock-step.
**Reviewer:** general-purpose (round 3 follow-up)
**Date:** 2026-05-23
**Branch under review:** `integration/phase-1-cookie-foundation`
**PR under review:** **#40** (`epic/e15-scaffolding-docs-catchup`) —
*OPEN*, +5589 / -1100, 86 files.
**Method:** read PR #40 docs (`gh pr checkout 40`) + cross-walked against
the live Cookie tree on integration (`app/Domain/Cookie/`, 45 production
files).

---

## TL;DR

PR #40 is a **substantial, mostly-complete close** of round 3's F1
CRITICAL (scaffolding docs 2 phases stale). The new doc surface — rewritten
`domain-scaffolding/SKILL.md`, regenerated `COMPLETE_FILE_INVENTORY.md`,
new `PROJECTIONS.md`, expanded `CLAUDE.md` reference-domain pointer,
expanded `cqrs-architecture/SKILL.md` — now describes the Cookie that
*actually exists* on integration (45 production files, including
Restore, StockChanged, Activated, Deactivated, ReadModels, Services,
ErrorCodes, AggregateRootInterface, AbstractDomainEvent, Snapshot,
StockChangeReason, two repositories). The new `bin/docs-cookie-sync`
walks `app/Domain/Cookie/` at CI time and fails the build when any new
Cookie file is undocumented — that *is* the forcing function the round 3
report demanded. Running it locally: **OK: 45 Cookie files all documented
across 2 doc(s)**.

What the PR does **not** close: F2 (USD-cents + retail bounds in
`CookiePrice` — E09 pending), F3 (English-only views, no `Cookies.php`
language file — E14 pending), F5 partial (deprecated methods still in
the live codepath — E10 pending), F10 (DTO vs ReadModel split still
present), F9 (`CookieRepository` 587 LOC, `Cookie.php` 348 LOC — both
breach the 200-line cap; E11 pending), the half-built ReadModel
migration pair (create + drop) still ships, and the `BusinessMetricsLogging`
trait still keys on the literal `'cookie'` and types against
`Cookie`/`CookiePrice` (F3-style internal-leak — not in any pending
epic).

The integration branch *already* fixes a few F-items that the round 3
report flagged: F4 (CookieRestoredEventHandler wired —
`CookieServiceProvider.php:222-228`), F11 (all events extend
`AbstractDomainEvent`), F14 (`COOKIE_STATE_NOT_PERSISTED = 403` exists in
`ErrorCodes.php:56` and is used by `CookieStateAssertions.php:62`). The
round 3 finding list under-counted these because it was generated against
the pre-E04/E06/E07 tree.

## Verdict

**MOSTLY-READY** — clear shift from round 3's NOT-READY. The single
biggest cloning hazard (docs lying about what Cookie is) is closed and
CI-enforced. Remaining hazards are now scoped to known pending epics
(E09/E10/E11/E14) or to the "shared infra trapped in Cookie namespace"
sub-problem that nobody has put an epic on yet.

## Could a new domain be cloned today?

**Yes, structurally** — `/add-domain Order` against the updated skill
would produce a 60+ file tree that matches Cookie's real shape, passes
`composer check`, and boots without editing `Services.php`/`Routes.php`.
But the cloner inherits **CookiePrice's USD-cents bounds, the
`BusinessMetricsLogging` `'cookie'` literal, the dead ReadModel migration
pair, and the English-only view copy verbatim** — so the clone is
runnable but technically wrong for any non-USD, non-retail, non-English
domain.

---

## Re-audit findings

### F1 — CLOSED — Scaffolding docs match the real Cookie tree
- **Status:** **CLOSED (PR #40, CI-enforced)**.
- **Evidence:**
  - `.claude/skills/domain-scaffolding/SKILL.md` rewritten (448 added /
    171 removed). Canonical tree at lines 21-100 lists every directory
    Cookie actually has, including `Projections/`, `Services/`,
    `ReadModels/`, `Repositories/{Entity}Repository.php` **and**
    `Repositories/{Entity}QueryRepository.php`, `ErrorCodes.php`,
    `Entities/{Entity}StateAssertions.php`.
  - Step 4 (lines 259-284) now lists **four** commands including Restore.
  - Step 6 (lines 304-328) lists **seven** events including Restored,
    Activated, Deactivated, StockChanged, all required to extend
    `AbstractDomainEvent`.
  - Step 8 (lines 356-383) puts the write repository at
    `app/Domain/{Domain}/Repositories/` (not Infrastructure) — matches
    integration branch's actual `app/Domain/Cookie/Repositories/CookieRepository.php`.
  - Step 9 (lines 387-397) explicitly retracts the old `Services.php`
    edit: *"You do NOT edit `app/Config/Services.php`… Tag the concrete
    repository class with the `#[AutoBind]` attribute"*.
  - `COMPLETE_FILE_INVENTORY.md` regenerated end-to-end (232 lines
    rewritten). Total file count corrected from 47 → 60+ / 72+. New
    sections for `ReadModels`, `Services`, `Projections`, `StateAssertions`,
    `Snapshot`, `StockChangeReason`.
  - `bin/docs-cookie-sync` (new, 180 LOC) walks
    `app/Domain/Cookie/` and asserts every basename appears in one of
    the two canonical docs (with `{Entity}/{Entities}/{Domain}/{entity}`
    placeholder expansion). Wired into `composer check` and
    `.github/workflows/ci.yml` (+3 lines). Local run today returns
    `OK: 45 Cookie files all documented`.
- **Residual:** Skill explicitly notes (line 12-17) it does *not* yet
  reflect E09, E10, E11, E12, E13, E14 — that's honest scoping, not a
  defect.

### F2 — OPEN (E09 pending) — `CookiePrice` still bakes USD-cents bounds
- **Location:** `app/Domain/Cookie/ValueObjects/CookiePrice.php:22-23, 197-209, 220-223`.
- **Status:** **OPEN, unchanged from round 3**.
- **Evidence:** `MIN_MINOR_UNITS = 1`, `MAX_MINOR_UNITS = 999_999` still
  hard-coded; `defaultCurrency()` still returns `Currency::default()`
  via `fromString`/`fromMinorUnits`/`fromFloat` factory paths
  (`$currency ?? self::defaultCurrency()`). Any cloned `InvoiceTotal` or
  `WageRate` silently caps at $9,999.99 and defaults to USD.
- **Why it survives sed-clone:** literal numeric constants + static call
  to `Currency::default()`; no constructor parameter for either.
- **Suggested fix:** wait for E09 (`Money` migration), or extract the
  bounds into a domain-specific policy class injected at the factory.

### F3 (partial) — OPEN (E14 pending) — English literals across all four views
- **Location:** `app/Views/cookies/index.php:6, 8, 13, 15, 18, 23, 28,
  65-66, 83, 89, 92`; same pattern in `show.php`, `create.php`, `edit.php`.
- **Status:** **OPEN, unchanged**.
- **Evidence:** `grep "lang(" app/Views/cookies/*.php` → zero matches.
  `app/Language/en/` contains only `App.php` and `Validation.php`; no
  `Cookies.php` language file exists. Hard-coded strings: "Cookies",
  "Create New Cookie", "Search cookies...", "Clear", "No cookies
  found.", "View"/"Edit", "Previous"/"Next", `Total: <n> cookies`.
- **Why it survives sed-clone:** pure literal strings in templates with
  no interpolation hook. `LocaleResolver` + `LocaleMiddleware` exist
  under `app/Infrastructure/I18n/` but Cookie does not exercise them.
- **Suggested fix:** E14 ships shared view partials — ship the
  `lang('Cookies.title')` rewrite at the same time, and bundle a
  reference `app/Language/en/Cookies.php`.

### F4 — CLOSED (long ago) — CookieRestoredEventHandler is wired
- **Location:** `app/Domain/Cookie/CookieServiceProvider.php:222-228`.
- **Status:** **CLOSED**, verified again on this re-audit. The provider
  imports `CookieRestoredEventHandler`, the
  `$dispatcher->subscribe(CookieRestoredEvent::class, …)` line is present,
  and the round 3 audit's claim of "still missing" was stale relative to
  even round 2.

### F5 (partial) — OPEN (E09/E10 pending) — Deprecated methods still on the live path
- **Location:** `app/Domain/Cookie/ValueObjects/CookiePrice.php:102, 123`
  (both `@deprecated` per docblock); `app/Domain/Cookie/DTOs/CookieDTO.php:44`
  still calls `$cookie->getPrice()->format()` on the deprecated path.
- **Status:** **OPEN, unchanged**. Cloners still inherit a
  deprecated-but-live codepath.
- **Note:** `CookieReadModelProjection.php.example` is still inert
  (verified), and the two ReadModel migrations
  (`2026-05-20-200000_CreateCookieReadModelTable.php` +
  `2026-05-21-120000_DropCookieReadModelTable.php`) **both still ship**.
  PR #40 closes this *as a documentation problem* (PROJECTIONS.md
  walks through the rationale and the re-enable steps) but does **not**
  delete the dead migrations. A cloner copying `app/Database/Migrations/`
  wholesale will still get the create/drop pair.

### F6 — CLOSED — File-inventory & SKILL.md now place the repo correctly
- **Status:** **CLOSED (PR #40)**.
- **Evidence:** `COMPLETE_FILE_INVENTORY.md:37-39` files #7-#8 are at
  `app/Domain/{Domain}/Repositories/{Entity}Repository.php` and
  `{Entity}QueryRepository.php`. Inventory line 106-109 explicitly
  retracts the old `app/Infrastructure/Persistence/Repositories/` path
  with a note explaining it's retained only for cross-domain adapters.
  SKILL.md step 8 mirrors the same path.

### F7 — PARTIALLY CLOSED — Singular/plural literals now documented
- **Status:** **DOC-CLOSED, code unchanged** (acceptable per round 3
  fix-option (b)).
- **Evidence:** `domain-scaffolding/SKILL.md:172-191` adds a 12-row
  "Singular/plural convention" table mapping every surface (namespace,
  entity class, VO prefix, repository, controller, model namespace, test
  namespace, route prefix, views dir, migration table name, migration
  class name) to singular or plural with concrete examples. Cloners now
  have a single grep-checklist source.

### F8 — OPEN — i18n absent on the example domain (paired with F3)
- See F3 above. Same root cause; E14 epic.

### F9 — OPEN — File-size cap breaches on the reference itself
- **Location:** `app/Domain/Cookie/Entities/Cookie.php` = **348 LOC**;
  `app/Domain/Cookie/Repositories/CookieRepository.php` = **587 LOC**.
- **Status:** **WORSE, not better**. Round 3 measured 288 and 586. E07
  added lifecycle methods to the entity (+60 LOC) so it grew through the
  cap. CLAUDE.md (line 67-68) still says *"Classes ≤ 200 lines (Phase 4
  will enforce mechanically)"*. The reference domain breaches by 174 %
  (Cookie.php) and 293 % (CookieRepository.php).
- **Why this is a template defect:** every cloned domain inherits the
  shape and the breach. Phase 4's mechanical enforcement will reject by
  default.
- **Suggested fix:** E11 (repo hygiene) is queued — push it forward;
  consider extracting `CookieEventPublisher` from the entity at the same
  time.

### F10 — OPEN (E10 pending) — DTOs vs ReadModels are still parallel
- **Location:** `app/Domain/Cookie/DTOs/CookieDTO.php` *and*
  `app/Domain/Cookie/ReadModels/CookieView.php` both exist on the
  integration tree.
- **Status:** **OPEN, explicitly scoped to E10**.
- **Evidence:** PR #40 docs honestly flag it — `COMPLETE_FILE_INVENTORY.md:42-44`
  notes the ReadModel is *"legacy projection shape; to be merged into
  `{Entity}DTO` by E10"*. SKILL.md line 87-89 mirrors the comment. So
  the docs do not pretend the parallel shape is intentional, but until
  E10 ships, cloners still get two abstractions with overlapping
  purpose.

### F11 — CLOSED (E04) — Event payload asymmetry resolved
- **Status:** **CLOSED**, verified.
- **Evidence:** `grep "extends AbstractDomainEvent"` →
  `CookieCreatedEvent` (line 39), `CookieUpdatedEvent` (line 26),
  `CookieDeletedEvent` (line 23), `CookieRestoredEvent` (line 19),
  `CookieStockChangedEvent` (line 32) — *all five*. The new
  `CookieActivatedEvent` and `CookieDeactivatedEvent` (added by E07)
  also extend the base. Five-field envelope (`eventId`/`occurredAt`/
  `actorId`/`aggregateType`/`aggregateId`) is uniform across the seven
  events.

### F12 — PARTIALLY CLOSED — Plural inconsistency documented
- See F7 above; documenting the convention is the round-3-accepted
  resolution. Code unchanged.

### F13 — CLOSED — CLAUDE.md no longer over-promises
- **Status:** **CLOSED on PR #40** (still OPEN on the integration
  branch). On PR #40, lines 13-18 rewrite the bullet to read
  *"Handlers, value objects, repository (write + read sides), events,
  lifecycle methods, optimistic locking, and tests are exemplified
  there. The projection scaffold ships as a `.example` file
  (single-aggregate template does not need it active — see
  `.claude/documentation/PROJECTIONS.md`)"*. The misleading "every
  pattern… is fully exemplified" wording is gone. New "Shared bases"
  bullet (lines 19-31) lists every cross-cutting class under
  `app/Domain/Shared/` (E04/E05/E06).
- **Caveat:** on the integration branch the file still says *"Every
  pattern (handlers, value objects, repository, projections, tests,
  logging) is fully exemplified"* — F13 will land when PR #40 merges.

### F14 — CLOSED — `COOKIE_STATE_NOT_PERSISTED` exists and is used
- **Status:** **CLOSED on integration**, verified again.
- **Evidence:** `ErrorCodes.php:54` defines `COOKIE_STATE_DELETED = 401`
  *and* `ErrorCodes.php:56` defines `COOKIE_STATE_NOT_PERSISTED = 403`.
  `CookieStateAssertions.php:46` uses the former for the deleted check,
  line 62 uses the latter for the persistence check. The round 3
  observation that `assertPersisted()` recycled the wrong code was
  resolved during E07 when the assertions trait was extracted.

### F15 — OPEN (no epic) — `findByIdWithTrashed` only on the write port
- **Location:** `app/Domain/Cookie/Ports/CookieRepositoryInterface.php:78`.
  `CookieQueryRepositoryInterface` has no equivalent.
- **Status:** **OPEN, unchanged**. No epic assigned.
- **Why it stays a template defect:** the read-write boundary
  documented in `cqrs-architecture/SKILL.md` says queries should not
  reach into the write side, but the only way to read a soft-deleted
  Cookie is through the write port. A cloner will copy the asymmetry.
- **Suggested fix:** add `findByIdIncludingDeleted` to the query port
  returning the view DTO, **or** document explicitly why restore
  *must* see the full aggregate (it must, to invoke entity methods —
  the asymmetry is intentional but undocumented).

---

## New findings (round 3 re-audit only — not in original slice 15)

### R1 — HIGH — `bin/docs-cookie-sync` only checks *Cookie* files, not Cookie's *external* touchpoints
- **Location:** `bin/docs-cookie-sync:73-95`.
- **Observation:** The walker iterates `app/Domain/Cookie/` only. It does
  **not** walk `app/Models/Cookie/`, `app/Controllers/Domain/Cookie/`,
  `app/Views/cookies/`, `app/Database/Migrations/*Cookie*`,
  `tests/Unit/Domain/Cookie/`, or `tests/Integration/Repositories/*Cookie*`.
  So if a new `CookieController` method is added or a new view file is
  introduced (or removed), the guard does not catch the drift.
- **Why this is a template defect:** the doc inventory promises 60+
  files spanning domain + application + presentation + persistence +
  tests; the guard only enforces ~45 of them. A future PR can add an
  `app/Controllers/Domain/Cookie/CookieAPIController.php` and the
  inventory will not be updated — CI will pass.
- **Suggested fix:** extend the walker to a tuple of `(root, file-pattern)`
  pairs and add the five additional directories. Twenty extra lines in
  the script.

### R2 — MEDIUM — Migration history still contains the create/drop pair
- **Location:** `app/Database/Migrations/2026-05-20-200000_CreateCookieReadModelTable.php`
  + `app/Database/Migrations/2026-05-21-120000_DropCookieReadModelTable.php`.
- **Observation:** PR #40 closes the *documentation* aspect of slice 15/F2
  (PROJECTIONS.md tells the reader the projection is intentionally
  disabled), but **both migration files still ship**. Net effect on a
  fresh `php spark migrate --all` is identical to the round 3 finding:
  table created on day 1, dropped on day 2, ends in nothing. A new
  cloner copying the Migrations directory wholesale (which is exactly
  what `domain-scaffolding/SKILL.md` step 10 implies) inherits this
  brittleness.
- **Suggested fix:** delete both files on the same PR as E10/E13 land,
  *or* gate the migrations behind a "demo" tag and exclude them from
  the cloner's copy template.

### R3 — MEDIUM — `BusinessMetricsLogging` trait is still Cookie-typed
- **Location:** `app/Models/Cookie/Traits/BusinessMetricsLogging.php:7-8,
  47, 54`.
- **Observation:** Round 3 F3 flagged this. PR #40 does not touch it.
  The trait still:
  - `use App\Domain\Cookie\Entities\Cookie;`
  - `use App\Domain\Cookie\ValueObjects\CookiePrice;`
  - reads `$this->loggingConfig->metricsThresholds['cookie']` at the
    two callsites — the literal `'cookie'` is the lookup key, so cloned
    domains' threshold slices are unreachable without copy-edit.
- **Why this matters:** every cloned domain copies the trait into its
  own namespace and edits the string — the round 3 finding's "shared
  infrastructure that isn't actually shared" claim survives intact.
- **Suggested fix:** Extract `MetricsThresholds` reader into
  `app/Domain/Shared/Logging/MetricsThresholds.php` keyed on `static::class`
  rather than a string literal; keep Cookie-specific log emission in a
  Cookie-named class.

### R4 — LOW — `domain-scaffolding/SKILL.md` description still says "45+ files" while inventory says "60+/72+"
- **Location:** `.claude/skills/domain-scaffolding/SKILL.md:3` —
  description string says *"60+ files/touchpoints"*. The slash-command
  registry under `.claude/skills/domain-scaffolding/` is updated.
  However, the harness-rendered skill list (visible in this session)
  *still* describes the skill as "Scaffolds a complete new domain from
  scratch with full CQRS structure (45+ files/touchpoints…)". So the
  skill registry has two copies of the description string and only one
  is updated.
- **Why this is a template defect:** the boot-time skill description is
  what a future agent will see first; the stale "45+ files" undercount
  will mislead. A 35 % under-count of the cloning footprint is
  non-trivial when planning task fan-out.
- **Suggested fix:** find the second copy (probably in the skill
  registry cache or in `.claude/agents/*.md`) and update it. Already
  fixed inside SKILL.md itself.

### R5 — INFO — `ReadModels/CookieView.php` carries a `$extra = []`
- **Location:** `app/Domain/Cookie/ReadModels/CookieView.php:31-35`.
- **Observation:** Round 3 already flagged the `$extra = []` reserved
  parameter ("reserved for tenant_id, audit fields when those land");
  on the integration branch the parameter is still there. E10 is
  expected to delete `CookieView` entirely so this is a known-temporary
  smell, but recording it for the E10 sweep.

---

## Cookie-specific magic that still survives a `sed s/Cookie/Foo/g`

| file:line | item | status |
|-----------|------|--------|
| `app/Domain/Cookie/ValueObjects/CookiePrice.php:22-23` | `MIN_MINOR_UNITS = 1` / `MAX_MINOR_UNITS = 999_999` | **OPEN** (E09) |
| `app/Domain/Cookie/ValueObjects/CookiePrice.php:220-223` | `defaultCurrency() => Currency::default()` | **OPEN** (E09) |
| `app/Domain/Cookie/ValueObjects/CookieName.php:39-40` | `MIN_LENGTH = 3`, `MAX_LENGTH = 100` coupled to `VARCHAR(100)` | OPEN (no epic) |
| `app/Models/Cookie/Traits/BusinessMetricsLogging.php:7-8, 47, 54` | `use App\Domain\Cookie\Entities\Cookie;` + `metricsThresholds['cookie']` literal | OPEN (R3) |
| `app/Domain/Cookie/Services/PriceFormatter.php:5, 7, 32` | typed against `CookiePrice`, not `Money` | **OPEN** (E09) |
| `app/Domain/Cookie/CookieServiceProvider.php:181` | static `LoggerFactory::create('cookie.events')` literal | OPEN (no epic) |
| `app/Views/cookies/index.php:6, 8, 13, 15, 28, 92` | English literal copy | **OPEN** (E14) |
| `app/Database/Migrations/2025-01-21-000001_CreateCookiesTable.php` | `DECIMAL(10,2)` price column | OPEN (E09) |
| `app/Domain/Cookie/DTOs/CookieDTO.php:26, 55-58` | `formattedPrice` + `isOutOfStock()` baked into DTO | **OPEN** (E10) |
| `app/Domain/Cookie/Repositories/CookieRepository.php:132-137` | English uniqueness error literal | OPEN (no epic) |
| Migrations: `Create/DropCookieReadModelTable` pair | dead create-then-drop pair | OPEN (R2) |

Magic items that *no longer* survive sed-clone (closed by PRs in PR #40's
dependency chain):
- All event payloads now uniform under `AbstractDomainEvent` (F11).
- Repository placement under `Domain/{Domain}/Repositories/` matches the
  docs (F6).
- `Restore`/`StockChanged`/`Activated`/`Deactivated` are now documented
  as standard touchpoints (F11 of the original slice closes the
  Restore-omission half).
- `ErrorCodes::COOKIE_STATE_NOT_PERSISTED` exists; the recycled-code
  anti-pattern is no longer modelled by the reference (F14).
- CLAUDE.md no longer claims "every pattern… fully exemplified" (F13,
  closes on merge of PR #40).

---

## Severity tally

| Severity | Round 3 (original) | Round 3 re-audit |
|---|---|---|
| CRITICAL | 2 | **0** |
| HIGH | 4 | **3** (F2 / R1 / F9) |
| MEDIUM | 5 | **5** (F3 / F5-partial / F10 / R2 / R3) |
| LOW | 3 | **2** (F12 / R4) |
| INFO | 1 | **2** (F15 / R5) |
| **Total findings** | 15 | **12** |
| Closed since round 3 | — | **6** (F1, F4, F6, F11, F13, F14) |
| Partially closed | — | **2** (F7, F12 — doc-level) |
| New (re-audit) | — | **5** (R1–R5) |

## Verdict shift

Round 3: **NOT-READY** (the docs lie about Cookie).
Re-audit: **MOSTLY-READY**. The docs no longer lie; CI guards drift
mechanically. Remaining defects are scoped to known pending epics
(E09/E10/E11/E14) or to the orphan "shared infra in Cookie namespace"
problem.

## Biggest residual

**F9 — the reference domain breaches its own size cap, and the breach
grew** (Cookie.php 288 → 348 LOC because E07 added lifecycle methods;
CookieRepository.php steady at 586-587 LOC). The doc says "classes ≤ 200
lines, Phase 4 enforces mechanically". When Phase 4 lands, the reference
domain will fail its own gate — and every cloned domain along with it.
E11 must ship before Phase 4 does.

## Could a new domain be cloned today? (one-liner)

Yes, structurally — `/add-domain Order` now scaffolds a 60+ file tree
that matches Cookie's real shape and `composer check` will pass — but
the cloner inherits Cookie's USD-cents bounds, English-only views, dead
ReadModel migration pair, and a `BusinessMetricsLogging` trait keyed on
the literal `'cookie'`, so the clone is *runnable* but technically wrong
for any non-USD, non-retail, non-English domain until E09+E10+E11+E14
ship.
