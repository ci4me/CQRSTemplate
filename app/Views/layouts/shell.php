<?php
/**
 * Authenticated ERP shell — thin alias for `layout` (E1).
 *
 * Views that want the modern path use:
 *     $this->extend('layouts/shell')
 * Existing views that still call `$this->extend('layout')` get the same
 * rendering through the parent path.
 */
$this->extend('layout');
?>
<?= $this->section('content') ?>
<?= $this->renderSection('content') ?>
<?= $this->endSection() ?>
