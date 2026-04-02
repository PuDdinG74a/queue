<?php
require_once __DIR__ . "/../auth.php";
require_once __DIR__ . "/../config.php";

requireRole('admin');

$user = currentUser();
$admin_id   = (int)($user['user_id'] ?? 0);
$admin_name = $user['full_name'] ?? $user['username'] ?? 'Admin';

if ($admin_id <= 0) {
  logoutAndRedirectToLogin();
}