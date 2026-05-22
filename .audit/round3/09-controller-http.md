# 09 — Cookie Controller & HTTP Layer

**Slice:** CookieController + routes + filters
**Reviewer:** codeigniter4-specialist
**Date:** 2026-05-22
**Source files reviewed:** 5 (`CookieController.php`, `BaseController.php`, `Routes.php`, `Filters.php`, `CookieServiceProvider.php::registerRoutes`)

## TL;DR

`CookieController` is genuinely thin — it dispatches commands/queries through the buses, catches `ValidationException` / `DomainException`, and redirects with flash data. It does not contain business logic. However, as a *template* it has several traits that break or surprise a developer running `sed s/Cookie/Foo/g`: (1) the catch-list is incomplete — any unanticipated `Throwable` from a handler escapes to CI4's `error_exception` view, leaking stack traces in non-production envs; (2) the controller is not `final`, contradicts the project rule "Final classes by default"; (3) per-action service lookups via `Services::queryBus()` / `Services::commandBus()` / `Services::actorResolver()` are repeated five times each — no constructor injection, breaking the template's own DDD/DI posture documented in `CLAUDE.md`; (4) routes use `POST /cookies/{id}/delete` rather than `DELETE`, which is consistent with non-JS HTML forms but is also missing from any documentation about REST conventions for the template; (5) no per-route filter (`web_auth`, `permission`, `ratelimit`) is attached at the route group level — Cookie relies on the `Filters.php` URI-pattern deny-list (`cookies`, `cookies/*`). When a developer clones to `Foo`, they must remember to edit `app/Config/Filters.php` to add `foo`, `foo/*` — otherwise the cloned routes are anonymous. This was already flagged round 2 (`r10-http-api.md:39`) as **CRITICAL — open-by-default**. (6) `Services::actorResolver()->resolve($this->request)` is repeated in every write action and silently returns `Actor::system()` for unauthenticated callers (round 2 `r10-http-api.md:68`); cloned domains will inherit that anonymous-attribution bug. (7) `(bool) $isActiveParam` swallows the `"0"`/`"false"`/`"off"` string conventions silently.

## Verdict
READY-WITH-FIXES

## Findings

### F1 — CRITICAL — Cookie route group has no route-level auth filter; relies on URI deny-list in Filters.php
- **Location:** `app/Domain/Cookie/CookieServiceProvider.php:224–235`; `app/Config/Filters.php:140–148`
- **Observation:** `registerRoutes()` registers the group with `['namespace' => 'App\Controllers\Domain\Cookie']` only — no `filter`. Authentication is provided entirely by the URI-pattern allow-list in `Filters.php` (`'web_auth' => ['before' => ['cookies', 'cookies/*', 'admin/*', 'dashboard']]`). A developer who runs `sed s/cookies/foos/g` on the provider but forgets to edit `Filters.php` ships an open-by-default operational surface. The reference template *itself* models this fragile pattern.
- **Why this is a template defect:** Defence-in-depth fails. The "single source of truth" for which routes need auth is split across two files in two different namespaces. Already verified CRITICAL by round 2 (`.audit/round2/r10-http-api.md:39`).
- **Suggested fix:** Attach `'filter' => 'web_auth'` (or a comma-separated `'web_auth,permission:cookie.view'`) directly on the route group inside `registerRoutes()`. Remove `cookies/*` from `Filters.php`'s `web_auth` block so the source of truth lives with the route. Document the pattern in `domain-scaffolding`.

### F2 — HIGH — No generic `Throwable` catch — unanticipated exceptions leak through to CI4 error renderer
- **Location:** `app/Controllers/Domain/Cookie/CookieController.php:120–158, 196–235, 250–262`
- **Observation:** Every write action catches only `ValidationException` and `DomainException`. If a handler throws a `RuntimeException` (DB error, cache miss, etc.), an `InvalidArgumentException` from outside the domain hierarchy, or a `TypeError` from a value-object boundary, the exception bubbles to CI4's default exception renderer. In `CI_ENVIRONMENT=development|testing` that means a full stack trace in the browser. In production it's a generic 500 view, but the original `withInput()` flash data is lost — the user sees a blank form on resubmit.
- **Why this is a template defect:** The cloned `FooController` will inherit the same gap. The "no leakage of `$e->getMessage()` from generic Throwable" rule (per audit brief and round 2 `r10-http-api.md:55`) is dodged here only by *not having* a generic catch — but that's the worse failure mode.
- **Suggested fix:** Add a final `catch (\Throwable $e)` that logs the exception with a correlation id via the injected logger and redirects with a fixed user-facing message ("An unexpected error occurred. Please try again."). Do NOT interpolate `$e->getMessage()` into the flash.

### F3 — HIGH — Service-locator pattern repeated per action; no constructor injection
- **Location:** `CookieController.php:51, 84, 118, 141, 171, 194, 219, 248, 253`
- **Observation:** `Services::queryBus()`, `Services::commandBus()`, and `Services::actorResolver()` are looked up inside each action. There is no constructor (`__construct(private CommandBus $commandBus, private QueryBus $queryBus, private ActorResolver $actorResolver)`). The skill `codeigniter4-specialist.md` example **explicitly** shows controllers with constructor-injected buses ("Real Example" block in this very agent's docs). CI4 4.6 supports constructor argument resolution via auto-discovery; the template's own `CookieController` does not use it.
- **Why this is a template defect:** Cloned domains will copy the service-locator pattern. Testing requires monkey-patching `Services::*` rather than passing fakes to a constructor. PHPStan can't follow `Services::queryBus()` as cleanly as a typed property. Violates "constructor injection" preference documented project-wide.
- **Suggested fix:** Refactor to constructor injection. Add a single `__construct` and store `$this->commandBus / $this->queryBus / $this->actorResolver`. This will also fix F2 since the logger can be injected the same way.

### F4 — HIGH — `(bool) $isActiveParam` is permissive — `"0"`, `"false"`, `"off"` are NOT false
- **Location:** `CookieController.php:133–134, 209–210`
- **Observation:** `$isActive = (bool) $this->request->getPost('is_active');` Note: `(bool) "0"` is `false` (correct by happenstance), but `(bool) "false"` is `true`, `(bool) "off"` is `true`, `(bool) "no"` is `true`. PHP's `filter_var($v, FILTER_VALIDATE_BOOLEAN)` exists for exactly this purpose. Also: when an HTML checkbox is unchecked, the field is **absent** entirely; `getPost('is_active')` returns `null`; `(bool) null = false` — that path happens to work. But a JSON client sending `{"is_active": "false"}` produces `true`.
- **Why this is a template defect:** The cloned `FooController` will inherit identical fragile coercion for any boolean field. The template should set the bar.
- **Suggested fix:** Use `filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false` or a small `bool_param()` helper shared across controllers.

### F5 — MEDIUM — `class CookieController` is not `final`
- **Location:** `CookieController.php:40`
- **Observation:** Project rule: "Final classes by default" (CLAUDE.md Code Quality). The neighbouring `CookieRepository`, `Cookie` entity, value objects, command/query handlers are all `final`. The reference controller is not.
- **Why this is a template defect:** A cloned `FooController` will copy the non-final declaration. PHPStan strict-rules and Slevomat's `RequireExplicitFinal` will flag it on every new domain.
- **Suggested fix:** Declare `final class CookieController extends BaseController`. If a domain truly needs subclassing, deviate consciously.

### F6 — MEDIUM — `index` hard-codes `perPage: 20` with no override
- **Location:** `CookieController.php:59–63`
- **Observation:** Page size is a constant, but the query DTO (`GetCookiesPaginatedQuery`) supports `perPage`. There is no `$this->request->getGet('per_page')` parameter, no clamp, no documented default. Audit brief asks: "Pagination params validated and clamped" — `page` is loosely cast (`is_numeric($pageParam) ? (int) $pageParam : 1`) but **not clamped to ≥ 1**; passing `?page=-5` produces `page = -5` which the handler must defend against.
- **Why this is a template defect:** The pattern propagates: every cloned domain ships with a magic-number page size and an un-clamped page index. Defence-in-depth assumed in handler. The brief specifically asks the template to demonstrate clamping.
- **Suggested fix:** `$page = max(1, is_numeric($p) ? (int) $p : 1);` and `$perPage = max(1, min(100, (int) ($this->request->getGet('per_page') ?? 20)));`.

### F7 — MEDIUM — `Services::actorResolver()->resolve($request)` returns `Actor::system()` for anonymous callers — silently anonymous attribution
- **Location:** `CookieController.php:141, 219, 253`
- **Observation:** When a non-authenticated user reaches the endpoint (would only happen if F1's auth filter is missing on a cloned route group — see F1), `ActorResolver::resolve()` returns `Actor::system()` (verified round 2 `r10-http-api.md:68`). The audit trail records system-attributed writes instead of failing closed.
- **Why this is a template defect:** The cloned `FooController` inherits the same "silent system attribution" failure mode. Audit logs lose forensic value the moment auth is misconfigured.
- **Suggested fix:** Either (a) `ActorResolver::resolveOrFail()` and let the exception map to 401/redirect-to-login, or (b) controller asserts `$actor->isAuthenticated()` before dispatching write commands.

### F8 — MEDIUM — `redirect()->back()` with no fallback target
- **Location:** `CookieController.php:150, 155, 227, 232, 260`
- **Observation:** When a POST arrives with no `HTTP_REFERER` header (curl, direct API call, some browsers blocking referer), CI4's `redirect()->back()` falls back to the current URL (the POST handler itself), which re-renders the form action on GET and may produce a 405 or unexpected re-execution depending on routing. Better practice: `redirect()->back('/cookies/' . $id)` style fallback or explicit redirect to a known-safe route.
- **Why this is a template defect:** Every cloned write action inherits the implicit referer dependency. Edge case but breaks under non-browser clients.
- **Suggested fix:** Define a `safeBack(string $fallback)` helper or pass an explicit second argument.

### F9 — MEDIUM — `POST /cookies/{id}/delete` vs `DELETE /cookies/{id}` — convention not documented
- **Location:** `CookieServiceProvider.php:233`
- **Observation:** The template uses `POST /cookies/(:num)/delete` for delete. This is a deliberate workaround for HTML form limitations (`<form method>` only supports GET/POST). The `update` route similarly uses `POST /cookies/(:num)` instead of `PUT`. Neither choice is explained in any inline comment or skill doc. A cloned domain that *does* want a JSON/RESTful surface (`api/v1/foos/{id}`) cannot use this controller and will diverge.
- **Why this is a template defect:** The template silently picks a UI-only convention without giving the user a clear branching point ("HTML controller" vs "API controller"). The slice-09 audit specifically asks: "Routing patterns (`cookies/...` vs `domain/cookie/...`) — consistent with what a cloned domain would use?" — Cookie uses `cookies/...` flat. The `api/v1/users/...` group exists in parallel. No reference template for a domain API controller exists.
- **Suggested fix:** Add an explicit comment in `registerRoutes()` explaining the HTML-form convention, and ship a sibling `Api/CookieApiController` reference for the JSON case.

### F10 — LOW — Unused import + namespacing inconsistency
- **Location:** `CookieController.php:5, 15–16`
- **Observation:** `use CodeIgniter\HTTP\RedirectResponse;` is used; `use Config\Services;` is used. Fine. But the namespace `App\Controllers\Domain\Cookie` does not match the routes mount path `cookies/`. Other consumers (route-listing, deep links from logs) will see a mismatch between FQCN and URI.
- **Why this is a template defect:** A `sed` substitution must update both the namespace (`Domain\Foo`) and the URI mount (`foos`) — they don't share a stem.
- **Suggested fix:** Document explicitly in the `domain-scaffolding` skill that the namespace stem and URI stem are independent and provide a checklist.

### F11 — LOW — `show()` and `edit()` swallow "not found" via redirect — no 404 emitted
- **Location:** `CookieController.php:89–92, 176–179`
- **Observation:** When the query returns `null`, the controller redirects to `/cookies` with a flash. HTTP-correct behaviour is `throw new PageNotFoundException("Cookie not found: {$id}")`, producing a 404 status. The current path returns 302 → 200 — search engines, monitoring tools, and API clients can't distinguish "missing" from "moved".
- **Why this is a template defect:** Cloned domains will mirror the non-RESTful 302-on-missing pattern.
- **Suggested fix:** Throw `\CodeIgniter\Exceptions\PageNotFoundException` (per CI4 idiom and per the skill's own example: "Real Example from Cookie Domain" in the agent's own context block uses this pattern).

### F12 — LOW — `$price = is_string($priceParam) ? $priceParam : '';` — empty-string fallback hides type confusion
- **Location:** `CookieController.php:127–128, 203–204`
- **Observation:** If a client posts `price=1099` as a JSON int (which CI4 *can* surface as `int|null` depending on `getRawInput()`), `is_string($priceParam)` is false → `price = ''`. The handler then receives `""`, the Money VO will throw a validation error, and the user sees "Price is required" even though they sent `1099`. Numeric input should accept both string and numeric forms.
- **Why this is a template defect:** Every cloned scalar-on-the-wire field copies the same brittle `is_string ? : ''` pattern.
- **Suggested fix:** Use `(string) ($priceParam ?? '')` with a `is_scalar` guard, or pre-normalize all post params via a `RequestNormalizer` shared helper.

### F13 — LOW — No `permission:` filter wired anywhere on Cookie routes
- **Location:** `CookieServiceProvider.php:226`; `Filters.php:51`
- **Observation:** `Filters.php` aliases `'permission' => PermissionMiddleware::class` but the Cookie group does not use it. Any authenticated user can create/update/delete cookies. The template models authorization as a no-op.
- **Why this is a template defect:** Cloned domains will likely *need* finer-grained perms (`cookie.create`, `cookie.delete`). The reference shows none, so cloned domains begin without it and may forget entirely.
- **Suggested fix:** Add a commented-out reference: `'filter' => 'permission:cookie.manage'` with a note pointing to the `PermissionMiddleware`.

### F14 — LOW — `delete()` does not catch `ValidationException`
- **Location:** `CookieController.php:250–262`
- **Observation:** `delete()` catches only `DomainException`. If `DeleteCookieCommand` ever adds a validation step (e.g., reason-required field), a `ValidationException` thrown by the handler will bypass the flash-message path and bubble to the global error handler.
- **Why this is a template defect:** Inconsistency with `store`/`update`. Cloned `FooController::delete` inherits the gap.
- **Suggested fix:** Mirror the `store`/`update` catch order (`ValidationException`, then `DomainException`, then `Throwable` per F2).

### F15 — INFO — `BaseController::initController` preload comment is dead weight in template
- **Location:** `app/Controllers/BaseController.php:53–61`
- **Observation:** The `// Preload any models, libraries, etc, here.` and `// E.g.: $this->session = service('session');` comments are CI4 starter-template leftovers. The DDD/CQRS template asks consumers *not* to preload models in controllers.
- **Why this is a template defect:** Encourages anti-pattern via stale comments.
- **Suggested fix:** Remove the comments or replace with: `// Controllers in this project use constructor injection — do NOT preload services here.`

## What is correct / praiseworthy

- **Genuinely thin actions** — each action is ≤ 30 lines and contains no business logic. Confirmed: no entity hydration, no repository access, no event dispatch, no SQL.
- **Strong typing throughout** — `declare(strict_types=1)` at the top, explicit return types (`string`, `RedirectResponse`, `string|RedirectResponse`), explicit `int $id` typing on routed params.
- **Explicit type-checks before casting** — `is_numeric($pageParam) ? (int) $pageParam : 1` and `is_string($searchParam) ? $searchParam : null` are the right safety pattern (round 2 prior cited approval, brief asks for this).
- **Correct redirect-after-POST** — every write action returns a `RedirectResponse` to a GET URL, preventing form re-submission on browser back.
- **`withInput()` + `with('errors', ...)` re-population** — the `store`/`update` validation paths preserve user input and emit field-level error data; views can render inline errors.
- **`ValidationException::getErrors()` is surfaced** — the handler-validated errors reach the view, not just the top-level message. This is non-trivial and is the right pattern.
- **CSRF is globally enforced** via `Filters.php:96` `'csrf'` in `globals.before` — POST routes are covered without the controller needing per-route CSRF wiring.
- **`correlation` and `locale` filters run on all requests** — Cookie inherits both without explicit wiring.
- **`Config\Services::commandBus()` / `queryBus()`** — at least the controller uses the bus indirection, not direct handler instantiation.

## Top 3 fixes before cloning

1. **Wire `web_auth` (and optionally `permission:`) directly on the route group inside `registerRoutes()`** (F1). Eliminates the cross-file deny-list. Remove `cookies/*` from `Filters.php` once attached. This single change defends every cloned domain by default.
2. **Refactor to constructor injection + add `catch (\Throwable $e)` fallback that logs with the injected logger and returns a generic 500-flash redirect** (F2, F3). Pulls the controller into the project's own dependency-injection posture and prevents stack-trace leakage on any unanticipated exception. Will simplify F5 (final) at the same time.
3. **Normalize input parsing** (F4, F6, F12). Add a `parseBool($post)` helper, clamp `page` and `per_page` with `max(1, …)` + `min(100, …)`, and accept numeric/scalar input for `price`/`stock`. Cloned domains then inherit safe coercion as a baseline pattern.

---

**Severity counts:** CRITICAL 1 | HIGH 3 | MEDIUM 5 | LOW 5 | INFO 1
**Top finding:** Route group registers no `web_auth`/`permission` filter — Cookie's auth depends on a URI deny-list in `Filters.php`; cloned domains are anonymous-by-default until that file is also edited.
