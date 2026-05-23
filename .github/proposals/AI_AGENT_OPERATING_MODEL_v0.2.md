# AI Agent Operating Model — v0.2 / v0.3-rebalance (Working Draft)

**Status:** Working draft, mid-rebalance.
**Date:** 2026-05-22.
**Predecessor:** [`../AI_AGENT_OPERATING_MODEL.md`](../AI_AGENT_OPERATING_MODEL.md) (v0.1, 5,515 lines).
**Goal:** ship the mechanically enforceable 10% of v0.1 — **without losing the rich persona ecosystem** the user wants from v1.

## 0. Course correction (v0.2 → v0.3-rebalance)

v0.2 over-cut. It collapsed v1's 20 personas to 4 in the name of mechanical enforceability. User feedback (2026-05-22): "I like having 30+ agents with their own specialization, knowledge, etc."

**Rebalance principle:** keep v0.2's mechanical-enforcement discipline (single Action, deterministic risk label, no unenforceable rules pretending to be enforced) **and** restore v1's full persona catalog (expanded to 32). Personas activate by labels — a 32-persona roster does NOT mean 32 reviews per PR; it means the right specialist is launched when its lens applies.

**Restored from v1 (was deferred in v0.2):**
- Full persona catalog (now 32 specialists; see §11).
- Audit protocol — read-only audit → findings → remediation issues → verification (v1 §18B).
- Knowledge mirror design (v1 §10) — design only, generator deferred.
- Cost / context-pack architecture (v1 §16A) — pack levels Tiny/Standard/Deep/Full quorum.
- PromptOps continuous-improvement loop (v1 §18A).
- Multi-quorum audit clearance for `risk:critical` audit findings (v1 §9F).
- Dissent log / debate thread / decision-record templates (v1 §19A) — but only required for `risk:high+`.

**Still cut from v1:**
- Per-persona GitHub accounts (not mechanically feasible — see §2).
- "Same model session" self-approval detection (not mechanically feasible).
- Universal dissent ritual for every non-trivial task (forces fake strawmen).
- Daily/weekly/monthly maintenance plan (aspirational for solo dev — Echo runs ad-hoc instead).
- 80+ labels — kept at ~35 (one per persona-trigger).
- `Governance / PR policy` workflow as required check before it exists (build first, require later).

---

## 1. What v0.2 cuts from v0.1

| v0.1 element | v0.2 status | Reason |
| --- | --- | --- |
| 20 named personas (Ari, Mara, Nico, Vera, Theo, Lina, Dario, Nova, Sofia, Kai, Pax, Iris, Omar, Tessa, Rhea, Milo, June, Echo, Prism, Cora) | Cut to 4 | Solo repo. Extra personas are roleplay, not enforcement. |
| Per-persona GitHub accounts | Deferred | Not feasible without infra. |
| Mandatory dissent check on every non-trivial task | Limited to `risk:high+` | Forces fake-strawman alternatives on obvious changes. |
| Generated memory mirror (`.github/agent-memory/*`) | Deferred | No generator exists; would add 4th sync surface. |
| Audit clearance multi-quorum (§9F) | Deferred | No audit volume yet to justify. |
| 80+ labels | Cut to ~15 | Cognitive load. |
| Daily/weekly/monthly maintenance plan | Cut | Aspirational for solo dev. |
| `Governance / PR policy` workflow listed as required check before it exists | Build first, require later | Otherwise blocks all merges. |
| `stabilization/**` / `release/**` branch protection | Use actual convention (`main`, `epic/*`) | Matches reality. |
| Self-approval rule enforced by "same model session" detection | Honor-code + 1-reviewer rule | Not mechanically detectable. |
| `composer ci` as canonical CI command | Keep `composer check` | Matches existing `composer.json` + CLAUDE.md. |

---

## 2. Roles — 32 personas, label-activated (prompts only, no access-control claims)

Personas are **prompts**, not GitHub accounts. They're activated by issue/PR labels at simulation time. A typical PR launches 3–8 personas; a `risk:critical` audit may launch 12+. The full catalog (mission / lens / triggers / subagent-type backing / authority / forbidden / output) lives in §11. Summary by layer:

### Executive Layer (4)

| Persona | Role | Activates on |
| --- | --- | --- |
| **Ari** | Orchestrator | every issue / every PR |
| **Mara** | Product Owner | every feature, every `risk:high+` |
| **Nico** | Program Manager | every issue triage |
| **Vera** | Risk Officer | every PR (assigns/challenges risk label) |

### Engineering Layer (13)

| Persona | Role | Activates on |
| --- | --- | --- |
| **Theo** | CQRS/DDD Architect | every code PR |
| **Lina** | Backend Implementer | branch + PR authoring |
| **Dario** | DB / Migration | migration files, `area:database` |
| **Nova** | API Contract | `area:api`, controller/route changes |
| **Sofia** | Frontend / UX | views, forms, `area:frontend` |
| **Kai** | DevOps | `.github/workflows/**`, `area:ci` |
| **Pax** | Performance | queries, indexes, repository hot paths |
| **Eve** | Domain Events | `app/Domain/*/Domain/Event/**`, state transitions |
| **Vale** | Value Objects | `app/Domain/*/Domain/ValueObject/**` |
| **Hex** | Hexagonal Boundaries | port/adapter changes, infrastructure layer |
| **Quinn** | Query / Read Model | `app/Domain/*/Application/Query/**`, projections |
| **Bus** | Bus & Middleware | command/query bus, audit middleware |
| **Saga** | Process Manager | multi-step / cross-aggregate flows |

### Assurance Layer (10)

| Persona | Role | Activates on |
| --- | --- | --- |
| **Iris** | Security Officer | `area:auth`, `area:tenant`, secrets/credentials |
| **Omar** | Audit & Compliance | `area:audit`, audit_log/actor/correlation-id changes |
| **Tessa** | Test Lead | every PR with code change |
| **Rhea** | Release Manager | every PR (final merge gate) |
| **Lex** | PHPStan L8 | every code PR |
| **Pico** | PHPCS / Slevomat | every code PR |
| **Deck** | Deptrac Architectural Boundaries | every PR touching `app/**` |
| **Doc** | Docblock Auditor | every PHP file change (the `docblocks:audit` gate) |
| **Cliff** | Coverage Gate | every code PR (≥ 90 % floor) |
| **Mocha** | Test Doubles | mocks/fakes/stubs/in-memory repos |

### Knowledge & Process Layer (5)

| Persona | Role | Activates on |
| --- | --- | --- |
| **Milo** | Memory Librarian | post-merge; durable lessons → Wiki/Discussions |
| **June** | Documentation Curator | `area:docs`, drift between docs and code |
| **Echo** | Retrospective Analyst | failed/reverted PRs, repeated review findings |
| **Prism** | PromptOps | failed simulations, agent invented facts, vague verdicts |
| **Cora** | Token / Cost Architect | before any multi-agent launch; sets context pack level |

**32 personas. 1 GitHub account.** Persona identity is declared in the comment header; no access-control mechanism enforces "Theo cannot also be Lina in the same conversation" — that's honor-code + the `Self-review conflict: Yes/No` field. If you want mechanical enforcement, that requires per-persona service accounts (Phase 3+ decision).

---

## 3. Risk classification — deterministic, path-based

Risk is **assigned by an Action** (`.github/workflows/risk-label.yml`, to be built) based on changed paths. Human/agent can override with an explicit comment.

| Risk | Path triggers | Required reviewers | Decision record |
| --- | --- | --- | --- |
| `risk:low` | `**/*.md`, `docs/**`, `tests/**` only | 1 reviewer | No |
| `risk:medium` | new code under `app/Domain/**` without migration/auth/audit | 1 reviewer | No |
| `risk:high` | `area:auth` / `area:audit` / `area:tenant` paths, new migration, event schema change, `app/Domain/*/Domain/Event/**`, `app/Domain/*/Infrastructure/Repository/**` | 1 reviewer + named lens | Yes |
| `risk:critical` | destructive migration (`-down` modifies columns), permission model rewrite, audit_log schema | 1 reviewer + human comment "approved for critical" | Yes |

**Override mechanism:**
A maintainer or agent may add an explicit label like `risk:override:high→medium` with a one-line justification comment. The merge gate records the override; CI doesn't reject it.

---

## 4. Required artifacts per PR

**Universal (every PR):**
- `Closes #N` in PR body.
- Risk label present (auto-assigned, possibly overridden).
- Acceptance matrix in PR body has no empty rows (existing template — already in repo).
- Existing quality checklist passes (`composer phpstan`, `composer phpcs`, `composer test`).

**For `risk:high`+:**
- Decision record link (GitHub Discussion or issue comment using the existing [`agent_decision_record.yml`](../ISSUE_TEMPLATE/agent_decision_record.yml) form).
- Rollback or "no-op rollback" note in PR body.

**For `risk:critical`:**
- Explicit human approval comment containing the literal string `approved for critical risk` (case-insensitive).
- Migration plan + rollback plan, even if rollback is lossy (must be honest, per v0.1 §24 retrospective rule).

---

## 5. Mechanical enforcement — one workflow

Single new Action: `.github/workflows/governance.yml`. Logic:

```yaml
name: Governance / PR policy
on:
  pull_request:
    types: [opened, edited, synchronize, labeled, unlabeled]

jobs:
  policy:
    runs-on: ubuntu-latest
    steps:
      - name: Closes-issue link present
      - name: Risk label present
      - name: For risk:high+, decision-record link present in PR body
      - name: For risk:critical, "approved for critical risk" comment present
      - name: Acceptance matrix has no empty rows
```

Once green for 2 weeks, add it to required checks for `main`. Until then, it runs in advisory mode (no required status).

**What this workflow does NOT do:**
- It does not classify risk (separate `risk-label.yml` does).
- It does not validate test coverage (existing CI does).
- It does not validate signed commits (existing repo settings do).

---

## 6. Templates — compressed

### 6.1 Standard Review Header (replaces v0.1 11-field header)

```markdown
**Persona:** Theo (Reviewer)
**Lens:** architecture + test
**Verdict:** APPROVE | REQUEST CHANGES | COMMENT
**Source:** PR diff + issue #N + CI run
**Self-review conflict:** No
```

That's it. The fallibility reminder lives once in the workflow guide, not in every comment.

### 6.2 Decision Record (replaces v0.1 ADR + dissent + sign-off table)

```markdown
**Decision:** <one sentence>
**Why:** <one paragraph>
**Rejected alternatives:** <bullets, or "none viable — single approach, see context">
**Risk if wrong:** <one sentence>
**Rollback:** <one sentence, or "n/a">
**Approver:** <persona or human>
```

Six fields. If you need more than that, the decision is too big — split it.

### 6.3 Merge Gate Comment (replaces v0.1 15-row table)

```markdown
**Persona:** Rhea (Release)
**Verdict:** MERGE READY | BLOCKED

| Gate | Status |
| --- | --- |
| Linked issue | PASS/FAIL |
| Risk label | PASS/FAIL |
| Acceptance matrix complete | PASS/FAIL |
| Required checks green | PASS/FAIL |
| Reviewer approved latest push | PASS/FAIL |
| Decision record (if risk:high+) | PASS/FAIL/N/A |
| Critical approval comment (if risk:critical) | PASS/FAIL/N/A |

**Exact blockers (if BLOCKED):** <list>
```

---

## 7. Phase 1 migration checklist (≤2 hours)

1. **Delete the nested git repo** in `examples/agent-operating-simulation/.git/` (or convert to submodule).
2. **Move v0.1** (`.github/AI_AGENT_OPERATING_MODEL.md`) to `.claude/documentation/AI_AGENT_OPERATING_MODEL_v0.1_archive.md`. Leave a 50-line stub at the old path pointing to v0.2.
3. **Consolidate issue templates**: drop `ai_agent_workflow.yml` (overlaps with `feature_request.yml`); keep `agent_decision_record.yml` for `risk:high+` work only.
4. **Trim `AI_AGENT_PROMPTS.md`** to a 30-line "where to find the prompt sources" pointer; canonical prompts live in §6 of this file + the [`agent-orchestration` skill](../../.claude/skills/agent-orchestration/SKILL.md).
5. **Verify PR template** (existing) is consistent with §4. (It already covers most fields.)
6. **Add minimal `risk-label.yml` Action** (path-based labeler — single file, ~60 lines of YAML).
7. **Add `governance.yml` Action** in advisory mode (§5).

Item 1 is mandatory before any commit; everything else can land in one PR.

---

## 8. Success metrics — measurable

Phase 1 is **done** when:

- [ ] The 7 checklist items above are merged.
- [ ] One PR opens with the new flow and reaches merge with no manual override of governance checks.

Phase 1 is **working** when, across the next 5 PRs:

- [ ] ≥4 of 5 PRs get correct auto risk labels (humans don't re-label them).
- [ ] Zero PRs are blocked by the governance Action for reasons the author didn't already know.
- [ ] Zero PRs are merged with an empty acceptance matrix row at `risk:medium+`.
- [ ] Average review-to-merge time does not increase by more than 50% compared to last 5 pre-v0.2 PRs.

If any of the four fail, the framework is too strict or too lenient and needs adjustment, not blind continuation.

---

## 9. Deferred items (revisit at Phase 2)

Track in a single issue. Don't expand v0.2 scope mid-flight.

- Additional personas (Iris/Omar/Tessa/Cora/Prism) if review volume justifies it.
- Generated memory mirror (when there's actually GitHub state worth mirroring).
- `Governance / PR policy` as a *required* status check (after 2 weeks of advisory green).
- Wiki + Discussions enablement.
- Project board fields beyond what GitHub auto-provides.
- Self-improvement / PromptOps loop.

---

## 10. Open questions (honest)

1. **Risk classifier rubric is path-based — what about behavior-based risks?** A PR that touches no `area:auth` paths but changes auth semantics indirectly won't auto-label correctly. Mitigation: reviewers can re-label.
2. **Acceptance matrix completeness is graded by "no empty cells"** — agents can fill cells with "TBD" to bypass. Need a stricter grammar check, or accept honor-code.
3. **No mechanism for cost cap.** Each multi-agent review can cost real tokens. No budget alert in Phase 1.
4. **Human comment "approved for critical risk"** is a magic-string gate. Vulnerable to typos, copy-paste from approved templates. Acceptable for Phase 1; revisit if abused.

---

## 11. Simulation harness

A simulation tests whether multi-persona review actually catches PR flaws. v0.2 ships **one** scenario: `001-suspend-cookie`. Pass/fail is graded by comparing agent verdicts to planted issues.

See [Appendix A](#appendix-a--simulation-scenario-001-suspend-cookie) for scenario 001.
See [Appendix B](#appendix-b--simulation-results-run-1) for Run 1 results (sim 001, 4 agents).
Appendix C: Run 2 results (sim 002 — false-positive resistance, 4 agents).
Appendix D: Run 3 results (sim 003 — critical destructive migration, 6 agents).
Appendix E: Run 4 results (sim 004 — hallucination trap, 5 agents incl. Prism meta-review).
Appendix F: Cross-run summary + prompt improvements adopted.

---

## 12. Adopt these GitHub-native widgets (Milestones, Relationships, Projects)

The v0.1 plan focused on Issues / PRs / Discussions / Wiki / Projects but **did not** call out **Milestones** or the newer **Relationships** feature. Both are free, native, and slot cleanly into v0.3. Recommendation: **use both.**

### Milestones — phase + version + release tracking

A Milestone is a named bucket with optional due date that groups issues/PRs. They survive across branches, link from the sidebar of every grouped issue, and have a built-in completion % indicator.

**Use them for:**

| Milestone type | Examples | Owner |
| --- | --- | --- |
| Operating-model rollout phase | `agents/phase-1-mechanical`, `agents/phase-2-personas`, `agents/phase-3-knowledge-mirror` | Ari |
| Epic / domain rollup | `E05.5 PHPStan CQRS Rules`, `Cookie domain stabilization`, `Audit-round-4` | Nico |
| Release versioning | `template-v1.0`, `template-v1.1` | Rhea |
| Sprint-like windows | `2026-Q2`, `freeze-window-2026-06-01` | Nico |

**Why use them over labels for these:**

- Labels are unbounded and orthogonal; Milestones are mutually exclusive (one per issue) and time-boxed.
- Milestones surface a stale-work signal: "12 issues in `phase-1-mechanical`, 0 closed in 30 days" is visible at a glance.
- Phase tracking via Milestones replaces the `phase-1`, `phase-2`, `phase-3` *labels* the v0.1 plan was implying. The user's current sidebar shows labels like `epic`, `phase-2`, `destructive` — `phase-2` should move to a Milestone; `epic` is fine as a label.

**Rule for v0.3:** every issue must be assigned to a Milestone OR explicitly carry `triage` (no milestone yet). Ari/Nico enforce this in triage.

### Relationships — the audit-finding lifecycle natively

GitHub's Issue Relationships (`blocked by`, `blocks`, `duplicates`, parent/child) are exactly the missing piece for v1's audit protocol (§18B). Without Relationships, the v1 protocol kept saying "link the remediation issue from the audit issue" — which was a manual comment hyperlink. With Relationships, this is structural.

**Map to v0.3:**

| Relationship | Use |
| --- | --- |
| **parent / child** | Epic issue → sub-issue. E.g., `Audit-round-4` → `SEC-001 session fixation`. |
| **blocked by** | A `risk:high` implementation issue is `blocked by` its Decision Record issue (until decision-record status = Approved). Rhea checks this at merge gate. |
| **blocks** | A flagged audit finding `blocks` the linked feature work that would touch the same area. |
| **duplicates** | Echo (Retrospective Analyst) marks repeated findings; reduces noise without losing history. |

**Specific high-value pattern — audit finding lifecycle:**

```text
Issue "Audit-round-4 charter"  [parent]
  └── Issue "SEC-001: session fixation suspected"  [child]
        ├── BLOCKED BY:  Issue "Decision: session-regeneration approach"
        └── BLOCKS:      Issue "feat(auth): rotate session id post-login"
                         └── Linked PR closes this issue
```

This means the merge gate can mechanically check: "If this PR closes an issue that is `blocked by` an open decision-record issue, BLOCK the merge." That's a v0.3 enforcement rule (§5).

### Projects — re-enable for Phase 2

v0.2 deferred Projects. Restore the decision for Phase 2 with a **trimmed field set** (not v1's 18-field monster):

- `Status` (Backlog / Ready / In Progress / Review / Blocked / Done) — built-in.
- `Risk` (low / medium / high / critical) — mirrors the label.
- `Persona owner` (Lina / Tessa / Iris / Omar / etc.) — single select.
- `Audit Status` (N/A / Proposed / Confirmed / Remediation / Verified / Cleared) — for audit findings only.

Keep it at 4 fields. v1's 18 fields were a maintenance disaster.

### Other sidebar widgets — verdicts

| Widget | Use in v0.3? | Why |
| --- | --- | --- |
| **Assignees** | Yes, but degenerate | Only one human account. Use as "human-of-record" — the person ultimately accountable. Persona owner lives in a Project field / label. |
| **Labels** | Core to v0.3 | Risk, area, persona, audit:*, work:*, blocked:*. ~35 labels total (§7). |
| **Projects** | Yes (Phase 2) | See above. |
| **Milestones** | **Yes (Phase 1)** | See above. Cheapest win in the whole plan. |
| **Relationships** | **Yes (Phase 1 for risk:high+ work)** | See above. Replaces manual comment-linking from v1. |
| **Development** (branch / PR linking) | Already used | `Closes #N` populates this automatically. |
| **Notifications / Participants** | No special use | Standard GitHub behavior. |

---

## 13. Identity & Multi-Model Signing (how 32 personas sign with one account)

Personas are prompts, not GitHub accounts. The "how do we know who wrote this comment" question has **five layers**, each more rigorous than the last. Pick the layer that matches the trust + cost budget for the work.

### 13.1 Layer 1 — Structured comment header (honor-code, machine-parseable) — **Phase 1**

Every agent-posted comment opens with a YAML-front-matter block. This is the **minimum**; even Layers 2–5 keep this header.

```markdown
---
Persona: Theo
Role: AI CQRS/DDD Architect
Layer: Engineering
Model: claude-opus-4-7-1m
Run-ID: 2026-05-22T22:11:43Z-7af3
Source: PR #N diff + issue #N + CI run #12345
Verdict: REQUEST CHANGES
Self-review conflict: No
Reviewers consulted: independent
Fallibility: This review may be wrong; verify against the diff and CI.
---
```

Cost: free. Trust: honor-code + machine-parseable.

### 13.2 Layer 2 — One shared GitHub App (`cqrstemplate-agent[bot]`) — **Phase 2**

Create **one** GitHub App with permissions to read PR/issue content and write comments. All agent-generated comments are posted via the App's identity. The GitHub UI then shows a `[bot]` badge — visually separated from human comments. The App's avatar and username are stable.

- The App posts on behalf of any persona; the persona is declared in the Layer-1 header.
- Comments cannot be confused with human comments. (Self-review conflict reduces to "did the bot review code the human wrote in the same session?")
- One-time setup (~30 min): register App at `github.com/settings/apps/new`, install on the repo, store the App's private key as a repo secret, wire up an Action that posts comments via the App's token.

Cost: free. Trust: comments are unforgeable by humans because they require the App's installation token.

### 13.3 Layer 3 — Per-persona avatars within one App (visual differentiation cheaply) — **Phase 2**

Same shared App, but each comment opens with a **persona emoji + persona-name H4 header** so the persona is visually scannable in the timeline without scrolling into the YAML block:

```markdown
#### 🏛️ Theo (CQRS/DDD Architect) — REQUEST CHANGES

---
Persona: Theo
...
---

**Blocking findings:**
...
```

Emoji map (proposal):

| Persona | Emoji | Persona | Emoji |
| --- | --- | --- | --- |
| Ari | 🎬 | Iris | 🛡️ |
| Mara | 🎯 | Omar | 📜 |
| Nico | 🗂️ | Tessa | 🧪 |
| Vera | ⚖️ | Rhea | 🚦 |
| Theo | 🏛️ | Lex | 🔍 |
| Lina | 🛠️ | Pico | 🎨 |
| Dario | 🗄️ | Deck | 🧱 |
| Nova | 🔌 | Doc | 📋 |
| Sofia | 🖼️ | Cliff | 📈 |
| Kai | ⚙️ | Mocha | 🎭 |
| Pax | ⚡ | Milo | 📚 |
| Eve | 💥 | June | 📝 |
| Vale | 💎 | Echo | 🔁 |
| Hex | 🔷 | Prism | 🌈 |
| Quinn | 🔎 | Cora | 💰 |
| Bus | 🚌 | Saga | 🪢 |

Cost: free. No new infra; just convention.

### 13.4 Layer 4 — Per-persona GitHub Apps (top personas only) — **Phase 3 (optional)**

For the **3–5 most important personas** (Rhea, Iris, Omar are top candidates because their verdicts gate merges), register dedicated GitHub Apps:

- `rhea-release[bot]` — only this App can post `MERGE READY` comments.
- `iris-security[bot]` — only this App can post `SECURITY: BLOCK`.
- `omar-audit[bot]` — only this App can post `AUDIT: FAIL`.

A merge-gate Action then requires the matching App identity for blocking verdicts (e.g., "BLOCKED by Iris" only counts if posted by `iris-security[bot]`). This is the closest you get to mechanical self-approval prevention.

**Do NOT** create 32 Apps. The marginal trust gain past 5 is not worth the registration overhead.

Cost: free per App; ~5 min/App setup; ~25 min total for 5 Apps.

### 13.5 Layer 5 — Comment-header validator Action — **Phase 1**

A repo Action that parses every bot-posted comment and asserts:

1. Layer-1 YAML header is present and well-formed.
2. `Persona` is in the 32-persona catalog from §11.
3. `Model` is in the approved-models allow-list.
4. `Verdict` is in the verdict enum.
5. `Self-review conflict: No` is required for the comment to count toward quorum.
6. `Run-ID` is unique per comment (prevents replay of an old review).

If validation fails, the Action posts a follow-up "INVALID HEADER" comment and removes the original comment's counted-toward-quorum status (via a label).

Cost: free. ~50 lines of YAML + a small Node/Python parser. Mechanically enforces the honor-code from Layer 1.

---

## 14. Multi-Model, Multi-Perspective Orchestration

Each persona × each model = a distinct viewpoint. Use this on purpose, not by accident.

### 14.1 Default model assignment per persona

| Persona group | Default model | Reasoning |
| --- | --- | --- |
| Architecture (Theo, Eve, Vale, Hex, Quinn, Bus, Saga) | Claude Opus 4.7 (1M) | needs whole-codebase context; deep reasoning |
| Security/audit (Iris, Omar) | GPT-4o or Claude Opus | red-team mindset; both run for cross-validation on `risk:critical` |
| Test (Tessa, Cliff, Mocha) | Claude Sonnet 4.6 | fast, structured output |
| Quality gates (Lex, Pico, Deck, Doc) | Deterministic tools (PHPStan, PHPCS, Deptrac) — no LLM needed | mechanical |
| Orchestration (Ari, Nico, Vera, Rhea, Cora) | Claude Haiku 4.5 or Sonnet | cheap, repeatable, structured |
| Knowledge (Milo, June, Echo, Prism) | Claude Sonnet | summarization-heavy |
| Product (Mara) | Claude Opus | needs business reasoning + acceptance-criteria mapping |

### 14.2 Cross-model validation policy

For `risk:critical` or any PR where two specialists disagree:

- Re-run the disputed persona under a **different model**.
- If model A says APPROVE and model B says REQUEST CHANGES on the same persona prompt → **dissent log** opened automatically. Escalates to Ari + human.
- If both models agree → confidence ↑, escalation not needed.

This costs 2× tokens for that persona on those PRs only. It is the cheapest known way to catch model-specific blind spots.

### 14.3 Why this matters even without leaving Anthropic

Even within the Anthropic SDK, Opus / Sonnet / Haiku produce meaningfully different reviews (Opus catches more architectural smell; Haiku is more literal). Multi-model is valuable **before** introducing GPT or Gemini.

### 14.4 When to use external models (Phase 3+)

- **GPT-4o** (via OpenAI API) — `risk:critical` security/audit review for adversarial perspective. Iris/Omar's "second pair of eyes."
- **Gemini 2.5 Pro** — fast price/quality fallback when Anthropic is unavailable.
- **GitHub Copilot Agents** (native, per v1 §9B) — implementation/draft-PR work. Native PR integration.

Integration points:
- Layer-1 header `Model:` field records which model wrote each comment.
- A `Models consulted:` row in the merge-gate table proves cross-model coverage for `risk:critical`.
- Cora (Cost Architect) sets the per-PR model budget. She approves "run Iris under both Claude Opus and GPT-4o" only for `risk:critical`.

### 14.5 Open question for you

> Which model providers do you want wired in for Phase 2? Anthropic-only is fine for v0.3. Adding GPT or Gemini is a Phase 3 conversation about API keys, billing, and which Actions invoke which model.

---

## 15. Self-Improvement Loop — how the system learns from its own mistakes

**Key insight:** your repo already has the load-bearing piece. Commits [d0c85ed](https://github.com/ci4me/CQRSTemplate/commit/d0c85ed) ("feat(phpstan): add CQRS handler-contract custom rules (E05.5)") and [c3a4aef](https://github.com/ci4me/CQRSTemplate/commit/c3a4aef) ("test(phpstan): add RuleTestCase coverage for E05.5 CQRS rules") prove the extension point exists: **custom PHPStan rules with test infrastructure.** The learning loop is the pipeline that turns "Theo caught the same thing in 3 PRs" into "next time it's a PHPStan error before the PR is even opened."

### 15.1 Trigger events (when the loop fires)

Echo (Retrospective Analyst) auto-opens an "incident" issue when ANY of these happen:

| Trigger | Detector | Severity |
| --- | --- | --- |
| 3+ rounds of REQUEST CHANGES on the same finding category in one PR | Action parses review comments | Medium |
| Revert within 7 days of merge | Action watches `git revert` commits | High |
| Hotfix PR following a recent merge (same files, < 72h) | Action correlates file overlaps | High |
| Same review finding category appears in 3+ different PRs in 30 days | Action with rolling-window query | Medium |
| Simulation regression — a previously-passing sim starts failing | Sim harness | High |
| Hallucination caught — agent claimed something not in diff | Reviewer header marks `Trust assessment: FAIL` | Medium |
| Risk underclassification — Vera reclassifies upward | Comment grep | Low (signal, not failure) |
| Production incident (later phase) | Incident-management webhook | Critical |

### 15.2 The refinement pipeline (4 stages, all GitHub-native)

```text
TRIGGER
  ↓
Echo opens "incident-NNN" issue with: trigger evidence, affected PRs, finding category
  ↓
Prism analyzes:
  - Was this preventable by a deterministic check? (Y/N + which kind)
  - Was this a prompt weakness? (Y/N + which prompt + proposed fix)
  - Was this a one-off judgment call? (Y/N → just document, don't mechanize)
  ↓
Prism opens follow-up issue (one of three types):
  TYPE A: "Add PHPStan/PHPCS/Action check for <pattern>"
  TYPE B: "Update <persona> prompt: <specific change>"
  TYPE C: "Wiki page: pattern to watch for"
  ↓
PR opened for the check/prompt/doc change:
  - For TYPE A: PHPStan rule + RuleTestCase that catches the historical incident + false-positive scan against last 20 merged PRs
  - For TYPE B: prompt diff + regression run of all simulation scenarios
  - For TYPE C: Wiki page authored by June + linked from issue templates
  ↓
PR review (the same governance gates as any other code) + merge
  ↓
Future PRs catch the pattern mechanically; manual review effort drops.
```

### 15.3 Manual review finding → mechanical check (the killer pattern)

This is the highest-leverage flow. Every recurring manual finding becomes a check. From the 4 simulations we've already run, here are **concrete check candidates** with their implementation sketch:

| Source sim | Finding | Check type | Implementation sketch |
| --- | --- | --- | --- |
| `001` (F1) | Command missing `Actor` field | **PHPStan rule (E05.6)** | Extend the E05.5 handler-contract rules: every class in `app/Domain/*/Application/Command/*Command.php` (excluding pure queries) must have an `Actor` or `ActorId` constructor parameter, OR carry an attribute `#[NoActorRequired(reason: "...")]` for documented exceptions. Test: the suspend-cookie mock command would fail this rule. |
| `001` (F2) | Aggregate state-transition method without domain event | **PHPStan rule (E05.7)** | Scan aggregate methods that mutate `$this->status` (or any property typed as a state enum). If the method body does not call `$this->recordEvent(...)` (or whatever the existing event-bag method is named), error. Test: `Cookie::suspend()` from sim 001 would fail. |
| `001` (F3) | `error_log()` used in domain layer for audit | **PHPCS sniff** | Custom Slevomat-style sniff: any call to `error_log`, `var_dump`, `print_r`, `echo`, `var_export` under `app/Domain/**` errors. Recommend Monolog logger or domain event instead. Test: `Cookie::suspend()` would fail. |
| `001` (F4) | Method body > 20 lines | **Existing PHPCS rule** (likely already in repo) | Check if `Generic.Metrics.MethodLength` or similar is already in `phpcs.xml.dist`. If not, add at threshold 20. |
| `001` (F5) | Tests missing for invariants on a state-transition | **Mutation-testing signal** (Phase 3) | Harder to mechanize. Phase 2 = require the PR template "Acceptance matrix" row "Tests cover invariants?" with a non-empty Evidence cell. Phase 3 = run Infection (mutation testing) on changed methods; if mutants survive, flag. |
| `001` (F6) | Risk label downgraded compared to path-classifier | **Action (`risk-label.yml`)** | Action runs path-based classifier first, posts predicted label. If author/agent applies a LOWER label, requires an explicit comment `risk-override-justification: <reason>` from a maintainer. |
| `001` (F7) | Acceptance criterion implemented in code but not asserted by test | **Action** parsing acceptance matrix in PR body | Every row of the matrix must cite a `path/to/test.php:line` or `MISSING`. Action regex-checks the matrix structure. |
| `003` (Dario) | `"BRL"` double-quoted (breaks under `ANSI_QUOTES`) | **PHPStan rule** OR `sqlfluff` lint | Static scan migrations for raw SQL containing `"` around string literals → error. |
| `003` (Dario) | Migration backfill not transactional | **PHPStan rule** + **manual migration-review checklist** | Detect `Migration::up()` methods that contain raw `$this->db->query(...)` without surrounding `transStart()`/`transComplete()`. Error if write query without transaction. |
| `003` (Omar) | Destructive column drop without historical retention | **PHPStan rule on migrations** | Detect `dropColumn`/`dropTable` calls in `up()` and require either a shadow table creation in the same migration or a `#[DestructiveMigration(retention: ...)]` attribute. |
| `003` (Vera) | Risk label not escalated for destructive migration | **Action** | Path-based classifier auto-labels `risk:critical` if migration file contains `dropColumn`/`dropTable`. Override requires named maintainer. |
| `004` (Theo, Omar) | PR summary makes claims not present in diff | **Action** (heuristic) | Action extracts bullet claims from PR summary. For each, greps the diff for keywords. If any claim has 0 supporting hunks, post a comment "POTENTIAL HALLUCINATION: claim '<X>' has no supporting diff. Verify or remove." Doesn't block; flags. |

**Each candidate above is a future PR.** Most can be built in 1–3 hours each. The order is yours to set; the table is the work-queue.

### 15.4 Prompt improvement loop (Prism's domain)

Persona prompts are versioned source code under `.claude/agents/` (or a new `.github/agent-prompts/` directory). Changes go through:

```text
incident → Prism proposes prompt diff → PR opened
  ↓
Regression test: re-run ALL existing simulation scenarios with the new prompt
  ↓
If any sim regresses (catches fewer flaws or invents facts), block.
If all sims still pass AND the original incident is now caught, merge.
  ↓
Updated prompt becomes default for all future agent runs.
```

The 6 prompt fixes Prism returned in sim 004 (Appendix E.4) — the Universal Reviewer Preamble — should land via exactly this loop **as the first test of the loop itself**.

### 15.5 Guardrails against bad learning

Self-improvement can go wrong in five known ways:

| Failure mode | Mitigation |
| --- | --- |
| **Overfitting** — check added for an outlier, false-positives everywhere | Mandatory false-positive scan against last 20 merged PRs before the check PR can merge. If FP rate > 5%, reject. |
| **Drift / fossilization** — checks accumulate, CI gets slow, no one removes anything | Quarterly "check audit" issue. Owner persona (Lex for PHPStan, Pico for PHPCS, Kai for Actions) lists their checks and FP rate. Stale or noisy checks proposed for removal. |
| **Loop poisoning** — LLM-proposed check becomes hardcoded without enough evidence | Each new check requires (a) link to ≥1 historical incident, (b) failing-test that proves the check catches it, (c) explicit Prism + Theo + relevant-domain-specialist approval. |
| **Silent weakening** — someone disables a noisy check via `// phpstan-ignore-next-line` comments creeping in | Action counts `phpstan-ignore-*` and `phpcs:disable` comments added per PR. If > 2 added, requires reviewer signoff with justification. |
| **Learning the wrong lesson** — one incident becomes a permanent rule for a non-pattern | Echo opens a "lesson" but doesn't propose a rule until the pattern repeats 3+ times. Single incidents go to Wiki, not to checks. |

### 15.6 Where lessons live (the knowledge taxonomy)

| Lesson type | Lives in | Updated by | Audience |
| --- | --- | --- | --- |
| Recurring pattern (3+ incidents) | Mechanical check (PHPStan/PHPCS/Action) | Prism + relevant specialist | Compiler/CI catches it before review |
| One-off judgment call | Wiki page "Patterns to watch for" | June + Echo | Future human + agent reviewers |
| Architectural decision | GitHub Discussion ADR | Theo + Prism | Long-lived reference |
| Prompt improvement | `.github/agent-prompts/preamble.md` | Prism | Every future agent run |
| Process improvement | This plan + PR template + issue templates | Prism | Every future PR |
| Cost lesson | Cora's context-pack catalog | Cora | Every future agent launch |

### 15.7 Concrete first checks to build (recommended order)

Sequencing the table from §15.3 by expected leverage / build cost:

1. **`risk-label.yml` Action** — path-based risk classifier with override comment requirement. ~60 lines YAML. Catches sim 001 F6 and sim 003 Vera-escalation cases.
2. **Acceptance-matrix parser Action** — fail if matrix rows empty or missing evidence path. ~80 lines. Catches sim 001 F5 framing.
3. **PHPStan E05.6: Command-must-have-Actor rule** — extends existing E05.5 infrastructure. ~150 lines including RuleTestCase. Catches sim 001 F1.
4. **PHPStan E05.7: Aggregate-state-transition-must-emit-event rule** — same extension point. ~200 lines. Catches sim 001 F2.
5. **PHPCS sniff: ban `error_log()` in `app/Domain/**`** — ~80 lines Slevomat-style. Catches sim 001 F3.
6. **Universal Reviewer Preamble file + prompt-regression Action** — prompts go through PR review with sim regression. Catches sim 004 class of hallucinations.
7. **Migration safety rules (transactional, destructive-attribute, no-double-quotes)** — multi-rule batch from sim 003 Dario findings.

This list is the v0.3 self-improvement work-queue. Each becomes its own issue.

---

## Appendix A — Simulation Scenario `001-suspend-cookie`

## A.1 Mock Issue

```markdown
Title: Add Cookie temporary suspension state for compliance holds

Goal:
The compliance team needs to temporarily suspend a Cookie without deleting it,
so that it can be restored after review.

Risk: risk:low   (← author-assigned)
Area: area:domain

Acceptance criteria:
- New SuspendCookieCommand exists.
- Cookie aggregate has suspend() method.
- Suspended cookies cannot be activated until unsuspended.
- Suspension records actor and reason.
- Tests cover happy path and key invariants.

Required Review:
- Independent reviewer.
```

## A.2 Mock PR diff (textual)

```diff
diff --git a/app/Domain/Cookie/Application/Command/SuspendCookieCommand.php b/app/Domain/Cookie/Application/Command/SuspendCookieCommand.php
new file mode 100644
+<?php
+declare(strict_types=1);
+
+namespace App\Domain\Cookie\Application\Command;
+
+final readonly class SuspendCookieCommand
+{
+    public function __construct(
+        public string $cookieId,
+        public string $reason,
+    ) {}
+}

diff --git a/app/Domain/Cookie/Application/Command/Handler/SuspendCookieHandler.php
+final class SuspendCookieHandler
+{
+    public function __construct(private CookieRepository $repo) {}
+
+    public function handle(SuspendCookieCommand $command): void
+    {
+        $cookie = $this->repo->findById($command->cookieId);
+        $cookie->suspend($command->reason);
+        $this->repo->save($cookie);
+    }
+}

diff --git a/app/Domain/Cookie/Domain/Entity/Cookie.php
@@ class Cookie
+    public function suspend(string $reason): void
+    {
+        if ($this->status === CookieStatus::Suspended) {
+            throw new \DomainException('Cookie already suspended');
+        }
+        if (empty($reason)) {
+            throw new \DomainException('Reason required');
+        }
+        if (strlen($reason) < 10) {
+            throw new \DomainException('Reason must be at least 10 characters');
+        }
+        if (strlen($reason) > 500) {
+            throw new \DomainException('Reason too long');
+        }
+        $this->status = CookieStatus::Suspended;
+        $this->suspendedAt = new \DateTimeImmutable();
+        $this->suspensionReason = $reason;
+        // audit
+        error_log("Cookie {$this->id} suspended: {$reason}");
+        $this->touchUpdatedAt();
+    }

diff --git a/tests/Unit/Cookie/SuspendCookieHandlerTest.php
+final class SuspendCookieHandlerTest extends TestCase
+{
+    public function test_it_suspends_cookie_with_valid_reason(): void
+    {
+        $cookie = CookieMother::active();
+        $repo = new InMemoryCookieRepository([$cookie]);
+        $handler = new SuspendCookieHandler($repo);
+        $handler->handle(new SuspendCookieCommand($cookie->id(), 'compliance hold review needed'));
+        $this->assertTrue($cookie->isSuspended());
+    }
+}
```

## A.3 Planted flaws (ground truth — agents do NOT see this)

| # | Flaw | Severity | Which lens catches it |
| --- | --- | --- | --- |
| F1 | `SuspendCookieCommand` has no `actorId` — actor not propagated for audit | High | Audit / Security |
| F2 | `Cookie::suspend()` mutates state but does NOT emit a `CookieSuspended` domain event — breaks CQRS/event-sourced pattern used elsewhere in the aggregate | High | Architecture (CQRS/DDD) |
| F3 | `error_log()` used as audit mechanism — should use proper Monolog channel or domain event | High | Audit / Security |
| F4 | `Cookie::suspend()` mixes responsibilities (validation + state mutation + audit + timestamp) in one method — SRP violation, even though raw line count is under 20 | Medium | Clean-code |
| F5 | Tests cover only happy path. Missing: "already suspended throws", "reason too short throws", "reason too long throws", "cannot activate while suspended" (the latter is in the acceptance criteria!) | High | Test |
| F6 | Risk labeled `low` but new domain state + audit-relevant transition + actor requirement → should be `medium` minimum, arguably `high` (area:audit) | Critical to gate | Release / Orchestrator |
| F7 | Acceptance criterion "Suspended cookies cannot be activated until unsuspended" not implemented at all (no change to `activate()`) | High | Architecture + Release |

**Expected verdicts:**

- Architect (Theo): `REQUEST CHANGES` — catches F1, F2, F7, F3.
- Test (Tessa): `REQUEST CHANGES` — catches F5 (with named missing tests), F7.
- Clean-code: `REQUEST CHANGES` — catches F3, F4 (as SRP violation, not line count), value-object opportunity.
- Release (Rhea): `BLOCKED` — flags F6 (risk underclassified to `low`), accumulated blockers.

**Pass criteria for the simulation:**
- ≥5 of 7 planted flaws are caught across the 4 agent reviews combined.
- No agent invents flaws that aren't present (no hallucinated file paths, methods, classes).
- Each agent's verdict is in the required enum.
- Rhea correctly blocks rather than waving through.

---

## Appendix B — Simulation Results (Run 1)

## B.1 Run metadata

- **Date:** 2026-05-22.
- **Agents launched (parallel, single message):** 4 specialists.
  - Theo (Architect) via `cqrs-specialist`.
  - Tessa (Test Lead) via `test-specialist`.
  - Clean-Code Reviewer via `clean-code-specialist`.
  - Rhea (Release Manager) via `general-purpose`.
- **Scenario:** `001-suspend-cookie`.
- **Total wall-clock:** ~35 seconds (parallel).
- **Aggregate token usage:** ~75k across the four agents.

## B.2 Per-agent verdicts

| Persona | Verdict | Top blocking findings |
| --- | --- | --- |
| **Theo (Architect)** | REQUEST CHANGES | (1) no `Actor` on command — breaks established `DeleteCookieCommand`/`RestoreCookieCommand` pattern; (2) `suspend()` mutates state without emitting `CookieSuspendedEvent` — diverges from every other Cookie lifecycle transition; (3) handler doesn't drain `pullEvents()` and depends on concrete `CookieRepository` instead of port; (4) AC "cannot be activated while suspended" entirely unimplemented (no guard added to `activate()`); (5) reason validation belongs in `SuspensionReason` VO using project's `App\Domain\Shared\Exceptions\DomainException` + `ErrorCodes`; (6) `error_log()` is domain-layer I/O — wrong; (7) handler is `final class` not `final readonly`; (8) `cookieId: string` mismatches `int` IDs used everywhere else in the domain. |
| **Tessa (Test Lead)** | REQUEST CHANGES | (1) only happy-path test exists; (2) "already suspended throws" untested (explicit AC); (3) "cannot be activated while suspended" has no test (explicit AC); (4) three validation branches (empty / `<10` / `>500`) all uncovered — collapses coverage below 90% gate; (5) actor recording untestable because spec gap propagates; (6) happy-path doesn't assert `suspendedAt` or `suspensionReason` state. **Named the six missing tests by method name + assertion type.** |
| **Clean-Code Reviewer** | REQUEST CHANGES | (1) `suspend()` mixes 5 responsibilities (guards / length validation / state mutation / audit / timestamp) — SRP violation; (2) `error_log()` for audit forbidden by project logging architecture; (3) magic numbers `10`/`500` unnamed; (4) bare `\DomainException` with string messages — project uses typed factories; (5) duplicate validation pattern — encapsulate in VO. **Counted method body at 17 lines — under the 20-line cap.** |
| **Rhea (Release)** | BLOCKED | Reclassified `risk:low` → `risk:high` because new aggregate state + audit implications; flagged: empty acceptance matrix, missing decision record (now required at high), missing rollback note, no reviewer approval yet, actor field missing in command, `error_log()` not the sanctioned audit path, invariant tests missing. |

## B.3 Coverage matrix vs planted flaws

| Flaw | Caught by | Missed by |
| --- | --- | --- |
| **F1** no actor propagation | Theo (blocker), Tessa (spec gap), Rhea (blocker) | Clean-Code (out of lens) |
| **F2** no domain event | Theo (blocker), Tessa (non-blocking), Clean-Code (referenced via "use event for audit") | — |
| **F3** `error_log()` audit | Theo, Tessa (non-blocking), Clean-Code (blocker), Rhea (blocker) | — |
| **F4** SRP violation in `suspend()` | Clean-Code (blocker), Rhea (mentioned) | Theo (focused on missing event), Tessa (out of lens) |
| **F5** missing invariant tests | Tessa (named 6), Theo, Rhea | Clean-Code (out of lens) |
| **F6** risk underclassified | Rhea (correctly reclassified to high), Theo (non-blocking) | Tessa, Clean-Code (out of lens) |
| **F7** "cannot activate while suspended" unimplemented | Theo (blocker), Tessa (FAIL in matrix), Rhea (blocker) | Clean-Code (out of lens) |

**Score: 7 / 7 planted flaws caught** (each by at least one agent in its expected lens).

## B.4 Bonus findings (real issues NOT planted, but agents surfaced them)

These strengthen confidence — the agents are doing real review, not just pattern-matching the planted issues:

- Theo: `cookieId` typed `string` but rest of domain uses `int`. **High-severity inconsistency.**
- Theo: `SuspendCookieHandler` declared `final class` not `final readonly class` like other handlers.
- Theo: handler depends on concrete `CookieRepository` (should be port/interface).
- Theo: handler doesn't drain `$cookie->pullEvents()` into `EventDispatcherInterface`.
- Theo & Clean-Code: bare `\DomainException` vs project's `App\Domain\Shared\Exceptions\DomainException` + `ErrorCodes::*`.
- Theo & Clean-Code: reason validation belongs in a `SuspensionReason` VO (paralleling `CookieName`, `CookiePrice`).
- Tessa: produced six concrete missing-test names with assertion types, not just "needs more tests."
- Clean-Code: magic numbers `10` / `500` should be named constants.
- Clean-Code: `$repo` constructor parameter should be more descriptive.

## B.5 Hallucinations / invented facts

**None observed.** All four agents stayed within the diff or cited real repo files (`.claude/CLAUDE.md`, `.claude/documentation/LOGGING_BEST_PRACTICES.md`, existing aggregate lifecycle commands). No agent invented method names, file paths, or behavior absent from the diff.

## B.6 Meta-findings about the simulation itself

1. **Mis-planted flaw caught:** F4 was originally specified as a method-length violation ("22 lines"). Clean-Code counted the actual method body at **17 lines** — under the 20-line cap. Rhea cited "~22 lines borderline." The agents correctly disagreed with the plant. **Resolution:** Appendix A.3 has been corrected to describe F4 as an SRP/responsibility violation (which is true) rather than a length violation (which is false).
2. **Line-counting convention not standardized:** Clean-Code (17) and Rhea (22) disagree. v0.2 §6 needs to specify: "count method body lines from opening brace to closing brace, exclusive; include blank lines and comments."
3. **Lens overlap is healthy, not wasteful:** F3 was caught by all four agents, F2 by three, F1 by three. Redundancy here is signal, not noise — each agent caught it for a slightly different reason (domain-layer I/O, project logging policy, spec gap, audit path violation).
4. **`cqrs-specialist` Read tool use was valuable:** Theo Read existing handlers to compare patterns and produced the most domain-aware review. The other agents could benefit from being instructed to do the same for their lens.
5. **Persona prompts held — no role drift.** Each agent stayed in its lens. Tessa didn't try to do architecture; Clean-Code didn't try to do release.

## B.7 Prompt improvements identified by the run

| Target | Improvement | Priority |
| --- | --- | --- |
| §6.1 review header | Add a "line-counting convention" footnote so Clean-Code and Rhea don't disagree on numbers. | Medium |
| Architect prompt | Make "skim existing aggregate lifecycle commands for pattern parity" an explicit instruction — Theo did this anyway, but only because `cqrs-specialist` has Read by default. | Medium |
| Test prompt | Keep current — Tessa's named-test enumeration was the strongest output of the run. | (none) |
| Clean-code prompt | Add "if line count is under cap but responsibilities are mixed, FAIL on SRP and explain why." Worked here organically; make it explicit so a less-trained agent doesn't approve at 17 lines. | High |
| Release prompt | Risk-table inclusion was the key — keep verbatim. Add: "if you reclassify risk upward, re-evaluate ALL required artifacts under the new risk level" — Rhea already did this, but make it explicit. | Low |
| Scenario harness | Add a `risk:critical` scenario and a `risk:low` (true low) scenario to test for false positives (agents over-blocking benign PRs). | Medium |

## B.8 Pass / fail

| Criterion | Threshold | Actual | Result |
| --- | --- | --- | --- |
| Planted flaws caught | ≥5 of 7 | 7 of 7 | **PASS** |
| No hallucinations | 0 invented files/methods | 0 | **PASS** |
| Verdicts in required enum | 4 of 4 | 4 of 4 | **PASS** |
| Rhea correctly blocks | yes | yes (with risk reclassification + 7 blockers) | **PASS** |
| Persona discipline (no role drift) | 4 of 4 | 4 of 4 | **PASS** |
| Wall-clock under 5 min | yes | 35 s | **PASS** |

**Simulation `001-suspend-cookie`: PASS.**

The v0.2 governance model, with four specialist agents reviewing in parallel under the persona prompts in §6, **correctly blocks** the flawed mock PR with concrete, actionable, evidence-cited findings — and at a token cost of ~75k for the whole run (one-time, not per-comment).

## B.9 Next runs to add

- `002-low-risk-docs-only` — verify gates do NOT block a legitimate docs-only PR (false-positive check).
- `003-critical-migration` — destructive migration without `approved for critical risk` comment; verify Rhea blocks with explicit human-escalation requirement.
- `004-hallucination-trap` — give agents a clean diff but a misleading PR summary claiming behavior the diff doesn't implement; verify they cite the diff, not the summary.
- `005-prior-approval-conflict` — same agent identity attempts to both implement and approve; verify the honor-code persona prompt at least flags `Self-review conflict: Yes`.

---

## Appendix C — Simulation Results (Run 2: `002-docs-only`)

### C.1 Run metadata

- **Date:** 2026-05-22.
- **Purpose:** false-positive resistance test — does the framework approve a clean docs-only PR without inventing flaws?
- **Agents launched (parallel):** 4 — Theo (`cqrs-specialist`), Tessa (`test-specialist`), June (`general-purpose` as Docs Curator), Rhea (`general-purpose`).
- **Wall-clock:** ~14 s.
- **Aggregate token usage:** ~78k.

### C.2 Per-agent verdicts

| Persona | Verdict | Notable |
| --- | --- | --- |
| **Theo (Architect)** | APPROVE | "Docs only, no architecture surface touched." Clean acceptance matrix. |
| **Tessa (Test Lead)** | APPROVE | "No tests required for docs-only change; coverage gate unaffected." Correctly resisted the temptation to demand tests. |
| **June (Docs Curator)** | COMMENT (non-blocking) | Caught a real **drift bug**: docs claim `error_code` is `string` ("VALIDATION_FAILED") but `LOGGING_BEST_PRACTICES.md` defines error codes as `int` constants (e.g. `COOKIE_VALIDATION_NAME = 101`). Recommended fix without blocking merge. **June read the actual referenced doc and verified consistency** — this is the kind of grounded, useful review the framework is supposed to produce. |
| **Rhea (Release)** | MERGE READY | All gates PASS; suggested Conventional Commit subject and `Closes #N` trailer preservation. |

### C.3 Pass criteria

| Criterion | Threshold | Actual | Result |
| --- | --- | --- | --- |
| No false positives (clean PR approved) | yes | yes | **PASS** |
| Architect didn't invent flaws | yes | yes | **PASS** |
| Tessa didn't demand tests for docs | yes | yes | **PASS** |
| Docs Curator surfaced a real drift bug | bonus | yes (one) | **BONUS** |
| Rhea passed the merge gate | yes | yes | **PASS** |

**Simulation `002-docs-only`: PASS (with bonus drift catch).**

### C.4 Lesson for the framework

The Docs Curator (June) lens is **high-leverage and cheap**. June caught a real consistency bug between the new doc and an existing canonical doc by reading both. Add `area:docs` → June as a mandatory lens activation in §11.

---

## Appendix D — Simulation Results (Run 3: `003-critical-migration`)

### D.1 Run metadata

- **Date:** 2026-05-22.
- **Purpose:** does the framework correctly **block** a destructive migration with lossy rollback, and does the risk classifier escalate `risk:high` → `risk:critical`?
- **Agents launched (parallel):** 6 — Dario (`codeigniter4-specialist`), Theo (`cqrs-specialist`), Omar (`general-purpose`), Tessa (`test-specialist`), Vera (`general-purpose`), Rhea (`general-purpose`).
- **Wall-clock:** ~30 s.
- **Aggregate token usage:** ~106k.

### D.2 Per-agent verdicts

| Persona | Verdict | Top findings |
| --- | --- | --- |
| **Dario (DB)** | REQUEST CHANGES | (1) forward path drops `price` immediately after backfill — no recovery if conversion silently truncates; needs split migration or shadow table; (2) backfill not transactional, not idempotent — partial failure leaves half-migrated state; (3) **rollback lossy and silent** for 0-decimal (JPY, KRW) and 3-decimal (BHD, KWD, TND) currencies — corrupts data without warning; (4) **`"BRL"` double-quoted** — breaks under ANSI_QUOTES SQL mode (real bug); (5) NULL price rows would violate `price_minor NOT NULL`; (6) no CHECK constraints; (7) for >1M rows expect minutes of metadata-lock contention — recommends `pt-online-schema-change` / `gh-ost`. |
| **Theo (Architect)** | REQUEST CHANGES | Migration is a half-cutover — VO/aggregate/repository hydrator diffs missing; flagged risk that proposed `string $currency` would be a regression vs. existing `Currency` VO. |
| **Omar (Audit)** | FAIL | (1) historical `audit_log` payload digests reference dropped `price` column → integrity check failures on replay; (2) backfill `'BRL'` for all rows **retroactively rewrites audit context** — fabricates a dimension that was never recorded; (3) no migration-emitted audit events / correlation_id / system-actor described. Required: emit one `audit_log` entry per migrated row with `system:migration-003` actor + shared correlation_id + `before=price` / `after=price_minor,price_currency`. |
| **Tessa (Test)** | REQUEST CHANGES | Named 6 missing tests: rollback behavior, forward-migration edge cases (zero, max DECIMAL, NULL, rounding boundaries, multi-row), VO/aggregate unit tests, schema-assertion test, idempotency test. |
| **Vera (Risk)** | **risk:critical** (upgraded from risk:high) | Triggers matched: destructive column drop + payment semantics + lossy rollback. Required: 4-eyes on money, ADR for currency assumption, expand/contract refactor instead of destructive drop. |
| **Rhea (Release)** | **BLOCKED** | 9 gates, 7 FAIL: wrong label (still high, should be critical), no decision record, no human "approved for critical risk" comment, dishonest rollback note, 4 unresolved REQUEST CHANGES threads, acceptance matrix has FAIL rows, critical-risk quorum incomplete (no security/product reviews). |

### D.3 Pass criteria

| Criterion | Threshold | Actual | Result |
| --- | --- | --- | --- |
| Risk correctly escalated to critical | yes | yes (Vera) | **PASS** |
| Lossy rollback caught and called out | yes | yes (Dario + Rhea) | **PASS** |
| Audit-trail impact of column drop caught | yes | yes (Omar) | **PASS** |
| Multiple specialists block (≥4) | yes | 4 (Dario, Theo, Omar, Tessa) | **PASS** |
| Rhea blocks at merge gate | yes | yes (7/9 gates FAIL) | **PASS** |
| Hallucinations | 0 | 0 | **PASS** |

**Simulation `003-critical-migration`: PASS.**

### D.4 Bonus findings (unplanted, real bugs the agents discovered)

- **`"BRL"` double-quoted** — under ANSI_QUOTES SQL mode this is parsed as an identifier, not a string. Migration would fail. Real bug, completely unplanted. (Dario)
- **NULL handling in backfill** — `ROUND(NULL * 100)` violates `NOT NULL`. (Dario)
- **No CHECK constraints** for `price_minor >= 0` or `price_currency REGEXP '^[A-Z]{3}$'`. (Dario)
- **`pt-online-schema-change` recommendation** for large tables — production-grade detail. (Dario)
- **Domain-event retroactive rewrite** — fabricating a `BRL` currency dimension for historical rows is itself an audit violation. (Omar — sharp catch.)
- **Currency VO regression risk** — flattening `Currency` VO to `string` would be an architectural step backward. (Theo)

### D.5 Lesson for the framework

`codeigniter4-specialist` as Dario produced **the most production-grade migration review of any run so far** (5 blocking + 4 non-blocking findings, including a real ANSI_QUOTES bug). The specialist agents have domain knowledge the general-purpose ones don't — this validates the 32-persona vision.

---

## Appendix E — Simulation Results (Run 4: `004-hallucination-trap`)

### E.1 Run metadata

- **Date:** 2026-05-22.
- **Purpose:** test whether agents are fooled by a grand PR summary when the diff actually does nothing.
- **Agents launched (parallel):** 5 — Theo (`cqrs-specialist`), Tessa (`test-specialist`), Omar (`general-purpose`), Rhea (`general-purpose`), and **Prism (`general-purpose`) meta-evaluating the prompt system itself**.
- **Wall-clock:** ~19 s.
- **Aggregate token usage:** ~86k.

### E.2 Per-agent verdicts

| Persona | Verdict | Trust assessment |
| --- | --- | --- |
| **Theo** | REQUEST CHANGES | "PR summary claims a full audit-trail feature spanning command, handler, event, middleware, and tests. Diff actually changes one whitespace + comment on `CreateCookieCommand::$price` and appends a CHANGELOG bullet. **Discrepancy: total.** None of the five summary claims are backed by any code change. The CHANGELOG entry describes work that does not exist — that is a documentation lie." All 5 acceptance-matrix rows: FAIL. |
| **Tessa** | REQUEST CHANGES | "Tests added in this PR: **0.** Tests claimed by summary: 4. A summary claim is not test evidence; only diffed test files count." |
| **Omar** | FAIL | "**Merging this would corrupt the audit trail itself — the very thing the PR claims to improve.** This is the worst class of audit risk: documentation drift introduced deliberately." All 4 audit-claim rows: FAIL. |
| **Rhea** | BLOCKED | Acceptance matrix "complete" but **inaccurate** (cites "see summary" instead of diff). New gate added implicitly: matrix-accuracy. |
| **Prism (meta)** | IMPROVE PROMPT (priority: High) | Identified 6 prompt weaknesses + 6 prompt fixes. See E.4. |

### E.3 Pass criteria

| Criterion | Threshold | Actual | Result |
| --- | --- | --- | --- |
| All 4 reviewers blocked the PR | yes | 4/4 | **PASS** |
| Zero reviewers approved on summary text alone | yes | 0 | **PASS** |
| Each reviewer cited diff lines, not summary | yes | yes | **PASS** |
| Acceptance matrix populated from diff, not summary | yes | yes | **PASS** |
| Prism surfaced concrete prompt improvements | bonus | 6 | **BONUS** |

**Simulation `004-hallucination-trap`: PASS.**

### E.4 Prism's prompt improvements (adopted)

Prism diagnosed 6 prompt weaknesses and prescribed 6 fixes — **adopt all six** into every reviewer-persona prompt in §11:

1. **Diff-first ordering:** "Read the diff BEFORE the PR description. Build your own claim list from the diff first."
2. **Summary as hypothesis:** "Treat the PR summary as a hypothesis. For each bullet, locate the supporting hunk (`path:line`) or mark `NOT_FOUND_IN_DIFF`."
3. **Evidence-bound acceptance matrix:** "Output an Acceptance Matrix with columns: Claim | Supporting hunk(s) | Verdict (PASS/FAIL/NOT_FOUND) | Evidence quote. Empty Evidence quote = automatic FAIL."
4. **Mismatch is blocking:** "If any claim is `NOT_FOUND_IN_DIFF`, the overall verdict MUST be REQUEST CHANGES."
5. **Forbidden phrases:** "Forbidden: 'looks good', 'overall solid', 'LGTM' without per-claim evidence. Every approval requires a hunk citation."
6. **Trivial-diff guard:** "Whitespace-only or CHANGELOG-only diffs cannot satisfy claims about code behavior, tests, or runtime contracts."

These six rules become the **Universal Reviewer Preamble** prepended to every persona prompt in §11.

---

## Appendix F — Cross-Run Summary & Adopted Improvements

### F.1 Aggregate results (4 runs, 19 agents launched, ~345k tokens)

| Run | Scenario | Type | Expected | Actual | Result |
| --- | --- | --- | --- | --- | --- |
| 1 | `001-suspend-cookie` | true positive (block flawed PR) | BLOCKED | BLOCKED | PASS |
| 2 | `002-docs-only` | true negative (approve clean PR) | MERGE READY | MERGE READY + bonus drift catch | PASS |
| 3 | `003-critical-migration` | true positive + risk escalation | BLOCKED + reclassified | BLOCKED + reclassified to critical | PASS |
| 4 | `004-hallucination-trap` | true positive against summary lies | BLOCKED on diff evidence | BLOCKED on diff evidence | PASS |

**4 / 4 simulations passed. 0 hallucinations across 19 agents.**

### F.2 Improvements adopted into v0.3

1. **Universal Reviewer Preamble** (from sim 004 / Prism — see E.4). Prepended to every persona prompt.
2. **Line-counting convention** (from sim 001): "Count method body lines from opening brace to closing brace, exclusive of signature, including blank lines and comments." Add to Clean-Code prompt.
3. **Specialist agents earn their keep:** `cqrs-specialist` (Theo) and `codeigniter4-specialist` (Dario) consistently produced higher-signal reviews than `general-purpose` for their lenses. Mapping in §11 makes this structural — use the most-specific subagent_type available for each persona.
4. **Cross-doc consistency check** (from sim 002 / June): for `area:docs` PRs, the Docs Curator persona MUST Read the canonical doc the PR references and verify consistency. Add to June's prompt.
5. **Risk escalation is mandatory, not optional** (from sim 003 / Vera): the Risk Officer prompt must include the v0.3 risk table verbatim and instructions to challenge author labels.
6. **Acceptance matrix accuracy is its own gate** (from sim 004 / Rhea): "matrix complete" ≠ "matrix accurate." Rhea's merge-gate must check both. Add the gate to §6.3.
7. **Hallucination-trap scenario becomes a permanent regression test** — re-run any time the reviewer-persona prompts change.

### F.3 Open scenarios still queued

- `005-prior-approval-conflict` — same agent identity implements + approves; does `Self-review conflict: Yes` appear in the comment header?
- `006-disagreeing-reviewers` — Theo approves, Iris blocks; how does Ari (Orchestrator) escalate?
- `007-real-repo-diff` — pick an actual recent commit from this repo (e.g., one from the E05.5 PHPStan rules epic) and re-review it under v0.3 personas. Sees if findings match what human review caught.
- `008-cost-budget-overrun` — Cora evaluates a launch request for 20 personas on a docs-only PR and rejects as wasteful.
- `009-audit-protocol-end-to-end` — full v0.3 §13 audit protocol from charter → finding → remediation issue → verification → cleared.

Each takes ~30 s wall-clock and ~80–110k tokens.
