<?php
/**
 * Shared auth-screen layout (E4).
 *
 * Used by login/register/forgot-password and any other anonymous view.
 * Kept deliberately minimal — no top bar, no sidebar, no notification
 * bell — because the visitor isn't authenticated yet. Carries:
 *  - locale-aware <html lang>
 *  - the project's existing auth.css (CSP-clean, no inline styles)
 *  - a flash partial so success / error messages render consistently
 *  - a single "card" wrapper that holds the page's form
 *
 * Pages extend with:
 *     $this->extend('layouts/auth');
 *     $this->section('content'); ... $this->endSection();
 */

$title ??= lang('App.app_name');
?>
<!DOCTYPE html>
<html lang="<?= esc(service('request')->getLocale()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title) ?> · <?= esc(lang('App.app_name')) ?></title>
    <link href="/assets/css/auth.css" rel="stylesheet">
</head>
<body>
    <div class="container">
        <?= $this->include('partials/_flash') ?>

        <?= $this->renderSection('content') ?>
    </div>
</body>
</html>
