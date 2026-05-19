# Security Cleanup Jobs

## Overview

Two cleanup commands are provided to maintain database hygiene and security compliance:

1. **Password Reset Token Cleanup** - Removes expired password reset tokens (CR-6.3)
2. **Session Cleanup** - Removes expired sessions (CR-7.3)

## Commands

### Cleanup Password Reset Tokens

```bash
# Production: Delete expired tokens
php spark auth:cleanup-reset-tokens

# Preview: See what would be deleted (dry-run)
php spark auth:cleanup-reset-tokens --dry-run
```

**What it does:**
- Deletes password reset tokens where `expires_at < NOW()`
- Prevents token accumulation and reduces attack surface
- Logs cleanup activity for audit

### Cleanup Expired Sessions

```bash
# Production: Delete expired sessions
php spark auth:cleanup-sessions

# Preview: See what would be deleted (dry-run)
php spark auth:cleanup-sessions --dry-run
```

**What it does:**
- Deletes sessions where `expires_at < NOW()`
- Reduces database bloat
- Improves query performance

## Scheduling

### Linux/Unix (Cron)

Add to crontab (`crontab -e`):

```cron
# Run daily at 2:00 AM
0 2 * * * cd /path/to/project && php spark auth:cleanup-reset-tokens >> /var/log/cleanup.log 2>&1
0 2 * * * cd /path/to/project && php spark auth:cleanup-sessions >> /var/log/cleanup.log 2>&1
```

### Windows (Task Scheduler)

1. Open Task Scheduler
2. Create Basic Task
3. Set trigger: Daily at 2:00 AM
4. Action: Start a program
5. Program: `C:\php\php.exe`
6. Arguments: `spark auth:cleanup-reset-tokens`
7. Start in: `C:\path\to\project`
8. Repeat for session cleanup

### Docker/Kubernetes

**Docker Compose with Cron:**

```yaml
services:
  cron:
    image: your-app-image
    command: >
      sh -c "
        echo '0 2 * * * cd /var/www/html && php spark auth:cleanup-reset-tokens' | crontab - &&
        echo '0 2 * * * cd /var/www/html && php spark auth:cleanup-sessions' | crontab - &&
        crond -f
      "
```

**Kubernetes CronJob:**

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: cleanup-password-reset-tokens
spec:
  schedule: "0 2 * * *"
  jobTemplate:
    spec:
      template:
        spec:
          containers:
          - name: cleanup
            image: your-app-image
            command: ["php", "spark", "auth:cleanup-reset-tokens"]
          restartPolicy: OnFailure
```

## Monitoring

### Log Files

Cleanup activity is logged to:
- CodeIgniter logs: `writable/logs/app-YYYY-MM-DD.json`
- Search for: `"command": "auth:cleanup-reset-tokens"` or `"command": "auth:cleanup-sessions"`

### Alerts

Consider setting up alerts if:
- No cleanup activity for 7+ days (cron job might have failed)
- Large number of tokens/sessions deleted (>10,000) - investigate why accumulation happened

## Testing

```bash
# Test in development (preview only)
php spark auth:cleanup-reset-tokens --dry-run
php spark auth:cleanup-sessions --dry-run

# Verify logs
tail -f writable/logs/app-$(date +%Y-%m-%d).json | grep cleanup
```

## Security Notes

- **No User Impact**: These commands only delete expired data
- **Safe to Run Anytime**: Can be run manually if needed
- **Idempotent**: Running multiple times has no side effects
- **Fast Execution**: Typically completes in <1 second for small databases

## Compliance

These cleanup jobs help maintain:
- **GDPR**: Data minimization principle (only keep necessary data)
- **PCI-DSS**: Secure deletion of authentication data
- **SOC 2**: Proper data lifecycle management
