# 10 — Bus + Middleware pipeline

## Files audited

- app/Infrastructure/Bus/CommandBus.php
- app/Infrastructure/Bus/QueryBus.php
- app/Infrastructure/Bus/EventDispatcher.php
- app/Infrastructure/Bus/CommandMiddlewareInterface.php
- app/Infrastructure/Bus/Middleware/LoggingMiddleware.php
- app/Infrastructure/Bus/Middleware/TransactionMiddleware.php
- app/Infrastructure/Bus/Middleware/AuditMiddleware.php
- app/Database/Migrations/2026-05-19-200000_CreateAuditLogTable.php
- app/Config/Services.php (commandBus + actorResolver wiring)
- app/Infrastructure/Logging/RedactingProcessor.php (cross-reference for sensitive key list)
- vendor/codeigniter4/framework/system/Database/BaseConnection.php (cross-reference for trans* semantics)

## Findings

### CRITICAL

**C1. Audit-insert failure aborts the business write (contradicts documented contract).**
AuditMiddleware.php:92-113 catches its own insert failure and logs at error level, so it never re-throws — the docblock at line 33-36 promises this. BUT: when CI4's query builder fails the insert, `BaseConnection::handleTransStatus()` (BaseConnection.php:910-915) flips `transStatus` to `false` for any depth>0. Audit runs INSIDE TransactionMiddleware, so after the handler returns and control unwinds back into TransactionMiddleware.php:59, `transStatus()` is `false` → the entire transaction is rolled back and a `RuntimeException` is thrown (TransactionMiddleware.php:67-69). Net effect: a flaky `audit_log` table takes down every command. Fix options: (a) call `$db->resetTransStatus()` after a caught audit write failure, (b) write audit on a separate connection group, (c) move audit OUTSIDE the transaction and accept the orphaned-write risk.

**C2. EventDispatcher swallows listener exceptions, so transactional event guarantees are a lie.**
EventDispatcher.php:91-103 catches `\Throwable` and continues. TransactionMiddleware.php:21-24 docs ("if any synchronous listener throws, the entire write is rolled back") are false: the throw never escapes the dispatcher, so the transaction commits. Either rethrow in dispatcher (breaks "events shouldn't fail") or remove the misleading promise from TransactionMiddleware docs. Pick one; current state is the worst combination.

### HIGH

**H1. AuditMiddleware sensitive-key list diverged from RedactingProcessor.**
AuditMiddleware.php:162-166 hard-codes `['password','token','jwt','authorization','api_key','secret','private_key','credit_card','card_number','cvv','plaintext']`. RedactingProcessor.php:31-50 has a *superset*: `password_hash`, `password_confirm`, `new_password`, `old_password`, `current_password`, `refresh_token`, `access_token` are MISSING from the audit list. Comment at AuditMiddleware.php:163 explicitly states "MUST stay aligned with RedactingProcessor." The digest of `ChangePasswordCommand{old_password, new_password}` will leak both fields into the digest input — defeating the stated purpose of "the digest reveals nothing." Extract one shared `SensitiveKeys::LIST` constant.

**H2. Audit and Transaction middlewares may not share the connection.**
TransactionMiddleware.php:42 and AuditMiddleware.php:90 each call `Database::connect()` with no arg if `$db` is null. Services.php:106-110 constructs both without an explicit `$db`. Today CI4 caches by group so it works, but the contract is implicit. Inject the same `BaseConnection` explicitly into both, or wire via a shared service. Otherwise a future "use the analytics DB for audits" tweak silently breaks atomicity.

**H3. Pipeline composition relies on undocumented array_reduce semantics.**
CommandBus.php:96-105 is correct (first-pushed = outermost) but the inversion happens via `array_reverse` + `array_reduce` with a `static fn`. With 3 middlewares this works; add a 4th and reviewers will get this wrong half the time. Add a unit test that asserts a deterministic order trace through `Logging→Transaction→Audit→handler` and back out.

### MEDIUM

**M1. AuditMiddleware::extractPublicState only reads public properties.**
AuditMiddleware.php:145 uses `ReflectionProperty::IS_PUBLIC`. The project convention is `final readonly` commands with promoted public properties, so it usually works — but any command that uses a private/protected promoted property (or wraps state in a private VO assigned in the constructor) silently produces a digest of `{}`. Tamper-detection then degrades to "did the command class change." Either iterate ALL properties (with `setAccessible`) or fail loudly on empty payloads.

**M2. AuditMiddleware::normaliseForJson loses information for VOs and enums.**
AuditMiddleware.php:209-218 handles only: `property_exists($value, 'id')` → `$value->id`, then `__toString` if defined, otherwise the FQCN. Common project shapes that get serialized as the bare class name (so digests collapse): `CookieName`/`CookiePrice` (private `$value`, accessor `getValue()`, no `__toString`), backed enums (no `id`, no `__toString` by default), `DateTimeImmutable` (no `id`). Result: two commands differing only in those fields produce IDENTICAL digests. Use a proper normaliser: check `instanceof UnitEnum` → `->name`/`->value`, `instanceof \Stringable`, then a `getValue()`/`toArray()` convention.

**M3. transStatus is not reset before transBegin.**
TransactionMiddleware.php:44 calls `transBegin()` directly. CI4 does NOT reset `transStatus` inside `transBegin` (BaseConnection.php:826-855 — only resets `transFailure`). If two commands run in the same request and the first command's middleware bypassed the rollback path (e.g. a future code path that catches and recovers), the second command starts with stale `transStatus=false` and immediately rolls back. Defensive fix: `$db->resetTransStatus()` before `transBegin()`, or rely on a fresh connection per command.

**M4. TransactionMiddleware silently nests when caller is already in a transaction.**
TransactionMiddleware.php:44 doesn't check `transDepth`. CI4 nests (depth++) and only the outermost begin/commit issues real SQL — there are no savepoints. If a controller, a CLI command, or a test fixture has started a transaction, our "commit" is a no-op and an outer rollback discards our work without notice. Document the assumption ("caller must not be in a transaction") or assert `transDepth === 0` on entry.

### LOW

**L1. AuditMiddleware writes the actor resolved BEFORE the handler.**
AuditMiddleware.php:53 captures the actor at entry. Commands that change the session (login, impersonate) will record the pre-handler actor, which is usually the desired audit semantic — but it's not documented. Add a one-line comment.

**L2. EventDispatcher dispatch order is registration order; not numbered.**
EventDispatcher.php:90 iterates `$this->listeners[$eventClass]` in insertion order. Fine for now; if priorities are ever needed, callers will have to refactor every `subscribe()` site.

**L3. Pipeline order at call site under-documented for Audit's "inside transaction" intent.**
Services.php:100-104 explains LoggingMiddleware → TransactionMiddleware reasoning but doesn't spell out that pushing AuditMiddleware AFTER TransactionMiddleware is what makes it commit atomically. Add: "Order matters: Audit is pushed AFTER Transaction so it runs INSIDE the transaction (commits/rolls back atomically with the handler)."

**L4. Duplicate handler registration: covered.**
CommandBus.php:65-69 and QueryBus.php:58-62 throw `RuntimeException` on duplicate registration. No silent overwrite path.

**L5. LoggingMiddleware payload safety: confirmed.**
LoggingMiddleware.php:34-59 never references the command's properties — only `$command::class`, duration, exception class/message, correlation id. Duration is computed with `microtime(true)*1000` at start and end of the SAME middleware frame, so it includes downstream middlewares (Transaction + Audit) — this is correct and called out in the audit context.

**L6. QueryBus is read-only by design.**
QueryBus.php has no middleware, no DB calls of its own, no mutations beyond `$this->handlers[]` (registration). It cannot mutate domain state itself; mutation can only come from a misbehaving query handler — out of the bus's contract. Lack of logging/audit middleware on QueryBus is acceptable (queries are high-volume, not auditable individually) but worth noting as a deliberate decision in the QueryBus docblock.

**L7. EventDispatcher catches \Error subclasses too.**
EventDispatcher.php:93 catches `\Throwable`, which on PHP 8.4 covers `\Error` (TypeError, AssertionError, ParseError…). Memory-exhaustion / OOM is not catchable and will still bubble — good. Suppressing `\Error` does mask programming bugs, but they ARE logged at error level — acceptable.

## Pipeline correctness analysis

**Composition (CommandBus.php:96-105).** Pipeline build: `$core = fn → handler->handle`. `array_reduce(array_reverse($middleware), wrap, $core)` produces nested closures so that the FIRST element of `$middleware` is the OUTERMOST. With Services.php:105-110 pushing in order `Logging, Transaction, Audit`, runtime order is:

```
Logging.before → Transaction.before → Audit.before → handler → Audit.after → Transaction.after → Logging.after
```

Reverse symmetry on exception is preserved because each middleware uses try/catch + rethrow. Correct under N middlewares.

**Trans-status flow (TransactionMiddleware.php:44-74).** transBegin → next($command) → on `\Throwable` rollback + log + rethrow; on success check transStatus, rollback + throw if false, else commit. The transStatus check after a successful `next()` is correct — CI4 sets transStatus=false from inside query builder on failed queries that did not themselves throw. Two gaps:
1. Audit's caught insert failure ALSO trips transStatus (CRITICAL C1).
2. No reset of transStatus before begin (MEDIUM M3).
3. Silent nesting when caller already in a transaction (MEDIUM M4).

**Audit placement.** Pushed last in Services.php:107 → innermost middleware → runs INSIDE the transaction. Correct intent. Side effect: audit insert participates in the same trans depth → its failure can poison the unit of work (C1).

**EventDispatcher / transaction interplay.** EventDispatcher is invoked from inside handlers (e.g. CreateCookieHandler). Handlers run inside TransactionMiddleware. EventDispatcher.php:91-103 swallows listener exceptions, so the transaction NEVER sees them → cannot roll back on listener failure. Contradicts TransactionMiddleware.php:21-24 (C2).

**QueryBus.** No middleware, no transaction, no logging. Cannot itself mutate. Acceptable for CQRS but lacks a docblock note about the deliberate omission.

**Duplicate handler registration.** Both buses guard via `isset($this->handlers[$class])` and throw `RuntimeException`. No silent overwrite path. EventDispatcher.php:64-71 is intentionally additive (multiple listeners allowed).

**Documentation at call site (Services.php:100-104).** Mentions order rationale but understates the audit-inside-transaction invariant (L3).

## Verdict

**NOT READY for production.** Two critical issues:
- C1: audit insert failure cascades into a business-write rollback — the opposite of the documented contract.
- C2: TransactionMiddleware and EventDispatcher disagree about whether listener exceptions roll back; the misleading docs will mislead callers.

Also: H1 (digest leaks new_password / refresh_token because the sensitive list diverged), H2 (implicit shared connection), H3 (no test pinning pipeline order).

Recommended fixes, ranked:
1. Reset transStatus after a caught audit-write failure, or write audit on a sibling connection (C1).
2. Decide event-on-transaction semantics; align docs and code (C2).
3. Extract a single `SensitiveKeys::LIST` shared by AuditMiddleware + RedactingProcessor (H1).
4. Inject the same `BaseConnection` into both transaction-aware middlewares from Services.php (H2).
5. Add a pipeline-order test using a recording middleware to lock in Logging→Transaction→Audit→handler (H3).
6. Improve `normaliseForJson` to handle enums, `Stringable`, and a `getValue()` convention (M2).
7. Iterate all command properties (not just public) when computing the digest, or assert non-empty (M1).
