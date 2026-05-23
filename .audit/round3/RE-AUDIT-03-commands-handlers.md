# RE-AUDIT — Slice 03 — Commands & Write Handlers

**Reviewer:** cqrs-specialist
**Date:** 2026-05-23
**PRs reviewed:** #34 (E05), #36 (E08), #37 (E05.5)
**Original slice:** `.audit/round3/03-commands-handlers.md`

## TL;DR

PR #34 (E05) landed: `CommandHandlerInterface` + `QueryHandlerInterface` + `ClockInterface`/`SystemClock` + `LogSampler` + `AbstractCommandHandler`/`AbstractQueryHandler` shared bases were created, and 22 handlers across the codebase (Cookie, User, Auth) now `implements CommandHandlerInterface`/`QueryHandlerInterface`. `CommandBus::register()` is typed and the dead `method_exists` check is gone. That is the entire delivered scope.

**PR #36 (E08) and PR #37 (E05.5) are paper PRs from the slice's perspective on the audited branch:**

- NO Cookie command handler extends `AbstractCommandHandler` (grep confirms zero matches outside the abstract class's own docblock and a single test fake). The 4 Cookie handlers still carry the 70-line boilerplate they did in Round 3. `DeleteCookieHandler.php:25` literally still says "E08 will collapse step 4 into AbstractCommandHandler::dispatchPulledEvents."
- `RestoreCookieCommand::$cookieId` is STILL `cookieId` (line 21), not `$id`. The class docblock `RestoreCookieHandler.php:21` literally says "E08 will… rename `$cookieId` to `$id`."
- `UpdateCookieCommand::$expectedVersion` is STILL `?int = null` (line 40), not required.
- `CreateCookieHandler::determineErrorCode()` still uses the `str_contains($e->getMessage(), ...)` resolver (lines 165-171).
- Custom PHPStan rules (`HandlerImplementsInterfaceRule`, `CommandQueryDtoIsReadonlyRule`, `HandleParamTypeMatchesCommandRule`) appear in `temp/cqrstemplate-agent-sandbox/tools/PHPStan/Rules/` — NOT in the live `phpstan.neon` of the audited branch.
- Repository drains `pullEvents()` inside `save()` but NOT inside `delete()` / `restore()`, so handlers' explicit drain loops are still the only path to dispatch for those operations. No `postCommit` hook in TransactionMiddleware; events fire synchronously inside the transaction.

## Closure matrix

| F# | Sev | Title | Status | Evidence |
|----|-----|-------|--------|----------|
| F1 | CRITICAL | Three competing event-dispatch patterns | **PARTIAL** | E07 moved Update/Delete/Restore lifecycle events into the entity. Create still hand-builds + dispatches directly. The repository drains events ONLY in `save()`, NOT in `delete()`/`restore()`. Still three patterns: (a) Create — handler instantiates + dispatches; (b) Update — entity raises, repo drains; (c) Delete/Restore — entity raises, handler drains. |
| F2 | CRITICAL | RestoreCookieHandler violates every convention | **PARTIAL** | Entity now owns the restore precondition. Handler itself still: has no `$startTime`/`duration_ms`; has no `try/catch`; throws raw `\RuntimeException` when `!$restored`; uses snake_case log keys; leaks actor id directly; command field is still `$cookieId`. Code-shape parity unfulfilled. |
| F3 | HIGH | `handle()` methods 70+ lines | **OPEN** | CreateCookieHandler.handle = ~82 lines, UpdateCookieHandler.handle = ~93 lines, DeleteCookieHandler.handle = ~50 lines, RestoreCookieHandler.handle = ~38 lines. All 4 handlers inline the start-log/try/success-log/catch/failure-log scaffold instead of inheriting `AbstractCommandHandler::handle`. |
| F4 | HIGH | `str_contains` determineErrorCode | **OPEN** | `CreateCookieHandler.php:165-171` retains the full `match (true) { str_contains(...) }` block unchanged from Round 3. |
| F5 | HIGH | CommandBus duck-typed | **CLOSED** | `CommandBus.php:95` typehints `CommandHandlerInterface $handler`. All 4 Cookie handlers + 12 other handlers implement the interface. Closure depends on TypeError-at-register-time only. |
| F6 | HIGH | UpdateCookieHandler catch omits exceptionClass + duration_ms | **OPEN** | `UpdateCookieHandler.php:141-147` still missing both fields. Four handlers, four failure-log shapes. |
| F7 | HIGH | Command shape drift (cookieId vs id; per-verb actor names) | **OPEN** | `RestoreCookieCommand.php:21` still `public int $cookieId`. Actor field name still per-verb. |
| F8 | HIGH | TransactionMiddleware silent on read-then-write | **OPEN** | `transBegin()` with no isolation hint. No TOCTOU warning. `CreateCookieHandler.php:87` still does the read-then-write. |
| F9 | MEDIUM | `expectedVersion` opt-in | **OPEN** | `UpdateCookieCommand.php:40`: still `public ?int $expectedVersion = null`. |
| F10 | MEDIUM | Restore idempotency rejects with wrong code | **CLOSED (partial improvement)** | `Cookie::restore()` now throws `COOKIE_STATE_NOT_DELETED` (dedicated 4xx code). |
| F11 | MEDIUM | Mixed `hrtime` vs `microtime` | **OPEN** | Create/Update use `microtime`, Delete uses `hrtime`, Restore has no timer. `SystemClock` exists but no handler injects it. |
| F12 | MEDIUM | Hard-coded `'domain' => 'Cookie'` | **OPEN** | 11 literal hits across the 4 handlers. |
| F13 | MEDIUM | Snapshot inconsistency (Delete vs Update vs Restore) | **PARTIAL** | Update + Delete now ship typed `CookieChangeSet`. Restore ships no snapshot; Create's payload is just (name, price, stock). |
| F14 | LOW | Per-handler log channel docblock vs DI | **OPEN** | Docblocks claim channel binding; handlers accept generic `LoggerInterface`. |
| F15 | LOW | RestoreCookieEvent timestamp inconsistency | **CLOSED** | Restore's event is constructed by `Cookie::restore()` via `buildLifecycleEvent`. Handler no longer stamps `restoredAt`. |
| F16 | INFO | Dead `method_exists` in `CommandBus::dispatch` | **CLOSED** | Second check is gone. |

**Counts:** CLOSED 4 (F5, F10, F15, F16) · PARTIAL 3 (F1, F2, F13) · OPEN 9 (F3, F4, F6, F7, F8, F9, F11, F12, F14) · REGRESSED 0.

## New issues

### N1 — HIGH — Dead infrastructure: AbstractCommandHandler exists but no handler extends it
- **Location:** `app/Domain/Shared/Bus/AbstractCommandHandler.php` (246 lines, fully documented, with a passing test) vs the 4 Cookie handlers + 12 User/Auth handlers duplicating the boilerplate.
- **Observation:** PR #34/#36 created the abstract base with explicit comments claiming "closes 03/F3 shape, 03/F6 shape, 14/F1", but no production handler extends it. Only `tests/Unit/Domain/Shared/Bus/AbstractCommandHandlerTest.php`'s `FakeCommandHandler` does.
- **Suggested fix:** Either migrate the 16 handlers to extend the base (real E08), or delete the base. Half-built infrastructure is worse than no infrastructure.

### N2 — HIGH — Custom PHPStan rules live in `temp/` sandbox, not in production config
- **Location:** `temp/cqrstemplate-agent-sandbox/tools/PHPStan/Rules/HandlerImplementsInterfaceRule.php` (+ 2 sibling files) vs `phpstan.neon` (no `services:` or `rules:` block referencing them).
- **Observation:** PR #37 (E05.5) was advertised as adding PHPStan custom rules. The rule files exist but are inside `temp/cqrstemplate-agent-sandbox/`, which is gitignored. The live `phpstan.neon` includes only the standard `phpstan-strict-rules` and `phpstan-phpunit`.
- **Suggested fix:** Move the rules into `tools/PHPStan/Rules/` (in-repo) and add `services:` + `rules:` blocks to `phpstan.neon`.

### N3 — MEDIUM — Repository drains events for `save()` but not `delete()`/`restore()`
- **Location:** `CookieRepository.php:124` (drain inside save) vs `:295-319` (delete — no drain) and `:327-352` (restore — no drain).
- **Observation:** The pattern asymmetry is the deeper cause of F1's "three patterns" mess.
- **Suggested fix:** Pick one. Either all three repo methods drain (and handlers stop), or all three handlers drain and the repo stops.

### N4 — LOW — `CookieRepository::dispatchPendingEvents` dispatches inside the transaction
- **Location:** `CookieRepository.php:187-189`.
- **Suggested fix:** Document the transactional model explicitly, then either route every event through the outbox (with a single post-commit relay) or formalise the current "sync-listeners-inside, outbox-relays-after-commit" split.

## Verdict shift

**Was:** NOT-READY (Round 3 / 16 findings, F1+F2 CRITICAL).
**Now:** **STILL-NOT-READY.** Only 4 of 16 findings fully closed; both CRITICALs are PARTIAL with the relevant entity-side improvements done but the handler-side parity work explicitly deferred (the deferral is literally written in the handler docblocks). Plus 2 new HIGH-severity issues caused by the half-finished E05/E08/E05.5 effort: dead abstract bases + sandboxed PHPStan rules.

## Top 3 still-open items

1. **Actually migrate the 4 Cookie handlers (and the other 12 across User/Auth) to `extends AbstractCommandHandler`.** This single change closes F3, F4, F6, F11, F12, and N1 in one stroke.
2. **Finish the RestoreCookieHandler/RestoreCookieCommand parity work that PR #36 promised.** Rename `$cookieId` → `$id`; rewrite to mirror Delete's shape; remove the raw `\RuntimeException`; switch log keys to camelCase. Closes F2 and the residue of F7.
3. **Land the PHPStan custom rules in the live config.** Move from `temp/cqrstemplate-agent-sandbox/` into `tools/PHPStan/Rules/` and wire them in `phpstan.neon`. Closes N2.

---

**Severity counts:** CLOSED 4 · PARTIAL 3 · OPEN 9 · REGRESSED 0 · NEW HIGH 2 · NEW MEDIUM 1 · NEW LOW 1.
**Biggest residual:** N1 — `AbstractCommandHandler` was built but ZERO production handler extends it on the audited branch.
