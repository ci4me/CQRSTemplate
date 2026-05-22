<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Domain\User\Entities\User;
use App\Domain\User\Repositories\UserRepository;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Domain\User\ValueObjects\UserStatus;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Persistence\Models\UserModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\IntegrationTestCase;

/**
 * Drives the SQL UserRepository against a real database. The repository was
 * previously only exercised indirectly through Auth integration tests — this
 * file pins its contract explicitly so refactors can't silently break the
 * mapping between {@see User} and the `users` table.
 *
 * Extends {@see IntegrationTestCase} for migration/refresh wiring; the
 * inherited `cookieRepository` is unused here (it's a sibling repository).
 */
#[AllowMockObjectsWithoutExpectations]
final class UserRepositoryTest extends IntegrationTestCase
{
    private UserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $logger = LoggerFactory::create('test.user.repository');
        $loggingConfig = config('Logging');
        $this->repository = new UserRepository(new UserModel(), $logger, $loggingConfig);
    }

    // ------------------------------------------------------------------
    // save() — insert path
    // ------------------------------------------------------------------

    public function test_save_inserts_user_and_returns_generated_id(): void
    {
        $id = $this->repository->save($this->makeUser('alice@example.com', 'Alice'));

        $this->assertGreaterThan(0, $id);
        $this->seeInDatabase('users', [
            'id' => $id,
            'email' => 'alice@example.com',
            'name' => 'Alice',
            'role' => 'customer',
            'status' => 'active',
        ]);
    }

    public function test_save_persists_hashed_password_not_plaintext(): void
    {
        $id = $this->repository->save($this->makeUser('bob@example.com', 'Bob'));

        $row = (new UserModel())->find($id);
        $this->assertIsArray($row);
        $hash = $row['password_hash'];
        $this->assertIsString($hash);
        $this->assertNotSame('plaintext-secret', $hash);
        $this->assertStringStartsWith('$2y$', $hash);
    }

    // ------------------------------------------------------------------
    // findById()
    // ------------------------------------------------------------------

    public function test_find_by_id_returns_reconstituted_entity(): void
    {
        $id = $this->repository->save($this->makeUser('carol@example.com', 'Carol'));

        $found = $this->repository->findById($id);

        $this->assertInstanceOf(User::class, $found);
        $this->assertSame($id, $found->getId());
        $this->assertSame('carol@example.com', $found->getEmail()->getValue());
        $this->assertSame('Carol', $found->getName()->getValue());
        $this->assertSame(UserRole::Customer, $found->getRole());
        $this->assertSame(UserStatus::Active, $found->getStatus());
    }

    public function test_find_by_id_returns_null_for_missing_user(): void
    {
        $this->assertNull($this->repository->findById(9999));
    }

    // ------------------------------------------------------------------
    // findByEmail()
    // ------------------------------------------------------------------

    public function test_find_by_email_returns_entity(): void
    {
        $this->repository->save($this->makeUser('dan@example.com', 'Dan'));

        $found = $this->repository->findByEmail(Email::fromString('dan@example.com'));

        $this->assertInstanceOf(User::class, $found);
        $this->assertSame('dan@example.com', $found->getEmail()->getValue());
    }

    public function test_find_by_email_returns_null_for_missing(): void
    {
        $this->assertNull($this->repository->findByEmail(Email::fromString('nobody@example.com')));
    }

    // ------------------------------------------------------------------
    // update()
    // ------------------------------------------------------------------

    public function test_update_persists_status_and_role_changes(): void
    {
        $id = $this->repository->save($this->makeUser('eve@example.com', 'Eve'));
        $user = $this->repository->findById($id);
        $this->assertNotNull($user);

        $user->update(
            UserName::fromString('Eve Updated'),
            Email::fromString('eve@example.com'),
            UserRole::Admin,
            UserStatus::Inactive,
        );
        $result = $this->repository->update($user);
        $this->assertTrue($result);

        $reloaded = $this->repository->findById($id);
        $this->assertNotNull($reloaded);
        $this->assertSame('Eve Updated', $reloaded->getName()->getValue());
        $this->assertSame(UserRole::Admin, $reloaded->getRole());
        $this->assertSame(UserStatus::Inactive, $reloaded->getStatus());
    }

    // ------------------------------------------------------------------
    // delete() — soft delete
    // ------------------------------------------------------------------

    public function test_delete_soft_deletes_user_and_hides_from_findById(): void
    {
        $id = $this->repository->save($this->makeUser('frank@example.com', 'Frank'));

        $this->assertTrue($this->repository->delete($id));

        $this->assertNull($this->repository->findById($id));
        // The row still exists in storage — just flagged.
        $row = (new UserModel())->withDeleted()->find($id);
        $this->assertIsArray($row);
        $this->assertNotNull($row['deleted_at']);
    }

    // ------------------------------------------------------------------
    // findPaginated()
    // ------------------------------------------------------------------

    public function test_find_paginated_returns_page_slice_with_correct_total(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->repository->save($this->makeUser("user{$i}@example.com", "User {$i}"));
        }

        $page1 = $this->repository->findPaginated(page: 1, perPage: 2);
        $page2 = $this->repository->findPaginated(page: 2, perPage: 2);
        $page3 = $this->repository->findPaginated(page: 3, perPage: 2);

        $this->assertSame(5, $page1['total']);
        $this->assertSame(3, $page1['totalPages']);
        $this->assertCount(2, $page1['data']);
        $this->assertCount(2, $page2['data']);
        $this->assertCount(1, $page3['data']);
    }

    public function test_find_paginated_filters_by_role_and_status(): void
    {
        $this->repository->save($this->makeUser('admin1@example.com', 'A1', UserRole::Admin));
        $this->repository->save($this->makeUser('admin2@example.com', 'A2', UserRole::Admin));
        $this->repository->save($this->makeUser('cust@example.com', 'C1', UserRole::Customer));

        $admins = $this->repository->findPaginated(
            page: 1,
            perPage: 10,
            includeInactive: false,
            searchTerm: '',
            role: 'admin',
        );

        $this->assertSame(2, $admins['total']);
        foreach ($admins['data'] as $user) {
            $this->assertSame(UserRole::Admin, $user->getRole());
        }
    }

    public function test_find_paginated_search_matches_name_or_email(): void
    {
        $this->repository->save($this->makeUser('alpha@example.com', 'Alpha'));
        $this->repository->save($this->makeUser('beta@example.com', 'Beta'));
        $this->repository->save($this->makeUser('charlie@search-me.com', 'Charlie'));

        $byName = $this->repository->findPaginated(1, 10, false, 'Alpha');
        $byEmail = $this->repository->findPaginated(1, 10, false, 'search-me');

        $this->assertSame(1, $byName['total']);
        $this->assertSame('Alpha', $byName['data'][0]->getName()->getValue());
        $this->assertSame(1, $byEmail['total']);
        $this->assertSame('charlie@search-me.com', $byEmail['data'][0]->getEmail()->getValue());
    }

    public function test_find_paginated_excludes_soft_deleted_by_default(): void
    {
        $keepId = $this->repository->save($this->makeUser('keep@example.com', 'Keep'));
        $dropId = $this->repository->save($this->makeUser('drop@example.com', 'Drop'));
        $this->repository->delete($dropId);

        $result = $this->repository->findPaginated(1, 10);

        $this->assertSame(1, $result['total']);
        $this->assertSame($keepId, $result['data'][0]->getId());
    }

    // ------------------------------------------------------------------
    // count helpers
    // ------------------------------------------------------------------

    public function test_count_total_ignores_soft_deleted(): void
    {
        $this->repository->save($this->makeUser('a@example.com', 'Alpha User'));
        $b = $this->repository->save($this->makeUser('b@example.com', 'Beta User'));
        $this->repository->save($this->makeUser('c@example.com', 'Gamma User'));
        $this->repository->delete($b);

        $this->assertSame(2, $this->repository->countTotal());
    }

    public function test_count_by_role_and_status_isolate_correctly(): void
    {
        $this->repository->save($this->makeUser('a1@example.com', 'Admin One', UserRole::Admin));
        $this->repository->save($this->makeUser('a2@example.com', 'Admin Two', UserRole::Admin, UserStatus::Inactive));
        $this->repository->save($this->makeUser('c1@example.com', 'Customer One', UserRole::Customer));

        $this->assertSame(2, $this->repository->countByRole('admin'));
        $this->assertSame(1, $this->repository->countByRole('customer'));
        $this->assertSame(2, $this->repository->countByStatus('active'));
        $this->assertSame(1, $this->repository->countByStatus('inactive'));
    }

    public function test_find_paginated_includes_inactive_when_flag_set(): void
    {
        $keepId = $this->repository->save($this->makeUser('alive@example.com', 'Alive'));
        $dropId = $this->repository->save($this->makeUser('dead@example.com', 'Dead'));
        $this->repository->delete($dropId);

        $result = $this->repository->findPaginated(1, 10, includeInactive: true);

        $ids = array_map(static fn ($u) => $u->getId(), $result['data']);
        $this->assertContains($keepId, $ids);
        $this->assertContains($dropId, $ids);
        $this->assertSame(2, $result['total']);
    }

    public function test_find_paginated_filters_by_status(): void
    {
        $this->repository->save($this->makeUser('active@example.com', 'Active', UserRole::Customer));
        $this->repository->save($this->makeUser(
            'inactive@example.com',
            'Inactive',
            UserRole::Customer,
            UserStatus::Inactive
        ));

        $inactive = $this->repository->findPaginated(1, 10, false, '', null, 'inactive');

        $this->assertSame(1, $inactive['total']);
        $this->assertSame(UserStatus::Inactive, $inactive['data'][0]->getStatus());
    }

    // ------------------------------------------------------------------
    // error-path catches (model-backed mocks)
    // ------------------------------------------------------------------

    public function test_save_rethrows_when_insert_returns_false(): void
    {
        $model = $this->createMock(UserModel::class);
        $model->method('insert')->willReturn(false);

        $repo = $this->makeRepoWithModel($model);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to save user');

        $repo->save($this->makeUser('insert-false@example.com', 'Insert False'));
    }

    public function test_save_logs_and_rethrows_model_exception(): void
    {
        $model = $this->createMock(UserModel::class);
        $model->method('insert')->willThrowException(new \RuntimeException('insert exploded'));

        $repo = $this->makeRepoWithModel($model);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('insert exploded');

        $repo->save($this->makeUser('boom@example.com', 'Boom'));
    }

    public function test_find_by_id_returns_null_on_model_exception(): void
    {
        $model = $this->createMock(UserModel::class);
        $model->method('find')->willThrowException(new \RuntimeException('storage down'));

        $repo = $this->makeRepoWithModel($model);

        $this->assertNull($repo->findById(123));
    }

    public function test_find_by_email_returns_null_on_model_exception(): void
    {
        $model = $this->getMockBuilder(UserModel::class)
            ->onlyMethods(['first'])
            ->getMock();
        $model->method('first')->willThrowException(new \RuntimeException('first failed'));

        $repo = $this->makeRepoWithModel($model);

        $this->assertNull($repo->findByEmail(Email::fromString('miss@example.com')));
    }

    public function test_update_logs_and_rethrows_model_exception(): void
    {
        $model = $this->createMock(UserModel::class);
        $model->method('update')->willThrowException(new \RuntimeException('update failed'));

        $repo = $this->makeRepoWithModel($model);
        $user = $this->makePersistedUser(42, 'upd@example.com', 'Upd');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('update failed');

        $repo->update($user);
    }

    public function test_delete_returns_false_when_model_throws(): void
    {
        $model = $this->createMock(UserModel::class);
        $model->method('delete')->willThrowException(new \RuntimeException('delete failed'));

        $repo = $this->makeRepoWithModel($model);

        $this->assertFalse($repo->delete(99));
    }

    public function test_find_paginated_rethrows_when_builder_throws(): void
    {
        $model = $this->createMock(UserModel::class);
        $model->method('builder')->willThrowException(new \RuntimeException('paginate broken'));

        $repo = $this->makeRepoWithModel($model);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paginate broken');

        $repo->findPaginated(1, 10);
    }

    public function test_count_total_returns_zero_on_exception(): void
    {
        $model = $this->getMockBuilder(UserModel::class)
            ->onlyMethods(['countAllResults'])
            ->getMock();
        $model->method('countAllResults')->willThrowException(new \RuntimeException('count exploded'));

        $repo = $this->makeRepoWithModel($model);

        $this->assertSame(0, $repo->countTotal());
    }

    public function test_count_by_role_returns_zero_on_exception(): void
    {
        $model = $this->getMockBuilder(UserModel::class)
            ->onlyMethods(['countAllResults'])
            ->getMock();
        $model->method('countAllResults')->willThrowException(new \RuntimeException('role count exploded'));

        $repo = $this->makeRepoWithModel($model);

        $this->assertSame(0, $repo->countByRole('admin'));
    }

    public function test_count_by_status_returns_zero_on_exception(): void
    {
        $model = $this->getMockBuilder(UserModel::class)
            ->onlyMethods(['countAllResults'])
            ->getMock();
        $model->method('countAllResults')->willThrowException(new \RuntimeException('status count exploded'));

        $repo = $this->makeRepoWithModel($model);

        $this->assertSame(0, $repo->countByStatus('active'));
    }

    public function test_slow_query_warning_branch_is_exercised(): void
    {
        // Threshold of -1 ms guarantees logQuery() treats every call as slow,
        // exercising the warning emit path inside logQuery().
        $logger = LoggerFactory::create('test.user.repository.slow');
        $cfg = config('Logging');
        $cfg->slowQueryThresholdMs = -1;

        $repo = new UserRepository(new UserModel(), $logger, $cfg);
        $id = $repo->save($this->makeUser('slow@example.com', 'Slow Query'));

        $this->assertNotNull($repo->findById($id));
        $this->assertNotNull($repo->findByEmail(Email::fromString('slow@example.com')));
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function makeUser(
        string $email,
        string $name,
        UserRole $role = UserRole::Customer,
        UserStatus $status = UserStatus::Active,
    ): User {
        $user = User::create(
            name: UserName::fromString($name),
            email: Email::fromString($email),
            hashedPassword: HashedPassword::fromHash(password_hash('Some-Strong-Password-1!', PASSWORD_BCRYPT)),
            role: $role,
        );

        if ($status !== UserStatus::Active) {
            // Status defaults to Active in the entity factory; the only way
            // to seed a non-active row here is via update() which also bumps
            // updated_at.
            $user->update(
                UserName::fromString($name),
                Email::fromString($email),
                $role,
                $status,
            );
        }

        return $user;
    }

    private function makeRepoWithModel(UserModel $model): UserRepository
    {
        $logger = LoggerFactory::create('test.user.repository.error');
        $cfg = config('Logging');

        return new UserRepository($model, $logger, $cfg);
    }

    private function makePersistedUser(int $id, string $email, string $name): User
    {
        return User::reconstitute(
            id: $id,
            name: UserName::fromString($name),
            email: Email::fromString($email),
            hashedPassword: HashedPassword::fromHash(password_hash('Some-Strong-Password-1!', PASSWORD_BCRYPT)),
            role: UserRole::Customer,
            status: UserStatus::Active,
            failedLoginAttempts: 0,
            lockedUntil: null,
            createdAt: new \DateTimeImmutable('-1 hour'),
            updatedAt: null,
            deletedAt: null,
        );
    }
}
