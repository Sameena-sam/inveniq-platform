<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: /inveniq/pages/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth  = new AuthEngine();
    $result = $auth->login($_POST['email'] ?? '', $_POST['password'] ?? '');
    if ($result['success']) {
        header('Location: /inveniq/pages/dashboard.php');
        exit;
    }
    $error = $result['error'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In · InvenIQ</title>
<link rel="stylesheet" href="/inveniq/assets/css/app.css">
<style>
  body { background: var(--bg-muted); display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .login-wrap { width: 100%; max-width: 400px; padding: 24px; }
  .login-logo { text-align: center; margin-bottom: 28px; }
  .login-logo .logo-name { font-size: 22px; font-weight: 700; color: var(--text); letter-spacing: -0.5px; }
  .login-logo .logo-sub  { font-size: 12px; color: var(--text-3); margin-top: 2px; }
  .login-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 28px; box-shadow: var(--shadow); }
  .login-title { font-size: 18px; font-weight: 700; margin-bottom: 6px; }
  .login-sub   { font-size: 13px; color: var(--text-3); margin-bottom: 22px; }
  .login-form  { display: flex; flex-direction: column; gap: 14px; }
  .login-label { font-size: 11px; font-weight: 600; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 5px; }
  .login-input { width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: var(--radius); font-size: 13px; color: var(--text); background: var(--bg-muted); outline: none; transition: border-color 0.15s; }
  .login-input:focus { border-color: var(--primary); background: #fff; }
  .login-btn   { width: 100%; padding: 10px; background: var(--primary); color: #fff; border: none; border-radius: var(--radius); font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.15s; margin-top: 4px; }
  .login-btn:hover { background: #1e293b; }
  .login-error { background: var(--danger-bg); border: 1px solid #fecaca; border-radius: var(--radius); padding: 10px 14px; font-size: 13px; color: var(--danger); }
  .login-footer { text-align: center; font-size: 11px; color: var(--text-3); margin-top: 20px; }
  .demo-accounts { margin-top: 20px; border-top: 1px solid var(--border); padding-top: 16px; }
  .demo-title { font-size: 11px; font-weight: 600; color: var(--text-3); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px; }
  .demo-item  { display: flex; align-items: center; justify-content: space-between; padding: 6px 0; font-size: 12px; border-bottom: 1px solid var(--border); cursor: pointer; }
  .demo-item:last-child { border-bottom: none; }
  .demo-item:hover { color: var(--primary); }
  .demo-role { font-size: 10px; padding: 2px 7px; border-radius: 20px; font-weight: 600; }
</style>
</head>
<body>
<div class="login-wrap">
  <div class="login-logo">
    <div class="logo-name">⬡ InvenIQ</div>
    <div class="logo-sub">Inventory Intelligence Platform</div>
  </div>

  <div class="login-card">
    <div class="login-title">Welcome back</div>
    <div class="login-sub">Sign in to your account to continue</div>

    <?php if ($error): ?>
    <div class="login-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="login-form">
      <div>
        <label class="login-label">Email address</label>
        <input class="login-input" type="email" name="email" placeholder="you@company.com" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div>
        <label class="login-label">Password</label>
        <input class="login-input" type="password" name="password" placeholder="Enter your password" required>
      </div>
      <button type="submit" class="login-btn">Sign In →</button>
    </form>

    <!-- Demo accounts for easy testing -->
    <div class="demo-accounts">
      <div class="demo-title">Demo accounts — click to fill</div>
      <?php
      $demos = [
        ['Super Admin',         'admin@inveniq.com',     'admin123',   'badge-purple'],
        ['Inventory Manager',   'inventory@inveniq.com', 'inv123',     'badge-info'],
        ['Procurement Officer', 'procurement@inveniq.com','proc123',   'badge-warning'],
        ['Finance Officer',     'finance@inveniq.com',   'fin123',     'badge-success'],
        ['Viewer',              'viewer@inveniq.com',    'view123',    'badge-muted'],
      ];
      foreach ($demos as [$role, $email, $pass, $cls]): ?>
      <div class="demo-item" onclick="fillDemo('<?= $email ?>','<?= $pass ?>')">
        <span><?= $email ?></span>
        <span class="badge <?= $cls ?> demo-role"><?= $role ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="login-footer">InvenIQ v2.0 · PHP + MySQL + XAMPP</div>
</div>

<script>
function fillDemo(email, pass) {
  document.querySelector('input[name="email"]').value = email;
  document.querySelector('input[name="password"]').value = pass;
}
</script>
</body>
</html>
