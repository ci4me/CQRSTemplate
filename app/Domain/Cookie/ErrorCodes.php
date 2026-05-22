<?php

declare(strict_types=1);

namespace App\Domain\Cookie;

/**
 * Cookie domain error codes.
 *
 * Error code ranges (DOMAIN-SCOPED — see contract below):
 * 100-199: Validation errors
 * 200-299: Not found errors
 * 300-399: Business rule violations
 * 400-499: State errors
 * 500-599: Repository errors
 *
 * Scoping contract:
 *   Numbers in this class are scoped to the Cookie domain. They WILL
 *   collide with the same numeric value in {@see \App\Domain\User\ErrorCodes}
 *   (e.g. 101 is COOKIE_VALIDATION_NAME here and USER_VALIDATION_EMAIL there).
 *   That is intentional — every code is emitted alongside a `domain` field
 *   in logs and a `domain` member in API problem-detail responses, so the
 *   numeric collision never produces an ambiguous record.
 *
 *   Consumers that need a GLOBAL identifier should use the FQCN of this
 *   constant (`App\Domain\Cookie\ErrorCodes::COOKIE_VALIDATION_NAME`) or
 *   concatenate `cookie.101` for log queries.
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
    public const int COOKIE_STATE_CONCURRENT_MODIFICATION = 402;
    public const int COOKIE_STATE_NOT_PERSISTED = 403;
    public const int COOKIE_STATE_NOT_DELETED = 404;

    // Repository errors (500-599)
    public const int COOKIE_REPOSITORY_SAVE_FAILED = 501;
    public const int COOKIE_REPOSITORY_DELETE_FAILED = 502;
    public const int COOKIE_REPOSITORY_QUERY_FAILED = 503;
    public const int COOKIE_RESTORE_FAILED = 504;

    // Validation errors continued (1xx range) — query-shape caps surface
    // here so they share the `Validation` family in the response payload.
    // (E08 — closes 04/F2, 04/F4, 04/F6.)
    public const int COOKIE_QUERY_RESULT_LIMIT_EXCEEDED = 110;
    public const int COOKIE_QUERY_PAGE_LIMIT_EXCEEDED = 111;
    public const int COOKIE_QUERY_SEARCH_TERM_TOO_LONG = 112;
}
