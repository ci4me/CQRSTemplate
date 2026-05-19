# Orchestration Patterns - Task-to-Agent Mapping

Complete guide for mapping atomic tasks to specialist agents for parallel execution.

---

## Discovering Available Agents and Skills

**Before mapping tasks, discover what resources are available:**

### Discover Agents

```bash
ls -1 .claude/agents/*.md
```

**For each agent file, check:**
1. Agent name (from filename)
2. Description (from file header)
3. Tools available (from file content)
4. Specialty/responsibility (from file content)

**Agent information is in:** `.claude/agents/{agent-name}.md`

**Example agents that may be available:**
- php-specialist, ddd-specialist, cqrs-specialist, clean-code-specialist
- test-specialist, phpstan-specialist, slevomat-specialist
- codeigniter4-specialist, claude-code-specialist
- **Note:** This list changes as new agents are added to the project

### Discover Skills

```bash
ls -1 .claude/skills/*/SKILL.md
```

**For each skill file, check:**
1. Skill name (from YAML frontmatter)
2. Description (from YAML frontmatter)
3. When to use (from file content)
4. Multi-step workflows available

**Skill information is in:** `.claude/skills/{skill-name}/SKILL.md`

**Example skills that may be available:**
- strategic-planner, business-rule-addition, code-review
- domain-scaffolding, property-addition
- **Note:** This list changes as new skills are added to the project

### When to Use Skills vs Agents

**Use Skills when:**
- Task requires complex multi-step workflow
- Task involves coordinating multiple specialists
- Task follows a repeatable pattern (e.g., adding property, creating domain)
- Task benefits from domain-specific guidance

**Use Agents when:**
- Task is atomic and focused (single file, single responsibility)
- Task requires specific technical expertise (PHP, DDD, testing)
- Task is part of larger skill execution

**Use Both when:**
- Complex task needs both orchestration (skill) and specialized execution (agents)
- Example: strategic-planner skill delegates to php-specialist, ddd-specialist agents

---

## Task Type Mapping

### 1. Create PHP Template Files

**Task:** "Create value object template"

**Primary Agent:** `php-specialist`
**Why:** PHP 8.4 syntax, readonly properties, type declarations

**Prompt Pattern:**
```
Create a value object template at {path} following these requirements:
- PHP 8.4 syntax with declare(strict_types=1)
- Readonly class with private constructor
- Static factory method (fromString/fromFloat/etc.)
- getValue() and equals() methods
- Validation in constructor
- Reference: app/Domain/Cookie/ValueObjects/CookieName.php
```

---

### 2. Extract Domain Patterns

**Task:** "Extract placement decision tree to patterns/"

**Primary Agent:** `ddd-specialist`
**Why:** Domain-Driven Design patterns and placement decisions

**Prompt Pattern:**
```
Extract the business rule placement decision tree from SKILL.md and create a comprehensive guide at {path}:
- Decision tree flowchart
- Examples for each placement (Value Object, Entity, Handler)
- Common mistakes and fixes
- Quick reference table
```

---

### 3. Create Checklists

**Task:** "Create domain layer checklist"

**Primary Agent:** `clean-code-specialist`
**Why:** Code quality checks and best practices

**Prompt Pattern:**
```
Create a domain layer checklist at {path} covering:
- Value objects are immutable
- Entities use factory methods
- Business logic in domain, not controllers
- Ubiquitous language used
- Invariants protected
```

---

### 4. Refactor SKILL.md Files

**Task:** "Rewrite code-review SKILL.md to be lightweight"

**Primary Agent:** `clean-code-specialist`
**Why:** Structure, organization, progressive disclosure

**Prompt Pattern:**
```
Refactor {path} to be lightweight (~150 lines):
- Keep YAML frontmatter and core workflow
- Replace embedded examples with references to supporting files
- Add "Supporting Documentation" section with links
- Target: ≤160 lines while maintaining clarity
```

---

### 5. Create Test Files

**Task:** "Create comprehensive test suite for business rule"

**Primary Agent:** `test-specialist`
**Why:** Test pyramid, coverage, test quality

**Prompt Pattern:**
```
Create test file at {path} covering:
- Happy path (rule passes)
- Each violation condition
- Boundary values (min, max, zero)
- Edge cases (null, empty, extreme)
- Target: 90%+ coverage for the business rule
```

---

### 6. Create Migration Files

**Task:** "Create migration for new column"

**Primary Agent:** `codeigniter4-specialist`
**Why:** CodeIgniter 4 migration patterns, spark commands

**Prompt Pattern:**
```
Create migration at {path}:
- up() method: add column with proper type and constraints
- down() method: drop column
- Follow CodeIgniter 4 migration conventions
- Test with: php spark migrate --dry-run
```

---

## Multi-Agent Patterns

### Pattern 1: Primary + Review

**Use When:** Creating complex code that needs quality review

```javascript
// Step 1: Primary agent creates the file
Task({
  subagent_type: "ddd-specialist",
  description: "Create Cookie entity",
  prompt: "Create Cookie entity with DDD patterns..."
})

// Step 2: Review agent checks quality (sequential)
Task({
  subagent_type: "clean-code-specialist",
  description: "Review Cookie entity",
  prompt: "Review Cookie entity for SOLID principles, method length..."
})
```

### Pattern 2: Parallel Independent Tasks

**Use When:** Multiple tasks with no dependencies

```javascript
// Launch all in SINGLE message for true parallelism
Task({ subagent_type: "php-specialist", description: "Create template 1", prompt: "..." })
Task({ subagent_type: "php-specialist", description: "Create template 2", prompt: "..." })
Task({ subagent_type: "ddd-specialist", description: "Extract pattern", prompt: "..." })
Task({ subagent_type: "clean-code-specialist", description: "Create checklist", prompt: "..." })
// Continue for all parallel tasks
```

### Pattern 3: Sequential Groups

**Use When:** Tasks have dependencies between groups

```javascript
// Group 1: Extract content (parallel)
Task({ ... })  // Extract example 1
Task({ ... })  // Extract example 2
Task({ ... })  // Extract pattern

// Wait for Group 1 to complete...

// Group 2: Rewrite cores (parallel, depends on Group 1)
Task({ ... })  // Rewrite SKILL 1
Task({ ... })  // Rewrite SKILL 2
```

---

## Decision Flowchart

```
Task Type?
  |
  ├─ Create PHP file → php-specialist
  |
  ├─ DDD pattern/entity → ddd-specialist
  |
  ├─ CQRS component → cqrs-specialist
  |
  ├─ Code refactoring → clean-code-specialist
  |
  ├─ Tests → test-specialist
  |
  ├─ Quality check → phpstan-specialist or slevomat-specialist
  |
  └─ Migration → codeigniter4-specialist
```

---

## Prompt Template Structure

**Effective agent prompts follow this structure:**

```
1. **Context:** What we're building and why
2. **Task:** Specific file to create/modify
3. **Requirements:** Detailed specifications
4. **Reference:** Existing file to follow as example
5. **Validation:** How to verify success
6. **Dependencies:** What must exist first
```

**Example:**
```
Context: We're refactoring skills to follow Claude Code best practices with progressive disclosure.

Task: Create a placement decision tree guide at .claude/skills/business-rule-addition/patterns/placement-decision-tree.md

Requirements:
- Decision flowchart (Value Object vs Entity vs Handler)
- 3 detailed examples with code
- Common mistakes section
- Quick reference table

Reference: Similar patterns in strategic-planner/frameworks/ directory

Validation: File exists, comprehensive, follows markdown structure

Dependencies: None (can start immediately)
```

---

## Task Batching Strategy

### Small Batches (Recommended)

**Advantages:**
- Clear progress tracking
- Easy to debug failures
- Better TodoWrite updates

**Pattern:** 3-5 agents per message

```javascript
// Batch 1: Templates (5 agents)
Task({ ... })
Task({ ... })
Task({ ... })
Task({ ... })
Task({ ... })

// Batch 2: Checklists (3 agents)
Task({ ... })
Task({ ... })
Task({ ... })
```

### Large Batches (Advanced)

**Use When:** Very high confidence, simple tasks

**Pattern:** 10+ agents per message

---

## Error Handling

**If agent fails:**
1. Check agent output for error message
2. Fix underlying issue (missing file, wrong path)
3. Re-launch ONLY the failed agent
4. Update TodoWrite to reflect retry

**Common failures:**
- Missing reference file → Create reference first
- Wrong path → Verify directory exists
- Circular dependencies → Check task ordering

---

**See Also:**
- `agent-delegation-guide.md` - Detailed Task tool patterns
- `parallel-execution-examples.md` - Real execution scenarios
- Main SKILL.md Phase 6 - Complete orchestration workflow
