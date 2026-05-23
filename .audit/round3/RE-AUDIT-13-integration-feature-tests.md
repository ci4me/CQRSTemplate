# RE-AUDIT 13 — Integration & Feature Tests (Round 3)

**Original audit:** `.audit/round3/13-integration-feature-tests.md` (17 findings: F1..F17 + 14 "Missing tests")
**Reviewer:** test-specialist
**Date:** 2026-05-23
**Local integration branch:** `integration/phase-1-cookie-foundation` (carries E04 + E05 + E06 + E07 only)
**PRs claimed to land between rounds:**
- **PR #30 — E01** (`epic/e01-mysql-ci-lane`): drops `force="true"` from `phpunit.xml.dist`, adds MySQL 8.0.36 CI matrix axis, `docker-compose.yml`, `Makefile`, `phpcov merge` gate, splits `CookieRepositoryTest` mock-only methods into new `tests/Unit/Domain/Cookie/Repositories/CookieRepositoryErrorMappingTest.php`, removes class-level `#[AllowMockObjectsWithoutExpectations]`. **State: OPEN** (not merged).
- **PR #31 — E03** (`epic/e03-mysql-connection-envelope`): MySQL `sessionVariables` envelope (`sql_mode`, isolation, charset), new `App\Infrastructure\Database\MySQLi\Connection`, `DBCollat` alignment to `utf8mb4_unicode_ci`, new `tests/Integration/Database/MySQLi/ConnectionEnvelopeTest.php`. **State: OPEN** (not merged).
- **PR #42 — E18 (partial)** (`epic/e18-coverage-close`): removes `sleep(1)` from `test_find_paginated_orders_by_created_at_desc` and stamps `created_at` via raw DB update. **State: OPEN** (not merged).

## TL;DR

**Three PRs would close F1 + F2 + F6 + F17 (the two CRITICALs and one HIGH and one INFO) on merge, but none are merged.** The local integration branch on `integration/phase-1-cookie-foundation` is byte-identical to round 3 for every test file in this slice: `phpunit.xml.dist:67` still carries `force="true"` on `database.tests.DBDriver=SQLite3`; `CookieRepositoryTest` is still 842 lines with the class-level `#[AllowMockObjectsWithoutExpectations]` attribute (line 19) and eight `createMock(CookieModel)` methods at lines 648..791; `sleep(1)` is still on `CookieRepositoryTest.php:358`. The MySQL CI lane is fully designed but unexercised because the workflow file itself is gated behind PR #30. Every "Missing tests" item from round 3 is also untouched: no idempotent-resave test, no FK-CASCADE assertion, no `pcntl_fork` real concurrency test, no NULL-vs-NULL UNIQUE assertion, no CSRF rejection in production-mode, no role-gate test. Treated as a verdict shift, the round-3 NOT-READY stands.

## Verdict
**NOT-READY** (unchanged from round 3)

If PR #30 + PR #31 + PR #42 all merge to `stabilization/erp-foundation` and then forward-merge into this branch, the verdict shifts to **READY-WITH-FIXES** — but the remaining HIGH items (F4 view-path assertions in `CookieCrudTest`, F5 bypassed auth fixture, F7 pagination edge cases on the write side) and the deferred MySQL-engine-specific test slices still keep the slice short of READY.

## Per-finding status

### F1 — CRITICAL — phpunit.xml.dist hard-locks SQLite via `force="true"`
- **Status:** **OPEN locally / CLOSED in PR #30** (PR not yet merged into integration branch).
- **Evidence (local):** `phpunit.xml.dist:67` still reads
  `<env name="database.tests.DBDriver" value="SQLite3" force="true"/>` plus six adjacent `database.tests.*` lines all carrying `force="true"`.
- **Evidence (PR #30 diff):** drops `force="true"` on lines 63-69, keeps the same default values, adds a 10-line comment block tagging the round-3 finding IDs (13/F1, 18/F-T1, 18/F-T2). The MySQL lane override in `.github/workflows/ci.yml` writes `database.tests.*` into both `.env` and `$GITHUB_ENV` so PHPUnit's `<env>` block picks them up. Diff is correct and minimal.
- **Residual risk on merge:** zero — the SQLite default is preserved for local `composer test`, the override takes effect under CI MySQL lane, and the round-3 audit's exact fix is applied.
- **Residual risk pre-merge:** unchanged. Every concurrency / collation / NULL-vs-NULL assertion still runs against the wrong engine on every contributor's machine and on every push that lands before PR #30.

### F2 — CRITICAL — `CookieRepositoryTest` is a hybrid integration/unit file with class-level `AllowMockObjectsWithoutExpectations`
- **Status:** **OPEN locally / CLOSED in PR #30** (PR not yet merged).
- **Evidence (local):** `CookieRepositoryTest.php` still 842 lines. Class-level `#[AllowMockObjectsWithoutExpectations]` at line 19. Eight `createMock(CookieModel::class)` methods between lines 648 and 791 (`test_save_translates_duplicate_key_database_exception_into_domain_exception`, `test_save_rethrows_non_duplicate_database_exception`, `test_find_all_logs_and_rethrows_when_builder_throws`, `test_find_paginated_logs_and_rethrows_when_builder_throws`, `test_restore_logs_and_rethrows_when_model_throws`, `test_find_by_id_logs_and_rethrows_when_model_throws`, `test_delete_logs_and_rethrows_when_model_throws`, `test_save_rethrows_unknown_throwable_from_model`) — all still rolled into the IntegrationTestCase-extending class.
- **Evidence (PR #30 diff):** removes the class-level attribute and the eight methods from the integration file (-176 lines), creates a new `tests/Unit/Domain/Cookie/Repositories/CookieRepositoryErrorMappingTest.php` (+211 lines) extending `UnitTestCase`, with per-method `#[AllowMockObjectsWithoutExpectations]` scoping. The new file's docblock explicitly cites round-3 13/F2 + 13/F17 as the closure target.
- **Residual risk on merge:** zero — exactly the fix the round-3 audit prescribed.
- **Residual risk pre-merge:** unchanged. Clones inherit the dual-shape file plus the class-wide risky-test opt-out.

### F3 — HIGH — Composite UNIQUE `(tenant_id, name, deleted_at)` is never exercised at DB level
- **Status:** **OPEN** (not addressed by any in-flight PR).
- **Evidence:** No `test_unique_name_within_tenant_*` or `test_soft_delete_then_recreate_same_name` integration test exists. Neither PR #30 nor PR #31 nor PR #42 adds one. Round-3 follow-up plan documents this is **DEFERRED** — both the engine swap (E01) and the connection envelope (E03) are prerequisites for a meaningful NULL-vs-NULL DB-layer assertion under MySQL.
- **Residual risk:** unchanged. The composite UNIQUE remains decorative; the recreate-after-soft-delete contract from migration B16/B17 has no test backing.

### F4 — HIGH — Feature tests assert on the view-path string `cookies/index` instead of rendered content
- **Status:** **OPEN** (not addressed).
- **Evidence:** `tests/Feature/Cookie/CookieCrudTest.php` is 439 lines and still ships with `assertSee('cookies/index')` etc. at lines 21, 71, 195, 218. The contrast file `CookieQueryE2ETest.php` (111 lines) still uses content assertions correctly. No PR in the inbox touches `CookieCrudTest`.
- **Residual risk:** unchanged. Two contradictory feature-test shapes still coexist; `CookieCrudTest` is the larger file and the more likely sed-clone target.

### F5 — HIGH — `loginAsAdmin` bypasses real auth flow; no `loginAsCustomer` exists
- **Status:** **OPEN** (not addressed).
- **Evidence:** `tests/Support/FeatureTestCase.php` unchanged on the integration branch. PR #30 introduces a `loginAndExtractAccessToken()` helper for JWT in `Tests\Feature\Api\UserApiControllerTest` but does **not** rewrite the session-based `loginAsAdmin` / `seedActiveAdminUser` helpers that every Cookie feature test depends on. Argon2id bogus-hash issue persists.
- **Residual risk:** unchanged. `POST /auth/login` still unreachable through any Cookie feature test; no role-rejection precedent for clones.

### F6 — HIGH — `test_find_paginated_orders_by_created_at_desc` uses `sleep(1)`
- **Status:** **OPEN locally / CLOSED in PR #42** (PR not yet merged).
- **Evidence (local):** `tests/Integration/Repositories/CookieRepositoryTest.php:358` still reads `sleep(1); // Ensure different timestamps`.
- **Evidence (PR #42 diff):** removes the `sleep(1)` and replaces with two `$db->table('cookies')->where('id', $id)->update(['created_at' => ...])` calls stamping deterministic timestamps one second apart. Six-line comment block tags 13/F6 as the closure target. Diff is minimal and correct.
- **Residual risk on merge:** zero — the fix matches the audit's exact suggestion ("stamp the rows directly in the DB after insert").
- **Residual risk pre-merge:** unchanged. Every contributor on `integration/*` still pays the 1-second tax per `composer test` run.

### F7 — HIGH — Pagination edge cases NOT covered on the write side
- **Status:** **OPEN** (not addressed).
- **Evidence:** `CookieRepositoryTest::test_find_paginated_*` block (lines 272-380) unchanged on integration branch. No `page=99` beyond-last, `page=-1`, `perPage=0`, `perPage=999999` tests added on the write side. Read-side `CookieQueryRepositoryTest::test_pagination_clamps_*` cases still in place; asymmetry persists.
- **Residual risk:** unchanged. Two repositories on the same aggregate have different test coverage of the same contract.

### F8 — MEDIUM — `IntegrationTestCase::setUp()` eagerly constructs `CookieRepository` for every domain
- **Status:** **OPEN** (not addressed).
- **Evidence:** `tests/Support/IntegrationTestCase.php` and `tests/Support/FeatureTestCase.php` not touched by any of PR #30, #31, #42. The `$cookieRepository` property + eager construction lives in the shared base. Every Integration test for `Auth`, `Numbering`, `Storage`, etc. still constructs an unused CookieRepository.
- **Residual risk:** unchanged. Constructor-signature change on `CookieRepository` continues to fan out across the entire integration suite.

### F9 — MEDIUM — Tenant filtering integration test bypasses repository with raw inserts
- **Status:** **OPEN** (not addressed).
- **Evidence:** `tests/Integration/Repositories/CookieQueryRepositoryTest.php:158-195` and `:141-149` unchanged. No `CookieFactory::createForTenant()` helper added.
- **Residual risk:** unchanged.

### F10 — MEDIUM — `assertFlashMessage('error')` without specific message — wrong-error-still-passes
- **Status:** **OPEN** (not addressed).
- **Evidence:** `CookieCrudTest.php` lines 128, 148, 164, 180, 292, 313, 348, 372 still call `assertFlashMessage('error')` with no second-argument message pin. None of the in-flight PRs touches this file.
- **Residual risk:** unchanged. Combined with F2's mock-bypass concession, the duplicate-key assertion is doubly fuzzy: the mocked unit test only proves the mapping, and the feature test doesn't pin the message string.

### F11 — MEDIUM — `test_complete_create_update_delete_journey` is a 50-line monolith
- **Status:** **OPEN** (not addressed).
- **Evidence:** `CookieCrudTest.php:379-425` unchanged. `$cookie = $this->cookieRepository->findAll()[0];` (line 396) still couples the test to internal ordering.
- **Residual risk:** unchanged.

### F12 — LOW — `test_index_route_supports_explicit_page_parameter` asserts only `assertOK()` on both pages
- **Status:** **OPEN** (not addressed).
- **Evidence:** `CookieQueryE2ETest.php:54-68` unchanged. No content pin on the page-1-vs-page-2 boundary.
- **Residual risk:** unchanged.

### F13 — LOW — `test_save_updates_only_changed_fields` is misnamed; always renames
- **Status:** **OPEN** (not addressed).
- **Evidence:** `CookieRepositoryTest.php:135-155` unchanged. The "save with identical payload" path — the exact MySQL `affectedRows()` no-op pitfall called out by r07 §1.1 — still has no regression test. Note: this test will be exercisable for real once F1 closes via PR #30 + #31 (the MySQL lane is what makes the `affectedRows()` divergence visible); the test itself remains missing.
- **Residual risk:** unchanged.

### F14 — LOW — `CookieOptimisticLockingTest::test_concurrent_modification_preserves_winners_write` uses bare `catch (DomainException)`
- **Status:** **OPEN** (not addressed).
- **Evidence:** `tests/Integration/Repositories/CookieOptimisticLockingTest.php:134-138` unchanged. No `expectExceptionMessage` / `str_contains` narrowing on the catch.
- **Residual risk:** unchanged.

### F15 — LOW — CSRF silently disabled in test env; no test asserts CSRF rejection
- **Status:** **OPEN** (not addressed).
- **Evidence:** `app/Config/Filters.php:95` unchanged; no `test_post_without_csrf_token_is_rejected_in_production_mode` in `CookieCrudTest`.
- **Residual risk:** unchanged.

### F16 — INFO — Hard-coded test data won't sed-clone cleanly
- **Status:** **OPEN** (not addressed).
- **Evidence:** `tests/Support/Factories/CookieFactory.php:14-43` still hard-codes `'Chocolate Chip'` etc. as defaults. PR #42 touches `CookieFactory.php` for other E18 reasons but the defaults are unchanged.
- **Residual risk:** unchanged. Cloned factories will inherit absurd defaults.

### F17 — INFO — Class-level `#[AllowMockObjectsWithoutExpectations]` on `CookieRepositoryTest`
- **Status:** **OPEN locally / CLOSED in PR #30** (PR not yet merged).
- **Evidence (local):** still present at `CookieRepositoryTest.php:15, 19`.
- **Evidence (PR #30 diff):** removes both the `use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;` import and the class-level attribute, scoping the attribute per-method only on the new unit-test class. Exact fix prescribed by the audit.
- **Residual risk on merge:** zero.
- **Residual risk pre-merge:** unchanged.

## Missing tests — status

Round 3 listed 14 missing real-DB / E2E scenarios. None landed; the deferral rationale is documented in `.audit/round3/REMEDIATION-PLAN.md` (E01/E03 are prerequisites, real-concurrency tests are scheduled as a follow-up).

| # | Scenario | Status | Notes |
|---|----------|--------|-------|
| 1 | Idempotent re-save (`affectedRows()` no-op) | OPEN | DEFERRED — requires MySQL lane (PR #30) to be authoritative. |
| 2 | Soft-delete + recreate same name at DB-constraint layer | OPEN | DEFERRED — requires MySQL lane + NOT-NULL `tenant_id` or functional index. |
| 3 | Restore-conflict (cookie A soft-deleted, B same name created, restore A) | OPEN | Production bug uncovered by audit remains untested. |
| 4 | Tenant isolation on write side | OPEN | Read side covered; write side needs `CookieFactory::createForTenant`. |
| 5 | Pagination beyond last page (`page=99` when `lastPage=3`) | OPEN | Read side already has it; write side asymmetric. |
| 6 | `perPage=0` / `page=-1` clamp on write side | OPEN | Same asymmetry as #5. |
| 7 | Empty body POST to `/cookies` | OPEN | |
| 8 | `pcntl_fork` real concurrency race | OPEN | DEFERRED — `pcntl` extension is now enabled in CI per PR #30 ("E18's concurrency tests, forthcoming"); test file does not yet exist. |
| 9 | `existsByNameExcludingId` rename-back-to-own-old-name | OPEN | |
| 10 | CSRF rejection on POST in production-mode | OPEN | |
| 11 | Anonymous visitor redirect on `GET /cookies` | OPEN | |
| 12 | Auth-layer role gate (`role:admin` rejection) | OPEN | Blocked by F5 (no `loginAsCustomer` helper). |
| 13 | JSON / XML response on `/cookies/:id` | OPEN | |
| 14 | Migration roundtrip (migrate → rollback → migrate) | **PARTIAL** | PR #30 adds `php spark migrate --all && migrate:rollback -all && migrate --all` as a CI step on the MySQL lane (only). Not a PHPUnit test; not exercised on the SQLite lane; not covered locally. |

MySQL-only collation, UNIQUE-NULL, and FK CASCADE tests are explicitly **DEFERRED** to a follow-up beyond E01/E03 per the remediation plan; they will be unblocked once the MySQL CI lane is authoritative.

## MySQL CI lane status

**Lane is fully designed in PR #30 but not yet exercised by a merged PR.** Summary of what PR #30 ships:

- `.github/workflows/ci.yml` matrix axis `db: [sqlite, mysql]` on PHP 8.4, with MySQL pinned to `mysql:8.0.36` (image tag pinned per round-3 REVIEW-tests change #1).
- `pcntl` PHP extension added to the runner so future fork-based concurrency tests can land (audit "Missing tests" #8 prerequisite).
- Per-lane coverage artifacts (`coverage-php8.4-{sqlite,mysql}`) plus a downstream `coverage-merge` job that runs `phpcov merge` and applies the `>= 90%` gate to the **union** of both lanes (per REVIEW-tests change #4). Per-lane gate would be unsatisfiable because lane-skipped tests drop integration files below threshold on the other lane.
- Local reproduction via `make test-mysql` → `docker-compose up -d mysql` with healthcheck wait + automatic teardown on Ctrl-C. Compose project name `ci4me-test`, host port `33060`.
- New workflow `README.md` cross-references every closed audit finding (`13/F1`, `13/F2`, `13/F17`, `18/F-T1`, `18/F-T2`) and the local-reproduction path.

**Caveats observed:**
1. The MySQL lane has never executed a PR-driven build because PR #30 itself is the workflow change — the new lane will only run starting from the merge of #30. No green run exists yet to certify that the MySQL `database.tests.*` env overrides actually take effect in practice.
2. PR #31's `App\Infrastructure\Database\MySQLi\Connection` envelope (`sessionVariables`) is gated on `DBDriver = 'App\\Infrastructure\\Database\\MySQLi'`. If PR #30 lands without #31, the MySQL CI lane runs under stock `MySQLi` driver and the `sql_mode` / isolation / timezone pins are silently inert — every "strict mode" claim in the documentation is unverified. Round 3 already noted these PRs should land together; the integration branch's order today is "neither has landed."
3. PR #30 alone cannot detect the MySQL `affectedRows()` no-op pitfall (F13's missing companion test) because the test asserting idempotent re-save does not exist. Closing F1 is necessary but not sufficient.
4. The `migrate → rollback → migrate` smoke step in PR #30 runs only on the MySQL lane and is a `php spark` invocation rather than a PHPUnit test, so it neither contributes to coverage nor produces a structured failure surface for a forward-rollback schema asymmetry — audit "Missing tests" #14 is only partially closed.

## Side-effects observed from PRs that DID land on the integration branch (E04 + E05 + E06 + E07)

None affect the integration/feature test surface analysed in this slice:

- E04 (`AbstractDomainEvent` + `CookieChangeSet`) — handlers now construct events with `eventId` / `occurredAt` / `actorId`; integration tests already use `CookieFactory` + `pullEvents()` flow so no test churn was needed. The `CookieRepositoryTest::test_save_drains_pending_events_to_injected_dispatcher` integration test (file lines 589-628) continues to pass against the new envelope.
- E05 (typed `CommandHandlerInterface` / `QueryHandlerInterface`) — handler signatures changed to `handle(object $command)`; no integration / feature test calls handlers directly (they all go through the bus or the controller), so no test churn.
- E06 (`AggregateHydrator::key()` + `AggregateRootInterface`) — repository's `assignId()` / `bumpVersion()` use is internal; no test asserts on the hydrator key. **No regression introduced.**
- E07 (entity owns lifecycle: `softDelete` / `restore` / `activate` / `deactivate` raise events) — handlers now drain `$cookie->pullEvents()` rather than constructing events directly. `CookieOptimisticLockingTest` and `CookieRepositoryTest` already exercise the persistence path; the entity-emits-event path is verified by unit tests in `tests/Unit/Domain/Cookie/Entities/`. **No regression introduced** in the integration / feature suites.

## Closure summary

| Severity | Total | Closed (merged) | Closed-pending (PR open) | Open | Notes |
|----------|------:|----------------:|-------------------------:|-----:|-------|
| CRITICAL |     2 |               0 |                        2 |    0 | F1 (PR #30), F2 (PR #30). |
| HIGH     |     5 |               0 |                        1 |    4 | F6 (PR #42); F3, F4, F5, F7 untouched. |
| MEDIUM   |     3 |               0 |                        0 |    3 | F8, F9, F10 untouched. |
| LOW      |     5 |               0 |                        0 |    5 | F11..F15 untouched. |
| INFO     |     2 |               0 |                        1 |    1 | F17 (PR #30); F16 untouched. |
| **Total**| **17**|           **0** |                    **4** |**13**| Three OPEN PRs would close 4/17 on merge. |

| Missing tests | Total | Closed | Partial | Deferred | Open |
|---------------|------:|-------:|--------:|---------:|-----:|
| (round-3 list)|    14 |      0 |       1 |        3 |   10 | #14 partial (PR #30 spark-only smoke); #1, #2, #8 deferred to follow-ups. |

## Verdict shift
Round 3: **NOT-READY** (suite is structurally SQLite-only; hybrid integration/unit file; sleep tax; missing concurrency + UNIQUE-NULL coverage).
Round 3 re-audit: **NOT-READY** (unchanged — 0/17 closed on the integration branch; 4/17 would close on PR #30 + #42 merge; F3 + F4 + F5 + F7 + the 10 still-open Missing tests remain).

## Biggest residual

**F4 + F5 together — feature tests rest on path-string assertions plus a bogus admin auth fixture that cannot exercise role rejection, and no in-flight PR addresses either.** The two CRITICALs (F1, F2) and F6 are well-targeted by open PRs and will close on merge; F3 / "missing tests" are honestly deferred behind the MySQL lane. But F4 (assert `cookies/index` view path instead of rendered content) and F5 (`seedActiveAdminUser` writes a bogus argon2id hash; no `loginAsCustomer`) are the patterns that *every* cloned domain will copy on day one, and neither is on any in-flight branch. A clone author reading `CookieCrudTest` today gets a 439-line file that asserts on view paths and never proves the role gate works — and that's the file that survives the next forward-merge intact.
