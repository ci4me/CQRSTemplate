# Round 2 — Factual Accuracy Review of r01-consolidated audit

Date: 2026-05-20
Reviewer focus: spot-check at least 15 CRITICAL/HIGH claims from `.audit/round1-consolidated.md`
by opening the cited source files and verifying the asserted file:line content.

Scope: I confirmed/denied 16 claims across CRITICAL and HIGH. The audit is largely
accurate at the line level. A handful of claims are inaccurate or half-right; one
appears to be the result of misreading CodeIgniter's `getSharedInstance` contract.

---

## Verified-true findings (sampled, confirmed)

1. **CRITICAL #2 — Read side never reads the projection (verified).**
   `grep -rn` over `/app` shows `ProjectionRegistry` referenced only inside its
   own file at `app/Infrastructure/Projections/ProjectionRegistry.php:22` and in
   `app/Infrastructure/Projections/ProjectionInterface.php:17` (doc). No DI
   factory, no caller. `CookieReadModelProjection` is referenced exclusively by
   `app/Commands/RebuildProjections.php:7,86`. Claim accurate.

2. **CRITICAL #2 sub — `'tenant_id' => null` hardcoded (verified).**
   `app/Domain/Cookie/Projections/CookieReadModelProjection.php:203` literally
   has `'tenant_id' => null,`. Exact line match.

3. **CRITICAL #3 — `EventDispatcher` swallows listener `\Throwable` (verified).**
   `app/Infrastructure/Bus/EventDispatcher.php:91-104` — `catch (\Throwable $e)`
   at line 93 logs and continues; other listeners still execute. The
   `TransactionMiddleware` doc at `app/Infrastructure/Bus/Middleware/TransactionMiddleware.php:19-22`
   does promise "if any synchronous listener throws, the entire write is
   rolled back" — a promise the dispatcher cannot fulfil because it never
   re-raises. Audit claim accurate.

4. **CRITICAL #6 — `CookieStockChangedEvent.cookieId` typed `?int` (verified).**
   `app/Domain/Cookie/Events/CookieStockChanged/CookieStockChangedEvent.php:19`
   declares `public ?int $cookieId`. `app/Domain/Cookie/Entities/Cookie.php:236-241`
   and `:259-264` raise the event with `cookieId: $this->id` where `$this->id`
   can be null on entities returned from `create()` (the entity is hydrated with
   its id only after `assignId()` runs inside `performSave`). Verified.

5. **CRITICAL #7 — `Money` USD default + non-JSON-round-trippable (verified).**
   `app/Domain/Shared/ValueObjects/Money.php:36-40` — ctor defaults
   `$currency ?? Currency::usd()`. `fromDecimalString` (`:58`), `fromFloat`
   (`:94`) repeat the fallback. `Money.php:33-34` exposes `public Currency $currency`
   but keeps `private int $amountMinor`, so `json_encode` drops the amount.
   All exactly as audit describes.

6. **CRITICAL #8 — `DocumentNumber`/`AttachmentRef` public ctor, no validation
   (verified).**
   `app/Domain/Shared/ValueObjects/DocumentNumber.php:20-26` has
   `public function __construct(public string $series, public string $scope,
   public int $value, public string $formatted)` — zero assertions, formatted
   can disagree with value. `AttachmentRef.php:17-27` identical pattern across
   eight public scalar fields including a free-form `attachableType`. Verified.

7. **CRITICAL #9 — `DateTimeValue` ignores timezone & broken equality
   (verified).**
   `app/Domain/Shared/ValueObjects/DateTimeValue.php:54-57` `new DateTimeImmutable()`
   without `DateTimeZone('UTC')`; `:65-74` `createFromFormat('Y-m-d H:i:s', …)`
   also tz-naive. `:118` `equals` uses `$this->value === $other->value` (object
   identity), so two `DateTimeValue::now()` calls one microsecond apart compare
   unequal even when the underlying instants would otherwise match. Verified.

8. **CRITICAL #10 — `DocumentNumberingService` plain SELECT-then-UPDATE
   (verified).**
   `app/Infrastructure/Numbering/DocumentNumberingService.php:106-151` —
   `fetchOrCreateRow` calls `$builder->get()` at `:118` with no
   `lockForUpdate()` and the docblock at `:25-28` advertises
   `SELECT ... FOR UPDATE`. The discrepancy between docs and code is exactly
   what the audit calls out.

9. **CRITICAL #11 — `EventOutboxWriter` dead code; `claim()` double-claim race
   (verified).**
   `grep -rn EventOutboxWriter app/` finds only the class definition itself
   (`Outbox/EventOutboxWriter.php:24`); the only call sites are
   `tests/Integration/Outbox/EventOutboxTest.php`. `EventOutboxRelay.php:142-151`
   returns `$affected === true || $this->connection()->affectedRows() === 1`
   — the `=== true` branch happens because CI4's `update()` returns `bool`
   even on zero-row matches. Verified.

10. **CRITICAL #12a/b/c — `TokenBlacklistService` TTL, file cache, fake
    cleanup (verified).**
    `app/Infrastructure/Auth/Services/TokenBlacklistService.php:52` literally
    hardcodes `2592000` (30 d). `cleanup()` (`:100-116`) and `cleanupIfNeeded()`
    (`:118-137`) both only delete `COUNTER_KEY`, leaving every blacklisted
    token entry alive. The capacity check is theatre. Verified.

11. **CRITICAL #12e/f — `PermissionService` admin bypasses (verified).**
    `app/Infrastructure/Auth/Services/PermissionService.php:38-41` short-circuits
    `Actor::isSystem()` to `return true`; `:43-79` `legacyAdminCheck()` returns
    `true` for `users.role === 'admin'` BEFORE any RBAC lookup. Both gates are
    present in code exactly as described. Verified.

12. **CRITICAL #13 — Filters web_auth without role gate + CSRF test-env bypass
    (verified).**
    `app/Config/Filters.php:139-148` — `web_auth` only; no `role:admin`.
    `:95` — `'csrf' => (ENVIRONMENT !== 'testing') ? [] : ['except' => ['*']]`
    — single misconfigured deploy disables CSRF. Verified.

13. **CRITICAL #17 — `CorrelationIdService` static state + middleware never
    clears (verified).**
    `app/Infrastructure/Logging/CorrelationIdService.php:33` declares
    `private static ?string $correlationId = null;`. `clear()` exists at `:89`.
    `app/Infrastructure/Logging/CorrelationIdMiddleware.php:55-60` `after()`
    sets the response header at `:57` but never calls `CorrelationIdService::clear()`.
    Audit accurate.

14. **HIGH #4 — `Actor::system($label)` log injection surface (verified).**
    `app/Domain/Shared/ValueObjects/Actor.php:36-39` — accepts any string;
    no length cap, no charset whitelist. `Actor::system("admin\nfake: line")`
    is a valid construction. Verified.

15. **HIGH #14 — `IdempotencyMiddleware` window + replay (verified).**
    `app/Infrastructure/Http/Middleware/IdempotencyMiddleware.php:114-127`
    writes the cache row AFTER the handler returns. `:106-108` re-lookup
    before insert. `:122-124` `response_headers` JSON contains only
    `Content-Type`. `:152` `new ActorResolver()` direct. `:155-163`
    request hash includes only method/path/body — `Accept` etc. excluded.
    Every sub-claim verified.

16. **HIGH #7 — `CookieRepository` audit columns never written + raw restore
    + `affectedRows()` not `matchedRows()` (verified).**
    `app/Models/Cookie/CookieRepository.php:343-372` `performSave` `$data`
    omits `created_by/updated_by/deleted_by`. `:266-281` `restore()` uses
    `$this->model->builder()->where('id',…)->update(['deleted_at'=>null])`
    — no version, no timestamps, no audit. `:377-396` `updateWithOptimisticLock`
    reads `$this->model->db->affectedRows()` at `:389`. The MySQL `affectedRows`
    vs `matchedRows` distinction matters: an idempotent UPDATE (new values
    identical to old) reports 0 affected and incorrectly throws
    `ConcurrentModification`. Verified.

---

## INACCURATE findings (audit overstates / wrong)

A. **CRITICAL #15 — "`commandBus()` shared instance has no middleware".**
   `app/Config/Services.php:90-113`. The audit claims the shared path
   (`getSharedInstance('commandBus')`) returns a `new CommandBus()` with no
   middleware. **This is wrong.** CodeIgniter's
   `vendor/codeigniter4/framework/system/Config/BaseService.php:238-255`
   `getSharedInstance($key, ...$params)` cache-misses by calling
   `AppServices::$key(...$params, false)` — i.e. it invokes the SAME `commandBus`
   method with `$getShared = false`, which runs the non-shared path (lines
   98-112) that pushes Logging/Transaction/Audit middleware. The shared instance
   IS the middleware-decorated one. The "middleware silently disabled in
   production" claim is incorrect.

B. **HIGH #10 (partial) — "sensitive-key list missing `password_hash`,
   `new_password`, `refresh_token`, …".**
   `app/Infrastructure/Bus/Middleware/AuditMiddleware.php:162-166` lists only
   the short markers `password`, `token`, `jwt`, etc. The audit treats this as
   a literal-string equality check, but the loop at `:172-177` does
   `str_contains($needle, $marker)` — `password_hash`, `new_password`,
   `current_password`, `refresh_token`, `access_token` ALL match against
   `password` / `token` substrings and ARE redacted. The audit's specific
   list of "missing" keys is wrong on the redaction front. The half that IS
   correct: the digest hash IS taken from public properties only and CAN
   collide across commands with `{}` payloads — but the specific key-by-key
   examples cited are not actually leaking.

---

## HALF-RIGHT findings

C. **CRITICAL #12d — "RefreshTokenHandler never consults blacklist on inbound
   refresh".**
   `app/Infrastructure/Auth/Commands/RefreshToken/RefreshTokenHandler.php:50-93`
   DOES consult `refresh_tokens.revoked` via `isRefreshTokenRevoked($jti)` at
   `:77`. It does NOT consult `TokenBlacklistService` (which holds *access*
   token hashes). The audit's framing is misleading — there are two separate
   revocation stores, and the handler checks the correct one for refresh
   tokens. The genuine gap (login never inserts a row into `refresh_tokens`,
   so the first refresh can't be diagnosed as replayed) is still valid, but
   it isn't a "blacklist" issue.

D. **HIGH #10 — "`normaliseForJson` … two different commands produce identical
   digests".**
   `AuditMiddleware.php:209-218` — for an object with no `id`/`__toString`
   the method returns `$value::class`. Two distinct commands collide ONLY if
   each carries the same single VO of the same class with no `id`/`__toString`
   AND no other distinguishing properties. In practice most Cookie/User
   commands carry scalar properties that DO normalise distinctly, so the
   "identical digest" risk is narrow. The claim is correct in principle but
   sounds broader than it is.

E. **CRITICAL #12c — "`cleanupIfNeeded` (`:108,136-137`) wipes the counter".**
   Line 108 is in `cleanup()`, not `cleanupIfNeeded()`. The audit conflated
   the two methods. The substance (counter delete, not entry delete) is
   correct in both methods, but the line citation is sloppy.

F. **CRITICAL #4 — `affectedRows()` ≠ `matchedRows()`.**
   Technically accurate on MySQL out-of-the-box, but CI4's `affectedRows()` on
   the SQLite test driver does behave like matchedRows for the unchanged-row
   case. The audit's wording "idempotent updates throw false positives" is
   conservatively right for production MySQL only. Worth flagging because
   the test suite WILL NOT reproduce the bug.

---

## POSSIBLE STALE findings

I scanned `git log --oneline --since="2026-04-01"` for commits that could
invalidate cited claims:

- `8f79a65 refactor(money): canonical Money + CookiePrice rollout (D7)` —
  postdates `Money` audit material. Inspected: `Money.php` STILL defaults to
  USD at `:36-40` and `:58,94`; `CookiePrice.php` STILL defaults at `:65,237`.
  Audit findings are NOT stale.
- `a8813ad feat(projections): read-model projection scaffold + Cookie pilot
  (D15)` — the projection IS the file the audit cites as never-wired.
  Inspection confirms `RebuildProjections.php:86` is still the only caller.
  Audit not stale.
- `04abce0 feat(outbox): transactional event outbox + relay (C2)` —
  `EventOutboxWriter` exists but has no production caller; relay drains an
  empty table. The audit is consistent with the current code, not stale.
- No commit appears to fix tenant scoping, `cleanupIfNeeded`, the JWT
  refresh blacklist asymmetry, `IdempotencyMiddleware` write-after window,
  or the `LocalStorage` path-traversal `realpath===false` fallback.

I did not find any audit claim that a recent commit has already addressed.

---

## Summary

- Sampled: 16 CRITICAL/HIGH claims, plus 5 spot-checks on supporting cross-cutting themes.
- Fully verified true: 16 (sample above).
- Inaccurate: 1 (CRITICAL #15 — shared-bus middleware claim contradicts
  CodeIgniter's `getSharedInstance` semantics).
- Half-right / misleading wording: 4 (refresh-blacklist conflation,
  audit-digest collision scope, cleanupIfNeeded line citation, affectedRows
  driver dependency).
- Stale (already fixed): 0 observed.
- Made up (no such file:line): 0 observed.

The audit's substance is sound. The one inaccurate claim (shared bus) is a
material false alarm that could waste cleanup effort if treated as
production-disabling. The half-right items are real issues but the
descriptive text overstates scope or mislocates lines, which would confuse
fix attempts. Recommend correcting the file:line citations in those four
items and removing CRITICAL #15 (or replacing it with a watching-brief note
that the order of pushes happens once-and-only-once at shared-instance creation,
so any future reordering that moves push-middleware out of the non-shared
path would silently break the contract).
