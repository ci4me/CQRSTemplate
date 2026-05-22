<?php

declare(strict_types=1);

namespace Tests\Integration\Auth\Commands;

use App\Domain\User\Ports\TokenBlacklistInterface;
use App\Infrastructure\Auth\Commands\LogoutUser\LogoutUserCommand;
use App\Infrastructure\Auth\Commands\LogoutUser\LogoutUserHandler;
use App\Infrastructure\Auth\Services\SessionManagementService;
use App\Infrastructure\Logging\LoggerFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\IntegrationTestCase;

/**
 * Integration tests for LogoutUserHandler.
 *
 * Targets the JTI extractor branches (malformed/blanked refresh, missing
 * userId, malformed access token) that Phase A's feature tests don't hit.
 * SessionManagementService is final, so we use a real instance and a stub
 * blacklist to verify call counts.
 */
#[AllowMockObjectsWithoutExpectations]
final class LogoutUserHandlerTest extends IntegrationTestCase
{
    private TokenBlacklistInterface $blacklist;
    private LogoutUserHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blacklist = $this->createMock(TokenBlacklistInterface::class);
        $this->handler = new LogoutUserHandler(
            $this->blacklist,
            new SessionManagementService(),
            LoggerFactory::create('test.auth.logout'),
        );
    }

    public function test_blacklists_access_and_refresh_tokens(): void
    {
        $access = $this->makeJwt(['jti' => 'access-1']);
        $refresh = $this->makeJwt(['jti' => 'refresh-1']);

        $this->blacklist->expects($this->exactly(2))->method('blacklist');

        $this->handler->handle(new LogoutUserCommand(
            token: $access,
            refreshToken: $refresh,
            userId: 7,
        ));
    }

    public function test_skips_refresh_blacklist_when_null(): void
    {
        $access = $this->makeJwt(['jti' => 'access-2']);

        $this->blacklist->expects($this->once())->method('blacklist')->with($access);

        $this->handler->handle(new LogoutUserCommand(
            token: $access,
            refreshToken: null,
            userId: 9,
        ));
    }

    public function test_skips_refresh_blacklist_when_blank(): void
    {
        $access = $this->makeJwt(['jti' => 'access-3']);

        $this->blacklist->expects($this->once())->method('blacklist')->with($access);

        $this->handler->handle(new LogoutUserCommand(
            token: $access,
            refreshToken: '',
            userId: 11,
        ));
    }

    public function test_skips_session_revoke_when_user_id_null(): void
    {
        $this->blacklist->expects($this->once())->method('blacklist');

        $this->handler->handle(new LogoutUserCommand(
            token: $this->makeJwt(['jti' => 'orphan']),
            refreshToken: null,
            userId: null,
        ));
    }

    public function test_malformed_token_skips_session_revoke(): void
    {
        $this->blacklist->expects($this->once())->method('blacklist');

        // Not three dot-segments — extractJti returns null
        $this->handler->handle(new LogoutUserCommand(
            token: 'not.a.jwt.token',
            refreshToken: null,
            userId: 12,
        ));
    }

    public function test_invalid_base64_payload_skips_session_revoke(): void
    {
        $this->blacklist->expects($this->once())->method('blacklist');

        // Three segments but middle is invalid base64 chars
        $this->handler->handle(new LogoutUserCommand(
            token: 'aaaa.****.bbbb',
            refreshToken: null,
            userId: 13,
        ));
    }

    public function test_payload_without_jti_skips_session_revoke(): void
    {
        $this->blacklist->expects($this->once())->method('blacklist');

        $this->handler->handle(new LogoutUserCommand(
            token: $this->makeJwt(['sub' => 99]),
            refreshToken: null,
            userId: 14,
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function makeJwt(array $payload): string
    {
        $base64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $header = $base64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = $base64(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $base64('signature');

        return $header . '.' . $body . '.' . $signature;
    }
}
