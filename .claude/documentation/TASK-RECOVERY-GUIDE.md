# Task Planning & Recovery Guide

Solutions for UI flickering and resuming interrupted executions.

---

## Problem 1: UI Flickering from JSON Task Files

### Issue
When strategic-planner creates/edits task JSON files, Claude Code UI updates constantly, causing flickering and visual noise.

### Root Cause
- Task files were in `temp/` directory (visible in file tree)
- Files were edited during execution (not just planning)
- Each edit triggers UI refresh

### Solution: Hidden Directory + Write-Once Pattern

#### 1. New Location: `.claude/planning/`

**Before:**
```
temp/tasks-1729642800.json  ← Visible, causes flickering
```

**After:**
```
.claude/planning/tasks-1729642800.json  ← Hidden, persists
```

**Benefits:**
- ✅ Less prominent in file tree (`.claude/` is infrastructure)
- ✅ Gitignored automatically
- ✅ Persists across sessions (recovery capability)
- ✅ Follows project conventions

#### 2. Write-Once Pattern

**Before (causes flickering):**
```
Create JSON → Edit during planning → Edit during execution → Constant updates ❌
```

**After (no flickering):**
```
Create JSON → Validate → Lock it → Use TodoWrite for tracking ✅
```

**Key insight:** JSON file is for **planning validation only**, not execution tracking!

---

## Problem 2: Resuming After Claude Code Stops

### Issue
If Claude Code crashes or stops, how do you resume partially completed tasks?

### Solution: Dual Recovery Points

#### Recovery Point 1: TodoWrite (Primary)
- Persists in Claude Code's todo system
- Survives crashes/restarts
- Check with: Look at existing todos in sidebar

#### Recovery Point 2: Task JSON File (Backup)
- Stored in `.claude/planning/tasks-{timestamp}.json`
- Contains full task definitions
- Used to regenerate TodoWrite if needed

### Recovery Process

#### Step 1: Assess Situation

```bash
# Find latest task plan
ls -lt .claude/planning/tasks-*.json | head -1

# Check what files were actually created (verify completion)
git status
ls -lt {expected-output-files}
```

#### Step 2: Check TodoWrite Status

Look at Claude Code's todo sidebar:
- ✅ Completed tasks - verified done
- 🔄 In-progress task - may be partial
- ⏸️ Pending tasks - not started

#### Step 3: Verify Completion Claims

For each task marked "completed" in TodoWrite, verify the actual result:

```bash
# Example: If task was "Create placement-decision-tree.md"
test -f .claude/skills/business-rule-addition/patterns/placement-decision-tree.md && echo "✅ Verified" || echo "❌ Not done"

# Example: If task was "Add allowed-tools to skill"
grep -q "allowed-tools" .claude/skills/domain-scaffolding/SKILL.md && echo "✅ Verified" || echo "❌ Not done"
```

#### Step 4: Resume Execution

**If TodoWrite still exists:**
```
1. Read task JSON to understand what each pending task does
2. Continue from first pending/in_progress task
3. Launch parallel groups starting from current group
```

**If TodoWrite is lost:**
```
1. Read task JSON file
2. Verify each task's completion by checking files
3. Recreate TodoWrite with verified status:
   - Mark verified tasks as "completed"
   - Mark unverified tasks as "pending"
4. Continue from first pending task
```

#### Step 5: Resume Code Example

```markdown
User: "Resume the skills compliance audit"

Assistant reads:
1. .claude/planning/tasks-1729642800.json (latest plan)
2. TodoWrite status (if exists)
3. File system (verify completion)

Assistant responds:
"Found interrupted plan with 16 tasks. Status:
- ✅ Tasks 1-13: Completed and verified
- 🔄 Task 14: In progress (file partially created)
- ⏸️ Tasks 15-16: Pending

Resuming from task 14..."
```

---

## Example: Complete Recovery Scenario

### Scenario
You started skills compliance audit. Claude Code crashed after completing Group 2 (tasks 1-12). How to resume?

### Recovery Steps

**1. Find the plan:**
```bash
$ ls -lt .claude/planning/tasks-*.json | head -1
.claude/planning/tasks-1729642800.json
```

**2. Check TodoWrite:**
```
Todos show:
- Tasks 1-12: ✅ completed
- Task 13: 🔄 in_progress
- Tasks 14-16: ⏸️ pending
```

**3. Verify claims:**
```bash
# Task 13 claims: "Add AskUserQuestion approval to strategic-planner"
$ grep -c "AskUserQuestion" .claude/skills/strategic-planner/SKILL.md
2  # ✅ Found it, task actually completed

# Task 14 claims: "Create placement-decision-tree.md"
$ test -f .claude/skills/business-rule-addition/patterns/placement-decision-tree.md
# ❌ File doesn't exist, task not done
```

**4. Resume:**
```markdown
Assistant updates TodoWrite:
- Tasks 1-13: completed (verified)
- Task 14: in_progress (starting now)
- Tasks 15-16: pending

Assistant continues:
"Resuming from task 14: Create placement-decision-tree.md..."
[Creates file]
[Continues with tasks 15-16]
```

---

## Prevention: How Strategic-Planner Now Works

### Planning Phase (Phases 1-5)

```
1. Understand request (Phase 1)
   ↓ STOP if questions needed → AskUserQuestion → Resume
2. Explore solutions (Phase 2, if complex)
3. Risk assessment (Phase 3, if high risk)
4. Generate tasks → Write to .claude/planning/tasks-{timestamp}.json (ONCE)
5. Validate with Python scripts
6. Present plan → AskUserQuestion for approval
   ↓ If user approves...
```

### Execution Phase (Phase 6)

```
7. Create TodoWrite with all tasks as "pending"
8. Execute Group 1 tasks in parallel
9. Mark Group 1 as completed in TodoWrite
10. Execute Group 2 tasks in parallel
11. Mark Group 2 as completed in TodoWrite
... continue ...

Note: JSON file NEVER edited during this phase!
```

### If Interrupted

```
Recovery process uses:
- TodoWrite (what's claimed done)
- File system (what's actually done)
- JSON file (what needs to be done)

→ Verify → Resume → Complete
```

---

## Configuration

### 1. Ensure .claude/planning/ is Gitignored

Already done automatically, but verify:

```bash
$ cat .gitignore | grep planning
.claude/planning/
```

### 2. Create Directory (First Time)

Already created, but for future projects:

```bash
mkdir -p .claude/planning
```

### 3. Strategic-Planner Updated

The skill now:
- ✅ Uses `.claude/planning/` for task files
- ✅ Includes recovery instructions
- ✅ Implements write-once pattern
- ✅ Documents interruption handling

---

## Quick Reference

### Find Latest Plan
```bash
ls -lt .claude/planning/tasks-*.json | head -1
```

### Verify Task Completion
```bash
# Check file exists
test -f {expected-file} && echo "✅" || echo "❌"

# Check file contains expected content
grep -q "{expected-pattern}" {file} && echo "✅" || echo "❌"
```

### Resume Command
```markdown
"Resume the [project name] tasks from .claude/planning/tasks-{timestamp}.json"
```

### Clean Old Plans
```bash
# Remove plans older than 30 days
find .claude/planning -name "tasks-*.json" -mtime +30 -delete
```

---

## Summary

### Problem 1: UI Flickering
**Solution:** Use `.claude/planning/` + write-once pattern

### Problem 2: Resume After Crash
**Solution:** Dual recovery (TodoWrite + JSON file) + verification

### Both Issues Resolved ✅

- No more UI flickering from task file edits
- Complete recovery capability if Claude Code stops
- Clear documented process for resuming work
- Prevention built into strategic-planner skill

---

**See Also:**
- `.claude/planning/README.md` - Directory purpose and usage
- `.claude/skills/strategic-planner/SKILL.md` - Full skill documentation
- Section "Recovering from Interruptions" in strategic-planner

---

**Last Updated:** 2025-10-22
