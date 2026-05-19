# Chain-of-Thought (CoT) - Reasoning Templates

Chain-of-Thought makes reasoning transparent by showing step-by-step thinking process.

## Core Principle

**"Think out loud"** - Show your reasoning, not just conclusions.

## CoT Structure

```markdown
# 💭 Understanding the Request

User said: "[original request]"

Let me break down what this means:
- [Interpretation 1]
- [Interpretation 2]
- [Questions raised]

**Complexity Assessment:** [LEVEL]
- [Reason 1]
- [Reason 2]

**Risk Level:** [LOW/MEDIUM/HIGH/CRITICAL]
- [Risk factor 1]
- [Risk factor 2]

→ **Decision:** [What planning approach to use]
```

## Template 1: Feature Addition

```markdown
# 💭 Understanding: Add [Feature]

User said: "Add [feature] to [domain]"

**What this involves:**
- [Component 1] needs updating
- [Component 2] must be created
- [Component 3] may be affected

**Asking myself questions:**
Q: Does similar feature exist in codebase?
A: Yes, [similar feature] in [location]

Q: What's the simplest approach?
A: [Approach description]

Q: What could go wrong?
A: [Risk 1], [Risk 2]

**Assumptions made:**
- [Assumption 1]
- [Assumption 2]

**Complexity:** [LEVEL]
→ Using [planning approach]
```

## Template 2: Bug Fix

```markdown
# 💭 Understanding: Fix [Bug]

User said: "[bug description]"

**Symptoms:**
- [What's broken]
- [When it happens]
- [Impact]

**Hypotheses:**
1. [Possible cause 1] - [Likelihood]
2. [Possible cause 2] - [Likelihood]

**Investigation needed:**
- [File/component to check 1]
- [File/component to check 2]

**Complexity:** [LEVEL]
→ [Planning approach]
```

## Template 3: Refactoring

```markdown
# 💭 Understanding: Refactor [Code]

User said: "Refactor [component]"

**Current state:**
- [Problem 1]
- [Problem 2]

**Desired state:**
- [Goal 1]
- [Goal 2]

**Considering approaches:**
- Incremental refactor: [pros/cons]
- Big bang refactor: [pros/cons]

**Testing strategy:**
- [How to verify nothing breaks]

**Risk assessment:**
- [Risk 1]: [Mitigation]
- [Risk 2]: [Mitigation]

**Complexity:** [LEVEL]
→ [Planning approach]
```

## Complexity Assessment Guide

```markdown
**Complexity Assessment:** [LEVEL]

Evaluating:
- Files affected: [count] → [LEVEL impact]
- Dependencies: [simple/complex] → [LEVEL impact]
- Risk: [LOW/MEDIUM/HIGH/CRITICAL] → [LEVEL impact]
- Testing needs: [simple/extensive] → [LEVEL impact]
- Unknowns: [count] → [LEVEL impact]

**Overall:** [LEVEL]
```

### Complexity Levels

**TRIVIAL:**
- 1-2 files
- No dependencies
- <5 minutes
- Example: "Add docblock to method"

**SIMPLE:**
- 3-5 files
- Simple dependencies
- <15 minutes
- Example: "Add new column to table"

**MODERATE:**
- 6-15 files
- Some parallelization possible
- <45 minutes
- Example: "Add property to entity"

**COMPLEX:**
- 16-30 files
- High parallelization needed
- <2 hours
- Example: "Add new feature with full CRUD"

**VERY COMPLEX:**
- 30+ files
- Critical path analysis essential
- 2+ hours
- Example: "Integrate payment system"

## Risk Assessment Guide

```markdown
**Risk Level:** [LEVEL]

Risk factors:
- Security implications: [YES/NO] → [impact]
- Data migration: [YES/NO] → [impact]
- External dependencies: [YES/NO] → [impact]
- Breaking changes: [YES/NO] → [impact]
- Financial impact: [YES/NO] → [impact]

**Overall risk:** [LOW/MEDIUM/HIGH/CRITICAL]
```

### Risk Levels

**LOW:**
- No security implications
- No data changes
- Easy rollback
- Example: "Add validation to input"

**MEDIUM:**
- Minor data changes
- Database migration involved
- Moderate rollback complexity
- Example: "Add new table"

**HIGH:**
- Security-sensitive
- Complex data migration
- Hard to rollback
- Example: "Change authentication"

**CRITICAL:**
- Financial transactions
- PCI compliance
- Data loss risk
- Example: "Implement payments"

## Combining with ToT

For COMPLEX tasks:
1. **CoT:** Understand request, assess complexity/risk
2. **ToT:** Explore 2-3 solution approaches
3. **CoT:** Explain why selected approach chosen
4. **Pre-Mortem:** (if high risk) Identify failure modes
5. **Task Generation:** Create SMART-E atomic tasks

## Example: Complete CoT + ToT Flow

See `examples/cot-tot-complete-flow.md` for full example showing:
1. Initial CoT understanding
2. ToT exploration of 3 approaches
3. CoT reasoning for selection
4. Risk assessment
5. Task generation
