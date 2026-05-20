<?php
$title = lang('App.sign_in');
$this->extend('layouts/auth');
?>

<?= $this->section('content') ?>

<h1>🔐 <?= esc(lang('App.sign_in')) ?></h1>

<form method="POST" action="<?= base_url('auth/login') ?>">
    <?= csrf_field() ?>

    <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autofocus
               value="<?= esc(old('email'), 'attr') ?>"
               autocomplete="username">
    </div>

    <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
    </div>

    <button type="submit"><?= esc(lang('App.sign_in')) ?></button>
</form>

<div class="link">
    <a href="<?= base_url('auth/register') ?>">Register</a>
</div>

<?= $this->endSection() ?>
