<?php
/**
 * Generic Bootstrap form field (E2).
 *
 * Centralises label + control + per-field error rendering so cookies/create,
 * cookies/edit, admin/users/create, admin/users/edit and every future
 * entity form stop repeating the same ~15 lines of markup.
 *
 * Usage:
 *   <?= $this->include('partials/_form_field', [
 *       'name'     => 'price',
 *       'label'    => lang('App.price'),
 *       'value'    => old('price', $cookie?->getPrice()->toDecimalString()),
 *       'type'     => 'number',          // text|number|email|password|textarea|select
 *       'required' => true,
 *       'step'     => '0.01',            // type=number only
 *       'options'  => ['draft'=>'Draft'], // type=select only
 *       'help'     => 'Price including VAT',
 *   ]) ?>
 *
 * All passed values are escaped via esc(). Validation errors come from
 * session()->getFlashdata('errors')[$name] if present, matching the
 * existing controller flash pattern.
 */

$name = (string) ($name ?? '');
$label = (string) ($label ?? $name);
$type = (string) ($type ?? 'text');
$value = $value ?? '';
$required = (bool) ($required ?? false);
$help = (string) ($help ?? '');
$placeholder = (string) ($placeholder ?? '');
$step = (string) ($step ?? '');
$options = is_array($options ?? null) ? $options : [];
$attributes = (string) ($attributes ?? '');

$errors = session()->getFlashdata('errors') ?? [];
$errorMessage = is_array($errors) && isset($errors[$name]) ? (string) $errors[$name] : '';
$invalidClass = $errorMessage !== '' ? ' is-invalid' : '';
?>
<div class="mb-3">
    <label for="<?= esc($name, 'attr') ?>" class="form-label">
        <?= esc($label) ?>
        <?php if ($required): ?>
            <span class="text-danger" aria-hidden="true">*</span>
        <?php endif ?>
    </label>

    <?php if ($type === 'textarea'): ?>
        <textarea
            class="form-control<?= $invalidClass ?>"
            id="<?= esc($name, 'attr') ?>"
            name="<?= esc($name, 'attr') ?>"
            <?php if ($placeholder !== ''): ?>placeholder="<?= esc($placeholder, 'attr') ?>"<?php endif ?>
            <?php if ($required): ?>required<?php endif ?>
            <?= $attributes ?>
            rows="3"><?= esc((string) $value) ?></textarea>

    <?php elseif ($type === 'select'): ?>
        <select
            class="form-select<?= $invalidClass ?>"
            id="<?= esc($name, 'attr') ?>"
            name="<?= esc($name, 'attr') ?>"
            <?php if ($required): ?>required<?php endif ?>
            <?= $attributes ?>>
            <?php foreach ($options as $optValue => $optLabel): ?>
                <option value="<?= esc((string) $optValue, 'attr') ?>"
                    <?= (string) $value === (string) $optValue ? 'selected' : '' ?>>
                    <?= esc((string) $optLabel) ?>
                </option>
            <?php endforeach ?>
        </select>

    <?php else: ?>
        <input
            type="<?= esc($type, 'attr') ?>"
            class="form-control<?= $invalidClass ?>"
            id="<?= esc($name, 'attr') ?>"
            name="<?= esc($name, 'attr') ?>"
            value="<?= esc((string) $value, 'attr') ?>"
            <?php if ($placeholder !== ''): ?>placeholder="<?= esc($placeholder, 'attr') ?>"<?php endif ?>
            <?php if ($required): ?>required<?php endif ?>
            <?php if ($step !== ''): ?>step="<?= esc($step, 'attr') ?>"<?php endif ?>
            <?= $attributes ?>>
    <?php endif ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="invalid-feedback"><?= esc($errorMessage) ?></div>
    <?php elseif ($help !== ''): ?>
        <div class="form-text"><?= esc($help) ?></div>
    <?php endif ?>
</div>
