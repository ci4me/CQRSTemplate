# Tree-of-Thought (ToT) - Exploration Patterns

Tree-of-Thought systematically explores multiple solution approaches before committing to one.

## When to Use ToT

**Use ToT when:**
- Multiple valid approaches exist
- Trade-offs between options
- High-risk decisions
- Complexity level: COMPLEX or VERY COMPLEX
- Significant architectural choice

**Skip ToT when:**
- Single obvious approach
- Complexity level: TRIVIAL or SIMPLE
- Pattern exists in library
- Time-sensitive simple task

## ToT Structure

```markdown
# 🌳 Exploring Solution Approaches

## Branch A: [Approach Name]
**Approach:** [Brief description]

**Analyzing pros/cons:**
✅ [Advantage 1]
✅ [Advantage 2]
❌ [Disadvantage 1]
❌ [Disadvantage 2]

**Checking compatibility:**
- [Requirement 1]: ✅/❌
- [Requirement 2]: ✅/❌

**Estimated complexity:** [LEVEL]
**Risk assessment:** [LOW/MEDIUM/HIGH/CRITICAL]

---

## Branch B: [Alternative Approach]
[Same structure as Branch A]

---

## Branch C: [Another Alternative]
[Same structure]

→ **ELIMINATED** (reason if eliminated)

---

## 🎯 Branch Selection

**Decision Matrix:**
| Criterion | Branch A | Branch B | Branch C |
|-----------|----------|----------|----------|
| [Criterion 1] | ✅ | ⚠️ | ❌ |
| [Criterion 2] | ✅ | ✅ | ❌ |

**SELECTED: Branch A**

**Reasoning:**
- [Key reason 1]
- [Key reason 2]

**Trade-offs accepted:**
- [Trade-off 1] acceptable because [reason]
- [Trade-off 2] mitigated by [approach]
```

## Real Example: Payment Provider Selection

See `examples/tot-payment-provider.md` for complete example.

### Branch A: Stripe Integration
**Pros:** PCI compliant, well-documented, handles complexity
**Cons:** External dependency, monthly fees, vendor lock-in
**Result:** SELECTED

### Branch B: PayPal Integration
**Pros:** Popular, trusted, no card storage
**Cons:** Complex API, poor UX (redirects), webhook complexity
**Result:** Viable alternative

### Branch C: Custom Processing
**Pros:** Full control
**Cons:** PCI Level 1 certification required, massive security risk, legal liability
**Result:** ELIMINATED

## Decision Matrix Tips

**Common criteria:**
- Security
- Complexity
- Cost
- UX/Developer experience
- Legal/Compliance
- Performance
- Maintainability
- Community support

**Weighting:**
- Mark critical criteria (security, compliance)
- Eliminating factors (deal-breakers)
- Nice-to-haves

## Pruning Branches Early

**Eliminate branches when:**
- Critical requirement not met
- Unacceptable risk level
- Incompatible with constraints
- Clearly inferior to alternatives

**Example:**
```
Branch C: ELIMINATED (security risk unacceptable)
→ No need to fully analyze if deal-breaker found early
```

## Recording Decisions

**Save decisions to decision logs:**
```json
{
  "timestamp": "2025-10-22T14:30:00Z",
  "decision": "payment_provider_selection",
  "branches_explored": ["stripe", "paypal", "custom"],
  "selected": "stripe",
  "reasoning": "Best security + UX balance",
  "trade_offs_accepted": [
    "Monthly fees acceptable for security benefits"
  ],
  "branches_eliminated": {
    "custom": "PCI compliance too risky"
  }
}
```

**Location:** `.claude/skills/strategic-planner/decision-logs/[feature]-[timestamp].json`

## Mini-ToT for Smaller Decisions

For MODERATE complexity, use abbreviated ToT:

```markdown
# Quick exploration: Value Object vs Simple String

**Option A: Value Object**
✅ Type safety, validation
❌ More code

**Option B: Simple String**
✅ Simpler
❌ No validation, typos possible

→ Selected A (data quality > simplicity)
```

## ToT + Pre-Mortem Integration

After selecting branch:
1. Run Pre-Mortem on selected approach
2. Identify risks specific to that choice
3. Add mitigation tasks to plan

Example:
```markdown
Selected: Stripe
Pre-Mortem: "Stripe integration failed. What went wrong?"
→ Forgot webhook signature verification
→ Add task: Implement webhook security (CRITICAL)
```
