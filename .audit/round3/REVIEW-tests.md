# Review (test-specialist) — v2 Remediation Plan

## Verdict
APPROVED-WITH-CHANGES

The plan correctly identifies the 5 template-multiplying test defects
(SQLite force-lock, real-FS unit logging, content-blind feature tests,
hybrid CookieRepositoryTest, missing concurrency proof) and allocates
**every** slice 12/13 finding to a numbered epic. Phase 0 sequencing
(E01 → MySQL lane BEFORE E03/E09/E11/E12) is exactly right: nothing
downstream is verifiable otherwise. However four operational gaps must
land before the plan is execution-ready, and one acceptance gate as
written is mathematically incapable of detecting a regression.

## Strengths

- **E01 puts the SQLite force-removal on the critical path** — every
  optimistic-lock, NULL-vs-NULL, FOR UPDATE SKIP LOCKED claim downstream
  is gated on this. Correct topology.
- **E01 explicitly extracts the 8 mocked `createMock(CookieModel)`
  methods into `tests/Unit/.../CookieRepositoryErrorMappingTest.php`** —
  the right split. Also kills the class-level
  `#[AllowMockObjectsWithoutExpectations]` (13/F17).
- **E18 allocates all 14 missing-test entries from slice 13** plus the 4
  missing-VO/Service/Trait/ErrorCodes test files from 12/F2 — explicit
  file paths listed.
- **E18 adds a deptrac rule forbidding `LoggerFactory` import from
  `tests/Unit/`** (12/F1 lock-in). Structural fix, not a code-review
  whack-a-mole.
- **E04 forces `eventId` UUIDv7 + `occurredAt` on every event** —
  cascades to outbox idempotency anchor (E12) and unlocks the missing
  payload-immutability tests for Restored/StockChanged (12/missing-9).
- **Test-pyramid preservation:** Phase 1 foundation epics (E04/E05/E06)
  each list explicit `test_…` acceptance assertions in the same epic —
  unit tests land in the same PR as the new abstract bases, no
  coverage-cliff window.

## Required changes

1. **E01 omits the GitHub Actions matrix specifics and local
   docker-compose entirely.** The epic mentions
   `.github/workflows/mysql.yml` with a "MySQL 8 service container" but
   does not (a) pin the MySQL image+tag (`mysql:8.0.36`), (b) state
   whether the lane runs on every PR or nightly, (c) provide a local
   `docker-compose.test.yml` / `make test-mysql` so a contributor can
   reproduce CI failures, (d) define the matrix axes (`php: [8.3]` ×
   `db: [sqlite, mysql]`). Without (c) the lane is CI-only and every
   MySQL-only failure becomes a guess-and-push loop. **Action:** add a
   `docker-compose.test.yml` line item to E01 and a matrix block
   sketch to the workflow file; pin the MySQL image tag.

2. **E18's `test_outbox_skip_locked_under_real_concurrency`
   (parenthetical "pcntl_fork or MySQL-only fixture") is
   under-specified and will land as the same-connection sham the audit
   already flagged.** Two queries on the same PDO handle do NOT
   exercise SKIP LOCKED — InnoDB sees one transaction. The plan must
   commit to **either** (a) `pcntl_fork()` with two child processes
   each opening a fresh `\Config\Database::connect('tests', false)` and
   a barrier (file-lock or `usleep` after fork) so both BEGIN before
   either commits, **or** (b) `proc_open('php spark cookie:claim-one')`
   spawning two real CLI workers. Option (a) requires `pcntl`
   extension on the CI runner — verify the GH Actions PHP image has
   it (`shivammathur/setup-php` does by default on Linux). Option (b)
   needs a new Spark command. **Action:** pick one, document, add the
   extension requirement to E01.

3. **E18's logger-isolation fix is incomplete: `NullLogger` alone
   loses the assertions the audit demanded.** Slice 12/F3 calls for
   converting 11 `assertTrue(true)` smoke tests to "assert log message
   + context shape." A `NullLogger` swallows every record so those
   assertions cannot exist. The plan needs to distinguish (i) handlers
   that legitimately don't care about logs (use `createMock(...)` with
   no expectations or `NullLogger`) from (ii) handlers whose log
   contract IS the public surface (use `Monolog\Handler\TestHandler`
   pushed into a real Monolog `Logger`, then assert via
   `$handler->hasInfoThatContains(...)` / `getRecords()`). **Action:**
   E18 should specify `TestHandler` for the 11 event-handler tests
   (matching the pattern in `test_cookie_restored_handler_logs_with_audit_context`),
   not `NullLogger`. Otherwise 12/F3 is closed on paper but the
   contract is still untested.

4. **E18's acceptance gate `coverage ≥ 90 % on both [SQLite and
   MySQL]` is unenforceable without a coverage-merge step.** PCOV runs
   produce two separate clover reports; CI must merge them
   (`phpcov merge` or sum line-hits) before applying the gate, OR the
   gate must be specified per-lane with the union of files. As written
   the lane-with-skipped-MySQL-tests will report < 90 % on the
   integration files the other lane covers. **Action:** add `phpcov
   merge build/logs/ --clover build/logs/clover.xml` step + a
   `--min-coverage 90` enforcer (e.g.
   `php-coveralls`/`coverage-check`) to E01's workflow definition.

## Missing items

- **No allocation for `setlocale`/`mb_internal_encoding` test bootstrap
  pinning.** MoneyFormatter (E10) localises currency — if the MySQL CI
  container's locale differs from the SQLite developer host,
  `MoneyFormatterTest` will be flaky. Add to E10 acceptance: pin
  `setlocale(LC_ALL, 'C')` in `tests/bootstrap.php`.
- **`failOnRisky="true"` interaction with `createMock(...)` without
  expectations is not addressed for the new tests.** E18 adds new
  mocks (11 handler tests, 5 new unit tests); if any mock is declared
  but not invoked, the suite turns red. Verify each new test either
  uses `expects(...)` or `[AllowMockObjectsWithoutExpectations]` at
  the *method* level (not class — that's the very anti-pattern 13/F17
  flagged).
- **No mention of running `composer test` against the post-E09
  migration in `migrate:rollback` mode.** The destructive money-schema
  change must be exercised both forward and backward in CI — add a
  `php spark migrate --all && php spark migrate:rollback &&
  php spark migrate --all` step to E01 or E09 acceptance.
- **`tests/Support/IntegrationTestCase.php` `sessionVariables`
  verification (E03 gate `test_session_variables_pinned_at_connect`)
  needs an explicit allocation in the test pyramid** — it is an
  integration test, list it under E03's "Files touched" not implicitly.
- **13/F17 (class-level `AllowMockObjectsWithoutExpectations`) is
  closed by E01, but no corresponding rule prevents it returning** —
  add a PHPStan/Slevomat rule or grep in `composer ci` that fails on
  class-level usage of the attribute.

## Coverage-risk audit per epic

| Epic | New production lines (est.) | Required test additions | Regression risk |
|------|----------------------------|------------------------|-----------------|
| E01  | ~0 (infra)                  | None (moves 8 tests)    | LOW — pure relocation; coverage neutral |
| E02  | ~30 (bin/docblocks-audit regex) | Test the regex against placeholder + real docblocks | LOW |
| E03  | ~40 (Database.php config)    | `test_session_variables_pinned_at_connect` (listed) | LOW |
| E04  | ~120 (AbstractDomainEvent + EventId VO) | 4 assertions listed in epic | LOW — explicit |
| E05  | ~180 (Abstract handlers + bus + LogSampler) | 4 assertions listed | MED — bus rewiring; needs end-to-end smoke |
| E06  | ~60 (interfaces + hydrator key) | 3 assertions listed | LOW |
| E07  | ~80 (entity lifecycle + 2 events) | 4 assertions listed | MED — entity is most-imported file |
| E08  | -280 (NET REMOVAL via base adoption) | Existing handler tests must still pass | **HIGH** — coverage of removed lines vanishes; ensure the abstract base picks up the deleted branches |
| E09  | ~250 (schema + VO factories + seeder) | 8 assertions listed | **HIGH** — destructive; both lanes must run |
| E10  | ~150 (consolidated DTO + ReadDTOInterface) | 3 assertions listed | MED — `CookieView` deletion drops its test file; ensure no transitive coverage loss |
| E11  | -300+250 (repo split) | 5 assertions listed | MED — extraction; verify `CookieEntityMapper` etc. each have their own unit test (not implied in epic) |
| E12  | ~180 (migration + relay + writer) | 4 assertions listed | **HIGH** — outbox change; relay path needs MySQL-only test (see required-change 2) |
| E13  | ~200 (provider + controller refactor) | 4 assertions listed | MED — auth filter change; CookieCrudTest must still pass |
| E14  | ~50 (views) | 2 assertions listed | LOW |
| E15  | ~0 (docs) | docs:cookie-sync CI guard | LOW |
| E16  | ~80 (8.4 idiom adoptions) | Existing tests cover behaviour | LOW |
| E17  | ~50 (8.3 polish) | Existing tests | LOW |
| E18  | ~0 prod, ~+600 test LoC | All slice 12/13 missing tests | MED — flaky-test risk, see required-change 2/3 |

**Highest coverage-cliff window:** E08 (handler-base migration) — the
hand-written `try/catch/durationMs` blocks being deleted are currently
covered by handler tests, but those tests pass through the base after
migration, so coverage moves to the base. Ensure the base's own unit
test (E05) covers every branch BEFORE E08 lands.

**Lowest-risk gate violation:** E18's `tests/Unit/` deptrac rule will
break the moment a sloppy commit imports `LoggerFactory` — the
intended outcome. Lock it in early; do not defer.
