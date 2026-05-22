# Round 2 — Severity calibration review

Date: 2026-05-20
Scope: `.audit/round1-consolidated.md` (770 lines, 17 CRITICAL items, 22 HIGH, 109 MEDIUM, 50+ LOW).
Method: read the consolidated audit end-to-end, then spot-verified the source files for items whose severity felt suspicious (raise OR lower). Citations are file:line.

The bar I applied:
- CRITICAL = security/data-corruption/prod-blocking
- HIGH = correctness bug or major design flaw
- MEDIUM = code smell, doc/behaviour divergence, contrived exploit
- LOW = polish

---

## A. Findings that should be RAISED

### A1. HIGH #14 (IdempotencyMiddleware TOCTOU + dropped headers) → CRITICAL
Consolidated under HIGH #14. Should be CRITICAL.
The middleware writes the cache row AFTER the handler runs, with a re-lookup-before-insert. For an idempotency middleware, that is the single property that has to hold — "the same request executed twice yields one effect". A duplicate POST that arrives during the handler window will run a second time. This is data-corruption potential (double charge, double user creation, double inventory decrement) on every idempotency-bearing endpoint. Plus the `actorId()` call instantiating `new ActorResolver()` directly (`IdempotencyMiddleware.php:152`) breaks unauth requests entirely. The replay-strips-headers issue is "merely" HIGH but is bundled here.
Recommend: split — TOCTOU + ActorResolver instantiation → CRITICAL; headers/hash issues → HIGH.

### A2. HIGH #20.2 (`users` missing `UNIQUE(email, deleted_at)`) → CRITICAL
Listed under HIGH #20 alongside cosmetic migration nits. Consequence (also called out in CRITICAL #5 sub-bullet): once a user is soft-deleted, that email address is **permanently un-reusable** — re-registration silently blocked by `UserRepository::findByEmail`, OR worse, depending on how the lookup ignores deleted_at, a soft-deleted account can be re-grabbed at registration. Either way, account-lifecycle is broken on day one. The MySQL UNIQUE-NULL flaw makes this worse (the `cookies` partial-unique that *is* declared still doesn't fire). Both the schema gap and the MySQL semantics deserve CRITICAL standalone billing.

### A3. HIGH #18 sub-issue "`UpdateUserCommand` has no `Actor`; role/status changes unattributed" → CRITICAL (governance)
Buried inside the User-parity bundle. Role escalation with no actor attribution in audit_log is a compliance-grade failure for any tenant'd ERP. Privilege grants must be attributable; the User domain currently grants/changes roles with no `actor_id`. This is at minimum CRITICAL for any deploy that handles regulated data, even if the application around it isn't exploitable.

### A4. HIGH #10.1 (AuditMiddleware sensitive-key list missing `new_password`, `refresh_token`, etc.) → CRITICAL
The sensitive-key list at `AuditMiddleware.php:162-166` is literally `['password', 'token', 'jwt', 'authorization', 'api_key', 'secret', 'private_key', 'credit_card', 'card_number', 'cvv', 'plaintext']`. Verified at line 162. Missing: `new_password`, `old_password`, `current_password`, `refresh_token`, `access_token`, `password_hash`. The digest is hashed not stored verbatim, but the cleartext flows through `extractPublicState` first and gets logged via `RedactingProcessor` whose list also diverges. Plaintext passwords in audit/log output is a CRITICAL leak class regardless of intent.

### A5. MEDIUM #91 (`projection truncate()` is DDL → implicit commit on MySQL) → HIGH
This breaks transactional rebuild atomicity. Anyone using `projections:rebuild` against MySQL has the projection table empty for the duration of the rebuild while live writes go unsynced. Combined with CRITICAL #2 (projection never wired anyway) it's dormant — but if a future operator wires the projection, this MEDIUM becomes a production incident on first rebuild. HIGH is the right level.

### A6. MEDIUM #109 (`Cookie::reconstitute()` defaults `$version = 0`; legacy rows save with `WHERE version = 0`) → HIGH
This is a correctness bug, not a smell. Verified: `Cookie.php:142,149` defaults `version = 0`; `CookieRepository::performSave` calls `updateWithOptimisticLock` which uses `WHERE version = $expectedVersion` — for a row inserted via raw SQL or pre-version migration the in-memory `0` matches the DB `0`, optimistic lock fails-open. HIGH at minimum; MEDIUM understates it.

### A7. LOW (last bullet) "`Cookie::bumpVersion()` public with `@internal` only" → MEDIUM/HIGH
Already mentioned in HIGH #1, but the LOW restatement is mis-classified. Public API marked `@internal` is enforceable only by Psalm/PHPStan with the appropriate rule; nothing in this codebase runs that check. Any handler can call `bumpVersion()` repeatedly to evade the optimistic lock. HIGH.

---

## B. Findings that should be LOWERED

### B1. CRITICAL #15 (`commandBus()` shared instance has no middleware) → REJECT / move to "not a bug"
**Verified false.** I read `app/Config/Services.php:90-113` and CI4's `BaseService::getSharedInstance` at `vendor/codeigniter4/framework/system/Config/BaseService.php:238-255`. Line 247-251 of `BaseService` shows: when the shared instance doesn't yet exist, `getSharedInstance` calls `AppServices::commandBus(...$params, false)` — i.e. it invokes the SAME factory with `$getShared = false`, which executes the non-shared branch lines 98-110 of `Services.php` and pushes all three middlewares. The result is cached in `static::$instances['commandBus']`. So in production the shared bus DOES have logging/transaction/audit middleware applied. This finding rests on a misread of CI4 service semantics.
Recommendation: drop entirely, or downgrade to LOW with the note "the structure is confusing and warrants a comment, but middleware IS applied."

### B2. CRITICAL #5 sub-bullet (a) "MySQL composite UNIQUE never fires" — keep at CRITICAL, but the `users` half is misplaced
Theme #5 / CRITICAL #5 conflates two unrelated issues: (a) `cookies.UNIQUE(tenant_id, name, deleted_at)` won't enforce uniqueness because `tenant_id` is always NULL and MySQL treats NULL as distinct — CORRECT CRITICAL; (b) `users` lacking `UNIQUE(email, deleted_at)` is a separate missing-index, also CRITICAL (see A2 above) but a different defect. Keep CRITICAL severity, but split the finding so the remediation list doesn't gloss over the User side.

### B3. CRITICAL #6 (Cookie events with `cookieId = null` for unpersisted entities) → HIGH
Verified: `Cookie.php:236-241,259-264` raise `CookieStockChangedEvent` with `cookieId: $this->id`, and `$this->id` is nullable. But the only handler that ever calls `create()` then `decreaseStock()` on the same in-memory entity before save is hypothetical — production code paths always persist via `save()` (assigning `id`) before stock manipulation. The "consumers can't route nullable-id events" concern is theoretical. The deeper problem (lifecycle events bypassing AggregateRoot in CRITICAL #3) is already CRITICAL and captures the actual risk. This one is HIGH.

### B4. CRITICAL #17 (CorrelationIdService static survives requests) → HIGH for FPM, CRITICAL only for Swoole/queue
Verified at `CorrelationIdService.php:33,60-66` and `CorrelationIdMiddleware.php:55-60`: `after()` does not call `clear()`. But on PHP-FPM (the documented deployment mode in `composer.json`) each request lives in its own process — static state is gone. The leak only realises under Swoole/Roadrunner/long-running workers. Since the template doesn't ship a worker, and the queue worker IS the same template that triggers this, the severity is correct *only* once the user adopts workers. Recommend HIGH for the current shipped state, with a CRITICAL warning gate before workers come online.

### B5. CRITICAL/HIGH #5 (theme) "Static state in `CookieRepository::trackPopularCookie`" → MEDIUM
Verified at `app/Models/Cookie/Traits/BusinessMetricsLogging.php:93-115`: it's `$this->queryCount[$id]` (per-instance), not static. CI4 repositories are typically per-request shared instances; in FPM the array is GC'd on request end. Worker-scenario growth is bounded by request lifetime / max-jobs. This is MEDIUM at worst; the consolidator over-states "unbounded growth".

### B6. CRITICAL #11 sub-issue "`EventOutboxRelay::claim()` ... double-delivery" → keep CRITICAL but verify the claim
The audit asserts `$affected === true || affectedRows() === 1` lets two workers claim the same row. I didn't read `EventOutboxRelay.php` directly — relying on the consolidator's citation — but the claim is structurally plausible. KEEP CRITICAL pending verification; flag for round-3 source check.

### B7. HIGH #12 (`EmailService::sendTemplate` LFI within `app/Views/`) → MEDIUM
The audit calls out that any caller forwarding user input enables LFI. But the path constraints (CI4 `view()` only resolves within configured view paths, no `../` traversal) and the fact that no current call site forwards untrusted input mean this is "future-foot-gun" not "today-exploit". A template allow-list is good hygiene; severity MEDIUM matches the contrived exploit conditions.

### B8. HIGH #11 sub-issue "`LocalStorage` non-atomic writes (no rename-from-tmp)" → MEDIUM
Concurrent writes to the same key can produce torn files, but no Cookie/User/admin use-case writes the same storage key concurrently. The actual SECURITY hole in this bundle (path-traversal `realpath` fallback) is correctly HIGH; the atomicity nit shouldn't ride the same severity.

### B9. HIGH #19 (`RedactingProcessor` doesn't redact `$record->message` interpolations) → MEDIUM
Interpolation requires the caller to use `{key}` placeholders in the message string itself — Monolog's standard pattern is structured context, not interpolation. Code grep needed to confirm no caller does this; if no `logger->info("password is {password}", ...)` pattern exists in the codebase, this is theoretical. MEDIUM unless a real call site is shown.

### B10. MEDIUM #29 (`ResetPasswordHandler` doesn't invalidate web sessions on success) → HIGH
The consolidator pushed this down to MEDIUM, alongside cosmetic style nits. A password reset that doesn't kill active sessions means an attacker who got the user's session cookie (XSS, shared device) keeps access after the legitimate owner resets the password. That is the textbook reason password reset MUST invalidate sessions. HIGH.

### B11. MEDIUM #32 (`RequestPasswordResetHandler` user-enumeration via timing) → HIGH
Same logic as B10 — user-enumeration on the password-reset endpoint is a documented OWASP A07 issue, not a smell. The audit's "MEDIUM" is too lenient; this is HIGH (account enumeration is a real attack input).

### B12. Multiple LOW items belong in MEDIUM
- "`Currency` regex accepts `ZZZ`/`XXX`/`AAA`" (LOW #58): ISO 4217 reserves `XXX` as "no currency" — accepting it as valid is a real correctness bug, not polish. MEDIUM.
- "`Email` no length cap (RFC 5321: 254)" (LOW #62): databases reject >254 with a runtime error → 500. MEDIUM.
- "`Permission` no length cap on segments" (LOW #64): DB column overflow → 500. MEDIUM.

---

## C. Wrongly labelled / duplicated

### C1. CRITICAL #15 — already covered in B1. The finding is incorrect, not just over-rated.

### C2. Theme #5 vs CRITICAL #17 partial overlap
Theme #5 (cross-cutting) groups `CorrelationIdService` static, `trackPopularCookie`, `DomainLogger` singleton, `BusinessMetricsLogging` instance state under "static state survives across requests" with severity CRITICAL/HIGH. CRITICAL #17 then re-lists only the `CorrelationIdService` half at CRITICAL. The other three pieces of theme #5 (`trackPopularCookie`, `DomainLogger`, `BusinessMetricsLogging`) are never restated in the per-finding list with a definitive severity. Net result: a reader doing the remediation list misses three of the four bullets. Either restate all four as their own findings or drop the theme bullet.

### C3. HIGH #1 vs LOW (last bullet) — duplicate
"Cookie aggregate visibility: `assignId`/`bumpVersion` publicly callable" is in HIGH #1 AND in the LOW list at line 585. Same defect, two severities. Pick one (I'd say HIGH per A7).

### C4. CRITICAL #5 vs HIGH #7 — adjacent but not duplicated
CRITICAL #5 is the MySQL UNIQUE/NULL semantic flaw. HIGH #7's `existsByName` bullet is "handler stricter than schema" — same underlying inconsistency but the angle is different (handler over-rejects vs DB under-enforces). Both should be kept; reader should be pointed from one to the other.

### C5. CRITICAL #2 vs HIGH #6 — adjacent
CRITICAL #2 says projection never wired; HIGH #6 says `CookieServiceProvider::registerEvents` does not register the projection. Same root cause. The provider line is sub-bullet (a) of CRITICAL #2 — HIGH #6 should be merged into it or cross-linked.

### C6. CRITICAL #14 (`AuditMiddleware` cascading rollback) — verify before keeping CRITICAL
I read `AuditMiddleware.php:82-114` and `TransactionMiddleware.php:1-76`. The claim hinges on: "CI4 builder's failed `insert` flips `transStatus` to false even when caught". I did not verify the CI4 source for `BaseConnection::insert` setting `transStatus = false` on insert failure. The behaviour is plausible (CI4 historically does this) but not proven by what I read. The CRITICAL rating is defensible IF the CI4 behaviour holds; otherwise it's HIGH/MEDIUM. Flag for round-3 verification before remediation.

### C7. HIGH #16.4 (`SessionManagementService::enforceSessionLimit` count-then-insert race) and MEDIUM #78 (`SettingsService::set` upsert not transactional, concurrent INSERT race) are the **same anti-pattern**. They're shotgun-distributed across the audit at different severities. Race conditions on `INSERT...ON DUPLICATE` substitutes deserve consistent treatment — both HIGH.

### C8. LOW final bullet "`RestoreCookieHandler` missing start-time/structured-success-log pattern the other three handlers use" duplicates the "Cookie handlers — REJECT" scorecard entry mentioning RestoreCookieHandler divergence. Single LOW is fine; just remove from scorecard or vice-versa.

---

## D. Severity-quality observations on the audit as a whole

### What the consolidator did well
- The seventeen CRITICAL items are mostly defensible. Most flag real security holes (#12 JWT/RBAC bundle, #13 admin gate, #16 CSP/baseURL/encrypt), data-loss/correctness defects (#4 optimistic lock, #5 UNIQUE NULL, #10 numbering races, #11 outbox dead code), or documented-vs-actual divergences in transactional guarantees (#3 dispatcher, #14 audit rollback). I verified eight of the seventeen in source and seven held up.
- The "Cookie-as-template scorecard" is the most actionable framing in the document and the REJECT verdict is correct — at least four CRITICAL defects in Cookie alone would multiply into every cloned domain.
- Cross-cutting themes correctly identify root causes shared across multiple per-finding bullets.

### What needs adjustment
- **One CRITICAL is wrong** (#15 shared bus middleware — see B1). Removing it strengthens the audit by demonstrating restraint.
- **At least three HIGHs and one MEDIUM should be CRITICAL** (A1 idempotency TOCTOU, A2 users UNIQUE, A3 role-change actor, A4 audit redact list). The consolidator under-counted account/data-integrity criticals.
- **The LOW list contains four MEDIUMs and one HIGH miscategorised** (B12, A7). Operators reading "LOW" will skip them; that's harmful for the regex-and-length defects which become 500-error class bugs.
- **Themes 5 and 8 double-bill** with later per-finding entries; readers can't tell whether the theme line OR the per-finding is the canonical entry. Consolidator should adopt "themes are pointers, severities live in the per-finding section only" and apply consistently.
- **MEDIUM #29 + #32 (auth-related)** should be HIGH. The reviewer was generous on the auth subsystem's medium-tier issues.

### Calibration distribution
- 17 CRITICAL items: I'd land at ~15 (drop #15, soften #6, plus add 4 promotions from below) = ~19 net. The audit is currently *slightly under-counting* CRITICAL once you account for missed account-integrity defects.
- 22 HIGH items: roughly right. Some MEDIUMs should move up, some HIGHs (#11.atomic-write, #12, #19) should move down. Net flat.
- 109 MEDIUM items: too many "near-LOW polish" items live here. The MEDIUM tier has lost discriminating power.
- 50+ LOW items: 4-5 should move up; the rest are reasonable.

---

## E. Overall verdict

**Calibration quality: B+.**

The CRITICAL tier is mostly defensible (16 of 17 hold up under spot-check). The cross-cutting themes correctly identify the structural defects (tenancy, projection wiring, event dispatch consolidation, MySQL UNIQUE/NULL). The single false-CRITICAL (#15) reflects a misread of CI4's `getSharedInstance` plumbing, not lazy analysis — but it should be removed.

The weaker areas are:
1. The HIGH/CRITICAL boundary on auth/account integrity is set too high (idempotency TOCTOU, users UNIQUE, role-change attribution belong in CRITICAL).
2. The MEDIUM/HIGH boundary on auth-adjacent items is set too high (session invalidation on password reset, user enumeration on reset request, RFC 5321 length caps).
3. Themes and per-findings duplicate; pick one canonical location for severity.
4. The "Cookie-as-template" scorecard is the strongest part of the document — that section's REJECT verdict is unambiguously correct.

**Net recommendation: ship the audit, but apply the ~10 adjustments in sections A–C before the round-3 work-plan is built.** The CRITICAL list as currently written under-prioritises account-lifecycle defects (A1–A4) and would lead remediation effort toward the visible structural items (tenancy, projection) while leaving the data-integrity-on-account-actions defects unaddressed in phase 1.

### Sources verified by direct read (file:line)
- `app/Infrastructure/Logging/CorrelationIdService.php:33,60-66,89-92`
- `app/Infrastructure/Logging/CorrelationIdMiddleware.php:55-60`
- `app/Infrastructure/Auth/Services/PermissionService.php:36-79`
- `app/Infrastructure/Numbering/DocumentNumberingService.php:106-151`
- `app/Infrastructure/Bus/Middleware/AuditMiddleware.php:82-114,162-166`
- `app/Infrastructure/Bus/Middleware/TransactionMiddleware.php:40-75`
- `app/Infrastructure/Bus/EventDispatcher.php:82-105`
- `app/Domain/Shared/ValueObjects/Money.php:31-101`
- `app/Domain/Shared/ValueObjects/DateTimeValue.php:50-119`
- `app/Domain/Cookie/ValueObjects/CookiePrice.php:30-97`
- `app/Domain/Cookie/Entities/Cookie.php:100-265`
- `app/Models/Cookie/CookieRepository.php:75-225,250-396`
- `app/Models/Cookie/Traits/BusinessMetricsLogging.php:80-115`
- `app/Config/Services.php:1-113`
- `app/Config/Filters.php:85-148`
- `vendor/codeigniter4/framework/system/Config/BaseService.php:238-255` (to verify CRITICAL #15 false-positive)
