<?php

declare(strict_types=1);

namespace App\Infrastructure\Attributes;

use Attribute;

/**
 * Marker attribute: this class is an infrastructure adapter.
 *
 * Used by deptrac to reclassify a class as the Infrastructure layer
 * regardless of where it physically lives. The motivating case (Phase 3
 * of the stabilization refactor) is concrete repositories that sit under
 * app/Domain/{X}/Repositories/ for cloning ergonomics, but are logically
 * infrastructure — they import framework primitives, Models, Config, and
 * other Infrastructure adapters. Marking the class with this attribute
 * tells deptrac.yaml's collectors:
 *
 *   - Domain layer must_not match this attribute
 *   - Infrastructure layer MUST  match this attribute
 *
 * The classifier is attribute-based on purpose. Renaming the class
 * (CookieRepository -> CookieGateway) no longer flips its deptrac layer;
 * only removing the attribute does. That is the answer to "FQCN regex
 * classifiers are brittle against rename" — see Plan v4 TV-1.
 *
 * This attribute is paired with {@see AutoBind} on every concrete
 * repository today; the two attributes are intentionally separate so
 * a class may opt into deptrac reclassification without opting into
 * the {@see \App\Infrastructure\ServiceProvider\ServiceProviderRegistry}
 * auto-binding (and vice-versa).
 *
 * Usage:
 * ```php
 * use App\Infrastructure\Attributes\InfrastructureAdapter;
 *
 * #[InfrastructureAdapter]
 * final readonly class CookieRepository implements CookieRepositoryInterface
 * {
 *     // ...
 * }
 * ```
 *
 * @package App\Infrastructure\Attributes
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class InfrastructureAdapter
{
}
