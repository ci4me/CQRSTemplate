<?php

declare(strict_types=1);

namespace App\Infrastructure\Attributes;

use Attribute;

/**
 * Attribute to mark a class as a Domain Service Provider.
 *
 * This attribute is used to automatically discover and register
 * domain service providers without manual configuration in Services.php.
 *
 * Usage:
 * ```php
 * #[DomainServiceProvider]
 * class CookieServiceProvider implements DomainServiceProviderInterface
 * {
 *     public function registerCommands(CommandBus $bus): void { ... }
 *     public function registerQueries(QueryBus $bus): void { ... }
 *     public function registerEvents(EventDispatcher $dispatcher): void { ... }
 * }
 * ```
 *
 * Benefits:
 * - Zero-configuration domain addition
 * - Automatic discovery via reflection
 * - Type-safe via interface enforcement
 * - Modern PHP 8+ approach
 *
 * @package App\Infrastructure\Attributes
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class DomainServiceProvider
{
}
