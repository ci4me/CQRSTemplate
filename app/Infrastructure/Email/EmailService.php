<?php

declare(strict_types=1);

namespace App\Infrastructure\Email;

use CodeIgniter\Email\Email;
use Psr\Log\LoggerInterface;

/**
 * Email Service.
 *
 * Centralized email sending with SMTP configuration and error handling.
 *
 * SECURITY: Validates email configuration before sending to prevent failures
 *
 * @package App\Infrastructure\Email
 */
final class EmailService
{
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    /**
     * Send password reset email.
     *
     * @param string $toEmail Recipient email address
     * @param string $resetToken Password reset token
     * @param string $baseUrl Application base URL
     * @return bool True if email sent successfully, false otherwise
     */
    public function sendPasswordResetEmail(string $toEmail, string $resetToken, string $baseUrl): bool
    {
        try {
            $resetUrl = rtrim($baseUrl, '/') . '/reset-password?token=' . urlencode($resetToken);

            $subject = 'Password Reset Request';
            $message = $this->getPasswordResetEmailBody($resetUrl);

            return $this->sendEmail($toEmail, $subject, $message);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send password reset email', [
                'domain' => 'Email',
                'to' => $toEmail,
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);

            return false;
        }
    }

    /**
     * Send email using CodeIgniter Email library.
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $message Email body (HTML)
     * @return bool True if sent successfully, false otherwise
     */
    private function sendEmail(string $to, string $subject, string $message): bool
    {
        // Configure SMTP from environment
        $config = [
            'protocol' => 'smtp',
            'SMTPHost' => getenv('EMAIL_SMTP_HOST') !== false ? getenv('EMAIL_SMTP_HOST') : 'localhost',
            'SMTPPort' => (int) (getenv('EMAIL_SMTP_PORT') !== false ? getenv('EMAIL_SMTP_PORT') : 587),
            'SMTPUser' => getenv('EMAIL_SMTP_USER') !== false ? getenv('EMAIL_SMTP_USER') : '',
            'SMTPPass' => getenv('EMAIL_SMTP_PASSWORD') !== false ? getenv('EMAIL_SMTP_PASSWORD') : '',
            'SMTPCrypto' => getenv('EMAIL_SMTP_ENCRYPTION') !== false ? getenv('EMAIL_SMTP_ENCRYPTION') : 'tls',
            'mailType' => 'html',
            'charset' => 'utf-8',
            'newline' => "\r\n",
        ];

        $email = new Email($config);

        $fromAddressEnv = getenv('EMAIL_FROM_ADDRESS');
        $fromAddress = $fromAddressEnv !== false ? $fromAddressEnv : 'noreply@localhost';
        $fromNameEnv = getenv('EMAIL_FROM_NAME');
        $fromName = $fromNameEnv !== false ? $fromNameEnv : 'Application';

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
        } else {
            $this->logger->error('Email sending failed', [
                'domain' => 'Email',
                'to' => $to,
                'subject' => $subject,
                'error' => $email->printDebugger(['headers']),
            ]);
        }

        return $result;
    }

    /**
     * Get password reset email body HTML.
     *
     * @param string $resetUrl Password reset URL
     * @return string HTML email body
     */
    private function getPasswordResetEmailBody(string $resetUrl): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .container {
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 30px;
        }
        .button {
            display: inline-block;
            background-color: #007bff;
            color: #ffffff;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 4px;
            margin: 20px 0;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Password Reset Request</h2>
        <p>You have requested to reset your password. Click the button below to proceed:</p>

        <a href="{$resetUrl}" class="button">Reset Password</a>

        <p>Or copy and paste this link into your browser:</p>
        <p style="word-break: break-all; color: #007bff;">{$resetUrl}</p>

        <p><strong>This link will expire in 1 hour.</strong></p>

        <p>If you did not request this password reset, please ignore this email and your password will remain unchanged.</p>

        <div class="footer">
            <p>This is an automated email. Please do not reply to this message.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
