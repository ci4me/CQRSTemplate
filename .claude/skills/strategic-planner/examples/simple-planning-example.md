# Simple Planning Example: Add Column to Table

**Complexity:** SIMPLE
**Duration:** ~12 minutes
**Risk:** LOW

## User Request

> "Add a 'stock' column to the Cookie table to track inventory"

## Phase 1: Understanding (Chain-of-Thought)

```markdown
# 💭 Understanding the Request

User wants to track inventory for cookies.

**What this involves:**
- Database migration (add stock column)
- Update Cookie entity (add stock property)
- Update commands/queries (handle stock)
- Update views (display stock)

**Complexity Assessment:** SIMPLE
- Clear requirement (just add column)
- Follow existing pattern (similar to price column)
- No business logic complexity

**Risk Level:** LOW
- Non-breaking change (nullable column)
- Easy rollback (drop column)
- No security implications

→ **Decision:** Skip ToT (obvious single approach), use CoT + SMART-E tasks
```

## Phase 2: Skip Tree-of-Thought

**Why skip:** Only one sensible approach (add integer column)

## Phase 3: Skip Pre-Mortem

**Why skip:** LOW risk, non-critical feature

## Phase 4: Generate SMART-E Atomic Tasks

### Task 1: Create Migration

**S - Specific:**
- File: `app/Database/Migrations/2025_10_22_AddStockToCookies.php`
- Changes: Add `stock INT UNSIGNED DEFAULT 0` column

**M - Measurable:**
- File exists: ✅/❌
- Migration runs: ✅/❌

**A - Achievable:**
- No blockers
- Can start immediately

**R - Relevant:**
- Enables inventory tracking
- Foundation for all stock features

**T - Testable:**
- Command: `php spark migrate --dry-run`
- Expected: "Migration can run"

**E - Executable:**
- Can start: NOW
- Tool: Write + codeigniter4-specialist

**Atomicity:**
- ✅ Files: 1
- ✅ Duration: 4 minutes
- ✅ Binary: File valid OR not
- ✅ Independent: Can verify alone
- ✅ Single responsibility: Creates migration

### Task 2: Run Migration

**S - Specific:**
- Command: `php spark migrate`
- Verify column exists in schema

**M - Measurable:**
- Column exists in database: ✅/❌

**A - Achievable:**
- Blocker: Task 1 must complete
- Depends on: task_1

**R - Relevant:**
- Applies schema change
- Required before code uses column

**T - Testable:**
- Command: `mysql -e "DESCRIBE cookies"`
- Expected: stock column present

**E - Executable:**
- Can start: After task_1
- Tool: Bash

**Atomicity:**
- ✅ Files: 0 (command only)
- ✅ Duration: 2 minutes
- ✅ Binary: Column exists OR doesn't
- ✅ Independent: Query database
- ✅ Single responsibility: Runs migration

### Task 3: Update Cookie Entity

**S - Specific:**
- File: `app/Domain/Cookie/Entities/Cookie.php`
- Changes: Add `private int $stock` property, add to constructor, add getStock() method

**M - Measurable:**
- Property added: ✅/❌
- PHPStan passes: ✅/❌

**A - Achievable:**
- Depends on: task_2 (column must exist)

**R - Relevant:**
- Entity represents database
- Enables business logic

**T - Testable:**
- Command: `composer phpstan`
- Expected: 0 errors

**E - Executable:**
- Can start: After task_2
- Tool: Edit + ddd-specialist

**Atomicity:**
- ✅ Files: 1
- ✅ Duration: 6 minutes
- ✅ Binary: Valid entity OR not
- ✅ Independent: PHPStan on this file
- ✅ Single responsibility: Adds stock property

## Phase 5: Python Validation (Skip for SIMPLE)

**Why skip:** Only 3 tasks, obvious sequential flow

**Manual validation:**
- Task 1 → Task 2 → Task 3 (clear dependencies)
- Duration: 4 + 2 + 6 = 12 minutes
- No parallelization opportunities

## Presenting to User

```markdown
# 🧠 Execution Plan: Add Stock Column

## Summary
- **Objective:** Add inventory tracking to Cookie
- **Approach:** Simple integer column (default 0)
- **Duration:** 12 minutes (sequential)
- **Risk Level:** LOW
- **Total Tasks:** 3 atomic tasks

## 📋 Tasks

### Task 1: Create migration for stock column (4 min)
- File: app/Database/Migrations/2025_10_22_AddStockToCookies.php
- Verify: php spark migrate --dry-run

### Task 2: Run migration (2 min)
- Depends on: Task 1
- Verify: Column exists in database

### Task 3: Update Cookie entity with stock property (6 min)
- Depends on: Task 2
- File: app/Domain/Cookie/Entities/Cookie.php
- Verify: composer phpstan

## 🚀 Ready to Execute?

Respond "yes" to proceed.
```

## TodoWrite Execution

```javascript
TodoWrite({
  todos: [
    {
      content: "Create migration for stock column",
      activeForm: "Creating migration for stock column",
      status: "pending"
    },
    {
      content: "Run migration to add stock column",
      activeForm: "Running migration",
      status: "pending"
    },
    {
      content: "Update Cookie entity with stock property",
      activeForm: "Updating Cookie entity",
      status: "pending"
    }
  ]
})
```

## Key Takeaways

**When to use simple planning:**
- Clear single approach
- Low complexity (3-5 tasks)
- Low risk
- Sequential flow obvious

**What to skip:**
- Tree-of-Thought (no alternatives)
- Pre-Mortem (low risk)
- Python scripts (few tasks, obvious flow)

**What to keep:**
- Chain-of-Thought (show understanding)
- SMART-E atomic tasks (precision)
- TodoWrite integration (tracking)
