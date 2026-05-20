<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Projections;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent;
use App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEvent;
use App\Domain\Cookie\Events\CookieRestored\CookieRestoredEvent;
use App\Domain\Cookie\Events\CookieStockChanged\CookieStockChangedEvent;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Infrastructure\Projections\ProjectionInterface;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Denormalised Cookie projection feeding the `cookie_read_model` table (D15).
 *
 * Listens to every Cookie aggregate event. On Created/Updated, upserts
 * the projection row from the event payload. On Stock changed, only
 * touches the relevant columns. On Deleted/Restored, flips the soft-delete
 * markers. apply() is idempotent — replaying the same event yields the
 * same row.
 *
 * Rebuild path: when the projection drifts (or doesn't exist yet on a
 * new deployment), `php spark projections:rebuild cookie` truncates the
 * table and re-derives every row from the canonical CookieRepository.
 * Useful when adding a column or fixing a transformation bug.
 */
final class CookieReadModelProjection implements ProjectionInterface
{
    /**
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     */
    public function __construct(
        private readonly CookieRepositoryInterface $repository,
        private readonly ?BaseConnection $db = null
    ) {
    }

    public function name(): string
    {
        return 'cookie';
    }

    /**
     * @return list<class-string>
     */
    public function subscribesTo(): array
    {
        return [
            CookieCreatedEvent::class,
            CookieUpdatedEvent::class,
            CookieDeletedEvent::class,
            CookieRestoredEvent::class,
            CookieStockChangedEvent::class,
        ];
    }

    public function apply(object $event): void
    {
        match (true) {
            $event instanceof CookieCreatedEvent => $this->onCreated($event),
            $event instanceof CookieUpdatedEvent => $this->onUpdated($event),
            $event instanceof CookieDeletedEvent => $this->onDeleted($event),
            $event instanceof CookieRestoredEvent => $this->onRestored($event),
            $event instanceof CookieStockChangedEvent => $this->onStockChanged($event),
            default => null,
        };
    }

    public function truncate(): void
    {
        $this->connection()->table('cookie_read_model')->truncate();
    }

    public function rebuildFromSource(?callable $progressCallback = null): void
    {
        $page = 1;
        $perPage = 100;

        while (true) {
            $result = $this->repository->findPaginated(
                page: $page,
                perPage: $perPage,
                searchTerm: null,
                includeInactive: true
            );

            /** @var list<Cookie> $rows */
            $rows = $result['data'];
            if ($rows === []) {
                break;
            }

            foreach ($rows as $cookie) {
                $this->upsertFromEntity($cookie);
            }

            if ($progressCallback !== null) {
                $progressCallback($this);
            }

            if ($page >= $result['lastPage']) {
                break;
            }
            $page++;
        }
    }

    private function onCreated(CookieCreatedEvent $event): void
    {
        $cookie = $this->repository->findById($event->cookieId);
        if ($cookie === null) {
            return;
        }
        $this->upsertFromEntity($cookie);
    }

    private function onUpdated(CookieUpdatedEvent $event): void
    {
        $cookie = $this->repository->findById($event->cookieId);
        if ($cookie === null) {
            return;
        }
        $this->upsertFromEntity($cookie);
    }

    private function onDeleted(CookieDeletedEvent $event): void
    {
        $now = date('Y-m-d H:i:s');
        $this->connection()->table('cookie_read_model')
            ->where('cookie_id', $event->cookieId)
            ->update([
                'deleted_at' => $now,
                'available' => 0,
                'updated_at' => $now,
                'projected_at' => $now,
            ]);
    }

    private function onRestored(CookieRestoredEvent $event): void
    {
        $cookie = $this->repository->findById($event->cookieId);
        if ($cookie === null) {
            return;
        }
        $this->upsertFromEntity($cookie);
    }

    private function onStockChanged(CookieStockChangedEvent $event): void
    {
        if ($event->cookieId === null) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $this->connection()->table('cookie_read_model')
            ->where('cookie_id', $event->cookieId)
            ->update([
                'stock' => $event->newStock,
                'available' => $event->newStock > 0 ? 1 : 0,
                'updated_at' => $now,
                'projected_at' => $now,
            ]);
    }

    private function upsertFromEntity(Cookie $cookie): void
    {
        $id = $cookie->getId();
        if ($id === null) {
            return;
        }

        $row = $this->rowFor($cookie);
        $db = $this->connection();

        $exists = $db->table('cookie_read_model')
            ->where('cookie_id', $id)
            ->countAllResults() > 0;

        if ($exists) {
            $db->table('cookie_read_model')
                ->where('cookie_id', $id)
                ->update($row);
            return;
        }

        $row['cookie_id'] = $id;
        $db->table('cookie_read_model')->insert($row);
    }

    /**
     * @return array<string, scalar|null>
     */
    private function rowFor(Cookie $cookie): array
    {
        $price = $cookie->getPrice();
        $now = date('Y-m-d H:i:s');

        return [
            'tenant_id' => null,
            'name' => $cookie->getName()->getValue(),
            'name_search' => strtolower($cookie->getName()->getValue()),
            'description' => $cookie->getDescription(),
            'price_minor' => $price->getMinorUnits(),
            'price_currency' => $price->getCurrency()->iso,
            'price_decimal' => $price->toDecimalString(),
            'price_formatted' => $price->format(),
            'stock' => $cookie->getStock(),
            'is_active' => $cookie->getIsActive() ? 1 : 0,
            'available' => $cookie->isAvailable() ? 1 : 0,
            'version' => $cookie->getVersion(),
            'created_at' => $cookie->getCreatedAt(),
            'updated_at' => $cookie->getUpdatedAt() ?? $now,
            'deleted_at' => $cookie->getDeletedAt(),
            'projected_at' => $now,
        ];
    }

    /**
     * @return BaseConnection<object|resource|false, object|resource|false>
     */
    private function connection(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }
}
