# r11 — Observability review (correlation, audit, redaction)

Scope: re-verify findings from round1 reports 13 (logging) and 10 (bus middleware),
focusing on correlation propagation, audit-row integrity, and PII redaction.
Spot-checked: `LoggerFactory`, `CorrelationIdService`, `CorrelationIdMiddleware`,
`RedactingProcessor`, `DomainLogger`, `LoggingMiddleware`, `AuditMiddleware`,
`Services::commandBus`, `Config\Filters::$globals`, audit_log migration, all
production call sites of `CorrelationIdService::*`.

---

## Verified observability gaps

### V1. CorrelationIdService leaks across requests in long-running workers — CONFIRMED CRITICAL
`CorrelationIdService::$correlationId` (`app/Infrastructure/Logging/CorrelationIdService.php:33`)
is `private static ?string` and only nulled by `clear()`. Production callers of
`clear()`: zero (grep across `app/`). `CorrelationIdMiddleware::after`
(`:55-60`) sets the response header but does NOT call `clear()`.

Per-context behaviour:
- **PHP-FPM** (one request per process before reset): safe. The persisted id
  is overwritten on the next request by `before()` whenever an inbound header
  arrives, and on absent inbound headers the previous id remains — same
  process, same id leaks to the next request. **Not safe even on FPM**: a
  request with no `X-Correlation-Id` header reuses the id from the
  previously-handled request on the same worker. Most FPM deployments
  retire workers via `pm.max_requests`, so the leak window is bounded but
  non-zero.
- **Roadrunner / Swoole / Octane**: every job inherits the first job's id.
  All logs across all jobs collapse to one trace.
- **Queue workers / CLI** (`spark events:relay`, `spark jobs:work`): no
  middleware ever runs. `JobWorker::processRow` (`:101-103`) and
  `EventOutboxRelay` (`:112`) `set()` the stored correlation per row but
  never restore the prior value or `clear()` after. The leak is partially
  mitigated row-by-row (each row overwrites), but logs emitted BETWEEN rows
  (e.g. the worker's own status logs) carry whichever id the last row set.

The audit's claim is correct. The single-line fix
(`CorrelationIdService::clear()` at end of `CorrelationIdMiddleware::after`
plus a `try/finally` wrapper restoring the prior value in JobWorker and
EventOutboxRelay) closes the leak.

### V2. DomainLogger uses one channel for all domains — CONFIRMED MEDIUM
`DomainLogger::getLogger()` (`:43-49`) hard-codes channel `'domain.validation'`.
The CQRS context processor in `LoggerFactory::createCqrsContextProcessor`
(`:121-156`) splits on `.` and picks `$parts[0]` as the `domain` value, then
matches `$parts[1]` against the literal strings `'command' | 'query' | 'event'`.

For channel `domain.validation`:
- `domain = "domain"` (literally the word) — useless for filtering
- `validation` is not `command|query|event` → match falls through, no
  command/query/event extra is set
- The actual domain (`Cookie`, `User`) lives only in the message context
  under key `domain`, set by the DomainLogger call sites. So logs are still
  queryable by `context.domain`, just not by Monolog `channel`.

Report 13's diagnosis is right. Two fixes: parameterise the channel
(`LoggerFactory::create("{$domain}.validation")`) and either drop the
static logger cache or key it by domain.

### V3. AuditMiddleware payload digest discards VO contents — CONFIRMED MEDIUM
`extractPublicState` (`:140-151`) iterates only `ReflectionProperty::IS_PUBLIC`.
Project convention is `final readonly class FooCommand` with public promoted
properties, so 100% of commands inspected in this codebase expose their fields
correctly. But `normaliseForJson` (`:195-221`) is fragile:

- `Actor` has public `id` → reduces to actor id. OK.
- `CookieName`, `CookiePrice`, User-`Email`, User-`UserName`, `Money`,
  `DocumentNumber`, `Permission`, `DateTimeValue` all implement
  `__toString` → handled via the `method_exists($value, '__toString')`
  branch. OK in the existing codebase.
- `Currency` (`final readonly class Currency`, props `iso/decimals/symbol`,
  no `__toString`) → falls through to `return $value::class`. Two commands
  differing only in `Currency::usd()` vs `Currency::brl()` produce
  IDENTICAL digests. Tamper-detection blind to currency.
- `\DateTimeImmutable` → no `id`, no `__toString` → also collapses to the
  class name. Two commands at different timestamps produce identical
  digests.
- Backed enums (PHP doesn't auto-stringify them) → collapse to class name.

The digest is also **not stable across PHP versions** for non-trivial
inputs: `json_encode` ordering is insertion-ordered for assoc arrays
(stable) and types are encoded deterministically across 8.x, BUT float
precision varies if `serialize_precision`/`precision` ini differ.
`CookiePrice::__toString` returns `toDecimalString()` (string), so floats
aren't a concern THERE; but any future VO that stringifies via
`(string)(float)$x` would be ini-dependent. The current corpus is safe;
the policy is fragile.

The real risk is enums and `DateTimeImmutable` collapsing — patch
`normaliseForJson` to:
- `instanceof \UnitEnum` → `$value->name` (or `->value` for backed)
- `instanceof \Stringable` → `(string) $value` (replaces `method_exists`)
- `instanceof \DateTimeInterface` → `$value->format(DATE_ATOM)`
- generic objects with public `id` → fallback
- otherwise fail loudly (don't silently digest `{}`)

### V4. AuditMiddleware insert failure rolls back business write — CONFIRMED CRITICAL (verified)
Confirmed against `BaseConnection::handleTransStatus()`
(`vendor/codeigniter4/.../BaseConnection.php`). `AuditMiddleware::writeRow`
(`:92-113`) swallows the write exception but the failed builder INSERT has
already flipped `transStatus`. `TransactionMiddleware` then sees
`transStatus()===false` post-handler and rolls back.

Net: a flaky `audit_log` table downs every command. Confirmed exactly as
report 10 C1 states.

### V5. Log levels are mostly disciplined, two outliers — CONFIRMED MEDIUM
Surveyed all production `logger->{warning,error}` and `DomainLogger::*` calls.

- ERROR for unrecoverable handler exceptions: correct everywhere
  (CreateCookieHandler, DeleteUserHandler, RegisterUserHandler, etc.).
- WARNING for user-side bad input: correct in `LoginUserHandler`
  ('Login failed - invalid credentials'), `RegisterUserHandler` ('email
  already exists'), `DeleteUserHandler` ('Self-deletion attempt blocked').
- **Outlier 1**: `DomainLogger::logBusinessRule` (`:74-80`) ALWAYS logs at
  ERROR. Business-rule violations (e.g. `User::changePassword` rejecting
  same-password) are caller-side input errors — WARNING is the correct
  level. `DomainLogger::logValidation` correctly uses WARNING. The
  inconsistency means User entity invariant violations spam ERROR-level
  alerting.
- **Outlier 2**: `ChangeUserPasswordHandler:63` 'User not found for
  password change' → ERROR. Lookup miss on user-supplied id is a 404
  condition, not a server error. Should be WARNING (or INFO if expected).
  Same pattern in `RefreshTokenHandler:68` ('User not found for refresh
  token'). Both poison ERROR-rate dashboards.

### V6. Audit table semantics — VERIFIED CORRECT BY DESIGN
- Every command writes a row (`writeRow` is called on both success and
  failure paths in `handle()` `:50-80`). Confirmed.
- Failed commands DO write a row with `status='failure'` and
  `error_class`/`error_message`. Confirmed.
- Queries NOT audited: `QueryBus` (`Services.php:124-130`) registers no
  middleware. Correct by design — queries are high-volume and read-only.
- Events NOT audited at bus level: `EventDispatcher` has no middleware
  pipeline. Events ARE persisted via outbox, which provides parallel
  durability. Acceptable.
- `actor_id=0` sentinel for system actor: matches `Actor::SYSTEM_ID = 0`.
- `tenant_id` column exists but is NULL by design until tenancy lands
  (comment at `:96`).

The audit_log design is sound. The blocker is V4 (cascade) and the actor
columns on domain entities being unused (cross-references report 10
finding 12 — out of scope for this review).

### V7. Handler stack / processor LIFO ordering — VERIFIED CORRECT
`LoggerFactory::create` (`:46-65`) pushes:
1. handler: RotatingFileHandler (single, synchronous)
2. processor: CQRS context (added first, runs LAST in LIFO)
3. processor: correlation id (runs second)
4. processor: RedactingProcessor (added last, runs FIRST in LIFO)

Order is correct and intentional: redaction runs before any other processor
can re-introduce sensitive values. Comment at `:59-61` is accurate.

### V8. Flush behaviour on fatal error — VERIFIED ACCEPTABLE
RotatingFileHandler writes synchronously per `handle()` call. No
`BufferHandler` / `FingersCrossedHandler` / `DeduplicationHandler` in the
stack. Fatal errors do not lose buffered records. No `register_shutdown_function`
hook is needed. (Tradeoff: synchronous disk I/O per log line — acceptable
for this scale.)

### V9. CSRF rejection logs have no correlation id — CONFIRMED (Filters order)
`app/Config/Filters.php:89-112` runs `csrf` BEFORE `correlation` in the
global `before` array. A CSRF rejection short-circuits the filter chain
before `CorrelationIdMiddleware::before` runs, so the rejection log/response
will lazily mint a one-off id via `CorrelationIdService::get()` rather than
adopt the client's `X-Correlation-Id`. Minor but real. Swap the order.

### V10. JobWorker/EventOutboxRelay restore-but-don't-clear — CONFIRMED LOW/MEDIUM
`JobWorker::processRow:103` and `EventOutboxRelay:112` call
`CorrelationIdService::set($originalCorrelation)` for each row but never
restore the prior value or `clear()` after the row finishes. Between rows,
the worker's own status logs carry the LAST row's id. The fix is
`try { set(); ...process... } finally { clear(); }` per row.

---

## False positives in round1

### F1. RedactingProcessor vs AuditMiddleware list "leaks new_password" — FALSE POSITIVE
Report 10 H1 and consolidated #10 claim AuditMiddleware's hardcoded list
omits `new_password`, `password_hash`, `refresh_token`, `access_token` and
that the digest input therefore leaks them.

Verified against the code:
- AuditMiddleware list: `['password','token','jwt','authorization','api_key','secret','private_key','credit_card','card_number','cvv','plaintext']`
- Matching is `str_contains(strtolower($key), $marker)` (`:170-173`).
- `strtolower('newPassword')='newpassword'`, `str_contains('newpassword','password')=true`. Matches.
- Same logic for `password_hash`, `old_password`, `current_password` — all caught by the `password` substring.
- `refresh_token`, `access_token` — caught by the `token` substring.

So the substring strategy means the two lists are FUNCTIONALLY EQUIVALENT
for everything either list names. RedactingProcessor's explicit
`new_password`/`access_token` entries are documentation/defence-in-depth,
not functional. The claim of digest leakage is wrong.

### F2. Shared CommandBus skips middleware — FALSE POSITIVE
Consolidated audit #15 claims `getSharedInstance` returns a bus with no
middleware. Verified against `vendor/codeigniter4/.../BaseService.php:238-255`:
`getSharedInstance` calls `AppServices::commandBus(false)` to construct the
shared instance, which is exactly the middleware-pushing branch. The bus IS
wired with middleware in shared mode. False positive.

(There's still a separate concern that test bootstrap could short-circuit
this via `static::$mocks`, but that's a test concern, not production.)

---

## Missed issues (new findings)

### N1. LoggingMiddleware doesn't honour the LIFO redaction guarantee
`LoggingMiddleware::handle` (`:34-59`) does NOT log the command payload —
it logs only the FQCN, duration, and exception class/message. Good
discipline. But: `$e->getMessage()` is logged verbatim. Domain exceptions
in this codebase routinely include attempted values
(e.g. `ValidationException::invalid` carries the offending value). If a
user passes `password=hunter2` and validation rejects it, the exception
message may leak the value. RedactingProcessor only walks
`context`/`extra` — it does NOT scan `$record->message`. Cross-reference
round1 report 13 "RedactingProcessor MEDIUM" about message redaction.

Surface area: every `logger->error` in handlers that includes
`'exception' => $e->getMessage()` is a potential leak vector. ~13 call
sites identified in `Domain/User/Commands/**Handler.php` and
`Domain/Cookie/Commands/**Handler.php`.

### N2. AuditMiddleware records the digest BEFORE handler — semantic ambiguity
`handle` (`:52-54`) computes the digest from the COMMAND, then calls the
handler. If the handler is allowed to mutate the command (it shouldn't —
commands are readonly — but a non-readonly future command would), the
recorded digest is "what the user submitted", not "what was executed".
The current readonly enforcement makes this safe today. Worth a docblock
sentence pinning the invariant ("digest reflects dispatched state, not
post-handler state").

### N3. LoggerFactory creates a fresh logger per call
Every `LoggerFactory::create($channel)` instantiates a new Logger with new
handler, new formatter, new processors. There's no caching. In hot paths
(e.g. `RepositoryLogging` trait creating per-method loggers), this
allocates ~5 objects per call. Not a correctness bug; a latent
performance smell. Either cache per-channel or document the convention
that a logger is constructed once and injected.

### N4. AuditMiddleware does not record idempotency-key
The `audit_log` table is missing `idempotency_key`. When `IdempotencyMiddleware`
replays a cached response, the audit row from the ORIGINAL execution is
the only record — the replay attempt is invisible to auditors. Add
`idempotency_key` to `audit_log` and write it from a shared filter or a
chained middleware.

### N5. CorrelationIdService has no per-coroutine isolation
Static state breaks under Swoole coroutines (they share globals within a
process). Even if `clear()` were called between requests, two concurrent
coroutines on the same worker would race on `$correlationId`. Not a bug
in FPM but a deployment-mode limitation that must be documented before
the first non-FPM deploy.

---

## Cross-tool divergence: RedactingProcessor vs AuditMiddleware

As verified in F1, the two lists differ in COMPOSITION but produce
IDENTICAL output for every key currently in use:

| Marker (substring)  | RedactingProcessor | AuditMiddleware | Catches |
|---------------------|--------------------|-----------------|---------|
| password            | yes (explicit)     | yes (explicit)  | password, newPassword, oldPassword, currentPassword, password_hash, password_confirm |
| token               | yes (explicit)     | yes (explicit)  | token, access_token, refresh_token, csrf_token, api_token |
| jwt                 | yes                | yes             | jwt, jwt_secret |
| authorization       | yes                | yes             | authorization, Authorization |
| api_key             | yes                | yes             | api_key, API_KEY |
| secret              | yes                | yes             | secret, client_secret, jwt_secret |
| private_key         | yes                | yes             | private_key |
| credit_card / card_number / cvv | yes    | yes             | as-named |
| plaintext           | yes                | yes             | plaintext |

The redundant explicit entries in RedactingProcessor (`password_hash`,
`new_password`, etc.) are defence-in-depth — they protect against someone
later splitting a marker into a narrower substring (e.g. shortening
`password` → `pwd`).

**Real divergence risk** is forward-compatible: if either file adds a new
substring (e.g. RedactingProcessor adds `bearer`, `session_id`,
`client_secret`, `pin`, `ssn`, `iban` per consolidated #19), the audit
digest will start including those values while logs redact them.
Recommendation matches consolidated #35: extract `SensitiveKeys::LIST` as
a shared constant, import in both. Minimal effort, eliminates the drift
class entirely.

Also note: neither list redacts `cookie`/`set_cookie` (HTTP cookies
carrying session tokens are an obvious leak vector for HTTP middlewares
that log headers). Round1 report 13 lists this for RedactingProcessor;
applies equally to AuditMiddleware.

---

## Verdict on observability posture

**NOT READY for production multi-tenant or worker deployments.**

**Critical blockers:**
1. **V1** (correlation worker leak) — every non-FPM deployment surface
   collapses trace ids. Mitigation is a 3-line change.
2. **V4** (audit-insert cascades into business-write rollback) — flaky
   audit table downs every command. Documented promise inverted.

**High-priority gaps:**
3. **V3** (digest collapses Currency, DateTimeImmutable, enums to class
   name) — tamper detection is blind to these field changes.
4. **V5** (log-level outliers) — DomainLogger forces business-rule
   violations to ERROR; user-not-found lookups log at ERROR. Both
   poison alerting.
5. **N1** (exception message not redacted) — a real PII surface.

**Medium:**
6. **V2** (DomainLogger channel string) — domain dimension lost on
   Monolog channel; data is still in context, so this is observability
   ergonomics, not data loss.
7. **N4** (no idempotency_key on audit_log) — replay invisibility.
8. **V9** (csrf-before-correlation filter order).
9. **V10** (worker correlation not restored).

**Acceptable today:**
- Processor LIFO order (V7).
- Synchronous flush (V8).
- Audit captured on success+failure, queries not audited, sentinel
  actor_id=0 for system (V6).
- RedactingProcessor vs AuditMiddleware key lists are functionally
  equivalent for the current corpus (F1) — the cross-file divergence is a
  forward-compat concern, not a current leak.

**Net posture:** the observability stack is well-thought-through and
internally consistent (PSR-3 abstraction, JSON formatter, correlation
processor injected automatically, LIFO redaction). The breaks are at the
boundaries: worker lifecycle (V1, V10), failure-mode coupling between
audit and transaction (V4), and the digest normaliser
under-handling VOs/enums (V3). All five are fixable in
under a day's work; until they are, do not run this codebase under
Roadrunner/Swoole and do not promise "audit failure does not affect the
business write" to compliance reviewers.
