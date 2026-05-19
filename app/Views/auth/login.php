<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ERP Template</title>
    <link href="/assets/css/auth.css" rel="stylesheet">
</head>
<body>
    <div class="container">
        <h1>🔐 Login</h1>
        
        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-error"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>
        
        <?php if (session()->getFlashdata('success')): ?>
            <div class="alert alert-success"><?= esc(session()->getFlashdata('success')) ?></div>
        <?php endif; ?>
        
        <form method="POST" action="<?= base_url('auth/login') ?>">
            <?= csrf_field() ?>
            
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required autofocus value="<?= esc(old('email'), 'attr') ?>">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit">Login</button>
        </form>
        
        <div class="link">
            Don't have an account? <a href="<?= base_url('auth/register') ?>">Register here</a>
        </div>
    </div>
</body>
</html>
