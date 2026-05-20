<?php

declare(strict_types=1);

namespace App\Infrastructure\Projections;

use App\Infrastructure\Bus\EventDispatcher;

/**
 * Wires {@see ProjectionInterface} implementations to the EventDispatcher
 * (D15).
 *
 * Each projection's `subscribesTo()` declares the events it cares about;
 * the registry subscribes the projection's `apply()` to every one of
 * them. After registration, normal command/event flow keeps the read
 * model up to date.
 *
 * The registry also keeps a list of registered projections so the spark
 * rebuild command can find the right one by name without an extra
 * service-locator lookup.
 */
final class ProjectionRegistry
{
    /**
     * @var array<string, ProjectionInterface>
     */
    private array $projections = [];

    public function __construct(private readonly EventDispatcher $dispatcher)
    {
    }

    public function register(ProjectionInterface $projection): void
    {
        $this->projections[$projection->name()] = $projection;

        foreach ($projection->subscribesTo() as $eventClass) {
            $this->dispatcher->subscribe(
                $eventClass,
                static function (object $event) use ($projection): void {
                    $projection->apply($event);
                }
            );
        }
    }

    /**
     * @return array<string, ProjectionInterface>
     */
    public function all(): array
    {
        return $this->projections;
    }

    public function get(string $name): ProjectionInterface
    {
        if (!isset($this->projections[$name])) {
            throw new \RuntimeException(sprintf('Projection "%s" is not registered.', $name));
        }
        return $this->projections[$name];
    }
}
