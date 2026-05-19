<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - ERP Template</title>
    <link href="/assets/css/auth.css" rel="stylesheet">
</head>
<body>
    <div class="container">
        <h1>📝 Register</h1>
        
        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-error"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>
        
        <form method="POST" action="<?= base_url('auth/register') ?>">
            <?= csrf_field() ?>
            
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required autofocus value="<?= esc(old('email'), 'attr') ?>">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required minlength="8">
            </div>

            <!-- SECURITY: Role selection removed - all registrations are 'customer' role -->
            <!-- Admin accounts must be created by existing administrators -->
            <input type="hidden" name="role" value="customer">

            <button type="submit">Register</button>
        </form>
        
        <div class="link">
            Already have an account? <a href="<?= base_url('auth/login') ?>">Login here</a>
        </div>
    </div>
</body>
</html>
