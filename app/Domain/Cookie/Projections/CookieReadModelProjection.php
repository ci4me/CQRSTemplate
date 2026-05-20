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
     * @param CookieRepositoryInterface                                         $repository
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     * @param \App\Infrastructure\Tenancy\TenantContext|null                    $tenantContext
     *        Stamps `tenant_id` on every projected row so the read repo's
     *        tenant filter has something to match against. Null falls
     *        back to TenantContext::DEFAULT_TENANT_ID (1), keeping the
     *        sentinel consistent with what CookieRepository writes.
     */
    public function __construct(
        private readonly CookieRepositoryInterface $repository,
        private readonly ?BaseConnection $db = null,
        private readonly ?\App\Infrastructure\Tenancy\TenantContext $tenantContext = null
    ) {
    }

    /**
     * name.
     *
     * @return string
     * @todo Auto-generated docblock — review and replace this description.
     */
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

    /**
     * apply.
     *
     * @param object $event
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
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

    /**
     * truncate.
     *
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function truncate(): void
    {
        $this->connection()->table('cookie_read_model')->truncate();
    }

    /**
     * rebuildFromSource.
     *
     * @param callable|null $progressCallback
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function rebuildFromSource(?callable $progressCallback = null): void
    {
        $this->rebuildInto('cookie_read_model', $progressCallback);
    }

    /**
     * Live-safe rebuild via shadow table.
     *
     * The default {@see self::rebuildFromSource()} truncates and rebuilds in
     * place, which means any read served while the rebuild is in flight sees
     * a partial table. This variant builds the full projection into a shadow
     * table FIRST and then renames it into place atomically:
     *
     *   1. CREATE TABLE cookie_read_model_shadow_<ts> LIKE cookie_read_model
     *   2. INSERT every projected row into the shadow.
     *   3. RENAME TABLE cookie_read_model TO _old, _shadow TO live (MySQL
     *      handles this atomically — readers never see an empty table).
     *   4. DROP the _old copy.
     *
     * SQLite has no atomic RENAME-to-existing semantics, so we fall back to
     * the in-place rebuild for the test/dev path. Production targets MySQL
     * or Postgres, which both support the atomic swap.
     *
     * @param callable|null $progressCallback
     * @return void
     */
    public function rebuildFromSourceAtomic(?callable $progressCallback = null): void
    {
        $db = $this->connection();
        $platform = strtolower($db->getPlatform());

        if ($platform === 'sqlite3') {
            // Fall back to the in-place rebuild: SQLite test runs are single-
            // writer anyway, so the race the shadow swap defends against
            // doesn't apply.
            $this->truncate();
            $this->rebuildInto('cookie_read_model', $progressCallback);
            return;
        }

        $shadow = sprintf('cookie_read_model_shadow_%d', time());

        // Step 1: clone the schema. CREATE ... LIKE works on MySQL;
        // Postgres uses CREATE TABLE ... (LIKE ... INCLUDING ALL).
        if ($platform === 'mysqli' || $platform === 'mysql') {
            $db->query(sprintf('CREATE TABLE %s LIKE cookie_read_model', $db->escapeIdentifiers($shadow)));
        } else {
            $db->query(sprintf(
                'CREATE TABLE %s (LIKE cookie_read_model INCLUDING ALL)',
                $db->escapeIdentifiers($shadow)
            ));
        }

        try {
            $this->rebuildInto($shadow, $progressCallback);

            // Step 3: atomic swap. MySQL's RENAME TABLE ... TO ..., ... TO ...
            // is atomic; Postgres needs ALTER TABLE ... RENAME inside a tx.
            $live = 'cookie_read_model';
            $old = sprintf('cookie_read_model_old_%d', time());

            if ($platform === 'mysqli' || $platform === 'mysql') {
                $db->query(sprintf(
                    'RENAME TABLE %s TO %s, %s TO %s',
                    $db->escapeIdentifiers($live),
                    $db->escapeIdentifiers($old),
                    $db->escapeIdentifiers($shadow),
                    $db->escapeIdentifiers($live)
                ));
            } else {
                $db->transBegin();
                $db->query(sprintf('ALTER TABLE %s RENAME TO %s', $db->escapeIdentifiers($live), $db->escapeIdentifiers($old)));
                $db->query(sprintf('ALTER TABLE %s RENAME TO %s', $db->escapeIdentifiers($shadow), $db->escapeIdentifiers($live)));
                $db->transCommit();
            }

            // Step 4: drop the old copy. Failing this leaves an orphan
            // table — we log but don't rethrow, the swap itself already
            // succeeded.
            try {
                $db->query(sprintf('DROP TABLE %s', $db->escapeIdentifiers($old)));
            } catch (\Throwable) {
                // intentional: see comment above.
            }
        } catch (\Throwable $e) {
            // Clean up the shadow table on failure so a half-built rebuild
            // doesn't accumulate orphans across reruns.
            try {
                $db->query(sprintf('DROP TABLE %s', $db->escapeIdentifiers($shadow)));
            } catch (\Throwable) {
                // best-effort
            }
            throw $e;
        }
    }

    /**
     * Shared rebuild loop: paginate over the canonical source and upsert
     * every row into the given target table. Used by both the in-place
     * and shadow-table flows.
     *
     * @param string        $targetTable
     * @param callable|null $progressCallback
     * @return void
     */
    private function rebuildInto(string $targetTable, ?callable $progressCallback): void
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
                $this->upsertFromEntity($cookie, $targetTable);
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

    /**
     * onCreated.
     *
     * @param CookieCreatedEvent $event
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function onCreated(CookieCreatedEvent $event): void
    {
        $cookie = $this->repository->findById($event->cookieId);
        if ($cookie === null) {
            return;
        }
        $this->upsertFromEntity($cookie);
    }

    /**
     * onUpdated.
     *
     * @param CookieUpdatedEvent $event
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function onUpdated(CookieUpdatedEvent $event): void
    {
        $cookie = $this->repository->findById($event->cookieId);
        if ($cookie === null) {
            return;
        }
        $this->upsertFromEntity($cookie);
    }

    /**
     * onDeleted.
     *
     * @param CookieDeletedEvent $event
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
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

    /**
     * onRestored.
     *
     * @param CookieRestoredEvent $event
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function onRestored(CookieRestoredEvent $event): void
    {
        $cookie = $this->repository->findById($event->cookieId);
        if ($cookie === null) {
            return;
        }
        $this->upsertFromEntity($cookie);
    }

    /**
     * onStockChanged.
     *
     * @param CookieStockChangedEvent $event
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
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

    /**
     * upsertFromEntity.
     *
     * @param Cookie $cookie
     * @param string $targetTable
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function upsertFromEntity(Cookie $cookie, string $targetTable = 'cookie_read_model'): void
    {
        $id = $cookie->getId();
        if ($id === null) {
            return;
        }

        $row = $this->rowFor($cookie);
        $db = $this->connection();

        $exists = $db->table($targetTable)
            ->where('cookie_id', $id)
            ->countAllResults() > 0;

        if ($exists) {
            $db->table($targetTable)
                ->where('cookie_id', $id)
                ->update($row);
            return;
        }

        $row['cookie_id'] = $id;
        $db->table($targetTable)->insert($row);
    }

    /**
     * @param Cookie $cookie
     * @return array<string, scalar|null>
     */
    private function rowFor(Cookie $cookie): array
    {
        $price = $cookie->getPrice();
        $now = date('Y-m-d H:i:s');

        // Stamp the tenant id from the active context so the read-side
        // filter (CookieReadModelRepository::applyTenantFilter) actually
        // matches. Fallback to the sentinel default keeps single-tenant
        // deploys aligned with what CookieRepository writes on the source.
        $tenantId = $this->tenantContext !== null
            ? $this->tenantContext->currentTenantId()
            : \App\Infrastructure\Tenancy\TenantContext::DEFAULT_TENANT_ID;

        return [
            'tenant_id' => $tenantId,
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
