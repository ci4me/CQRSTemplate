<?= $this->extend('layout') ?>

<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Dashboard</h1>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h2 class="h5 card-title">Cookies</h2>
                <p class="card-text text-muted">Manage the template entity used as the pattern for ERP modules.</p>
                <a href="/cookies" class="btn btn-primary">Open Cookies</a>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h2 class="h5 card-title">Users</h2>
                <p class="card-text text-muted">Manage application users and administrative access.</p>
                <a href="/admin/users" class="btn btn-primary">Open Users</a>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
