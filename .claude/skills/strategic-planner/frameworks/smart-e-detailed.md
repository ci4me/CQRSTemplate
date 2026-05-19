# SMART-E Framework - Detailed Guide

The SMART-E framework extends traditional SMART goals for software task planning.

## The Six Criteria

### S - Specific
**Definition:** Task scope is precisely defined with no ambiguity.

**Requirements:**
- Exact files to modify (with paths)
- Exact changes to make
- Clear boundaries (what's in scope, what's out)

**Example:**
```
❌ Bad: "Update Cookie entity"
✅ Good: "Add flavor property to Cookie entity in app/Domain/Cookie/Entities/Cookie.php"
```

### M - Measurable
**Definition:** Success can be quantified with pass/fail criteria.

**Requirements:**
- Quantifiable success criteria
- Pass/fail determination method
- Expected output defined

**Example:**
```
❌ Bad: "Make sure it works"
✅ Good: "File exists: ✅/❌, PHPStan passes: ✅/❌, Test coverage >90%: ✅/❌"
```

### A - Achievable
**Definition:** No blockers prevent starting the task.

**Requirements:**
- No blockers identified
- Dependencies met OR identified
- Resources available

**Example:**
```
❌ Bad: "Add feature (need to research API first)"
✅ Good: "Blockers: None | Prerequisites: Composer installed | Resources: php-specialist"
```

### R - Relevant
**Definition:** Task contributes to parent goal.

**Requirements:**
- Clear contribution to parent goal
- Enables other tasks (or is standalone)
- Purpose stated explicitly

**Example:**
```
❌ Bad: Task with no stated purpose
✅ Good: "Contributes to: Enable flavor property storage | Enables: All code using Cookie.flavor"
```

### T - Testable
**Definition:** Outcome can be verified independently.

**Requirements:**
- Verification command provided
- Expected output specified
- Failure indicators listed

**Example:**
```
❌ Bad: "Test manually"
✅ Good: "Command: php spark migrate --dry-run | Expected: 'Migration can run' | Fails if: syntax error"
```

### E - Executable
**Definition:** Task can start immediately OR after specific dependencies.

**Requirements:**
- Can start NOW or after known deps
- All decisions already made
- Clear execution path

**Example:**
```
❌ Bad: "Start when ready (need to decide approach)"
✅ Good: "Can start: After task_1.1 completes | Decisions made: Yes (using Value Object) | Path: Write tool + ddd-specialist"
```

## Validation Checklist

For each task, verify:
- [ ] **S**: Files and changes specified?
- [ ] **M**: Success criteria quantified?
- [ ] **A**: No blockers remaining?
- [ ] **R**: Purpose and contribution stated?
- [ ] **T**: Verification command provided?
- [ ] **E**: Can start immediately or after deps?

**If any ❌, the task is NOT ready for execution.**
