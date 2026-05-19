<?php

declare(strict_types=1);

namespace App\Domain\User;

/**
 * Centralized error codes for the User domain.
 *
 * Error Code Ranges:
 * - 1xx: Validation errors
 * - 2xx: Not found errors
 * - 3xx: Business rule violations
 * - 4xx: Repository/persistence errors
 * - 5xx: Authentication/authorization errors
 */
final class ErrorCodes
{
    // Validation errors (100-199)
    public const int USER_VALIDATION_NAME = 100;
    public const int USER_VALIDATION_EMAIL = 101;
    public const int USER_VALIDATION_PASSWORD = 102;
    public const int USER_VALIDATION_ROLE = 103;
    public const int USER_VALIDATION_STATUS = 104;
    public const int USER_VALIDATION_TOKEN = 105;

    // Not found errors (200-299)
    public const int USER_NOT_FOUND = 201;
    public const int USER_NOT_FOUND_BY_EMAIL = 202;

    // Business rule violations (300-399)
    public const int USER_BUSINESS_RULE_ACCOUNT_LOCKED = 301;
    public const int USER_BUSINESS_RULE_LOCKED = 301; // Alias
    public const int USER_BUSINESS_RULE_ACCOUNT_INACTIVE = 302;
    public const int USER_BUSINESS_RULE_ACCOUNT_SUSPENDED = 303;
    public const int USER_BUSINESS_RULE_SUSPENDED = 303; // Alias
    public const int USER_BUSINESS_RULE_EMAIL_ALREADY_EXISTS = 304;
    public const int USER_BUSINESS_RULE_TOO_MANY_LOGIN_ATTEMPTS = 305;
    public const int USER_BUSINESS_RULE_PASSWORD_TOO_WEAK = 306;
    public const int USER_BUSINESS_RULE_INVALID_ROLE_ASSIGNMENT = 307;

    // Repository errors (400-499)
    public const int USER_REPOSITORY_SAVE_FAILED = 401;
    public const int USER_REPOSITORY_UPDATE_FAILED = 402;
    public const int USER_REPOSITORY_DELETE_FAILED = 403;
    public const int USER_REPOSITORY_QUERY_FAILED = 404;

    // Authentication/authorization errors (500-599)
    public const int USER_AUTH_INVALID_CREDENTIALS = 501;
    public const int USER_AUTH_TOKEN_EXPIRED = 502;
    public const int USER_AUTH_TOKEN_INVALID = 503;
    public const int USER_AUTH_TOKEN_BLACKLISTED = 504;
    public const int USER_AUTH_INSUFFICIENT_PERMISSIONS = 505;

    private function __construct()
    {
    }
}
