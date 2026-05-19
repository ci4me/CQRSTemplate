# Pattern Library

This file grows over time as the strategic-planner learns from successful executions.

## Purpose
Store successful planning patterns to:
- Speed up future planning
- Avoid past mistakes
- Improve recommendations
- Learn project-specific conventions

## Pattern Format

```json
{
  "pattern_id": "unique_identifier",
  "name": "Human-readable name",
  "times_used": 0,
  "success_rate": 100.0,
  "avg_duration": 0,
  "description": "What this pattern is for",
  "triggers": ["keywords that suggest this pattern"],
  "template": {
    "phases": [],
    "common_tasks": [],
    "dependencies": []
  },
  "common_pitfalls": [],
  "recommendations": []
}
```

## Initial Patterns

### Pattern: Add Value Object Property
*Will be populated after first successful execution*

### Pattern: Create New Domain
*Will be populated after first successful execution*

### Pattern: Add Business Rule
*Will be populated after first successful execution*

---

**Note:** This library will grow automatically as you use strategic-planner.
Each successful execution adds pattern data for future reference.
