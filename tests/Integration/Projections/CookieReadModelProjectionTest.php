<?php

declare(strict_types=1);

namespace Tests\Integration\Projections;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent;
use App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEvent;
use App\Domain\Cookie\Events\CookieRestored\CookieRestoredEvent;
use App\Domain\Cookie\Events\CookieStockChanged\CookieStockChangedEvent;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent;
use App\Domain\Cookie\Projections\CookieReadModelProjection;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Projections\ProjectionRegistry;
use Config\Database;
use Tests\Support\IntegrationTestCase;

final class CookieReadModelProjectionTest extends IntegrationTestCase
{
    public function test_created_event_inserts_a_row_with_denormalised_fields(): void
    {
        $cookie = $this->saveCookie('Choco Chip', '2.99', 5, true);

        $projection = new CookieReadModelProjection($this->cookieRepository);
        $projection->apply(new CookieCreatedEvent(
            cookieId: (int) $cookie->getId(),
            cookieName: 'Choco Chip',
            cookiePrice: '2.99',
            initialStock: 5
        ));

        $row = $this->loadRow((int) $cookie->getId());
        $this->assertNotNull($row);
        $this->assertSame('Choco Chip', $row['name']);
        $this->assertSame('choco chip', $row['name_search']);
        $this->assertSame(299, (int) $row['price_minor']);
        $this->assertSame('USD', $row['price_currency']);
        $this->assertSame('$2.99', $row['price_formatted']);
        $this->assertSame(1, (int) $row['available']);
    }

    public function test_apply_is_idempotent(): void
    {
        $cookie = $this->saveCookie('Idempotent', '1.00', 3, true);
        $projection = new CookieReadModelProjection($this->cookieRepository);

        $event = new CookieCreatedEvent((int) $cookie->getId(), 'Idempotent', '1.00', 3);
        $projection->apply($event);
        $projection->apply($event);
        $projection->apply($event);

        $rowCount = Database::connect()->table('cookie_read_model')
            ->where('cookie_id', $cookie->getId())
            ->countAllResults();
        $this->assertSame(1, $rowCount);
    }

    public function test_updated_event_refreshes_denormalised_fields(): void
    {
        $cookie = $this->saveCookie('Old Name', '1.00', 2, true);
        $projection = new CookieReadModelProjection($this->cookieRepository);
        $projection->apply(new CookieCreatedEvent((int) $cookie->getId(), 'Old Name', '1.00', 2));

        // Mutate and persist
        $cookie->update(
            CookieName::fromString('New Name'),
            'updated description',
            CookiePrice::fromString('2.50'),
            10,
            true
        );
        $this->cookieRepository->save($cookie);

        $projection->apply(new CookieUpdatedEvent(
            cookieId: (int) $cookie->getId(),
            cookieName: 'New Name',
            cookiePrice: '2.50'
        ));

        $row = $this->loadRow((int) $cookie->getId());
        $this->assertSame('New Name', $row['name']);
        $this->assertSame('updated description', $row['description']);
        $this->assertSame(250, (int) $row['price_minor']);
        $this->assertSame(10, (int) $row['stock']);
    }

    public function test_deleted_event_flags_row_unavailable(): void
    {
        $cookie = $this->saveCookie('To Delete', '1.00', 5, true);
        $projection = new CookieReadModelProjection($this->cookieRepository);
        $projection->apply(new CookieCreatedEvent((int) $cookie->getId(), 'To Delete', '1.00', 5));

        $projection->apply(new CookieDeletedEvent(
            cookieId: (int) $cookie->getId(),
            cookieName: 'To Delete'
        ));

        $row = $this->loadRow((int) $cookie->getId());
        $this->assertNotNull($row['deleted_at']);
        $this->assertSame(0, (int) $row['available']);
    }

    public function test_restored_event_re_upserts_row_from_source(): void
    {
        $cookie = $this->saveCookie('To Restore', '1.00', 5, true);
        $projection = new CookieReadModelProjection($this->cookieRepository);
        $projection->apply(new CookieCreatedEvent((int) $cookie->getId(), 'To Restore', '1.00', 5));
        $projection->apply(new CookieDeletedEvent((int) $cookie->getId(), 'To Restore'));

        // restore() on the entity is not exposed; the cookie repository
        // restore method drives the test instead.
        $this->cookieRepository->restore((int) $cookie->getId());

        $projection->apply(new CookieRestoredEvent(
            cookieId: (int) $cookie->getId(),
            restoredBy: 0,
            restoredAt: date('c')
        ));

        $row = $this->loadRow((int) $cookie->getId());
        $this->assertNull($row['deleted_at']);
        $this->assertSame(1, (int) $row['available']);
    }

    public function test_stock_changed_event_only_touches_stock_columns(): void
    {
        $cookie = $this->saveCookie('Stock Test', '1.00', 10, true);
        $projection = new CookieReadModelProjection($this->cookieRepository);
        $projection->apply(new CookieCreatedEvent((int) $cookie->getId(), 'Stock Test', '1.00', 10));

        $projection->apply(new CookieStockChangedEvent(
            cookieId: (int) $cookie->getId(),
            previousStock: 10,
            newStock: 0,
            reason: 'decreaseStock'
        ));

        $row = $this->loadRow((int) $cookie->getId());
        $this->assertSame(0, (int) $row['stock']);
        $this->assertSame(0, (int) $row['available']);
        // name was not changed
        $this->assertSame('Stock Test', $row['name']);
    }

    public function test_rebuild_from_source_clears_then_repopulates_table(): void
    {
        $a = $this->saveCookie('Rebuild A', '1.00', 1, true);
        $b = $this->saveCookie('Rebuild B', '2.00', 2, false);

        $projection = new CookieReadModelProjection($this->cookieRepository);

        // Insert garbage we expect to be wiped.
        Database::connect()->table('cookie_read_model')->insert([
            'cookie_id' => 9999,
            'name' => 'GARBAGE',
            'name_search' => 'garbage',
            'price_minor' => 0,
            'price_currency' => 'USD',
            'price_decimal' => 0,
            'price_formatted' => '',
            'stock' => 0,
            'is_active' => 0,
            'available' => 0,
            'version' => 0,
            'projected_at' => date('Y-m-d H:i:s'),
        ]);

        $projection->truncate();
        $projection->rebuildFromSource();

        $rows = Database::connect()->table('cookie_read_model')->orderBy('cookie_id', 'ASC')->get()->getResultArray();
        $this->assertCount(2, $rows);
        $this->assertSame((int) $a->getId(), (int) $rows[0]['cookie_id']);
        $this->assertSame((int) $b->getId(), (int) $rows[1]['cookie_id']);
    }

    public function test_registry_wires_projection_to_dispatcher(): void
    {
        $dispatcher = new EventDispatcher();
        $projection = new CookieReadModelProjection($this->cookieRepository);

        (new ProjectionRegistry($dispatcher))->register($projection);

        $cookie = $this->saveCookie('Wired', '1.00', 1, true);
        // Dispatching the event should land a row even though we didn't
        // call apply() directly.
        $dispatcher->dispatch(new CookieCreatedEvent((int) $cookie->getId(), 'Wired', '1.00', 1));

        $row = $this->loadRow((int) $cookie->getId());
        $this->assertNotNull($row);
        $this->assertSame('Wired', $row['name']);
    }

    private function saveCookie(string $name, string $price, int $stock, bool $active): Cookie
    {
        $cookie = Cookie::create(
            CookieName::fromString($name),
            $name . ' description',
            CookiePrice::fromString($price),
            $stock,
            $active
        );
        $this->cookieRepository->save($cookie);
        return $cookie;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRow(int $cookieId): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = Database::connect()
            ->table('cookie_read_model')
            ->where('cookie_id', $cookieId)
            ->get()
            ->getRowArray();
        return $row;
    }
}
