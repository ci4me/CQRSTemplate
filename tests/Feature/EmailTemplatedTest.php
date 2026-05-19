<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Infrastructure\Email\EmailService;
use Psr\Log\NullLogger;
use Tests\Support\FeatureTestCase;

/**
 * Feature-level test for the templated email rendering (D13). Uses the
 * EmailService's transport hook to capture the rendered HTML instead of
 * actually delivering email.
 */
final class EmailTemplatedTest extends FeatureTestCase
{
    protected bool $authenticateByDefault = false;

    /**
     * CodeIgniter's view renderer pushes an output buffer that callers in
     * the request lifecycle would normally flush. Inside a unit-style test
     * the buffer is left open, which PHPUnit flags as "risky". Drain any
     * stragglers in tearDown so the assertions stay green.
     */
    private int $bufferLevelAtStart = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bufferLevelAtStart = ob_get_level();
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->bufferLevelAtStart) {
            ob_end_clean();
        }
        parent::tearDown();
    }

    public function test_password_reset_email_renders_template_with_payload(): void
    {
        $svc = new EmailService(new NullLogger());

        /** @var list<array{to: string, subject: string, html: string}> $captured */
        $captured = [];
        $svc->setTransport(static function (string $to, string $subject, string $html) use (&$captured): bool {
            $captured[] = ['to' => $to, 'subject' => $subject, 'html' => $html];
            return true;
        });

        $ok = $svc->sendPasswordResetEmail('alice@example.com', 'tok-123', 'https://app.example.com');

        $this->assertTrue($ok);
        $this->assertCount(1, $captured);
        $this->assertSame('alice@example.com', $captured[0]['to']);
        $this->assertSame('Password Reset Request', $captured[0]['subject']);
        $this->assertStringContainsString('https://app.example.com/reset-password?token=tok-123', $captured[0]['html']);
        $this->assertStringContainsString('60 minutes', $captured[0]['html']);
        $this->assertStringContainsString('ERP Template', $captured[0]['html']);
    }

    public function test_arbitrary_template_payload_is_passed_to_view(): void
    {
        $svc = new EmailService(new NullLogger());

        $captured = '';
        // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
        $svc->setTransport(static function (string $to, string $subject, string $html) use (&$captured): bool {
            $captured = $html;
            return true;
        });

        $ok = $svc->sendTemplate(
            toEmail: 'bob@example.com',
            subject: 'Test',
            view: 'emails/auth/password_reset',
            payload: [
                'resetUrl' => 'https://x.test/r?token=abc',
                'expiresInMinutes' => 15,
                'title' => 'Custom Title',
            ]
        );

        $this->assertTrue($ok);
        $this->assertStringContainsString('Custom Title', $captured);
        $this->assertStringContainsString('15 minutes', $captured);
    }

    public function test_render_error_is_logged_and_returns_false(): void
    {
        $svc = new EmailService(new NullLogger());
        $svc->setTransport(static fn(): bool => true);

        $ok = $svc->sendTemplate(
            toEmail: 'a@b.c',
            subject: 'x',
            view: 'emails/nonexistent/template'
        );

        $this->assertFalse($ok);
    }
}
