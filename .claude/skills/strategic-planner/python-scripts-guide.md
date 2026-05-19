# Python Scripts Guide

The strategic-planner uses two Python scripts for computational analysis of task plans.

## Prerequisites

### One-Time Setup

```bash
cd .claude/skills/strategic-planner
python3 -m venv venv
source venv/bin/activate
pip install -r scripts/requirements.txt
```

This creates a virtual environment and installs:
- `networkx==3.5` - Graph analysis library
- `matplotlib==3.10.7` - Visualization library

### Activating Environment

**Before running scripts:**
```bash
cd .claude/skills/strategic-planner
source venv/bin/activate
```

## Script 1: Atomicity Validator

**Purpose:** Validates tasks against SMART-E and 5 atomicity rules.

**Location:** `scripts/2_atomicity_validator.py`

### Usage

```bash
python scripts/2_atomicity_validator.py temp/tasks-2025-10-22-143000.json
```

### Input Format

JSON file with tasks:
```json
{
  "task_1.1": {
    "name": "Create migration for flavor column",
    "files": ["app/Database/Migrations/2025_10_22_AddFlavorToCookies.php"],
    "duration_minutes": 5,
    "depends_on": [],
    "risk": "low",
    "specific_changes": "Create migration with up()/down() methods",
    "success_criteria": "Migration runs without errors",
    "blockers": ["none"],
    "verification_command": "php spark migrate --dry-run",
    "can_start_immediately": true,
    "definition_of_done": [
      "File exists at correct path",
      "up() adds flavor column",
      "down() drops flavor column"
    ]
  }
}
```

### Output

```json
{
  "summary": {
    "total_tasks": 25,
    "total_checks": 250,
    "passes": 220,
    "warnings": 27,
    "failures": 3,
    "pass_rate": 88.0,
    "verdict": "PASS"
  },
  "task_results": {
    "task_1.1": {
      "task_id": "task_1.1",
      "task_name": "Create migration for flavor column",
      "rule_1_files": {
        "rule": "≤3 files modified",
        "status": "PASS",
        "details": "1 file (ideal)"
      },
      "rule_2_duration": {
        "rule": "<30 minutes",
        "status": "PASS",
        "details": "5 minutes"
      }
      // ... more rules
    }
  }
}
```

### Interpretation

- **PASS:** All critical checks passed, <5 warnings
- **WARN:** 5-10 warnings, no failures
- **FAIL:** Any rule failures, >10 warnings

**If FAIL:**
1. Review failure details
2. Fix violated tasks
3. Re-run validator
4. Do NOT proceed until PASS

## Script 2: Dependency Analyzer

**Purpose:** Calculates critical path and identifies parallelization opportunities.

**Location:** `scripts/1_dependency_analyzer.py`

### Usage

```bash
python scripts/1_dependency_analyzer.py temp/tasks-2025-10-22-143000.json
```

### Input Format

Same JSON as atomicity validator. Key field: `depends_on`

```json
{
  "task_1.1": {
    "depends_on": []  // Can start immediately
  },
  "task_1.2": {
    "depends_on": ["task_1.1"]  // Requires task_1.1 first
  },
  "task_2.1": {
    "depends_on": ["task_1.2"]
  },
  "task_2.2": {
    "depends_on": ["task_1.2"]  // Parallel with task_2.1
  }
}
```

### Output

```json
{
  "critical_path": {
    "tasks": ["task_1.1", "task_1.2", "task_2.1", "task_3.1"],
    "total_duration_minutes": 42,
    "task_details": [
      {
        "task_id": "task_1.1",
        "name": "Create migration",
        "duration": 5,
        "cumulative": 5
      }
    ]
  },
  "parallel_groups": [
    {
      "level": 0,
      "tasks": ["task_1.1"],
      "can_run_parallel": false
    },
    {
      "level": 1,
      "tasks": ["task_1.2"],
      "can_run_parallel": false
    },
    {
      "level": 2,
      "tasks": ["task_2.1", "task_2.2", "task_2.3"],
      "can_run_parallel": true
    }
  ],
  "optimization": {
    "sequential_duration": 67,
    "parallel_duration": 42,
    "time_saved_minutes": 25,
    "efficiency_gain_percent": 37.3
  },
  "statistics": {
    "total_tasks": 25,
    "max_parallel_tasks": 3,
    "avg_task_duration": 8.5
  }
}
```

### Interpretation

**Critical Path:**
- Longest dependency chain
- Cannot be shortened by parallelization
- Tasks on critical path must complete serially

**Parallel Groups:**
- Tasks at same dependency level
- Can execute simultaneously
- Significant time savings

**Optimization:**
- Sequential: Running all tasks serially
- Parallel: Optimal parallelization
- Time saved: Difference between approaches

## Workflow Integration

### Complete Planning Workflow

```bash
# 1. Generate tasks (AI creates JSON)
# Tasks saved to: temp/tasks-2025-10-22-143000.json

# 2. Validate atomicity
cd .claude/skills/strategic-planner
source venv/bin/activate
python scripts/2_atomicity_validator.py temp/tasks-2025-10-22-143000.json

# Check verdict: PASS/WARN/FAIL
# If FAIL: fix tasks and re-validate

# 3. Analyze dependencies
python scripts/1_dependency_analyzer.py temp/tasks-2025-10-22-143000.json

# Review critical path and parallel opportunities

# 4. Present optimized plan to user
# Include: duration, critical path, parallelization gains

# 5. Execute with TodoWrite
# Convert JSON tasks to TodoWrite entries
```

## Troubleshooting

### Error: "networkx not found"

**Solution:**
```bash
cd .claude/skills/strategic-planner
python3 -m venv venv
source venv/bin/activate
pip install -r scripts/requirements.txt
```

### Error: "Invalid JSON"

**Solution:**
- Check JSON syntax (trailing commas, quotes)
- Validate with `python -m json.tool temp/tasks-*.json`

### Error: "Circular dependency detected"

**Solution:**
- Review `depends_on` fields
- Remove circular references
- Graph analysis will identify the cycle

### Error: "Missing required field"

**Solution:**
- Check task has all required fields:
  - name, files, duration_minutes, depends_on
  - risk, specific_changes, success_criteria
  - verification_command, can_start_immediately

## Performance

**Typical execution times:**
- Atomicity Validator: <1 second for 50 tasks
- Dependency Analyzer: <1 second for 50 tasks
- Both scale linearly with task count

**Limitations:**
- Tested up to 100 tasks
- NetworkX handles graphs with thousands of nodes efficiently
