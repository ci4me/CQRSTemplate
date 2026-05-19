<?php
/**
 * Shared layout for transactional emails (D13).
 *
 * Email clients (Gmail, Outlook) ignore most modern CSS, so the layout
 * stays inline and minimal. Body markup is escaped via esc(); URLs go
 * through esc('attr', 'url') so they survive HTML encoding.
 *
 * Each template extends this layout via $this->extend('emails/layout') /
 * $this->section('content') ... $this->endSection().
 *
 * Variables in scope:
 *   $title        — used both in <title> and the H1
 *   $appName      — branding line at the top
 *   $supportEmail — shown in the footer (settable via SettingsService)
 */
$title ??= 'Notification';
$appName ??= 'ERP Template';
$supportEmail ??= 'noreply@localhost';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= esc($title) ?></title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f9f9f9; border: 1px solid #ddd; border-radius: 5px; padding: 30px;">
        <p style="margin: 0 0 10px 0; color: #888; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">
            <?= esc($appName) ?>
        </p>
        <h2 style="margin: 0 0 20px 0;"><?= esc($title) ?></h2>

        <?= $this->renderSection('content') ?>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666;">
            <p>This is an automated email. Please do not reply to this message.</p>
            <p>Questions? Contact <a href="mailto:<?= esc($supportEmail, 'attr') ?>"><?= esc($supportEmail) ?></a>.</p>
        </div>
    </div>
</body>
</html>
