<?php
/**
 * Password reset email body.
 *
 * Variables required:
 *   $resetUrl    — full URL with token query
 *   $expiresInMinutes — informational, defaults to 60
 *
 * Inherits the shared shell from emails/layout.
 */
$this->extend('emails/layout');
$expiresInMinutes ??= 60;
?>

<?= $this->section('content') ?>

<p>You have requested to reset your password. Click the button below to proceed:</p>

<p style="margin: 24px 0;">
    <a href="<?= esc($resetUrl, 'attr') ?>"
       style="display: inline-block; background-color: #007bff; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 4px;">
        Reset Password
    </a>
</p>

<p>Or copy and paste this link into your browser:</p>
<p style="word-break: break-all; color: #007bff;"><?= esc($resetUrl) ?></p>

<p><strong>This link will expire in <?= (int) $expiresInMinutes ?> minutes.</strong></p>

<p>If you did not request this password reset, please ignore this email and your password will remain unchanged.</p>

<?= $this->endSection() ?>
