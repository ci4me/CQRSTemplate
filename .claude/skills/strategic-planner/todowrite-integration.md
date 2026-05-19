# TodoWrite Integration Guide

Strategic-planner generates atomic tasks that integrate seamlessly with Claude Code's TodoWrite tool.

## Workflow

1. **Generate SMART-E tasks** (JSON format)
2. **Validate with Python** (atomicity + dependencies)
3. **Present plan to user** (with reasoning)
4. **User confirms** ("yes" to proceed)
5. **Convert to TodoWrite** (create todo entries)
6. **Execute sequentially** (track progress)

## JSON to TodoWrite Conversion

### Input: JSON Tasks

```json
{
  "task_1.1": {
    "name": "Create migration for flavor column",
    "duration_minutes": 5,
    "depends_on": []
  },
  "task_1.2": {
    "name": "Run migration to add flavor column",
    "duration_minutes": 3,
    "depends_on": ["task_1.1"]
  },
  "task_2.1": {
    "name": "Create CookieFlavor value object with enum validation",
    "duration_minutes": 8,
    "depends_on": ["task_1.2"]
  }
}
```

### Output: TodoWrite Entries

```javascript
TodoWrite({
  todos: [
    {
      content: "Create migration for flavor column",
      activeForm: "Creating migration for flavor column",
      status: "pending"
    },
    {
      content: "Run migration to add flavor column",
      activeForm: "Running migration to add flavor column",
      status: "pending"
    },
    {
      content: "Create CookieFlavor value object with enum validation",
      activeForm: "Creating CookieFlavor value object",
      status: "pending"
    }
  ]
})
```

## TodoWrite Requirements

### Content Field (Imperative Form)
**Purpose:** Describes what needs to be done

**Format:**
- Starts with verb (Create, Update, Delete, Run, Test, etc.)
- Concise but descriptive
- Max ~60 characters for readability

**Examples:**
```javascript
✅ "Create migration for flavor column"
✅ "Update Cookie entity with flavor property"
✅ "Run PHPStan to verify code quality"

❌ "Creating migration..." (use activeForm for this)
❌ "Migration file creation and validation" (too long)
```

### Active Form (Present Continuous)
**Purpose:** Shows status while task is executing

**Format:**
- Present continuous tense (-ing)
- Same meaning as content, different tense
- Can be slightly shortened

**Examples:**
```javascript
content: "Create migration for flavor column"
activeForm: "Creating migration for flavor column"

content: "Update Cookie entity with flavor property"
activeForm: "Updating Cookie entity"

content: "Run PHPStan to verify code quality"
activeForm: "Running PHPStan verification"
```

### Status Field
**States:**
- `"pending"` - Task not started
- `"in_progress"` - Currently executing
- `"completed"` - Task finished

**Rules:**
- Exactly ONE task should be "in_progress" at a time
- Mark completed IMMEDIATELY after finishing
- Don't batch completions

## Execution Pattern

### Sequential Execution (Default)

```javascript
// Initial state: all pending
TodoWrite({ todos: [
  { content: "Task 1", activeForm: "Doing task 1", status: "pending" },
  { content: "Task 2", activeForm: "Doing task 2", status: "pending" },
  { content: "Task 3", activeForm: "Doing task 3", status: "pending" }
]});

// Start task 1
TodoWrite({ todos: [
  { content: "Task 1", activeForm: "Doing task 1", status: "in_progress" },
  { content: "Task 2", activeForm: "Doing task 2", status: "pending" },
  { content: "Task 3", activeForm: "Doing task 3", status: "pending" }
]});

// ... execute task 1 ...

// Complete task 1, start task 2
TodoWrite({ todos: [
  { content: "Task 1", activeForm: "Doing task 1", status: "completed" },
  { content: "Task 2", activeForm: "Doing task 2", status: "in_progress" },
  { content: "Task 3", activeForm: "Doing task 3", status: "pending" }
]});

// ... and so on ...
```

### Handling Dependencies

Tasks with `depends_on` MUST wait for dependencies to complete:

```javascript
// Task 2.1 depends on task 1.2 completing
{
  "task_1.2": { "depends_on": [] },
  "task_2.1": { "depends_on": ["task_1.2"] }
}

// CORRECT: Wait for 1.2 to complete before starting 2.1
// 1. Execute task 1.2
// 2. Mark 1.2 as completed
// 3. THEN start task 2.1

// INCORRECT: Starting 2.1 while 1.2 is pending
```

### Parallel Tasks (Future Enhancement)

Currently, TodoWrite executes sequentially. Parallel tasks identified by dependency analyzer should still be added sequentially, but user can see parallelization potential:

```markdown
**Parallel Opportunity:** Tasks 2.1, 2.2, 2.3 can run simultaneously
→ Current implementation: Executes sequentially
→ Time saved if parallel: 15 minutes
```

## Error Handling

### Task Fails During Execution

```javascript
// If task 2 fails
TodoWrite({ todos: [
  { content: "Task 1", status: "completed" },
  { content: "Task 2", status: "in_progress" },  // Keep in_progress
  { content: "Task 3", status: "pending" }
]});

// Do NOT mark as completed if:
// - Tests fail
// - PHPStan errors
// - Verification command fails

// Create new task to fix the issue:
TodoWrite({ todos: [
  { content: "Task 1", status: "completed" },
  { content: "Task 2", status: "in_progress" },
  { content: "Fix errors in task 2", status: "pending" },
  { content: "Task 3", status: "pending" }
]});
```

### Discovering New Requirements

If new tasks discovered during execution:

```javascript
// Add new tasks before remaining tasks
TodoWrite({ todos: [
  { content: "Task 1", status: "completed" },
  { content: "Task 2", status: "completed" },
  { content: "NEW: Fix validation issue", status: "pending" },
  { content: "NEW: Add tests for edge case", status: "pending" },
  { content: "Task 3", status: "pending" }
]});
```

## Best Practices

### 1. Clear Task Names
```javascript
✅ "Create CookieFlavor value object with enum validation"
❌ "Update files"
```

### 2. Binary Completion
```javascript
✅ Mark completed when: File created AND passes PHPStan AND verification succeeds
❌ Mark completed when: File created (but has errors)
```

### 3. One In-Progress Task
```javascript
✅ Exactly one task with status "in_progress"
❌ Multiple tasks "in_progress" simultaneously
```

### 4. Immediate Completion
```javascript
✅ Complete task → TodoWrite update → Start next task
❌ Complete 3 tasks → Batch TodoWrite update
```

## Complete Example

See `examples/todowrite-execution-flow.md` for complete example showing:
1. Initial TodoWrite creation (all pending)
2. Sequential execution with status updates
3. Error handling mid-execution
4. Adding newly discovered tasks
5. Final completion
