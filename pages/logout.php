<?php
require_once __DIR__ . '/../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$auth = new AuthEngine();
$auth->logout();
header('Location: /inveniq/pages/login.php');
exit;
