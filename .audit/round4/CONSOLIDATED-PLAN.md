# Round-4 Audit → Consolidated Remediation Plan (Cookie-centric ERP base)

## Context

- **Repo**: CQRSTemplate (PHP 8.3, CI4.6, CQRS + DDD + hexagonal, PHPStan L8, 90% gate), branch `stabilization/erp-foundation`. This template is the foundation for refactoring a production ERP; `app/Domain/Cookie` is the reference entity cloned for every future domain — **every flaw multiplies per clone**.
- **Audit shape (token-constrained)**: 6 deep read-only auditors on core dimensions + 1 breadth sweeper over 9 supporting dimensions + synthesis done by the main agent (the workflow's synthesizer agent failed; all 7 reports were recovered from the journal). 94 findings, 36 prior findings verified fixed.
- **Full reports**: `/tmp/claude-1000/-home-gabriel-Documentos-CQRSTemplate/7eeb26cd-0c2f-44f7-aebf-91b5818f6b3b/tasks/round4-harvest.json` (176 KB, volatile — Step 0 persists it).

## Headline verdict

**Overall health ≈ 4.4/10 — do NOT clone Cookie into ERP domains yet.** Health by dimension: write-side **3**, aggregate-entity **4**, read-side **4**, events-outbox **4**, value-objects **5**, repositories **5**, supporting sweep **6**.

**Finding #1 — the audit record lies (phantom merges).** Five auditors independently proved that round-3 `RE-AUDIT-*` files mark findings CLOSED whose PRs (**#34 E05, #35 E07-part, #36/#37 E08, #41 E11, #42 E18-logging**) were **never merged** — only E01/E02/E03/E06 are in the tree. Every CLOSED claim in `.audit/round3/` is untrustworthy; the open PR stack is where the fixes actually live.

## Cross-cutting themes (root causes)

1. **Stale audit record / phantom merges** — all dimensions. The conflicted PR stack #32–#42 carries most fixes.
2. **Event architecture broken** (both CRITICALs live here): outbox same-tx guarantee covers ONLY UpdateCookie — Create/Delete/Restore hand-dispatch from handlers, bypassing the outbox (`CookieRepository.php:175-202` vs `CreateCookieHandler.php:105`, `DeleteCookieHandler.php:92`, `RestoreCookieHandler.php:95`); outbox rows are dispatched in-process AND re-delivered by the relay with no dedup (**double delivery**); events drained twice (repo + UpdateCookieHandler); 3 competing dispatch patterns; E04 envelope absent; no stuck-`in_flight` reaper; `CookieStockChangedEvent` is dead code.
3. **Money near-miss**: the Money/Currency kernel is ERP-grade, BUT `Money::multiply` has **no overflow guard** (price×qty!), `CookiePrice` applies USD-cents bounds to all currencies (JPY/BHD mis-bounded), factories silently default to USD, `CookieStock` has no max (wraparound).
4. **Optimistic locking is theater on the client path**: `UpdateCookieHandler` ignores the client's `expectedVersion` — the WHERE clause uses the freshly-loaded version (`CookieRepository.php:475-486`), so lost updates are NOT prevented; `restore()` bumps no version and returns true on zero rows.
5. **Test/log bleed**: integration tests write to real `writable/logs` → **389 MB/day**, 92,746 copies of one exception (`dropKey('users','users_email')` fails on SQLite — a pattern every clone would copy); dual logging stacks (CI4-native + Monolog), only one rotates.
6. **Tenancy opt-out**: `TenantContext` is nullable (filter silently no-ops), `IntegrationTestCase` builds repos WITHOUT it, and `DocumentNumberingService` ignores tenants entirely → **cross-tenant sequence collisions** for gapless document numbers.
7. **User-vs-Cookie schism**: User has no aggregate contract/version/locking/outbox/read-port (reads hydrate the write aggregate), wrong exception contract. Two "references" → cloners pick randomly.
8. **Dead/aspirational code as pattern noise**: StateMachine (0 call sites), CookieView (+test), PriceFormatter (0 callers), decrease/increaseStock + StockChanged event (unreachable), projection `.php.example`, `CookiePrice::getValue(): float`.
9. **Gate-scope dishonesty**: `app/Commands` (the outbox relay!) + `app/Helpers` outside PHPStan AND the coverage denominator; `docblocks:audit` walks Cookie only; duplicate lowercase test trees + foreign `tests/_skipped/AbiSageIntacct` ship with the template.

## Execution plan

### Phase R0 — Truth & triage (XS–S each; do before everything)
1. **Persist round 4**: copy harvest JSON → `.audit/round4/` (reports + this plan as `CONSOLIDATED-PLAN.md`).
2. **Correct the record**: in `.audit/round3/POST-REVIEW-CONSOLIDATED.md`, re-mark F2/F3/F6–F9/F11 (slice 01), F3/F5/F12/F16 (slices 03/04) etc. as OPEN; annotate every RE-AUDIT as "describes PR state, not tree state".
3. **PR triage** (the stack still contains the right content): rebase-or-recut onto current base in dependency order — **#42 (E18 logger isolation) FIRST** (stops the 389 MB/day bleed, independent), then #34 (E05) → #35 (E07) → #36/#37 (E08) → #38/#39 (E10) → #41 (E11) → #40 (E12) → #32. For each: if rebase conflicts exceed re-implementation cost, close and fold into the epic implementation below.
4. **Quick wins batch** (all XS): add `ErrorCodes::COOKIE_STATE_NOT_DELETED=403` (use in `RestoreCookieHandler.php:71`) + `COOKIE_STATE_NOT_PERSISTED` (use in `Cookie::assertPersisted`); delete dead `method_exists` check (`CommandBus.php:111-115`); reduce `CreateCookieHandler::determineErrorCode` to the `getErrorCode`-only form; delete dead drain loop (`UpdateCookieHandler.php:126-128`); delete `CookiePrice::getValue()`; error code on `CookiePrice::multiplyBy:163`; fix `GetAllCookiesQuery` docblock; `esc()` on `formattedPrice`/`id` in `cookies/index.php:47,50` + `show.php:28,41`; move root `ERP_TEMPLATE_AUDIT.md`/`FINAL_AUDIT.md` → `.audit/`; truncate `writable/logs/*`.

### Phase R1 — P0 correctness (data integrity; this sprint)
5. **Stop the log bleed** (sweep CRIT): land logger isolation (PR #42 or re-do): `LoggerFactory::getLogDirectory()` honors `ENVIRONMENT=testing` → temp/null sink; CI4 `Config/Logger.php` threshold ≤4 in testing. **Fix the migration pattern**: `2026-05-20-210000_UsersEmailUniqueWithSoftDelete.php:39-43` — skip `dropKey` on SQLite, look up real index name on MySQL. Acceptance: full phpunit run produces <1 MB of logs.
6. **Unify event dispatch — outbox as single path** (events CRIT×2, write CRIT, agg HIGH): aggregate raises ALL events (`Cookie::markDeleted()`, `Cookie::restore()`, raise Created after id assignment — E07 PR content); repository is the ONLY drain (`dispatchPendingEvents` in save/delete/restore, same TX); delete the 3 handler hand-dispatches; **remove the in-process dispatch** (`CookieRepository.php:199-201`) so the relay is the only deliverer. Tests: in-tx outbox test + dispatch-count==pullEvents-count.
7. **Money guards** (VO HIGHs): overflow-checked `Money::multiply` (`Money.php:263-266`, `intdiv(PHP_INT_MAX, |mult|)` pattern); `CookieStock` `MAX_STOCK` + guarded `incrementBy`; tests incl. JPY/BHD cases.
8. **Real optimistic locking** (write HIGH): thread `command.expectedVersion` into `updateWithOptimisticLock` WHERE (`CookieRepository.php:475-486`); make `UpdateCookieCommand.expectedVersion` required or document last-writer-wins; restore() gets `WHERE deleted_at IS NOT NULL` + `version+1` + `lastAffectedRows===1` (E11 content). Integration test: load v5 → out-of-band write v6 → submit v5 → expect `concurrentModification`.

### Phase R2 — P1 contracts (before any domain is cloned)
9. **E04 envelope**: `AbstractDomainEvent` (eventId/occurredAt/actorId, JsonSerializable); extend the 5 Cookie events; tighten `CookieStockChangedEvent.cookieId` to int.
10. **E05/E08 bases + typed buses**: `AbstractCommandHandler`/`AbstractQueryHandler` (+ `ClockInterface`/`SystemClock`, `LogSampler` with `random_int`); buses type `register(string, XHandlerInterface)` — kill `method_exists` duck-typing; migrate all 4+3 Cookie handlers; **RestoreCookieHandler parity** (raw `RuntimeException`, snake_case keys, leaked actor id, wrong code — `RestoreCookieHandler.php:71-101`); uniform failure-log shape, slow-query→`warning`, domain from `static::class`; land the E05.5 PHPStan rule restricting `AggregateHydrator::key()` to repository namespaces.
11. **E11 repo hygiene**: `existsByName` drop `withDeleted` (contradicts the name-reuse migration contract, `CookieModel:93-115`); LIKE escaping (`CookieQueryRepository:136,572`); trusted reconstitution (`fromTrusted`); single-statement delete; de-hardcode `Cookie` literals in `RepositoryLogging`/`BusinessMetricsLogging` traits (constructor-set domain name — survives clone-by-sed).
12. **E12 outbox hardening** (destructive — MySQL lane green first): `event_uuid` UNIQUE + dedup/ProcessedEventStore, status VARCHAR(32) (relay already writes 18-char `unsupported_schema`!), lease columns + expired-`in_flight` reaper (`spark events:reap`), `tenant_id`, SKIP LOCKED.
13. **Tenancy hardening (NEW epic E19)**: `TenantContext` non-nullable across repos; `IntegrationTestCase.php:60-62` constructs WITH tenant; `DocumentNumberingService` gets `tenant_id` in `document_sequences` key + FOR UPDATE path; cross-tenant isolation test (tenant-1 reads never see tenant-2 rows).
14. **Gate honesty (fold into E02/E18)**: add `app/Commands` + `app/Helpers` to `phpstan.neon` paths and the coverage denominator; cover `RelayOutboxEvents`; delete duplicate test trees (`tests/unit`, `tests/_support`, `tests/_skipped`, `tests/session`, `tests/database`, `*.bak`) + their stale excludes; widen `docblocks:audit` incrementally.
15. **Read-side perf edges (from E08 scope)**: search `LIKE '%term%'` → prefix `like(...,'after')` + `(tenant_id, name)` index (or FULLTEXT decision); `GetAllCookies` gets `MAX_RESULTS` or is deleted from the template; page ceiling on pagination.

### Phase R3 — P2 consolidation
16. **E10 read-side**: CookieDTO canonical (delete `CookieView`+test); `ReadDTOInterface` (toArray/jsonSerialize, snake_case, ISO-8601 dates — fix the false docblock at `CookieDTO.php:31-32`); id non-nullable; `isOutOfStock()` → precomputed field; shared `MoneyFormatter` (resolve PriceFormatter: wire or delete); 404-vs-redirect contract for `GetCookieById` miss.
17. **Exception/error-code contract sweep**: User VOs → `ValidationException`+ErrorCodes (raw `\InvalidArgumentException` with code in wrong arg today); Shared kernel decision (SharedErrorCodes vs documented structural-invariant exemption); consolidate duplicate `Email` VOs into Shared.
18. **User domain: converge or quarantine (NEW epic E20)**: bring User onto AggregateRoot/version/locking/outbox/read-port — or move it to an `examples/legacy` quarantine and stamp Cookie as THE reference. Do not leave two contradictory references.
19. **Dead-code adjudication (NEW epic E21)**: StateMachine — wire Cookie lifecycle through it or delete; stock mutation path — add `AdjustCookieStock` command (through outbox) or delete mutators+event+handler; projections — registry demo with one real projection or stay `.example` with docs; collapse dual logging stacks (Monolog only); `CommandBus` idempotency seam decision (`idempotency_keys` exists at HTTP edge only).

### Phase R4 — P3 polish (existing E13–E18 as planned, scope-corrected)
20. E13 (DI/controller), E14 (views/i18n: pt-BR `Validation.php` missing entirely, lang() in cookie views), E15 (docs/skills regenerated from the POST-remediation tree — they currently describe code that doesn't exist), E16/E17 (PHP 8.4/idioms), E18 remainder (CookieStock test gap!, coverage close), identity-VO decision (CookieId — strategic, separate epic), command field naming (`id`+`actor` standard).

## Verification (every phase)

- `composer check` (docblocks, PHPCS, PHPStan L8, deptrac, PHPUnit ≥90%) + MySQL CI lane green.
- New named tests per P0/P1 item: in-tx outbox write, dispatch-count, concurrency (v5-vs-v6), cross-tenant isolation, JPY/BHD bounds, overflow multiply, restore-not-deleted, log-volume <1 MB/run.
- Re-run a focused re-audit (cheap, 1 agent) only after R1+R2 to confirm CRIT/HIGH closure before the first real ERP domain is cloned.

## Notes

- E09 scope correction: `CookiePrice` is ALREADY Money/minor-units-backed — E09 narrows to currency-aware bounds, required-currency factories, and the `DECIMAL→price_minor` schema migration (still destructive; rehearse on MySQL lane).
- The 2 slipped stragglers' partial transcripts and the failed synthesizer cost ~tokens but produced nothing; all value is in the 7 harvested reports.
