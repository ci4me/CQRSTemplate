<?= $this->extend('layout') ?>

<?= $this->section('content') ?>
<!-- admin/users/show -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>User Details</h1>
    <div class="btn-group">
        <a href="/admin/users" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to List
        </a>
        <a href="/admin/users/<?= $user->id ?>/edit" class="btn btn-primary">
            <i class="bi bi-pencil"></i> Edit User
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">User Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-bordered">
                    <tbody>
                        <tr>
                            <th width="30%">User ID</th>
                            <td><?= $user->id ?></td>
                        </tr>
                        <tr>
                            <th>Name</th>
                            <td><?= esc($user->name) ?></td>
                        </tr>
                        <tr>
                            <th>Email</th>
                            <td><?= esc($user->email) ?></td>
                        </tr>
                        <tr>
                            <th>Role</th>
                            <td>
                                <span class="badge bg-<?= $user->role === 'admin' ? 'danger' : 'primary' ?>">
                                    <?= esc(ucfirst($user->role)) ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>Status</th>
                            <td>
                                <?php if ($user->status === 'active'): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Created At</th>
                            <td><?= $user->createdAt ?></td>
                        </tr>
                        <tr>
                            <th>Updated At</th>
                            <td><?= $user->updatedAt ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <!-- Actions Card -->
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Actions</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="/admin/users/<?= $user->id ?>/edit" class="btn btn-primary">
                        <i class="bi bi-pencil"></i> Edit User
                    </a>
                    <a href="/admin/users/<?= $user->id ?>/reset-password" class="btn btn-warning">
                        <i class="bi bi-key"></i> Reset Password
                    </a>
                    <?php if (!$user->deletedAt): ?>
                        <form method="post" action="/admin/users/<?= $user->id ?>/delete"
                              data-confirm="Are you sure you want to delete this user?">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-danger w-100">
                                <i class="bi bi-trash"></i> Delete User
                            </button>
                        </form>
                    <?php else: ?>
                        <button class="btn btn-secondary w-100" disabled>
                            <i class="bi bi-trash"></i> User Deleted/Inactive
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Activity Card -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Activity</h5>
            </div>
            <div class="card-body">
                <p class="small mb-2">
                    <strong>Account Age:</strong><br>
                    <?php
                        $accountAge = (new DateTimeImmutable($user->createdAt))->diff(new DateTimeImmutable());
                        echo $accountAge->days . ' days';
                    ?>
                </p>
                <p class="small mb-2">
                    <strong>Last Updated:</strong><br>
                    <?php
                        if ($user->updatedAt !== null) {
                            $lastUpdate = (new DateTimeImmutable($user->updatedAt))->diff(new DateTimeImmutable());
                            if ($lastUpdate->days === 0) {
                                echo 'Today';
                            } else {
                                echo $lastUpdate->days . ' days ago';
                            }
                        } else {
                            echo 'Never';
                        }
                    ?>
                </p>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
