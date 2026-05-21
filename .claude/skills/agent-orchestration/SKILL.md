---
name: agent-orchestration
description: The orchestrator pattern for this CQRS template — pre/during/post execution rules, parallel-specialist delegation, rejection policy. Load when writing code that touches the standard PHPStan/Slevomat/PHPUnit gate.
allowed-tools: [Read, Write, Edit, Bash, Glob, Grep]
---

# Agent Orchestration

This skill captures the rules for delegating to specialist agents that USED
to live in `.claude/CLAUDE.md`. Load it whenever you're touching code that
the gate (PHPStan / Slevomat / PHPUnit / deptrac / docblocks:audit) covers.

---

## Pre-execution — BEFORE any code change

- Use `phpstan-specialist` to capture the current baseline so you know
  whether your change introduces new errors.
- Use `slevomat-specialist` to confirm the file is clean before you edit.
- Use `test-specialist` if you're touching an existing feature so you
  understand the current coverage profile.

---

## Execution — WHILE writing code

Always delegate by responsibility:

- **`php-specialist`** for every PHP file you touch.
- **`clean-code-specialist`** for every method or class.
- **`cqrs-specialist`** for any command / query / event / handler change.
- **`ddd-specialist`** for entity or value-object changes.
- **`codeigniter4-specialist`** for migrations, controllers, or routing.
- **`test-specialist`** whenever you add or modify a feature.

---

## Post-execution — AFTER the change

Three gates that MUST pass:

- `phpstan-specialist` — Level 8, **0 errors**.
- `slevomat-specialist` — **0 violations**.
- `test-specialist` — coverage stays **≥ 90 %**.

Also run:

```bash
composer docblocks:audit                       # 0 markers
vendor/bin/deptrac analyse --no-progress       # 0 violations
```

---

## Parallel delegation patterns

For complex tasks, fan out to 2–3 specialists at once:

| Task | Specialists to launch in parallel |
|---|---|
| Add a new property | `ddd-specialist` + `test-specialist` + `clean-code-specialist` |
| Create a new domain | `cqrs-specialist` + `ddd-specialist` + `test-specialist` |
| Refactor a method | `clean-code-specialist` + `php-specialist` + `test-specialist` |
| Add a business rule | `ddd-specialist` + `test-specialist` + `phpstan-specialist` |
| Fix a bug | `test-specialist` + `php-specialist` + `phpstan-specialist` |

Run them concurrently (same tool-call message). Aggregate the responses
before deciding what to ship.

---

## Rejection policy

Reject any code change that:

- Fails PHPStan Level 8.
- Fails Slevomat checks with any violation.
- Drops test coverage below 90 %.
- Has a method exceeding 20 lines.
- Violates CQRS / DDD patterns.
- Lacks tests for a new feature.

The expected behaviour when you discover one of those is **don't ship the
change**. Fix it before commit; do not amend a commit that already passed
the local hook just to slip the violation in.

---

## Mandatory planning rules

For any task that meets ONE of these criteria, invoke the
`strategic-planner` skill BEFORE writing code:

- 5+ steps or 15+ files affected.
- HIGH or CRITICAL risk (auth, payment, data migration).
- COMPLEX or VERY COMPLEX classification.
- User asked for a plan, a todo list, or "how do I implement X?".

The planner runs 5 phases (CoT understanding → ToT exploration → pre-mortem
→ SMART-E atomic tasks → Python validation) and rejects its own output if
any atomicity rule fails. Trust its rejection — re-plan, don't override.

---

## Tool-usage discipline

Native tools beat shell commands for autonomy:

- Read files → `Read` tool (not `cat`, `head`, `tail`).
- Create files → `Write` tool (not `echo >` or heredocs).
- Modify files → `Edit` tool (not `sed`, `awk`).
- Find files → `Glob` (not `find`, `ls`).
- Search code → `Grep` (not `grep`, `rg`).

Use `Bash` for: composer / spark / vendor binaries, mysql, git, npm / build.

Be PROACTIVE: don't ask permission to run specialists in parallel, don't
wait for confirmation to fix style with `phpcbf`, don't pause to ask whether
to reject violation-bearing code — just do the right thing.

---

## Creating new agents / skills / commands

When the user asks for a new agent, skill, or slash command:

1. Use the `claude-code-specialist` agent for guidance.
2. Follow the patterns of files already in `.claude/agents/`,
   `.claude/skills/`, `.claude/commands/`.
3. Keep agents under 200 lines (focused responsibility).
4. Test with a tiny example before declaring it ready.

**Agent file shape:** YAML frontmatter with `name`, `description`, `tools`,
concise description ("Use PROACTIVELY" / "MUST BE USED"), common violations
and fixes, collaboration list.

**Skill file shape:** `SKILL.md` with YAML frontmatter, supporting files in
the same directory, multi-step workflow that delegates to specialists,
worked examples.

**Slash-command file shape:** markdown with clear syntax, optional
interactive prompts, calls into skills or agents, includes usage examples.
