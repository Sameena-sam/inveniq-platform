<?php
// =============================================================
// InvenIQ — First-time Setup Script
// Run ONCE at: http://localhost/inveniq/pages/setup.php
// DELETE this file after running it.
// =============================================================
require_once __DIR__ . '/../includes/config.php';

$db = getDB();

// Create users table if not exists
$db->exec("
CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(100) NOT NULL,
  email         VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('super_admin','inventory_manager','procurement_officer','finance_officer','viewer')
                NOT NULL DEFAULT 'viewer',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  last_login    DATETIME NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");

// Seed demo users
$users = [
    ['Super Admin',         'admin@inveniq.com',       'admin123',  'super_admin'],
    ['Inventory Manager',   'inventory@inveniq.com',   'inv123',    'inventory_manager'],
    ['Procurement Officer', 'procurement@inveniq.com', 'proc123',   'procurement_officer'],
    ['Finance Officer',     'finance@inveniq.com',     'fin123',    'finance_officer'],
    ['Viewer',              'viewer@inveniq.com',      'view123',   'viewer'],
];

$created = [];
foreach ($users as [$name, $email, $pass, $role]) {
    $check = $db->prepare("SELECT id FROM users WHERE email = ?");
    $check->execute([$email]);
    if ($check->fetch()) {
        $created[] = "⚠ Already exists: $email";
        continue;
    }
    $stmt = $db->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?,?,?,?)");
    $stmt->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
    $created[] = "✓ Created: $email (password: $pass)";
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>InvenIQ Setup</title>
<style>
  body{font-family:sans-serif;max-width:600px;margin:60px auto;padding:24px}
  .ok{color:#16a34a}.warn{color:#d97706}
  pre{background:#f1f5f9;padding:16px;border-radius:8px;font-size:13px}
  .btn{display:inline-block;background:#0f172a;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;margin-top:20px}
</style></head>
<body>
<h2>⬡ InvenIQ — Setup Complete</h2>
<p>Demo users have been created:</p>
<pre><?php foreach($created as $line) echo $line."\n"; ?></pre>
<table border="1" cellpadding="8" style="border-collapse:collapse;font-size:13px;margin-top:16px">
<tr><th>Role</th><th>Email</th><th>Password</th></tr>
<tr><td>Super Admin</td><td>admin@inveniq.com</td><td>admin123</td></tr>
<tr><td>Inventory Manager</td><td>inventory@inveniq.com</td><td>inv123</td></tr>
<tr><td>Procurement Officer</td><td>procurement@inveniq.com</td><td>proc123</td></tr>
<tr><td>Finance Officer</td><td>finance@inveniq.com</td><td>fin123</td></tr>
<tr><td>Viewer</td><td>viewer@inveniq.com</td><td>view123</td></tr>
</table>
<p style="margin-top:20px;color:#dc2626"><strong>⚠ Please delete this file after setup: <code>pages/setup.php</code></strong></p>
<a href="/inveniq/pages/login.php" class="btn">Go to Login →</a>
</body></html>
