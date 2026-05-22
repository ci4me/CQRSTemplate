# 10 — Views, XSS, CSRF, Accessibility

**Slice:** All Cookie view templates
**Reviewer:** codeigniter4-specialist
**Date:** 2026-05-22
**Source files reviewed:** 4 Cookie views + 4 ancillary

- `app/Views/cookies/index.php`
- `app/Views/cookies/create.php`
- `app/Views/cookies/edit.php`
- `app/Views/cookies/show.php`
- `app/Views/layout.php` (parent layout)
- `app/Views/layouts/shell.php` (alias)
- `app/Views/partials/_flash.php`
- `app/Views/partials/_pagination.php` (referenced but unused by Cookie views)

## TL;DR

The Cookie views are the **reference template** that newer partials (`_pagination`, `_form_field`, `_flash`, `_breadcrumbs`) were designed to replace — but none of those partials are wired in here. The XSS surface is mostly correct (a regression noted in `.audit/final-sweep.md` lines 102–103 has been fixed), but several rendering choices are silently fragile:

1. The DTO contract is **split** — views call `$cookie->formattedPrice` and `$cookie->isOutOfStock()` which exist on `CookieDTO` (legacy) but **not on `CookieView`** (the new read-model in `app/Domain/Cookie/ReadModels/CookieView.php`). A developer cloning this domain hits an immediate `Undefined property` if they switch the query handler to `CookieView`.
2. **Description on `show.php:36` outputs raw `<em>` HTML** from a string expression evaluated inline — `esc($cookie->description) ?: '<em class="text-muted">No description</em>'`. The fallback HTML is hard-coded, but the pattern teaches "I can put HTML after `?:`" — and a sed-clone that swaps `description` for a richer field will be tempted to do the same with user data.
3. **Hard-coded "Cookie" / "cookies" strings everywhere** (titles, buttons, table headers, validation help text, success messages). `lang()` is half-adopted in the shell but completely absent in the entity views — a `sed s/Cookie/Foo/g` will work for English, but locale resources never updated.
4. **No permission gating on action buttons.** Create / Edit / Delete buttons render unconditionally. Sidebar uses `can()`; entity views do not. Cloning carries the regression forward.
5. **Pagination is reimplemented inline** (`cookies/index.php:79–94`) despite `partials/_pagination.php` existing and being purpose-built for this. The inline version also assembles URLs by string concat with `urlencode`, instead of via `http_build_query` like the partial.
6. **No `aria-label` on icon-only action buttons** ("View", "Edit", "Delete" rely on visible text — OK — but the `<i class="bi …">` icons are *empty* because Bootstrap Icons CSS is not loaded by `layout.php`).

## Verdict
**READY-WITH-FIXES.** The XSS / CSRF gates are clean (form CSRF is present, escapes are correct on the previously-flagged lines). The structural defects are template-cloning hazards rather than security holes.

## Findings

### F1 — HIGH — View↔DTO drift: views depend on `CookieDTO` accessors absent from `CookieView`
- **Location:**
  - `app/Views/cookies/index.php:50` (`$cookie->formattedPrice`), `:52` (`->isOutOfStock()`)
  - `app/Views/cookies/show.php:41` (`->formattedPrice`), `:47` (`->isOutOfStock()`)
  - cross-reference: `app/Domain/Cookie/DTOs/CookieDTO.php:26,55` defines them; `app/Domain/Cookie/ReadModels/CookieView.php:36–51` does NOT.
- **Observation:** Two coexisting DTOs in the domain — `CookieDTO` (legacy, has `formattedPrice` and `isOutOfStock()`) and `CookieView` (B14 read-model, has neither). The query handlers (`GetCookieByIdHandler`, `GetCookiesPaginatedHandler`) currently return `CookieDTO`. The reference views silently rely on the older shape. The newer DTO is documented as the "right" surface but cannot render the existing views.
- **Why this is a template defect:** A developer cloning Cookie→Foo who notices `CookieView` exists and switches `FooReadModel` to follow the new pattern will get `Error: Undefined property: FooView::$formattedPrice` at first render. The two contracts are inconsistent; one of them is wrong for the template.
- **Suggested fix:** Either (a) delete `CookieDTO` and add `formattedPrice` + `isOutOfStock()` to `CookieView` (preferred — the read-model is the documented future), and update views accordingly; or (b) explicitly document on `CookieView` that it is **list-API only** and views must use `CookieDTO`. The current state lets a developer wander into the wrong one.

### F2 — HIGH — Hard-coded English strings throughout — `lang()` not used
- **Location:**
  - `cookies/index.php:6` ("Cookies"), `:8` ("Create New Cookie"), `:16` ("Search"), `:18` ("Clear"), `:28` ("No cookies found."), `:35–41` (every `<th>`), `:58,60` ("Active"/"Inactive"), `:65–66` ("View"/"Edit"), `:86` ("Page X of Y"), `:92` ("Total: X cookies")
  - `cookies/create.php:6,14,30,39,50,64,79,86,88,98–105`
  - `cookies/edit.php:6,14,30,39,50,64,79,87,89,99–117`
  - `cookies/show.php:6,9,12,79,84,89,93` (table headers `:27–69` also hard-coded)
- **Observation:** The shell layout (`layout.php:14,19`) does use `lang('App.dashboard')`. The pagination partial (`_pagination.php:45,79`) uses `lang('App.previous')` / `lang('App.next')`. Every Cookie entity view reverts to literal English.
- **Why this is a template defect:** "Cookies" / "cookie" appears in 35+ user-visible places. After `sed s/Cookie/Foo/g` the strings become "Foos" / "foo" — grammatically wrong (Foos? Foo?), and a real new domain ("Inventory Item") needs full rewording. The right pattern is `lang('Cookies.title')` so a sister `Foos.title` exists per locale.
- **Suggested fix:** Establish `app/Language/en/Cookies.php` with keys `title`, `create`, `search_placeholder`, `empty_state`, `table_headers.*`, `actions.view/edit/delete`, `status.active/inactive`, etc. Replace literal strings in all four views. Document this in the scaffolding skill.

### F3 — HIGH — Action buttons render without `can()` gating
- **Location:**
  - `cookies/index.php:7` (Create button — should gate on `cookies.create`)
  - `cookies/index.php:65–66` (View / Edit — should gate on `cookies.view` / `cookies.update`)
  - `cookies/show.php:8` (Edit), `:83` (Edit), `:86–91` (Delete form/button — should gate on `cookies.update`/`cookies.delete`)
  - `cookies/edit.php:86–87` (Update submit — handler-side gate exists but the button is rendered anyway)
- **Observation:** The sidebar (`_sidebar.php:43`) correctly does `if (!can($perm)) continue;`. Entity views skip the check. Because permission checks also run in controllers/handlers, this is not a security bypass — just a UX leak: a user with read-only access sees Edit / Delete buttons that error out on submit.
- **Why this is a template defect:** Sister entities (`admin/users/index.php`) repeat the same omission. The "reference" pattern silently teaches: "actions don't need `can()` in the view." Round-1 audit flagged this; no fix landed.
- **Suggested fix:** Wrap every `<a>`/`<form>` action in `<?php if (can('cookies.create')): ?> … <?php endif ?>`. Add a comment in the reference views explaining the pattern. Consider a `partials/_action_button` helper that takes a permission key.

### F4 — HIGH — Pagination duplicated inline, ignoring `partials/_pagination.php`
- **Location:** `cookies/index.php:79–94` versus existing `app/Views/partials/_pagination.php`
- **Observation:** The inline block re-rolls Previous / Next links, builds URLs via raw string concat plus `urlencode($search)`, and renders "Page X of Y" with no window. The partial already implements all of this with a 5-page window, `http_build_query`, and `lang()` strings. It is never invoked from anywhere.
- **Why this is a template defect:** Every new domain that clones Cookie inherits the duplicate. The partial's `preserved => ['search' => $search]` API was designed exactly for this view's case and is unused.
- **Suggested fix:** Replace lines 79–94 with `<?= $this->include('partials/_pagination', ['page' => $pager['page'], 'last_page' => $pager['lastPage'], 'base_url' => '/cookies', 'preserved' => array_filter(['search' => $search])]) ?>`.

### F5 — MEDIUM — `show.php:36` renders hard-coded HTML in a ternary alongside escaped content
- **Location:** `app/Views/cookies/show.php:36`
- **Observation:**
  ```php
  <td><?= esc($cookie->description) ?: '<em class="text-muted">No description</em>' ?></td>
  ```
  The fallback `<em>...</em>` is literal author-controlled HTML, so it is safe **today**. But the pattern teaches "after `?:` you can write HTML in a `<?= ?>` block." A clone that swaps `description` for any user-controllable string and replaces the fallback with a richer expression will introduce XSS.
- **Why this is a template defect:** Reference templates should model the safer form. The empty-state should be a separate `<?php if (empty($cookie->description)): ?> <em class="text-muted">No description</em> <?php else: ?> <?= esc($cookie->description) ?> <?php endif ?>` or `<?= lang('Cookies.no_description') ?>` block, so the escape boundary is unambiguous.
- **Suggested fix:** Split the conditional out of the echo. Use an `if/else` block and route the empty-state string through `lang()`.

### F6 — MEDIUM — Bootstrap Icons referenced but never loaded
- **Location:** `index.php:8,28`, `create.php:8,14,86`, `edit.php:8,14,87`, `show.php:9,12,84,89,93`. Layout: `layout.php:23–27` only loads `bootstrap.min.css`.
- **Observation:** All `<i class="bi bi-*">` tags render as invisible — no CDN link for `bootstrap-icons.css` in `layout.php`. Round-1 audit flagged this; still not fixed.
- **Why this is a template defect:** Buttons that nominally have icon + text now render with an empty 1em gap before the label, plus accessibility tools may announce the empty `<i>` weirdly.
- **Suggested fix:** Either drop the `<i class="bi-…">` tags from the reference views, or add the icon stylesheet to `layout.php` with SRI hash. If they're decorative, also mark them `aria-hidden="true"` (sidebar does this on its emoji wrapper `_sidebar.php:49`).

### F7 — MEDIUM — Date / price formatting is inconsistent across views
- **Location:**
  - `cookies/index.php:50` and `show.php:41` use `$cookie->formattedPrice` (good — goes through `Price::format()`)
  - `cookies/show.php:64,68` output raw `<?= $cookie->createdAt ?>` (NOT escaped, no formatter)
  - `cookies/edit.php:102–103` output `<?= esc($cookie->createdAt) ?>` (escaped, but no humanised format)
- **Observation:** Three different patterns for the same data type. Round-1 audit flagged this and the final-sweep flagged `edit.php:101–103` specifically — the `esc()` is now there. But `show.php:64,68` still output the timestamp raw (unescaped), and neither view goes through a `DateTimeFormatter` value object.
- **Why this is a template defect:** Inconsistency at the template level. Even if raw `?string` `createdAt` is safe content today (DATETIME column), a future reformat (e.g. `Y-m-d H:i:s O` with a timezone name containing `<`-like chars from `Etc/GMT+1`) will not break escaped sites but will break raw ones. And cloners will copy the wrong pattern half the time.
- **Suggested fix:** (a) `esc()` everywhere as a baseline. (b) Add a `format_datetime($string, $locale)` helper in `app/Helpers/` and route every timestamp through it. (c) Same for price — the DTO's `formattedPrice` works, but the read-model `CookieView` doesn't have it (see F1).

### F8 — MEDIUM — Empty-state markup is duplicated and lives inline
- **Location:** `index.php:26–29`
- **Observation:** "No cookies found." is hard-coded HTML in the view. Every cloned domain re-rolls the same `alert alert-info` + icon + sentence.
- **Why this is a template defect:** Template should model "use a partial." A `partials/_empty_state.php` accepting `message` + `cta` would be reusable across domains.
- **Suggested fix:** Extract `partials/_empty_state.php`; call from `index.php` and document the pattern in scaffolding.

### F9 — LOW — Title not passed to layout — page title is the default "Dashboard"
- **Location:** None of the four Cookie views set `$title` before extending the layout. `layout.php:14` defaults to `lang('App.dashboard')`.
- **Observation:** Every Cookie page shows "Dashboard · CodeIt4Me" in the browser tab. Controller does not pass a title either.
- **Why this is a template defect:** Bookmarking, browser history, screen-reader announcement all show the wrong page.
- **Suggested fix:** Either `$this->section('title') … <?= $this->endSection() ?>` blocks in each view, or pass `'title' => lang('Cookies.list_title')` from the controller into `view('cookies/index', […])`.

### F10 — LOW — `<?= $cookie->id ?>` rendered without `esc()` (defence-in-depth)
- **Location:**
  - `index.php:47` (`<td><?= $cookie->id ?></td>`), `:65,66,83,89` (URL fragments), `:82,86,88,89` (pager arithmetic)
  - `edit.php:7,27,89` (URL fragments)
  - `show.php:8,28,83,86` (URL fragments)
- **Observation:** `id` is `int` on the DTO so today it's safe. Treating ints as "trusted" is fine in the strictest sense but the template should model the rule "all output goes through `esc()` or a typed cast" so a `sed`-clone where `id` becomes a `string` UUID (very plausible — slug fields) doesn't break.
- **Why this is a template defect:** Templates teach by example. The `edit.php:101` line (`<?= (int) $cookie->id ?>`) is the correct pattern — cast on output. Most other id usages just inject the raw property.
- **Suggested fix:** Adopt `<?= (int) $cookie->id ?>` (or `esc((string) $cookie->id, 'attr')` inside `href` attrs) everywhere in the reference views.

### F11 — LOW — Delete form uses `data-confirm` JS — degrades silently without JS
- **Location:** `show.php:86–91` plus `public/assets/js/delete-confirm.js`
- **Observation:** The delete form has `data-confirm="Are you sure you want to delete this cookie?"` and relies on `delete-confirm.js` to intercept submit and prompt. With JS disabled the form submits unconfirmed. The script is one-of-two scripts loaded by `layout.php:56` (no `defer`/`async`).
- **Why this is a template defect:** Most ERP back-offices have to assume JS, so this is acceptable. But the confirm message is the only safety on a destructive action and is purely client-side. There is no server-side confirmation token / two-step delete.
- **Suggested fix:** Acceptable for the template, but document in the reference view that the confirm is purely UX and the handler MUST treat any POST as "delete now". Consider a `<noscript>` fallback message.

### F12 — LOW — Search form omits CSRF token (acceptable because GET, but should be documented)
- **Location:** `index.php:13` (`<form method="get" action="/cookies">`)
- **Observation:** No CSRF token. Correct for `method="get"` (idempotent, CSRF does not apply). A cloner might "fix" this by adding `csrf_field()` (it's harmless) or, worse, change the method to POST without re-thinking.
- **Why this is a template defect:** Reference view doesn't state *why* CSRF is omitted on this specific form. The next dev may either add it pointlessly or "fix" the search by POSTing and lose the CSRF gate.
- **Suggested fix:** Add a one-line comment `<!-- GET form: no CSRF token by design -->` so the omission is intentional and documented.

### F13 — LOW — `<button type="submit">` is explicit (good) but Cancel `<a>` next to Submit looks like a button
- **Location:** `create.php:84–89`, `edit.php:85–90`, `show.php:82–95`
- **Observation:** Buttons all have explicit `type="submit"`. Good. The "Cancel" link is `<a … class="btn btn-secondary">`, which is fine semantically (it's a navigation, not a form action) but the `<a>` next to a `<button>` in a `d-flex gap-2` row pairs them visually. Screen readers announce "Cancel, link" vs "Update Cookie, button", so they're distinguishable.
- **Why this is a template defect:** Not really a defect, but the template should keep linking the cancel as an `<a>` and the submit as a `<button type="submit">`. Document this convention.
- **Suggested fix:** Add a comment in the reference create/edit views: "Submit = `<button type=submit>`. Cancel = `<a>` (navigation, not a form action)."

### F14 — INFO — Two layouts (`layout.php` + `layouts/shell.php`) for the same render
- **Location:** `layouts/shell.php` is a 14-line alias that just calls `$this->extend('layout')`. All four Cookie views extend `layout` directly.
- **Observation:** Documented as a transition mechanism. Clean. But the *reference template* extends the *legacy* path. A cloner is likely to copy that.
- **Why this is a template defect:** The DocBlock on `layout.php:5–7` says "new views are encouraged to use `$this->extend('layouts/shell')` directly." The reference does not.
- **Suggested fix:** Either delete the alias and have one layout, or migrate the Cookie views to `$this->extend('layouts/shell')` so the canonical example follows the documented preference.

### F15 — INFO — `_flash.php` keys are hard-coded English; success/error messages set by controller are English
- **Location:** `_flash.php:10,17`; controller `CookieController.php:148,225,258` ("Cookie created successfully", "Cookie updated successfully", "Cookie deleted successfully", "Cookie not found")
- **Observation:** Flash partial correctly `esc()`'s the message. But the messages themselves are literal strings in the controller, not `lang()` keys.
- **Why this is a template defect:** Same as F2 — i18n half-done. Cloners will copy the strings.
- **Suggested fix:** Route every `->with('success', …)` through `lang('Cookies.flash.created')` etc.

## What is correct / praiseworthy

- **CSRF tokens are present on every mutating form** (`create.php:28`, `edit.php:28`, `show.php:87`). Good.
- **Mutating forms use `method="post"`** (no DELETE/PUT spoofing required). Search form correctly uses `method="get"`.
- **`old(...)` is escaped in `attr` context** on every input — `esc(old('name'), 'attr')`, `esc(old('price', $cookie->price), 'attr')`. This is the prior final-sweep regression and it has been fixed.
- **Labels are associated with inputs** — every `<label for="x">` has a matching `id="x"`. Good a11y baseline.
- **`<button type="submit">` is explicit everywhere** — no accidental form submits.
- **Form validation errors are rendered per-field AND in a summary block** at the top, with proper `is-invalid` class wiring. Good pattern.
- **Delete is a POST inside a `<form>`, not a GET link** — correct REST hygiene.
- **`csrf_field()` (not raw token interpolation) is used** — the helper handles the token name correctly.
- **`session('errors')` summary list at `create.php:12–21` / `edit.php:12–21` `esc()`'s each error** — no XSS via validation messages.
- **The `formattedPrice` accessor exists on `CookieDTO`** — formatting is centralised in the value object, not done ad-hoc in views (where it survives). It just needs to migrate to `CookieView` (see F1).
- **`description` is escaped in show/index** (`show.php:36`, `index.php:49`).
- **HTML `<title>` and shell brand are escaped** via `esc($title)` / `esc($appName)` in `layout.php:23`.
- **`html lang="…"` is set from the current locale** in `layout.php:19` — good a11y / SEO.

## Top 3 fixes before cloning

1. **Resolve the `CookieDTO` vs `CookieView` split (F1).** Pick one read-model, give it `formattedPrice` + `isOutOfStock()` (or document why views should keep using `CookieDTO`). Update the four views to use that one type. Add a `@param` to each view's PHPDoc header so PHPStan can catch a future clone that mis-types it.
2. **Adopt `lang()` and `can()` in the four reference views (F2 + F3).** Create `app/Language/en/Cookies.php`. Replace every hard-coded "Cookie" / "cookies" / "Create" / "Edit" / "Delete" / "Active" / "Search" string. Wrap every action button in `<?php if (can('cookies.…')): ?>`. This is what gets sed-cloned 100× and where the template currently teaches the wrong habit.
3. **Wire `partials/_pagination.php` into `index.php` (F4) and split the inline empty-state into `partials/_empty_state.php` (F8).** Eliminates two of the three "100-line duplication per new entity" hazards flagged in round-1.

---

**Severity counts:** CRITICAL 0 | HIGH 4 | MEDIUM 4 | LOW 5 | INFO 2
**Top finding:** Views call `$cookie->formattedPrice` / `->isOutOfStock()` — accessors that exist on `CookieDTO` (legacy) but not on `CookieView` (the documented future read-model), making the template inconsistent with its own DTO strategy.
