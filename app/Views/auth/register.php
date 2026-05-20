<?php
$title = 'Register';
$this->extend('layouts/auth');
?>

<?= $this->section('content') ?>

<h1>📝 Register</h1>

<form method="POST" action="<?= base_url('auth/register') ?>">
    <?= csrf_field() ?>

    <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autofocus
               value="<?= esc(old('email'), 'attr') ?>"
               autocomplete="email">
    </div>

    <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
    </div>

    <!-- SECURITY: Role selection removed - all registrations are 'customer' role.
         Admin accounts must be created by existing administrators. -->
    <input type="hidden" name="role" value="customer">

    <button type="submit">Register</button>
</form>

<div class="link">
    <a href="<?= base_url('auth/login') ?>"><?= esc(lang('App.sign_in')) ?></a>
</div>

<?= $this->endSection() ?>
