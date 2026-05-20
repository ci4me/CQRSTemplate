<?= $this->extend('layout') ?>

<?= $this->section('content') ?>
<!-- admin/users/reset_password -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Reset User Password</h1>
    <div class="btn-group">
        <a href="/admin/users/<?= $user->id ?>" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <a href="/admin/users/<?= $user->id ?>/edit" class="btn btn-primary">
            <i class="bi bi-pencil"></i> Edit User
        </a>
    </div>
</div>

<?php if (session('errors')): ?>
    <div class="alert alert-danger">
        <h5><i class="bi bi-exclamation-triangle"></i> Validation Errors:</h5>
        <ul class="mb-0">
            <?php foreach (session('errors') as $field => $error): ?>
                <li><?= esc($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-warning">
                <h5 class="mb-0">
                    <i class="bi bi-exclamation-triangle"></i> Security Warning
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-warning">
                    <strong>Admin Password Reset</strong><br>
                    You are about to reset the password for user: <strong><?= esc($user->name) ?></strong> (<?= esc($user->email) ?>).<br><br>
                    This action will:
                    <ul class="mb-0 mt-2">
                        <li>Be logged for security audit purposes</li>
                        <li>Immediately change the user's password</li>
                        <li>The user should be notified of this change</li>
                    </ul>
                </div>

                <form method="post" action="/admin/users/<?= $user->id ?>/reset-password">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control <?= session('errors.new_password') ? 'is-invalid' : '' ?>"
                               id="new_password" name="new_password" required autocomplete="new-password">
                        <?php if (session('errors.new_password')): ?>
                            <div class="invalid-feedback"><?= esc(session('errors.new_password')) ?></div>
                        <?php endif; ?>
                        <div class="form-text">
                            Must be at least 12 characters with: uppercase, lowercase, digit, and special character (@$!%*?&).
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control <?= session('errors.confirm_password') ? 'is-invalid' : '' ?>"
                               id="confirm_password" name="confirm_password" required autocomplete="new-password">
                        <?php if (session('errors.confirm_password')): ?>
                            <div class="invalid-feedback"><?= esc(session('errors.confirm_password')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirm_reset" required>
                        <label class="form-check-label" for="confirm_reset">
                            I understand that this password reset will be logged and the user should be notified.
                        </label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-key"></i> Reset Password
                        </button>
                        <a href="/admin/users/<?= $user->id ?>" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card bg-light">
            <div class="card-body">
                <h5 class="card-title">User Information</h5>
                <p class="card-text small">
                    <strong>User ID:</strong> <?= $user->id ?><br>
                    <strong>Name:</strong> <?= esc($user->name) ?><br>
                    <strong>Email:</strong> <?= esc($user->email) ?><br>
                    <strong>Role:</strong> <?= esc(ucfirst($user->role)) ?><br>
                    <strong>Status:</strong> <?= esc(ucfirst($user->status)) ?>
                </p>
            </div>
        </div>

        <div class="card bg-light mt-3">
            <div class="card-body">
                <h5 class="card-title">Password Requirements</h5>
                <ul class="small mb-0">
                    <li>Minimum 12 characters</li>
                    <li>At least one uppercase letter (A-Z)</li>
                    <li>At least one lowercase letter (a-z)</li>
                    <li>At least one digit (0-9)</li>
                    <li>At least one special character (@$!%*?&)</li>
                </ul>
            </div>
        </div>

        <div class="card border-danger mt-3">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0">Security Notice</h5>
            </div>
            <div class="card-body">
                <p class="card-text small">
                    All password resets are logged with:
                </p>
                <ul class="small mb-0">
                    <li>Admin user ID (you)</li>
                    <li>Target user ID</li>
                    <li>Timestamp</li>
                    <li>Correlation ID for tracing</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
