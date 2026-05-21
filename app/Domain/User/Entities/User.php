<?php

declare(strict_types=1);

namespace App\Domain\User\Entities;

use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Domain\User\ValueObjects\UserStatus;

/**
 * User Domain Entity (Aggregate Root).
 *
 * This entity represents a user account in the system and enforces
 * all business rules related to users and authentication.
 *
 * Business Rules Enforced:
 * 1. Email must be unique (enforced by repository)
 * 2. Account locks after 5 failed login attempts for 15 minutes
 * 3. Only Active users can login
 * 4. Suspended accounts require admin intervention
 * 5. Deleted users are soft-deleted (deleted_at field)
 *
 * Aggregate Root:
 * User is an aggregate root in DDD terms, meaning it's the entry
 * point for all operations on the user aggregate. All changes
 * to a user go through this entity's methods.
 *
 * Security Considerations:
 * - Password is always stored hashed (HashedPassword value object)
 * - Account lockout prevents brute force attacks
 * - Status transitions are controlled by business methods
 * - Failed login attempts are tracked and logged
 *
 * Why Domain Entity vs Data Model:
 * - Contains business logic and security invariants
 * - Uses Value Objects for validation and type safety
 * - Technology-agnostic (no database concerns)
 * - Enforces security policies at domain level
 *
 * Usage Example:
 * ```php
 * $user = User::create(
 *     email: Email::fromString('user@example.com'),
 *     hashedPassword: HashedPassword::fromPlaintext('securePassword123'),
 *     role: UserRole::Customer
 * );
 * $user->incrementFailedLoginAttempts(); // Track failed login
 * if ($user->isLockedOut()) {
 *     // Handle locked account
 * }
 * ```
 *
 * @package App\Domain\User\Entities
 */
final class User
{
    private const int MAX_FAILED_LOGIN_ATTEMPTS = 5;
    private const int LOCKOUT_DURATION_MINUTES = 15;

    /** @var int|null */
    private ?int $id = null;
    /** @var UserName */
    private UserName $name;
    /** @var Email */
    private Email $email;
    /** @var HashedPassword */
    private HashedPassword $hashedPassword;
    /** @var UserRole */
    private UserRole $role;
    /** @var UserStatus */
    private UserStatus $status;
    /** @var int */
    private int $failedLoginAttempts;
    /** @var \DateTimeImmutable|null */
    private ?\DateTimeImmutable $lockedUntil;
    /** @var \DateTimeImmutable */
    private \DateTimeImmutable $createdAt;
    /** @var \DateTimeImmutable|null */
    private ?\DateTimeImmutable $updatedAt;
    /** @var \DateTimeImmutable|null */
    private ?\DateTimeImmutable $deletedAt;

    /**
     * Create a new User instance.
     *
     * Use named static factories (create, reconstitute) instead of
     * calling this constructor directly.
     *
     * @param UserName                $name                The user's full name
     * @param Email                   $email               The user's email address
     * @param HashedPassword          $hashedPassword      The user's hashed password
     * @param UserRole                $role                The user's role
     * @param UserStatus              $status              The user's account status
     * @param int                     $failedLoginAttempts Number of failed login attempts
     * @param \DateTimeImmutable|null $lockedUntil         Account locked until this time
     */
    private function __construct(
        UserName $name,
        Email $email,
        HashedPassword $hashedPassword,
        UserRole $role,
        UserStatus $status = UserStatus::Active,
        int $failedLoginAttempts = 0,
        ?\DateTimeImmutable $lockedUntil = null
    ) {
        $this->name = $name;
        $this->email = $email;
        $this->hashedPassword = $hashedPassword;
        $this->role = $role;
        $this->status = $status;
        $this->failedLoginAttempts = $failedLoginAttempts;
        $this->lockedUntil = $lockedUntil;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = null;
        $this->deletedAt = null;
    }

    /**
     * Create a new User (factory method for new users).
     *
     * @param UserName       $name           The user's full name
     * @param Email          $email          The user's email address
     * @param HashedPassword $hashedPassword The user's hashed password
     * @param UserRole       $role           The user's role
     * @return self New User instance
     */
    public static function create(
        UserName $name,
        Email $email,
        HashedPassword $hashedPassword,
        UserRole $role
    ): self {
        return new self(
            name: $name,
            email: $email,
            hashedPassword: $hashedPassword,
            role: $role,
            status: UserStatus::Active,
            failedLoginAttempts: 0,
            lockedUntil: null
        );
    }

    /**
     * Reconstitute a User from persistence (factory method for existing users).
     *
     * Used by the repository when loading users from the database.
     *
     * @param int                     $id                  The user ID
     * @param UserName                $name                The user's full name
     * @param Email                   $email               The user's email address
     * @param HashedPassword          $hashedPassword      The user's hashed password
     * @param UserRole                $role                The user's role
     * @param UserStatus              $status              The user's account status
     * @param int                     $failedLoginAttempts Number of failed login attempts
     * @param \DateTimeImmutable|null $lockedUntil         Account locked until this time
     * @param \DateTimeImmutable      $createdAt           Creation timestamp
     * @param \DateTimeImmutable|null $updatedAt           Last update timestamp
     * @param \DateTimeImmutable|null $deletedAt           Deletion timestamp (null if not deleted)
     * @return self Reconstituted User instance
     */
    public static function reconstitute(
        int $id,
        UserName $name,
        Email $email,
        HashedPassword $hashedPassword,
        UserRole $role,
        UserStatus $status,
        int $failedLoginAttempts,
        ?\DateTimeImmutable $lockedUntil,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $deletedAt = null
    ): self {
        $user = new self(
            name: $name,
            email: $email,
            hashedPassword: $hashedPassword,
            role: $role,
            status: $status,
            failedLoginAttempts: $failedLoginAttempts,
            lockedUntil: $lockedUntil
        );

        $user->id = $id;
        $user->createdAt = $createdAt;
        $user->updatedAt = $updatedAt;
        $user->deletedAt = $deletedAt;

        return $user;
    }

    /**
     * Check if user account is active.
     *
     * Business Rule: Only Active users can access the system.
     *
     * @return bool True if status is Active and not soft deleted
     */
    public function isActive(): bool
    {
        return $this->status === UserStatus::Active && $this->deletedAt === null;
    }

    /**
     * Check if user account is locked out.
     *
     * Business Rule: Account is locked if lockedUntil is in the future.
     *
     * @return bool True if account is currently locked
     */
    public function isLockedOut(): bool
    {
        if ($this->lockedUntil === null) {
            return false;
        }

        return $this->lockedUntil > new \DateTimeImmutable();
    }

    /**
     * Activate the user account.
     *
     * Sets status to Active, allowing the user to login.
     *
     * @return void
     */
    public function activate(): void
    {
        $this->status = UserStatus::Active;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Deactivate the user account.
     *
     * Sets status to Inactive, preventing login.
     *
     * @return void
     */
    public function deactivate(): void
    {
        $this->status = UserStatus::Inactive;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Increment failed login attempts counter.
     *
     * Business Rule: After 5 failed attempts, lock account for 15 minutes.
     *
     * @return void
     */
    public function incrementFailedLoginAttempts(): void
    {
        $this->failedLoginAttempts++;

        if ($this->failedLoginAttempts >= self::MAX_FAILED_LOGIN_ATTEMPTS) {
            $this->lockAccount();
        }

        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Reset failed login attempts counter and clear lock.
     *
     * Called after successful login.
     *
     * @return void
     */
    public function resetFailedLoginAttempts(): void
    {
        $this->failedLoginAttempts = 0;
        $this->lockedUntil = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Suspend the user account.
     *
     * Business Rule: Suspended accounts require admin intervention to reactivate.
     *
     * @param string $reason Reason for suspension (retained for domain event dispatch)
     * @return void
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     */
    public function suspend(string $reason): void
    {
        $this->status = UserStatus::Suspended;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Update user information.
     *
     * Business Rules:
     * - Email must be unique (enforced by repository)
     * - Only admins can change roles
     * - Status changes should be logged for audit
     *
     * @param UserName   $name   New name
     * @param Email      $email  New email address
     * @param UserRole   $role   New role
     * @param UserStatus $status New status
     * @return void
     */
    public function update(UserName $name, Email $email, UserRole $role, UserStatus $status): void
    {
        $this->name = $name;
        $this->email = $email;
        $this->role = $role;
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Change user password.
     *
     * @param HashedPassword $newPassword The new hashed password
     * @return void
     */
    public function changePassword(HashedPassword $newPassword): void
    {
        $this->hashedPassword = $newPassword;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Lock user account for lockout duration.
     *
     * Business Rule: Lock for 15 minutes after 5 failed attempts.
     *
     * @return void
     */
    private function lockAccount(): void
    {
        $this->lockedUntil = (new \DateTimeImmutable())
            ->modify(sprintf('+%d minutes', self::LOCKOUT_DURATION_MINUTES));
    }

    // Getters

    /**
     * getId.
     *
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * getName.
     *
     * @return UserName
     */
    public function getName(): UserName
    {
        return $this->name;
    }

    /**
     * getEmail.
     *
     * @return Email
     */
    public function getEmail(): Email
    {
        return $this->email;
    }

    /**
     * getHashedPassword.
     *
     * @return HashedPassword
     */
    public function getHashedPassword(): HashedPassword
    {
        return $this->hashedPassword;
    }

    /**
     * getRole.
     *
     * @return UserRole
     */
    public function getRole(): UserRole
    {
        return $this->role;
    }

    /**
     * getStatus.
     *
     * @return UserStatus
     */
    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    /**
     * getFailedLoginAttempts.
     *
     * @return int
     */
    public function getFailedLoginAttempts(): int
    {
        return $this->failedLoginAttempts;
    }

    /**
     * getLockedUntil.
     *
     * @return \DateTimeImmutable|null
     */
    public function getLockedUntil(): ?\DateTimeImmutable
    {
        return $this->lockedUntil;
    }

    /**
     * getCreatedAt.
     *
     * @return \DateTimeImmutable
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * getUpdatedAt.
     *
     * @return \DateTimeImmutable|null
     */
    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * getDeletedAt.
     *
     * @return \DateTimeImmutable|null
     */
    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }
}
