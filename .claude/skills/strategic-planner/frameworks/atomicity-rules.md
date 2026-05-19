# Atomicity Rules - The Five Laws

Tasks must be **atomic** - the smallest independently executable and verifiable units.

## Rule 1: ≤3 Files Modified (Ideally 1)

**Rationale:** Large file changes are hard to verify and prone to partial completion.

**Examples:**
```
❌ Violation: "Update 15 files to add new parameter"
✅ Compliant: "Update CreateCookieCommand.php to add flavor parameter"
✅ Acceptable: "Update Cookie entity, migration, and seeder (3 files)"
```

**If you need more than 3 files:**
- Split into multiple tasks
- Group by logical responsibility

## Rule 2: <30 Minutes Duration (Ideally <10)

**Rationale:** Long tasks lose atomicity benefits and risk partial completion.

**Examples:**
```
❌ Violation: "Implement entire payment system (2 hours)"
✅ Compliant: "Create StripePaymentCommand (5 minutes)"
✅ Compliant: "Create StripePaymentHandler (8 minutes)"
```

**Duration guidelines:**
- File creation: 3-5 minutes
- Test creation: 5-8 minutes
- Migration: 3-5 minutes
- Complex handler: 10-15 minutes

## Rule 3: Binary Done State

**Rationale:** "80% done" is impossible to track. Tasks are done OR not done.

**Examples:**
```
❌ Violation: "Partially implement feature (completed 3 of 5 methods)"
✅ Compliant: "Implement getById method" (either done or not)
✅ Compliant: "Implement delete method" (either done or not)
```

**How to achieve:**
- Tasks so small they can't be "partially done"
- Clear definition of done
- Single completion state

## Rule 4: Independently Verifiable

**Rationale:** Task verification shouldn't depend on other tasks completing.

**Examples:**
```
❌ Violation: "Update handler (can only verify after tests written)"
✅ Compliant: "Update handler" → Verify: "PHPStan passes on this file"
✅ Compliant: "Write handler tests" → Verify: "Tests run and pass"
```

**Verification strategies:**
- Static analysis (PHPStan, PHPCS)
- Unit tests (if task creates tests)
- Dry-run commands
- File existence checks

## Rule 5: Single Responsibility

**Rationale:** Tasks doing multiple things lose atomicity and increase failure risk.

**Examples:**
```
❌ Violation: "Create migration, run it, and update entity"
✅ Compliant: "Create migration for flavor column"
✅ Compliant: "Run migration to add flavor column"
✅ Compliant: "Update Cookie entity with flavor property"
```

**Single Responsibility Principle for Tasks:**
- Each task does ONE thing
- If description has "and", split it
- Clear single purpose

## Validation Script

The atomicity validator (`2_atomicity_validator.py`) checks all 5 rules automatically:

```python
# Example validation output
{
  "task_id": "task_1.1",
  "rule_1_files": "PASS (1 file)",
  "rule_2_duration": "PASS (5 minutes)",
  "rule_3_binary_state": "PASS (clear done state)",
  "rule_4_independent": "PASS (can verify alone)",
  "rule_5_single_responsibility": "PASS (creates migration only)"
}
```

## Common Violations & Fixes

### Violation: "Update all command handlers"
**Problem:** Too many files, no single responsibility
**Fix:** Split into one task per handler

### Violation: "Implement feature with tests"
**Problem:** Multiple responsibilities
**Fix:** Task 1 - Implement feature, Task 2 - Write tests

### Violation: "Research and implement solution"
**Problem:** Not achievable (research creates blockers)
**Fix:** Task 1 - Research options, Task 2 - Implement chosen solution

### Violation: "Update files (should take 45 minutes)"
**Problem:** Exceeds time limit
**Fix:** Split by logical groups, each <30 minutes
