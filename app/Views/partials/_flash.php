<?php
/**
 * Render success / error flash messages (E2).
 *
 * Domain controllers set `session()->setFlashdata('success', 'msg')` or
 * `'error'`. This partial renders the corresponding Bootstrap alert(s).
 * The shell layout includes this once at the top of every page.
 */
?>
<?php if (session()->has('success')): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= esc(session('success')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif ?>

<?php if (session()->has('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= esc(session('error')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif ?>
