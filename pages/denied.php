<?php
require_once __DIR__ . '/../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) { header('Location: /inveniq/pages/login.php'); exit; }
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Access Denied · InvenIQ</title>
<link rel="stylesheet" href="/inveniq/assets/css/app.css">
<style>
body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:var(--bg-muted); }
.denied-box { text-align:center; max-width:420px; padding:40px 32px; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-lg); box-shadow:var(--shadow); }
.denied-icon { font-size:48px; margin-bottom:16px; }
.denied-title { font-size:20px; font-weight:700; margin-bottom:8px; }
.denied-sub { font-size:13px; color:var(--text-3); margin-bottom:24px; line-height:1.6; }
</style>
</head>
<body>
<div class="denied-box">
  <div class="denied-icon">🔒</div>
  <div class="denied-title">Access Denied</div>
  <div class="denied-sub">
    Your account (<strong><?= htmlspecialchars($user['role_label']) ?></strong>) does not have permission to view this page.<br>
    Please contact your administrator if you need access.
  </div>
  <a href="/inveniq/pages/dashboard.php" class="btn btn-primary">← Back to Dashboard</a>
</div>
</body>
</html>
