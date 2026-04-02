<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../push_lib.php";

header("Content-Type: application/json; charset=utf-8");

function ok($data = []){
  echo json_encode(["ok" => true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}

function err($msg, $code = 400){
  http_response_code($code);
  echo json_encode(["ok" => false, "error" => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  err("method not allowed", 405);
}

$queue_id = isset($_POST["queue_id"]) ? (int)$_POST["queue_id"] : 0;
if ($queue_id <= 0) {
  err("missing queue_id");
}

try {

  $pdo->beginTransaction();

  $stmt = $pdo->prepare("
    SELECT 
      q.queue_id,
      q.queue_no,
      q.status,
      s.name AS shop_name
    FROM queues q
    LEFT JOIN shops s ON s.shop_id = q.shop_id
    WHERE q.queue_id = ?
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([$queue_id]);
  $q = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$q) {
    $pdo->rollBack();
    err("queue not found", 404);
  }

  $status   = trim((string)($q["status"] ?? ""));
  $queueNo  = (int)($q["queue_no"] ?? 0);
  $shopName = trim((string)($q["shop_name"] ?? "ร้านค้า"));

  // อนุญาตเฉพาะ calling หรือ served
  if (!in_array($status, ["calling", "served"], true)) {
    $pdo->rollBack();
    err("queue cannot be marked as received in current status");
  }

  $stmt = $pdo->prepare("
    UPDATE queues
    SET status = 'received'
    WHERE queue_id = ?
      AND status IN ('calling','served')
    LIMIT 1
  ");
  $stmt->execute([$queue_id]);

  $updated = $stmt->rowCount() > 0;

  $pdo->commit();

  // ส่ง push notification
  if ($updated) {
    sendQueuePush(
      $pdo,
      $queue_id,
      "รับออเดอร์แล้ว",
      "คุณยืนยันรับออเดอร์ของ {$shopName} แล้ว (คิว #{$queueNo})",
      APP_BASE . "/Frontend/my-queue.php?queue_id=" . $queue_id
    );
  }

  ok([
    "queue_id" => $queue_id,
    "new_status" => "received",
    "message" => "ยืนยันรับออเดอร์แล้ว"
  ]);

} catch (Throwable $e) {

  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  err("server error: " . $e->getMessage(), 500);
}