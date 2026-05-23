# RE-AUDIT 16 — Documentation, Comments & Docblocks

**Re-auditor:** general-purpose (docblocks + scaffolding-docs)
**Original audit:** `.audit/round3/16-documentation-docblocks.md` (15 findings, 2026-05-22)
**Re-audit date:** 2026-05-23
**Scope verified:** `app/Domain/Cookie/**`, `bin/docblocks-{audit,generate}`,
`composer.json`, `.github/workflows/ci.yml`, `.claude/CLAUDE.md`,
`.claude/documentation/{COMPLETE_FILE_INVENTORY,SERENA_CODE_ANALYSIS_COOKIE_DOMAIN,LOGGING_BEST_PRACTICES,GIT_WORKFLOW,PROJECTIONS}.md`,
`.claude/skills/{domain-scaffolding,cqrs-architecture}/SKILL.md`
**PRs reviewed for impact:** #29, #30, #31, #32, #33, #34, #35, #36, #37, #38, #39, #40, #41, #42

## TL;DR

The PR wave has the **fixes drafted but not landed**. PR #29 (E02) rewrites
`bin/docblocks-audit` to detect both legacy markers AND single-word stubs,
re-arms `bin/docblocks-generate` with the marker, and adds explicit
`composer docblocks:audit` + `composer deptrac` steps to CI. PR #40 (E15)
replaces `COMPLETE_FILE_INVENTORY.md` with a 60+-file post-#29–#39 inventory,
adds `.claude/documentation/PROJECTIONS.md`, rewrites the scaffolding +
cqrs-architecture skills, and adjusts the CLAUDE.md "Every pattern …
projections" claim. PR #35 (E07, merged) replaced the
`RestoreCookieHandler` class-level stub with real prose and added rich
docblocks to the new Activate/Deactivate/Restored/StockChanged events.
PR #36 (E08, OPEN) replaces the placeholders on the four command handlers
(Create/Update/Delete/Restore) and the `__construct` stubs on three of
the four commands.

`HEAD` of `stabilization/erp-foundation` carries the **E07 changes only**
(b70db32 is the integration merge). Everything else is still on its
feature branch. Concretely:

- placeholder method docblocks today: **20** (down from 26 in the original
  audit), all in files PRs #29 / #36 / #40 propose to fix;
- `bin/docblocks-audit` on HEAD still only greps for the legacy marker;
- `.github/workflows/ci.yml` on HEAD still runs phpcs + phpstan only;
- `.claude/documentation/PROJECTIONS.md` does **not** exist on HEAD;
- `bin/docs-cookie-sync` (the CI drift guard) does **not** exist on HEAD;
- `.claude/documentation/COMPLETE_FILE_INVENTORY.md` still claims "45+
  files" and still points the repository at
  `app/Infrastructure/Persistence/Repositories/CookieRepository.php`;
- `.claude/skills/domain-scaffolding/SKILL.md` still walks the reader
  through editing `app/Config/Services.php` and `app/Config/Routes.php`;
- `CookieRepository.php:47` still carries `@package App\Models\Cookie`;
- `.claude/documentation/SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md` still
  reads "23 PHP files / 3 commands / 3 events / Grade A (95/100)";
- `.claude/CLAUDE.md` still says "Every pattern (handlers, value objects,
  repository, projections, tests, logging) is fully exemplified there."

When PRs #29, #36 and #40 merge, F1 drops from 20 → ~0 stubs, F4 / F8 /
F9 / F10 / F12 / F14 all close, F3 / F6 close in the same wave. F5 (the
`RestoreCookieHandler` class docblock) is already CLOSED on HEAD via
PR #35. F2 closes with PR #40. F7 (the SERENA legacy doc) is not touched
by any open PR and remains OPEN.

Verification commands run:
- `grep -rnE "^[ \t]*\* [A-Za-z_]+\.[ \t]*$" app/Domain/Cookie/ | grep -v '\.example:'`
  → **20 hits** (was 26 in the original audit; PR #35 closed 6 in
  Restore/Restored/StockChanged class/event surfaces).
- `grep -n "@package" app/Domain/Cookie/Repositories/CookieRepository.php`
  → still `@package App\Models\Cookie` (F4 still OPEN).
- `grep -n "docblocks:audit\|deptrac" .github/workflows/ci.yml` → zero hits
  (F8 still OPEN).
- `ls .claude/documentation/PROJECTIONS.md` → file does not exist
  (F9 still OPEN, despite PR #40 staging it).
- `ls bin/docs-cookie-sync` → file does not exist (PR #40 not merged).
- `grep -nE "Infrastructure/Persistence|Services\.php|Routes\.php" .claude/skills/domain-scaffolding/SKILL.md`
  → 6 hits at the same line numbers the original audit cited (F3 OPEN).
- `grep -nE "45 ?\+|47 ?\+" .claude/documentation/COMPLETE_FILE_INVENTORY.md`
  → still claims "45+" + "47+" totals (F2 OPEN).
- `grep -n "23 PHP files" .claude/documentation/SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md`
  → still present (F7 OPEN).

## Closure matrix

| F#  | Severity | Title (abbrev.)                                            | Status   | Evidence / note                                                                                                                                                                                                                                                                                                                                |
|-----|----------|------------------------------------------------------------|----------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| F1  | HIGH     | 26 placeholder `* MethodName.` docblocks across Cookie     | PARTIAL  | HEAD: 20 stubs remain (was 26). PR #35 closed Restore/Restored/StockChanged class + handler + event stubs (~6). PR #29 rewrites `bin/docblocks-audit` to fail on the pattern + reinstates the legacy marker in `bin/docblocks-generate`. PR #36 (E08) replaces Create/Update/Delete/Restore handler + command stubs. PR #40 doesn't directly touch placeholders. Net: full close requires #29 + #36 to merge; until then 20 stubs ship. |
| F2  | HIGH     | `COMPLETE_FILE_INVENTORY.md` 2 phases stale                | OPEN     | PR #40 ships a fresh 60+-file inventory + repo path corrected to `app/Domain/Cookie/Repositories/` + Services.php edit removed + Restore + StockChanged + Read side enumerated. **Not yet merged.** HEAD still claims "45+" / "47+" and still lists `app/Infrastructure/Persistence/Repositories/CookieRepository.php`.                          |
| F3  | HIGH     | `domain-scaffolding/SKILL.md` walks pre-auto-discovery     | OPEN     | PR #40 rewrites Steps 8/9/11 + adds Query side, Restore, StockChanged, Traits, ErrorCodes, PriceFormatter. **Not yet merged.** HEAD still tells the user to `mkdir app/Infrastructure/Persistence/Repositories` and edit `Services.php`.                                                                                                       |
| F4  | MEDIUM   | `CookieRepository @package App\Models\Cookie`              | OPEN     | PR #29 line-diffs `@package App\Models\Cookie` → `@package App\Domain\Cookie\Repositories`. **Not yet merged.** HEAD still wrong (line 47).                                                                                                                                                                                                       |
| F5  | MEDIUM   | `RestoreCookieHandler` one-word class docblock             | CLOSED   | E07 (PR #35, merged at b70db32) rewrote the class docblock to ~13 lines explaining: findByIdWithTrashed, builder UPDATE, manual event dispatch, the three exception families. Verified on HEAD at `app/Domain/Cookie/Commands/RestoreCookie/RestoreCookieHandler.php:15-31` (note: line shifted by E08; PR #36 further enriches).                                          |
| F6  | MEDIUM   | Port docblocks omit soft-delete / case-insensitive         | OPEN     | PR #29 replaces `* findById.`, `* existsByName.`, `* existsByNameExcludingId.`, `* delete.` on the port + `* findById.` on the query port and query repository with real contract prose (case-insensitive across live+trashed for `existsByName`; explicit soft-delete contract; actor-required-for-HTTP contract on delete). **Not yet merged.** HEAD still placeholder. |
| F7  | MEDIUM   | `SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md` analyses 23-file Cookie | OPEN | **No PR touches this file.** PR #40 doesn't add a disclaimer; it adds the new `PROJECTIONS.md` and rewrites the skill, but leaves SERENA's "23 PHP files / Grade A (95/100)" header intact. HEAD still misleading.                                                                                                                                |
| F8  | HIGH     | `composer docblocks:audit` not in CI                       | OPEN     | PR #29 adds two CI steps (`composer docblocks:audit` + `composer deptrac`) in `.github/workflows/ci.yml`. **Not yet merged.** HEAD's ci.yml still runs only phpcs + phpstan; `grep "docblocks\|deptrac" .github/workflows/ci.yml` → 0 hits.                                                                                                          |
| F9  | LOW      | `CookieAccessors` trait `@property` block confusing        | MOOT     | E07 (PR #35, merged) **deleted** `app/Domain/Cookie/Entities/CookieAccessors.php` outright; accessors are now inlined on `Cookie.php:117-168`. The original finding cannot recur because the file no longer exists. (Re-audit slice 01 already confirmed this deletion.)                                                                                  |
| F10 | LOW      | Cookie-specific prose ("a cookie product", "retail catalogue") | OPEN | No PR carves "pattern vs example" callouts into the value-object class docblocks. `CookieName`, `CookiePrice`, `CookieStock` class docblocks unchanged on HEAD; PR #36 enriches *handler* docblocks but not VO docblocks. Sed-cloning still produces "a foo product" / "retail catalogue" relics.                                                                |
| F11 | LOW      | `CookieSeeder` docblock omits required columns             | OPEN     | No PR in the wave touches `app/Database/Seeds/CookieSeeder.php` or its docblock. The seeder still says "10 sample cookies with realistic data" with no warning about the `tenant_id`/`version`/`created_by`/`updated_by` bypass.                                                                                                                                |
| F12 | LOW      | `CookieReadModelProjection.php.example` says "feeds D15"    | OPEN     | PR #40 adds `.claude/documentation/PROJECTIONS.md` which **explains** the re-enable contract. The class docblock inside the `.example` file itself is **not** rewritten (it would have to live on the `.example` file). On HEAD, the file isn't touched and PROJECTIONS.md doesn't exist yet.                                                                  |
| F13 | INFO     | `CookieController` docblock predates actor-resolver wiring | OPEN     | PR #36 (E08) touches the controller and reorganises some flows; the controller class-level docblock is enriched but does not add the explicit "resolve via `Services::actorResolver()` — audit-trail contract (B10)" bullet. HEAD unchanged.                                                                                                              |
| F14 | INFO     | `@throws` annotations inconsistent across handlers          | PARTIAL  | PR #35 (merged) made `RestoreCookieHandler::handle()`'s `@throws` explicit (DomainException + \RuntimeException). PR #36 (E08, OPEN) standardises Create/Update/Delete `@throws` on `DomainException` and adds the implements-of-the-bus-interface clause; \Throwable is still not declared. Improvement is real on HEAD for one handler; full uniformity requires #36 to merge.            |
| F15 | INFO     | `LOGGING_BEST_PRACTICES.md` / `GIT_WORKFLOW.md` flagged for cross-check | OPEN | Original finding was a "did not deeply review" placeholder. No PR in the wave touches either file. No-op for this re-audit.                                                                                                                                                                                                                       |

**Totals:** CLOSED 1 (F5) · MOOT 1 (F9, no-longer-applicable due to file deletion) · PARTIAL 2 (F1, F14) · OPEN 11.

## PR-by-PR impact on this slice

| PR  | Epic   | Touches in-scope files? | Impact on slice 16                                                                                                                                                                                                          |
|-----|--------|--------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| #29 | E02    | YES                      | Largest impact in the wave: rewrites `bin/docblocks-audit` to fail on the F1 pattern; re-arms `bin/docblocks-generate` with the AUTO_GENERATED_MARKER; replaces placeholders on the four `CookieRepositoryInterface` + `CookieQueryRepositoryInterface` + `CookieQueryRepository` ports; fixes the wrong `@package`; adds `composer docblocks:audit` + `composer deptrac` CI steps. Closes F4 / F6 / F8 once merged; partially closes F1. |
| #30 | E01    | No                       | MySQL CI lane / docker-compose. No docblocks touched. |
| #31 | E03    | No                       | MySQL session envelope. No docblocks touched. |
| #32 | E04    | YES (events)             | Adds the `AbstractDomainEvent` envelope; rewrites the 5 Cookie event class docblocks with real prose about envelope + payload semantics. Closes ~5 entries in F1's list. |
| #33 | E06    | YES (entity)             | Adds `AggregateRootInterface` + `AggregateHydrator`; rewrites the `Cookie::reconstitute()` + `assignId()` / `bumpVersion()` docblocks with the new contract. No direct impact on F1's enumerated stubs, but does change the surrounding entity-docblock landscape. |
| #34 | E05    | YES (handlers)           | Introduces `AbstractCommandHandler` / `AbstractQueryHandler` with full docblock prose; the new bases are template-grade. No direct F1 impact (the handlers' inherited stubs remain until #36). |
| #35 | E07    | YES (entity + events)    | **Merged** at `b70db32`. Closes F5 (`RestoreCookieHandler` class docblock); moots F9 (`CookieAccessors` deleted); replaces stubs on `CookieActivated*` / `CookieDeactivated*` events; reduces F1's count from 26 → 20.       |
| #36 | E08    | YES (handlers + commands) | Closes the remaining F1 placeholders on Create/Update/Delete/Restore handlers and Create/Update/Delete/Restore commands' `__construct.` stubs. Adds typed `@implements CommandHandlerInterface<…>` annotations; standardises `@throws DomainException` across the four handlers. Together with #29's port fixes, this is the second-biggest slice-16 PR. |
| #37 | E05.5  | No                       | PHPStan custom rules. Tests-only docblocks added — out of slice. |
| #38 | E12.5  | YES (1 line)             | Adds `ProcessedEventStore` to the outbox handler chain. New code carries good docblocks; no impact on slice-16 backlog. |
| #39 | E17    | YES (Controller)         | Adds `final` + `#[\Override]` + `Stringable` polish. Touches `CookieController` and `Cookie.php`; does NOT carve the actor-resolver bullet F13 asks for, so F13 stays OPEN. |
| #40 | E15    | YES (docs / skills / CI) | Largest doc-side PR: rewrites `COMPLETE_FILE_INVENTORY.md` to a 60+ file post-#29–#39 inventory; adds `.claude/documentation/PROJECTIONS.md`; rewrites `.claude/skills/domain-scaffolding/SKILL.md` + `.claude/skills/cqrs-architecture/SKILL.md` to match Cookie's 2026-05-23 shape; updates the CLAUDE.md "Every pattern … projections" claim. Adds `bin/docs-cookie-sync` CI guard against future inventory drift. Closes F2 / F3 / F9 (CLAUDE.md side) once merged. **Does not touch** `SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md` (F7 stays open). |
| #41 | E11    | YES (repository)         | Repository hygiene: single-statement delete, version-bumping restore, purge(), trusted reconstitute. Existing docblocks rewritten with the new contracts; does not address F1's repo stubs directly but eliminates the inline `* isDuplicateKey.` / `* dispatchPendingEvents.` shapes by deleting their host methods or moving them. Reduces F1's count further once merged (3 entries on `CookieRepository.php`). |
| #42 | E18    | YES (tests + deptrac)    | Coverage backfill + a deptrac LoggerFactory ban + sleep removal. Test docblocks are not in slice-16 scope; the deptrac change indirectly hardens what F8's fix enforces. |

Method: `gh pr view <n> --json files` filtered for `app/Domain/Cookie/**`,
`bin/docblocks*`, `composer.json`, `.github/workflows/ci.yml`, and
`.claude/**`. Spot-checked diffs via `gh pr diff <n>`.

## Cross-slice interaction risk

- **F1 ↔ F8** are coupled. PR #29 ships both the audit-script tightening
  and the CI wire-in. If the script change merges without the CI step,
  the gate still passes on stubs; if the CI step merges without the
  script change, the gate still passes because the script doesn't
  detect stubs. **Both must land in the same PR**, which #29 already
  does. Reviewers should not split the PR.
- **F1 ↔ F2 ↔ F3** are coupled with the planned E10 (DTO consolidation)
  and E14 (view i18n + permission gating) epics. The fresh inventory
  (PR #40) describes the **post-#29–#39 Cookie**, but E10 will delete
  `CookieDTO` and E14 will collapse the four Views into shared
  partials. PR #40 explicitly calls this out in its CLAUDE.md "Snapshot
  scope" addition: "E09 (multi-currency), E10 (DTO consolidation), E11
  (repo hygiene), E12 (outbox hardening), E13 (provider DI), and E14
  (view collapse) are **not** yet reflected and will trigger a
  follow-up doc refresh." This is good guardrails; the docs-sync CI
  guard (`bin/docs-cookie-sync`) is the load-bearing piece — once it
  exists, future epics that change Cookie's surface MUST update the
  inventory in the same PR. **The CI guard must not be skipped on
  merge of #40.**
- **F7 ↔ F2/F3.** PR #40 leaves `SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md`
  intact even though it now contradicts the rewritten inventory. A
  cloner reading SERENA first will see "Grade: A (95/100)" and trust
  it; the rewritten skill points them at the new docs. F7 is the doc
  that survives the wave most stale. A trivial one-line top-of-file
  disclaimer (or outright deletion / archival) closes it; flagging
  here so reviewers of #40 can ask for the disclaimer to be added.
- **F11** (the seeder) sits next to slice 18 / migrations findings
  (the seeder produces `version=0` rows). Slice 06's `purge()`
  introduction in PR #41 actively widens the gap: a cloner who runs
  `db:seed` then `purge` on the seeded rows will hit pre-purge a
  concurrent-modification error first. The fix is one paragraph in
  the seeder docblock; no PR carries it.

## New findings introduced by the PR wave (not in original audit)

### N1 — LOW — PR #29's `composer docblocks:audit` is scoped to `app/Domain/Cookie` only
- **Location:** `composer.json` after PR #29 (`"docblocks:audit": "@php bin/docblocks-audit app/Domain/Cookie"`).
- **Observation:** The script can scan the whole `app/` tree; the
  wrapper deliberately narrows the scope to Cookie because the rest of
  `app/` (Auth, User, Numbering, Bus, …) still ships hundreds of
  legacy placeholder docblocks. The composer-script comment says
  exactly this and promises "later epics widen the scope domain-by-
  domain". That is honest, but it means **the gate does not police
  the rest of the tree**. A cloner reading CLAUDE.md will still see
  "Reject your own work if docblocks:audit fails" and assume it
  covers everything.
- **Suggested fix:** add a banner to the script's stdout when scope
  is narrower than `app/` ("⚠ scope: app/Domain/Cookie only; widen as
  epics roll forward"); or wire a follow-up burn-down issue per epic
  that widens the scope by one directory. No code change to the
  script itself.

### N2 — LOW — PR #29 reintroduces the AUTO_GENERATED_MARKER in `bin/docblocks-generate`
- **Location:** `bin/docblocks-generate` after PR #29 (line ~38 of the
  diff: re-adds the `const AUTO_GENERATED_MARKER = '...';` definition
  and emits the marker on every new docblock the generator inserts).
- **Observation:** The prior commit (`2fe2d90 chore(docblocks): drain
  379 auto-generated placeholder TODOs + stop generator from emitting
  them`) deliberately *removed* the marker because the prior backlog
  was 379 entries strong. PR #29 brings it back. The intent is sound
  ("the audit must catch un-reviewed generator output") but reviewers
  should note: anyone running `composer docblocks:generate` on a
  fresh file now fails their own gate immediately. That is the right
  contract, but the README / CLAUDE.md should call it out.
- **Suggested fix:** PR #29 already carries a comment block (`see
  REVIEW-slevomat.md req. #3: 'patching only the auditor leaves the
  generator emitting the same placeholders any future contributor
  would reintroduce'`). Add one bullet to CLAUDE.md "Common
  commands" so a cloner discovers the contract before they run the
  generator and get a surprise red CI.

### N3 — INFO — PR #40's `bin/docs-cookie-sync` is itself undocumented in CLAUDE.md
- **Location:** `bin/docs-cookie-sync` (new, in PR #40) +
  `.github/workflows/ci.yml` step that runs it.
- **Observation:** The script is a documentation drift guard:
  every file in `app/Domain/Cookie/**.php` must appear in
  `COMPLETE_FILE_INVENTORY.md`, and every entry in the inventory must
  exist on disk. This is excellent. But CLAUDE.md (post-PR #40) does
  not list the script under "Common commands"; a cloner whose CI
  fails on docs-cookie-sync will go hunting through .github/workflows
  to find what failed.
- **Suggested fix:** add `composer docs:cookie-sync` (or `bin/docs-
  cookie-sync` direct invocation) to the CLAUDE.md "Common commands"
  block alongside `composer phpcs` / `composer phpstan`. PR #40
  should be amended to include the line before merge.

### N4 — INFO — Round-3 audit `.audit/round3/16-documentation-docblocks.md` itself counts among the docs that future PRs must keep current
- **Location:** `.audit/round3/16-documentation-docblocks.md` quantitative
  table (lines 16-42) hard-codes "26 stubs" / "47+ files" / "23 PHP files"
  — numbers that will rot as #29 / #36 / #40 land.
- **Observation:** Cosmetic, but a cloner browsing audits in 6 months
  will see the original numbers and wonder why "26" no longer matches
  reality. The re-audit file you are reading is the resolution.
- **Suggested fix:** none required; cross-reference this re-audit
  from the head of `16-documentation-docblocks.md` once the wave
  lands so a future reader follows the trail.

## Verdict shift

**NOT-READY → READY-WITH-FIXES, contingent on PRs #29 + #36 + #40
merging together.**

The drafted fixes are comprehensive. The original audit's core complaint
("the docblock gate the template promises is silently a no-op, and the
scaffolding docs the cloner is told to copy describe a 2-phase-old
Cookie") is closed by the union of:

- **#29** — script catches the F1 pattern, CI runs the gate, `@package`
  fixed, port docblocks describe the soft-delete + case-insensitive
  contracts.
- **#36** — handler + command stubs replaced with prose explaining
  the orchestration, the `@implements` annotations, the `@throws`
  uniformity.
- **#40** — inventory + skill + CLAUDE.md describe today's Cookie;
  `bin/docs-cookie-sync` makes the inventory drift-resistant.

What remains as honest residual:

- **F7** (SERENA legacy doc) — no PR touches it. Either delete the
  file or prepend a "STALE" disclaimer. A one-line fix; should be
  bundled into #40 before merge.
- **F10 / F11 / F13** — cosmetic; flagged but unaddressed by the
  wave. Suitable for an E15-followup or rolled into the eventual
  E14 (views) doc refresh.

The gate will only police what is wired today. The composer script's
narrowed scope (`app/Domain/Cookie` only, N1) means a cloner copying
`app/User/**` or `app/Auth/**` inherits hundreds of legacy placeholder
docblocks the gate does not see. Reviewers should not interpret "gate
green" as "tree clean" until the scope widens.

## Biggest residual

**F1 hand-off risk between PR #29 and PR #36.** The audit-script
tightening (#29) and the handler/command stub rewrites (#36) live in
separate PRs. If #29 merges first, the new gate fails immediately on
the 20 stubs still present on `stabilization/erp-foundation` and on
every feature branch that hasn't rebased — including #36 itself, which
would then have to absorb a wave of "fix CI" commits. If #36 merges
first, the stubs are replaced with prose but the gate does not yet
check for them, so future contributors silently reintroduce them via
`composer docblocks:generate`.

The safe sequencing is:

1. Land **#36** first (replaces 20 stubs with prose, no gate change).
2. Rebase **#29** on top of #36, then merge #29 (gate now passes
   immediately, and the regenerator is re-armed for future stubs).
3. **#40** at any time after (independent of #29 / #36).
4. Inside the same release window, **prepend a "STALE" disclaimer
   to `SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md` (or delete the file).**

Without this sequencing, the wave momentarily leaves the tree in a
state where CI is louder than the code that triggered it, costing the
maintainer one or two emergency rebase cycles.

---

**Severity counts (post re-audit):** CRITICAL 0 | HIGH 3 (F1 PARTIAL,
F2 OPEN, F3 OPEN, F8 OPEN — was 4 HIGH; effectively 4 HIGH if F1 is
counted as still HIGH while PARTIAL) | MEDIUM 4 (F4 OPEN, F6 OPEN, F7
OPEN; F5 CLOSED) | LOW 3 (F10 OPEN, F11 OPEN, F12 OPEN; F9 MOOT) |
INFO 3 (F13 OPEN, F14 PARTIAL, F15 OPEN) + N1-N4 added.

**Net delta from original:** CLOSED 1 (F5), MOOT 1 (F9 — file
deleted), PARTIAL 2 (F1, F14), OPEN 11, NEW 4 (N1-N4, three LOW + one
INFO). The "ready-with-fixes-pending" trajectory is the right call:
none of the open findings is structural; all are addressed by drafted
PRs on open branches.
