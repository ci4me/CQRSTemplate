<?php

declare(strict_types=1);

namespace App\Infrastructure\Email;

use CodeIgniter\Email\Email;
use Psr\Log\LoggerInterface;

/**
 * Centralised email sending (D13).
 *
 * Templates live under `app/Views/emails/` and inherit from
 * `emails/layout`. Domain code calls {@see self::sendTemplate()} with a
 * view path and a payload array — the inline-HTML smell from earlier
 * versions is gone.
 *
 * SMTP configuration comes from environment variables; tests inject a
 * fake transport via {@see self::setTransport()} so the suite doesn't
 * try to open a real socket.
 */
final class EmailService
{
    /**
     * Closure of signature (string $to, string $subject, string $html): bool
     *
     * @var (callable(string, string, string): bool)|null
     */
    private $transport = null;

    /**
     * __construct.
     *
     * @param LoggerInterface $logger
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Render an email template and send the resulting HTML.
     *
     * @param string               $toEmail Recipient address (single)
     * @param string               $subject Subject line
     * @param string               $view    View path under app/Views/, e.g. 'emails/auth/password_reset'
     * @param array<string, mixed> $payload View variables (must include $title for the layout)
     * @return bool
     */
    public function sendTemplate(
        string $toEmail,
        string $subject,
        string $view,
        array $payload = []
    ): bool {
        try {
            $payload['title'] ??= $subject;
            $html = view($view, $payload);
            return $this->sendEmail($toEmail, $subject, $html);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to render or send templated email', [
                'domain' => 'Email',
                'to' => $toEmail,
                'view' => $view,
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
            return false;
        }
    }

    /**
     * Convenience wrapper that builds the password-reset URL and renders
     * the corresponding template. Preserved for backward compatibility
     * with existing call sites in the auth flow.
     *
     * @param string $toEmail
     * @param string $resetToken
     * @param string $baseUrl
     * @return bool
     */
    public function sendPasswordResetEmail(string $toEmail, string $resetToken, string $baseUrl): bool
    {
        $resetUrl = rtrim($baseUrl, '/') . '/reset-password?token=' . urlencode($resetToken);

        return $this->sendTemplate(
            toEmail: $toEmail,
            subject: 'Password Reset Request',
            view: 'emails/auth/password_reset',
            payload: [
                'resetUrl' => $resetUrl,
                'expiresInMinutes' => 60,
            ]
        );
    }

    /**
     * Override the underlying transport. Intended for testing — production
     * callers leave this alone and the service falls back to CI4's Email.
     *
     * @param callable|null $transport * @param (callable(string, string, string): bool)|null $transport
     * @return void
     */
    public function setTransport(?callable $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * sendEmail.
     *
     * @param string $to
     * @param string $subject
     * @param string $message
     * @return bool
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function sendEmail(string $to, string $subject, string $message): bool
    {
        if ($this->transport !== null) {
            return $this->dispatchViaTransport($to, $subject, $message);
        }

        return $this->dispatchViaCodeIgniter($to, $subject, $message);
    }

    /**
     * dispatchViaTransport.
     *
     * @param string $to
     * @param string $subject
     * @param string $message
     * @return bool
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function dispatchViaTransport(string $to, string $subject, string $message): bool
    {
        $transport = $this->transport;
        if ($transport === null) {
            return false;
        }
        $result = $transport($to, $subject, $message);

        $this->logger->info('Email dispatched via injected transport', [
            'domain' => 'Email',
            'to' => $to,
            'subject' => $subject,
            'ok' => $result,
        ]);

        return $result;
    }

    /**
     * dispatchViaCodeIgniter.
     *
     * @param string $to
     * @param string $subject
     * @param string $message
     * @return bool
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function dispatchViaCodeIgniter(string $to, string $subject, string $message): bool
    {
        $email = new Email($this->buildConfig());
        [$fromAddress, $fromName] = $this->resolveFrom();

        $email->setFrom($fromAddress, $fromName);
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($message);

        $result = $email->send();

        if ($result) {
            $this->logger->info('Email sent successfully', [
                'domain' => 'Email',
                'to' => $to,
                'subject' => $subject,
            ]);
            return true;
        }

        $this->logger->error('Email sending failed', [
            'domain' => 'Email',
            'to' => $to,
            'subject' => $subject,
            'error' => $email->printDebugger(['headers']),
        ]);
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildConfig(): array
    {
        return [
            'protocol' => 'smtp',
            'SMTPHost' => $this->envOr('EMAIL_SMTP_HOST', 'localhost'),
            'SMTPPort' => (int) $this->envOr('EMAIL_SMTP_PORT', '587'),
            'SMTPUser' => $this->envOr('EMAIL_SMTP_USER', ''),
            'SMTPPass' => $this->envOr('EMAIL_SMTP_PASSWORD', ''),
            'SMTPCrypto' => $this->envOr('EMAIL_SMTP_ENCRYPTION', 'tls'),
            'mailType' => 'html',
            'charset' => 'utf-8',
            'newline' => "\r\n",
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveFrom(): array
    {
        return [
            $this->envOr('EMAIL_FROM_ADDRESS', 'noreply@localhost'),
            $this->envOr('EMAIL_FROM_NAME', 'Application'),
        ];
    }

    /**
     * envOr.
     *
     * @param string $key
     * @param string $default
     * @return string
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function envOr(string $key, string $default): string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}
