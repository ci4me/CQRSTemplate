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
 * E2E coverage for the public self-registration surface.
 *
 * The web form posts to /auth/register; the JSON API exposes
 * /api/v1/auth/register. Both end up running RegisterUserHandler,
 * which hard-codes the role to `customer` for security.
 */
final class UserRegistrationTest extends FeatureTestCase
{
    protected bool $authenticateByDefault = false;

    private const string STRONG_PASSWORD = 'ValidPass123!@#';

    protected function setUp(): void
    {
        parent::setUp();
        \CodeIgniter\Config\Services::cache()->clean();
        $this->mockSession();
    }

    public function test_api_register_creates_customer_user(): void
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/register', [
            'name' => 'Newbie Customer',
            'email' => 'register-ok@test.local',
            'password' => self::STRONG_PASSWORD,
            'role' => 'customer',
        ]);

        $response->assertStatus(201);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('user_id', $body['data']);
        $this->assertDatabaseHas('users', [
            'email' => 'register-ok@test.local',
            'role' => 'customer',
        ]);
    }

    public function test_api_register_rejects_self_assigned_admin_role(): void
    {
        // SECURITY: Even if the caller asks for admin, the handler
        // refuses self-registration with elevated privilege.
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/register', [
            'name' => 'Wannabe Admin',
            'email' => 'register-admin@test.local',
            'password' => self::STRONG_PASSWORD,
            'role' => 'admin',
        ]);

        $response->assertStatus(400);
        $this->assertDatabaseMissing('users', [
            'email' => 'register-admin@test.local',
        ]);
    }

    public function test_api_register_rejects_duplicate_email(): void
    {
        $email = 'register-dup@test.local';
        $this->createCustomer($email, self::STRONG_PASSWORD);

        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/register', [
            'name' => 'Duplicate Email',
            'email' => $email,
            'password' => self::STRONG_PASSWORD,
            'role' => 'customer',
        ]);

        // RegisterUserHandler throws DomainException (mapped to 400)
        // with a generic message to avoid user enumeration.
        $response->assertStatus(400);
    }

    public function test_api_register_rejects_weak_password(): void
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/register', [
            'name' => 'Weak Password',
            'email' => 'register-weak@test.local',
            'password' => 'short',
            'role' => 'customer',
        ]);

        // Password complexity is enforced by HashedPassword::fromPlaintext.
        $this->assertContains(
            $response->response()->getStatusCode(),
            [400, 422],
            'weak password must be rejected with 400 or 422'
        );
        $this->assertDatabaseMissing('users', [
            'email' => 'register-weak@test.local',
        ]);
    }

    public function test_api_register_rejects_invalid_email_format(): void
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/register', [
            'name' => 'Bad Email',
            'email' => 'not-a-real-email',
            'password' => self::STRONG_PASSWORD,
            'role' => 'customer',
        ]);

        $this->assertContains(
            $response->response()->getStatusCode(),
            [400, 422]
        );
    }

    public function test_web_register_redirects_to_login_on_success(): void
    {
        $result = $this->post('/auth/register', [
            'name' => 'Web Registered',
            'email' => 'register-web@test.local',
            'password' => self::STRONG_PASSWORD,
            'role' => 'customer',
        ]);

        $result->assertRedirect();
        $location = (string) $result->getRedirectUrl();
        $this->assertStringContainsString('/auth/login', $location);
        $this->assertDatabaseHas('users', [
            'email' => 'register-web@test.local',
        ]);
    }

    public function test_web_register_flashes_error_when_email_already_registered(): void
    {
        // RegisterUserHandler throws DomainException for duplicate
        // emails, which the controller catches and converts to a
        // back-redirect with a flashed error message.
        $email = 'register-web-dup@test.local';
        $this->createCustomer($email, self::STRONG_PASSWORD);

        $result = $this->post('/auth/register', [
            'name' => 'Duplicate Web',
            'email' => $email,
            'password' => self::STRONG_PASSWORD,
            'role' => 'customer',
        ]);

        $result->assertRedirect();
        $this->assertFlashMessage('error');
    }

    public function test_register_form_view_renders(): void
    {
        $result = $this->get('/auth/register');

        $result->assertOK();
    }

    private function createCustomer(string $email, string $password): User
    {
        $user = User::create(
            name: UserName::fromString('Existing'),
            email: Email::fromString($email),
            hashedPassword: HashedPassword::fromPlaintext($password),
            role: UserRole::Customer,
        );

        $repo = \Config\Services::repository('userRepository');
        assert($repo instanceof UserRepository);
        $userId = $repo->save($user);

        $found = $repo->findById($userId);
        if ($found === null) {
            throw new \RuntimeException('Failed to persist user during test setup');
        }

        return $found;
    }
}
