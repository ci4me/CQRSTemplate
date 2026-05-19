<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Logging;

use App\Infrastructure\Logging\RedactingProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Support\UnitTestCase;

final class RedactingProcessorTest extends UnitTestCase
{
    private const string MASK = '***';

    public function test_redacts_password_field(): void
    {
        $record = $this->makeRecord(['password' => 'super-secret']);

        $out = (new RedactingProcessor())($record);

        $this->assertSame(self::MASK, $out->context['password']);
    }

    public function test_redacts_case_insensitively(): void
    {
        $record = $this->makeRecord(['Password' => 'x', 'AUTH_TOKEN' => 'y']);

        $out = (new RedactingProcessor())($record);

        $this->assertSame(self::MASK, $out->context['Password']);
        $this->assertSame(self::MASK, $out->context['AUTH_TOKEN']);
    }

    public function test_redacts_substring_matches(): void
    {
        // "Authorization" contains "authorization"; "refresh_token" contains "token"
        $record = $this->makeRecord([
            'Authorization' => 'Bearer abc',
            'refresh_token' => 'rt-xyz',
            'api_key' => 'k',
        ]);

        $out = (new RedactingProcessor())($record);

        $this->assertSame(self::MASK, $out->context['Authorization']);
        $this->assertSame(self::MASK, $out->context['refresh_token']);
        $this->assertSame(self::MASK, $out->context['api_key']);
    }

    public function test_redacts_nested_arrays(): void
    {
        $record = $this->makeRecord([
            'payload' => [
                'email' => 'a@b.c',
                'password' => 'x',
                'meta' => ['new_password' => 'y'],
            ],
        ]);

        $out = (new RedactingProcessor())($record);

        $this->assertSame('a@b.c', $out->context['payload']['email']);
        $this->assertSame(self::MASK, $out->context['payload']['password']);
        $this->assertSame(self::MASK, $out->context['payload']['meta']['new_password']);
    }

    public function test_leaves_non_sensitive_keys_unchanged(): void
    {
        $record = $this->makeRecord(['email' => 'a@b.c', 'user_id' => 7]);

        $out = (new RedactingProcessor())($record);

        $this->assertSame('a@b.c', $out->context['email']);
        $this->assertSame(7, $out->context['user_id']);
    }

    public function test_also_redacts_extra_fields(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'msg',
            context: [],
            extra: ['jwt' => 'eyJ...']
        );

        $out = (new RedactingProcessor())($record);

        $this->assertSame(self::MASK, $out->extra['jwt']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function makeRecord(array $context): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'msg',
            context: $context,
            extra: []
        );
    }
}
