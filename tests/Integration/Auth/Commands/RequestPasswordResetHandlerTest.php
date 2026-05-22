<?php

declare(strict_types=1);

namespace Tests\Integration\Auth\Commands;

use App\Domain\User\Entities\User;
use App\Domain\User\Repositories\UserRepository;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Infrastructure\Auth\Commands\RequestPasswordReset\RequestPasswordResetCommand;
use App\Infrastructure\Auth\Commands\RequestPasswordReset\RequestPasswordResetHandler;
use App\Infrastructure\Email\EmailService;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\IntegrationTestCase;

/**
 * Integration tests for RequestPasswordResetHandler covering the
 * email-success log path, email-failure log path, and the silent-failure
 * catch block (e.g. invalid email format).
 *
 * The handler intentionally swallows all errors to prevent user enumeration;
 * tests therefore assert side-effects (DB state, email-transport
 * invocations) rather than exception propagation.
 */
final class RequestPasswordResetHandlerTest extends IntegrationTestCase
{
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $logger = LoggerFactory::create('test.auth.request-reset');
        $this->userRepository = new UserRepository(new UserModel(), $logger, config('Logging'));
    }

    public function test_generates_token_and_dispatches_email_for_known_user(): void
    {
        $email = 'reset-known@example.com';
        $userId = $this->createUser($email);
        $captured = [];

        $emailService = new EmailService(LoggerFactory::create('test.auth.email'));
        $emailService->setTransport(function (string $to, string $subject) use (&$captured): bool {
            $captured[] = ['to' => $to, 'subject' => $subject];
            return true;
        });

        $handler = new RequestPasswordResetHandler($this->userRepository, $emailService);
        $handler->handle(new RequestPasswordResetCommand(email: $email));

        $this->assertSame([['to' => $email, 'subject' => 'Password Reset Request']], $captured);
        $this->seeInDatabase('password_reset_tokens', ['user_id' => $userId]);
    }

    public function test_email_failure_branch_logs_but_does_not_throw(): void
    {
        $email = 'reset-bounce@example.com';
        $userId = $this->createUser($email);

        $emailService = new EmailService(LoggerFactory::create('test.auth.email'));
        $emailService->setTransport(static fn (): bool => false);

        $handler = new RequestPasswordResetHandler($this->userRepository, $emailService);
        $handler->handle(new RequestPasswordResetCommand(email: $email));

        // The token should still be stored even when email delivery fails
        $this->seeInDatabase('password_reset_tokens', ['user_id' => $userId]);
    }

    public function test_unknown_email_is_silent_no_token_stored(): void
    {
        $emailService = new EmailService(LoggerFactory::create('test.auth.email'));
        $invoked = false;
        $emailService->setTransport(function () use (&$invoked): bool {
            $invoked = true;
            return true;
        });

        $handler = new RequestPasswordResetHandler($this->userRepository, $emailService);
        $handler->handle(new RequestPasswordResetCommand(email: 'nobody@example.com'));

        $this->assertFalse($invoked);
        $this->dontSeeInDatabase('password_reset_tokens', []);
    }

    public function test_invalid_email_format_is_caught_silently(): void
    {
        $emailService = new EmailService(LoggerFactory::create('test.auth.email'));
        $emailService->setTransport(static fn (): bool => true);

        $handler = new RequestPasswordResetHandler($this->userRepository, $emailService);
        // ValidationException from Email::fromString is swallowed by the outer
        // catch block — handler returns void without throwing.
        $handler->handle(new RequestPasswordResetCommand(email: 'not-an-email'));

        $this->dontSeeInDatabase('password_reset_tokens', []);
    }

    public function test_existing_token_is_replaced(): void
    {
        $email = 'reset-replace@example.com';
        $userId = $this->createUser($email);

        $emailService = new EmailService(LoggerFactory::create('test.auth.email'));
        $emailService->setTransport(static fn (): bool => true);

        $handler = new RequestPasswordResetHandler($this->userRepository, $emailService);
        $handler->handle(new RequestPasswordResetCommand(email: $email));
        $handler->handle(new RequestPasswordResetCommand(email: $email));

        // Only the most-recent token row should remain
        /** @var \CodeIgniter\Database\BaseConnection<object|resource|false, object|resource|false> $db */
        $db = \Config\Database::connect();
        $count = (int) $db->table('password_reset_tokens')->where('user_id', $userId)->countAllResults();
        $this->assertSame(1, $count);
    }

    private function createUser(string $email): int
    {
        $user = User::create(
            name: UserName::fromString('Reset User'),
            email: Email::fromString($email),
            hashedPassword: HashedPassword::fromPlaintext('StrongP@ssw0rd!'),
            role: UserRole::Customer,
        );

        return $this->userRepository->save($user);
    }
}
