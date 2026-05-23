# RE-AUDIT 04 — Queries & Read Handlers (Round 3)

**Slice:** GetById/GetAll/GetPaginated queries + handlers
**Reviewer:** cqrs-specialist
**Re-audit date:** 2026-05-23
**Original audit:** `.audit/round3/04-queries-handlers.md` (13 findings)
**PRs claimed:** #34 (E05), #36 (E08), #37 (E05.5)

## TL;DR

Infrastructure for the F1/F7/F11/F12 fixes was built in `app/Domain/Shared/Bus/{AbstractQueryHandler,QueryHandlerInterface,LogSampler}.php` — but **only `QueryHandlerInterface` and `LogSampler` are actually adopted by the three Cookie handlers**. `AbstractQueryHandler` exists as orphaned scaffolding. As a result, F3 (typed contract) and F12 (CSPRNG sampling) are genuinely closed, but the rest of the original audit's HIGH/MEDIUM findings remain present in the canonical reference handlers that future domains will copy. The infrastructure-without-adoption pattern means the template ships a worked example that doesn't use the template's own abstractions.

## Verdict shift

| Original | Re-audit | Direction |
|---|---|---|
| READY-WITH-FIXES | READY-WITH-FIXES | unchanged |

The bus tightening (F3) is real; the per-handler hygiene (F1/F2/F4/F6/F7) was not delivered in the reference domain.

## Closure matrix

| # | Sev | Title | Status | Evidence |
|---|---|---|---|---|
| F1 | HIGH | Logging boilerplate duplicated | **PARTIAL** | `AbstractQueryHandler.php:41–190` exists; **none** of the three Cookie query handlers extend it. All three still ship 60–90 lines of `logQueryExecution` / `logQuery` / `shouldSample`. |
| F2 | HIGH | `GetAllCookies` unbounded | **OPEN** | `GetAllCookiesQuery.php:16–27` has no `MAX_RESULTS` constant. `CookieQueryRepository::findAll()` emits no `LIMIT`. |
| F3 | HIGH | No `QueryHandlerInterface` | **CLOSED** | Interface exists; `QueryBus::register()` typehints it; all three handlers `implements QueryHandlerInterface`. `method.notFound` suppression is gone. |
| F4 | MED | Search not length-capped / LIKE-escaped | **PARTIAL** | `GetCookiesPaginatedQuery.php:44` still just does `trim($searchTerm)` — no `mb_substr` cap. `CookieQueryRepository.php:131` uses CI4's `$builder->like()`, which does escape wildcards by default; the length cap and contract are absent. |
| F5 | MED | No sort/order whitelist | **OPEN** | No `sortBy` / `sortDir`. Repository hard-codes `orderBy('id', 'ASC')`. |
| F6 | MED | No page ceiling | **OPEN** | `max(self::DEFAULT_PAGE, $page)` — no `MAX_PAGE`. |
| F7 | MED | Slow queries logged at `info` | **OPEN** | All three handlers still call `$this->logger->info('Query executed', $context)` even when `$isSlowQuery === true`. `AbstractQueryHandler::logQueryExecution()` does the correct `warning` escalation — but Cookie handlers don't use it. |
| F8 | MED | Search-analytics override bypasses log-level | **OPEN** | `GetCookiesPaginatedHandler.php:87` — `if ($isSlowQuery || $isSearchQuery)` still forces a log on every search regardless of `queryLoggingLevel`. |
| F9 | MED | `GetCookieById` null-on-miss has no contract | **OPEN** | Handler still returns `?CookieDTO` with the same "Controller can decide" docblock. |
| F10 | LOW | Hard-coded `'Cookie'` / query-class strings | **OPEN** | Still literal strings at `GetCookieByIdHandler.php:112–113` and equivalents. |
| F11 | LOW | No caching seam | **PARTIAL** | `AbstractQueryHandler::cacheKey()` / `cacheTtlSeconds()` hooks exist but handlers don't extend the base so the seam is unreachable. |
| F12 | LOW | `mt_rand()` for sampling | **CLOSED** | All three handlers' `shouldSample()` now delegates to `new LogSampler(...)->shouldSample()`. `LogSampler` uses `random_int(1, 10_000)`. |
| F13 | INFO | `GetAllCookiesQuery` docblock misleading | **OPEN** | Docblock still says "Returns all cookies that are: Active". Unchanged. |

**Tally:** Closed 2 (F3, F12) · Partial 3 (F1, F4, F11) · Open 8 (F2, F5, F6, F7, F8, F9, F10, F13).

## New issues introduced

- **N1 — LOW — Orphaned `AbstractQueryHandler` is a template-debt trap.** Fully built (190 lines, documented) but **nothing in the codebase extends it**. A future maintainer reading the abstract base will assume the Cookie handlers use it (they don't).
- **N2 — LOW — `new LogSampler(...)` per call inside `shouldSample()`.** `GetCookieByIdHandler.php:139` and the other two construct a fresh `LogSampler` every invocation. The original `LogSampler` docblock at `:30` even calls this out: "E08 lifts this to constructor injection when migrating onto AbstractQueryHandler." E08 didn't.
- **N3 — INFO — `QueryHandlerInterface::handle()` signature is `object $query` not `TQuery`.** PHP-8-generics limitation, not regressive.

## Top 3 still-open

1. **F1 + F7 + F11 — Migrate the three Cookie handlers onto `AbstractQueryHandler`.** All the work is already done; the gap is purely adoption.
2. **F2 + F6 — Bound `GetAllCookies` and cap `GetCookiesPaginated.page`.** Still template-level OOM/DoS surfaces.
3. **F4 + F5 — Search-input hygiene at the query DTO layer + sort story.** `mb_substr($term, 0, 100)` + whitelist-enum pattern.

## Praiseworthy deltas vs. round-1

- `QueryHandlerInterface` with `@template TQuery` / `@template-covariant TResult` — F3 structurally closed.
- `LogSampler` clean single source of truth, input validation in `[0.0, 1.0]` — F12 fix more thorough than the audit asked for.
- `AbstractQueryHandler` well-designed (template-method, slow→warning, cacheKey hook, ClockInterface injection) — architecture right, just needs adopters.

**Severity counts after re-audit:** CRITICAL 0 / HIGH 2 (F1 partial + F2 open) / MEDIUM 5 / LOW 3 / INFO 2.
**Net delta:** −2 closed, +3 partial, +3 new (informational/low). Substantive template-cloning risk **unchanged** because the three handlers — the actual reference artefacts — still carry the round-1 boilerplate.
