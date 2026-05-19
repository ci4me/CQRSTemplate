# Multi-Factor Authentication (MFA) Implementation Plan

**STATUS:** Phase 2 - Post-MVP Enhancement
**PRIORITY:** HIGH (Security enhancement)
**ESTIMATED EFFORT:** 3-5 days (1 developer)
**RISK LEVEL:** MEDIUM (user experience impact)

---

## Executive Summary

This document outlines the implementation plan for adding Time-Based One-Time Password (TOTP) Multi-Factor Authentication to the CQRS Template authentication system. MFA significantly reduces account takeover risk even if passwords are compromised.

**Security Impact:**
- **Reduces account takeover risk by 99.9%** (Microsoft Security Report 2020)
- **Mitigates credential stuffing attacks** (stolen password databases)
- **Prevents phishing attacks** (even if user enters password on fake site)
- **Compliance requirement** for PCI-DSS, SOC 2, HIPAA, GDPR (high-risk processing)

---

## 1. Library Selection

### Recommended: `spomky-labs/otphp`

**Rationale:**
- ✅ **Mature and actively maintained** (8+ years, 2M+ downloads)
- ✅ **PSR-4 compliant** (autoloading, namespace support)
- ✅ **RFC 6238 (TOTP) compliant** (Google Authenticator, Microsoft Authenticator, Authy)
- ✅ **Zero dependencies** (no bloat, easy security audits)
- ✅ **Comprehensive documentation** with examples
- ✅ **PHPStan Level 8 compatible** (strict type safety)

**Installation:**

```bash
composer require spomky-labs/otphp
```

**Alternative Libraries Considered:**

| Library | Pros | Cons | Verdict |
|---------|------|------|---------|
| `spomky-labs/otphp` | Mature, RFC compliant, zero deps | None significant | ✅ SELECTED |
| `endroid/qr-code` | QR code generation | Heavy dependencies, overkill | ❌ Use otphp built-in |
| `robthree/twofactorauth` | Simple API | Less active, fewer features | ❌ Less battle-tested |
| `bacon/bacon-qr-code` | Standalone QR | Requires separate TOTP lib | ❌ Unnecessary complexity |

---

## 2. Database Schema Changes

### 2.1 Migration: Add MFA Columns to Users Table

**File:** `app/Database/Migrations/YYYYMMDDHHIISS_AddMfaToUsers.php`

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddMfaToUsers extends Migration
{
    public function up(): void
    {
        $fields = [
            'mfa_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'comment'    => 'Whether MFA is enabled for this user (0=disabled, 1=enabled)',
            ],
            'mfa_secret' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => true,
                'comment'    => 'Base32-encoded TOTP secret key (16 bytes = 32 hex chars)',
            ],
            'mfa_backup_codes' => [
                'type'       => 'TEXT',
                'null'       => true,
                'comment'    => 'JSON array of backup codes (10 codes, 8 chars each, hashed with bcrypt)',
            ],
            'mfa_backup_codes_used' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
                'comment'    => 'Number of backup codes used (for monitoring)',
            ],
            'mfa_enabled_at' => [
                'type'       => 'DATETIME',
                'null'       => true,
                'comment'    => 'Timestamp when MFA was enabled',
            ],
        ];

        $this->forge->addColumn('users', $fields);

        // Index for MFA-enabled users queries
        $this->forge->addKey('mfa_enabled', false, false, 'idx_users_mfa_enabled');
    }

    public function down(): void
    {
        $this->forge->dropColumn('users', ['mfa_enabled', 'mfa_secret', 'mfa_backup_codes', 'mfa_backup_codes_used', 'mfa_enabled_at']);
    }
}
```

### 2.2 Value Object: MfaSecret

**File:** `app/Domain/User/ValueObjects/MfaSecret.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObjects;

use OTPHP\TOTP;

/**
 * MFA Secret Value Object.
 *
 * Encapsulates TOTP secret generation, validation, and QR code generation.
 */
final readonly class MfaSecret
{
    private function __construct(
        private string $secret
    ) {
        $this->validate();
    }

    public static function generate(): self
    {
        // Generate cryptographically secure 160-bit (20 bytes) secret
        $secret = TOTP::create()->getSecret();
        return new self($secret);
    }

    public static function fromString(string $secret): self
    {
        return new self($secret);
    }

    public function getValue(): string
    {
        return $this->secret;
    }

    /**
     * Generate provisioning URI for QR code.
     *
     * @param string $userEmail User's email (displayed in authenticator app)
     * @param string $issuer Application name (displayed in authenticator app)
     */
    public function getProvisioningUri(string $userEmail, string $issuer = 'CQRS Auth'): string
    {
        $totp = TOTP::create($this->secret);
        $totp->setLabel($userEmail);
        $totp->setIssuer($issuer);
        return $totp->getProvisioningUri();
    }

    /**
     * Verify TOTP code against this secret.
     *
     * @param string $code 6-digit code from authenticator app
     * @param int $window Time window (±30 seconds = 1, ±60 seconds = 2)
     */
    public function verify(string $code, int $window = 1): bool
    {
        $totp = TOTP::create($this->secret);
        return $totp->verify($code, null, $window);
    }

    private function validate(): void
    {
        if ($this->secret === '') {
            throw new \InvalidArgumentException('MFA secret cannot be empty');
        }

        // Base32 validation (A-Z, 2-7)
        if (!preg_match('/^[A-Z2-7]+$/', $this->secret)) {
            throw new \InvalidArgumentException('MFA secret must be valid Base32');
        }

        // Minimum 16 characters (80 bits) for security
        if (strlen($this->secret) < 16) {
            throw new \InvalidArgumentException('MFA secret must be at least 16 characters');
        }
    }
}
```

### 2.3 Value Object: BackupCodes

**File:** `app/Domain/User/ValueObjects/BackupCodes.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObjects;

/**
 * MFA Backup Codes Value Object.
 *
 * Generates and manages one-time backup codes for account recovery.
 */
final readonly class BackupCodes
{
    private const int CODE_COUNT = 10;
    private const int CODE_LENGTH = 8;

    /**
     * @param array<int, string> $hashedCodes Bcrypt-hashed backup codes
     */
    private function __construct(
        private array $hashedCodes
    ) {
        $this->validate();
    }

    /**
     * Generate new backup codes.
     *
     * @return array{plain: array<int, string>, hashed: BackupCodes} Plain codes for user display, hashed codes for storage
     */
    public static function generate(): array
    {
        $plainCodes = [];
        $hashedCodes = [];

        for ($i = 0; $i < self::CODE_COUNT; $i++) {
            // Generate 8-character alphanumeric code
            $code = self::generateCode();
            $plainCodes[] = $code;
            $hashedCodes[] = password_hash($code, PASSWORD_ARGON2ID);
        }

        return [
            'plain' => $plainCodes,
            'hashed' => new self($hashedCodes),
        ];
    }

    /**
     * Reconstitute from stored JSON.
     */
    public static function fromJson(string $json): self
    {
        $hashedCodes = json_decode($json, true);
        if (!is_array($hashedCodes)) {
            throw new \InvalidArgumentException('Invalid backup codes JSON');
        }
        return new self($hashedCodes);
    }

    /**
     * Verify backup code and mark as used.
     *
     * @param string $code Plain backup code from user
     * @return array{valid: bool, remaining: BackupCodes|null} Validity and remaining codes
     */
    public function verify(string $code): array
    {
        foreach ($this->hashedCodes as $index => $hashedCode) {
            if (password_verify($code, $hashedCode)) {
                // Remove used code
                $remainingCodes = $this->hashedCodes;
                unset($remainingCodes[$index]);
                $remainingCodes = array_values($remainingCodes); // Re-index

                return [
                    'valid' => true,
                    'remaining' => new self($remainingCodes),
                ];
            }
        }

        return ['valid' => false, 'remaining' => null];
    }

    public function toJson(): string
    {
        return json_encode($this->hashedCodes);
    }

    public function count(): int
    {
        return count($this->hashedCodes);
    }

    private static function generateCode(): string
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // No I, O, 0, 1 (ambiguous)
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }

        // Format as XXXX-XXXX for readability
        return substr($code, 0, 4) . '-' . substr($code, 4, 4);
    }

    private function validate(): void
    {
        if (count($this->hashedCodes) > self::CODE_COUNT) {
            throw new \InvalidArgumentException('Too many backup codes');
        }

        foreach ($this->hashedCodes as $code) {
            if (!is_string($code) || strlen($code) < 20) {
                throw new \InvalidArgumentException('Invalid backup code hash');
            }
        }
    }
}
```

---

## 3. TOTP Setup Flow (User Enrollment)

### 3.1 Command: EnableMfaCommand

**File:** `app/Domain/User/Commands/EnableMfa/EnableMfaCommand.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\User\Commands\EnableMfa;

final readonly class EnableMfaCommand
{
    public function __construct(
        public int $userId,
        public string $totpCode  // 6-digit code from authenticator app (verification)
    ) {
    }
}
```

### 3.2 Handler: EnableMfaHandler

**File:** `app/Domain/User/Commands/EnableMfa/EnableMfaHandler.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain/User/Commands/EnableMfa;

use App\Domain\User\ValueObjects\BackupCodes;
use App\Domain\User\ValueObjects\MfaSecret;
use App\Infrastructure\Persistence\Repositories\UserRepository;
use Psr\Log\LoggerInterface;

final readonly class EnableMfaHandler
{
    public function __construct(
        private UserRepository $repository,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @return array{backup_codes: array<int, string>} Plain backup codes for user download
     */
    public function handle(EnableMfaCommand $command): array
    {
        $user = $this->repository->findById($command->userId);
        if ($user === null) {
            throw new \RuntimeException('User not found');
        }

        // Verify TOTP code before enabling
        if (!$user->getMfaSecret()->verify($command->totpCode)) {
            throw new \RuntimeException('Invalid TOTP code');
        }

        // Generate backup codes
        $backupCodesResult = BackupCodes::generate();

        // Enable MFA on user entity
        $user->enableMfa($backupCodesResult['hashed']);

        // Save to database
        $this->repository->save($user);

        $this->logger->info('MFA enabled for user', [
            'domain' => 'User',
            'user_id' => $user->getId(),
        ]);

        // Return plain backup codes for user download (ONLY TIME SHOWN)
        return ['backup_codes' => $backupCodesResult['plain']];
    }
}
```

### 3.3 API Endpoint: Setup MFA

**Route:** `POST /api/v1/auth/mfa/setup`

**Request:**

```json
{
  "totp_code": "123456"
}
```

**Response:**

```json
{
  "success": true,
  "message": "MFA enabled successfully",
  "data": {
    "backup_codes": [
      "ABCD-1234",
      "EFGH-5678",
      "IJKL-9012",
      "MNOP-3456",
      "QRST-7890",
      "UVWX-1234",
      "YZAB-5678",
      "CDEF-9012",
      "GHIJ-3456",
      "KLMN-7890"
    ],
    "warning": "Save these backup codes in a secure location. They will not be shown again."
  }
}
```

---

## 4. TOTP Verification Flow (Login)

### 4.1 Updated Login Flow

```
1. User submits email + password
   ↓
2. Verify credentials (existing flow)
   ↓
3. IF user.mfa_enabled == true:
     → Return { "requires_mfa": true, "temp_token": "<short-lived>" }
   ELSE:
     → Return { "access_token": "...", "refresh_token": "..." }
   ↓
4. User submits TOTP code + temp_token
   ↓
5. Verify TOTP code
   ↓
6. Return { "access_token": "...", "refresh_token": "..." }
```

### 4.2 Command: VerifyMfaCommand

**File:** `app/Domain/User/Commands/VerifyMfa/VerifyMfaCommand.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\User\Commands\VerifyMfa;

final readonly class VerifyMfaCommand
{
    public function __construct(
        public int $userId,
        public string $totpCode  // 6-digit code OR backup code
    ) {
    }
}
```

### 4.3 Handler: VerifyMfaHandler

**File:** `app/Domain/User/Commands/VerifyMfa/VerifyMfaHandler.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\User\Commands\VerifyMfa;

use App\Infrastructure\Persistence\Repositories\UserRepository;
use Psr\Log\LoggerInterface;

final readonly class VerifyMfaHandler
{
    public function __construct(
        private UserRepository $repository,
        private LoggerInterface $logger
    ) {
    }

    public function handle(VerifyMfaCommand $command): bool
    {
        $user = $this->repository->findById($command->userId);
        if ($user === null || !$user->isMfaEnabled()) {
            return false;
        }

        // Try TOTP code first
        if ($user->getMfaSecret()->verify($command->totpCode)) {
            $this->logger->info('MFA verification successful (TOTP)', [
                'domain' => 'User',
                'user_id' => $user->getId(),
            ]);
            return true;
        }

        // Try backup code fallback
        $backupCodesResult = $user->getBackupCodes()->verify($command->totpCode);
        if ($backupCodesResult['valid']) {
            // Update user with remaining backup codes
            $user->setBackupCodes($backupCodesResult['remaining']);
            $this->repository->save($user);

            $this->logger->warning('MFA verification successful (backup code used)', [
                'domain' => 'User',
                'user_id' => $user->getId(),
                'remaining_backup_codes' => $backupCodesResult['remaining']->count(),
            ]);

            return true;
        }

        $this->logger->warning('MFA verification failed', [
            'domain' => 'User',
            'user_id' => $command->userId,
        ]);

        return false;
    }
}
```

---

## 5. Recovery Process

### 5.1 Admin MFA Reset

**File:** `app/Domain/User/Commands/ResetUserMfa/ResetUserMfaCommand.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\User\Commands\ResetUserMfa;

final readonly class ResetUserMfaCommand
{
    public function __construct(
        public int $targetUserId,  // User whose MFA to reset
        public int $adminUserId,   // Admin performing the reset
        public string $reason      // Audit trail reason
    ) {
    }
}
```

**Handler:** Disables MFA, clears secret and backup codes, logs event for security audit.

### 5.2 Backup Codes

- **10 single-use codes** generated during MFA setup
- **8 characters each** (format: XXXX-XXXX)
- **Bcrypt hashed** in database (prevents theft)
- **User downloads once** during setup (cannot retrieve later)
- **Used when:** User loses authenticator device

**User Flow:**

1. User loses phone with authenticator app
2. User goes to login page
3. User enters email + password
4. System prompts for TOTP code
5. User clicks "Use backup code instead"
6. User enters one of their saved backup codes
7. System logs user in and marks code as used
8. System prompts user to re-enable MFA with new device

---

## 6. Testing Requirements

### 6.1 Unit Tests

- [ ] MfaSecret value object (generation, validation, verification)
- [ ] BackupCodes value object (generation, verification, removal)
- [ ] EnableMfaHandler (success, invalid code, already enabled)
- [ ] VerifyMfaHandler (TOTP success, backup code success, failures)
- [ ] ResetUserMfaHandler (admin reset, audit logging)

### 6.2 Integration Tests

- [ ] MFA setup flow (end-to-end)
- [ ] Login with MFA enabled (TOTP)
- [ ] Login with backup code
- [ ] Admin reset MFA
- [ ] QR code generation and scanning

### 6.3 Security Tests

- [ ] Rate limiting on TOTP verification (5 attempts per 5 minutes)
- [ ] Backup codes are hashed (bcrypt) in database
- [ ] MFA secret is never exposed in API responses
- [ ] Timing attack resistance (constant-time comparison)
- [ ] Brute force protection (account lockout after 10 failed MFA attempts)

---

## 7. Implementation Checklist

### Phase 1: Database & Value Objects (Day 1)
- [ ] Create migration: Add MFA columns to users table
- [ ] Run migration in development
- [ ] Create MfaSecret value object with tests
- [ ] Create BackupCodes value object with tests
- [ ] Update User entity with MFA methods

### Phase 2: Commands & Handlers (Day 2)
- [ ] Create EnableMfaCommand + Handler
- [ ] Create VerifyMfaCommand + Handler
- [ ] Create ResetUserMfaCommand + Handler (admin only)
- [ ] Write unit tests for all handlers

### Phase 3: API Endpoints (Day 3)
- [ ] POST /api/v1/auth/mfa/setup (generate QR code)
- [ ] POST /api/v1/auth/mfa/enable (verify TOTP, return backup codes)
- [ ] POST /api/v1/auth/mfa/verify (during login)
- [ ] DELETE /api/v1/auth/mfa (disable MFA)
- [ ] POST /api/v1/admin/users/:id/mfa/reset (admin only)

### Phase 4: Frontend Integration (Day 4)
- [ ] MFA setup page (display QR code, verify TOTP)
- [ ] Backup codes download page (one-time display)
- [ ] Login MFA verification page (TOTP or backup code)
- [ ] User settings MFA toggle

### Phase 5: Testing & Deployment (Day 5)
- [ ] Run all unit and integration tests
- [ ] Security testing (rate limiting, brute force)
- [ ] User acceptance testing
- [ ] Documentation update
- [ ] Production deployment

---

## 8. Security Considerations

**TOTP Configuration:**
- **Time step:** 30 seconds (RFC 6238 standard)
- **Code length:** 6 digits (industry standard)
- **Verification window:** ±1 step (allows for clock skew)

**Rate Limiting:**
- **TOTP verification:** 5 attempts per 5 minutes per user
- **Backup code verification:** 3 attempts per 5 minutes per user
- **Account lockout:** After 10 consecutive failed MFA attempts (requires admin reset)

**Secret Storage:**
- **MFA secrets encrypted at rest** (application-level encryption recommended)
- **Backup codes hashed with Argon2ID** (cannot be recovered if leaked)

**Audit Logging:**
- [ ] MFA enabled/disabled events
- [ ] TOTP verification attempts (success/failure)
- [ ] Backup code usage
- [ ] Admin MFA resets

---

## 9. User Communication

### Email Templates

**MFA Enabled:**
```
Subject: Multi-Factor Authentication Enabled

Hi {name},

Multi-factor authentication has been enabled on your account.

You will now need to enter a 6-digit code from your authenticator app when logging in.

If this was not you, please contact support immediately.

Security Team
```

**Backup Code Used:**
```
Subject: Backup Code Used

Hi {name},

A backup code was used to access your account.

Remaining backup codes: {count}/10

We recommend setting up a new authenticator device and generating new backup codes.

Security Team
```

---

## 10. Rollout Strategy

### Gradual Rollout (Recommended)

1. **Week 1:** Optional MFA for all users
2. **Week 2-4:** Email reminders to enable MFA
3. **Week 5:** Mandatory MFA for admin accounts
4. **Week 6-8:** Mandatory MFA for all users (with 2-week grace period)

### Monitoring Metrics

- MFA adoption rate (target: 80% by week 4)
- Login failure rate (watch for user friction)
- Support tickets related to MFA
- Backup code usage rate

---

## 11. Compliance & Standards

**Compliance Requirements Met:**
- ✅ PCI-DSS 8.3 - Multi-factor authentication for remote access
- ✅ NIST SP 800-63B - Level 2 Authenticator Assurance
- ✅ SOC 2 - CC6.1 Logical Access Security
- ✅ GDPR Article 32 - Appropriate technical measures

**Standards Followed:**
- ✅ RFC 6238 - TOTP Algorithm
- ✅ RFC 4226 - HOTP Algorithm (backup codes)
- ✅ OWASP Authentication Cheat Sheet

---

## 12. Cost-Benefit Analysis

**Costs:**
- Development time: 3-5 days (1 developer)
- User training and support
- Slight increase in login friction

**Benefits:**
- **99.9% reduction** in account takeover risk
- **Compliance requirements** met (PCI-DSS, SOC 2)
- **Insurance premiums** reduced (cyber liability)
- **Customer trust** increased

**ROI:** Positive within 3 months (assuming single data breach prevented)

---

**APPROVAL REQUIRED FROM:**
- [ ] Security Team
- [ ] Product Manager
- [ ] Engineering Manager

**READY FOR IMPLEMENTATION:** YES (pending approval)
