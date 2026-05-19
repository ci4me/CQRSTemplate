# Agent Delegation Guide - Task Tool Usage Patterns

Practical guide for using the Task tool to delegate work to specialist agents.

---

## Task Tool Syntax

```javascript
Task({
  subagent_type: "agent-name",           // Required: Which specialist to use
  description: "Short task description", // Required: 3-5 word summary
  prompt: "Detailed instructions..."     // Required: Full task specification
})
```

---

## Parallel Execution Pattern

**CRITICAL:** Launch multiple agents in a SINGLE message for true parallelism.

### ✅ Correct: Parallel Execution

```javascript
// ONE message with multiple Task calls
Task({ subagent_type: "php-specialist", description: "Create template 1", prompt: "..." })
Task({ subagent_type: "php-specialist", description: "Create template 2", prompt: "..." })
Task({ subagent_type: "ddd-specialist", description: "Extract pattern", prompt: "..." })

// All 3 agents launch simultaneously
```

### ❌ Wrong: Sequential Execution

```javascript
// Message 1
Task({ subagent_type: "php-specialist", ... })

// Message 2 (waits for Message 1)
Task({ subagent_type: "php-specialist", ... })

// Message 3 (waits for Message 2)
Task({ subagent_type: "ddd-specialist", ... })

// Agents run one at a time = NO parallelization
```

---

## Complete Orchestration Example

### Scenario: Refactor 3 Skills

**From dependency analysis:**
- Group 1: 9 parallel tasks (extraction)
- Group 2: 3 parallel tasks (rewrites, depends on Group 1)

### Step 1: Create TodoWrite Entries

```javascript
TodoWrite({
  todos: [
    // Group 1 tasks
    { content: "Extract complex example from skill A", status: "pending" },
    { content: "Extract patterns from skill A", status: "pending" },
    { content: "Create checklist 1 for skill B", status: "pending" },
    { content: "Create checklist 2 for skill B", status: "pending" },
    { content: "Create checklist 3 for skill B", status: "pending" },
    { content: "Create template 1 for skill C", status: "pending" },
    { content: "Create template 2 for skill C", status: "pending" },
    { content: "Create template 3 for skill C", status: "pending" },
    { content: "Create example for skill C", status: "pending" },

    // Group 2 tasks (depends on Group 1)
    { content: "Rewrite skill A SKILL.md", status: "pending" },
    { content: "Rewrite skill B SKILL.md", status: "pending" },
    { content: "Rewrite skill C SKILL.md", status: "pending" }
  ]
})
```

### Step 2: Launch Group 1 (9 agents in parallel)

```javascript
// ONE message with 9 Task calls
Task({
  subagent_type: "ddd-specialist",
  description: "Extract complex example",
  prompt: `Extract the complex business rule example from .claude/skills/business-rule-addition/SKILL.md (lines 188-223) to examples/complex-rule-example.md.

Include:
- Full implementation code
- Test suite
- Decision rationale
- Key takeaways

Reference strategic-planner/examples/ for structure.`
})

Task({
  subagent_type: "ddd-specialist",
  description: "Extract placement patterns",
  prompt: `Extract the placement decision tree from .claude/skills/business-rule-addition/SKILL.md (lines 26-38) to patterns/placement-decision-tree.md.

Create comprehensive guide with:
- Decision flowchart
- 3 detailed examples
- Common mistakes
- Quick reference table

Reference strategic-planner/frameworks/ for structure.`
})

Task({
  subagent_type: "clean-code-specialist",
  description: "Create checklist 1",
  prompt: `Create domain layer checklist at .claude/skills/code-review/checklists/domain-layer.md.

Cover:
- Value objects immutable
- Entities use factories
- Business logic in domain
- Ubiquitous language
- Invariants protected`
})

Task({
  subagent_type: "clean-code-specialist",
  description: "Create checklist 2",
  prompt: `Create CQRS layer checklist at .claude/skills/code-review/checklists/cqrs-layer.md.

Cover:
- Commands immutable readonly
- Queries immutable readonly
- Events past tense
- One handler per command
- Handlers delegate to entities`
})

Task({
  subagent_type: "clean-code-specialist",
  description: "Create checklist 3",
  prompt: `Create code quality checklist at .claude/skills/code-review/checklists/code-quality.md.

Cover:
- Methods ≤20 lines
- No else after return
- Early returns used
- No duplicate code (DRY)
- Magic numbers extracted`
})

Task({
  subagent_type: "php-specialist",
  description: "Create template 1",
  prompt: `Create value object template at .claude/skills/domain-scaffolding/templates/value-object-template.php.

Requirements:
- PHP 8.4 with declare(strict_types=1)
- Readonly class
- Private constructor with validation
- Static factory method
- getValue() and equals() methods

Reference: app/Domain/Cookie/ValueObjects/CookieName.php`
})

Task({
  subagent_type: "php-specialist",
  description: "Create template 2",
  prompt: `Create entity template at .claude/skills/domain-scaffolding/templates/entity-template.php.

Requirements:
- PHP 8.4 with declare(strict_types=1)
- Final class
- Private constructor
- Static create() and reconstitute() methods
- Getters for all properties
- Business methods (not setters)

Reference: app/Domain/Cookie/Entities/Cookie.php`
})

Task({
  subagent_type: "php-specialist",
  description: "Create template 3",
  prompt: `Create command template at .claude/skills/domain-scaffolding/templates/command-template.php.

Requirements:
- PHP 8.4 with declare(strict_types=1)
- Readonly class
- Public readonly properties
- No methods (pure DTO)

Reference: app/Domain/Cookie/Commands/CreateCookie/CreateCookieCommand.php`
})

Task({
  subagent_type: "ddd-specialist",
  description: "Create walkthrough example",
  prompt: `Create complete domain walkthrough at .claude/skills/domain-scaffolding/examples/complete-domain-walkthrough.md.

Use Cookie domain as reference to document creating all 45 files:
- Directory structure
- Value objects
- Entity
- Commands and handlers
- Queries and handlers
- Events and handlers
- Repository
- Controller
- Views
- Tests

Step-by-step with code examples.`
})

// End of Group 1 - all 9 agents launch simultaneously
```

### Step 3: Wait for Group 1 to Complete

**Monitor TodoWrite:** All Group 1 tasks should show "completed"

### Step 4: Launch Group 2 (3 agents in parallel)

```javascript
// ONE message with 3 Task calls (after Group 1 done)
Task({
  subagent_type: "clean-code-specialist",
  description: "Rewrite skill A",
  prompt: `Refactor .claude/skills/business-rule-addition/SKILL.md to be lightweight (~150 lines).

Changes:
- Keep YAML frontmatter and core workflow
- Replace embedded examples with: See examples/complex-rule-example.md
- Replace embedded patterns with: See patterns/placement-decision-tree.md
- Add Supporting Documentation section
- Target: ≤160 lines

The supporting files already exist (created by Group 1).`
})

Task({
  subagent_type: "clean-code-specialist",
  description: "Rewrite skill B",
  prompt: `Refactor .claude/skills/code-review/SKILL.md to be lightweight (~120 lines).

Changes:
- Keep YAML frontmatter and core workflow
- Replace embedded checklists with: See checklists/*.md
- Add Supporting Documentation section
- Target: ≤130 lines

The checklist files already exist (created by Group 1).`
})

Task({
  subagent_type: "clean-code-specialist",
  description: "Rewrite skill C",
  prompt: `Refactor .claude/skills/domain-scaffolding/SKILL.md to be lightweight (~150 lines).

Changes:
- Keep YAML frontmatter and core workflow
- Reference templates: See templates/*.php
- Reference example: See examples/complete-domain-walkthrough.md
- Target: ≤160 lines

The template and example files already exist (created by Group 1).`
})

// End of Group 2 - all 3 agents launch after Group 1 completes
```

### Step 5: Final TodoWrite Update

```javascript
TodoWrite({
  todos: [
    // All tasks marked completed
    { content: "Extract complex example from skill A", status: "completed" },
    { content: "Extract patterns from skill A", status: "completed" },
    // ... all 12 tasks as completed
  ]
})
```

---

## Prompt Best Practices

### 1. Be Specific About File Paths

```javascript
✅ Good: prompt: "Create file at .claude/skills/business-rule-addition/examples/complex-rule-example.md"
❌ Bad:  prompt: "Create example file in examples folder"
```

### 2. Provide Reference Files

```javascript
✅ Good: prompt: "Follow structure from app/Domain/Cookie/ValueObjects/CookieName.php"
❌ Bad:  prompt: "Create a value object"
```

### 3. Define Success Criteria

```javascript
✅ Good: prompt: "Target: ≤160 lines, references to supporting files, clear workflow"
❌ Bad:  prompt: "Make it shorter"
```

### 4. List Dependencies Explicitly

```javascript
✅ Good: prompt: "The supporting files already exist (created by Group 1): examples/complex-rule-example.md and patterns/placement-decision-tree.md"
❌ Bad:  prompt: "Reference the examples"
```

---

## Error Recovery

### If Agent Reports Missing File

```javascript
// Agent fails: "Cannot find reference file Cookie.php"

// Fix: Verify path is correct
Task({
  subagent_type: "php-specialist",
  description: "Retry template creation",
  prompt: "... Reference: app/Domain/Cookie/Entities/Cookie.php (note: correct path is Entities/ not Entity/)"
})
```

### If Agent Produces Wrong Output

```javascript
// Agent created file but wrong structure

// Fix: Be more specific in prompt
Task({
  subagent_type: "ddd-specialist",
  description: "Recreate with correct structure",
  prompt: `Recreate .claude/skills/business-rule-addition/patterns/placement-decision-tree.md with this EXACT structure:

1. Decision Tree ASCII diagram
2. Three sections: Value Object, Entity Method, Handler
3. Code examples for each (not pseudocode, real PHP)
4. Common Mistakes section with ✅/❌ examples
5. Quick Reference table

See strategic-planner/frameworks/smart-e-detailed.md for structure example.`
})
```

---

## Performance Tips

### 1. Batch Similar Tasks

```javascript
// Good: Same agent type together
Task({ subagent_type: "php-specialist", ... })  // Template 1
Task({ subagent_type: "php-specialist", ... })  // Template 2
Task({ subagent_type: "php-specialist", ... })  // Template 3
```

### 2. Respect Dependencies

```javascript
// Don't launch Group 2 before Group 1 completes
// Group 2 tasks reference files created by Group 1
```

### 3. Limit Batch Size

```javascript
// Recommended: 5-10 agents per message
// Too many (>15) may cause coordination issues
```

---

## Coordination Checklist

Before launching agents:
- [ ] TodoWrite entries created for all tasks
- [ ] Dependencies identified (which tasks depend on others)
- [ ] Tasks grouped by dependency level
- [ ] Prompts include specific file paths
- [ ] Prompts include reference files
- [ ] Prompts include success criteria
- [ ] All tasks in a group launched in SINGLE message

After agents complete:
- [ ] TodoWrite updated to reflect completion
- [ ] Output files verified (exist and have correct content)
- [ ] Next group can start (all dependencies met)

---

**See Also:**
- `orchestration-patterns.md` - Task-to-agent mapping
- `parallel-execution-examples.md` - Complete scenarios
- Main SKILL.md Phase 6 - Orchestration workflow
