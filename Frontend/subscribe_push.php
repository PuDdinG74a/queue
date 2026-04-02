<?php
require_once __DIR__ . "/../config.php";

header("Content-Type: application/json; charset=utf-8");

function ok(array $data = []): void {
  echo json_encode(["ok" => true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}

function fail(string $msg, int $code = 400): void {
  http_response_code($code);
  echo json_encode(["ok" => false, "error" => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  fail("Method not allowed", 405);
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
  fail("รูปแบบข้อมูลไม่ถูกต้อง");
}

$queue_id       = (int)($data["queue_id"] ?? 0);
$customer_token = trim((string)($data["customer_token"] ?? ""));
$page_url       = trim((string)($data["page_url"] ?? ""));
$subscription   = $data["subscription"] ?? null;

if ($queue_id <= 0) {
  fail("queue_id ไม่ถูกต้อง");
}

if ($customer_token === "") {
  fail("customer_token ไม่ถูกต้อง");
}

if (!is_array($subscription)) {
  fail("subscription ไม่ถูกต้อง");
}

$endpoint = trim((string)($subscription["endpoint"] ?? ""));
$keys     = is_array($subscription["keys"] ?? null) ? $subscription["keys"] : [];
$p256dh   = trim((string)($keys["p256dh"] ?? ""));
$auth     = trim((string)($keys["auth"] ?? ""));

if ($endpoint === "" || $p256dh === "" || $auth === "") {
  fail("ข้อมูล subscription ไม่ครบ");
}

$userAgent = substr(trim((string)($_SERVER["HTTP_USER_AGENT"] ?? "")), 0, 255);
$pageUrlDb = substr($page_url, 0, 255);

try {
  // ตรวจว่ามีคิวนี้จริงไหม
  $check = $pdo->prepare("
    SELECT queue_id, customer_token
    FROM queues
    WHERE queue_id = ?
      AND deleted_at IS NULL
    LIMIT 1
  ");
  $check->execute([$queue_id]);
  $queue = $check->fetch(PDO::FETCH_ASSOC);

  if (!$queue) {
    fail("ไม่พบคิวที่ต้องการ", 404);
  }

  $queueCustomerToken = trim((string)($queue["customer_token"] ?? ""));

  // ถ้ายังไม่มี token ใน queues ให้เติม
  if ($queueCustomerToken === "") {
    $updQueue = $pdo->prepare("
      UPDATE queues
      SET customer_token = ?
      WHERE queue_id = ?
      LIMIT 1
    ");
    $updQueue->execute([$customer_token, $queue_id]);
  }

  // หาแถวเดิมจาก queue_id + endpoint
  $find = $pdo->prepare("
    SELECT id
    FROM push_subscriptions
    WHERE queue_id = ?
      AND endpoint = ?
    LIMIT 1
  ");
  $find->execute([$queue_id, $endpoint]);
  $existing = $find->fetch(PDO::FETCH_ASSOC);

  if ($existing) {
    $stmt = $pdo->prepare("
      UPDATE push_subscriptions
      SET
        customer_token = ?,
        p256dh_key = ?,
        auth_key = ?,
        user_agent = ?,
        page_url = ?,
        is_active = 1,
        updated_at = NOW()
      WHERE id = ?
      LIMIT 1
    ");
    $stmt->execute([
      $customer_token,
      $p256dh,
      $auth,
      $userAgent,
      $pageUrlDb,
      $existing["id"]
    ]);
  } else {
    $stmt = $pdo->prepare("
      INSERT INTO push_subscriptions
        (queue_id, customer_token, endpoint, p256dh_key, auth_key, user_agent, page_url, is_active, created_at, updated_at)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
    ");
    $stmt->execute([
      $queue_id,
      $customer_token,
      $endpoint,
      $p256dh,
      $auth,
      $userAgent,
      $pageUrlDb
    ]);
  }

  ok([
    "message" => "บันทึก push subscription สำเร็จ",
    "queue_id" => $queue_id,
    "customer_token" => $customer_token
  ]);

} catch (Throwable $e) {
  fail("เกิดข้อผิดพลาดในการบันทึก push subscription: " . $e->getMessage(), 500);
}