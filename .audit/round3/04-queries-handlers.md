# 04 — Queries & Read Handlers

**Slice:** GetById/GetAll/GetPaginated queries + handlers
**Reviewer:** cqrs-specialist
**Date:** 2026-05-22
**Source files reviewed:** 8 (3 queries, 3 handlers, 1 read port, 1 QueryBus)

## TL;DR

Queries are properly immutable; handlers correctly return DTOs (round-1's "CRITICAL — handlers return entities" is fixed) and depend on a narrowed `CookieQueryRepositoryInterface` (read port), not the write repo. However, the handlers ship ~80 lines of duplicated logging boilerplate that will multiply per future domain, search/sort inputs lack a length cap and whitelist, `GetAllCookies` is unbounded, every query logs at `info` (slow queries should escalate to `warning`), and the `QueryBus`+handlers have no `QueryHandlerInterface<TQuery,TResult>` contract, so a future cloned domain can silently return an entity again.

## Verdict
READY-WITH-FIXES

## Findings

### F1 — HIGH — Logging boilerplate duplicated three times, will multiply per domain
- **Location:** `GetCookieByIdHandler.php:74-131`, `GetAllCookiesHandler.php:72-137`, `GetCookiesPaginatedHandler.php:79-147`
- **Observation:** `logQueryExecution`, `logQuery`, `shouldSample`, slow-query detection and the same `match` over `queryLoggingLevel()` are reimplemented near-verbatim in all three handlers. `shouldSample()` is identical in all three. The "core read" of each `handle()` is 3 lines; the rest is plumbing.
- **Why this is a template defect:** A new domain with 3 queries pays this duplication price 3 times; 10 domains = 30 copies that all drift independently. The round-1 audit explicitly flagged this as the #2 template-cloning risk and it remains unaddressed.
- **Suggested fix:** Lift to an `AbstractQueryHandler` (or `LogsQueries` trait) in `app/Domain/Shared/`. Concrete handler implements `doHandle(TQuery): TResult` and `queryName(): string`; base handles timing/logging/sampling.

### F2 — HIGH — `GetAllCookiesQuery` is unbounded
- **Location:** `GetAllCookiesQuery.php:16-27`, `GetAllCookiesHandler.php:52-63`, `CookieQueryRepositoryInterface.php:37`
- **Observation:** No `LIMIT`, no max-result safety net. A cloned domain with 500k rows will OOM the PHP process on the first call. The handler returns `array<int, CookieDTO>` — every row reconstituted as a DTO in memory.
- **Why this is a template defect:** Cookie is treated as the canonical "small enum-like list" but the template gives no signal that GetAll is dangerous. The next domain (Orders, AuditLog…) will copy the pattern and inherit a latent DoS / OOM.
- **Suggested fix:** Either delete `GetAllCookies` from the template (force callers to use the paginated variant), or add an explicit `MAX_RESULTS` constant on the query, enforce it in the repository, and log+throw when exceeded. Document that GetAll is only safe for bounded reference data.

### F3 — HIGH — No `QueryHandlerInterface<TQuery, TResult>` contract
- **Location:** `app/Infrastructure/Bus/QueryBus.php:65-71, 96-97`, all three handlers
- **Observation:** `QueryBus::register` checks `method_exists($handler, 'handle')` (duck typing) and `ask()` returns `mixed`. There is no typed contract that says "a query handler must exist and must return a DTO/array/null — never a domain entity." `@phpstan-ignore method.notFound` at `:96` papers over the missing interface.
- **Why this is a template defect:** The whole reason round-1 flagged the entity-return CRITICAL is that nothing structurally prevents it. A cloned domain can write `function handle(GetFooByIdQuery $q): Foo` and the bus will accept it without warning. PHPStan generics would catch this for free.
- **Suggested fix:** Introduce `interface QueryHandlerInterface { /** @template TQuery of object @template TResult */ public function handle(object $query): mixed; }` or a per-handler typed signature. Have `QueryBus::register` typehint it. Delete the `method_exists` reflection.

### F4 — MEDIUM — Search term not length-capped or LIKE-escaped
- **Location:** `GetCookiesPaginatedQuery.php:44`
- **Observation:** Constructor does `trim($searchTerm)` only. No `mb_substr` cap, no escaping of `%` and `_`. A caller passing `%` matches every row; a 1 MB string is forwarded verbatim to MySQL. Round-1 (MEDIUM) flagged this; still present.
- **Why this is a template defect:** This is the canonical paginated-search shape. Every future domain that clones it inherits the same wildcard-leak + memory amplification.
- **Suggested fix:** In the constructor: `$trim = $searchTerm !== null ? mb_substr(trim($searchTerm), 0, 100) : null;` and escape `%_\` before handing to the repository's `like()`. Better: lift `AbstractSearchablePaginatedQuery` with `MAX_SEARCH_LEN = 100` and an `escapedSearchTerm()` accessor.

### F5 — MEDIUM — No sort/order input at all on the paginated query
- **Location:** `GetCookiesPaginatedQuery.php` (entire file)
- **Observation:** Pagination has no `sortBy` / `sortDir`. This is actually safer than free-form sort (no SQL-by-orderby), but the template gives zero example of *how* to do sort safely. The first cloned domain that needs `?sort=created_at` will invent it ad-hoc, very likely via free-form input.
- **Why this is a template defect:** Cookie is the reference. The reference has no answer for "how do I sort a list page" — so every new domain will improvise. Improvised sort is where SQL-injection-by-orderby lives.
- **Suggested fix:** Either explicitly document "sorting is out of scope for the template — always whitelist" in the query class docblock, or add a `sortBy` field with an `ALLOWED_SORT_FIELDS` const + `sortDir` constrained to `enum SortDirection { case Asc; case Desc; }`. Show the whitelist pattern once so it gets copied correctly.

### F6 — MEDIUM — Page number has a floor but no ceiling
- **Location:** `GetCookiesPaginatedQuery.php:42`
- **Observation:** `$this->page = max(self::DEFAULT_PAGE, $page);` — `page=999999999` is accepted and triggers `LIMIT 20 OFFSET 19999999980`. MySQL still scans the whole table to that offset. Round-1 LOW; still unfixed.
- **Why this is a template defect:** Cheap DoS via a single querystring parameter, cloned to every paginated read in every future domain.
- **Suggested fix:** Cap `page` at `PHP_INT_MAX / $perPage` or, better, cap at a sane absolute (e.g. `MAX_PAGE = 10000`). Throw `ValidationException` rather than silently clamp so client bugs surface.

### F7 — MEDIUM — Slow queries logged at `info`, not `warning`
- **Location:** `GetCookieByIdHandler.php:120`, `GetAllCookiesHandler.php:126`, `GetCookiesPaginatedHandler.php:136`
- **Observation:** Even when `$isSlowQuery === true`, `$this->logger->info('Query executed', $context)` is used. The slow-query flag is just an extra context key.
- **Why this is a template defect:** Operators filtering on `level >= warning` won't see slow queries. The whole point of slow-query logging is alerting; INFO defeats it. Cloned per-domain = silent perf cliff everywhere.
- **Suggested fix:** `$this->logger->{$isSlowQuery ? 'warning' : 'info'}(...)`. Better still, surface this in the abstract handler from F1.

### F8 — MEDIUM — Search analytics override bypasses operator's log-level config
- **Location:** `GetCookiesPaginatedHandler.php:84-87`
- **Observation:** `if ($isSlowQuery || $isSearchQuery)` forces a log entry on every search regardless of `queryLoggingLevel`. Comments call this "analytics" but it pollutes the operational log channel.
- **Why this is a template defect:** Operators who set `queryLoggingLevel = 'errors'` to silence noise will still get a flood of search logs. Cloned per-domain, this becomes systemic noise.
- **Suggested fix:** Route search-analytics to a dedicated `search-analytics` log channel, not the operational logger. Or move it behind a separate `searchLoggingEnabled` config switch.

### F9 — MEDIUM — `GetCookieById` null-on-miss has no documented contract anywhere structural
- **Location:** `GetCookieByIdHandler.php:54`, `CookieQueryRepositoryInterface.php:32`
- **Observation:** The handler returns `?CookieDTO` and the docblock says "Controller can decide". That's a reasonable choice, but the template gives no example of what the controller does — does it 404, throw `EntityNotFoundException`, return an empty view? Inconsistency across future domains is guaranteed.
- **Why this is a template defect:** The reference domain leaves the most common decision (what does "not found" mean?) unanswered. Cloned domains will each choose differently.
- **Suggested fix:** Either commit to nullable DTO (current) **and** show the controller doing `throw new NotFoundException()` once, or commit to handler-throws-on-miss. Pick one in the template and document why.

### F10 — LOW — Hard-coded `'Cookie'` / query-class strings in logging context
- **Location:** `GetCookieByIdHandler.php:109-110`, `GetAllCookiesHandler.php:115-116`, `GetCookiesPaginatedHandler.php:119-120`
- **Observation:** `'domain' => 'Cookie'` and `'query' => 'GetCookieByIdQuery'` are string literals. A `sed s/Cookie/Foo/g` clones the string fine, but the second literal could be derived from `static::class` / `$query::class`.
- **Why this is a template defect:** Two sources of truth for the query name (class + log string). Easy to forget to update one when renaming.
- **Suggested fix:** Use `$query::class` (or `(new ReflectionClass($query))->getShortName()`) and `static::DOMAIN` constant defined once in the abstract handler from F1.

### F11 — LOW — No caching layer / cache invalidation hook in the query path
- **Location:** All three handlers; `CookieQueryRepositoryInterface.php`
- **Observation:** Zero `cache`, `Cache`, or `remember` references in the queries directory. Round-2 docs imply caching is intentionally deferred, but the template gives no seam for it.
- **Why this is a template defect:** Adding caching later requires touching every handler. A `CachedQueryHandlerDecorator` or even just a documented `cacheKey(): ?string` hook on the base would let domains opt in without rewrites.
- **Suggested fix:** In the abstract handler (F1), expose `protected function cacheKey(TQuery $q): ?string { return null; }` and `protected function cacheTtlSeconds(): int { return 0; }`. Implement the cache check in the base. Document the invalidation contract (which events bust which key).

### F12 — LOW — `mt_rand()` for sampling decision is not seeded per-request consistently
- **Location:** `GetCookieByIdHandler.php:130`, `GetAllCookiesHandler.php:136`, `GetCookiesPaginatedHandler.php:146`
- **Observation:** `mt_rand() / mt_getrandmax() < rate` is fine for sampling, but `random_int(0, PHP_INT_MAX) / PHP_INT_MAX` (or just `random_int(1, 100) <= $ratePercent`) is the modern PHP 8.3 idiom and avoids the LCG bias of `mt_rand`.
- **Why this is a template defect:** Pattern gets copied verbatim into every cloned handler.
- **Suggested fix:** Move sampling to the abstract handler. Use `random_int`.

### F13 — INFO — `GetAllCookiesQuery` docblock is misleading
- **Location:** `GetAllCookiesQuery.php:8-13`
- **Observation:** Docblock says "Returns all cookies that are: Active (is_active = true), Not deleted (deleted_at = null)" but the constructor takes `$includeInactive`. The "active" sentence contradicts the parameter.
- **Why this is a template defect:** Future domain authors copy the docblock and forget to update it for `$includeArchived`, etc.
- **Suggested fix:** Rewrite to: "Returns all non-deleted cookies. By default excludes inactive (is_active=false); pass `$includeInactive=true` to include them."

## What is correct / praiseworthy

- All three query DTOs are `final readonly` with public promoted properties. No setters anywhere.
- `GetCookiesPaginatedQuery` clamps `perPage` to `[1, MAX_PER_PAGE=100]` — the `MAX_PER_PAGE` cap is the right move and is properly enforced before the value reaches the repository.
- Handlers correctly depend on a narrowed read port (`CookieQueryRepositoryInterface`) that exposes only `findById / findAll / findPaginated` and returns DTOs only — the read/write split round-1 demanded is now in place.
- Handler `handle()` methods are short (≤ 15 lines each) — all logging complexity is correctly extracted to private helpers.
- `findPaginated` return shape is precisely typed via PHPStan array shape (`array{data:..., total:int, page:int, perPage:int, lastPage:int}`) — `total`, `lastPage`, and the requested page are all returned. No off-by-one in the contract.
- Soft-delete exclusion is documented in the read port's class-level docblock (the read port acknowledges it shares the physical table).
- `GetCookiesPaginatedQuery` trims `searchTerm` to `null` when blank, avoiding the "empty string vs null" ambiguity downstream.

## Top 3 fixes before cloning

1. **Introduce `AbstractQueryHandler` + `QueryHandlerInterface<TQuery, TResult>`** (F1 + F3). Eliminates ~80 lines of per-handler duplication and structurally prevents the next domain from returning an entity. Slow-query level escalation (F7), `random_int` sampling (F12), and the cache hook (F11) all land naturally on the base.
2. **Bound `GetAllCookies` and cap `GetCookiesPaginated.page`** (F2 + F6). Today both are template-level OOM/DoS surfaces. Either delete GetAll or document+enforce a `MAX_RESULTS`; cap `page` and throw on overflow.
3. **Sanitize search input properly: length cap + LIKE-escape, and pick a sort story** (F4 + F5). `mb_substr($term, 0, 100)` + `addcslashes($term, '%_\\')` in the query constructor, and either explicitly forbid sort or demonstrate the whitelist-enum pattern once so cloned domains don't improvise an injection.

**Severity counts:** CRITICAL 0 / HIGH 3 / MEDIUM 6 / LOW 3 / INFO 1 (total 13).
**Top finding:** F1 — HIGH — Logging boilerplate duplicated three times across handlers; the template will replicate ~80 lines of identical timing/sampling/match code into every future domain. Pair with F3 (missing `QueryHandlerInterface`) and one base class fixes both.
