# RE-AUDIT 10 — Views, XSS, CSRF, Accessibility

**Re-auditor:** codeigniter4-specialist
**Original audit:** `.audit/round3/10-views-security.md` (15 findings, 2026-05-22)
**Re-audit date:** 2026-05-23
**Scope verified:** `app/Views/cookies/{index,create,edit,show}.php`, `app/Views/layout.php`,
`app/Views/layouts/shell.php`, `app/Views/partials/_flash.php`, `app/Views/partials/_pagination.php`
**PRs reviewed for impact:** #29, #30, #31, #32, #33, #34, #35, #36, #37, #38, #39, #40, #41, #42

## TL;DR

**No PR in the #29–#42 wave touches `app/Views/**`.** E14 (the views/i18n/permission-gating
epic) has not been opened. Every finding from the round-3 view audit therefore remains
**OPEN** in `HEAD` of `stabilization/erp-foundation`. Spot-checks of the live files confirm
the exact line-level evidence cited in round-3 still applies verbatim.

Verification commands:
- `gh pr list --state all` for PRs #29–#42 → none list any `app/Views/**` path in `files[]`.
- `grep -oE "app/Views/[a-zA-Z_/]+\\.php"` against the combined PR-files dump → zero matches.
- `mtime` of every file under `app/Views/cookies/` is **2026-05-19/20**, predating the wave.
- `grep -n "formattedPrice\\|isOutOfStock" app/Domain/Cookie/ReadModels/CookieView.php` → **0 hits**
  (the read-model still lacks both accessors that views require — F1 still bites).
- `grep -rn "lang(" app/Views/cookies/` → **0 hits** (F2 still open).
- `grep -rn "can(" app/Views/cookies/` → **0 hits** (F3 still open).
- `grep "bootstrap-icons" app/Views/layout.php` → **0 hits** (F6 still open).
- `ls app/Language/en/` → `App.php Validation.php` only; **no `Cookies.php`** (F2/F15 still open).

## Closure matrix

| ID  | Severity | Title (abbrev.)                                    | Status | Evidence / note                                                                                       |
|-----|----------|----------------------------------------------------|--------|--------------------------------------------------------------------------------------------------------|
| F1  | HIGH     | View↔DTO drift (`formattedPrice` / `isOutOfStock`) | OPEN   | (E14 not yet opened) `CookieView.php` still missing both; views (`index.php:50,52`, `show.php:41,47`) still call them. E10 plans to delete `CookieDTO` — E10 has also not shipped, so the drift will widen the moment E10 lands without E14. |
| F2  | HIGH     | Hard-coded English strings, `lang()` absent        | OPEN   | (E14 not yet opened) `grep "lang(" app/Views/cookies/` returns nothing; no `app/Language/en/Cookies.php` exists. |
| F3  | HIGH     | Action buttons render without `can()` gating       | OPEN   | (E14 not yet opened) `grep "can(" app/Views/cookies/` returns nothing; Create/View/Edit/Delete buttons render unconditionally. |
| F4  | HIGH     | Pagination duplicated inline, `_pagination` unused | OPEN   | (E14 not yet opened) `index.php:79–94` still inline; partial still un-`include`d from any Cookie view. |
| F5  | MEDIUM   | `show.php:36` HTML-in-ternary anti-pattern         | OPEN   | (E14 not yet opened) line 36 unchanged: `esc($cookie->description) ?: '<em class="text-muted">…</em>'`. |
| F6  | MEDIUM   | Bootstrap Icons referenced but never loaded        | OPEN   | (E14 not yet opened) `layout.php` still loads only `bootstrap.min.css`; no `bootstrap-icons.css` link. |
| F7  | MEDIUM   | Date/price formatting inconsistent across views    | OPEN   | (E14 not yet opened) `show.php:64,68` still emit raw `<?= $cookie->createdAt ?>`; no date helper added. |
| F8  | MEDIUM   | Empty-state markup duplicated, lives inline        | OPEN   | (E14 not yet opened) `index.php:26–29` unchanged; no `partials/_empty_state.php` created. |
| F9  | LOW      | Page title defaults to "Dashboard"                 | OPEN   | (E14 not yet opened) None of the four views set `$title` or define a `title` section. |
| F10 | LOW      | `$cookie->id` echoed without cast/escape           | OPEN   | (E14 not yet opened) `index.php:47,65,66,83,89` and `show.php:8,28,83,86` unchanged. |
| F11 | LOW      | Delete confirm is JS-only (`data-confirm`)         | OPEN   | (E14 not yet opened) `show.php:86–91` unchanged. |
| F12 | LOW      | Search form omits CSRF (correct, but undocumented) | OPEN   | (E14 not yet opened) `index.php:13` has no explanatory comment. |
| F13 | LOW      | Submit vs Cancel button/link convention undocumented | OPEN | (E14 not yet opened) No comment added to `create.php` / `edit.php`. |
| F14 | INFO     | Two layouts (`layout.php` + `layouts/shell.php`)   | OPEN   | (E14 not yet opened) All four Cookie views still `$this->extend('layout')`, contradicting `layout.php`'s own DocBlock advice. |
| F15 | INFO     | `_flash` keys + controller flash strings English   | OPEN   | (E14 not yet opened) `CookieController` literal flash strings unchanged; no `Cookies.flash.*` lang keys exist. |

**Totals:** OPEN 15 · FIXED 0 · MITIGATED 0 · MOVED 0

## PR-by-PR impact on this slice

| PR  | Epic   | Touches `app/Views/**`? | Impact on slice 10 |
|-----|--------|-------------------------|---------------------|
| #29 | E02    | No                      | None |
| #30 | E01    | No                      | None |
| #31 | E03    | No                      | None |
| #32 | E04    | No                      | None |
| #33 | E06    | No                      | None |
| #34 | E05    | No                      | None |
| #35 | E07    | No                      | None |
| #36 | E08    | No                      | None |
| #37 | E05.5  | No                      | None |
| #38 | E12.5  | No                      | None |
| #39 | E17    | No                      | None |
| #40 | E15    | No                      | None |
| #41 | E11    | No                      | None |
| #42 | E18    | No                      | None |

Method: `gh pr view <n> --json files`. No PR lists any path matching `app/Views/`.
E14 (the planned views/i18n/permission/partials epic) has no PR or branch.

## Cross-slice interaction risk

- **E10 (`CookieView` becomes the canonical read-model and `CookieDTO` is deleted)**
  is on the roadmap but **not yet opened**. The moment E10 lands without E14 also
  landing, the four Cookie views will fatal at first render — `Undefined property:
  App\Domain\Cookie\ReadModels\CookieView::$formattedPrice`. **E14 MUST land before
  or atomically with E10**, otherwise the reference template is broken on `main`.
- **E07 / E08 / E11** (entity lifecycle, handler migration, repository hygiene)
  add new commands (`RestoreCookie`, `purge`, `existsByName`) without surfacing
  them in the views. The views advertise only Edit / Delete; "Restore" has no UI
  affordance even though the command exists. This is a new sub-finding (see below)
  introduced by the round-3 PR wave even though no view file changed.

## New finding introduced by the PR wave (not in original audit)

### F16 — MEDIUM — `RestoreCookie` command lacks any view affordance after E07/E08
- **Why it appears now:** PR #35 (E07) raises `CookieRestored`; PR #36 (E08) wires the
  handler; PR #41 (E11) adds `purge()` semantics. None add a list filter for
  soft-deleted cookies nor a "Restore" button. `show.php` shows live cookies only and
  has no toggle to view trashed ones; the route `/cookies/<id>/restore` exists
  (per controller) but is unreachable from the UI.
- **Why it is a template defect:** A cloner adding soft-delete to a new domain
  will inherit the gap and never expose the "restore" affordance, defeating the
  point of soft-delete.
- **Suggested fix:** Add a `?include_deleted=1` toggle on the index page (gated on
  `cookies.restore` if E14 adds `can()`), a "deleted" badge column, and a
  `<form method="post" action=".../restore">` button on `show.php` when the
  cookie has `$cookie->deletedAt !== null`.

## Verdict shift

**No verdict change.** Round-3 said **READY-WITH-FIXES** because XSS/CSRF gates
are clean and the remaining defects are template-cloning hazards. That assessment
holds. The slice has not regressed — it simply has not been touched. The
**urgency** of E14 has risen one notch because E10 (which deletes `CookieDTO`)
is now planned: once E10 ships, F1 becomes a fatal runtime error, not a soft
"developer confusion" risk.

## Biggest residual

**F1 — View↔DTO drift coupled with the impending E10 deletion of `CookieDTO`.**
Today the views work because `CookieDTO` still exists and carries
`formattedPrice` / `isOutOfStock()`. The published roadmap intends to delete
`CookieDTO` (E10) and standardise on `CookieView` (which has neither accessor).
If E10 merges before E14 — or without explicit coordination — the four reference
Cookie views fatal on first render and every domain cloned from the template
inherits a broken UI. **E14 must be sequenced with or before E10**, and the
acceptance criteria for E14 must include "every accessor used by Cookie views
exists on whichever read-model the query handlers return."

---

**Severity counts (post re-audit):** CRITICAL 0 | HIGH 4 | MEDIUM 5 | LOW 5 | INFO 2
(F16 added: MEDIUM count rose from 4 → 5; original 15 remain OPEN.)
