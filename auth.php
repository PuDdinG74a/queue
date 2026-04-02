<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

function isLoggedIn(): bool {
  return !empty($_SESSION['user']) && is_array($_SESSION['user']);
}

function currentUser(): ?array {
  return $_SESSION['user'] ?? null;
}

function redirectToLogin(): void {
  header("Location: /queue/login.php");
  exit;
}

function logoutAndRedirectToLogin(): void {
  session_unset();
  session_destroy();
  header("Location: /queue/login.php");
  exit;
}

function requireLogin(): void {
  if (!isLoggedIn()) {
    redirectToLogin();
  }

  $user = currentUser();

  if (
    empty($user['user_id']) ||
    empty($user['username']) ||
    empty($user['role'])
  ) {
    logoutAndRedirectToLogin();
  }
}

function requireRole(string $role): void {
  requireLogin();

  $user = currentUser();
  if (($user['role'] ?? '') !== $role) {
    redirectToLogin();
  }
}