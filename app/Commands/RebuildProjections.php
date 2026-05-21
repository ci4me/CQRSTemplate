<?php

declare(strict_types=1);

namespace App\Commands;

use App\Infrastructure\Projections\ProjectionInterface;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Rebuild a read-model projection from the canonical source (D15).
 *
 * Usage:
 *   php spark projections:rebuild cookie
 *
 * Truncates the projection's table and re-derives every row from the
 * authoritative repository. Run after a schema change, a bug fix in the
 * projection logic, or to seed the read model on a fresh deployment.
 *
 * Production-safe with caveats: the projection is unavailable while the
 * rebuild is in flight, so schedule outside of read traffic peaks.
 */
final class RebuildProjections extends BaseCommand
{
    /** @var string */
    protected $group = 'Projections';

    /** @var string */
    protected $name = 'projections:rebuild';

    /** @var string */
    protected $description = 'Truncate and rebuild a read-model projection from the source repository';

    /** @var string */
    protected $usage = 'projections:rebuild <projection-name>';

    /** @var array<int, string> */
    protected $arguments = [
        'name' => 'Projection name (e.g. "cookie")',
    ];

    /**
     * @param array<int|string, mixed> $params
     * @return int
     */
    public function run(array $params): int
    {
        $target = (string) ($params[0] ?? '');
        if ($target === '') {
            CLI::error('A projection name is required. Try: cookie');
            return 1;
        }

        $projection = $this->resolveProjection($target);
        if ($projection === null) {
            CLI::error(sprintf('Unknown projection: %s', $target));
            return 1;
        }

        CLI::write(sprintf('Truncating projection "%s"...', $projection->name()), 'yellow');
        $projection->truncate();

        CLI::write('Rebuilding from source...', 'yellow');
        $start = microtime(true);

        $batches = 0;
        $projection->rebuildFromSource(function () use (&$batches): void {
            $batches++;
            CLI::write(sprintf('  ... batch %d', $batches), 'cyan');
        });

        $duration = round(microtime(true) - $start, 2);
        CLI::write(sprintf('Done. %d batches in %ss.', $batches, $duration), 'green');

        return 0;
    }

    /**
     * resolveProjection.
     *
     * @param string $name
     * @return ProjectionInterface|null
     */
    private function resolveProjection(string $name): ?ProjectionInterface
    {
        // For now we wire projections by name to avoid coupling the
        // rebuild command to the dispatcher boot path. Each domain adds
        // its projection here when it lands.
        //
        // Phase 2 of the stabilization refactor collapsed the Cookie read
        // model into the canonical `cookies` table, so the "cookie" target
        // is intentionally not wired up. The reference implementation is
        // preserved at
        // app/Domain/Cookie/Projections/CookieReadModelProjection.php.example.

        unset($name);

        return null;
    }
}
