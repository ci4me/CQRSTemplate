<?php
/**
 * Authenticated ERP shell (E1).
 *
 * Top-bar + sidebar layout. Existing views continue to `$this->extend('layout')`;
 * new views are encouraged to use `$this->extend('layouts/shell')` directly,
 * which points at the same template.
 *
 * Variables in scope:
 *   $title       — used in <title> and the page header
 *   $breadcrumbs — optional list passed to partials/_breadcrumbs
 */

$title ??= lang('App.dashboard');
$appName = lang('App.app_name');
$breadcrumbs = is_array($breadcrumbs ?? null) ? $breadcrumbs : [];
?>
<!DOCTYPE html>
<html lang="<?= esc(service('request')->getLocale()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title) ?> · <?= esc($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM"
          crossorigin="anonymous">
</head>
<body class="bg-light">
    <header class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="/dashboard"><?= esc($appName) ?></a>
            <div class="ms-auto">
                <?= $this->include('partials/_user_menu') ?>
            </div>
        </div>
    </header>

    <div class="d-flex" style="min-height: calc(100vh - 56px);">
        <?= $this->include('partials/_sidebar') ?>

        <main class="flex-grow-1 p-4 bg-white">
            <?php if ($breadcrumbs !== []): ?>
                <?= $this->include('partials/_breadcrumbs', ['items' => $breadcrumbs]) ?>
            <?php endif ?>

            <?= $this->include('partials/_flash') ?>

            <?= $this->renderSection('content') ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz"
            crossorigin="anonymous"></script>
    <script src="/assets/js/delete-confirm.js"></script>
</body>
</html>
