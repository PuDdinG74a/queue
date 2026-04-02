<?php
require_once __DIR__ . "/../auth.php";
require_once __DIR__ . "/../config.php";

requireRole('owner');

$user = currentUser();

$owner_user_id = (int)($user['user_id'] ?? 0);
$owner_shop_id = (int)($user['shop_id'] ?? 0);
$owner_name    = $user['full_name'] ?? $user['username'] ?? 'ร้านค้า';

if ($owner_user_id <= 0 || $owner_shop_id <= 0) {
  logoutAndRedirectToLogin();
}

/**
 * shop_id ของร้านที่ล็อกอินอยู่
 */
function ownerShopId(): int {
  $user = currentUser();
  return (int)($user['shop_id'] ?? 0);
}

/**
 * บังคับให้ owner ใช้งานได้เฉพาะร้านของตัวเอง
 * - ถ้าไม่มี shop_id ส่งมา => ใช้ shop_id จาก session
 * - ถ้ามี shop_id แต่ไม่ตรง => เด้งกลับ dashboard ร้านตัวเอง
 */
function enforceOwnerShopAccess(?int $requestedShopId = null): int {
  $sessionShopId = ownerShopId();

  if ($sessionShopId <= 0) {
    logoutAndRedirectToLogin();
  }

  if ($requestedShopId === null || $requestedShopId <= 0) {
    return $sessionShopId;
  }

  if ($requestedShopId !== $sessionShopId) {
    header("Location: /final_project/owner/owner-dashboard.php?shop_id=" . $sessionShopId);
    exit;
  }

  return $sessionShopId;
}