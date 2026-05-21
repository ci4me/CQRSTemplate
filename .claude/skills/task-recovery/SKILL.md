---
name: task-recovery
description: Detect and resume interrupted executions tracked in .claude/planning/. Load when the user asks "do I have unfinished tasks?", "resume my plan", or otherwise wants to pick up where a previous session stopped.
allowed-tools: [Read, Bash, Glob]
---

# Task Recovery

This skill recovers state from the `strategic-planner` execution tracker so a
new session can pick up where the last one stopped without re-doing finished
work.

---

## Trigger phrases

- "Do I have any unfinished tasks?"
- "Resume my tasks"
- "What was I working on?"
- "Pick up the plan from where it stopped"

When you hear one of those, follow the workflow below before doing anything
else.

---

## 1. Detect an unfinished execution

```bash
# Fast path: is the symlink pointing at an active execution?
if [ -L .claude/planning/current ]; then
  STATUS=$(jq -r '.status' .claude/planning/current/execution.json 2>/dev/null)
  if [ "$STATUS" = "in_progress" ] || [ "$STATUS" = "paused" ]; then
    echo "Found unfinished execution"
  fi
fi

# Full scan
find .claude/planning/executions -name execution.json -exec sh -c \
  'jq -r "select(.status == \"in_progress\" or .status == \"paused\") | .execution_id" "$1"' _ {} \;

# Quick summary
jq -r '.status, .plan_name, .completed_tasks, .total_tasks' \
   .claude/planning/current/execution.json
```

---

## 2. Load execution context

Three files describe a saved execution:

- `.claude/planning/current/execution.json` — overall state, including
  `status`, `completed_tasks`, `pending_tasks`, `current_group`, and the
  `todowrite_mapping` array.
- `.claude/planning/current/tasks.json` — full atomic-task definitions.
- `.claude/planning/current/metadata.json` — optional metadata (timestamps,
  plan source).

Read all three before resuming so you have:

- The full ordered task list.
- The first pending task (look for `"status": "pending"` in `todowrite_mapping`).
- The verification command for any task that claims to be complete (so you
  can confirm it before trusting it).

---

## 3. Resume from the first pending task

1. Find the first task with `"status": "pending"` in `todowrite_mapping`.
2. Look up its full definition (commands, files, verification) in `tasks.json`.
3. Verify any neighbouring "completed" claims by running each task's
   `verification_command`. If verification fails, downgrade the task back to
   `pending` and re-run it.
4. Execute the next pending task. Update `execution.json` and the user-visible
   TodoWrite list as you progress.

---

## 4. Preserve history

All execution folders are kept forever:

```
.claude/planning/
├── current → executions/exec-2026-05-20-001
└── executions/
    ├── exec-2026-05-19-001/
    │   ├── execution.json
    │   ├── tasks.json
    │   └── metadata.json
    └── exec-2026-05-20-001/
        └── ...
```

Never delete an execution folder — that would lose the audit trail. If a plan
is abandoned, set `status` to `cancelled` and leave the directory in place.

---

## 5. Validate before resuming

```bash
python .claude/skills/strategic-planner/scripts/validate_execution.py exec-<id>
```

This catches corruption (missing files, broken dependencies, stale verification
commands) before you start executing again. If validation fails, STOP and ask
the user how to proceed.

---

## See also

- `.claude/documentation/TASK-RECOVERY-GUIDE.md` — detailed recovery workflows
- `.claude/skills/strategic-planner/SKILL.md` — the planner this skill recovers from
