<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\Exceptions\DomainException;
use Tests\Support\IntegrationTestCase;

final class CookieOptimisticLockingTest extends IntegrationTestCase
{
    public function test_save_bumps_version_on_insert(): void
    {
        $cookie = Cookie::create(
            CookieName::fromString('Lock Test 1'),
            'd',
            CookiePrice::fromString('1.00'),
            5
        );

        $this->assertSame(0, $cookie->getVersion(), 'new cookie starts at version 0');

        $id = $this->cookieRepository->save($cookie);

        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, $cookie->getVersion(), 'version should be 1 after first save');

        $reloaded = $this->cookieRepository->findById($id);
        $this->assertNotNull($reloaded);
        $this->assertSame(1, $reloaded->getVersion());
    }

    public function test_save_bumps_version_on_update(): void
    {
        $cookie = Cookie::create(
            CookieName::fromString('Lock Test 2'),
            'd',
            CookiePrice::fromString('1.00'),
            5
        );
        $id = $this->cookieRepository->save($cookie);

        $cookie->update(
            CookieName::fromString('Lock Test 2'),
            'updated',
            CookiePrice::fromString('2.00'),
            10,
            true
        );

        $this->cookieRepository->save($cookie);

        $this->assertSame(2, $cookie->getVersion());
        $reloaded = $this->cookieRepository->findById($id);
        $this->assertSame(2, $reloaded?->getVersion());
    }

    public function test_concurrent_update_throws_domain_exception(): void
    {
        $cookie = Cookie::create(
            CookieName::fromString('Lock Test 3'),
            'd',
            CookiePrice::fromString('1.00'),
            5
        );
        $id = $this->cookieRepository->save($cookie);

        // Simulate two readers; both see version 1.
        $readerA = $this->cookieRepository->findById($id);
        $readerB = $this->cookieRepository->findById($id);
        $this->assertNotNull($readerA);
        $this->assertNotNull($readerB);

        // Reader A wins the race.
        $readerA->update(
            CookieName::fromString('Lock Test 3'),
            'A wrote first',
            CookiePrice::fromString('1.10'),
            5,
            true
        );
        $this->cookieRepository->save($readerA);

        // Reader B now tries to write with the stale version.
        $readerB->update(
            CookieName::fromString('Lock Test 3'),
            'B was second',
            CookiePrice::fromString('1.20'),
            5,
            true
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('modified by someone else');

        $this->cookieRepository->save($readerB);
    }

    public function test_concurrent_modification_preserves_winners_write(): void
    {
        $cookie = Cookie::create(
            CookieName::fromString('Lock Test 4'),
            'd',
            CookiePrice::fromString('1.00'),
            5
        );
        $id = $this->cookieRepository->save($cookie);

        $readerA = $this->cookieRepository->findById($id);
        $readerB = $this->cookieRepository->findById($id);
        $this->assertNotNull($readerA);
        $this->assertNotNull($readerB);

        $readerA->update(
            CookieName::fromString('Lock Test 4'),
            'A wins',
            CookiePrice::fromString('1.10'),
            5,
            true
        );
        $this->cookieRepository->save($readerA);

        $readerB->update(
            CookieName::fromString('Lock Test 4'),
            'B loses',
            CookiePrice::fromString('9.99'),
            999,
            true
        );

        try {
            $this->cookieRepository->save($readerB);
        } catch (DomainException) {
            // expected
        }

        // The DB MUST still reflect A's write, not B's.
        $final = $this->cookieRepository->findById($id);
        $this->assertNotNull($final);
        $this->assertSame('A wins', $final->getDescription());
        $this->assertSame('1.10', $final->getPrice()->toDecimalString());
        $this->assertSame(5, $final->getStock());
    }
}
