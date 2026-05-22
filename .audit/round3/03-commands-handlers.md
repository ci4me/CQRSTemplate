# 03 — Commands & Write Handlers

**Slice:** Create/Update/Delete/Restore command + handler pairs
**Reviewer:** cqrs-specialist
**Date:** 2026-05-22
**Source files reviewed:** 9 files (4 command + 4 handler + CommandBus, plus middleware context)

## TL;DR

Significant progress since Round-1 (Actor now on all four commands; `expectedVersion` added to `UpdateCookieCommand`; outer `LoggingMiddleware` + `TransactionMiddleware` + `AuditMiddleware` pipeline in place). However, **the four handlers still ship three template-multiplying defects**: (1) lifecycle events are still hand-built and dispatched directly by handlers instead of being raised by the aggregate and drained by the repository — except `UpdateCookieHandler` which now does drain `pullEvents()`, creating a *third* dispatch pattern inside the same domain; (2) `handle()` methods remain 70+ lines, violating the project's 20-line ceiling; (3) `RestoreCookieHandler` is still an outlier — no `try/catch`, no `duration_ms`, throws `\RuntimeException`, and uses different field names (`cookie_id` vs `cookieId`, `restoredBy->id` exposed). The string-match `determineErrorCode` brittle resolver is unchanged. `CommandBus` is duck-typed and never enforces `CommandHandlerInterface` despite the interface existing.

## Verdict
**NOT-READY** — cloning will propagate three pattern inconsistencies and one CRITICAL event-dispatch fragmentation to every future ERP entity.

## Findings

### F1 — CRITICAL — Three competing event-dispatch patterns inside one domain
- **Location:** `CreateCookieHandler.php:104-110`, `UpdateCookieHandler.php:126-128`, `DeleteCookieHandler.php:91-96`, `RestoreCookieHandler.php:71-77`
- **Observation:** Four handlers, three patterns:
  - Create: handler instantiates `CookieCreatedEvent` and dispatches directly.
  - Update: handler calls `$cookie->update(...)` which raises internally, then handler drains `foreach ($cookie->pullEvents() as $event)` and dispatches.
  - Delete: handler instantiates `CookieDeletedEvent` (with snapshot) and dispatches directly.
  - Restore: handler instantiates `CookieRestoredEvent` and dispatches directly.
  Comment at `UpdateCookieHandler.php:122-125` says "the repository ALSO drains, but a mock repo in tests won't" — confirming that in production this is a double-dispatch unless tests are using mocks. Either the repository drains (then handler should not) or the handler drains (then repository should not).
- **Why this is a template defect:** A `sed s/Cookie/Foo/g` cloner inherits three competing models in the same package. The next domain author will pick whichever they saw last. Outbox/async work will have to handle every shape forever, and the double-dispatch in Update is a latent duplicate-event bug the moment the test mocks are swapped for the real repository.
- **Suggested fix:** Pick one. Recommended: move `CookieCreatedEvent`, `CookieDeletedEvent`, `CookieRestoredEvent` into the entity (`Cookie::create()` raises Created after `assignId()`, `Cookie::softDelete()` raises Deleted, `Cookie::restore()` raises Restored). Handlers drain via `$cookie->pullEvents()` and dispatch. Repository stops draining. Document the contract in `AggregateRoot`. Until then, at least delete one of the two drain points in Update.

### F2 — CRITICAL — `RestoreCookieHandler` violates every convention the other three honour
- **Location:** `RestoreCookieHandler.php:35-78`
- **Observation:** Compared to the other three handlers:
  - No `$startTime`; no `duration_ms` in success log.
  - No `try/catch` — handler emits zero error log when `findByIdWithTrashed` throws or repo returns false (only one error log inside the `if (!$restored)` block).
  - Throws raw `\RuntimeException` at line 59, breaking the "all command failures are `DomainException`" rule used by the other three.
  - Uses snake_case log keys (`cookie_id`, `restored_by`) while the other three use camelCase (`cookieId`).
  - Leaks the actor id (`$command->restoredBy->id`) into log payload directly — the other three log the actor only via `AuditMiddleware`.
  - "Cookie is not deleted" misuses `ErrorCodes::COOKIE_NOT_FOUND` (300-range business-rule violation reusing a 200-range code).
  - Command field is `cookieId` (line 21) — the other three commands use `id`.
- **Why this is a template defect:** Cookie is THE reference template. A cloner copying RestoreCookieHandler gets a handler that looks nothing like its three siblings, and the controller layer's exception-to-HTTP mapper will not have a case for `\RuntimeException`. Multiplied per future domain.
- **Suggested fix:** Rewrite RestoreCookieHandler to mirror DeleteCookieHandler structure exactly: start-time, try/catch wrapping the entire flow, structured success + failure logs, `DomainException::businessRuleViolation` with a real restore-failed code, and log-key camelCase. Rename `RestoreCookieCommand::$cookieId` → `$id` to match the other three.

### F3 — HIGH — `handle()` methods still 70+ lines; violates project 20-line ceiling
- **Location:** `CreateCookieHandler.php:65-139` (75 lines), `UpdateCookieHandler.php:56-149` (94 lines), `DeleteCookieHandler.php:50-121` (72 lines)
- **Observation:** CLAUDE.md mandates "Methods ≤ 20 lines". The handlers are ~4× over. Round-1 02 flagged this; nothing changed. The bulk is logging boilerplate (start-info, success-info, failure-error) that could collapse into a `HandlerLoggingTrait` or a `LoggedHandler` decorator.
- **Why this is a template defect:** The template enshrines the violation. Every cloned domain's `handle()` will be 70+ lines on day one. `phpcs`/`clean-code-specialist` should already be rejecting this; either the rule is not enforced or the handlers are exempted somewhere.
- **Suggested fix:** Extract `logStart()`, `logSuccess(int $durationMs)`, `logFailure(\Throwable $e, int $durationMs)`, and let `handle()` be the orchestration shell. Or push the timing/error-code logging entirely into a `MetricsMiddleware` so the handler emits only domain-specific events.

### F4 — HIGH — `CreateCookieHandler::determineErrorCode` still uses `str_contains` on exception messages
- **Location:** `CreateCookieHandler.php:155-161`
- **Observation:** Round-1 flagged this; unchanged. `match (true) { str_contains($e->getMessage(), 'name must be unique') => ... }` ties error codes to English exception strings. Any wording polish, localisation, or change to `DomainException::businessRuleViolation`'s message format silently re-maps to `COOKIE_REPOSITORY_SAVE_FAILED`.
- **Why this is a template defect:** Every cloned `Create*Handler` gets a brittle resolver that will silently degrade. The string keys (`'name'`, `'price'`, `'stock'`) are even domain-specific — a cloner who forgets to rename them will report bogus codes for `Customer.email` mismatches.
- **Suggested fix:** Pass `ErrorCodes::*` constants at throw sites only (`DomainException::businessRuleViolation(..., ErrorCodes::X)`) and have `determineErrorCode` rely solely on `$e->getErrorCode()`. Delete the `match (true) { str_contains(...) }` block. The `UpdateCookieHandler::determineErrorCode` already does the right thing (no string match) — copy that pattern.

### F5 — HIGH — `CommandBus` is duck-typed; `CommandHandlerInterface` is defined but never required
- **Location:** `CommandBus.php:83-87` (`method_exists($handler, 'handle')`), `CommandHandlerInterface.php` (exists but unused)
- **Observation:** `CommandHandlerInterface` was added to document the contract and let PHPStan reason about handlers, yet `CommandBus::register()` accepts `object $handler` and only checks `method_exists`. None of the four Cookie handlers `implements CommandHandlerInterface`. There is no PHPStan rule that requires handlers in `Commands/*/` to implement it.
- **Why this is a template defect:** A cloner writing `final readonly class FooHandler { public function handle(FooCommand $cmd): int }` with a typo (`handel`) or wrong arg type will register cleanly and only fail at dispatch. The interface is documentation theatre.
- **Suggested fix:** Either make handlers `implements CommandHandlerInterface` (and tighten `CommandBus::register()` to require it) or delete the interface. The deeper fix is a PHPStan rule + namespace convention; see `phpstan-specialist` agent.

### F6 — HIGH — `UpdateCookieHandler` catch block omits `exceptionClass` and `duration_ms`
- **Location:** `UpdateCookieHandler.php:138-148`
- **Observation:** Three handlers, three failure-log shapes. Create logs `domain, command, exception, exceptionClass, name, error_code, duration_ms`. Delete logs `domain, command, error_code, exception, exceptionClass, cookieId`. Update logs `domain, command, error_code, exception, cookieId` — no `exceptionClass`, no `duration_ms`. Restore logs almost nothing (see F2).
- **Why this is a template defect:** Per-domain dashboards have to handle 4+ schemas. Cloners copy the inconsistency forward.
- **Suggested fix:** Standardise the failure-log shape in a `HandlerLoggingTrait` or in the proposed `MetricsMiddleware` (see F3 fix).

### F7 — HIGH — Command shape drift across the four DTOs
- **Location:** All four command files
- **Observation:**
  - `CreateCookieCommand`: `$createdBy` (after the business fields, before the optional `$isActive`).
  - `UpdateCookieCommand`: `$updatedBy` mid-list, then `$expectedVersion`.
  - `DeleteCookieCommand`: `$deletedBy` second.
  - `RestoreCookieCommand`: `$restoredBy` second; ID field named `$cookieId` not `$id`.
  Actor field naming follows verb tense (`createdBy`/`updatedBy`/`deletedBy`/`restoredBy`) — fine for domain events, but for command DTOs a single `$actor` would let middleware introspect uniformly. The ID-field rename (`id` vs `cookieId`) is a real surprise: `$command->id` works on three handlers, breaks on Restore.
- **Why this is a template defect:** A `sed`-cloner who renames Cookie→Foo and tries to write a unified controller mapper will hit the `cookieId`/`id` divergence. Future cross-cutting middleware (e.g. tenant-scope) cannot reflect on a uniform field name.
- **Suggested fix:** Standardise: all commands carry `public Actor $actor` and `public int $id` (where applicable). Keep the verb-tense names on the *events*, where the audit semantic matters.

### F8 — HIGH — TransactionMiddleware contract is silent on read-then-write critical sections
- **Location:** `CreateCookieHandler.php:84` (`existsByName`) → `:102` (`save`); `UpdateCookieHandler.php:100` (`existsByNameExcludingId`) → `:120` (`save`)
- **Observation:** Both handlers do a read (existence check) and then a write inside the same handler. Under READ COMMITTED (MySQL default), two concurrent Create commands for the same name can both pass `existsByName`. `TransactionMiddleware.php:74` calls `$db->transBegin()` with no isolation hint. Practically the DB unique index saves us, but the handler-level check is misleading and the comment in TransactionMiddleware never warns about this. The Round-1 flag is unaddressed.
- **Why this is a template defect:** Every cloned Create-handler inherits a TOCTOU pattern that "works" only because the DB has a unique index. Domains that forget the index (Customer, Order line items, etc.) will silently corrupt.
- **Suggested fix:** Either (a) document at the top of every Create handler "name uniqueness is enforced by the DB index; the existsByName check is best-effort UX"; or (b) drop the handler-side check entirely and translate the index-violation error in the repository into a `DomainException::businessRuleViolation`; or (c) set transaction isolation to SERIALIZABLE per-handler when the read-then-write pattern is genuinely needed.

### F9 — MEDIUM — `expectedVersion` is optional (`?int = null`) — opt-in concurrency control
- **Location:** `UpdateCookieCommand.php:40`, `UpdateCookieHandler.php:84-92`
- **Observation:** Round-1's CRITICAL is partially addressed: the field exists. But it defaults to null, and the handler comment at lines 78-83 documents that null callers skip the pre-flight check and "rely on the repository's row-level guard". If the repository's `WHERE version = ?` is built from the entity's freshly-loaded version (which equals itself), there is no race protection at all for null-version callers. The path of least resistance for a controller is to omit it.
- **Why this is a template defect:** Cloned domains will copy the "optional, default null" idiom, and most callers will skip it. Concurrency safety becomes an opt-in feature nobody opts into.
- **Suggested fix:** Make `expectedVersion` required (no default). Force every dispatcher (HTTP controller, CLI, integration test) to pass a real value, even if it has to load the entity first to get one. Document loudly that null means "I accept silent overwrite".

### F10 — MEDIUM — `RestoreCookieHandler` idempotency: rejects already-active with NOT_FOUND code
- **Location:** `RestoreCookieHandler.php:43-49`
- **Observation:** When `!$cookie->isDeleted()`, throws `DomainException::businessRuleViolation('Cookie is not deleted; nothing to restore.', ..., ErrorCodes::COOKIE_NOT_FOUND)`. The error code is wrong (it's not a 404, the entity was found). The semantic question: should restoring an already-active cookie be idempotent (return success, no event)? Most RESTful semantics for `POST /restore` on an active entity prefer 204/idempotent. As-is, every cloned domain will reject double-restore with a misleading error code.
- **Why this is a template defect:** Operator-driven workflows (a button in admin UI) will surface a confusing "not found" error on a clearly-existing record.
- **Suggested fix:** Define semantics: either (a) idempotent — log and return without event; or (b) reject with a new `COOKIE_STATE_NOT_DELETED` code in the 400-range. Pick one, document, propagate to every future domain.

### F11 — MEDIUM — `DeleteCookieHandler` uses `hrtime`, others use `microtime` — three timer sources now
- **Location:** `DeleteCookieHandler.php:52, 98` vs `CreateCookieHandler.php:67`, `UpdateCookieHandler.php:58`, `RestoreCookieHandler.php` (none)
- **Observation:** Round-1 flagged this; unchanged.
- **Suggested fix:** Use `microtime(true)` everywhere or centralise to a `Clock`/`Timer` service injected as a port.

### F12 — MEDIUM — Hard-coded domain string `'Cookie'` in every log payload
- **Location:** `CreateCookieHandler.php:70,115,128`; `UpdateCookieHandler.php:61,133,140`; `DeleteCookieHandler.php:55,82,102,109`; `RestoreCookieHandler.php:55,65`
- **Observation:** `'domain' => 'Cookie'` is hand-written in 12 places across 4 files. A `sed s/Cookie/Foo/g` cloner WILL get this right (because the literal happens to match the class name), but the field is duplicate of the class FQCN that `LoggingMiddleware` already records. Pure ceremony, and one missed replace would log the wrong domain forever.
- **Suggested fix:** Move to the middleware or compute from `static::class` once at construct. Or delete (the FQCN already conveys the domain).

### F13 — MEDIUM — `DeleteCookieHandler` builds a manual snapshot — Update/Restore do not
- **Location:** `DeleteCookieHandler.php:71-79`
- **Observation:** Delete carefully snapshots `['id', 'name', 'description', 'price', 'stock', 'is_active']` for the event payload. Update raises events via the entity (which has a before/after diff per the comment). Restore captures nothing. The Round-2 audits emphasise that audit trails matter; the inconsistency means delete is recoverable from the event stream, restore is not.
- **Why this is a template defect:** Cloners face three patterns for "what does the event carry?" Pick one.
- **Suggested fix:** Decide a snapshot policy (full state on Created/Deleted, diff on Updated, nothing on Restored is fine because restore is reversal). Document in `AggregateRoot`.

### F14 — LOW — Per-handler log channel mentioned in docblock but never actually used
- **Location:** `CreateCookieHandler.php:49` (`channel: cookie.command.create`), `UpdateCookieHandler.php:41`, `DeleteCookieHandler.php:35`
- **Observation:** The docblocks claim a per-command channel but DI injects a generic `LoggerInterface` (no channel binding visible). Either the provider wires the right channel and the docblock is correct, or it's docblock theatre — a cloner cannot tell.
- **Suggested fix:** Verify provider registration (out of scope here — agent 08) or change docblock to "channel: assigned by provider".

### F15 — LOW — `RestoreCookieEvent` uses string `restoredAt` while other events use no timestamp
- **Location:** `RestoreCookieHandler.php:75` (`(new \DateTimeImmutable())->format('c')`)
- **Observation:** Only Restore puts a timestamp in the event. Other events rely on the dispatcher/listener to stamp `occurred_at`. Out of scope per slice boundaries (event-payload audit is 05) but the handler is generating it, so flagging here.
- **Suggested fix:** Decide policy in the AggregateRoot and remove from handler.

### F16 — INFO — `CommandHandlerInterface` and `CommandBus` interaction note
- **Location:** `CommandBus.php:111-115`
- **Observation:** The second `method_exists` check inside `dispatch()` is dead code — `register()` already guarantees the method exists. Cosmetic.
- **Suggested fix:** Drop the second check or convert to an `assert()`.

## What is correct / praiseworthy

- All four commands are `final readonly`, no setters, named constructor only. Immutability is solid.
- All four commands now carry an Actor (Round-1's CRITICAL fixed for Create/Update/Delete).
- `expectedVersion` was added to `UpdateCookieCommand` (Round-1 CRITICAL partially fixed; see F9 for residue).
- The `LoggingMiddleware` → `TransactionMiddleware` → `AuditMiddleware` → handler pipeline is well-architected: outermost log captures transaction outcome; transaction wraps event dispatch so synchronous-listener failures roll back the write; audit insert uses a clever transException-disable hack so audit failure cannot poison the business commit. The comments at `TransactionMiddleware.php:60-72` and `AuditMiddleware.php:111-122` are exemplary.
- Handler return types are consistent: Create returns `int` (id), Update/Delete/Restore return `void`. No leakage of `Cookie` entity from the handler API.
- Error codes are domain-scoped constants (`Cookie\ErrorCodes`) rather than magic numbers in throw sites.
- `CommandHandlerInterface` exists and is documented (even if unused — see F5).
- `UpdateCookieHandler::determineErrorCode` correctly avoids string matching and relies on `$e->getErrorCode()`.

## Top 3 fixes before cloning

1. **Unify event dispatch (F1).** Move all four lifecycle events into the entity (`Cookie::create/update/softDelete/restore` each `raiseEvent(...)`), drain via `pullEvents()` at one well-defined point (handler OR repository, not both), and delete every direct `eventDispatcher->dispatch(new Cookie*Event(...))` call from the four handlers. This eliminates the three-pattern split and makes the outbox path the single source of truth.
2. **Bring RestoreCookieHandler to parity (F2).** Rewrite using DeleteCookieHandler as the template: start-time, full try/catch with structured failure log, `DomainException` not `\RuntimeException`, camelCase log keys, rename `RestoreCookieCommand::$cookieId` → `$id`, and assign a proper restore-failed error code in the 400-range. Until this is done, the "Restore is special" anomaly will be cloned forever.
3. **Shrink `handle()` to ≤ 20 lines (F3) and delete the string-match resolver (F4).** Extract logging into a `HandlerLoggingTrait` or push it into a `MetricsMiddleware`. Remove `CreateCookieHandler::determineErrorCode`'s `match (true) { str_contains(...) }` block — rely on `$e->getErrorCode()` like UpdateCookieHandler does. Both fixes prevent the template from enshrining its own rule violation across every future domain.

---

**Severity counts:** CRITICAL: 2 · HIGH: 6 · MEDIUM: 5 · LOW: 2 · INFO: 1 (16 findings)
**Top finding:** F1 — three competing event-dispatch patterns inside one domain (Create instantiates+dispatches; Update drains entity events; Delete/Restore instantiate+dispatch). Cloning fragments the dispatch model permanently and the Update path double-dispatches under the real repository.
