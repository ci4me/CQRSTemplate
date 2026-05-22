# Round 3 Audit — Cookie Domain as Reference Template
## Consolidated Report (v2 — covers all 18 slices)

**Date:** 2026-05-22
**Method:** 18 parallel specialist audits, two-pass synthesis (v1 covered 1–15; v2 expands to 16–18, re-themes, and re-deduplicates)
**Source:** `.audit/round3/01-entity-aggregate.md` through `.audit/round3/18-mysql-database.md`
**Companion:** [`REMEDIATION-PLAN.md`](./REMEDIATION-PLAN.md)

---

## Executive summary

The Cookie domain is **structurally a credible reference template** — the
folder shape, hexagonal port placement, `#[DomainServiceProvider]` +
`#[AutoBind]` auto-discovery, named-factory VOs, optimistic locking,
transactional outbox, and round 2's previously-CRITICAL gaps (missing
`CookieRestoredEventHandler`, silent dead projection) have all landed. But
**it is not yet safe to clone**. The 18 slices together raise **17
CRITICAL**, **74 HIGH**, **76 MEDIUM**, **51 LOW**, and **24 INFO** raw
findings (242 raw findings → roughly **140 canonical findings** after
dedup). Cloning today via `/add-domain Foo` or `sed s/Cookie/Foo/g`
produces a domain that fails the project's own gates in at least seven
reproducible ways: scaffolding skill is two refactors stale, event
dispatch forks into three competing patterns, multi-currency money is
contradicted by `DECIMAL(10,2)` persistence, the entire integration suite
is locked to SQLite via `phpunit.xml.dist:67 force="true"`, the
`docblocks:audit` gate the docs promise is silently a no-op, PHPStan has
no `phpVersion` pinned (PHP 8.4 syntax can silently land in code targeting
8.3), and the MySQL operational story (sql_mode, isolation, charset,
ROW_FORMAT, outbox truncation, no `event_uuid` UNIQUE) is in pieces.

The findings collapse into **roughly nineteen root-cause themes** (T1–T19),
refined and expanded from v1's fourteen. Three new CRITICALs emerged from
the MySQL audit (slice 18): `event_outbox.status VARCHAR(16)` silently
truncates `'unsupported_schema'` (F-O8) on any non-strict server because
no `sql_mode` is pinned; the outbox table has no `event_uuid` UNIQUE so
retry-after-rollback double-delivers (F-I2); and the entire MySQL
connection envelope (sql_mode / isolation / charset / collation /
ROW_FORMAT) is unpinned (F-C1/F-C2/F-C3/F1). Slice 16 surfaced a
silently-broken documentation gate: `composer docblocks:audit` is wired
into `composer check` but **CI does not invoke it**, and the audit script
no longer catches the 26 placeholder docblocks that ship in Cookie. Slice
17 surfaced the **PHPStan-missing-`phpVersion`** footgun: a contributor on
a PHP 8.4 host can land property hooks or asymmetric visibility into code
that the README says targets `^8.3`, with zero CI warning.

Slice-level verdict spread is **6 NOT-READY** (03, 07, 08, 13, 15, 16, 18 — 7
NOT-READY in fact) and **11 READY-WITH-FIXES**. No slice declared READY.
The aggregate verdict is therefore **NOT-READY-TO-CLONE** until the
unblocker themes T15 (docs gate), T16 (PHP 8.3 idiom pin), T17 (PHP 8.4
opportunities deferred but unpinned), T18 (MySQL connection envelope),
and T19 (outbox data-correctness) are addressed alongside the structural
foundation themes T1–T6.

The good news: **the foundation themes remain small**. Three abstract
bases (`AbstractDomainEvent`, `AbstractCommandHandler`,
`AbstractQueryHandler`), one shared `ReadDTOInterface`, one money-schema
decision, one scaffolding regeneration, one MySQL CI lane, one
sessionVariables block, and one outbox-table migration close roughly
**70 % of the HIGH/CRITICAL surface**. The remaining ~30 % is per-slice
cleanup that flows naturally once the foundations are in place.

---

## Verdict matrix

| # | Slice | Reviewer | Verdict | CRIT | HIGH | MED | LOW | INFO | Top finding (one line) |
|---|---|---|---|---|---|---|---|---|---|
| 01 | Entity & aggregate root | ddd-specialist | READY-WITH-FIXES | 0 | 4 | 5 | 2 | 1 | `activate/deactivate` raise no events; no `softDelete/restore` on entity |
| 02 | Value Objects (Name/Price/Stock) | ddd-specialist | READY-WITH-FIXES | 1 | 3 | 3 | 2 | 1 | `CookiePrice` USD-cents bounds applied to every currency |
| 03 | Commands & write handlers | cqrs-specialist | **NOT-READY** | 2 | 6 | 5 | 2 | 1 | Three competing event-dispatch patterns inside one domain |
| 04 | Queries & read handlers | cqrs-specialist | READY-WITH-FIXES | 0 | 3 | 6 | 3 | 1 | Logging boilerplate duplicated 3× per query handler |
| 05 | Events & dispatch lifecycle | cqrs-specialist | READY-WITH-FIXES | 0 | 2 | 4 | 3 | 1 | 5 events, 5 different envelope shapes; no `eventId`/`occurredAt` |
| 06 | Repository ports & adapters | ddd-specialist | READY-WITH-FIXES | 0 | 4 | 6 | 5 | 2 | `existsByName` contradicts schema's reuse-after-soft-delete |
| 07 | DTOs & ReadModels | cqrs-specialist | **NOT-READY** | 2 | 5 | 4 | 3 | 0 | `CookieDTO` + `CookieView` both ship; one is dead code |
| 08 | Service provider & DI wiring | codeigniter4-specialist | **NOT-READY** | 3 | 5 | 6 | 4 | 1 | Provider hardcodes controllers namespace string + magic-string DI |
| 09 | Controller & HTTP layer | codeigniter4-specialist | READY-WITH-FIXES | 1 | 3 | 5 | 5 | 1 | Route group has no `web_auth` filter; relies on URI deny-list |
| 10 | Views, XSS, CSRF, a11y | codeigniter4-specialist | READY-WITH-FIXES | 0 | 4 | 4 | 5 | 2 | Views call `formattedPrice` / `isOutOfStock()` not on `CookieView` |
| 11 | Migrations & schema | codeigniter4-specialist | READY-WITH-FIXES | 0 | 3 | 4 | 4 | 2 | `price DECIMAL(10,2)` discards `Money` currency |
| 12 | Unit tests | test-specialist | READY-WITH-FIXES | 0 | 3 | 3 | 4 | 2 | 19 unit tests open real `writable/logs/app.json` |
| 13 | Integration & feature tests | test-specialist | **NOT-READY** | 2 | 5 | 3 | 5 | 2 | `phpunit.xml.dist:67` force-locks DB to SQLite |
| 14 | Clean code & PHP usage | clean-code-specialist | READY-WITH-FIXES | 0 | 6 | 7 | 7 | 3 | Handler boilerplate duplicated 4×; methods 3–5× over 20-line cap |
| 15 | Template cloneability (meta) | general-purpose | **NOT-READY** | 2 | 4 | 5 | 3 | 1 | Scaffolding skill describes a Cookie that hasn't existed for 2 refactors |
| 16 | Documentation & docblocks | general-purpose | **NOT-READY** | 0 | 4 | 4 | 4 | 3 | docblocks:audit no-op + CI doesn't run it + 26 placeholder docblocks |
| 17 | PHP 8.4 optimization | php-specialist | READY-WITH-FIXES | 0 | 3 | 7 | 8 | 2 | PHPStan has no `phpVersion` pin → PHP 8.4 syntax can land in 8.3 code |
| 18 | MySQL / database | codeigniter4-specialist | **NOT-READY** | 3 | 10 | 13 | 6 | 2 | `event_outbox.status VARCHAR(16)` truncates `'unsupported_schema'` |
| **Total raw** | | | **7 NOT-READY / 11 RWF** | **17** | **74** | **76** | **51** | **24** | |

> Raw totals are pre-dedup. Many findings repeat across slices (e.g.
> `existsByName` issue raised in 06/F1 + 11/F3 + 18/F-T1); the canonical
> dedup section below collapses these.

---

## Cross-cutting themes (v2)

### T1 — No abstract base for domain events; payload asymmetry
- **Symptom across slices:** `01/F12, 03/F1, 05/F1, 05/F2, 05/F3, 05/F5,
  05/F6, 05/F9, 12/F6, 14/F18, 15/F11`
- **Root cause:** `DomainEventInterface` is an intentional marker only.
  The five Cookie events therefore each define their own constructor shape:
  Created carries no actor / no timestamp; Updated has `updatedBy`; Deleted
  has `deletedBy` + snapshot; Restored has `restoredBy` + a stringly-typed
  `restoredAt`; StockChanged has neither and uses `?int $cookieId`. No
  event carries an `eventId` (no idempotency anchor), no event carries an
  `occurredAt: DateTimeImmutable` (envelope-only). The split-dispatch
  model (some events raised by entity, some by handlers) compounds the
  asymmetry.
- **Why it propagates per clone:** A `sed`-cloner reads whichever event
  file they open first and reproduces that envelope. Multiplied per future
  domain × ~5 events = guaranteed inconsistency across the ERP. The outbox
  relay supports retries with backoff (up to 6 attempts) but has no
  idempotency anchor at the event level, so any future side-effect handler
  (email, webhook) will double-send. Combined with T19 (no `event_uuid`
  UNIQUE on the outbox), the relay cannot dedup either.
- **Severity rollup:** 1 CRITICAL + 4 HIGH + 5 MEDIUM/LOW = 10 findings.
- **Resolution sketch:** Introduce `abstract readonly class
  AbstractDomainEvent` carrying `public string $eventId` (UUIDv7),
  `public \DateTimeImmutable $occurredAt`, `public ?int $actorId`. Require
  every event to extend it. Unify dispatch — entity raises all lifecycle
  events; handlers drain via `pullEvents()`; repository stops draining.
  Codify in `domain-scaffolding` SKILL.

### T2 — No abstract handler base; 70+ lines of boilerplate per handler
- **Symptom across slices:** `03/F3, 03/F6, 03/F11, 03/F12, 03/F14, 03/F16,
  04/F1, 04/F3, 04/F7, 04/F10, 04/F12, 12/F1, 14/F1, 14/F2, 14/F3, 14/F12,
  14/F20, 14/F21, 17/F2`
- **Root cause:** All four command handlers and all three query handlers
  hand-code the same scaffold (`startTime`, info-start, try, body, durationMs,
  info-success, catch `\Throwable`, `determineErrorCode($e)`, error-log,
  rethrow). Each handler is 44–94 lines, 3–5× the project's 20-line method
  cap. The `CommandBus`/`QueryBus` are duck-typed (`method_exists`); no
  `CommandHandlerInterface<TCommand>` or `QueryHandlerInterface<TQ,TR>`
  contract is enforced. Slow queries are logged at `info` rather than
  `warning`. `mt_rand()` sampling is duplicated three times and is also
  numerically biased (F2 of slice 17). Time bases drift (`microtime` vs
  `hrtime`).
- **Why it propagates per clone:** Every cloned domain pays this
  duplication 4× (commands) + 3× (queries) on day one. The CLAUDE.md
  "≤ 20 lines per method" rule is structurally impossible to honour.
  PHPStan can't catch a handler that returns the wrong type.
- **Severity rollup:** 1 HIGH (mt_rand) + 6 HIGH + 6 MEDIUM + 5 LOW = ~18
  findings.
- **Resolution sketch:** `AbstractCommandHandler` with
  `withLogging(string $commandName, array $context, callable $body)`
  encapsulating startTime/try/catch/durationMs/error-code-from-`getErrorCode()`.
  Same shape `AbstractQueryHandler` with sampling/slow-query escalation/
  log-policy enum. Enforce `*HandlerInterface` on bus registration. Delete
  the `str_contains(...)` error-code resolver (T6).

### T3 — Multi-currency contradiction across VOs, schema, and defaults
- **Symptom across slices:** `02/F1, 02/F2, 02/F5, 05/F9, 07/F2, 07/F3,
  07/F14, 11/F1, 14/F8, 14/F9, 15/F4, 18/F2`
- **Root cause:** `Money` was deliberately built to refuse currency
  defaults — explicit `Currency` is required at every factory.
  `CookiePrice` undoes that one layer up: three factories accept
  `?Currency = null` and fall through to `Currency::default()` (env then
  USD). `MIN_MINOR_UNITS = 1` / `MAX_MINOR_UNITS = 999_999` are USD-cents
  semantics applied uniformly to JPY (cap = ¥999,999 ≈ $6.7k) and BHD
  (cap = 999.999 BHD ≈ $2.6k). The canonical `cookies` table stores
  `price DECIMAL(10,2)` with no currency column, discarding the currency
  on every write and capping magnitude at ~99,999,999.99. The
  previously-dropped read-model migration knew this and stored
  `price_minor INT` + `price_currency CHAR(3)`; that migration was
  reverted. `PriceFormatter` is `@deprecated`-pointed-to but bypassed by
  every caller; `CookiePrice::format()` is `@deprecated` but still called
  by `CookieDTO::fromEntity()` and `CookieQueryRepository::formatPrice()`.
- **Why it propagates per clone:** Every cloned monetary VO inherits a
  multi-currency-shaped wrapper around single-currency-bounded validation,
  a silent USD default, a lossy DECIMAL schema, and a deprecation arrow
  pointing at a service no one uses.
- **Severity rollup:** 1 CRITICAL + 5 HIGH + 5 MEDIUM + 1 LOW = 12
  findings.
- **Resolution sketch:** Keep `Money`/`Currency` requirement; drop
  `Currency::default()`; make currency a required factory parameter;
  recompute bounds per-call from `$currency->decimals`. Change the schema
  to `price_minor BIGINT UNSIGNED NOT NULL` + `price_currency CHAR(3) NOT
  NULL`. Either route every formatter call through `PriceFormatter` and
  remove the `@deprecated` from `CookiePrice::format()` once unused, or
  delete `PriceFormatter` and drop the deprecation.

### T4 — Read-side identity crisis (CookieDTO vs CookieView)
- **Symptom across slices:** `04/F9, 07/F1, 07/F4, 07/F5, 07/F6, 07/F7,
  07/F8, 07/F9, 07/F10, 07/F11, 07/F12, 07/F13, 07/F14, 10/F1, 14/F10,
  14/F11, 15/F10`
- **Root cause:** Two parallel read-DTOs coexist. `CookieDTO` is the
  production runtime path (returned by all three query handlers, consumed
  by every view). `CookieView` is dead code outside its own unit test. They
  overlap on basic fields but diverge on `formattedPrice` / `isOutOfStock()`,
  `version/deletedAt/isDeleted/isAvailable/$extra`. Neither implements
  `JsonSerializable`; key cases disagree (camelCase vs snake_case).
  `CookieView::detail()` silently coerces null id → 0. `CookieView::$extra`
  is constructor-accepted but `toArray()` drops it. Neither offers a
  `fromRow(array)` factory. The views (`cookies/index.php`, `show.php`)
  call methods that exist only on `CookieDTO`.
- **Why it propagates per clone:** A cloner copying "the DTO" gets
  camelCase JSON + presentation methods on a class that claims to carry
  no behaviour. A cloner copying "the View" gets snake_case JSON + dead
  state + missing presentation. A cloner copying both gets both.
- **Severity rollup:** 2 CRITICAL + 5 HIGH + 6 MEDIUM/LOW = 13 findings.
- **Resolution sketch:** Delete `CookieView`; fold `version/deletedAt/
  isDeleted/isAvailable` and a `summary()` factory into `CookieDTO`;
  add `fromRow()`; convert `isOutOfStock()` from method into precomputed
  `bool $outOfStock`; implement `JsonSerializable` with snake_case output;
  introduce `app/Domain/Shared/DTOs/ReadDTOInterface`.

### T5 — Scaffolding skill / docs / inventory are stale
- **Symptom across slices:** `08/F4, 14/F23, 15/F1, 15/F6, 15/F11, 15/F13,
  16/F2, 16/F3, 16/F7`
- **Root cause:** `.claude/skills/domain-scaffolding/SKILL.md`,
  `.claude/documentation/COMPLETE_FILE_INVENTORY.md`, and
  `.claude/documentation/SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md` all
  describe a 2024-vintage Cookie that pre-dates Phase 4 (StockVO
  extraction), the Restore command, the StockChanged event, the
  QueryRepository split, the ReadModels directory, the ErrorCodes class,
  the Accessors trait, the two logging traits, the Projection-as-`.example`
  pattern, `#[AutoBind]` auto-discovery, `EventOutboxWriter`, and
  `TenantContext`. The docs say "step 9 — add Repository to Services.php" —
  Cookie doesn't do that anymore. The docs put the repository at
  `app/Infrastructure/Persistence/Repositories/`; Cookie has it at
  `app/Domain/Cookie/Repositories/`. `DomainServiceProviderInterface` is
  missing a `registerProjections()` hook the comment in `Services.php`
  recommends using. The Serena analysis claims 23 files; reality is 38+.
- **Why it propagates per clone:** `/add-domain Order` today produces a
  skeleton that **the project's own `composer check` would reject**: no
  `version` column (optimistic lock can't work), no `tenant_id`, no
  `ErrorCodes` class (handlers reference undefined constants).
- **Severity rollup:** 1 CRITICAL + 3 HIGH + 5 MEDIUM/LOW = 9 findings.
- **Resolution sketch:** Regenerate SKILL.md and inventory **from** the
  current Cookie tree (`find app/Domain/Cookie -type f`). Add Restore +
  StockChanged + ReadModels + Services + ErrorCodes + Accessors + QueryRepo
  + logging traits + projection-`.example` + outbox/tenant optional deps.
  Switch step 8/9 to `#[AutoBind]`. Add
  `DomainServiceProviderInterface::registerProjections()`. Either refresh
  or delete `SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md`. Pin a CI rule that
  fails when Cookie changes without docs.

### T6 — Hard-coded domain literals and stringly-typed DI
- **Symptom across slices:** `03/F12, 04/F10, 06/F2, 06/F3, 08/F1, 08/F2,
  08/F5, 08/F9, 08/F11, 08/F14, 08/F15, 11/F8, 14/F5, 14/F12, 15/F3, 15/F7,
  15/F12`
- **Root cause:** Three classes of literal survive `sed s/Cookie/Foo/g`
  wrongly: (a) lowercase tokens like `'cookie'` (metric slice key in
  `BusinessMetricsLogging`), `'cookie.events'` (log channel); (b) plural
  tokens like `'cookies'` (route prefix, table name, view directory,
  controller redirects); (c) DI keys like `'cookieRepository'` / `'logger'`
  / `'loggingConfig'` resolved through `getRepository(string)` which
  silently undefined-index-faults on typos. `BusinessMetricsLogging` /
  `RepositoryLogging` traits hard-code `'domain' => 'Cookie'` in 12+ log
  payloads, live under `App\Models\Cookie\Traits\` while pretending to be
  reusable, and import `Cookie` and `CookiePrice` directly.
- **Why it propagates per clone:** Logs misattribute cloned domains as
  `'Cookie'`; metric thresholds silently fall back to Cookie defaults for
  `Customer`/`Order`; DI typo errors point at the wrong line; cloned
  providers are forced to be 75+ lines because the `instanceof` dance is
  mandatory.
- **Severity rollup:** 3 CRITICAL + 6 HIGH + 8 MEDIUM/LOW = 17 findings.
- **Resolution sketch:** Replace `getRepositories()`/`setRepositories()` +
  `getRepository(string)` with constructor injection. Derive log channel
  via convention. Move `PriceFormatter` → `Domain/Shared/Services/
  MoneyFormatter`. Move `RepositoryLogging` → `Domain/Shared/Logging/`.
  Decompose `BusinessMetricsLogging` into generic + Cookie-scoped. Pull
  `URI_SEGMENT` and `CONTROLLER_NAMESPACE` to provider constants;
  reference `CookieController::class`.

### T7 — Lifecycle events missing on the entity; soft-delete leaks
- **Symptom across slices:** `01/F1, 01/F2, 01/F3, 01/F7, 01/F9, 03/F1,
  15/F11`
- **Root cause:** `Cookie::activate()` and `Cookie::deactivate()` flip
  `$isActive` and assert-not-deleted but raise no event. `Cookie` has no
  `softDelete()` / `restore()` methods — setting `deletedAt` is the
  repository's job, breaking the "command method, not setter" rule.
  `Cookie::update()` returns silently pre-persist, dropping its
  `CookieUpdatedEvent` on the floor. The entity's docblock promises
  "every public mutator raises ≥ 1 event"; three don't.
- **Why it propagates per clone:** Cloned domains repeat the asymmetry —
  the entity guards against `deleted` state but cannot itself transition
  into or out of it. Audit trails will be silently incomplete; catalog /
  inventory / search-index consumers cannot react to activate/deactivate.
- **Severity rollup:** 3 HIGH + 2 missing tests = 5 findings.
- **Resolution sketch:** Add `softDelete()`, `restore()`,
  `CookieActivatedEvent`, `CookieDeactivatedEvent` — codify "every public
  mutator raises ≥ 1 event" in `domain-scaffolding`. Repository's
  `delete()` calls `$cookie->softDelete()` then persists. Decide whether
  pre-persist `update()` is allowed; guard or queue accordingly.

### T8 — `existsByName` contradicts the schema's reuse-after-delete contract
- **Symptom across slices:** `06/F1, 06/F6, 11/F3, 18/F-T1 (partial)`
- **Root cause:** `CookieModel::existsByName()` calls `withDeleted()` AND
  wraps the column in `LOWER(name)`. Both wrong. (a) `withDeleted()` rejects
  a name that matches a soft-deleted row, but the migration's docblock and
  the composite UNIQUE `(tenant_id, name, deleted_at)` say soft-deleted
  rows do **not** block creation (B16/B17). (b) `LOWER()` defeats the index
  that `utf8mb4_unicode_ci` collation already makes case-insensitive.
- **Why it propagates per clone:** Every cloned domain inherits a
  uniqueness rule that disagrees with its own DB schema *and* runs O(N)
  on every check.
- **Severity rollup:** 2 HIGH + 1 LOW = 3 findings.
- **Resolution sketch:** Drop `withDeleted()` from both `existsByName*`
  methods. Drop `LOWER()`. Update the model docblock to match the
  migration's "names released after soft delete" contract.

### T9 — Repository hygiene: trusted reconstitution, LIKE escape, single-statement delete
- **Symptom across slices:** `04/F4, 06/F4, 06/F7, 06/F8, 06/F9, 06/F10,
  06/F13`
- **Root cause:** `CookieRepository::toDomainEntity()` re-runs full VO
  validation on every row, so a single corrupt legacy row throws
  `ValidationException` mid-list. The read path's `LIKE` is unescaped.
  `delete()` does SELECT-then-UPDATE-twice (three round-trips).
  `restore()` bypasses optimistic locking entirely.
- **Why it propagates per clone:** Read paths that throw on legacy data
  poison every paginated list. `%`/`_` injection becomes the search recipe
  every domain copies. Restore is a state mutation without version bump,
  letting stale entities silently overwrite restored rows.
- **Severity rollup:** 1 HIGH + 6 MEDIUM/LOW = 7 findings.
- **Resolution sketch:** Add `CookieName::fromTrusted` /
  `CookiePrice::fromTrusted` skipping validation; use in `toDomainEntity`.
  Escape `%`/`_`/`\` before passing to `like()`. Collapse `delete()` into
  single conditional UPDATE checking `affectedRows()`. Bump version in
  `restore()`'s UPDATE payload and WHERE clause. Remove `findAll` /
  `findPaginated` from the write port.

### T10 — Controller/HTTP: auth filter not on route group, generic-Throwable leak
- **Symptom across slices:** `09/F1, 09/F2, 09/F3, 09/F5, 09/F7, 09/F12,
  09/F13, 09/F14, 10/F3, 13/F5, 13/F15`
- **Root cause:** Route group registered with `['namespace' => …]` only —
  no `filter`. Authentication is provided by URI-pattern allow-list in
  `Filters.php`. A cloner that runs `sed` on the provider but forgets to
  edit `Filters.php` ships an open-by-default surface. Controller catches
  only `ValidationException` / `DomainException` — any `RuntimeException`
  / `TypeError` from a handler reaches CI4's default error renderer. No
  constructor injection (per-action `Services::*()` calls). Not `final`.
  `(bool) $isActive` accepts `"false"` as true. `ActorResolver` returns
  `Actor::system()` for anonymous callers. Views render Create/Edit/Delete
  buttons without `can()` gating. CSRF is silently disabled in test env.
- **Why it propagates per clone:** Open-by-default routes,
  anonymous-system audit attribution, stack-trace leakage in dev — all
  cloned forward.
- **Severity rollup:** 1 CRITICAL + 3 HIGH + 7 MEDIUM/LOW = 11 findings.
- **Resolution sketch:** Attach `'filter' => 'web_auth'` on route group in
  `registerRoutes()`. Remove `cookies/*` from `Filters.php`. Refactor
  controller to constructor injection. Final class. Generic
  `catch (\Throwable $e)`. `filter_var(..., FILTER_VALIDATE_BOOLEAN)`.
  `ActorResolver::resolveOrFail()` default. Wire `can()` checks into view
  action buttons.

### T11 — Test infrastructure: SQLite-locked, real-FS unit tests, content-blind features
- **Symptom across slices:** `12/F1, 12/F3, 12/F4, 13/F1, 13/F2, 13/F3,
  13/F4, 13/F5, 13/F6, 13/F8, 13/F9, 13/F10, 13/F11, 13/F13, 13/F14, 13/F17,
  18/F-T1`
- **Root cause:** `phpunit.xml.dist:67` forces `database.tests.DBDriver =
  SQLite3` with `force="true"`. Optimistic-locking tests pass only because
  SQLite serialises writes; MySQL `affectedRows()` counts rows-CHANGED
  (not MATCHED) so an idempotent re-save silently throws
  `concurrentModification`. The composite UNIQUE
  `(tenant_id, name, deleted_at)` is never exercised — NULL-vs-NULL gap
  unseen. 19 unit tests call `LoggerFactory::create()` which opens a real
  `RotatingFileHandler` on `writable/logs/app.json`. 11 event-handler tests
  assert only `assertTrue(true)`. Feature tests `assertSee('cookies/index')`
  — the literal view-path string. One test uses `sleep(1)`. CSRF disabled
  in test env. `IntegrationTestCase` eagerly constructs `CookieRepository`
  for every test. The MySQL audit (slice 18 F-T1) enumerates which
  MySQL-specific behaviours this hides: VARCHAR truncation, FK action
  semantics, FOR UPDATE SKIP LOCKED, JSON validation, sql_mode, collation.
- **Why it propagates per clone:** Every cloned domain inherits the same
  `phpunit.xml.dist`, false-confidence test shape, and FS pollution. Every
  MySQL claim of the template is unverifiable.
- **Severity rollup:** 3 CRITICAL + 8 HIGH + 6 MEDIUM/LOW = 17 findings.
- **Resolution sketch:** Drop `force="true"` from `database.tests.DBDriver`;
  add MySQL CI lane. Forbid `LoggerFactory` import from `tests/Unit/`.
  Convert 11 event-handler tests to log-shape assertions. Replace
  `assertSee('cookies/…')` with content assertions. Extract 8 mocked
  methods. Remove `sleep(1)`. Add missing tests: idempotent re-save,
  NULL-uniqueness, restore-conflict.

### T12 — Migration/schema: money column, FK absence, charset, seeder bypasses VOs
- **Symptom across slices:** `06/F11, 11/F2, 11/F4, 11/F5, 11/F6, 11/F9,
  11/F10, 11/F11, 11/F12, 15/F2`
- **Root cause:** Beyond T3's money issue: no `addForeignKey()` on
  `created_by` / `updated_by` / `deleted_by` (users exists); no explicit
  `ENGINE = InnoDB` / `DEFAULT CHARSET = utf8mb4` / `COLLATE = utf8mb4_
  unicode_ci` on `createTable()`; `created_at` is nullable; `version`
  defaults to `0` (vs `1`); the Create-then-Drop read-model migration pair
  (one day apart) is forever in `migrate:status`; `CookieSeeder` uses raw
  `insertBatch()` bypassing VOs, missing `tenant_id`/`version`/`created_by`.
  Composite UNIQUE only deduplicates when both rows agree on a non-NULL
  `deleted_at` AND non-NULL `tenant_id`.
- **Why it propagates per clone:** Schema integrity at the cloned domain
  begins broken. Seeder pattern is contagious.
- **Severity rollup:** 3 HIGH + 5 MEDIUM/LOW = 8 findings.
- **Resolution sketch:** Add FKs. Pin `ENGINE`/`CHARSET`/`COLLATE` on every
  `createTable`. Make `created_at`/`updated_at` `NOT NULL`. Default
  `version` to `1`. Squash the Create+Drop pair. Rewrite seeder to dispatch
  `CreateCookieCommand`. Make `tenant_id` `NOT NULL DEFAULT 0`.

### T13 — Views: i18n missing, partials not wired, can() gating absent
- **Symptom across slices:** `10/F2, 10/F3, 10/F4, 10/F5, 10/F6, 10/F7,
  10/F8, 10/F9, 10/F14, 10/F15, 15/F8`
- **Root cause:** Hard-coded English strings in 35+ user-visible places.
  `lang()` half-adopted — shell uses it; entity views don't. No
  `app/Language/en/Cookies.php`. `partials/_pagination.php` exists but
  unused; views re-implement inline. Empty-state markup duplicated.
  Action buttons render without `can()` gating. `show.php:36` renders
  HTML in a ternary. Bootstrap Icons referenced but never loaded. No
  `$title`. Two layouts (`layout.php` + `layouts/shell.php`) for same
  render.
- **Why it propagates per clone:** Cloned views re-roll the same
  untranslatable copy, inline pagination, un-gated buttons. The
  infrastructure exists; reference views ignore it.
- **Severity rollup:** 4 HIGH + 6 MEDIUM/LOW = 10 findings.
- **Resolution sketch:** Create `app/Language/en/Cookies.php`. Replace
  hard-coded strings. Wrap every action button in `<?php if (can('cookies.…'))
  ?>`. Wire `partials/_pagination`. Extract `partials/_empty_state`. Load
  Bootstrap Icons CSS or drop the `<i>` tags. Pass `$title`.

### T14 — Missing tests (coverage gaps in VOs, services, factories)
- **Symptom across slices:** `12/F2, 12/F7, 12/F12, 13/F7, 13/missing-1..14`
- **Root cause:** No `CookieStockTest`, no `PriceFormatterTest`, no
  `CookieAccessorsTest`, no `ErrorCodesTest`. `CookieStock` is a Phase-4
  split tested transitively only. `CookieFactory` itself has no test —
  silent-drop bug (`version` override ignored) and `priceFromMixed('')`
  trap are uncovered. `Cookie::bumpVersion()` has no direct test.
  `activate()`/`deactivate()` event emission is uncovered. Integration
  tests miss: idempotent re-save, composite-UNIQUE NULL-vs-NULL, restore-
  conflict, tenant write-isolation, pagination beyond-last-page, CSRF
  rejection.
- **Why it propagates per clone:** Cloners see "no test for `CookieStock`"
  and conclude their `FooStock` doesn't need one either. 90 % coverage
  gate is harder to hit because the missing leaves branches uncovered.
- **Severity rollup:** 2 HIGH + ~14 missing-test entries.
- **Resolution sketch:** Add the four missing unit-test files. Add
  `CookieFactoryTest`. Add three MySQL-conditional integration tests
  (`markTestSkipped` until MySQL CI lane lands).

### T15 — **NEW** Documentation gate silently no-op; placeholder docblocks shipped
- **Symptom across slices:** `16/F1, 16/F4, 16/F5, 16/F6, 16/F8, 16/F9,
  16/F10, 16/F11, 16/F12, 16/F13, 16/F14`
- **Root cause:** `bin/docblocks-generate` deliberately emits single-word
  placeholder docblocks (`* findById.`, `* __construct.`, …) and the
  companion `bin/docblocks-audit` script only greps for an older marker
  (`@todo Auto-generated docblock`) that the generator no longer emits.
  Twenty-six placeholders ship in Cookie scope. CI does **not** invoke
  `composer docblocks:audit` or `composer ci` (only `phpcs`/`phpstan`/
  `phpunit`). CLAUDE.md asserts the audit is a gate; the gate is a no-op.
  Adjacent defects: `CookieRepository::@package` is wrong (`App\Models\
  Cookie`, should be `App\Domain\Cookie\Repositories`);
  `RestoreCookieHandler`'s class docblock is a one-word stub on the most
  divergent handler; port interface docblocks (`findById`, `existsByName`,
  `delete`) don't describe the soft-delete contract.
- **Why it propagates per clone:** Cloners inherit (a) the placeholder
  backlog, (b) the broken audit script, (c) the CI-skip-gate. Every new
  domain ships with 20+ empty docblocks on day one.
- **Severity rollup:** 4 HIGH + 4 MEDIUM + 4 LOW + 3 INFO = 15 findings.
- **Resolution sketch:** Extend `bin/docblocks-audit` to also fail on
  `\* (\w+)\.$\n\s*\*/` (single-word block where the only content is the
  method name with a period). Wire `composer ci` into
  `.github/workflows/ci.yml`. Fix the 26 placeholder docblocks. Fix the
  wrong `@package`. Rewrite the port interface docblocks to describe the
  soft-delete contract. Refresh or delete
  `SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md`.

### T16 — **NEW** PHP 8.3 idiom gaps + PHPStan-missing-phpVersion footgun
- **Symptom across slices:** `17/F1, 17/F2, 17/F3, 17/F4, 17/F5, 17/F6,
  17/F7, 17/F8, 17/F9, 17/F10, 17/P1, 17/P2, 17/P3, 17/P5`
- **Root cause:** PHPStan has **no `phpVersion`** pinned in `phpstan.neon`,
  so on a developer host running PHP 8.4, 8.4-only syntax (property hooks,
  asymmetric visibility) is admitted into code targeting `^8.3` without
  warning. Adjacent idiom gaps: `CookieName::MIN_LENGTH`/`MAX_LENGTH` lack
  typed-const types while every other const in the codebase uses
  typed-const-8.3 form. No `#[\Override]` anywhere (~25 sites that need
  it). VOs with `__toString()` don't declare `implements \Stringable`.
  `CookieController` and `CookieModel` are not `final`. `mt_rand()` for
  sampling (also biased: `mt_rand() / mt_getrandmax()` includes 1.0).
  `EventOutboxRelay::describeListener()` 19 lines of branching. `microtime`
  vs `hrtime` drift across handlers. Two `@deprecated` docblock tags that
  the engine can't enforce.
- **Why it propagates per clone:** Every cloned domain inherits all of
  these (the inconsistent typed-const, the no-`#[\Override]`, the
  non-final controller, the biased sampler).
- **Severity rollup:** 3 HIGH + 7 MEDIUM + 8 LOW + 2 INFO = 20 findings.
- **Resolution sketch:** Pin `parameters.phpVersion: 80300` in
  `phpstan.neon` (and `<config name="php_version" value="80300"/>` in
  `phpcs.xml`). Add `#[\Override]` to every interface/parent-method
  implementation in Cookie. Add `implements \Stringable` to VOs. Final
  the controller and the model. Replace `mt_rand` samplers with
  `random_int`-based shared `LogSampler`. Convert `ErrorCodes` to enum
  (also helps T2 / T17). Standardise on `hrtime(true)`.

### T17 — **NEW** PHP 8.4 opportunities deferred but flagged
- **Symptom across slices:** `17/G1, 17/G2, 17/G3, 17/G4, 17/G5, 17/G6,
  17/G7, 17/G8, 17/G9, 17/G10, 17/G11, 17/G12`
- **Root cause:** PHP 8.4 ships features that directly resolve open
  findings: asymmetric visibility (`public private(set) ?int $id`)
  engine-enforces the `@internal assignId/bumpVersion` contract from
  slice 01 F5. Property hooks centralise the `assertNotDeleted()` guard.
  `Random\Randomizer` replaces `mt_rand`. `array_find` / `array_any`
  simplify the `isDuplicateKey` substring chain. `new ClassName()->method()`
  deref. `#[\Deprecated]` replaces docblock `@deprecated`. Lazy objects
  for `CookieDTO` hydration. `#[\SensitiveParameter]` on
  `AuditMiddleware::digestOf`. `mb_trim()` for Unicode whitespace. The
  template currently has none of these because `composer.json` still pins
  `^8.3`.
- **Why it propagates per clone:** Until `composer.json` bumps, the template
  keeps shipping 8.3 idioms even though 8.4 offers cleaner answers for
  problems the audit already raised.
- **Severity rollup:** 2 HIGH + 5 MEDIUM + 4 LOW + 1 INFO = 12 findings.
- **Resolution sketch:** Phase 4 epic: bump `composer.json` to `^8.4`,
  set `phpstan.neon` to `phpVersion: 80400`, then adopt asymmetric
  visibility for `$id` / `$version` (engine-enforces slice 01 F5),
  `Randomizer` for sampling, `#[\Deprecated]`, `array_any` for
  `isDuplicateKey`. Other items (property hooks, lazy objects, lazy
  reflection) deferred to performance-pass.

### T18 — **NEW** MySQL connection envelope unpinned
- **Symptom across slices:** `18/F-C1, 18/F-C2, 18/F-C3, 18/F1, 18/F3,
  18/F4, 18/F-T2`
- **Root cause:** `Config/Database.php` has no `sessionVariables`; no
  `sql_mode`; no `transaction_isolation`. `strictOn=true` only appends
  `STRICT_ALL_TABLES` to whatever the server set as default. `DBCollat` is
  `utf8mb4_general_ci` while `cookies.name` column is pinned to
  `utf8mb4_unicode_ci` — collation-mix on JOIN risk. `numberNative=false`
  so `INT UNSIGNED` columns come back as PHP strings (footgun for the
  `version` optimistic-lock column). `is_active TINYINT(1)` has no CHECK
  constraint. `tenant_id` is nullable while UNIQUE depends on it being
  non-NULL. `DBPrefix='db_'` set in Config but `.env` says empty.
- **Why it propagates per clone:** Every cloned project running on a
  MySQL server with weaker defaults (MariaDB, MySQL 5.7, managed DB) gets
  silently non-strict SQL, MyISAM-shaped tables, mixed-collation joins,
  and `'unsupported_schema'` truncation (T19).
- **Severity rollup:** 4 HIGH + 3 MEDIUM + 1 LOW = 8 findings.
- **Resolution sketch:** Add `sessionVariables` block to `Config/Database.php`
  pinning `sql_mode` (STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,
  ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION),
  `transaction_isolation = READ-COMMITTED`, `time_zone = +00:00`,
  charset/collation belt-and-braces. Align `DBCollat` to
  `utf8mb4_unicode_ci`. Set `numberNative=true`. Every `createTable` gets
  attributes (ENGINE=InnoDB, CHARSET=utf8mb4, COLLATE=utf8mb4_unicode_ci,
  ROW_FORMAT=DYNAMIC). Either drop the `DBPrefix` or align with `.env`.

### T19 — **NEW** Outbox table data-correctness defects
- **Symptom across slices:** `18/F-I1, 18/F-I2, 18/F-I3, 18/F-I4, 18/F-I5,
  18/F-O5, 18/F-O7, 18/F-O8, 18/F5, 18/F-A2`
- **Root cause:** Multiple structural flaws on `event_outbox`:
  - `status VARCHAR(16)` cannot hold `'unsupported_schema'` (18 chars);
    silently truncates without strict sql_mode (T18 connection).
  - No `event_uuid` column / no UNIQUE constraint → retry-after-rollback
    duplicates that no DB-level dedup can catch.
  - No claim / lease columns (`reserved_at`, `reserved_by`) → multi-worker
    relay race; `SELECT … FOR UPDATE SKIP LOCKED` not used despite MySQL 8.
  - Indexing: `KEY(status, available_at)` misses `id` in the index, forcing
    filesort under load.
  - No `tenant_id` column → no per-tenant draining.
  - Payload is `LONGTEXT`, not JSON → MySQL JSON validation forfeited.
  - Status is a free-form VARCHAR — no CHECK constraint listing valid values.
  - No retention / partitioning strategy.
  - `audit_log` similarly lacks `entity_type`/`entity_id`.
- **Why it propagates per clone:** Every domain emits events through this
  outbox. Truncation, double-delivery, and race conditions are
  template-wide bugs.
- **Severity rollup:** 1 CRITICAL (F-O8) + 1 CRITICAL (F-I2) + 4 HIGH + 4
  MEDIUM = 10 findings.
- **Resolution sketch:** Migration to widen `status` to VARCHAR(32) with
  CHECK; add `event_uuid CHAR(36) NOT NULL UNIQUE`; add
  `reserved_at`/`reserved_by`/`tenant_id` columns; convert payload to JSON
  type; add covering index `(status, available_at, id)` and tenant index;
  document SKIP LOCKED contract; introduce retention/partitioning doc.

---

## Top 15 fix-now items

These are highest-leverage items in **execution order** (foundation first,
propagation second). Each item lists canonical slice/F# references.

1. **Drop `force="true"` on `database.tests.DBDriver`; add MySQL CI lane**
   — closes `12/F1, 13/F1, 18/F-T1`. *Why now:* Every claim about MySQL
   schema, locking, charset, sql_mode, FK, JSON, SKIP LOCKED is
   unverifiable until this lands. Time-critical Phase 0 unblocker.

2. **Pin `phpVersion: 80300` in `phpstan.neon` + `php_version=80300` in
   `phpcs.xml`** — closes `17/F1 (related), 17/G1 (gate)`. *Why now:* Without
   this pin, a PHP 8.4 host can land 8.4-only syntax in code targeting
   `^8.3` invisibly. Single-line fix; highest-leverage 8.3 item.

3. **Fix `docblocks:audit` to catch placeholder stubs + wire `composer ci`
   into CI** — closes `16/F1, 16/F8`. *Why now:* The documentation gate
   the README promises is a no-op. Until CI runs the gate, every other
   docblock fix in this plan rots.

4. **Pin `sql_mode`, isolation, charset, ENGINE, ROW_FORMAT via
   `sessionVariables` and per-migration attributes** — closes
   `18/F1, 18/F-C1, 18/F-C2, 18/F-C3, 11/F5`. *Why now:* Cookie's MySQL
   claims (strict optimistic locking, NULL-uniqueness behaviour,
   case-insensitive collation) only hold under a pinned envelope. Without
   it, every other MySQL fix below has unpinned semantics.

5. **Fix `event_outbox.status` truncation, add `event_uuid` UNIQUE, add
   lease columns** — closes `18/F-I2, 18/F-O8, 18/F-I1, 18/F-I3`. *Why
   now:* `'unsupported_schema'` silently truncates today; the relay can
   double-deliver and double-lease today. Two CRITICALs at the outbox
   layer that the template propagates to every domain.

6. **Regenerate `domain-scaffolding/SKILL.md` + `COMPLETE_FILE_INVENTORY.md`
   from current Cookie** — closes `08/F4, 15/F1, 15/F6, 15/F11, 15/F13,
   16/F2, 16/F3, 16/F7`. *Why now:* every other fix is silently undone by
   `/add-domain` if the docs are stale; source of truth must be true.
   (Lands AFTER structural epics finish so it documents the
   post-remediation Cookie.)

7. **Introduce `AbstractDomainEvent` (eventId UUIDv7 + occurredAt +
   actorId) and unify dispatch** — closes `01/F12, 03/F1, 05/F1, 05/F2,
   05/F3, 05/F5, 05/F6, 05/F9, 14/F18`. *Why now:* Five different event
   envelopes per domain × N future domains = guaranteed inconsistency,
   plus the outbox has no idempotency anchor until events have UUIDs.

8. **Introduce `AbstractCommandHandler` + `AbstractQueryHandler` +
   bus-enforced `*HandlerInterface`** — closes `03/F3, 03/F4, 03/F5, 03/F6,
   03/F11, 04/F1, 04/F3, 04/F7, 04/F12, 14/F1, 14/F2, 14/F3, 14/F20,
   17/F2`. *Why now:* Removes 70+ lines of per-handler boilerplate,
   eliminates `str_contains` error-code resolver, brings every `handle()`
   under the 20-line cap.

9. **Add lifecycle mutators + events to Cookie entity (`softDelete`,
   `restore`, `activate`, `deactivate`)** — closes `01/F1, 01/F2, 01/F3,
   03/F1 (entity side)`. *Why now:* Without these, the unified event
   dispatch above has nothing to dispatch on those transitions.

10. **Resolve multi-currency story: schema → `price_minor + price_currency`,
    factories require `Currency`, bounds recompute per-currency** — closes
    `02/F1, 02/F2, 02/F5, 05/F9, 11/F1, 14/F8, 15/F4, 18/F2`. *Why now:*
    Every cloned monetary VO inherits the contradiction; the schema change
    is destructive and must land before more domains build on it.

11. **Consolidate read-side DTOs into one `CookieDTO`-style class with
    `JsonSerializable`, `fromRow()`, `ReadDTOInterface`; resolve
    `PriceFormatter` vs `CookiePrice::format` contradiction** — closes
    `04/F9, 07/F1, 07/F2, 07/F3, 07/F4, 07/F5, 07/F6, 07/F7, 07/F8, 07/F9,
    07/F10, 07/F11, 07/F12, 10/F1, 14/F9, 14/F10, 14/F11, 15/F10`. *Why
    now:* The template ships two contradictory read-DTOs; the views call
    methods the documented future doesn't have.

12. **Provider DI overhaul: constructor injection, `URI_SEGMENT`/
    `CONTROLLER_NAMESPACE` constants, derive log channel via convention,
    add `registerProjections()` to interface** — closes `08/F1, 08/F2,
    08/F3, 08/F4, 08/F5, 08/F8, 08/F9, 08/F11, 08/F14, 08/F15, 14/F5,
    14/F22`. *Why now:* Eliminates the `instanceof` dance, silent
    undefined-index runtime fault, and stale registration interface.

13. **Repository hygiene: trusted reconstitution, LIKE escape, drop LOWER,
    single-statement delete, restore-with-version-bump, drop `withDeleted`
    from existsByName** — closes `06/F1, 06/F4, 06/F6, 06/F7, 06/F8, 06/F9,
    06/F10, 06/F13, 11/F3, 04/F4, 14/F6`. *Why now:* The 586-line
    `CookieRepository` is the worst single class in the template; its bugs
    are user-visible.

14. **Controller + filters: attach `web_auth` on route group, refactor to
    constructor injection + `final`, generic `Throwable` catch, view i18n
    + `can()` gating** — closes `09/F1, 09/F2, 09/F3, 09/F4, 09/F5, 09/F6,
    09/F7, 10/F2, 10/F3, 10/F4, 10/F5, 10/F8, 13/F5, 13/F15, 15/F8`. *Why
    now:* The HTTP layer is open-by-default and view drift makes the
    read-DTO consolidation visible.

15. **Add `#[\Override]` everywhere; `implements \Stringable` on VOs;
    final the controller/model; replace `mt_rand` with shared
    `LogSampler`; convert `ErrorCodes` to enum** — closes `14/F4, 17/F2,
    17/F3, 17/F4, 17/F5`. *Why now:* These are tiny diffs that lift the
    template from "good PHP 8.3" to "exemplary PHP 8.3" and pre-empt
    drift before the 8.4 bump.

---

## All findings, deduped and severity-sorted

Below: every finding from every slice, canonical-id (`slice/F#`),
one-line title, list of duplicate slice/F# raising the same defect.

### CRITICAL (~17 canonical)

- **`02/F1`** `CookiePrice` USD-cents bounds applied to every currency
  *(also `15/F4`, theme T3)*
- **`03/F1`** Three competing event-dispatch patterns
  (Create/Update/Delete/Restore handlers) *(also `01/F12`, theme T1)*
- **`03/F2`** `RestoreCookieHandler` violates every convention the other
  three honour *(also `16/F5`, theme T2)*
- **`07/F1`** Two competing read-DTOs (`CookieDTO` + `CookieView`); one
  is dead code *(also `15/F10, 14/F10`, theme T4)*
- **`07/F2`** `PriceFormatter` bypassed by every production caller
  *(also `14/F9`, theme T3)*
- **`08/F1`** `CookieServiceProvider::registerRoutes()` namespace string
  sed-hostile *(theme T6)*
- **`08/F2`** `getRepository()` silently undefined-index-faults on typos
  *(theme T6)*
- **`08/F3`** `registerEvents()` constructs its own logger via static
  factory, bypassing DI *(also `14/F22`, theme T6)*
- **`09/F1`** Cookie route group has no `web_auth` filter; relies on URI
  deny-list in `Filters.php` *(theme T10)*
- **`13/F1`** `phpunit.xml.dist:67` force-locks test DB to SQLite
  *(also `18/F-T1`, theme T11)*
- **`13/F2`** `CookieRepositoryTest` mixes real-DB with `createMock` cases
  inside an integration class *(theme T11)*
- **`15/F1`** Scaffolding skill / inventory / `ADDING_DOMAINS.md` describe
  a Cookie that hasn't existed for 2 refactors *(also `16/F2, 16/F3, 16/F7`,
  theme T5)*
- **`15/F2`** Reference projection is `.php.example` with self-
  contradicting Create+Drop migration pair *(also `11/F4`, theme T12)*
- **`18/F-I2`** `event_outbox` has no `event_uuid` / no UNIQUE — retry
  duplicate cannot be detected *(theme T19)*
- **`18/F-O8`** `event_outbox.status VARCHAR(16)` truncates
  `'unsupported_schema'` *(theme T19)*
- **`18/F-T1`** Tests run on in-memory SQLite, blinding the suite to every
  MySQL-specific behaviour *(also `13/F1`, theme T11)*

### HIGH (~74 canonical)

- **`01/F1`** `Cookie::update()` silently drops its event when called
  pre-persist *(theme T7)*
- **`01/F2`** `Cookie::activate()` / `deactivate()` raise no event
  *(theme T7)*
- **`01/F3`** Soft-delete / restore are not entity methods *(theme T7)*
- **`01/F4`** `reconstitute()` default `int $version = 0` is wrong
  direction *(theme T7)*
- **`02/F2`** `defaultCurrency()` implicit env-read silently falls back to
  USD *(also `15/F4`, theme T3)*
- **`02/F3`** CookieName equality split between `equals()` and
  `equalsIgnoreCase()` *(theme T3)*
- **`02/F4`** `CookieStock::fromInt()` no maximum; `incrementBy()` can
  overflow `PHP_INT_MAX`
- **`03/F3`** Handler `handle()` methods 70-94 lines *(also `14/F1`,
  theme T2)*
- **`03/F4`** `CreateCookieHandler::determineErrorCode()` `str_contains`
  on exception messages *(also `14/F2`, theme T2)*
- **`03/F5`** `CommandBus` duck-typed; `CommandHandlerInterface` unused
  *(theme T2)*
- **`03/F6`** `UpdateCookieHandler` failure-log shape diverges *(also
  `14/F12`, theme T2)*
- **`03/F7`** Command shape drift: `$cookieId` vs `$id`, varied actor
  positioning
- **`03/F8`** `TransactionMiddleware` silent on read-then-write critical
  sections (existsByName TOCTOU)
- **`04/F1`** Query-handler logging boilerplate duplicated 3× *(also
  `14/F3`, theme T2)*
- **`04/F2`** `GetAllCookiesQuery` unbounded — OOM/DoS surface
- **`04/F3`** No `QueryHandlerInterface<TQuery, TResult>` contract
  *(theme T2)*
- **`05/F1`** Event payloads asymmetric across 5 events (no `eventId`/
  `occurredAt`/`actor`) *(theme T1)*
- **`05/F2`** `CookieStockChangedEvent::$cookieId` is `?int` (nullable)
  *(also `01/F7`, theme T1)*
- **`06/F1`** `existsByName` `withDeleted` contradicts schema's
  reuse-after-delete contract *(also `11/F3`, theme T8)*
- **`06/F2`** Hard-coded `'cookie'` metric slice key survives sed
  *(theme T6)*
- **`06/F3`** `RepositoryLogging` / `BusinessMetricsLogging` hard-code
  `'Cookie'` literals *(also `14/F12, 15/F3`, theme T6)*
- **`06/F4`** Read repository `LIKE` unescaped — `%`/`_` injection
  *(theme T9)*
- **`07/F3`** `PriceFormatter` not stateless-by-design and not
  locale-aware
- **`07/F4`** `CookieDTO::isOutOfStock()` violates DTO docblock (DTO
  carries behaviour)
- **`07/F5`** `CookieDTO::id` nullable — ambiguous serialization
- **`07/F6`** `CookieDTO` / `CookieView` represent price differently; no
  JSON contract
- **`07/F7`** `CookieView` factories take `Cookie` entity — couples
  read-model to write-side
- **`08/F4`** `DomainServiceProviderInterface` has no
  `registerProjections()` hook *(theme T5)*
- **`08/F5`** `setRepositories()`/`getRepositories()` imperative coupling
  defeats `#[AutoBind]` *(also `14/F5`, theme T6)*
- **`08/F6`** Provider discovery scans `app/Domain` + `app/Infrastructure`
  on every cold start (no manifest cache)
- **`08/F7`** `Config\Cookie` framework class collides with Cookie domain
- **`08/F8`** `Services::ensureProvidersRegistered()` re-entrance landmine
- **`09/F2`** Controller catches only `ValidationException`/
  `DomainException` — `Throwable` leaks *(theme T10)*
- **`09/F3`** Controller service-locator per action; no constructor
  injection *(theme T10)*
- **`09/F4`** `(bool) $isActiveParam` permissive — `"false"` / `"off"`
  evaluate true
- **`10/F1`** Views call `formattedPrice` / `isOutOfStock()` accessors not
  on `CookieView` *(theme T4)*
- **`10/F2`** Hard-coded English strings throughout — `lang()` not used
  *(also `15/F8`, theme T13)*
- **`10/F3`** Action buttons render without `can()` gating *(theme T13)*
- **`10/F4`** Pagination duplicated inline, ignoring
  `partials/_pagination.php` *(theme T13)*
- **`11/F1`** Money/schema mismatch: `DECIMAL(10,2)` vs `Money`
  minor-units *(also `18/F2`, theme T3)*
- **`11/F2`** Seeder bypasses VOs, omits ERP-baseline columns
  *(also `16/F11`, theme T12)*
- **`12/F1`** 19 unit tests open real `LoggerFactory` →
  `writable/logs/app.json` *(theme T11)*
- **`12/F2`** Missing tests for `CookieStock`, `PriceFormatter`,
  `CookieAccessors`, `ErrorCodes` *(theme T14)*
- **`12/F3`** 11 event-handler tests assert only `assertTrue(true)`
  *(theme T11)*
- **`13/F3`** Composite UNIQUE `(tenant_id, name, deleted_at)` never
  exercised; NULL-vs-NULL gap unseen *(theme T11)*
- **`13/F4`** Feature tests `assertSee('cookies/index')` — content-blind
  *(theme T11)*
- **`13/F5`** `loginAsAdmin` bypasses real auth flow; bogus argon2id
  hash; no `loginAsCustomer()` *(also `09/F7`, theme T11)*
- **`13/F6`** `sleep(1)` in
  `test_find_paginated_orders_by_created_at_desc` *(theme T11)*
- **`13/F7`** Pagination edge cases not covered *(theme T11)*
- **`14/F4`** `ErrorCodes` is class of `const int` not `enum: int` (loss
  of type safety) *(theme T16)*
- **`14/F6`** `CookieRepository` is 586 LoC; mixes 7 concerns *(also
  `15/F9`, theme T9)*
- **`15/F3`** "Shared" infrastructure traits/services scoped under Cookie
  namespace *(also `06/F2, 06/F3`, theme T6)*
- **`15/F5`** `ErrorCodes` collision contract documented but not
  enforced *(theme T6)*
- **`15/F6`** File inventory + SKILL.md disagree about repository
  location *(theme T5)*
- **`16/F1`** 26 placeholder `* MethodName.` docblocks across Cookie
  surface *(theme T15)*
- **`16/F2`** `COMPLETE_FILE_INVENTORY.md` 2 phases stale: wrong repo
  path, missing 4th command, missing query side *(also `15/F1, 15/F6`,
  theme T5/T15)*
- **`16/F3`** `domain-scaffolding/SKILL.md` points at non-existent paths
  and pre-auto-discovery workflow *(also `15/F1`, theme T5/T15)*
- **`16/F8`** `composer docblocks:audit` is in `composer check` but NOT
  in CI *(theme T15)*
- **`17/F2`** `mt_rand()` for log sampling (×3 sites; biased sampler)
  *(also `04/F12`, theme T16)*
- **`18/F1`** No `ENGINE` / no `ROW_FORMAT` / no table charset on any
  migration *(theme T18)*
- **`18/F2`** `DECIMAL(10,2)` for price loses currency dimension and caps
  at ~99M *(also `11/F1`, theme T3)*
- **`18/F-I1`** No leasing index on `event_outbox`; ORDER BY id requires
  filesort *(theme T19)*
- **`18/F-I3`** No claim semantics: relay uses plain UPDATE without
  `FOR UPDATE SKIP LOCKED` *(theme T19)*
- **`18/F-S1`** Composite UNIQUE(tenant_id, name, deleted_at) does NOT
  prevent two active rows when both have NULL tenant *(also `11/F11,
  13/F3`, theme T12)*
- **`18/F-FK1`** `cookies` has no FK on `created_by`/`updated_by`/
  `deleted_by`/`tenant_id` *(also `11/F6`, theme T12)*
- **`18/F-G1`** No hard-delete / `purge()` path for `cookies` (GDPR)
  *(also `06/F14`)*
- **`18/F-C1`** No `sessionVariables` in `Config/Database.php`
  *(theme T18)*
- **`18/F-C2`** `strictOn=true` is not the same as pinned strict `sql_mode`
  *(theme T18)*
- **`18/F-C3`** No isolation level pinned *(theme T18)*
- **`18/F-T2`** `database.tests.DBPrefix='db_'` in Config but not in
  `.env` *(theme T11)*
- **`17/G1`** Asymmetric visibility on `Cookie::$id` / `$version` (PHP
  8.4 — fixes slice 01 F5) *(theme T17)*
- **`17/G2`** `Random\Randomizer` replaces `mt_rand` sampler (PHP 8.4
  cleanup of F2) *(theme T17)*

### MEDIUM (~76 canonical, abbreviated)

`01/F5` `@internal public` not enforced (8.4 G1 supersedes) • `01/F6`
`assertPersisted` wrong error code *(also `14/F7, 15/F14`)* • `01/F7`
stock-event `(int)` cast *(also `05/F2`)* • `01/F8` `CookieAccessors`
`@property` brittle dependency *(also `14/F23, 16/F9`)* • `01/F9`
`decreaseStock` stringly-typed reason *(also `17/G9`)* • `02/F5` asymmetric
error codes across `CookiePrice` exceptions • `02/F6` no `JsonSerializable`
on Cookie VOs • `02/F7` `equalsIgnoreCase(string)` bypasses VO validation
• `03/F9` `expectedVersion` opt-in concurrency control • `03/F10`
`RestoreCookieHandler` `COOKIE_NOT_FOUND` for not-deleted • `03/F11`
`DeleteCookieHandler` uses `hrtime`, others use `microtime` *(also `14/F21,
17/P3`)* • `03/F13` `DeleteCookieHandler` builds manual snapshot, others
don't • `04/F4` search term not length-capped or LIKE-escaped • `04/F5` no
sort input on paginated query • `04/F6` page has floor but no ceiling •
`04/F7` slow queries logged at `info` not `warning` *(theme T2)* • `04/F8`
search analytics override bypasses log-level config • `04/F9`
`GetCookieById` null-on-miss has no controller-side contract • `05/F3`
`CookieRestoredEvent::$restoredAt` is raw `string` *(also `14/F18,
17/G5`)* • `05/F4` unbounded snapshot array invites PII leakage • `05/F5`
no event has `eventId`; no idempotency guard *(theme T1/T19)* • `05/F6`
placeholder `__construct.` docblocks on Restored/StockChanged *(also
`16/F1`)* • `06/F5` write paginate vs read paginate disagree on default
ORDER BY • `06/F7` reconstitute re-validates VOs *(theme T9)* • `06/F8`
`delete()` SELECT-then-UPDATE-twice *(theme T9)* • `06/F9` `restore()`
no `version` bump *(theme T9)* • `06/F10` `CookieQueryRepository::
findPaginated` swallows `false` get() *(theme T9)* • `07/F8` `CookieView`
can't represent soft-deleted state from read path • `07/F9` `CookieView::
$extra` dead state *(also `14/F10`)* • `07/F10` `DTOs/` vs `ReadModels/`
naming inconsistency • `07/F11` asymmetric factories • `08/F9`
`lcfirst(shortName)` repository key sed-fragile • `08/F10` `Autoload.php`
dead `'App\\Domains'` mapping • `08/F11` `registerCommands` 75-line method
• `08/F12` eager handler construction in provider • `08/F13`
`setRepositories()` overwrites instead of merging • `08/F14`
`getRepository()` returns `object` (defeats PHPStan L8) • `09/F5`
`CookieController` not `final` *(also `17/F5`)* • `09/F6` `index`
hard-codes `perPage: 20` • `09/F7` `ActorResolver` returns
`Actor::system()` for anonymous *(also `13/F5`)* • `09/F8`
`redirect()->back()` no fallback target • `09/F9` `POST /cookies/{id}/
delete` convention not documented • `10/F5` `show.php:36` renders HTML in
ternary alongside escaped content • `10/F6` Bootstrap Icons referenced
but never loaded • `10/F7` date formatting inconsistent across views •
`10/F8` empty-state markup duplicated • `11/F4` Create-then-Drop
read-model migration pair *(also `15/F2, 18/F-M2`)* • `11/F5` no explicit
`ENGINE`/`CHARSET`/`COLLATE` on `createTable` *(also `18/F1`)* • `11/F6`
no FKs on `tenant_id`/`created_by`/`updated_by`/`deleted_by` *(also
`18/F-FK1`)* • `11/F7` filename convention inconsistent *(also `18/F-M1`)*
• `12/F4` `expectException(\Exception::class)` loses type specificity •
`12/F5` `CookieEventsTest` immutability tests are tautologies • `12/F6`
`CookieDeletedEvent` carries no `deletedBy`/`deletedAt` payload *(also
`05/F1`)* • `13/F8` `IntegrationTestCase` eagerly constructs
`CookieRepository` • `13/F9` tenant test directly inserts via raw
`Database::connect` • `13/F10` `assertFlashMessage('error')` no specific
message • `13/F11` 50-line monolithic "journey" test • `14/F7` entity
carries deprecated/legacy concerns • `14/F8` `CookiePrice`/`CookieName`
ship `@deprecated` methods on a template • `14/F12` logging context keys
mix snake/camel case *(also `06/F3`)* • `14/F13` `getStock()` returns int
unwrapping the VO • `15/F7` plural/pluralisation literals scattered •
`15/F9` `Cookie.php` 288 lines / `CookieRepository.php` 586 lines • `15/F11`
Restore command missing from scaffolding promise *(also `16/F2, 16/F3`)*
• `16/F4` `CookieRepository.php` wrong `@package` tag *(theme T15)* •
`16/F5` `RestoreCookieHandler` class-level docblock is a one-word stub
*(also `03/F2`)* • `16/F6` `CookieRepositoryInterface::findById`
docblock says nothing about soft-delete contract • `16/F7`
`SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md` dated 2025-10-26 analyses a
23-file Cookie that no longer exists *(theme T5)* • `17/F1` `CookieName`
class constants are not typed *(theme T16)* • `17/F3` No `#[\Override]`
attribute anywhere *(theme T16)* • `17/F4` VOs with `__toString()` don't
declare `implements \Stringable` *(theme T16)* • `17/F5` `CookieController`
and `CookieModel` are not `final` *(theme T16, also `09/F5`)* • `17/G3`
Property hooks for `assertNotDeleted()` cross-cutting check (PHP 8.4)
*(theme T17)* • `17/G4` `array_find`/`array_any` adoption in
`isDuplicateKey` (PHP 8.4) *(theme T17)* • `17/G5` `new
ClassName()->method()` deref-on-new (PHP 8.4) *(theme T17)* • `17/G6`
Replace `@deprecated` docblock with `#[\Deprecated]` (PHP 8.4)
*(theme T17)* • `18/F3` `is_active TINYINT(1)` no CHECK constraint
*(theme T18)* • `18/F4` `cookies.tenant_id` is nullable *(also `11/F11`,
theme T18)* • `18/F5` `event_outbox.payload` is `LONGTEXT`, not `JSON`
*(theme T19)* • `18/F-I4` `event_outbox` has no `tenant_id` *(theme T19)*
• `18/F-I5` `audit_log` / `event_outbox` lack retention/partitioning
*(theme T19)* • `18/F-I6` Soft-delete predicate index missing • `18/F-S2`
`deleted_by` paired-but-not-enforced with `deleted_at` • `18/F-S3` No
`restored_at` / `restored_by` columns • `18/F-FK2` `event_outbox`,
`audit_log` have no FK on aggregate_id (string type) • `18/F-A1`
`audit_log.actor_id` no FK • `18/F-A2` `audit_log` has no `entity_type`/
`entity_id` *(theme T19)* • `18/F-G2` PII columns not annotated •
`18/F-G3` No retention/auto-purge on `event_outbox`, `audit_log`,
`idempotency_keys` *(theme T19)* • `18/F-M1` Migration filename
timestamps inconsistent *(also `11/F7`)* • `18/F-FK3` `notifications.user_id`
CASCADE may violate GDPR

### LOW (~51 canonical, abbreviated)

`01/F10` missing `implements AggregateRootInterface` • `01/F11` `snapshot()`
heterogeneous types • `02/F8` `CookieStock::$value` public; siblings
private+getter • `02/F9` `CookiePrice::format()` deprecation note couples
VO to Service *(also `07/F2`)* • `03/F14` per-handler log channel
mentioned in docblock but unused • `03/F15` `RestoreCookieEvent::restoredAt`
raw string *(theme T1)* • `03/F16` `CommandBus::dispatch` dead
`method_exists` check • `04/F10` hard-coded `'Cookie'` /
`'GetCookieByIdQuery'` strings *(theme T6)* • `04/F11` no caching seam in
query path • `04/F12` `mt_rand()` sampling vs `random_int` *(also `17/F2`)*
• `05/F7` `EventDispatcher` not `final` (PHPUnit subclassing) • `05/F8`
`dispatch()` short-circuits silently with no listeners • `05/F9` event
price format ambiguous *(theme T3)* • `06/F11` `CookieModel` not `final`
*(also `17/F5`)* • `06/F12` write-side `findById` triggers
`trackPopularCookie` • `06/F13` `save()` undocumented side effect • `06/F14`
no hard-delete escape hatch *(also `18/F-G1`)* • `06/F15` `BaseConnection`
template parameter over-specified • `08/F15` `'cookie.events'` channel
name doesn't match `deriveLogChannel()` *(theme T6)* • `08/F16`
`Config\Events.php` naming overload • `08/F17` `Routes.php` auto-mount
loop no error handling • `08/F18` `getRepositories(): array<mixed>`
should be `list<string>` • `09/F10` namespace stem `Domain\Cookie` vs URI
stem `cookies` • `09/F11` `show()`/`edit()` swallow not-found via redirect
• `09/F12` `$price = is_string ? : ''` hides type confusion • `09/F13` no
`permission:` filter wired • `09/F14` `delete()` doesn't catch
`ValidationException` • `10/F9` no `$title` passed to layout • `10/F10`
`<?= $cookie->id ?>` no `esc()` • `10/F11` delete `data-confirm` JS
degrades without JS • `10/F12` search form omits CSRF (acceptable GET) •
`10/F13` Cancel `<a>` next to submit `<button>` • `11/F8` `cookies` table
name hard-coded *(theme T6)* • `11/F9` `created_at`/`updated_at`/
`deleted_at` all nullable *(theme T12)* • `11/F10` `is_active TINYINT(1)`
ambiguous semantics *(also `18/F3`)* • `11/F11` composite UNIQUE NULL-vs-
NULL allows duplicates *(also `18/F-S1, 13/F3`)* • `12/F7` `CookieFactory::
createDatabaseRow` dead code in unit tests *(theme T14)* • `12/F8`
`determine_error_code_match_arms` test asserts message, not code •
`12/F9` `UnitTestCase::assertExceptionMessage` catches `\Exception` not
`\Throwable` • `12/F10` whitespace/indentation drift in `version: 1`
argument • `13/F12` `test_supports_explicit_page_parameter` asserts only
`assertOK()` • `13/F13` `test_save_updates_only_changed_fields` misnamed
*(theme T14)* • `13/F14` `CookieOptimisticLockingTest` bare `catch
(DomainException)` • `13/F15` CSRF silently disabled in test env
*(theme T10/T11)* • `14/F14` `PriceFormatter` `final class` not `final
readonly class` • `14/F15` placeholder docblocks (`__construct.`)
auto-generated stubs *(also `16/F1`)* • `14/F16` `TenantContext` FQN inline
• `14/F17` `(int) $this->id` cast after `assertPersisted` • `14/F18`
`CookieRestoredEvent::restoredAt` raw string *(also `05/F3`)* • `14/F19`
two-layer clamping in paginated query • `14/F20` `mt_rand()` for sampling
duplicated 3 times *(also `04/F12, 17/F2`)* • `15/F12` Singular/plural
inconsistency across namespace/entity/table/route/view/channel *(theme T6/
T13)* • `15/F14` `ErrorCodes` constants used inconsistently inside entity
*(theme T7)* • `16/F9` `CookieAccessors` trait `@property` block can be
misread *(also `01/F8, 14/F23`)* • `16/F10` Cookie ports/DTOs lean on
Cookie-domain-specific semantics in prose *(theme T15)* • `16/F11`
`CookieSeeder` docblock omits the columns now required by migration *(also
`11/F2`)* • `16/F12` `CookieReadModelProjection.php.example` docblock
references migrations that no longer line up *(also `11/F4, 15/F2`)* •
`17/F6` `@deprecated` docblock not engine-enforced *(also `17/G6`)* •
`17/F7` `(int) $command->id` / `(bool) $isActiveParam` casts in controller
*(also `09/F4, 09/F12`)* • `17/F8` `CookieView::summarise()` uses static
fn closure where first-class callable would do *(theme T16/T17)* • `17/F9`
`EventOutboxRelay` raw `json_decode` (PHP 8.4 `json_validate` opportunity)
• `17/F10` `EventDispatcher::describeListener()` reinvents `Closure::
fromCallable` *(theme T16)* • `17/G7` Lazy objects for `CookieQueryRepository`
hydration (PHP 8.4) *(theme T17)* • `17/G8` `#[\SensitiveParameter]` on
`AuditMiddleware::digestOf` (PHP 8.4) *(theme T17)* • `17/G9` Native enums
for `StockChangeReason` / query-logging level *(also `01/F9, 04/F5`,
theme T17)* • `17/G10` `mb_trim()` for `CookieName` (PHP 8.4)
*(theme T17)* • `17/P1` `array_map` with bound `$this` closures
*(theme T16/T17)* • `17/P2` Hot-path `LOWER(name)` interacts with PHP-side
`strtolower` *(also `06/F6`)* • `17/P3` `microtime(true)` everywhere; one
handler uses `hrtime` *(also `03/F11, 14/F21`)* • `17/P5`
`EventOutboxRelay::rehydrate()` uses reflection per row • `18/F6`
`cookies.description` is `TEXT NULL` with no length cap • `18/F7`
`users.role` / `users.status` are `ENUM` columns • `18/F-FK3` FK action
on `notifications.user_id` is CASCADE (GDPR) • `18/F-O7` outbox status
free-form VARCHAR — no CHECK constraint *(theme T19)* • `18/F-M2`
CreateCookieReadModel + DropCookieReadModel within 1 day *(also `11/F4,
15/F2`)* • `18/F-A3` `audit_log.duration_ms` caps at ~99M ms • `18/F-G4`
No encryption-at-rest documentation • `18/F-I7` No FULLTEXT index on
`cookies.name`

### INFO (~24 canonical)

`01/F12` class docblock encodes split-dispatch workaround as canon
*(theme T1)* • `02/F10` `CookieStock::fromInt` naming • `03/F16`
`CommandBus::dispatch` dead `method_exists` check • `04/F13`
`GetAllCookiesQuery` misleading docblock • `05/F10`
`CookieReadModelProjection.php.example` deprecation header is exemplary
(kept) • `06/F16` `CookieModel::$validationRules` duplicates VO invariants
• `06/F17` write port `existsByName(string)` leaks scalar • `08/F19`
`RegisterRoutesNoop` trait referenced but unused • `10/F14` two layouts
(`layout.php` + `layouts/shell.php`) for same render • `10/F15`
`_flash.php` keys hard-coded English *(theme T13)* • `11/F12` `version`
default `0` vs `1` *(theme T12)* • `11/F13` no covering index for
`LOWER(name)` *(theme T8)* • `12/F11` test naming consistent (`test_…`
snake_case) — keep it • `12/F12` `CookieFactory::createPersistedCookie`
silently drops `version` override *(theme T14)* • `13/F16` factory
defaults won't sed-clone (`'Chocolate Chip'` survives into Invoice)
*(theme T14)* • `13/F17` class-level `#[AllowMockObjectsWithoutExpectations]`
weakens risky detection *(theme T11)* • `14/F21` mixed time bases
(`microtime` vs `hrtime`) *(also `03/F11, 17/P3`)* • `14/F22`
`LoggerFactory::create('cookie.events')` static call (also `08/F3`) •
`14/F23` `CookieAccessors` trait `@property` duplication *(also `01/F8`)*
• `15/F15` `findByIdWithTrashed` on write port, no read-port equivalent •
`16/F13` `CookieController` controller docblock predates actor-resolver
wiring *(theme T15)* • `16/F14` `@throws` annotations inconsistent across
handlers *(theme T15)* • `16/F15` `LOGGING_BEST_PRACTICES.md` and
`GIT_WORKFLOW.md` existence (cross-check note) • `17/G11` `Stringable` as
typed parameter constraint (PHP 8.4) *(theme T17)* • `17/G12`
Implicit-nullable-type deprecation — Cookie clean (confirmed non-finding)
• `18/F8` `audit_log.payload_digest` is CHAR(64) but no `payload` snapshot
• `18/P4` No `eval`/`extract` — verified clean

> **Total raw findings: 242. Canonical findings (after dedup): ~140. Every
> raw finding appears either as canonical or as a duplicate of another
> canonical.** Total Finding-to-Epic Matrix row count in the remediation
> plan: **242** rows (one per raw finding, including duplicates pointed
> at the same epic so reviewers can verify allocation).

---

## Patterns the template gets right

Future domains should copy these verbatim.

- **Folder shape** — `Commands/`, `Queries/`, `Events/`, `Ports/`, `DTOs/`,
  `ReadModels/`, `ValueObjects/`, `Services/`, `Repositories/`,
  `Projections/`. Hexagonal port placement is CI4-free.
- **Named-factory pattern** — `Cookie::create` / `Cookie::reconstitute`,
  `CookieName::fromString`, `CookiePrice::fromString` / `fromMinorUnits`.
- **Final-by-default + readonly VOs/DTOs/events/commands/queries** — 31/31
  in-scope files have `declare(strict_types=1)`.
- **Constructor property promotion** — every command/query/event uses it.
- **Named arguments at call sites** with > 2 args.
- **`#[DomainServiceProvider]` + `#[AutoBind]`** — auto-discovery works at
  runtime.
- **`registerRoutes()` on the provider** — domain owns its routes.
- **Optimistic locking pattern** in
  `CookieRepository::updateWithOptimisticLock`.
- **Outbox-first / dispatcher-second ordering** in `dispatchPendingEvents()`.
- **Versioned event envelope (SV-1)** in `EventOutboxWriter`/Relay.
- **`DomainEventInterface` as security gate** —
  `EventOutboxRelay::rehydrate()` refuses to instantiate any class that
  doesn't implement the marker.
- **Transactional dispatch toggle** —
  `EventDispatcher::setRethrowOnListenerFailure()`.
- **Composite UNIQUE `(tenant_id, name, deleted_at)`** in the migration —
  thoughtful (modulo theme T8 and T19).
- **`ErrorCodes` file with scoping contract**.
- **`@internal` markers** on `bumpVersion`/`assignId`.
- **Read/write port split**.
- **`CookieReadModelProjection.php.example`** with the exemplary
  deprecation header.
- **`UsersEmailUniqueWithSoftDelete` migration** explicitly discusses the
  NULL-vs-NULL UNIQUE caveat — Cookie should match this honesty.
- **`jobs` table has the leasing shape the outbox lacks** —
  `available_at`, `reserved_at`, `max_attempts`, `last_error`. The outbox
  should mimic this pattern.
- **`document_sequences` with `(series, scope)` UNIQUE + transactional
  `FOR UPDATE`**.
- **Migration `dropTable($name, true)` with `cascadeForeignKeys` flag**.
- **`CookieOptimisticLockingTest`** has the right shape (3 tests).
- **`CookieQueryE2ETest`** exemplifies the right feature-test posture.
- **Test conventions** — `test_…` snake_case; no mocked VOs; `$refresh =
  true`; `failOnRisky="true"`.
- **`get_debug_type()`** instead of `gettype()` in
  `EventOutboxRelay:221, 243, 251, 258`.
- **`json_encode` with `JSON_THROW_ON_ERROR`** in `EventOutboxWriter:186`,
  `AuditMiddleware:224`.
- **Strong class-level prose** on VOs and on the migration — these are
  template-grade.
- **`@deprecated`** on `CookiePrice::getValue` / `format` is used
  correctly with replacement pointers (modulo the contradiction in T3).

---

## Scope-of-work envelope (v2)

Approximate sizing for executing the remediation plan in
`REMEDIATION-PLAN.md`:

- **Files touched:** **~135–170 production files + ~35–45 test files + ~8
  documentation files**. Cookie has ~57 in-tree files; the v2 remediation
  also edits Shared, Infrastructure (outbox + Config\Database +
  phpstan.neon + phpcs.xml + ci.yml + docblocks-audit), Config, and the
  scaffolding skill, plus migrations for outbox and money.
- **LoC delta:** roughly **+2,400 / −2,800 net −400**. Most of the deletion
  comes from collapsing handler boilerplate (≈ 280 LoC across 7 handlers)
  and the dead `CookieView`/`PriceFormatter` paths (≈ 250 LoC). Most of
  the addition comes from `Abstract*Handler` + `AbstractDomainEvent` +
  `ReadDTOInterface` + new tests + scaffolding regeneration + outbox
  hardening migration + sessionVariables block.
- **PR count:** **~18 epics → ~22 PRs**. Three epics (handler migration,
  read-side consolidation, MySQL CI lane + outbox-hardening) likely split
  into 2 PRs each because their diffs cross 300 LoC and have independent
  risk profiles.
- **Human-review hours:** **~36–48 hours** spread across ~22 PRs.
  Foundation PRs (E04–E06 abstract bases + hydrator) are high-impact /
  low-LoC / careful review = ~3 hours each. Destructive schema migration
  PR (E09 money) is destructive and needs a careful migration rehearsal =
  ~5 hours. MySQL CI lane (E01) needs to confirm both SQLite and MySQL
  lanes green + outbox SKIP LOCKED rehearsal = ~5 hours. Remaining PRs
  ~1.5–2.5 hours each.
- **Calendar (one contributor, serial PRs):** realistic delivery is **~5
  weeks**. Two contributors with parallel foundation epics compress to
  **~3 weeks**; the schema-migration / outbox-hardening / docs epics gate
  everything after them.

Companion file: [`REMEDIATION-PLAN.md`](./REMEDIATION-PLAN.md) sequences
these into epics with explicit dependency graph, per-epic acceptance
gates, and a Finding-to-Epic Matrix that allocates every single finding.

---

## Cross-references to prior audits

- **Round 1** (15 area-based audits) — first pass; identified the open-by-
  default auth (round 1 / R10), the entity-return-in-handlers CRITICAL,
  the SQLite-only test suite, and the missing `CookieRestoredEventHandler`.
  Verified-as-fixed in r3: `CookieRestoredEventHandler` (slice 05), entity
  returns DTOs (slices 03 + 07).
- **Round 2** (15 thematic audits) — second pass; identified the
  `CookiePrice` USD-cents bound problem (R03 V3), the
  read-model-silently-dead pattern (R03 V5), the composite-UNIQUE NULL
  pitfall (R03 V4 / R06 V8), and the LoggerFactory in unit tests pattern
  (R07). Verified-as-fixed in r3: projection is now `.example` with
  exemplary deprecation header (slice 05 F10). Still open from r2:
  `CookiePrice` bounds (slice 02 F1); composite UNIQUE caveat (slice 18
  F-S1); LoggerFactory in unit tests (slice 12 F1); seeder bypasses VOs
  (slice 11 F2); `Cookie.php`/`CookieRepository.php` size (slice 14 F6 +
  slice 15 F9).
- **Final-sweep** — spot-checks; flagged `edit.php:101–103` `esc()`
  regression (now fixed; slice 10 F7 spot-check) and confirmed
  `IntegrationTestCase` eagerly constructs CookieRepository (slice 13 F8;
  still open).
- **Round 3** (this audit, 18 slices) — adds MySQL operational (slice 18),
  documentation gate (slice 16), PHP 8.4 forward-look (slice 17), and
  deeper PHP idiom audit (slice 14 / 17 overlap). Three new CRITICALs
  (outbox VARCHAR truncation, outbox missing UNIQUE, SQLite-locked test
  blinds MySQL claims) emerged that round-2 could not detect with the
  thematic angles it used.

The Cookie-ready-as-template checklist in the remediation plan is the
single binary verifier for "is round 3 done?".
