<?php
session_start();
require_once __DIR__ . "/../config.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo "Method not allowed";
  exit;
}

$shop_id = (int)($_POST["shop_id"] ?? 0);
$customer_name  = trim((string)($_POST["customer_name"] ?? ""));
$customer_phone = trim((string)($_POST["customer_phone"] ?? ""));
$customer_note  = trim((string)($_POST["customer_note"] ?? ""));

// ✅ ใช้วันที่จาก PHP
$today = date('Y-m-d');

// ============================
// helper
// ============================
function fail(string $message, int $code = 400): void {
  http_response_code($code);
  echo $message;
  exit;
}

function normalize_spaces(string $text): string {
  $text = preg_replace('/\s+/u', ' ', $text);
  return trim((string)$text);
}

// ============================
// 0) สร้าง / ดึง customer_token
// ลูกค้า 1 คนใช้ token เดียวกันได้หลายร้าน
// ============================
$customerToken = '';

if (!empty($_COOKIE["customer_token"])) {
  $customerToken = trim((string)$_COOKIE["customer_token"]);
} elseif (!empty($_SESSION["customer_token"])) {
  $customerToken = trim((string)$_SESSION["customer_token"]);
}

if ($customerToken === '') {
  $customerToken = bin2hex(random_bytes(16));
}

// sync เข้า session และ cookie
$_SESSION["customer_token"] = $customerToken;
setcookie("customer_token", $customerToken, [
  "expires"  => time() + (86400 * 30),
  "path"     => "/",
  "httponly" => true,
  "samesite" => "Lax"
]);

// ============================
// 1) Validate เบื้องต้น
// ============================
if ($shop_id <= 0) {
  fail("shop_id ไม่ถูกต้อง", 400);
}

// จัดรูปแบบข้อความ
$customer_name = normalize_spaces($customer_name);
$customer_note = trim((string)$customer_note);

// จำกัดความยาวให้สอดคล้องกับฐานข้อมูล
$customer_name = mb_substr($customer_name, 0, 150);
$customer_note = mb_substr($customer_note, 0, 255);

if ($customer_name === "") {
  fail("กรุณากรอกชื่อ", 400);
}

// ✅ ทำความสะอาดเบอร์: เหลือแต่ตัวเลข
$customer_phone = preg_replace('/\D+/', '', $customer_phone);

// ✅ บังคับ 10 หลักเท่านั้น
if (!preg_match('/^\d{10}$/', $customer_phone)) {
  fail("กรุณากรอกเบอร์โทรศัพท์ 10 หลักให้ถูกต้อง", 400);
}

try {
  $pdo->beginTransaction();

  // ============================
  // 2) ล็อกร้านก่อน แล้วเช็ค status + queue_limit
  // ============================
  $stmt = $pdo->prepare("
    SELECT shop_id, status, queue_limit
    FROM shops
    WHERE shop_id = ?
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([$shop_id]);
  $shop = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$shop) {
    $pdo->rollBack();
    fail("ไม่พบร้าน", 404);
  }

  $shopStatus = (string)($shop["status"] ?? "open");
  $queueLimit = $shop["queue_limit"];

  if ($shopStatus !== "open") {
    $pdo->rollBack();
    fail("ตอนนี้ร้านยังไม่เปิดรับคิว", 403);
  }

  // ============================
  // 3) กันคิวซ้ำจาก customer_token
  // อนุญาตให้ลูกค้าคนเดิมกดหลายร้านได้
  // แต่ไม่ให้กดซ้ำร้านเดิมในวันเดียวกัน ถ้าคิวยังไม่จบ
  // ============================
  $stmt = $pdo->prepare("
    SELECT queue_id
    FROM queues
    WHERE customer_token = ?
      AND shop_id = ?
      AND queue_date = ?
      AND status IN ('waiting','calling','served')
      AND deleted_at IS NULL
    ORDER BY queue_id DESC
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([$customerToken, $shop_id, $today]);
  $existingQueueId = (int)($stmt->fetchColumn() ?: 0);

  if ($existingQueueId > 0) {
    $pdo->commit();
    $_SESSION["last_queue_id"] = $existingQueueId;
    header("Location: my-queue.php?queue_id=" . $existingQueueId);
    exit;
  }

  // ============================
  // 4) หาเลขคิวถัดไปของวันนี้ของร้านนี้
  // ============================
  $stmt = $pdo->prepare("
    SELECT COALESCE(MAX(queue_no), 0) AS max_no
    FROM queues
    WHERE shop_id = ?
      AND queue_date = ?
      AND deleted_at IS NULL
    FOR UPDATE
  ");
  $stmt->execute([$shop_id, $today]);
  $maxNo = (int)($stmt->fetch(PDO::FETCH_ASSOC)["max_no"] ?? 0);
  $nextNo = $maxNo + 1;

  // ============================
  // 5) จำกัดจำนวนคิวต่อวัน
  // ============================
  if ($queueLimit !== null && $nextNo > (int)$queueLimit) {
    $pdo->rollBack();
    fail("วันนี้คิวเต็มแล้ว", 403);
  }

  // ============================
  // 6) INSERT คิว
  // ============================
  $stmt = $pdo->prepare("
    INSERT INTO queues
      (
        shop_id,
        queue_date,
        queue_no,
        customer_name,
        customer_note,
        customer_phone,
        customer_token,
        status,
        created_at
      )
    VALUES
      (?, ?, ?, ?, ?, ?, ?, 'waiting', NOW())
  ");
  $stmt->execute([
    $shop_id,
    $today,
    $nextNo,
    $customer_name,
    ($customer_note === "" ? null : $customer_note),
    $customer_phone,
    $customerToken
  ]);

  $queue_id = (int)$pdo->lastInsertId();

  $pdo->commit();

} catch (PDOException $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  error_log("take_queue PDOException: " . $e->getMessage());

  http_response_code(500);
  echo "บันทึกคิวไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
  exit;

} catch (Exception $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  error_log("take_queue Exception: " . $e->getMessage());

  http_response_code(500);
  echo "บันทึกคิวไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
  exit;
}

// เก็บคิวล่าสุดไว้
$_SESSION["last_queue_id"] = $queue_id;

// ✅ ไปหน้า confirm ก่อน
header("Location: confirm-queue.php?queue_id=" . $queue_id);
exit;