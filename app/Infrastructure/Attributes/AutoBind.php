<?php

declare(strict_types=1);

namespace App\Infrastructure\Attributes;

use Attribute;

/**
 * Marker attribute: auto-discover this class and register it under the
 * lower-camelCase form of its short name as a repository service.
 *
 * Used by {@see \App\Infrastructure\ServiceProvider\ServiceProviderRegistry}.
 * On boot the registry scans app/Domain and app/Infrastructure for classes
 * marked with this attribute and instantiates each, injecting a small set
 * of well-known dependencies via constructor reflection:
 *
 *   - {@see \CodeIgniter\Database\BaseConnection}        (via Config\Database::connect())
 *   - {@see \Psr\Log\LoggerInterface}                    (via LoggerFactory)
 *   - {@see \Config\Logging}                             (via new \Config\Logging())
 *   - {@see \App\Infrastructure\Tenancy\TenantContext}   (via Services::tenantContext())
 *   - {@see \App\Infrastructure\Outbox\EventOutboxWriter}
 *   - {@see \App\Infrastructure\Persistence\Models\UserModel}
 *   - {@see \App\Models\Cookie\CookieModel}
 *
 * Each instance is then exposed under its lower-camelCase short name
 * (CookieRepository -> 'cookieRepository') in the repositories array that
 * the registry passes to every DomainServiceProvider via setRepositories().
 *
 * This attribute is intentionally separate from {@see InfrastructureAdapter}.
 * The split [Plan v4 TV-1] keeps each concern explicit:
 *   - #[InfrastructureAdapter] is for deptrac classification only
 *   - #[AutoBind] is for DI registration only
 * A class may carry one without the other when that makes sense.
 *
 * Usage:
 * ```php
 * use App\Infrastructure\Attributes\AutoBind;
 *
 * #[AutoBind]
 * final readonly class CookieRepository implements CookieRepositoryInterface
 * {
 *     public function __construct(
 *         private LoggerInterface $logger,
 *         private Logging $loggingConfig,
 *     ) {}
 * }
 * ```
 *
 * After auto-discovery, `$repositories['cookieRepository']` resolves to the
 * concrete CookieRepository instance.
 *
 * @package App\Infrastructure\Attributes
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AutoBind
{
}
