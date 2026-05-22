# Review (clean-code-specialist) — v2 Remediation Plan

## Verdict
APPROVED-WITH-CHANGES

The plan correctly identifies T2 (handler boilerplate) and T4 (DTO duality)
as the dominant clean-code defects, allocates every slice-14 F# I checked
to an epic, and sequences foundation→consumption correctly (E05 before
E08, E09 before E10/E11). However, three load-bearing clean-code questions
are under-specified: the 20-line ceiling proof for `handle()` after E05/E08,
the 200-line ceiling for `Cookie.php` itself, and the `'Cookie'`/`'cookie'`
hard-coded literal constants (theme T6) — currently buried inside E13.

---

## Strengths

- **T2 fully decomposed.** E05 introduces `AbstractCommandHandler` +
  `AbstractQueryHandler` + `QueryLoggingPolicy` + `LogSampler` separately
  from E08 (consumption), which is the correct order — bases must exist
  before consumers migrate. This is the cleanest cut for the 70-LoC-per-
  handler bloat called out in 14/F1–F3 and 14/F20–F21.
- **E08 acceptance gate is explicit:** "All 7 `handle()` methods ≤ 20
  lines" + "all four failure-log shapes identical" — clean-code measurable.
- **F2's `str_contains` defence-in-depth is killed** by allocating
  03/F4 + 14/F2 to E08, which says delete the resolver and rely on
  `$e->getErrorCode() !== 0` at the throw site.
- **F4 (ErrorCodes enum) is allocated twice** — once in E08's deps and
  once in E17 (`14/F4 (ErrorCodes → enum if not done in E08)`) — safe
  belt-and-braces.
- **F6 (586-LoC `CookieRepository`) is allocated to E11** with a hard
  numeric gate: `CookieRepository.php ≤ 250 LoC`, plus three extracted
  collaborators named (`CookieEntityMapper`, `CookieOptimisticLocker`,
  `CookieEventDrainer`). Excellent.
- **F10/F11 (`CookieView` dead code, null-id coercion) physically deleted**
  in E10 (`Delete: app/Domain/Cookie/ReadModels/CookieView.php +
  …/CookieViewTest.php`). Not deprecated — removed. Correct.

## Required changes

1. **Make E05's `handle()` template explicit.** The plan promises
   "every `handle()` ≤ 20 lines" but doesn't show the body. The
   `withLogging(string $commandName, array $context, callable $body)`
   sketch must show the distribution: `logStart()` private to the base
   (called inside `withLogging`'s prelude), `logSuccess()` private to the
   base (called in finally on success), `logFailure()` private to the
   base (called in the `catch (\Throwable)`). The concrete `handle()`
   becomes literally: validate VOs → `$this->withLogging('create.cookie',
   $ctx, fn () => $this->execute($command))`. Add a concrete worked
   example to the epic body — without it, a contributor can satisfy the
   "≤ 20 lines" gate by extracting 4× 10-line private helpers, which is
   not what the rule means.

2. **Cap `Cookie.php` (288 LoC) explicitly.** Slice 14 calls out
   `Entities/Cookie.php` at 288 LoC even after the `CookieAccessors`
   extraction. E07 adds `softDelete()`, `restore()`, two activation events
   — this **grows** the entity, not shrinks it. The plan must either
   (a) add a numeric LoC gate to E07's acceptance (e.g. `Cookie.php ≤ 250
   LoC` with explicit waiver), or (b) allocate F23 (Trait location) to
   E07 and split snapshot / state guards into `CookieStateAssertions` +
   `CookieSnapshot` value object (F7 already alludes to the snapshot VO).
   Right now F23 (INFO) is unallocated — grep `14/F23` in the matrix and
   confirm.

3. **Hoist the `'Cookie'`/`'cookie'` literal-to-constant work out of
   E13 into its own sub-task.** E13 bundles provider DI overhaul +
   `URI_SEGMENT` + `CONTROLLER_NAMESPACE` + auth filter + controller
   refactor + 18 files. The `BusinessMetricsLogging`/`RepositoryLogging`
   traits hard-code `'domain' => 'Cookie'` in 12+ payloads (T6 root-cause
   text). Moving those traits to `Domain/Shared/Logging/` and parameterising
   the domain string with a `protected const DOMAIN = 'Cookie';` override
   is its own concern; bundling it inside the HTTP/DI epic risks the
   logging change being a "while we're here" footnote with no gate.

## Missing items

- **No SRP risk check on the new bases.** `AbstractCommandHandler` is
  being asked to own: time measurement, logging start/success/failure,
  error-code resolution, slow-query escalation (E05 mentions this in
  the same class as the query base — re-read F1), exception rethrow
  semantics. Risk of "god middleware". The plan must clarify which
  responsibilities sit on the abstract handler vs on the middleware
  chain (`TransactionMiddleware`, `AuditMiddleware`). Otherwise the
  base will hit ~200 LoC itself.
- **`DTOs/` vs `ReadModels/` is unresolved.** E10 deletes `CookieView`
  from `ReadModels/` and keeps `CookieDTO` in `DTOs/`, but the slice-14
  text leaves the directory question open and the plan does not state
  whether the `ReadModels/` directory itself survives (empty) or is
  removed. Pick one: I recommend removing `ReadModels/` entirely and
  keeping `DTOs/` as the read-side home — name the convention in E15's
  scaffolding update.
- **F8 (`@deprecated` on a template) only partially closed.** E09 deletes
  `CookiePrice::getValue(): float` and `format()`; E10 moves `PriceFormatter`
  to Shared. Verify `CookieName::equalsIgnoreCase()` deprecation is
  removed in E09 (currently listed as 02/F3, 02/F7 closure) — but the
  plan's E09 text says "pick one equality semantics" without explicitly
  saying the `@deprecated` tag is gone. Tighten the acceptance.
- **F15 (placeholder docblocks)** allocated to E02 — good. But the audit
  regex (`\* (\w+)\.$\n\s*\*/`) is shown only in the E02 prose; promote
  it to a literal acceptance assertion in `bin/docblocks-audit` tests.
- **F12 (snake_case vs camelCase log keys)** allocated to E08 ("identical
  failure-log shape") and acknowledged in fixes #3 of slice 14's top-3.
  But the plan does not state which convention wins; `LOGGING_BEST_PRACTICES.md`
  says `snake_case`. Make E08 explicit: "snake_case everywhere, including
  `cookie_id`, `duration_ms`, `result_count`".
- **F17 (defensive `(int) $this->id` cast)** not allocated anywhere I
  could find. Slice 14 F17 → add `@phpstan-assert` on `assertPersisted`.
  Belongs in E07 (entity work) or E11 (repository hygiene).
- **F19 (double-clamping in Query DTO + Repository)** not in any epic
  closure list. Belongs in E08 (query handler migration owns query
  DTOs) or E11.

## Method/class size compliance audit

| Epic | Target file | Current LoC | Target LoC | How the epic gets there |
|---|---|---|---|---|
| E05 | `AbstractCommandHandler.php` (NEW) | n/a | ≤ 80 | New base; must police itself — see required change #1. |
| E08 | `CreateCookieHandler::handle` | 75 | ≤ 20 | Delegates to `withLogging(name,ctx,fn=>execute())`; `execute()` itself ≤ 20 with VO factories doing validation. |
| E08 | `UpdateCookieHandler::handle` | 94 | ≤ 20 | Same pattern; old-price snapshot extracted to `loadAndSnapshot()` private helper (still in handler, ≤ 20). |
| E08 | `DeleteCookieHandler::handle` | 72 | ≤ 20 | Same pattern + `hrtime` standardisation (F21). |
| E08 | `RestoreCookieHandler::handle` | 44 | ≤ 20 | Parity rewrite to mirror Delete; gains `determineErrorCode` from base. |
| E08 | `*PaginatedHandler::logQueryExecution` | 24 | n/a (deleted) | Moves to `QueryLoggingPolicy`; handler no longer carries it. |
| E07 | `Cookie.php` | 288 | ≤ 250 (waiver acceptable to 300) | Add `softDelete`/`restore`/`activate-event`/`deactivate-event` — actually GROWS; mitigate by extracting `CookieSnapshot` VO and moving state assertions. **See required change #2.** |
| E09 | `CookiePrice.php` | 224 | ≤ 200 | Remove `getValue(): float`, `format()`, `equalsIgnoreCase` deprecation aliases; bounds method generalises rather than duplicates per-currency. |
| E11 | `CookieRepository.php` | 586 | ≤ 250 | Extract `CookieEntityMapper` (~120), `CookieOptimisticLocker` (~100), `CookieEventDrainer` (~60). Gate is in the epic. |
| E11 | `CookieRepository::performSave` | 44 | ≤ 20 | Moves into `CookieOptimisticLocker`. |
| E11 | `CookieRepository::executeFindPaginated` | 46 | ≤ 20 | Stays in repo orchestrator after collaborator extraction; split read assembly into private `assemblePaginatedResult()`. |
| E11 | `CookieQueryRepository::findPaginated` | 53 | ≤ 20 | LIKE-escape helper + clamp removal (F19) reduces body; extract `applyFilters()` private. |
| E13 | `CookieServiceProvider.php` | 284 | ≤ 200 | Constructor injection deletes `setRepositories`/`getRepositories`/`getRepository` (~70 LoC); `URI_SEGMENT`/`CONTROLLER_NAMESPACE` constants; `registerEvents` receives logger via ctor. |

---

**Top 3 required changes** are summarised under "Required changes" above:
(1) make E05 worked example explicit; (2) cap `Cookie.php` LoC explicitly
in E07 (it grows); (3) hoist the `'Cookie'`/`'cookie'` literal-to-constant
work out of the E13 bundle.
