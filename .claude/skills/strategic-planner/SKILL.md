---
name: strategic-planner
description: Advanced planning skill that uses Tree-of-Thought exploration, Chain-of-Thought reasoning, and SMART-E atomic task generation with Python-powered analysis. Use when user requests complex task planning, needs todo list generation, wants to "break down" work, or asks "how to implement" something. Provides surgical precision planning with optimized execution paths.
allowed-tools: [Read, Write, Edit, Glob, Grep, Bash, Task, TodoWrite, AskUserQuestion]
---

# Strategic Planner - Surgical Precision Task Planning

Transform complex requests into executable SMART-E atomic tasks using AI reasoning + computational analysis.

## When to Use This Skill

**Automatically invoke when user:**
- Asks "How do I implement [feature]?"
- Requests "Create a plan for [task]"
- Says "Break down [complex work]"
- Requests "Generate todo list for [project]"
- Asks "What steps are needed to [goal]?"

**Especially valuable for:**
- Multi-step implementations (5+ tasks)
- Complex features (auth, payments, integrations)
- Refactoring projects
- New domain/module creation
- Architecture changes

## Core Philosophy

**"Think deeply, show your work, execute precisely"**

1. 🌳 **Explore alternatives** (Tree-of-Thought) - Consider multiple approaches
2. 💭 **Show reasoning** (Chain-of-Thought) - Transparent decision-making
3. ⚛️ **Generate atomic tasks** (SMART-E) - Precise, executable units
4. 🐍 **Validate with Python** - Computational analysis ensures quality
5. 🎓 **Learn patterns** - Improves recommendations over time

## The 5-Phase Planning Process

### Phase 1: Understanding & Analysis (Chain-of-Thought)

**Show your thinking process transparently:**

```markdown
# 💭 Understanding the Request

User said: "[original request]"

Let me break down what this means:
- [What's involved]
- [Questions raised]
- [Assumptions made]

**Complexity Assessment:** [TRIVIAL/SIMPLE/MODERATE/COMPLEX/VERY COMPLEX]
- [Reasoning for level]

**Risk Level:** [LOW/MEDIUM/HIGH/CRITICAL]
- [Risk factors identified]

→ **Decision:** [Which planning approach to use]
```

**Details:** See `frameworks/cot-templates.md` for templates and examples.

**Important:** If you need clarification from the user:
- STOP planning immediately
- Use AskUserQuestion tool to gather needed information
- Resume planning after receiving the user's answer

---

### Phase 2: Explore Solution Space (Tree-of-Thought)

**For complex tasks, explore 2-3 approaches:**

```markdown
# 🌳 Exploring Solution Approaches

## Branch A: [Approach Name]
✅ Pros | ❌ Cons | Compatibility | Complexity | Risk

## Branch B: [Alternative]
✅ Pros | ❌ Cons | Compatibility | Complexity | Risk

## Branch C: [Another Alternative]
→ **ELIMINATED** (if deal-breaker found)

---

## 🎯 Branch Selection

**Decision Matrix:**
| Criterion | Branch A | Branch B | Branch C |
|-----------|----------|----------|----------|

**SELECTED: Branch A**
**Reasoning:** [Why this approach chosen]
**Trade-offs accepted:** [What we're compromising on]
```

**When to use ToT:**
- COMPLEX or VERY COMPLEX tasks
- Multiple valid approaches exist
- High-risk decisions with trade-offs

**When to skip ToT:**
- TRIVIAL or SIMPLE tasks
- Single obvious approach
- Pattern exists in library

**Details:** See `frameworks/tot-patterns.md` for complete guide.

---

### Phase 3: Risk Assessment (Pre-Mortem)

**For HIGH/CRITICAL risk tasks:**

```markdown
# ⚠️ Pre-Mortem: What Could Go Wrong?

"It's 1 week later and [feature] is broken. What happened?"

**Brainstorming failures:**

🔴 **CRITICAL:** [Failure scenario]
- Impact: [What breaks]
- Likelihood: [HIGH/MEDIUM/LOW]
- Mitigation: [Add task X to plan]

🟠 **HIGH:** [Failure scenario]
🟡 **MEDIUM:** [Failure scenario]
🟢 **LOW:** [Failure scenario] (monitor, don't add task)

**Mitigations added to plan:**
- Task X.Y: [Mitigation] (PRIORITY)
```

**When to use Pre-Mortem:**
- HIGH or CRITICAL risk tasks
- Security-sensitive features
- Financial transactions
- Data migrations
- Authentication changes

**Details:** See examples in `examples/complex-planning-example.md`.

---

### Phase 4: Generate SMART-E Atomic Tasks

**Create tasks following strict criteria:**

#### SMART-E Framework

Every task MUST satisfy all 6 criteria:
- **S**pecific: Exact files and changes defined
- **M**easurable: Clear success criteria
- **A**chievable: No blockers preventing start
- **R**elevant: Contributes to parent goal
- **T**estable: Verification command provided
- **E**xecutable: Can start immediately OR after deps

**Details:** See `frameworks/smart-e-detailed.md` for complete guide.

#### The 5 Atomicity Rules

Each task MUST satisfy all 5 rules:
1. **≤3 files modified** (ideally 1)
2. **<30 minutes duration** (ideally <10)
3. **Binary done state** (done OR not done, no "80% done")
4. **Independently verifiable** (doesn't need other tasks to verify)
5. **Single responsibility** (does ONE thing only)

**Details:** See `frameworks/atomicity-rules.md` for examples and fixes.

#### Task Format

Write all tasks to JSON file:

**Real PHP/CQRS Example:**

```json
{
  "task_1.1": {
    "name": "Create CookieFlavor value object with validation",
    "files": ["app/Domain/Cookie/ValueObjects/CookieFlavor.php"],
    "duration_minutes": 8,
    "depends_on": [],
    "risk": "low",
    "specific_changes": "Create readonly class with fromString() factory, 3-50 char validation, DomainLogger integration",
    "success_criteria": "Value object validates flavor correctly with error codes",
    "blockers": ["none"],
    "verification_command": "vendor/bin/phpstan analyse app/Domain/Cookie/ValueObjects/CookieFlavor.php --level=8",
    "can_start_immediately": true,
    "definition_of_done": [
      "File exists at app/Domain/Cookie/ValueObjects/CookieFlavor.php",
      "Uses readonly class pattern",
      "Has private constructor with validation",
      "Has static fromString() factory method",
      "Validates length 3-50 characters",
      "Uses DomainLogger for validation failures",
      "Uses ErrorCodes::COOKIE_VALIDATION_FLAVOR",
      "PHPStan Level 8 passes with 0 errors"
    ]
  },
  "task_1.2": {
    "name": "Update Cookie entity to use CookieFlavor",
    "files": ["app/Domain/Cookie/Entities/Cookie.php"],
    "duration_minutes": 6,
    "depends_on": ["task_1.1"],
    "risk": "low",
    "specific_changes": "Add private CookieFlavor $flavor property, update constructor, create(), reconstitute(), update(), add getFlavor() getter",
    "success_criteria": "Cookie entity uses CookieFlavor value object",
    "blockers": ["task_1.1 must be completed"],
    "verification_command": "vendor/bin/phpstan analyse app/Domain/Cookie/Entities/Cookie.php --level=8",
    "can_start_immediately": false,
    "definition_of_done": [
      "Added CookieFlavor $flavor property",
      "Updated constructor signature",
      "Updated create() method",
      "Updated reconstitute() method",
      "Updated update() method",
      "Added getFlavor() getter",
      "PHPStan Level 8 passes"
    ]
  },
  "task_3.1": {
    "name": "Create migration AddFlavorToCookiesTable",
    "files": ["app/Database/Migrations/2025_10_26_000001_AddFlavorToCookies.php"],
    "duration_minutes": 5,
    "depends_on": [],
    "risk": "low",
    "specific_changes": "Create migration with up()/down() methods to add/drop flavor column (VARCHAR 50)",
    "success_criteria": "Migration runs without errors",
    "blockers": ["none"],
    "verification_command": "php spark migrate --dry-run",
    "can_start_immediately": true,
    "definition_of_done": [
      "File exists at correct path",
      "up() adds flavor VARCHAR(50) NOT NULL",
      "down() drops flavor column",
      "Migration name follows CodeIgniter convention"
    ]
  }
}
```

**Save to:** `.claude/planning/executions/exec-{execution-id}/tasks.json`

**Execution ID format:** `exec-YYYYMMDD-HHMMSS` (e.g., `exec-20251022-143000`)

**Directory structure:**
```bash
mkdir -p .claude/planning/executions/exec-{execution-id}
```

**Why execution folders?**
- All files for one execution together
- Never lose history (for learning and improvements)
- Easy to analyze patterns across executions
- Complete audit trail forever
- Can compare similar tasks over time

**JSON Schema Validation:**

All task JSON files are validated against strict schemas:
- **Schema location:** `.claude/skills/strategic-planner/schemas/task-schema.json`
- **Validates:** SMART-E compliance, atomicity rules, required fields
- **Template available:** `.claude/skills/strategic-planner/templates/task-template.json`

**Example task structure:**
```json
{
  "task_1.1": {
    "name": "Add UserName value object with validation",
    "files": ["app/Domain/User/ValueObjects/UserName.php"],
    "duration_minutes": 8,
    "depends_on": [],
    "risk": "low",
    "specific_changes": "Create UserName.php with readonly class...",
    "success_criteria": "UserName value object created with validation",
    "blockers": ["none"],
    "verification_command": "vendor/bin/phpstan analyse ...",
    "can_start_immediately": true,
    "definition_of_done": ["File exists", "Validation implemented", ...]
  }
}
```

---

### Phase 5: Python-Powered Validation & Optimization

**Run automated analysis:**

```bash
cd .claude/skills/strategic-planner
source venv/bin/activate

# Set execution ID
EXEC_ID="exec-$(date +%Y%m%d-%H%M%S)"

# Step 1: Validate atomicity (MUST pass before proceeding)
python scripts/2_atomicity_validator.py ../../planning/executions/${EXEC_ID}/tasks.json

# Step 2: Analyze dependencies and critical path
python scripts/1_dependency_analyzer.py ../../planning/executions/${EXEC_ID}/tasks.json
```

**If atomicity validator returns FAIL:**
- Read failure details
- Fix violated tasks
- Re-validate
- **Do NOT proceed until PASS**

**Use dependency analyzer output for:**
- Identify critical path (longest chain)
- Group parallelizable tasks
- Calculate time savings
- Estimate accurate duration

**Details:** See `python-scripts-guide.md` for setup and usage.

---

## Presenting the Plan

**Format the final plan as:**

```markdown
# 🧠 Execution Plan: [Feature Name]

## Summary
- **Objective:** [Clear goal]
- **Approach:** [Selected approach + reasoning]
- **Duration:** [X minutes] (critical path)
- **Optimization:** [Y%] time savings via parallelization
- **Risk Level:** [LEVEL] → Mitigated to [LEVEL]
- **Total Tasks:** [N] atomic tasks across [M] phases

## 💭 Reasoning Summary
[Key decisions and trade-offs]

## ⚠️ Risk Mitigations
[Pre-mortem results if applicable]

## 📊 Execution Strategy
**Critical Path:** task_X → task_Y → task_Z
**Parallel Opportunities:** [Groups of parallel tasks]

## 📋 Atomic Tasks
[Organized by phase with SMART-E details]

## ✅ Quality Validation
- ✅ Atomicity: [PASS/WARN/FAIL]
- ✅ Dependencies: No circular dependencies
- ✅ Critical path: Optimized

## 🚀 Ready to Execute?

**Stop here if you only need the plan.** Present the plan to the user and ask for approval before proceeding.

**Use AskUserQuestion tool** with these options:
- "Execute now" → Proceed to Phase 6 with TodoWrite + agent orchestration
- "Modify plan first" → User has questions or wants changes
- "Just show plan (no execution)" → Stop at planning phase

**If user approves**, continue to Phase 6 for execution.
```

---

## Recovering from Interruptions

**If Claude Code stops, crashes, or execution is interrupted:**

### Quick Recovery (3 steps):

1. **Read state file** - Get complete execution context
2. **Verify completion** - Check claimed completed tasks
3. **Resume execution** - Continue from next pending task

### Detailed Recovery Process:

**Step 1: Load execution state**
```bash
# Read the current execution state (symlink to active execution)
cat .claude/planning/current/execution.json

# Or list all executions
ls -lt .claude/planning/executions/
```

This shows:
- Execution ID
- Which task file is being executed
- Current progress (completed/pending counts)
- Which group you're in
- TodoWrite position → task_id mapping
- Execution history

**Step 2: Verify completed tasks**
```bash
# For each task claimed as "completed" in state file, verify:
task_id=$(jq -r '.todowrite_mapping."14".task_id' .claude/planning/current/execution.json)
# → "task_3.1"

# Read task details
jq ".${task_id}" .claude/planning/current/tasks.json
# → Shows files to create, verification command

# Run verification
test -f {expected-file} && echo "✅ Verified" || echo "❌ Not done"
```

**Step 3: Resume execution**
```markdown
# If state file exists and TodoWrite persists:
1. Read current/execution.json for context
2. Check TodoWrite for current position
3. Verify completion of in_progress tasks
4. Continue from next pending task in current group

# If state file exists but TodoWrite lost:
1. Read current/execution.json for full mapping
2. Recreate TodoWrite from todowrite_mapping
3. Mark verified tasks as completed
4. Continue from first pending

# If current symlink broken (rare):
1. List executions: ls -lt .claude/planning/executions/
2. Find most recent with status: "in_progress"
3. Recreate symlink: ln -sfn executions/exec-{id} .claude/planning/current
4. Resume from Step 1

# All executions preserved for analysis
No files ever deleted - complete history available
```

### Recovery Example

**State file shows:**
```json
{
  "execution_id": "exec-20251022-143000",
  "plan_name": "Skills Compliance Audit",
  "current_group": 3,
  "completed_tasks": 13,
  "todowrite_mapping": {
    "14": {"task_id": "task_3.1", "status": "in_progress"},
    "15": {"task_id": "task_3.2", "status": "pending"}
  },
  "task_file_path": ".claude/planning/executions/exec-20251022-143000/tasks.json"
}
```

**Resume process:**
```
1. Load task details for task_3.1 from current/tasks.json
2. Check if task_3.1 actually completed (verify file exists)
3. If completed: Mark as done, move to task_3.2
4. If not completed: Resume task_3.1
5. Update state file + TodoWrite as you go
```

**Complete execution history preserved:**
- **Execution folder** (`.claude/planning/executions/exec-{id}/`) - All files together
- **State file** (`execution.json`) - Complete context (validated against schema)
- **Task file** (`tasks.json`) - Task details (validated against schema)
- **Metadata file** (`metadata.json`) - Optional learning data (validated against schema)
- **TodoWrite** - User-visible progress
- **Symlink** (`.claude/planning/current/`) - Easy access
- **Never deleted** - Full history for analysis and learning

**Available Resources:**
- **Schemas:** `.claude/skills/strategic-planner/schemas/` (JSON Schema validation)
- **Templates:** `.claude/skills/strategic-planner/templates/` (Example JSON structures)
- **Validator:** `.claude/skills/strategic-planner/scripts/validate_execution.py` (Integrity checks)

**All persist across sessions for bulletproof recovery + continuous improvement!**

---

### Phase 6: Orchestrate Execution (Agent Delegation)

**After user confirms plan, orchestrate execution using specialist agents in parallel.**

#### Step 1: Discover Available Agents, Skills, and Map Tasks

**Check available agents:** List files in `.claude/agents/` directory to see which specialists are available.

**Check available skills:** List files in `.claude/skills/` directory to see which skills can assist.

For each agent file, check:
- Agent name (from filename)
- Description (from file header)
- Tools available (from file content)
- Specialty/responsibility (from file content)

For each skill file, check:
- Skill name (from YAML frontmatter)
- Description (from YAML frontmatter)
- When to use (from file content)
- Multi-step workflows available

**Task Mapping Strategy:**

For each task, determine:
1. **Primary agent:** Which specialist handles this task type?
   - PHP files → php-specialist (if available)
   - Domain patterns → ddd-specialist (if available)
   - CQRS components → cqrs-specialist (if available)
   - Tests → test-specialist (if available)
   - Code refactoring → clean-code-specialist (if available)

2. **Supporting skills:** Could another skill help with complex multi-step work?
   - Code quality validation → code-review skill (if available)
   - New domain creation → domain-scaffolding skill (if available)
   - Property additions → property-addition skill (if available)
   - Business rule additions → business-rule-addition skill (if available)

3. **Orchestrator pattern:** Use CLAUDE.md orchestrator pattern as guide

**See:** `orchestration-patterns.md` for task-to-agent mapping patterns (dynamically updates as new agents/skills are added).

#### Step 2: Create Execution Folder & State Files

**IMPORTANT:** Create execution folder FIRST with unique ID.

**2.1 Generate execution ID and create folder:**

```bash
# Generate execution ID: exec-YYYYMMDD-HHMMSS
EXEC_ID="exec-$(date +%Y%m%d-%H%M%S)"

# Create execution folder
mkdir -p .claude/planning/executions/${EXEC_ID}
```

**Or use Bash tool in Claude Code:**

```bash
mkdir -p .claude/planning/executions/exec-$(date +%Y%m%d-%H%M%S)
```

**2.2 Create execution state file:**

**Use Write tool in Claude Code:**

```json
{
  "execution_id": "exec-20251026-143000",
  "plan_name": "Add flavor property to Cookie domain",
  "task_file": "tasks.json",
  "task_file_path": ".claude/planning/executions/exec-20251026-143000/tasks.json",
  "started_at": "2025-10-26T14:30:00Z",
  "last_updated": "2025-10-26T14:30:00Z",
  "status": "in_progress",
  "total_tasks": 16,
  "total_groups": 4,
  "current_group": 1,
  "completed_tasks": 0,
  "pending_tasks": 16,
  "todowrite_mapping": {
    "1": {"task_id": "task_1.1", "description": "Create CookieFlavor value object", "status": "pending"},
    "2": {"task_id": "task_1.2", "description": "Update Cookie entity", "status": "pending"},
    "3": {"task_id": "task_2.1", "description": "Update CreateCookieCommand", "status": "pending"}
  },
  "execution_log": [
    {"time": "2025-10-26T14:30:00Z", "event": "Execution started", "group": 1}
  ]
}
```

**2.3 Create symlink to current execution:**

```bash
# Point 'current' to active execution
ln -sfn executions/${executionId} .claude/planning/current
```

Now `.claude/planning/current/execution.json` always points to active execution!

**2.4 Create TodoWrite with task ID references:**

**Use TodoWrite tool in Claude Code:**

```
TodoWrite with PHP/CQRS examples:
[task_1.1] Create CookieFlavor value object with validation
[task_1.2] Update Cookie entity to use CookieFlavor
[task_2.1] Update CreateCookieCommand with flavor parameter
[task_2.2] Update CreateCookieHandler to handle flavor
[task_3.1] Create migration AddFlavorToCookiesTable
[task_3.2] Update CookieModel $allowedFields
[task_3.3] Update CookieRepository save() and toDomainEntity()
```

**Example TodoWrite call:**

```
{ content: "[task_1.1] Create CookieFlavor value object", activeForm: "Creating CookieFlavor value object", status: "pending" }
{ content: "[task_1.2] Update Cookie entity", activeForm: "Updating Cookie entity", status: "pending" }
{ content: "[task_2.1] Update CreateCookieCommand", activeForm: "Updating CreateCookieCommand", status: "pending" }
```

**Why execution folder + state file + TodoWrite?**
- **Execution folder** = All related files organized together
- **State file** = Complete context (progress, mapping, history)
- **TodoWrite** = User-visible progress tracking
- **Symlink** = Easy access to current execution
- **Never deleted** = Complete history for learning and improvements

#### Step 3: Launch Parallel Agent Groups

Use dependency analysis from Phase 5 to group tasks by parallel execution level.

**Pattern: Launch all parallel tasks in SINGLE message**

**Example for adding flavor property to Cookie domain:**

```
Group 1: Launch all independent tasks simultaneously

Task(agent: "ddd-specialist", description: "Create CookieFlavor value object with validation (3-50 chars)")
Task(agent: "ddd-specialist", description: "Update Cookie entity to use CookieFlavor value object")
Task(agent: "cqrs-specialist", description: "Update CreateCookieCommand with flavor parameter")
Task(agent: "cqrs-specialist", description: "Update UpdateCookieCommand with flavor parameter")

Continue for all Group 1 tasks in same message
```

**Wait for Group 1 to complete, then launch Group 2:**

```
Group 2: Tasks that depend on Group 1

Task(agent: "cqrs-specialist", description: "Update CreateCookieHandler to handle flavor")
Task(agent: "cqrs-specialist", description: "Update UpdateCookieHandler to handle flavor")
Task(agent: "phpstan-specialist", description: "Verify type safety for all handler changes")
```

**Example for complete domain scaffolding:**

```
Group 1: Value Objects and Entity (12 parallel tasks)

Task(agent: "ddd-specialist", description: "Create OrderNumber value object")
Task(agent: "ddd-specialist", description: "Create OrderTotal value object")
Task(agent: "ddd-specialist", description: "Create Order entity with factory methods")
...
```

#### Step 4: Track Progress with State File & TodoWrite

**Update BOTH after each task/group completes:**

**4.1 Update state file (use Edit tool):**

Update these fields in `.claude/planning/current/execution.json`:
- `completed_tasks` (increment)
- `pending_tasks` (decrement)
- `current_group` (when group finishes)
- `todowrite_mapping` (update status for completed tasks)
- `execution_log` (append completion event)

**4.2 Update TodoWrite:**

**PHP/CQRS Example:**

```
{ content: "[task_1.1] Create CookieFlavor value object", status: "completed" },
{ content: "[task_1.2] Update Cookie entity", status: "in_progress" },
{ content: "[task_2.1] Update CreateCookieCommand", status: "pending" }
{ content: "[task_2.2] Update CreateCookieHandler", status: "pending" }
```

**Example state file update after Group 1:**
```json
{
  "current_group": 2,
  "completed_tasks": 5,
  "pending_tasks": 11,
  "last_updated": "2025-10-22T14:35:00Z",
  "todowrite_mapping": {
    "1": {"task_id": "task_1.1", "status": "completed"},
    "2": {"task_id": "task_1.2", "status": "completed"},
    ...
    "6": {"task_id": "task_2.1", "status": "in_progress"}
  },
  "execution_log": [
    {"time": "2025-10-22T14:30:00Z", "event": "Execution started", "group": 1},
    {"time": "2025-10-22T14:35:00Z", "event": "Group 1 completed", "tasks": 5}
  ]
}
```

**State transitions:**
- **pending** → Agent hasn't started yet
- **in_progress** → Agent is currently working
- **completed** → Agent finished successfully

#### Step 5: Coordinate Sequential Groups

**Critical:** Wait for all tasks in a group to complete before starting next group.

```markdown
Execution flow:
1. Launch Group 1 (12 tasks parallel) → Wait for all to complete
2. Launch Group 2 (4 tasks parallel) → Wait for all to complete
3. Launch Group 3 (1 task) → Final task
```

**Time savings example:**
- Sequential: 135 minutes
- Parallel groups: 24 minutes (critical path)
- **Efficiency gain: 82%**

**Details:** See `agent-delegation-guide.md` for complete patterns and `parallel-execution-examples.md` for real scenarios.

---

## Quick Reference

### Complexity Levels

| Level | Tasks | Duration | Dependencies | Use ToT? | Pre-Mortem? | Python? |
|-------|-------|----------|--------------|----------|-------------|---------|
| TRIVIAL | 1-2 | <5 min | None | ❌ | ❌ | ❌ |
| SIMPLE | 3-5 | <15 min | Simple | ❌ | ❌ | ❌ |
| MODERATE | 6-15 | <45 min | Some | ⚠️ | ⚠️ | ✅ |
| COMPLEX | 16-30 | <2 hrs | High | ✅ | ✅ | ✅ |
| VERY COMPLEX | 30+ | 2+ hrs | Critical path | ✅ | ✅ | ✅ |

### Risk Levels

| Risk | Pre-Mortem? | Extra Validation? | Examples |
|------|-------------|-------------------|----------|
| LOW | ❌ | ❌ | Add column, update view |
| MEDIUM | ⚠️ | ✅ | Add table, migration |
| HIGH | ✅ | ✅ | Auth changes, security |
| CRITICAL | ✅ | ✅✅ | Payments, PCI, data loss risk |

---

## Supporting Documentation

### Frameworks (Deep Dives)
- `frameworks/smart-e-detailed.md` - SMART-E criteria explained
- `frameworks/atomicity-rules.md` - 5 atomicity rules with examples
- `frameworks/tot-patterns.md` - Tree-of-Thought exploration patterns
- `frameworks/cot-templates.md` - Chain-of-Thought reasoning templates

### Guides
- `python-scripts-guide.md` - Python scripts setup and usage
- `todowrite-integration.md` - TodoWrite integration guide
- `pattern-library.md` - Learned patterns (evolves over time)

### Examples
- `examples/simple-planning-example.md` - Simple feature addition (3 tasks)
- `examples/complex-planning-example.md` - Complex system integration (47 tasks)

---

**Version:** 2.0.0 (Refactored for progressive disclosure)
**Last Updated:** 2025-10-22
