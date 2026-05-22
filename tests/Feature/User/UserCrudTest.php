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
 * E2E coverage for the admin User CRUD surface (/admin/users/*).
 *
 * Session-authenticated admin (set up by the base FeatureTestCase
 * via `loginAsAdmin()`) drives index / show / create / update /
 * delete. A separate test logs in as a customer to confirm the
 * role guard answers with a redirect away from the admin area.
 */
final class UserCrudTest extends FeatureTestCase
{
    private const string TEST_PASSWORD = 'ValidPass123!@#';

    protected function setUp(): void
    {
        parent::setUp();
        \CodeIgniter\Config\Services::cache()->clean();
    }

    public function test_admin_index_renders_users_list(): void
    {
        $result = $this->get('/admin/users');

        $result->assertOK();
        $result->assertSee('admin/users/index');
    }

    public function test_admin_index_supports_search_query_param(): void
    {
        $result = $this->get('/admin/users?search=admin');

        $result->assertOK();
    }

    public function test_admin_create_form_renders(): void
    {
        $result = $this->get('/admin/users/create');

        $result->assertOK();
        $result->assertSee('admin/users/create');
    }

    public function test_admin_show_displays_existing_user(): void
    {
        $user = $this->createUser('crud-show@test.local', self::TEST_PASSWORD);
        $userId = (int) $user->getId();

        $result = $this->get('/admin/users/' . $userId);

        $result->assertOK();
        $result->assertSee('admin/users/show');
    }

    public function test_admin_show_redirects_when_user_not_found(): void
    {
        $result = $this->get('/admin/users/99999');

        $result->assertRedirect();
        $location = (string) $result->getRedirectUrl();
        $this->assertStringContainsString('/admin/users', $location);
    }

    public function test_admin_store_creates_new_user(): void
    {
        $email = 'crud-store@test.local';
        $result = $this->post('/admin/users', [
            'name' => 'Newly Created',
            'email' => $email,
            'password' => self::TEST_PASSWORD,
            'role' => 'customer',
        ]);

        $result->assertRedirect();
        $this->assertDatabaseHas('users', [
            'email' => $email,
            'role' => 'customer',
        ]);
    }

    public function test_admin_store_flashes_error_on_invalid_payload(): void
    {
        $result = $this->post('/admin/users', [
            'name' => 'X', // too short
            'email' => 'not-an-email',
            'password' => 'short',
            'role' => 'customer',
        ]);

        $result->assertRedirect();
        $this->assertFlashMessage('error');
    }

    public function test_admin_edit_form_renders_for_existing_user(): void
    {
        $user = $this->createUser('crud-edit@test.local', self::TEST_PASSWORD);
        $userId = (int) $user->getId();

        $result = $this->get('/admin/users/' . $userId . '/edit');

        $result->assertOK();
        $result->assertSee('admin/users/edit');
    }

    public function test_admin_update_modifies_user(): void
    {
        $user = $this->createUser('crud-update@test.local', self::TEST_PASSWORD);
        $userId = (int) $user->getId();

        $result = $this->post('/admin/users/' . $userId, [
            'name' => 'Renamed User',
            'email' => 'crud-update-renamed@test.local',
            'role' => 'customer',
            'status' => 'active',
        ]);

        $result->assertRedirect();
        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => 'crud-update-renamed@test.local',
            'name' => 'Renamed User',
        ]);
    }

    public function test_admin_delete_soft_deletes_user(): void
    {
        $user = $this->createUser('crud-delete@test.local', self::TEST_PASSWORD);
        $userId = (int) $user->getId();

        $result = $this->post('/admin/users/' . $userId . '/delete');

        $result->assertRedirect();
        $this->assertFlashMessage('success', 'User deleted successfully');
    }

    public function test_admin_reset_password_form_renders(): void
    {
        $user = $this->createUser('crud-reset-pw@test.local', self::TEST_PASSWORD);
        $userId = (int) $user->getId();

        $result = $this->get('/admin/users/' . $userId . '/reset-password');

        $result->assertOK();
    }

    public function test_admin_reset_password_updates_hash(): void
    {
        $user = $this->createUser('crud-store-pw@test.local', self::TEST_PASSWORD);
        $userId = (int) $user->getId();

        $result = $this->post('/admin/users/' . $userId . '/reset-password', [
            'new_password' => 'BrandNewPass789!@#',
        ]);

        $result->assertRedirect();
        $this->assertFlashMessage('success', 'Password reset successfully');
    }

    public function test_non_admin_session_is_redirected_away_from_admin_area(): void
    {
        // Replace the test-trait $session array (which seeds $_SESSION on
        // every request) with a customer-role authentication, then hit
        // the admin route — the role:admin guard must refuse.
        $customer = $this->createUser('crud-customer@test.local', self::TEST_PASSWORD, UserRole::Customer);
        $this->withSession([
            'user_id' => $customer->getId(),
            'email' => $customer->getEmail()->getValue(),
            'role' => 'customer',
            'logged_in' => true,
        ]);

        $result = $this->get('/admin/users');

        // The role:admin filter answers with a 403 JSON or a redirect
        // depending on request type; either way it is NOT a 200 view.
        $this->assertNotSame(200, $result->response()->getStatusCode());
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
