<?php

declare(strict_types=1);

namespace App\Domain\Cookie;

/**
 * Cookie domain error codes.
 *
 * Error code ranges:
 * 100-199: Validation errors
 * 200-299: Not found errors
 * 300-399: Business rule violations
 * 400-499: State errors
 * 500-599: Repository errors
 *
 * Usage Example:
 * ```php
 * throw ValidationException::required('name', ErrorCodes::COOKIE_VALIDATION_NAME);
 * throw DomainException::businessRuleViolation('Stock negative', 'details', ErrorCodes::COOKIE_BUSINESS_RULE_STOCK_NEGATIVE);
 * ```
 *
 * @package App\Domain\Cookie
 */
final class ErrorCodes
{
    // Validation errors (100-199)
    public const int COOKIE_VALIDATION_NAME = 101;
    public const int COOKIE_VALIDATION_PRICE = 102;
    public const int COOKIE_VALIDATION_STOCK = 103;

    // Not found errors (200-299)
    public const int COOKIE_NOT_FOUND = 201;
    public const int COOKIE_NAME_NOT_UNIQUE = 202;

    // Business rule violations (300-399)
    public const int COOKIE_BUSINESS_RULE_STOCK_NEGATIVE = 301;
    public const int COOKIE_BUSINESS_RULE_INACTIVE = 302;
    public const int COOKIE_BUSINESS_RULE_NAME_DUPLICATE = 303;

    // State errors (400-499)
    public const int COOKIE_STATE_DELETED = 401;

    // Repository errors (500-599)
    public const int COOKIE_REPOSITORY_SAVE_FAILED = 501;
    public const int COOKIE_REPOSITORY_DELETE_FAILED = 502;
    public const int COOKIE_REPOSITORY_QUERY_FAILED = 503;
}
