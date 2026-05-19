<?= $this->extend('layout') ?>

<?= $this->section('content') ?>
<!-- admin/users/create -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Create New User</h1>
    <a href="/admin/users" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Back to List
    </a>
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
            <div class="card-body">
                <form method="post" action="/admin/users">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control <?= session('errors.name') ? 'is-invalid' : '' ?>"
                               id="name" name="name" value="<?= old('name') ?>" required>
                        <?php if (session('errors.name')): ?>
                            <div class="invalid-feedback"><?= esc(session('errors.name')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control <?= session('errors.email') ? 'is-invalid' : '' ?>"
                               id="email" name="email" value="<?= old('email') ?>" required>
                        <?php if (session('errors.email')): ?>
                            <div class="invalid-feedback"><?= esc(session('errors.email')) ?></div>
                        <?php endif; ?>
                        <div class="form-text">Must be a valid email address and unique in the system.</div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control <?= session('errors.password') ? 'is-invalid' : '' ?>"
                               id="password" name="password" required>
                        <?php if (session('errors.password')): ?>
                            <div class="invalid-feedback"><?= esc(session('errors.password')) ?></div>
                        <?php endif; ?>
                        <div class="form-text">
                            Must be at least 12 characters with: uppercase, lowercase, digit, and special character (@$!%*?&).
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password_confirm" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control <?= session('errors.password_confirm') ? 'is-invalid' : '' ?>"
                               id="password_confirm" name="password_confirm" required>
                        <?php if (session('errors.password_confirm')): ?>
                            <div class="invalid-feedback"><?= esc(session('errors.password_confirm')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select <?= session('errors.role') ? 'is-invalid' : '' ?>"
                                        id="role" name="role" required>
                                    <option value="">Select Role</option>
                                    <option value="admin" <?= old('role') === 'admin' ? 'selected' : '' ?>>Admin</option>
                                    <option value="customer" <?= old('role', 'customer') === 'customer' ? 'selected' : '' ?>>Customer</option>
                                </select>
                                <?php if (session('errors.role')): ?>
                                    <div class="invalid-feedback"><?= esc(session('errors.role')) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select <?= session('errors.status') ? 'is-invalid' : '' ?>"
                                        id="status" name="status" required>
                                    <option value="">Select Status</option>
                                    <option value="active" <?= old('status', 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= old('status') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                                <?php if (session('errors.status')): ?>
                                    <div class="invalid-feedback"><?= esc(session('errors.status')) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Create User
                        </button>
                        <a href="/admin/users" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card bg-light">
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

        <div class="card bg-light mt-3">
            <div class="card-body">
                <h5 class="card-title">Role Descriptions</h5>
                <p class="card-text small">
                    <strong>Admin:</strong> Full access to all system features including user management.<br><br>
                    <strong>Customer:</strong> Limited access to customer-facing features only.
                </p>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
