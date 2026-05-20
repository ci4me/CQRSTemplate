# Complex Planning Example: Payment System Integration

**Complexity:** VERY COMPLEX
**Duration:** ~85 minutes (sequential) → 42 minutes (parallel)
**Risk:** HIGH

## User Request

> "Add payment system to process cookie orders"

## Phase 1: Understanding (Chain-of-Thought)

```markdown
# 💭 Understanding the Request

User wants to accept payments for cookie orders.

**What this involves:**
- Payment gateway integration (which provider?)
- Storing payment records
- Security (PCI compliance)
- Refunds? Subscriptions? One-time only?
- Failed payment handling

**Complexity Assessment:** VERY COMPLEX
- External dependencies (payment provider)
- Security critical (financial data)
- Multiple domains affected (Order, Payment, User)
- 30+ files estimated

**Risk Level:** HIGH
- Financial transactions
- PCI compliance required
- Data security critical
- Legal implications

→ **Decision:** Use FULL strategic planning (ToT + CoT + Pre-Mortem + Python)
```

## Phase 2: Tree-of-Thought Exploration

```markdown
# 🌳 Exploring Solution Approaches

## Branch A: Stripe Integration
**Approach:** Use Stripe SDK, delegate card processing to Stripe

**Analyzing pros/cons:**
✅ PCI compliant (Stripe handles cards, we never see them)
✅ Well-documented PHP SDK
✅ Handles complex scenarios (refunds, disputes, webhooks)
✅ Battle-tested by millions of businesses
✅ Excellent error handling
❌ External dependency (Stripe downtime affects us)
❌ Monthly fees (2.9% + 30¢ per transaction)
❌ Vendor lock-in (hard to switch later)

**Checking compatibility:**
- PHP 8.3+: ✅ Compatible
- CodeIgniter 4: ✅ Framework-agnostic (we'll wrap in our CQRS pattern)
- CQRS pattern: ✅ Easy to wrap (ProcessStripePaymentCommand)

**Estimated complexity:** MEDIUM
- Integration: 20 files
- Webhook handling: 10 files
- Error handling: 5 files
**Total: ~35 files**

**Risk assessment:** LOW
- PCI handled by Stripe
- Well-documented
- Community support available

---

## Branch B: PayPal Integration
**Approach:** PayPal REST API for payment processing

**Analyzing pros/cons:**
✅ Popular and trusted brand
✅ No card storage (PayPal handles)
✅ Buyer protection built-in
⚠️ More complex API (compared to Stripe)
❌ User redirects (poor UX - leaves our site)
❌ Webhook handling more complex
❌ PHP SDK less elegant

**Checking compatibility:**
- PHP 8.3+: ✅ Compatible
- CodeIgniter 4: ✅ Works
- CQRS pattern: ⚠️ Redirect flow complicates command pattern

**Estimated complexity:** HIGH
- Integration: 25 files
- Redirect handling: 15 files
- Webhook handling: 12 files
**Total: ~52 files**

**Risk assessment:** MEDIUM
- PCI handled by PayPal
- Redirect flow adds complexity
- Return URL handling error-prone

---

## Branch C: Custom Payment Processing
**Approach:** Process credit cards directly using payment processor API

**Analyzing pros/cons:**
✅ Full control over flow
✅ No user redirects
❌ PCI compliance NIGHTMARE (Level 1 certification required)
❌ Massive security risk (card storage, encryption, etc.)
❌ Legal liability (data breaches, fraud)
❌ Insurance costs (cyber insurance required)
❌ Quarterly security audits (expensive)

**Estimated complexity:** VERY HIGH
**Risk assessment:** CRITICAL

→ **ELIMINATED** (unacceptable risk, prohibitive cost)

---

## 🎯 Branch Selection

**Decision Matrix:**
| Criterion | Stripe | PayPal | Custom |
|-----------|--------|--------|--------|
| Security | ✅ | ✅ | ❌ |
| UX | ✅ | ❌ | ✅ |
| Complexity | ✅ | ⚠️ | ❌ |
| Cost | ⚠️ | ⚠️ | ❌ |
| Legal | ✅ | ✅ | ❌ |
| Dev Time | ✅ | ⚠️ | ❌ |

**SELECTED: Branch A (Stripe Integration)**

**Reasoning:**
1. Best security posture (PCI handled completely by Stripe)
2. Simplest integration (cleanest API)
3. Best UX (no redirects, customers stay on our site)
4. Proven reliability (industry standard)
5. Lower complexity = faster time to market

**Trade-offs accepted:**
- Monthly fees acceptable (2.9% + 30¢ is industry standard)
- Vendor lock-in mitigated by Stripe's market dominance and stability
- External dependency acceptable given Stripe's 99.99% uptime

**Decision logged to:** `.claude/skills/strategic-planner/decision-logs/2025-10-22-payment-provider.json`
```

## Phase 3: Pre-Mortem Analysis

```markdown
# ⚠️ Pre-Mortem: What Could Go Wrong?

"It's 1 week later and the payment system is broken. What happened?"

**Brainstorming failures:**

🔴 **CRITICAL:** Webhook endpoint not secured with signature verification
- **Impact:** Attacker could fake payment confirmations, steal products
- **Likelihood:** HIGH (extremely common mistake in Stripe integrations)
- **Mitigation:** Add webhook signature verification as FIRST webhook task
- **Added to plan:** Task 3.1 - Implement webhook signature verification

🔴 **CRITICAL:** Stripe API keys committed to git repository
- **Impact:** Anyone with repo access can charge cards, steal money
- **Likelihood:** MEDIUM (common developer mistake)
- **Mitigation:** Add .gitignore validation + .env security check
- **Added to plan:** Task 1.3 - Verify API keys in .env only

🔴 **CRITICAL:** Didn't test refund flow
- **Impact:** Cannot issue refunds, angry customers, legal issues
- **Likelihood:** MEDIUM (refunds often forgotten in initial development)
- **Mitigation:** Add comprehensive refund test task
- **Added to plan:** Task 5.4 - Implement and test refund flow

🟠 **HIGH:** Race condition in webhook vs redirect
- **Impact:** Order marked as paid twice or payment recorded incorrectly
- **Likelihood:** MEDIUM (timing issues with async webhooks)
- **Mitigation:** Add idempotency key handling
- **Added to plan:** Task 3.3 - Implement idempotency for webhooks

🟠 **HIGH:** Forgot to handle failed payments
- **Impact:** Orders stuck in "pending" state forever
- **Likelihood:** HIGH (easy to forget error paths)
- **Mitigation:** Add failed payment handler
- **Added to plan:** Task 4.2 - Handle failed payment events

🟡 **MEDIUM:** Currency handling issues (cents vs dollars)
- **Impact:** Wrong amounts charged ($10 becomes $0.10 or $1000)
- **Likelihood:** LOW (good type safety in codebase)
- **Mitigation:** Use CurrencyAmount value object with cents
- **Added to plan:** Task 2.1 - Create CurrencyAmount value object

🟡 **MEDIUM:** Didn't test with declined cards
- **Impact:** Poor error messages, confused users
- **Likelihood:** MEDIUM
- **Mitigation:** Add test for declined cards
- **Added to plan:** Task 6.3 - Test declined card scenarios

🟢 **LOW:** Rate limits hit during high traffic
- **Impact:** Payments fail under load
- **Likelihood:** LOW (unlikely in initial launch)
- **Mitigation:** Monitor first, address if occurs (not adding to plan)

**Critical mitigations added to plan:**
- Task 1.3: Verify API keys security (CRITICAL)
- Task 2.1: CurrencyAmount value object (prevents $ vs ¢ bugs)
- Task 3.1: Webhook signature verification (CRITICAL)
- Task 3.3: Idempotency handling (HIGH)
- Task 4.2: Failed payment handler (HIGH)
- Task 5.4: Refund flow implementation (CRITICAL)
- Task 6.3: Declined card testing (MEDIUM)
```

## Phase 4: Generate SMART-E Atomic Tasks

(Abbreviated - see full plan for all 47 tasks)

### Phase 1: Infrastructure & Security (6 tasks, 18 min)

#### Task 1.1: Install Stripe PHP SDK
- Files: composer.json, composer.lock
- Duration: 3 min
- Depends: none
- Verify: `composer show stripe/stripe-php`

#### Task 1.2: Create .env variables for Stripe keys
- Files: .env.example (document), .env (add keys)
- Duration: 4 min
- Depends: task_1.1
- Verify: Keys present in .env

#### Task 1.3: Verify API keys NOT in git (CRITICAL)
- Files: none (verification only)
- Duration: 2 min
- Depends: task_1.2
- Verify: `git grep -i 'sk_live' || echo 'PASS'` (no matches)

### Phase 2: Domain Layer (12 tasks, 35 min)

#### Task 2.1: Create CurrencyAmount value object (MEDIUM risk mitigation)
- Files: app/Domain/Payment/ValueObjects/CurrencyAmount.php
- Duration: 8 min
- Depends: task_1.3
- Verify: `composer phpstan`

#### Task 2.2: Create PaymentIntent entity
...

### Phase 3: Webhook Security (8 tasks, 22 min)

#### Task 3.1: Implement webhook signature verification (CRITICAL)
- Files: app/Infrastructure/Payment/StripeWebhookVerifier.php
- Duration: 10 min
- Depends: task_2.12
- Verify: Unit test with invalid signature fails

#### Task 3.2: Create webhook controller
...

#### Task 3.3: Add idempotency key handling (HIGH)
...

## Phase 5: Python Validation

```bash
source .claude/skills/strategic-planner/venv/bin/activate

# Validate atomicity
python scripts/2_atomicity_validator.py temp/tasks-payment-system.json
# Output: PASS (94% pass rate, 3 warnings, 0 failures)

# Analyze dependencies
python scripts/1_dependency_analyzer.py temp/tasks-payment-system.json
# Output:
# - Critical path: 42 minutes (18 tasks)
# - Sequential: 85 minutes
# - Parallel optimization: 50.6% time savings
# - Max parallel tasks: 5 (phase 2)
```

### Analysis Results

**Critical Path (42 minutes):**
```
task_1.1 → task_1.2 → task_1.3 → task_2.1 → task_3.1 → task_4.1 → task_5.1 → task_6.1
```

**Parallel Opportunities:**
- Phase 2: 5 tasks can run parallel (domain entities/value objects)
- Phase 4: 4 tasks can run parallel (event handlers)
- Phase 6: 3 tasks can run parallel (different test suites)

**Time Savings:** 43 minutes (50.6% efficiency gain)

## Presenting to User

```markdown
# 🧠 Execution Plan: Stripe Payment Integration

## Summary
- **Objective:** Accept payments for cookie orders via Stripe
- **Approach:** Stripe SDK integration (ToT: Selected over PayPal/Custom)
- **Duration:** 42 minutes (critical path) vs 85 minutes (sequential)
- **Optimization:** 50.6% time savings via parallelization
- **Risk Level:** HIGH → Mitigated to MEDIUM
- **Total Tasks:** 47 atomic tasks across 6 phases

## 💭 Reasoning Summary

**Why Stripe over PayPal/Custom?**
- PayPal: Poor UX (user redirects), more complex API
- Custom: PCI compliance nightmare, unacceptable risk
- Stripe: Best security + UX + developer experience

**Trade-offs accepted:**
- 2.9% + 30¢ fees acceptable for security benefits
- Vendor lock-in acceptable given Stripe's market position

## ⚠️ Risk Mitigations from Pre-Mortem

Pre-mortem identified 8 potential failures, added 7 mitigation tasks:
- 🔴 Webhook security → Task 3.1 (signature verification)
- 🔴 API keys in git → Task 1.3 (verification check)
- 🔴 Refund flow → Task 5.4 (full refund implementation)
- 🟠 Race conditions → Task 3.3 (idempotency)
- 🟠 Failed payments → Task 4.2 (error handler)
- 🟡 Currency bugs → Task 2.1 (CurrencyAmount VO)
- 🟡 Declined cards → Task 6.3 (test coverage)

## 📊 Execution Strategy

**Critical Path (42 min):**
Infrastructure → Security → Core payment → Webhooks → Refunds → Testing

**Parallel Opportunities:**
- Phase 2: 5 domain entities (save 20 min)
- Phase 4: 4 event handlers (save 15 min)
- Phase 6: 3 test suites (save 8 min)

## 🚀 Ready to Execute?

Respond "yes" to create TodoWrite entries and begin execution.
```

## Key Takeaways

**When to use complex planning:**
- Multiple valid approaches (use ToT)
- High risk (use Pre-Mortem)
- 30+ tasks (use Python scripts)
- Significant time savings from parallelization

**All planning techniques used:**
- Chain-of-Thought (understanding)
- Tree-of-Thought (3 branches explored)
- Pre-Mortem (8 risks identified, 7 mitigated)
- SMART-E atomic tasks (47 tasks)
- Python validation (atomicity + dependencies)
- Critical path optimization (50% time savings)
