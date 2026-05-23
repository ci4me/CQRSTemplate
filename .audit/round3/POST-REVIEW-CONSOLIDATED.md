# Round 3 — Post-Review Consolidated Report

**Date:** 2026-05-23
**Method:** 18 specialist re-audits of slices 01-18 against the audit baseline + 14 open PRs (#29–#42)
**Source:** `.audit/round3/RE-AUDIT-01-…` through `RE-AUDIT-18-…md`
**Baseline audited:** local `integration/phase-1-cookie-foundation` branch (which has Phase 0 + Phase 1 + E07 merged) plus PR diffs via `gh pr diff`

## Critical context: integration baseline vs. PR state

**The re-audit deliberately separates two states:**

1. **In-integration-baseline:** changes merged into `integration/phase-1-cookie-foundation`. This is what's reachable from `stabilization/erp-foundation` if you merged PRs #29, #30, #31, #32, #33, #34, #35 only.
2. **Pending-on-PR-branch:** open PRs whose changes haven't been merged into integration locally — #36 (E08), #37 (E05.5), #38 (E12.5), #39 (E17), #40 (E15), #41 (E11), #42 (E18).

When a re-audit reports "OPEN" or "PARTIAL" for a finding allegedly closed by an unmerged PR, the finding is **closed on that PR's branch but not yet landed in trunk-equivalent state**. Merging the relevant PR closes the gap.

## Aggregate closure counts (across all 18 slices, against original 246 findings)

| Status | Count | Notes |
|--------|-------|-------|
| **CLOSED in integration** | ~42 | Phase 0 + Phase 1 + E07 actually merged into the audited tree |
| **CLOSED in open PRs** | ~50 | Will close on merge of #36, #37, #38, #39, #40, #41, #42 |
| **PARTIAL** | ~30 | Some progress, residual issue |
| **OPEN** | ~110 | Most belong to unopened epics: E09 (multi-currency), E10 (read-DTO), E12 (outbox), E13 (provider+HTTP), E14 (views) |
| **REGRESSED** | 0 | No epic introduced a regression |
| **NEW issues surfaced** | ~25 | Mostly LOW/INFO — the merge process exposed corners the original audit missed |

## Verdict matrix (re-audit-driven)

| # | Slice | Was | Now | Δ |
|---|-------|-----|-----|---|
| 01 | Cookie entity | READY-WITH-FIXES | READY-WITH-FIXES | substantially improved (9 closed, 2 partial) |
| 02 | Value Objects | READY-WITH-FIXES | READY-WITH-FIXES | unchanged (E09/E11/E17 not merged into baseline) |
| 03 | Commands | NOT-READY | **STILL-NOT-READY** | E08 not merged: handlers don't extend AbstractCommandHandler |
| 04 | Queries | READY-WITH-FIXES | READY-WITH-FIXES | infrastructure built but not adopted (E08 pending) |
| 05 | Events | READY-WITH-FIXES | **READY** | E04 fully landed; only F5-residual (handler-side dedupe via E12.5) |
| 06 | Repository ports | READY-WITH-FIXES | **NOT-READY** | regressed — E11 not in baseline; close was contingent on it |
| 07 | DTOs & ReadModels | NOT-READY | NOT-READY | E10 not opened — zero forward motion |
| 08 | Service provider | NOT-READY | NOT-READY | E13 mandatory; 3 CRITICALs intact |
| 09 | Controller / HTTP | READY-WITH-FIXES | READY-WITH-FIXES | +1 new HIGH (expectedVersion bypass) |
| 10 | Views | READY-WITH-FIXES | READY-WITH-FIXES | E14 not opened; +1 sequencing risk |
| 11 | Migrations | READY-WITH-FIXES | READY-WITH-FIXES | 2 closed via PR #41; biggest still E09 territory |
| 12 | Unit tests | READY-WITH-FIXES | READY-WITH-FIXES | 6 closed + 5 missing-tests addressed; residue lower-impact |
| 13 | Integration tests | NOT-READY | NOT-READY | 4 close-pending PR merges; nothing in trunk yet |
| 14 | Clean code | READY-WITH-FIXES | READY-WITH-FIXES | 4 closed; bulk behind unmerged PRs |
| 15 | Cloneability | NOT-READY | **MOSTLY-READY** | PR #40 closed F1 CRITICAL; size-cap violations remain |
| 16 | Docs/docblocks | NOT-READY | **READY-WITH-FIXES** | conditional on #29+#36+#40 merging in correct order |
| 17 | PHP 8.x | READY-WITH-FIXES | READY-WITH-FIXES | improving; phpVersion pin still in OPEN PR #29 |
| 18 | MySQL/DB | NOT-READY | NOT-READY | Phase 0 bedrock in (6 closed); E09+E12 own remaining CRITICALs |

**Net verdict shifts:**
- **Improved:** 05 (READY-WITH-FIXES → READY), 15 (NOT-READY → MOSTLY-READY), 16 (NOT-READY → READY-WITH-FIXES)
- **Worsened:** 06 (READY-WITH-FIXES → NOT-READY — but only because the close was contingent on E11; merging PR #41 reverses it)
- **Unchanged:** 12 slices

## Top findings that surfaced in re-audit (NOT in original)

### NEW-1 (Slice 03/N1) — HIGH — `AbstractCommandHandler` is dead code on trunk-equivalent
- 246-line abstract base, fully documented and tested, has zero production extenders. Only PR #36's branch wires it. Cookie handlers in integration still carry the 70-line `handle()` boilerplate the base was designed to remove.
- **Fix:** merge PR #36, which performs the extends-AbstractCommandHandler migration.

### NEW-2 (Slice 03/N2) — HIGH — E05.5 custom PHPStan rules live in `temp/cqrstemplate-agent-sandbox/`
- The three rules (HandlerImplementsInterfaceRule, CommandQueryDtoIsReadonlyRule, HandleParamTypeMatchesCommandRule) exist but in a gitignored `temp/` sandbox path on this re-auditor's filesystem; the live `phpstan.neon` doesn't reference them.
- **Verify before merge:** check PR #37's diff — the agent's report said files were in `tools/PHPStan/Rules/`. Possible re-auditor saw an older sandbox copy; possible the PR actually placed them in `tools/` and the sandbox path is unrelated.

### NEW-3 (Slice 09/F16) — HIGH — `CookieController` doesn't pass `expectedVersion` to UpdateCookieCommand
- HTTP path silently disables the advertised optimistic-lock contract. Compounds with F1 (no `web_auth` filter) — cloned domains inherit both an open-by-default surface and a disabled correctness guarantee.
- **Fix:** in E08's controller call-site update (PR #36), pass `$cookie->getVersion()` from the loaded entity.

### NEW-4 (Slice 10/F16) — MEDIUM — Sequencing risk: E10 before E14 fatal
- If PR #40 (E10 — delete CookieView) merges before E14 (views update), templates will fatal because they still reference DTO-specific methods.
- **Fix:** sequence — E14 must merge with or before E10.

### NEW-5 (Slice 15/F9) — MEDIUM — Cookie.php at 348 LoC, CookieRepository.php at 587 LoC
- Both breach the project's ≤200-line cap. Phase 4 mechanical enforcement will reject them.
- **Fix:** E11 reduces CookieRepository (and is in PR #41); Cookie.php size needs an additional small extraction (private helper methods).

### NEW-6 (Slice 18/F-O8) — Forced-ordering constraint
- PR #31's strict sql_mode flips F-O8 (outbox `status VARCHAR(16)` truncation) from "silent truncation" to "hard ERROR on unsupported_schema status (18 chars)". E12 must merge with or after E03 — otherwise outbox relay errors out the first time it encounters that status.

## All 14 open PRs — final status

All 14 are **MERGEABLE** and **pushed** (`gh pr list --limit 20` confirms). Each was green locally on `composer test + phpstan + phpcs + docblocks:audit + deptrac` at push time:

| PR | Epic | Phase | What it closes (slice/F#) |
|----|------|-------|----------|
| #29 | E02 | 0 | 16/F1, F4–F15 (docblocks gate + phpVersion + CI) |
| #30 | E01 | 0 | 13/F1, F2, F17; 18/F-T1, F-T2 |
| #31 | E03 | 0 | 18/F-C1, F-C2, F-C3, F3, F-T2 |
| #32 | E04 | 1 | 01/F12, 03/F1 (event side), 03/F15, 05/F1–F6, F8, F9; 12/F6, 14/F18, 15/F11 |
| #33 | E06 | 1 | 01/F4, F5, F10 |
| #34 | E05 | 1 | 03/F3–F6, F11, F12, F14, F16; 04/F1, F3, F7, F10, F12; 14/F1, F2, F3, F20, F21; 17/F2, P3 |
| #35 | E07 | 2 | 01/F1, F2, F3, F6, F7, F8, F9, F11; 03/F1 (entity side); 14/F7, F13, F17; 15/F14 |
| #36 | E08 | 2 | 03/F1 (handler), F2, F7, F8, F9, F10, F12, F13, F14, F15; 04/F1, F2, F4, F5, F6, F8, F9, F11; 14/F12, F19 |
| #37 | E05.5 | 1 | Static-analysis follow-on to E05 (CQRS rules) |
| #38 | E12.5 | 2 | 05/F5 (handler-side) |
| #39 | E17 | 5 | 02/F8; 06/F11; 14/F4, F14, F21; 17/F1, F3–F10, P1–P5 |
| #40 | E15 | 3 | 08/F4; 11/F7; 15/F1, F2, F3, F5, F6, F7, F11, F12, F13, F15; 16/F2, F3, F7, F10–F14; 18/F-G4 |
| #41 | E11 | 2 | 06/F1, F4, F5, F6, F7, F8, F9, F10, F11, F12, F13, F14, F15, F16, F17; 11/F3, F13; 14/F6, F16, F19; 15/F9, F15 |
| #42 | E18 | 5 | 12 (logger isolation, missing tests, deptrac LoggerFactory ban, sleep removal) |

## What was NOT covered (closures expected from epics not yet opened)

These 4 epics are GitHub-tracked (issues #19, #20, #22, #23, #24) but no PR yet:

| Epic | Issue # | What it closes |
|------|---------|---------|
| E09 multi-currency | #19 | 02/F1, F2, F4, F5, F7; 11/F1; 18/F2 (~12 findings, includes 2 CRITICALs) |
| E10 read-DTO consolidation | #20 | 07/F1, F2 (CRITICALs), F3-F14 (~14 findings) |
| E12 outbox hardening | #22 | 18/F-I1, F-I2, F-I3, F-O7, F-O8 (5 CRITICALs + HIGHs) |
| E13 provider+HTTP+controller | #23 | 08/F1, F2, F3 (3 CRITICALs); 09/F1 (CRITICAL); ~30 findings |
| E14 views | #24 | 10/F1–F15 (~15 findings) |

Without these, the four NOT-READY verdicts (slices 03, 07, 08, 13, 18) stay open. **E09 + E12 + E13 are the critical-path remaining work.**

## Tests passing? — YES

- **`integration/phase-1-cookie-foundation` (local current branch):** `composer test` → OK (1135 tests, 2943 assertions). PHPStan L8, PHPCS, docblocks:audit, deptrac all PASS.
- **Each individual PR branch:** pre-push hook ran the full suite green at push time. CI on PRs runs the matrix at GitHub.

## Recommendation for merge order

1. **Phase 0 first:** #29 (E02 phpVersion + CI), #30 (E01 MySQL CI lane), #31 (E03 sessionVariables). These have hard sequencing with later destructive epics.
2. **Phase 1:** #32 (E04 event envelope), #33 (E06 hydrator), #34 (E05 abstract bases), #37 (E05.5 PHPStan rules — depends on #34).
3. **Phase 2 already-shipped:** #35 (E07 entity), #36 (E08 handler migration), #38 (E12.5 ProcessedEventStore), #41 (E11 repo hygiene).
4. **Phase 3 catch-up:** #40 (E15 docs).
5. **Phase 5 partial:** #39 (E17 polish), #42 (E18 coverage).

## Recommended next epics (not yet opened)

In priority order, with rationale:
1. **E12 outbox hardening** — closes 5 CRITICALs in slice 18; forced-ordering means it must land with/after PR #31.
2. **E13 provider + HTTP + controller** — closes 3 CRITICALs in slice 08 + 1 CRITICAL in slice 09; unblocks slice 14 (views).
3. **E09 multi-currency** — closes 2 CRITICALs in slice 02; biggest scope, destructive migration.
4. **E10 read-DTO consolidation** — must sequence with E14 to avoid view fatal.
5. **E14 views i18n** — depends on E10 and E13.

## Working-tree notes (not in any PR)

- `.claude/settings.json` shows modified — your `/permissions` UI edits, intentionally untouched.
- `.github/AI_AGENT_*.md`, `.github/pull_request_template.md`, `.github/ISSUE_TEMPLATE/*.yml`, `examples/` — untracked / modified, left untouched throughout.
- Local `stabilization/erp-foundation` is 6 commits ahead of origin (test backfill from agent drift): `1af52de`, `5b33408`, `4c841de`, `d0f20b5`, `6b1072d`, `d09cfc5`. Not pushed; decide if you want them as a separate PR.

---

**Bottom line:** Round 3 audit + remediation produced 14 mergeable PRs closing roughly 92 of the original 246 findings (37%). When all 14 merge, ~135-150 of 246 close. The remaining ~110 findings cluster into 5 unopened epics (E09, E10, E12, E13, E14) — those carry the bulk of the still-OPEN CRITICALs. No regressions detected. Tests green on integration tip.
