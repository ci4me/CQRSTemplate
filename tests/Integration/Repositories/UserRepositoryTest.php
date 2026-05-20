<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Domain\User\Entities\User;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Domain\User\ValueObjects\UserStatus;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Persistence\Models\UserModel;
use App\Infrastructure\Persistence\Repositories\UserRepository;
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
}
