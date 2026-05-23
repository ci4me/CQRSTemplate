# RE-AUDIT 09 — Cookie Controller & HTTP Layer (Round 3)

**Slice:** CookieController + routes + filters + BaseController
**Reviewer:** codeigniter4-specialist
**Date:** 2026-05-23
**Branch:** `stabilization/erp-foundation`
**Prior audit:** `.audit/round3/09-controller-http.md` (15 findings; CRITICAL 1 / HIGH 3 / MEDIUM 5 / LOW 5 / INFO 1)
**Source files re-reviewed:** 5 (`CookieController.php`, `BaseController.php`, `Routes.php`, `Filters.php`, `CookieServiceProvider.php::registerRoutes`)
**PRs since prior:** #36 (E08 — controller call-site updates: `expectedVersion`, restore `$id`, Actor in controllers), #39 (E17 — `final` on `CookieController`)

## TL;DR

E13 (Provider DI + HTTP/auth + Controller refactor) has NOT shipped on `stabilization/erp-foundation`. PRs #36 and #39 are also NOT merged onto this branch — `5a1cdc0 refactor(cookie): mark CookieController final` lives only on `epic/e17-php83-idiom-polish`, and the controller still reads `class CookieController extends BaseController` (line 40, no `final`). The E08 call-site updates likewise have not landed: `CookieController::update()` constructs `UpdateCookieCommand` WITHOUT passing `expectedVersion` (line 212–220), and there is no `restore()` action at all in the controller (so the "`$id` for restore" change is moot at the HTTP layer — the route group does not expose restore). Net effect on Round 3 verdict: **no findings closed**; one new finding (F16) is added because the controller silently disables the optimistic-lock path that the new `UpdateCookieCommand` contract advertises.

## Verdict
**READY-WITH-FIXES** (unchanged from prior; CRITICAL F1 still open, blocking)

## Findings status

### F1 — CRITICAL — Cookie route group has no route-level auth filter — **OPEN**
- **Evidence:** `app/Domain/Cookie/CookieServiceProvider.php:252–263` — `registerRoutes()` still calls `$routes->group('cookies', ['namespace' => 'App\Controllers\Domain\Cookie'], ...)` with NO `filter` key. Authentication is still bolted on via the URI deny-list at `app/Config/Filters.php:140–148`.
- **Why open:** E13 (Provider DI + HTTP/auth + Controller refactor) has not shipped. The cross-file fragility is unchanged: a developer who copies `CookieServiceProvider` to `FooServiceProvider` and forgets to add `foos`, `foos/*` to `Filters.php::$filters['web_auth']['before']` ships an anonymous-by-default route group.
- **Action:** unchanged — attach `'filter' => 'web_auth'` (or `'web_auth,permission:cookie.view'`) inside the route group; drop `cookies/*` from `Filters.php`; document in `domain-scaffolding`.

### F2 — HIGH — No generic `Throwable` catch — **OPEN**
- **Evidence:** `CookieController.php:149–158` (store), `196–235` (update), `259–262` (delete). Each write action still catches only `ValidationException` and `DomainException`. A `RuntimeException` / `TypeError` / `\PDOException` from a handler still bubbles to CI4's exception renderer.
- **Why open:** No change to the controller body since the prior audit; the `try/catch` blocks are byte-identical to the snippet quoted in the round-3 review.
- **Action:** unchanged — add `catch (\Throwable $e)` after the existing catches, log via injected logger with correlation id, redirect with a fixed user-facing message (no `$e->getMessage()` leak).

### F3 — HIGH — Service-locator pattern (no constructor injection) — **OPEN**
- **Evidence:** Nine `Services::queryBus()` / `Services::commandBus()` / `Services::actorResolver()` call-sites confirmed: lines 51, 84, 118, 141, 171, 194, 219, 248, 253. No `__construct` method in `CookieController`.
- **Why open:** E13 territory — refactor not started on `stabilization/erp-foundation`.
- **Action:** unchanged — refactor to `__construct(private CommandBus $commandBus, private QueryBus $queryBus, private ActorResolver $actorResolver, private LoggerInterface $logger)`; remove per-action service lookups.

### F4 — HIGH — `(bool) $isActiveParam` permissive coercion — **OPEN**
- **Evidence:** `CookieController.php:133–134` (store) and `209–210` (update) still read `$isActive = (bool) $isActiveParam;`. JSON clients sending `"is_active": "false"` will see `true`.
- **Why open:** No change. Same fragile pattern; F-related E17 polish only added `final` (and that didn't land here either).
- **Action:** unchanged — switch to `filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false` or a shared `bool_param()` helper.

### F5 — MEDIUM — `CookieController` is not `final` — **OPEN (NOT CLOSED BY E17)**
- **Evidence:** `CookieController.php:40` reads `class CookieController extends BaseController` — no `final`. PR #39's commit `5a1cdc0 refactor(cookie): mark CookieController final per project default` exists ONLY on `epic/e17-php83-idiom-polish` (verified via `git branch --contains 5a1cdc0`). The branch has not been merged into `stabilization/erp-foundation`.
- **Why open:** The prior re-audit brief stated "F4 (non-final class): CLOSED by E17 — verify" — verification shows it is **NOT closed on this branch**. PR #39 is staged but unmerged.
- **Action:** unchanged — add `final` keyword (preferably as part of E17 merge into `stabilization/erp-foundation`).

### F6 — MEDIUM — `index` hard-codes `perPage: 20`, no clamp on `page` — **OPEN**
- **Evidence:** `CookieController.php:53–63` — `$page = is_numeric($pageParam) ? (int) $pageParam : 1;` (no `max(1, …)`); `perPage: 20` literal in `GetCookiesPaginatedQuery` constructor; no `per_page` GET parameter parsed.
- **Why open:** No change.
- **Action:** unchanged — clamp `$page = max(1, …)` and accept/clamp `per_page` between 1 and 100.

### F7 — MEDIUM — `Services::actorResolver()->resolve()` silently returns `Actor::system()` for anonymous callers — **OPEN**
- **Evidence:** `CookieController.php:141, 219, 253` still call `Services::actorResolver()->resolve($this->request)` without any `isAuthenticated()` assertion. Combined with F1's open status, this is the silent-anonymous-attribution failure mode flagged in round 2 `r10-http-api.md:68`.
- **Why open:** No change to controller, and the upstream `ActorResolver::resolve()` policy is unchanged.
- **Action:** unchanged — switch to `resolveOrFail()` (preferred) or assert `$actor->isAuthenticated()` before dispatch.

### F8 — MEDIUM — `redirect()->back()` with no fallback target — **OPEN**
- **Evidence:** Five bare `redirect()->back()` calls: lines 150, 155, 227, 232, 260. No second-argument fallback.
- **Why open:** No change.
- **Action:** unchanged — pass explicit fallback (`redirect()->back('/cookies')`).

### F9 — MEDIUM — `POST /cookies/{id}/delete` convention undocumented — **OPEN**
- **Evidence:** `CookieServiceProvider.php:260, 261` — `$routes->post('(:num)', ...)` for update; `$routes->post('(:num)/delete', ...)` for delete. No inline comment explaining the HTML-form rationale; no sibling API controller.
- **Why open:** No change to provider's `registerRoutes()` body.
- **Action:** unchanged — add inline comment and ship a `Api/CookieApiController` reference.

### F10 — LOW — Namespace vs URI stem mismatch undocumented — **OPEN**
- **Evidence:** `CookieController.php` namespace `App\Controllers\Domain\Cookie`; URI mount `cookies/` (provider line 254). No change.
- **Why open:** No change.
- **Action:** unchanged — document the dual-stem requirement in `domain-scaffolding`.

### F11 — LOW — `show()`/`edit()` swallow "not found" via 302 redirect — **OPEN**
- **Evidence:** `CookieController.php:89–92` (show) and `176–179` (edit) still `return redirect()->to('/cookies')->with('error', …)` instead of throwing `PageNotFoundException`. No change.
- **Why open:** No change.
- **Action:** unchanged — throw `\CodeIgniter\Exceptions\PageNotFoundException`.

### F12 — LOW — `is_string ? : ''` price fallback hides type confusion — **OPEN**
- **Evidence:** `CookieController.php:127–128` (store) and `203–204` (update). No change.
- **Why open:** No change.
- **Action:** unchanged — use `(string) ($priceParam ?? '')` with `is_scalar` guard.

### F13 — LOW — No `permission:` filter on Cookie routes — **OPEN**
- **Evidence:** `CookieServiceProvider.php:254` — route group registers no filter at all (depends on F1's deny-list, which itself does not chain `permission:`). `Filters.php:51` still aliases `permission` but no Cookie route uses it.
- **Why open:** No change.
- **Action:** unchanged — add a commented `'filter' => 'permission:cookie.manage'` reference.

### F14 — LOW — `delete()` does not catch `ValidationException` — **OPEN**
- **Evidence:** `CookieController.php:250–262` still catches only `DomainException`. No change.
- **Why open:** No change.
- **Action:** unchanged — mirror `store`/`update` catch order.

### F15 — INFO — `BaseController::initController` preload comments are dead weight — **OPEN**
- **Evidence:** `app/Controllers/BaseController.php:55–60` — comments `// Preload any models, libraries, etc, here.` and `// E.g.: $this->session = service('session');` still present.
- **Why open:** No change.
- **Action:** unchanged — remove or replace with a "use constructor injection" hint.

## New findings (Round 3 re-audit)

### F16 — HIGH (NEW) — Controller silently disables optimistic-lock path on update
- **Location:** `app/Controllers/Domain/Cookie/CookieController.php:212–220`
- **Evidence:** `UpdateCookieCommand` (`app/Domain/Cookie/Commands/UpdateCookie/UpdateCookieCommand.php:27–43`) accepts an optional `?int $expectedVersion = null` whose docblock states: "If `expectedVersion` is null (legacy callers), the handler skips the pre-flight check and relies on the repository's WHERE version=? UPDATE to detect concurrent modification." The controller's `update()` call site (line 212) never reads `$this->request->getPost('version')` and never passes `expectedVersion`. Every HTTP update therefore takes the legacy/slow path.
- **Why this is a template defect:** Optimistic concurrency control is half-implemented at the HTTP layer. The reference domain advertises the `expectedVersion` contract in the command DTO but never exercises it from the only client that exists (the HTML controller). Cloned domains will inherit "we have OCC but we never use it from the UI" — defeating the point of E08's command-DTO tightening (commit `f3c1767 refactor(cookie): tighten command/query DTOs + bring controller to parity` exists on `epic/e08-tx-occ-actor` but not here either).
- **Suggested fix:**
  1. Render a hidden `<input type="hidden" name="version" value="{{ cookie.version }}">` in the edit view (touch slice 12 — Views, not this slice).
  2. In `CookieController::update()`, parse it: `$versionParam = $this->request->getPost('version'); $expectedVersion = is_numeric($versionParam) ? (int) $versionParam : null;`
  3. Pass it: `new UpdateCookieCommand(..., expectedVersion: $expectedVersion)`.
- **Severity rationale:** HIGH because (a) it nullifies an advertised correctness guarantee, (b) cloned domains will copy the disabled pattern, and (c) the fix is small and bounded.

## Verification of PR-touched call-sites (E08 / E36)

- **`$id` for restore:** No `restore()` action exists in `CookieController`. The provider's route group (`CookieServiceProvider::registerRoutes`, lines 254–262) registers only `index/create/store/show/edit/update/delete`. No restore route, no restore controller method. PR #36's "controller passes `$id` for restore" change has no application surface in this slice — it must have targeted a different controller (or only landed in the command-handler test harness).
- **`expectedVersion` for update:** Not passed by controller. See F16.
- **Actor in controllers:** Present at lines 141 (`createdBy`), 219 (`updatedBy`), 253 (`deletedBy`). This part of the E08 change set IS reflected on `stabilization/erp-foundation`. Confirmed.

## What is correct / praiseworthy (unchanged)

The strengths catalogued in the prior audit still hold: thin actions, strict typing, explicit pre-cast type-checks, redirect-after-POST, `withInput()` + flashed `errors`, surfaced `ValidationException::getErrors()`, global CSRF + correlation + locale, bus indirection via `Services::commandBus()`/`queryBus()`. Actor attribution is reaching the command DTOs as designed.

## Top 3 fixes before cloning (re-prioritised after re-audit)

1. **Ship E13** — wire `web_auth` (and `permission:cookie.manage`) directly on the route group, drop the `Filters.php` URI deny-list for `cookies/*`. Closes F1 (CRITICAL), enables F13 fix. Single change defends every cloned domain by default.
2. **Refactor to constructor injection + add `catch (\Throwable $e)` fallback** (F2, F3, F5, F15). Pulls the controller into the project's DI posture, allows a logged generic-500 redirect, and gives the cloned controller a `final` declaration in the same pass.
3. **Pass `expectedVersion` from controller + render hidden `version` field in edit view** (F16, NEW). Activates the optimistic-lock contract that the command DTO already advertises. Pair with F4 / F6 / F12 input-normalisation cleanup so the cloned `FooController` inherits safe scalar coercion as the baseline.

---

**Round 3 severity counts (post-re-audit):** CRITICAL 1 | HIGH 4 | MEDIUM 5 | LOW 5 | INFO 1 (total 16; up from 15 — F16 added, no closures)
**Verdict shift:** none — **READY-WITH-FIXES** with the CRITICAL still blocking. E17 (`final`) and E08 (controller call-site parity) confirmed NOT merged onto `stabilization/erp-foundation` despite being staged on epic branches.
**Top finding:** F1 unchanged (route-group has no `web_auth` filter; auth depends on cross-file URI deny-list). Largest residual is the dual gap of F1 (open-by-default cloned domains) + F16 (disabled optimistic-lock from HTTP path).
