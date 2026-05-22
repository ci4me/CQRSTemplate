<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Domain\User\Repositories\PasswordHistoryRepository;
use App\Domain\User\Repositories\UserRepository;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Persistence\Models\UserModel;
use Config\Database;
use Tests\Support\Factories\UserFactory;
use Tests\Support\IntegrationTestCase;

/**
 * Integration tests for PasswordHistoryRepository.
 *
 * Pin the contract of the password-reuse table against a real database:
 * insert, retrieve, hash/plaintext containment checks, pruning to MAX_HISTORY_COUNT,
 * count, and per-user wipe.
 */
final class PasswordHistoryRepositoryTest extends IntegrationTestCase
{
    private PasswordHistoryRepository $repository;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var \CodeIgniter\Database\BaseConnection<object|resource|false, object|resource|false> $db */
        $db = Database::connect();
        $this->repository = new PasswordHistoryRepository($db);

        $logger = LoggerFactory::create('test.user.repository');
        $this->userRepository = new UserRepository(new UserModel(), $logger, config('Logging'));
    }

    /**
     * Persist a real user (FK requirement for password_history) and return its id.
     */
    private function createUserId(string $email): int
    {
        return $this->userRepository->save(UserFactory::createUser([
            'name' => 'PWHist User',
            'email' => $email,
            'password' => 'StrongP@ssw0rd!',
        ]));
    }

    public function test_store_persists_hash_and_returns_insert_id(): void
    {
        $userId = $this->createUserId('store@example.com');
        $hash = password_hash('First-Password-1!', PASSWORD_ARGON2ID);

        $id = $this->repository->store($userId, $hash);

        $this->assertGreaterThan(0, $id);
        $this->seeInDatabase('password_history', [
            'id' => $id,
            'user_id' => $userId,
            'password_hash' => $hash,
        ]);
    }

    public function test_get_last_n_hashes_returns_recent_subset(): void
    {
        $userId = $this->createUserId('lastn@example.com');
        $hashes = [];
        for ($i = 1; $i <= 3; $i++) {
            $hashes[$i] = password_hash("Pass-{$i}!", PASSWORD_ARGON2ID);
            $this->repository->store($userId, $hashes[$i]);
        }

        $latestTwo = $this->repository->getLastNHashes($userId, 2);

        $this->assertCount(2, $latestTwo);
        foreach ($latestTwo as $hash) {
            $this->assertContains($hash, $hashes);
        }
    }

    public function test_get_last_n_hashes_returns_empty_for_unknown_user(): void
    {
        $this->assertSame([], $this->repository->getLastNHashes(99999));
    }

    public function test_contains_hash_matches_existing_entry(): void
    {
        $userId = $this->createUserId('contains@example.com');
        $hash = password_hash('Constant-Time-1!', PASSWORD_ARGON2ID);
        $this->repository->store($userId, $hash);

        $this->assertTrue($this->repository->containsHash($userId, $hash));
        $this->assertFalse($this->repository->containsHash($userId, 'unknown-hash-string'));
    }

    public function test_contains_password_matches_plaintext_against_stored_hashes(): void
    {
        $userId = $this->createUserId('containspw@example.com');
        $plaintext = 'Stored-Password-9!';
        $this->repository->store($userId, password_hash($plaintext, PASSWORD_ARGON2ID));

        $this->assertTrue($this->repository->containsPassword($userId, $plaintext));
        $this->assertFalse($this->repository->containsPassword($userId, 'Other-Password-9!'));
    }

    public function test_store_prunes_history_above_max_count(): void
    {
        $userId = $this->createUserId('prune@example.com');

        // Seven inserts; only last 5 should remain
        for ($i = 1; $i <= 7; $i++) {
            $this->repository->store($userId, password_hash("Round-{$i}-Password-1!", PASSWORD_ARGON2ID));
            usleep(1100);
        }

        $this->assertSame(5, $this->repository->countByUserId($userId));
    }

    public function test_delete_by_user_id_removes_all_entries(): void
    {
        $userId = $this->createUserId('wipe@example.com');
        $this->repository->store($userId, password_hash('A1!aaaaaaaaaa', PASSWORD_ARGON2ID));
        $this->repository->store($userId, password_hash('B2@bbbbbbbbbb', PASSWORD_ARGON2ID));

        $deleted = $this->repository->deleteByUserId($userId);

        $this->assertGreaterThanOrEqual(2, $deleted);
        $this->assertSame(0, $this->repository->countByUserId($userId));
    }

    public function test_count_by_user_id_returns_zero_for_empty_user(): void
    {
        $this->assertSame(0, $this->repository->countByUserId(424242));
    }
}
