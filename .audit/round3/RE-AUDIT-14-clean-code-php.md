# RE-AUDIT 14 — Clean Code & PHP Usage

**Slice:** Cross-cutting code quality across `app/Domain/Cookie/**`
**Reviewer:** clean-code-specialist (re-audit)
**Date:** 2026-05-23
**Original audit:** `.audit/round3/14-clean-code-php.md`
**Branch under review:** `stabilization/erp-foundation`
**Merged into integration:** E04 (shared kernel polish) + E05 (handler bus contracts / `LogSampler`) + E06 (`AggregateHydrator` + reconstitute guard) + E07 (entity owns lifecycle + `CookieStateAssertions` + `CookieSnapshot`)
**Open PRs consulted (not yet merged here):** PR #34 (E05 — abstract bases; *MERGED PARTIALLY* — only `LogSampler` + `CommandHandlerInterface`/`QueryHandlerInterface` typing landed; the abstract `LoggedCommandHandler` base did NOT), PR #36 (E08 — handlers ≤ 20 LoC; postCommit pattern; **NOT MERGED**), PR #39 (E17 — `#[\Override]`, `Stringable`, controller `final`, `CookieStock::$value` private; **NOT MERGED**), PR #41 (E11 — CookieRepository code health; **NOT MERGED**).

## TL;DR

Of the 23 round-3 findings, **6 are fully fixed** in the integration branch (F13 entity accessors trait deleted; F17 `assertPersisted` extracted & narrowed; F18 `CookieRestoredEvent` envelope-only `occurredAt`; F20 partial — `LogSampler` shared and uses `random_int`; partial F7 — `CookieSnapshot` VO replaced the scalar map and `COOKIE_STATE_NOT_PERSISTED` error code exists). **3 more are fixed only behind unmerged PRs** the original audit pointed at (F1/F2/F3 await E08+E11; F4 ErrorCodes-as-enum still open; F6 CookieRepository still 587 LoC because E11/PR #41 is unmerged). **The remaining ~14 findings (F5 string-keyed DI registry, F8 deprecated VO methods, F9 `CookieDTO::fromEntity` still calls deprecated `format()`, F10/F11 `CookieView::$extra` + `?? 0`, F12 mixed snake/camel log keys, F14 `PriceFormatter` not `readonly`, F15 placeholder docblocks, F16 inline FQCN `TenantContext`, F19 double-clamp, F21 mixed `microtime`/`hrtime`, F22 service-locator `LoggerFactory::create()`) are unchanged.** The headline regression risk — every command handler is still 90–150 LoC and three of the four still carry a copy-pasted `try { … } catch (\Throwable) { log + rethrow }` scaffold with drifting key conventions — has not actually moved on the integration branch yet. The verdict shifts **READY-WITH-FIXES → STILL-READY-WITH-FIXES (no improvement landed on this slice)**: the in-tree state is virtually identical to round 3 for handlers, repository and service provider; only the entity + shared bus surface improved.

## Verdict
STILL-READY-WITH-FIXES (no net change on the headline issues)

## Per-finding status (integration branch)

| # | Status | Where it lives now | Notes |
|---|---|---|---|
| F1 — Handler boilerplate × 4 | UNFIXED | `Commands/{Create,Update,Delete,Restore}Cookie/*Handler.php` (167 / 169 / 99 / 84 LoC) | Lives behind PR #36 (E08). No `AbstractCommandHandler` exists in `app/Domain/Shared/Bus/`. `Create::handle()` still 81 LoC, `Update::handle()` still 93 LoC. |
| F2 — `determineErrorCode()` string sniffing | UNFIXED | `CreateCookieHandler.php:154-175`, `UpdateCookieHandler.php:156-167` | Create still does `match (true) { str_contains($e->getMessage(), 'name must be unique') => … }`. Update keeps the substring branch through default. |
| F3 — Query-handler logging policy ×3 | PARTIALLY FIXED | All three `Get*Handler.php` files; shared `LogSampler` at `app/Domain/Shared/Bus/LogSampler.php` | `shouldSample()` now delegates to `LogSampler` (~5 LoC each instead of 8 of `mt_rand`). The `match('all','errors','slow','sampling')` magic-string state machine and `logQueryExecution`/`logQuery` duplication remain in all three handlers. |
| F4 — `ErrorCodes` should be a backed enum | UNFIXED | `app/Domain/Cookie/ErrorCodes.php` | Still `final class` with 13× `public const int`. Two codes were added (`COOKIE_STATE_NOT_PERSISTED = 403`, `COOKIE_STATE_NOT_DELETED = 404`) — both inherit the same non-enum anti-pattern. |
| F5 — String-keyed DI registry | UNFIXED | `CookieServiceProvider.php:80, 96-108, 154-164, 276-285, 308-311` | Still `array<string, object> $repositories` + `instanceof` checks + `\RuntimeException('Invalid repository, event dispatcher or logger type injected')`. |
| F6 — `CookieRepository` ≥ 586 LoC | UNFIXED | `CookieRepository.php` — now **587 LoC** | PR #41 (E11) not yet merged into integration. No `CookieEntityMapper`, `CookieOptimisticLocker`, or `CookieEventDrainer` extractions in tree. `performSave()` 44 LoC, `executeFindPaginated()` 46 LoC. |
| F7 — Entity legacy concerns | PARTIALLY FIXED | `Cookie.php` (349 LoC, up from 288); `CookieSnapshot.php` (81 LoC, NEW); `CookieStateAssertions.php` (68 LoC, NEW) | Wins: `assertPersisted()` extracted + returns `int` (F17 fix); `snapshot()` now returns a typed `CookieSnapshot` VO over `CookieChangeSet`; `assignId()` retains `\LogicException` raw throw — UNFIXED. New `COOKIE_STATE_NOT_PERSISTED = 403` code is used. Entity LoC grew 21 % (288 → 349) because the accessors trait was inlined back (F13 fix) and lifecycle/event work expanded. **Still over the 200-line cap by 75 %.** |
| F8 — `@deprecated` methods on VOs | UNFIXED | `CookiePrice.php:101-108, 120-131` (`getValue()`, `format()`), `CookieName.php:124-127` (`equalsIgnoreCase()`) | Identical signatures + deprecation tags. |
| F9 — `CookieDTO::fromEntity` calls deprecated `format()` | UNFIXED | `DTOs/CookieDTO.php:44` (`formattedPrice: $cookie->getPrice()->format()`) | Same line, same deprecation warning. The read side `CookieQueryRepository::formatPrice()` (line 197) ALSO calls `CookiePrice::fromString(...)->format()` — the deprecated path is the de-facto canonical formatter, contradicting the `@deprecated` tag's "Use `PriceFormatter::format()`". |
| F10 — `CookieView::$extra` YAGNI slot | UNFIXED | `ReadModels/CookieView.php:36-49` | Still `public array $extra = []`. |
| F11 — `CookieView::detail/summary` silently coerces `null` id → `0` | UNFIXED | `ReadModels/CookieView.php:59, 80` (`id: $cookie->getId() ?? 0`) | Unchanged. |
| F12 — snake_case vs camelCase log keys | UNFIXED | `CreateCookieHandler.php:75-78` (`name`, `price`, `stock`, `isActive`) + `127-128` (`cookieId`, `duration_ms`); `DeleteCookieHandler.php:55` (`cookieId`); `RestoreCookieHandler.php:65, 75` (`cookie_id`); `Models/Cookie/Traits/RepositoryLogging.php:79, 108-114` (`result_count`, `duration_ms` next to `searchTerm`) | Same drift. The `cookieId` / `cookie_id` split survives across the four command handlers. Repository trait (E13 territory) untouched. |
| F13 — `CookieAccessors` trait | **FIXED (E07)** | `app/Domain/Cookie/Entities/CookieAccessors.php` deleted; accessors inlined into `Cookie.php:117-168` | Comment on line 116 explicitly cites slice 01/F8. |
| F14 — `PriceFormatter` should be `final readonly` (typed class consts) | UNFIXED | `Services/PriceFormatter.php:22` (`final class PriceFormatter`); `CookieName.php:39-40` still untyped (`private const MIN_LENGTH = 3`); `CookieStock.php` still has no typed constants | E17/PR #39 carries these polish fixes and is unmerged. |
| F15 — Placeholder/empty docblocks | UNFIXED | `DTOs/CookieDTO.php:18-20, 34-36, 52-54`; `Commands/{Update,Delete,Restore}*Command.php`; `CookieRepository.php:151, 161, 392, 486`; event handlers (`__invoke.`) | Verified four placeholder blocks remain (`__construct.`, `fromEntity.`, `isOutOfStock.`, `isDuplicateKey.`, `dispatchPendingEvents.`, `getOldPrice.`, `raiseConcurrentModification.`, `determineErrorCode.`). |
| F16 — Inline FQCN `?\App\Infrastructure\Tenancy\TenantContext` | UNFIXED | `CookieRepository.php:72, 94` | No `use` import added. |
| F17 — `(int) $this->id` cast after guards | **FIXED (E07)** | `Cookie.php` no longer casts; `CookieStateAssertions::ensurePersisted()` returns the non-null `int` so callers (`softDelete:226`, `restore:242`, `setActive:273`) get the typed id directly. `changeStock` (line 332) uses `\assert($this->id !== null)` for PHPStan. |
| F18 — `CookieRestoredEvent` carried ISO string | **FIXED (E07)** | `Events/CookieRestored/CookieRestoredEvent.php:27-40` constructor takes `\DateTimeImmutable $occurredAt` from the envelope; no `$restoredAt` string field. `jsonSerialize()` only adds `cookieId`. |
| F19 — Double-clamp `page`/`perPage` in DTO + repository | UNFIXED | `Queries/GetCookiesPaginated/GetCookiesPaginatedQuery.php:42-43` (`max`/`min`) and `Repositories/CookieQueryRepository.php:113-114` (`max(1,$page)`, `max(1, min(100, $perPage))`) | Both layers still clamp; the `MAX_PER_PAGE = 100` constant is not shared. |
| F20 — `mt_rand` sampling duplicated ×3 | **FIXED (E05)** | `app/Domain/Shared/Bus/LogSampler.php` (85 LoC) is the single source; uses `random_int()` (CSPRNG) and integer basis-points. All three query handlers' `shouldSample()` now call `new LogSampler(...)->shouldSample()` instead. Note: each handler still constructs the sampler per-call (cheap, but allocates) — see "Residual debt" below. |
| F21 — Mixed `microtime` (3 handlers) vs `hrtime` (DeleteCookieHandler) | UNFIXED | `DeleteCookieHandler.php:50, 76` uses `hrtime(true)` + `/1_000_000`; the other three handlers + the repository's `logSlowQuery()` use `microtime(true)`. | Same drift. |
| F22 — `CookieServiceProvider::registerEvents()` calls `LoggerFactory::create('cookie.events')` directly | UNFIXED | `CookieServiceProvider.php:195` | Still service-locator inside an otherwise DI-registered provider. |
| F23 — `CookieAccessors` trait `@property` duplication | **FIXED (E07)** | Trait deleted (see F13). Accessors now inlined; entity is the single source. |

## Size table (integration branch, current)

| file | LoC | cap | over by |
|---|---:|---:|---:|
| `Commands/CreateCookie/CreateCookieHandler.php` | 177 | 200 | ok class / `handle()` 81 LoC method **4.0× over** |
| `Commands/UpdateCookie/UpdateCookieHandler.php` | 169 | 200 | ok class / `handle()` 93 LoC method **4.6× over** |
| `Commands/DeleteCookie/DeleteCookieHandler.php` | 99 | 200 | ok class / `handle()` 51 LoC method **2.5× over** |
| `Commands/RestoreCookie/RestoreCookieHandler.php` | 84 | 200 | ok class / `handle()` 39 LoC method **1.9× over** |
| `Entities/Cookie.php` | 349 | 200 | **+74 %** (grew from 288 by inlining accessors + lifecycle methods) |
| `Repositories/CookieRepository.php` | 587 | 200 | **+193 %** (unchanged; was 586 pre-round-3) |
| `Repositories/CookieQueryRepository.php` | 230 | 200 | +15 % (1 LoC growth — comment edits) |
| `CookieServiceProvider.php` | 312 | 200 | +56 % (was 284; grew with extra event subscribers in E07) |
| `ValueObjects/CookiePrice.php` | 224 | 200 | +12 % (unchanged) |
| `Queries/GetCookiesPaginated/GetCookiesPaginatedHandler.php` | 155 | 200 | ok class / `logQuery()` 26 LoC **+30 %**, `logQueryExecution()` 24 LoC **+20 %** |
| `Queries/GetAllCookies/GetAllCookiesHandler.php` | 145 | 200 | ok class / methods within cap |
| `Queries/GetCookieById/GetCookieByIdHandler.php` | ~135 | 200 | ok class / `logQueryExecution()` 23 LoC **+15 %** |

**Headline:** 8 size violations remain (vs 9 in round 3). The drop is because the read side did not regress; nothing new was extracted from the write side.

## Status summary

- FIXED: F13, F17, F18, F23 — **4 / 23 (17 %)**
- PARTIALLY FIXED: F3 (sampler only), F7 (snapshot + assertions only), F20 (CSPRNG + single class but per-call allocation) — **3 / 23 (13 %)**
- UNFIXED: F1, F2, F4, F5, F6, F8, F9, F10, F11, F12, F14, F15, F16, F19, F21, F22 — **16 / 23 (70 %)**

## Verdict shift

**round 3:** READY-WITH-FIXES.
**re-audit:** STILL-READY-WITH-FIXES — verdict UNCHANGED. The three "Top 3 fixes before cloning" called out in the original audit (F1/F2 handler boilerplate, F4 enum + F3 policy, F6 split + F12 keys) are **all still in tree as-is or live behind unmerged PRs (E08/E11/E17)**. The integration branch landed only entity-internal cleanup (E07 — F13/F17/F18/F23) and one shared kernel helper (E05 — F20 partial). The 587-LoC repository, the 81-LoC `CreateCookieHandler::handle()`, and the string-keyed DI registry are unchanged and would still be the dominant defects a `sed Cookie → Foo` clone inherits today.

## Biggest residual

The single biggest defect remaining is **F1 + F2 combined**: every command handler is still 39–93 LoC long inside one method, three of the four still wrap a 70-line `try { … } catch (\Throwable $e) { logger->error(...); throw }` scaffold, and two of the four still ship `determineErrorCode()` substring matching on exception messages. Because the integration branch did not merge E08, a clone today inherits four handlers that each violate the "≤ 20 LoC method" rule by 2–5× *and* a `match(true) { str_contains($e->getMessage(), 'name must be unique') => ErrorCodes::COOKIE_BUSINESS_RULE_NAME_DUPLICATE, … }` block whose key strings drift the moment anyone tweaks an exception message at the throw site (the repository already says "Cookie name must be unique within the tenant.", which the `str_contains('name must be unique')` branch still happens to match — fragile coupling that a clone will silently break).

Secondary residual: F6 (CookieRepository at 587 LoC, 2.9× the cap, mixing `BusinessMetricsLogging` + `RepositoryLogging` traits + outbox + optimistic-lock + tenant-stamping + DB mapping) is the worst single file in the slice and stays the worst single file a clone starts from.

## What is correct / praiseworthy (still true, plus new wins)

Confirmed still true from round 3: universal `declare(strict_types=1);`, final-by-default, `readonly` on every VO/DTO/event/command/query/handler, constructor property promotion universal, named arguments at every >2-arg call, `CookieRestoredEventHandler` registered, `never` return on `raiseConcurrentModification()`.

**New since round 3:**
- `CookieSnapshot` (81 LoC, `final readonly`) wraps the previously-loose `array<string,scalar|null>` snapshot in a typed VO over `CookieChangeSet` — closes the F7 "snapshot drifts with the cloner's mood" concern.
- `CookieStateAssertions` (68 LoC) extracted `ensureNotDeleted` + `ensurePersisted` as pure static methods; the entity is no longer the source of these gates, and `ensurePersisted` returns the non-null `int` so callers get type-narrowed access without `(int)` casts (F17).
- `LogSampler` (85 LoC, `final readonly`) replaces three private `mt_rand()/mt_getrandmax()` copies with one CSPRNG-backed sampler in integer basis points — eliminates float-comparison surprises at 0/100 % boundaries.
- `Cookie::reconstitute()` rejects `version < 1` (E06) with an explicit `\InvalidArgumentException` that says "likely malformed DB row or migration drift" — good error ergonomics.
- The `AggregateHydrator` key on `assignId()` / `bumpVersion()` is a clean way to give the repository write access to entity internals without making the methods part of the domain API.
- New error codes 403 (`COOKIE_STATE_NOT_PERSISTED`) and 404 (`COOKIE_STATE_NOT_DELETED`) close the misuse of `COOKIE_STATE_DELETED` for two unrelated gates that round 3 flagged.

## Top 3 fixes before cloning (re-ordered for current state)

1. **Land E08 (PR #36) and E11 (PR #41) into integration.** This is the single highest-leverage move: E08 deletes the per-handler 70-line `try/catch/log` scaffold by hoisting it into an `AbstractCommandHandler` base, which simultaneously kills F1, F2 (drop the substring `match`), F12 (one key convention in one place), and F21 (one time base in one place). E11 splits the 587-LoC repository which closes F6 + reduces the surface that F12 also touches.
2. **Convert `ErrorCodes` to `enum CookieErrorCode: int` (F4).** Independent of any PR; ~30 minutes of work. Removes the int-soup at every `throw` site, gives `match` exhaustiveness, and surfaces cross-domain code collisions as type errors instead of runtime confusion.
3. **Stop calling `CookiePrice::format()` from `CookieDTO::fromEntity` and `CookieQueryRepository::formatPrice()` (F9).** Currently the `@deprecated` method is the project's de-facto formatter — the deprecation tag is a lie that PHPStan and IDE tooling will both flag in a clone. Either drop the deprecation tags or actually route through `PriceFormatter::format()`.

