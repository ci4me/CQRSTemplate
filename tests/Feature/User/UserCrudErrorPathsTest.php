<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Domain\User\Entities\User;
use App\Domain\User\Repositories\UserRepository;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use Tests\Support\FeatureTestCase;

/**
 * Drives the validation/domain-error branches on UserController that the
 * happy-path tests in UserCrudTest don't exercise: duplicate email on
 * store/update, missing-id on edit/show/reset-password, and DomainException
 * on delete.
 */
final class UserCrudErrorPathsTest extends FeatureTestCase
{
    private const string TEST_PASSWORD = 'ValidPass123!@#';

    protected function setUp(): void
    {
        parent::setUp();
        \CodeIgniter\Config\Services::cache()->clean();
    }

    public function test_admin_store_duplicate_email_flashes_error(): void
    {
        $email = 'dup-store@test.local';
        $this->createUser($email, self::TEST_PASSWORD);

        $result = $this->post('/admin/users', [
            'name' => 'Second Same Email',
            'email' => $email,
            'password' => self::TEST_PASSWORD,
            'role' => 'customer',
        ]);

        $result->assertRedirect();
        $this->assertFlashMessage('error');
    }

    public function test_admin_edit_missing_user_redirects_to_index(): void
    {
        $result = $this->get('/admin/users/99999/edit');

        $result->assertRedirect();
        $location = (string) $result->getRedirectUrl();
        $this->assertStringContainsString('/admin/users', $location);
    }

    public function test_admin_update_invalid_payload_redirects_back(): void
    {
        $user = $this->createUser('upd-invalid@test.local', self::TEST_PASSWORD);
        $userId = (int) $user->getId();

        $result = $this->post('/admin/users/' . $userId, [
            'name' => '',           // invalid: too short
            'email' => 'not-email', // invalid: malformed
            'role' => 'customer',
            'status' => 'active',
        ]);

        $result->assertRedirect();
        $this->assertFlashMessage('error');
    }

    public function test_admin_update_missing_user_id_yields_error(): void
    {
        $result = $this->post('/admin/users/99999', [
            'name' => 'Ghost User',
            'email' => 'ghost@test.local',
            'role' => 'customer',
            'status' => 'active',
        ]);

        $result->assertRedirect();
        $this->assertFlashMessage('error');
    }

    public function test_admin_delete_missing_user_yields_error_flash(): void
    {
        $result = $this->post('/admin/users/99999/delete');

        $result->assertRedirect();
        $this->assertFlashMessage('error');
    }

    public function test_admin_reset_password_missing_user_redirects_to_index(): void
    {
        $result = $this->get('/admin/users/99999/reset-password');

        $result->assertRedirect();
        $location = (string) $result->getRedirectUrl();
        $this->assertStringContainsString('/admin/users', $location);
    }

    public function test_admin_store_password_invalid_redirects_back(): void
    {
        $user = $this->createUser('reset-weak@test.local', self::TEST_PASSWORD);
        $userId = (int) $user->getId();

        $result = $this->post('/admin/users/' . $userId . '/reset-password', [
            'new_password' => 'weak', // fails complexity rules
        ]);

        $result->assertRedirect();
        $this->assertFlashMessage('error');
    }

    private function createUser(string $email, string $password, UserRole $role = UserRole::Customer): User
    {
        $user = User::create(
            name: UserName::fromString('Test User'),
            email: Email::fromString($email),
            hashedPassword: HashedPassword::fromPlaintext($password),
            role: $role,
        );

        $repository = $this->getUserRepository();
        $userId = $repository->save($user);

        $found = $repository->findById($userId);
        if ($found === null) {
            throw new \RuntimeException('Failed to persist user during test setup');
        }

        return $found;
    }

    private function getUserRepository(): UserRepository
    {
        $repo = \Config\Services::repository('userRepository');
        assert($repo instanceof UserRepository);

        return $repo;
    }
}
