# 14 — Clean Code & PHP Usage

**Slice:** Cross-cutting code quality across app/Domain/Cookie/**
**Reviewer:** clean-code-specialist
**Date:** 2026-05-22
**Source files reviewed:** 31 PHP files, ~2,200 LoC

## TL;DR

The Cookie domain is broadly disciplined: `declare(strict_types=1)` is universal,
final-by-default is honoured, `readonly` is used on every VO/DTO/event/command/query,
constructor property promotion is consistent, and PHP 8.3 typed-class-constants
appear in `CookieStock`, `CookiePrice`, `GetCookiesPaginatedQuery`, and `ErrorCodes`.
The big horizontal defects a `sed Cookie → Foo` clone will inherit are: (1) the
command-handler boilerplate (start-time, log "starting", try/catch, log success,
log failure, `determineErrorCode`) is copy-pasted four times with subtle drift —
`CreateCookieHandler` is 167 LoC and crosses the 200-line CLAUDE.md threshold once
the "exit door" `match` is counted; (2) the four query handlers re-implement an
identical "should I log this query?" state machine with the same `mt_rand()`
sampler and the same `'all'|'errors'|'slow'|'sampling'` magic-string match — that
belongs in a shared trait or QueryLoggingPolicy; (3) `ErrorCodes` is a class of
typed `const int`s but should be a `IntBackedEnum` in PHP 8.3+ — a clone will
inherit the "stringly-typed numeric code" anti-pattern; (4) `CookieServiceProvider`
wires repositories via a string-keyed array (`'cookieRepository'`, `'logger'`,
`'loggingConfig'`) that the cloner has to find-and-replace by hand and which
explodes at runtime, not compile time. Verdict: **READY-WITH-FIXES.**

## Verdict
READY-WITH-FIXES

## Method/class size offenders

| file:line | symbol | LoC / cyclo | rule violated |
|---|---|---|---|
| `Commands/CreateCookie/CreateCookieHandler.php:65-139` | `handle()` | 75 LoC body (74 lines between `{`..`}`) / cyclo ~6 | **CLAUDE.md "Max 20 lines per method"** — 3.7× over |
| `Commands/CreateCookie/CreateCookieHandler.php:144-165` | `determineErrorCode()` | 22 LoC / cyclo 6 (5-arm `match(true)` over `str_contains`) | "Max 20 lines" + fragile string sniffing |
| `Commands/UpdateCookie/UpdateCookieHandler.php:56-149` | `handle()` | 94 LoC / cyclo ~7 | "Max 20 lines" — 4.7× over |
| `Commands/DeleteCookie/DeleteCookieHandler.php:50-121` | `handle()` | 72 LoC / cyclo 4 | "Max 20 lines" — 3.6× over |
| `Commands/RestoreCookie/RestoreCookieHandler.php:35-78` | `handle()` | 44 LoC / cyclo 5 | "Max 20 lines" — 2.2× over |
| `Commands/CreateCookie/CreateCookieHandler.php` | class file | 167 LoC | under 200 but ~85 % consumed by `handle()` boilerplate |
| `Queries/GetAllCookies/GetAllCookiesHandler.php:52-86` (`handle` + `logQueryExecution`) | per-method | 12 + 15 LoC | OK |
| `Queries/GetCookieById/GetCookieByIdHandler.php:74-96` | `logQueryExecution()` | 23 LoC / cyclo 5 | borderline; `match` itself is 7 lines |
| `Queries/GetCookiesPaginated/GetCookiesPaginatedHandler.php:79-102` | `logQueryExecution()` | 24 LoC / cyclo 5 | over 20 LoC |
| `Queries/GetCookiesPaginated/GetCookiesPaginatedHandler.php:112-137` | `logQuery()` | 26 LoC / cyclo 3 | over 20 LoC |
| `ValueObjects/CookiePrice.php` | class file | 224 LoC | **over 200-line CLAUDE.md cap** (12 % over) |
| `Repositories/CookieRepository.php` | class file | 586 LoC | **2.9× the 200-line cap** |
| `Repositories/CookieRepository.php:412-455` | `performSave()` | 44 LoC / cyclo 5 | over 20 LoC |
| `Repositories/CookieRepository.php:540-585` | `executeFindPaginated()` | 46 LoC / cyclo 5 | over 20 LoC |
| `Repositories/CookieQueryRepository.php:107-159` | `findPaginated()` | 53 LoC / cyclo 4 | over 20 LoC |
| `Entities/Cookie.php` | class file | 288 LoC (lifecycle + accessors-trait split) | over 200 even after the `CookieAccessors` extraction |
| `CookieServiceProvider.php` | class file | 284 LoC | over 200 |

**Headline:** 9 methods and 6 classes break the documented size caps. Every
command handler does.

## Findings

### F1 — HIGH — Command-handler boilerplate is duplicated four times and is the dominant cost of the file
- **Location:** `Commands/CreateCookie/CreateCookieHandler.php:65-165`, `Commands/UpdateCookie/UpdateCookieHandler.php:56-165`, `Commands/DeleteCookie/DeleteCookieHandler.php:50-121`, `Commands/RestoreCookie/RestoreCookieHandler.php:35-78`.
- **Observation:** Each command handler implements the same five-step scaffold by hand: `$startTime = microtime(true)` (Delete uses `hrtime`, drift), `logger->info('… starting', [...])`, try block with domain work, `$durationMs = (...) * 1000` (Delete uses `/ 1_000_000`, more drift), `logger->info('… success', [...])`, catch `\Throwable`, `determineErrorCode($e)` (only Create + Update implement it; Delete inlines a ternary; Restore has no error-code mapping at all), `logger->error('… failed', [...])`, rethrow. The result is ~75 lines of bookkeeping wrapping ~10 lines of business logic in every handler.
- **Why this is a template defect:** A `sed Cookie → Foo` clone inherits four near-identical files that drift independently the moment anyone touches one. The DRY violation also makes the "Max 20 lines per method" CLAUDE.md rule impossible to honour — every `handle()` is 44-94 lines.
- **Suggested fix:** Extract a `LoggedCommandHandler` trait (or a `CommandLogger` collaborator) exposing `withLogging(string $command, array $context, callable $body): mixed` that owns the startTime/try/catch/durationMs/error-code lookup. Pull `determineErrorCode()` into a per-domain `ErrorCodeResolver` keyed by exception type — see F2.

### F2 — HIGH — `determineErrorCode()` matches on exception message substrings
- **Location:** `CreateCookieHandler.php:144-165`, `UpdateCookieHandler.php:154-165`.
- **Observation:**
  ```php
  return match (true) {
      str_contains($e->getMessage(), 'name must be unique') => ErrorCodes::COOKIE_BUSINESS_RULE_NAME_DUPLICATE,
      str_contains($e->getMessage(), 'stock') => ErrorCodes::COOKIE_BUSINESS_RULE_STOCK_NEGATIVE,
      str_contains($e->getMessage(), 'name') => ErrorCodes::COOKIE_VALIDATION_NAME,
      str_contains($e->getMessage(), 'price') => ErrorCodes::COOKIE_VALIDATION_PRICE,
      default => ErrorCodes::COOKIE_REPOSITORY_SAVE_FAILED,
  };
  ```
  The exception message is presentation, not a stable contract — adjusting "name must be unique" to "Cookie name must be unique within the tenant" (which `CookieRepository::save()` already does at line 133) silently re-routes the error code to `COOKIE_REPOSITORY_SAVE_FAILED`.
- **Why this is a template defect:** The string-sniffing pattern will be cloned into every new domain. New error messages will silently regress error codes for monitoring and API problem-detail responses.
- **Suggested fix:** Always pass the error code at the throw site (already done in most places — see `CookieName`, `CookieStock`, `CookieRepository::save`). Treat `$e->getErrorCode() !== 0` as the only path; the `match (true)` block is dead defence and should be deleted.

### F3 — HIGH — Query-handler logging policy is copy-pasted three times
- **Location:** `Queries/GetAllCookies/GetAllCookiesHandler.php:65-137`, `Queries/GetCookieById/GetCookieByIdHandler.php:67-131`, `Queries/GetCookiesPaginated/GetCookiesPaginatedHandler.php:72-147`.
- **Observation:** Every query handler carries `logQueryExecution()`, `shouldLogByLevel()` (or inlined match), `logQuery()`, and `shouldSample()` with identical bodies — the only delta is the context payload. The `match` over `'all' | 'errors' | 'slow' | 'sampling'` is a magic-string state machine repeated three times.
- **Why this is a template defect:** Every new query in every new domain inherits ~70 LoC of cargo-culted logging. The strings `'all'`, `'errors'`, `'slow'`, `'sampling'` are not enums — a typo in one handler silently disables logging there.
- **Suggested fix:** Introduce `QueryLoggingPolicy` (or extend `LogConfigPort`) with `enum QueryLoggingLevel: string`. The shared policy takes `(query, result, durationMs)` and emits the log line. Each handler becomes 3-5 lines.

### F4 — HIGH — `ErrorCodes` is not an enum
- **Location:** `app/Domain/Cookie/ErrorCodes.php`.
- **Observation:** A final class with 12 `public const int FOO = 101;` declarations. PHP 8.3 supports `enum ErrorCode: int { case ValidationName = 101; ... }`. The current shape lets callers pass any `int` to APIs that say `int $errorCode`, defeating type safety, and it forces `match` consumers to write `default => …` instead of getting exhaustiveness for free.
- **Why this is a template defect:** Every cloned domain will create a parallel `ErrorCodes` class with the same anti-pattern. The numeric-scoping comment at lines 14-23 acknowledges that codes collide across domains; an enum-per-domain would surface that statically as a different type.
- **Suggested fix:** Convert to `enum CookieErrorCode: int`. Update `DomainException::businessRuleViolation`, `ValidationException::*`, and the logger contexts to accept either the enum or its `->value`.

### F5 — HIGH — `CookieServiceProvider` resolves dependencies through a string-keyed array
- **Location:** `CookieServiceProvider.php:86-122, 133-169, 280-283`.
- **Observation:** `setRepositories(array $repositories)` and `getRepository(string $name)` push every dependency through `array<string, object>`. The handler in `registerCommands()` then has to `instanceof` check and throw `\RuntimeException('Invalid repository, event dispatcher or logger type injected')`. A typo in `'cookieRepository'` vs `'CookieRepository'` is undetectable until runtime, and the type-safety lecture in the class docblock at lines 58-59 ("Type-safe: Interface enforcement…") is false — the registry is just `mixed`.
- **Why this is a template defect:** Every cloned `FooServiceProvider` will repeat the same string keys (`'fooRepository'`, `'fooQueryRepository'`) — and the cloner will silently forget to update `setRepositories()` callers. The pattern also makes `getRepositories()` (line 248) the source of truth for keys but does not declare expected types.
- **Suggested fix:** Replace `$repositories` with typed properties (`?CookieRepositoryInterface $cookieRepository = null`, etc.) and use a `setDependencies()` method with explicit parameters, or use PSR-11 container injection with constructor wiring.

### F6 — HIGH — `CookieRepository` is 586 LoC and mixes write, read-of-old-state, audit-stamping, optimistic-locking, outbox, tenant scoping, and two distinct logging traits
- **Location:** `Repositories/CookieRepository.php`.
- **Observation:** The class uses `BusinessMetricsLogging`, `RepositoryLogging`, takes seven constructor dependencies (logger, logging config, model, dispatcher, outbox writer, tenant context, plus the implicit default model), and exposes `save / delete / restore / findById / findAll / findPaginated / existsByName / existsByNameExcludingId / findByIdWithTrashed`. Five private methods (`isDuplicateKey`, `dispatchPendingEvents`, `getOldPrice`, `performSave`, `updateWithOptimisticLock`, `raiseConcurrentModification`, `toDomainEntity`, `executeFindAll`, `executeFindPaginated`) make the file 2.9× the 200-line cap.
- **Why this is a template defect:** A `sed Cookie → Foo` clone inherits a 586-line class as the *starting point*. The first edit to a new domain will already be in the worst-violation file.
- **Suggested fix:** Extract `CookieEntityMapper` (toDomainEntity, `getOldPrice`), `CookieOptimisticLocker` (`performSave`, `updateWithOptimisticLock`, `raiseConcurrentModification`), and `CookieEventDrainer` (`dispatchPendingEvents`). Keep the repository as the orchestrator.

### F7 — MEDIUM — `Cookie` entity carries deprecated/legacy concerns: dual `assertPersisted` error code, `LogicException` raw throw in `assignId`, snapshot-as-scalar-map
- **Location:** `Entities/Cookie.php:131-139, 199-222, 184-194`.
- **Observation:** `assertPersisted()` (line 213) uses `ErrorCodes::COOKIE_STATE_DELETED` for an "id is null" error — the code name is wrong. `assignId()` (line 131) throws raw `\LogicException` instead of a domain exception. `snapshot()` returns `array<string, scalar|null>` which has to be kept in lock-step with the projection / event payload; refactors will drift.
- **Why this is a template defect:** Both an enum-misuse and a raw-exception pattern propagate to clones.
- **Suggested fix:** Add `ErrorCodes::COOKIE_STATE_NOT_PERSISTED` (or convert to enum F4). Throw `DomainException::invalidState` from `assignId` for consistency. Consider a `CookieSnapshot` value object in place of the scalar map.

### F8 — MEDIUM — `CookiePrice` and `CookieName` ship `@deprecated` methods on a template
- **Location:** `ValueObjects/CookiePrice.php:101-108, 120-131`, `ValueObjects/CookieName.php:124-127`.
- **Observation:** `getValue(): float`, `format(?string)`, and `equalsIgnoreCase()` are marked deprecated or have legacy callers. A template should ship the *destination* shape, not the migration.
- **Why this is a template defect:** Clones will inherit deprecation warnings that have no meaning in their context, and may keep calling the deprecated path because the example is right there.
- **Suggested fix:** Remove the deprecated methods or move them into a `LegacyCookiePriceAdapter` that lives outside the reference template directory.

### F9 — MEDIUM — `DTOs/CookieDTO::fromEntity` calls a deprecated method
- **Location:** `DTOs/CookieDTO.php:44`.
- **Observation:** `formattedPrice: $cookie->getPrice()->format()` calls the `@deprecated` `CookiePrice::format()`. The deprecation notice says "Use `PriceFormatter::format()`", but the canonical DTO factory ignores it.
- **Why this is a template defect:** The template self-contradicts. Cloners will copy this and then PHPStan-warn against their own template.
- **Suggested fix:** Switch to `PriceFormatter::format($cookie->getPrice())`.

### F10 — MEDIUM — `CookieView` carries an `array<string, scalar|null> $extra = []` slot marked "currently unused"
- **Location:** `ReadModels/CookieView.php:36-50`.
- **Observation:** YAGNI placeholder in a reference template.
- **Why this is a template defect:** Clones will inherit a never-populated field; future developers will assume it carries meaning.
- **Suggested fix:** Remove `$extra` until a concrete reader needs it; the comment says "reserved for tenant_id, audit fields", but those are already first-class columns elsewhere.

### F11 — MEDIUM — `CookieView::detail()` and `summary()` silently coerce a `null` id to `0`
- **Location:** `ReadModels/CookieView.php:59, 80` — `id: $cookie->getId() ?? 0`.
- **Observation:** A View built from an un-persisted Cookie ends up with `id = 0`, indistinguishable from a real row with id 0 (impossible in this schema but possible in others).
- **Why this is a template defect:** Templates that paper over invalid state propagate the habit.
- **Suggested fix:** Make `CookieView::detail()` accept a persisted Cookie only — assert `getId() !== null` and throw `DomainException::invalidState` if it is. Or change `public int $id` to `public ?int $id`.

### F12 — MEDIUM — Logging context keys mix `snake_case` and `camelCase` in the same line
- **Location:** `CreateCookieHandler.php:69-76` uses `name`, `price`, `stock`, `isActive`. The success log at lines 114-119 uses `cookieId` and `duration_ms` together. `DeleteCookieHandler.php:54-58` uses `cookieId`; `RestoreCookieHandler.php:54-58` uses `cookie_id`. Across the four event handlers the same fact is logged as both `cookie_id` and `cookieId`.
- **Why this is a template defect:** Inconsistent keys break log aggregation: a Splunk query for `cookie_id=42` misses half the lines. The clone will inherit the inconsistency and amplify it.
- **Suggested fix:** Pick one convention (the project's `LOGGING_BEST_PRACTICES.md` says `snake_case`) and apply it everywhere.

### F13 — MEDIUM — `CookieStock::value` is a public readonly property and is reached through both `$stock->value` and a `getStock()` accessor that returns the int
- **Location:** `ValueObjects/CookieStock.php:32`, `Entities/CookieAccessors.php:50-53`, `Entities/Cookie.php:191, 251`.
- **Observation:** The codebase accesses stock as `$this->stock->value` (entity internals) and as `$cookie->getStock()` (returning the raw int, not the VO). This mixes "value object" with "primitive obsession" — external callers get an `int`, losing the VO benefits.
- **Why this is a template defect:** A future ChooseCookie or AdjustStock command will get an int from `getStock()` and have to re-wrap. The accessor effectively unwraps the VO at the aggregate boundary.
- **Suggested fix:** `getStock(): CookieStock` and let the caller `.value` if needed. The DTO can convert.

### F14 — LOW — `PriceFormatter` is `final class` but should be `final readonly class` (or just a static method on `CookiePrice`)
- **Location:** `Services/PriceFormatter.php:22`.
- **Observation:** Class has only one static method and no state; final-readonly would communicate "no state, no instance" intent. Or, given the deprecation in CookiePrice::format, it could just be the canonical method.
- **Suggested fix:** Mark `final readonly class PriceFormatter`. Optional: collapse into `CookiePrice::toFormatted()`.

### F15 — LOW — `CookieDTO` docblocks are auto-generated placeholders (`__construct.`, `fromEntity.`, `isOutOfStock.`)
- **Location:** `DTOs/CookieDTO.php:18-20, 34-36, 52-54`.
- **Observation:** Stub-style docblocks (`@docblocks:audit` allowed them through). Same pattern in `CreateCookieCommand`, `UpdateCookieCommand`, `DeleteCookieCommand`, `RestoreCookieCommand`, `RestoreCookieHandler`, `CookieStockChangedEventHandler` (`__invoke.`), `CookieRestoredEventHandler` (`__invoke.`), the four event constructors, several repository private methods (`isDuplicateKey.`, `dispatchPendingEvents.`, `raiseConcurrentModification.`, `determineErrorCode.`).
- **Why this is a template defect:** Documentation noise. A new developer reading the template sees ceremony with no information.
- **Suggested fix:** Delete the no-content docblocks; the strict types + parameter names are self-documenting. Keep only docblocks that add `@throws`, `@param array<…>` shape, or business context.

### F16 — LOW — `CookieRepository::__construct` uses inline fully-qualified `?\App\Infrastructure\Tenancy\TenantContext $tenantContext` instead of a `use` import
- **Location:** `Repositories/CookieRepository.php:71, 93`.
- **Observation:** The rest of the file imports its dependencies; only `TenantContext` is referenced FQN-inline. Minor inconsistency.
- **Suggested fix:** Add `use App\Infrastructure\Tenancy\TenantContext;` at the top.

### F17 — LOW — `Cookie::changeStock()` casts `(int) $this->id` after `assertPersisted` already proved it non-null
- **Location:** `Entities/Cookie.php:255`.
- **Observation:** `$this->id` is `?int`; PHPStan-narrowed after `assertNotDeleted()` + `assertPersisted()` (line 232-233, 245-246). The `(int)` cast is defensive but reads as "we don't trust our own guards". `assertPersisted` should narrow the type.
- **Suggested fix:** Use an `@phpstan-assert` annotation on `assertPersisted` so the cast is unnecessary.

### F18 — LOW — `CookieRestoredEvent` uses `string $restoredAt` (ISO-8601 string) while other events store nothing date-typed
- **Location:** `Events/CookieRestored/CookieRestoredEvent.php:20`, dispatch site `Commands/RestoreCookie/RestoreCookieHandler.php:75`.
- **Observation:** A `DateTimeImmutable` is constructed at the dispatch site and immediately serialised to string. The event then carries a string field that downstream consumers will parse back.
- **Suggested fix:** Use `\DateTimeImmutable $restoredAt` directly in the event; let serialisers handle the wire format.

### F19 — LOW — `GetCookiesPaginatedQuery` clamps inputs in the constructor (`max(1, $page)`, `min(...)`) while `CookieQueryRepository::findPaginated` clamps them again
- **Location:** `Queries/GetCookiesPaginated/GetCookiesPaginatedQuery.php:42-44`, `Repositories/CookieQueryRepository.php:113-114`.
- **Observation:** Two layers of "safe clamping" violate DRY and disagree on the max (the query caps at 100 via `MAX_PER_PAGE`, the repository also caps at 100 — at least they match today, but the constants are not shared).
- **Suggested fix:** Trust the query DTO. The repository should accept whatever the DTO produced.

### F20 — LOW — `mt_rand() / mt_getrandmax()` for sampling is duplicated three times and uses non-cryptographic RNG
- **Location:** All three query handlers (`shouldSample()`).
- **Observation:** Not a security defect (sampling doesn't need CSPRNG) but the duplication ties into F3. The expression is a slightly obscure way to write "uniform float in [0,1)" — would be clearer as a helper.
- **Suggested fix:** Move into the shared `QueryLoggingPolicy` from F3.

### F21 — INFO — Mixed time bases: `microtime(true)` in three handlers, `hrtime(true)` in `DeleteCookieHandler`
- **Location:** `DeleteCookieHandler.php:52, 98`.
- **Observation:** All four handlers measure duration, but Delete uses `hrtime(true)` then `/1_000_000`. Functionally equivalent for the magnitude, but stylistically inconsistent.
- **Suggested fix:** Standardise on one. `hrtime` is monotonic and more accurate — preferable.

### F22 — INFO — `CookieServiceProvider::registerEvents()` calls `LoggerFactory::create('cookie.events')` directly instead of receiving the logger via DI
- **Location:** `CookieServiceProvider.php:181`.
- **Observation:** Service-locator pattern inside a service provider that already has an `array<string, object>` of injected deps including a `'logger'` entry. Inconsistent with `registerCommands` and `registerQueries`.
- **Suggested fix:** Add `'eventLogger'` to `getRepositories()` and inject it.

### F23 — INFO — `Cookie` aggregate uses `CookieAccessors` trait but the trait file lives in the same `Entities/` folder, making the trait a hidden dependency
- **Location:** `Entities/CookieAccessors.php`.
- **Observation:** The split is well-reasoned (lines 12-17 of the trait) but the `@property` docblock duplicates the property list from `Cookie`, and any property added to `Cookie` must be added in two places (`Cookie.php` and the `@property` docblock of the trait) or PHPStan will complain on the trait in isolation. Clones will diverge.
- **Suggested fix:** Either inline the eight accessors back into `Cookie` (trivial methods, the 200-line cap is then exceeded by ~70 LoC — accept it for the entity), or move the trait to a `Cookie\Entities\Traits\` sub-namespace to flag it as a partial.

## What is correct / praiseworthy

- `declare(strict_types=1);` is present on every file in scope (31/31).
- Final-by-default is observed everywhere except `Entities/Cookie.php` (correctly non-final-but-aggregate — actually it IS `final`; only the trait is open).
- `readonly` is used uniformly on VOs (`CookieName`, `CookiePrice`, `CookieStock`), DTOs (`CookieDTO`, `CookieView`), events (all five), commands (all four), queries (all three), and handlers (all 11 handlers + 5 event handlers).
- Constructor property promotion is consistent — every command/query/event uses it without exception.
- PHP 8.3 typed class constants appear in `CookieStock` (none — uses plain), `CookiePrice` (`private const int`), `GetCookiesPaginatedQuery` (`private const int`), `ErrorCodes` (`public const int`), `CookieQueryRepository` (`private const string TABLE`). Adoption is partial but present.
- Named arguments are used at call sites with >2 args throughout — `Cookie::create(name:, description:, price:, stock:, isActive:)`, every event constructor, the DTO factory. This is consistent and excellent.
- `CookieName` and `CookiePrice` correctly hide their constructors behind static factories (`fromString`, `fromMinorUnits`).
- Domain exceptions carry stable error codes at most throw sites (the `ErrorCodes::COOKIE_*` constant is passed explicitly).
- `CookieRestoredEventHandler` exists — round 2 caught the missing subscription; this round confirms it's present and registered.
- `never` return type on `raiseConcurrentModification()` (CookieRepository:489) — PHP 8.1+ idiom used correctly.
- `Repositories/CookieQueryRepository.php` keeps the read side narrow: 230 LoC, single responsibility (rows → DTOs), tenant filtering centralised.

## Top 3 fixes before cloning

1. **Extract a `LoggedCommandHandler` trait + delete `determineErrorCode()` substring matching (F1 + F2).** The single biggest defect a clone inherits — four handlers, ~75 LoC each of identical bookkeeping, plus a `match (true)` that silently breaks when exception messages are tweaked. Replacing this with a trait or collaborator brings every `handle()` under the 20-line cap and removes the error-code regression risk in one shot.
2. **Convert `ErrorCodes` to a `IntBackedEnum` and a shared `QueryLoggingPolicy` (F4 + F3).** PHP 8.3-native enum gives type-safety and exhaustiveness; collapsing the duplicated query-handler logging into one policy eliminates ~150 LoC of repeated `match`/`shouldSample`/`mt_rand` code that every cloned domain will re-implement.
3. **Split `CookieRepository` and audit logging-key consistency (F6 + F12).** The 586-line repository is the worst single class in the template; clones will start out 2.9× over the size cap. Extract `CookieEntityMapper`, `CookieOptimisticLocker`, `CookieEventDrainer`. While in there, normalise every log context to `snake_case` (`cookie_id`, `duration_ms`, `result_count`) so log-aggregation queries work uniformly across handlers and events.
