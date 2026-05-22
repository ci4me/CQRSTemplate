<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Repositories;

use App\Domain\Cookie\Repositories\CookieRepository;
use App\Domain\Shared\Exceptions\DomainException;
use App\Infrastructure\Logging\LoggerFactory;
use App\Models\Cookie\CookieModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Logging;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use RuntimeException;
use Tests\Support\Factories\CookieFactory;
use Tests\Support\UnitTestCase;

/**
 * Unit-level coverage of CookieRepository error-mapping / rethrow paths.
 *
 * These cases were previously hosted inside
 * `tests/Integration/Repositories/CookieRepositoryTest.php` with a
 * class-level `#[AllowMockObjectsWithoutExpectations]` — the resulting
 * file was half integration (real DB) and half unit (`createMock`),
 * paid the migration cost on every test, and silently weakened risky-
 * test detection for the real-DB methods.
 *
 * Each method here injects a mocked `CookieModel` to force the catch /
 * rethrow branches that SQLite (and even MySQL) cannot reach via the
 * real driver — duplicate-key on `tenant_id=NULL`, builder failures,
 * generic Throwables. The MySQL-only duplicate-key behaviour against
 * the real driver lives in the integration suite under the MySQL CI
 * lane (E01); this file covers the pure mapping logic.
 *
 * Round-3 audit findings closed by this split: 13/F2, 13/F17.
 *
 * @package Tests\Unit\Domain\Cookie\Repositories
 */
final class CookieRepositoryErrorMappingTest extends UnitTestCase
{
    private function logging(): Logging
    {
        /** @var Logging $config */
        $config = config('Logging');

        return $config;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_save_translates_duplicate_key_database_exception_into_domain_exception(): void
    {
        // The composite UNIQUE (tenant_id, name, deleted_at) on the
        // cookies table is what catches a concurrent create that raced
        // past the handler's existsByName guard. The repository's catch
        // block must translate the DatabaseException into a
        // DomainException with a stable error code so callers don't
        // leak SQL state.
        //
        // SQLite does not surface a duplicate-key error naturally because
        // NULLs in `deleted_at` are treated as distinct, so a directly-
        // injected model is the deterministic way to test the catch +
        // translation logic regardless of which lane is active.
        $model = $this->createMock(CookieModel::class);
        $model->method('find')->willReturn(null);
        $model->method('insert')
            ->willThrowException(new DatabaseException(
                'duplicate entry "Twin Cookie" for key cookies_tenant_name'
            ));

        $logger = LoggerFactory::create('test.cookie.repository.duplicate');
        $repo = new CookieRepository($logger, $this->logging(), $model);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('must be unique');

        $repo->save(CookieFactory::createCookie(['name' => 'Twin Cookie']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_save_rethrows_non_duplicate_database_exception(): void
    {
        // Non-duplicate-key DatabaseException (e.g. connection lost) must
        // be logged and rethrown as-is, NOT mapped into DomainException.
        $model = $this->createMock(CookieModel::class);
        $model->method('find')->willReturn(null);
        $model->method('insert')
            ->willThrowException(new DatabaseException(
                'connection refused at host 127.0.0.1'
            ));

        $logger = LoggerFactory::create('test.cookie.repository.dberror');
        $repo = new CookieRepository($logger, $this->logging(), $model);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('connection refused');

        $repo->save(CookieFactory::createCookie(['name' => 'Connection Test']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_find_all_logs_and_rethrows_when_builder_throws(): void
    {
        // executeFindAll calls $this->model->builder() — mock the model so
        // builder() throws and the outer catch in findAll() is exercised.
        $model = $this->createMock(CookieModel::class);
        $model->method('builder')->willThrowException(new RuntimeException('builder unavailable'));

        $logger = LoggerFactory::create('test.cookie.repository.findall-error');
        $repo = new CookieRepository($logger, $this->logging(), $model);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('builder unavailable');

        $repo->findAll();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_find_paginated_logs_and_rethrows_when_builder_throws(): void
    {
        $model = $this->createMock(CookieModel::class);
        $model->method('builder')->willThrowException(new RuntimeException('paginator broken'));

        $logger = LoggerFactory::create('test.cookie.repository.findpaginated-error');
        $repo = new CookieRepository($logger, $this->logging(), $model);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('paginator broken');

        $repo->findPaginated();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_restore_logs_and_rethrows_when_model_throws(): void
    {
        // findByIdWithTrashed inside restore() succeeds with a soft-deleted
        // row, but the builder->update() chain throws.
        $model = $this->createMock(CookieModel::class);
        $model->method('withDeleted')->willReturnSelf();
        $model->method('find')->willReturn([
            'id' => 1, 'name' => 'Trashed', 'description' => null,
            'price' => '1.00', 'stock' => 1, 'is_active' => 0,
            'created_at' => '2026-05-22 00:00:00', 'updated_at' => null,
            'deleted_at' => '2026-05-22 12:00:00', 'version' => 1,
        ]);
        $model->method('builder')->willThrowException(new RuntimeException('restore failed'));

        $logger = LoggerFactory::create('test.cookie.repository.restore-error');
        $repo = new CookieRepository($logger, $this->logging(), $model);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('restore failed');

        $repo->restore(1);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_find_by_id_logs_and_rethrows_when_model_throws(): void
    {
        $model = $this->createMock(CookieModel::class);
        $model->method('find')->willThrowException(new RuntimeException('storage layer down'));

        $logger = LoggerFactory::create('test.cookie.repository.findbyid-error');
        $repo = new CookieRepository($logger, $this->logging(), $model);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('storage layer down');

        $repo->findById(42);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_delete_logs_and_rethrows_when_model_throws(): void
    {
        // findById inside delete() returns a cookie row, but then the
        // builder->update() chain throws.
        $model = $this->createMock(CookieModel::class);
        $model->method('find')->willReturn([
            'id' => 1, 'name' => 'Doomed', 'description' => null,
            'price' => '1.00', 'stock' => 1, 'is_active' => 1,
            'created_at' => '2026-05-22 00:00:00', 'updated_at' => null,
            'deleted_at' => null, 'version' => 1,
        ]);
        $model->method('delete')->willThrowException(new RuntimeException('write barrier failed'));

        $logger = LoggerFactory::create('test.cookie.repository.delete-error');
        $repo = new CookieRepository($logger, $this->logging(), $model);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('write barrier failed');

        $repo->delete(1);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_save_rethrows_unknown_throwable_from_model(): void
    {
        // A generic Throwable (not DatabaseException) must propagate
        // through the third catch arm with logging.
        $model = $this->createMock(CookieModel::class);
        $model->method('find')->willReturn(null);
        $model->method('insert')->willThrowException(new RuntimeException('out of memory'));

        $logger = LoggerFactory::create('test.cookie.repository.unknownerror');
        $repo = new CookieRepository($logger, $this->logging(), $model);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('out of memory');

        $repo->save(CookieFactory::createCookie(['name' => 'Memory Test']));
    }
}
