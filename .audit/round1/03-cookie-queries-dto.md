# 03 — Cookie queries + DTOs

## Files audited

- `app/Domain/Cookie/Queries/GetCookieById/GetCookieByIdQuery.php`
- `app/Domain/Cookie/Queries/GetCookieById/GetCookieByIdHandler.php`
- `app/Domain/Cookie/Queries/GetAllCookies/GetAllCookiesQuery.php`
- `app/Domain/Cookie/Queries/GetAllCookies/GetAllCookiesHandler.php`
- `app/Domain/Cookie/Queries/GetCookiesPaginated/GetCookiesPaginatedQuery.php`
- `app/Domain/Cookie/Queries/GetCookiesPaginated/GetCookiesPaginatedHandler.php`
- `app/Domain/Cookie/ReadModels/CookieView.php`
- `app/Infrastructure/Bus/QueryBus.php`
- Supporting: `app/Models/Cookie/CookieRepository.php`, `app/Models/Cookie/CookieModel.php`, `app/Domain/Cookie/Projections/CookieReadModelProjection.php`, `app/Controllers/Domain/Cookie/CookieController.php`

## Findings

### Immutability of query DTOs

- OK — `GetCookieByIdQuery.php:19` `final readonly`, single `public int $id`.
- OK — `GetAllCookiesQuery.php:16` `final readonly`, single `bool` flag.
- OK — `GetCookiesPaginatedQuery.php:17` `final readonly`. Properties assigned in ctor via clamping; values are immutable after construction.

### Pagination input validated/clamped

- OK — `GetCookiesPaginatedQuery.php:42-43` clamps `page >= 1`, `perPage in [1, 100]`.
- **LOW** — `GetCookiesPaginatedQuery.php:42` only floors `page` at 1 — no upper bound. A caller passing `page=999999999` triggers a real `LIMIT … OFFSET 19999999980` against `cookies` table. Combine with absence of a maximum total cap and this is a cheap DoS vector. Cap `page` at e.g. `ceil(MAX_TOTAL/perPage)` or `PHP_INT_MAX / perPage`.
- **LOW** — `GetCookiesPaginatedQuery.php:42-46` constructor silently coerces bogus values instead of rejecting them. For an API surface this hides client bugs. Consider throwing `ValidationException` for negative `page` or `perPage > MAX`.

### Search input sanitisation against SQL injection

- OK at the SQL boundary — `CookieRepository.php:461` uses CI4 query builder `like('name', $searchTerm)` which parameter-binds. Not raw SQL.
- **MEDIUM** — `GetCookiesPaginatedQuery.php:44` only `trim()`s `searchTerm`. No length cap, no character whitelist, no LIKE wildcard escaping. A user-supplied `%` or `_` matches every row and silently changes query semantics. A 1 MB search term will be sent verbatim to the DB. Add `mb_substr($s, 0, 100)` and `addcslashes($s, '%_\\')` before handing to `like()`, or push the cap into the query builder call.
- **MEDIUM** — `CookieRepository.php:461` `like('name', …)` with default `'both'` side wildcards forces full-table scan on `cookies.name` (no FULLTEXT, no prefix index helps with leading `%`). Becomes a perf cliff at scale even though it isn't an injection.

### Handlers return DTOs (CookieView) or domain entities?

- **CRITICAL** — None of the three handlers return `CookieView`. They return raw `Cookie` entities, contradicting the documented "DTOs are the new pattern".
  - `GetCookieByIdHandler.php:54` returns `?Cookie`.
  - `GetAllCookiesHandler.php:51` returns `array<int, Cookie>`.
  - `GetCookiesPaginatedHandler.php:53` returns `array{data: array<int, Cookie>, …}`.
- **CRITICAL** — `CookieView` exists (`app/Domain/Cookie/ReadModels/CookieView.php`) but is dead code outside tests. `grep CookieView` across `app/` returns only its own file. Controllers (`CookieController.php:67,91,173`) hand raw `Cookie` entities to views, leaking aggregate internals (events, version, factory state) to the presentation layer.
- **HIGH** — `CookieView::detail()` (`CookieView.php:53`) and `summary()` (`CookieView.php:74`) read `getId() ?? 0`; an unpersisted entity silently becomes id `0` in JSON. Should throw — a read-model row without an id is a bug, not a value.

### Tenant scoping

- **CRITICAL** — Zero tenant scoping in any query path. `CookieModel.php:44` declares `tenant_id` as an allowed write column, the read-model migration (`2026-05-20-200000_CreateCookieReadModelTable.php:39-44`) reserves a `tenant_id` column and indexes it, the projection writes `'tenant_id' => null` (`CookieReadModelProjection.php:203`), yet:
  - `CookieRepository.php:425, 454` `executeFindAll`/`executeFindPaginated` never filter on `tenant_id`.
  - `CookieRepository.php:151` `findById` calls `$model->find($id)` directly — any tenant can fetch any other tenant's cookie by guessing IDs.
  - `Queries/*Query.php` carry no tenant context.
  - `CookieController.php:50-92` never injects current-user tenant.
  This is a cross-tenant data-leak surface, not a stylistic issue.

### N+1 risks

- LOW — Current read shape (`Cookie` aggregate has no child collections) means none of the three handlers exhibit N+1.
- **MEDIUM** — `CookieRepository.php:161` calls `$this->trackPopularCookie($id)` on every `findById`. If that helper writes to a metrics store synchronously (it lives in `BusinessMetricsLogging`), every detail-page hit causes an extra round-trip. At minimum it shouldn't be in the read path; move to a fire-and-forget channel or event.
- **MEDIUM** — `CookieReadModelProjection.php:179-191` performs `countAllResults` + then `update` or `insert` — two queries per projected row during rebuild. For ≥ ~10k rows this is a real cost. Use `ON DUPLICATE KEY UPDATE` (MySQL) or a single upsert.

### Does the read path actually use `cookie_read_model`?

- **CRITICAL** — No. All three query handlers go through `CookieRepositoryInterface` → `CookieRepository` → `CookieModel` whose `$table = 'cookies'` (`CookieModel.php:30`). The new `cookie_read_model` table (D15) is populated by `CookieReadModelProjection` but **never read** by `GetCookieById`, `GetAllCookies`, or `GetCookiesPaginated`. The projection is write-only side-effect work; the entire CQRS read story is currently a fiction. Either: (a) introduce a `CookieReadModelRepository` and switch the query handlers to it, or (b) explicitly accept the read-side is the same physical table for now and delete the projection until needed.
- **HIGH** — Because of the above, every documented read-model benefit (composite index on `(tenant_id, name_search)`, precomputed `available`, `price_formatted`) is paid for in writes but yields zero query benefit.

### Soft-delete leakage

- OK for `findAll`/`findPaginated` — `CookieRepository.php:425, 454` add explicit `where('deleted_at IS NULL')`.
- OK for `findById` — `CookieModel.php:35` sets `useSoftDeletes = true`, so `$model->find()` filters trashed rows by default.
- **MEDIUM** — `CookieRepository.php:425, 454` use a raw string `'deleted_at IS NULL'` rather than the model's native soft-delete handling (because the code calls `$model->builder()`, which bypasses the model layer's automatic `deleted_at` filter). Easy to forget on any new query. The query builder approach also won't honour the `$deletedField` config if someone renames it. Either route through model methods that respect soft deletes, or wrap the filter behind a `withoutDeleted()` helper on the repository so it's impossible to forget.
- **LOW** — `GetAllCookiesQuery::$includeInactive` only toggles `is_active`, not `deleted_at`. The doc on the query (`GetAllCookiesQuery.php:11-12`) is accurate but the naming makes callers think it controls "all". If anyone ever needs a "with trashed" variant it'll be wired through the wrong flag.

### CookieView read-model DTO

- **HIGH** — `CookieView.php:49` types `array $extra = []` with PHPDoc `array<string, scalar|null>` but `toArray()` (`CookieView.php:97-110`) silently drops `extra` from the serialised output. Either include it in the array (most useful for the tenant/audit fields the docblock anticipates) or remove the property — currently it's misleading dead state.
- **MEDIUM** — `CookieView::detail()`/`summary()` couple the DTO to the `Cookie` entity (`use App\Domain\Cookie\Entities\Cookie`, `CookieView.php:7,53,74`). If the read path moves to `cookie_read_model`, the DTO will need a `fromRow(array $row)` factory. Add it now; tying the DTO to the aggregate is exactly the leak the file's own docblock warns against.
- **LOW** — `CookieView.php:59` `price` returned as `string` via `toDecimalString()`. Loses precision/currency information already encoded in `Money`. The projection table has `price_minor`, `price_currency`, `price_formatted` — the DTO ought to expose all three (or a structured `price` object), not a single ambiguous decimal.
- **LOW** — `CookieView::summary()` (`CookieView.php:74`) nulls `description`, `createdAt`, `updatedAt`, `deletedAt`. Callers receive a different shape depending on the static factory. Consider two distinct DTO classes (`CookieDetailView`, `CookieSummaryView`) so static analysis can enforce the shape, instead of one class with conditional fields.

### QueryBus contract

- OK — `QueryBus.php:40` single-handler-per-query, throws on double-register (`:58`), throws on unregistered (`:81`), throws on missing `handle()` (`:90`).
- **LOW** — `QueryBus::ask()` returns `mixed` (`:77`). No generic enforcement that handlers actually return a DTO. With no convention enforced, the "DTOs are the new pattern" assertion lives only in a docblock. A `QueryHandlerInterface<TQuery, TResult>` (or at minimum a marker interface) would let static analysis catch the entity-return regression noted above.
- **LOW** — `QueryBus.php:90` `method_exists()` check duplicates what a `QueryHandlerInterface::handle()` contract would give for free.

### Misc

- **LOW** — `GetCookieByIdHandler.php:128-130`, `GetAllCookiesHandler.php:133-135`, `GetCookiesPaginatedHandler.php:143-145` each define their own `shouldSample()`. Same logic in three places.
- **LOW** — `GetCookiesPaginatedHandler.php:83` forces a log entry on every search query regardless of configured `queryLoggingLevel`. The docstring calls this "analytics" but it bypasses the operator's logging-level config. If the goal is search analytics, send to a search-analytics channel, not the operational query log.
- **LOW** — `GetCookieByIdHandler.php:120`, `GetAllCookiesHandler.php:125`, `GetCookiesPaginatedHandler.php:135` log at `info` even for slow queries. Slow queries should escalate to `warning` so they surface in default log filters.

## Template-cloning risks

If this domain is the canonical template, anything cloned per-domain inherits these defects. The following deserve a base/abstract before more domains land:

1. **AbstractPaginatedQuery DTO** — Page/perPage clamping logic (`GetCookiesPaginatedQuery.php:42-46`) plus search-term sanitisation will otherwise be reimplemented per domain, each with a different `MAX_PER_PAGE`. Lift `DEFAULT_PAGE`, `DEFAULT_PER_PAGE`, `MAX_PER_PAGE`, the trim, the (missing) length cap and (missing) LIKE-escape into a single class.
2. **AbstractQueryHandler<TQuery, TResult>** — The logging boilerplate (`logQueryExecution`, `logQuery`, `shouldSample`, slow-query detection) is duplicated nearly verbatim across all three handlers — ~80 lines/handler × 3 handlers × N domains. Move to a trait/base and let each concrete handler implement only `doHandle()`.
3. **QueryHandlerInterface** — Currently the only contract is the duck-typed `handle()` method (`QueryBus.php:90`). A typed interface enforces "must return a DTO" and unlocks PHPStan generic typing.
4. **TenantScopedRepository / TenantContext** — Tenant scoping must be a framework concern, not a per-domain reinvention. Otherwise the cross-tenant leak documented above will be replicated on every new domain. A repository base that automatically applies `tenant_id = :currentTenant` and a `TenantContext` service injected at the boundary.
5. **ReadModelRepository pattern** — There is currently no abstraction for "read from the projection table". Whatever the Cookie domain ends up doing here (`CookieReadModelRepository`?) needs to be lifted to a generic shape before User/Order/etc clone the broken read-from-write-table pattern.
6. **CookieView / read-DTO pattern** — `detail()`/`summary()` factory pair on a single class will be cloned per domain. Codify as either two-classes-per-domain or as an abstract `ResourceView` with an `extra` slot that actually serialises.
7. **Soft-delete filter helper** — Raw `where('deleted_at IS NULL')` strings (`CookieRepository.php:425, 454`) will get forgotten on the first cloned domain. A `withoutTrashed()` repository helper or a query-builder scope should encapsulate it.

## Verdict

**FAIL.** Three issues block this domain from being held up as a template:

1. **CRITICAL — Tenant scoping is entirely absent** from query, handler, repository, and controller, despite the schema reserving the column. Any new domain copying this will inherit a multi-tenant data leak.
2. **CRITICAL — Read path does not use `cookie_read_model`.** The projection writes; nothing reads. CQRS read-side is a no-op.
3. **CRITICAL — Handlers return `Cookie` entities, not `CookieView` DTOs.** The DTO is dead code in production paths; the "DTOs are the new pattern" claim is unverified.

Plus **HIGH/MEDIUM** issues on LIKE wildcards / search-term length, `CookieView::$extra` dead field, projection upsert cost, slow-query log level, soft-delete being a raw string, and synchronous `trackPopularCookie` in the read path.

Do not clone this domain as-is. Fix tenant scoping, decide whether to actually read from the projection or remove it, and switch handlers to return `CookieView` before promoting to template status.
