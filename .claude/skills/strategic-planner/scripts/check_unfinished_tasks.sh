#!/bin/bash
# Check for unfinished strategic-planner executions

echo "Checking for unfinished executions..."
echo ""

# Check if planning directory exists
if [ ! -d ".claude/planning/executions" ]; then
    echo "No executions found."
    exit 0
fi

# Find all in-progress or paused executions
UNFINISHED=$(find .claude/planning/executions -name "execution.json" -exec sh -c '
    STATUS=$(jq -r ".status" "$1" 2>/dev/null)
    if [ "$STATUS" = "in_progress" ] || [ "$STATUS" = "paused" ]; then
        EXEC_ID=$(jq -r ".execution_id" "$1")
        PLAN=$(jq -r ".plan_name" "$1")
        COMPLETED=$(jq -r ".completed_tasks" "$1")
        TOTAL=$(jq -r ".total_tasks" "$1")
        echo "$EXEC_ID|$PLAN|$COMPLETED/$TOTAL|$STATUS"
    fi
' _ {} \;)

if [ -z "$UNFINISHED" ]; then
    echo "✓ No unfinished executions found."
    exit 0
fi

echo "Found unfinished executions:"
echo ""
echo "EXECUTION ID          | PLAN NAME                        | PROGRESS | STATUS"
echo "----------------------|----------------------------------|----------|-------------"

echo "$UNFINISHED" | while IFS='|' read -r exec_id plan completed status; do
    printf "%-21s | %-32s | %-8s | %s\n" "$exec_id" "$plan" "$completed" "$status"
done

echo ""
echo "To resume, ask: 'Resume execution exec-YYYYMMDD-HHMMSS'"
echo "Or: 'Resume my current tasks'"
