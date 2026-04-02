<?php
require_once __DIR__ . "/../config.php";

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

$queue_id = isset($_GET["queue_id"]) ? (int)$_GET["queue_id"] : 0;
if ($queue_id <= 0) err("missing queue_id");

try {

  $stmt = $pdo->prepare("
    SELECT
      q.queue_id,
      q.shop_id,
      q.queue_date,
      q.queue_no,
      q.customer_phone,
      q.customer_note,
      q.status,
      q.created_at,
      q.called_at,
      q.served_at,
      q.deleted_at,

      s.name AS shop_name,
      s.eta_per_queue_min,

      l.lock_no,
      d.dome_id,
      d.dome_name

    FROM queues q
    JOIN shops s ON s.shop_id = q.shop_id
    JOIN locks l ON l.lock_id = s.lock_id
    JOIN domes d ON d.dome_id = l.dome_id
    WHERE q.queue_id = ?
    LIMIT 1
  ");
  $stmt->execute([$queue_id]);
  $q = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$q) err("queue not found", 404);

  $shopId    = (int)$q["shop_id"];
  $queueNo   = (int)$q["queue_no"];
  $queueDate = (string)$q["queue_date"];

  $status = trim((string)($q["status"] ?? "waiting"));
  if ($status === "") $status = "waiting";

  // คิวที่ร้านกำลังเรียก / คิวล่าสุดที่จบแล้ว
  $stmt = $pdo->prepare("
    SELECT
      COALESCE(
        (
          SELECT MIN(queue_no)
          FROM queues
          WHERE shop_id = ?
            AND queue_date = ?
            AND deleted_at IS NULL
            AND status = 'calling'
        ),
        (
          SELECT MAX(queue_no)
          FROM queues
          WHERE shop_id = ?
            AND queue_date = ?
            AND deleted_at IS NULL
            AND status IN ('served','received')
        ),
        0
      ) AS current_no
  ");
  $stmt->execute([$shopId, $queueDate, $shopId, $queueDate]);
  $currentQueue = (int)($stmt->fetchColumn() ?: 0);

  // คิวที่ยังไม่เสร็จของร้าน
  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM queues
    WHERE shop_id = ?
      AND queue_date = ?
      AND deleted_at IS NULL
      AND status IN ('waiting', 'calling')
  ");
  $stmt->execute([$shopId, $queueDate]);
  $waitingCount = (int)($stmt->fetchColumn() ?: 0);

  // เหลือก่อนถึงคิวคุณ
  $beforeMe = 0;
  if (!in_array($status, ["served", "received", "cancel"], true)) {
    $stmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM queues
      WHERE shop_id = ?
        AND queue_date = ?
        AND deleted_at IS NULL
        AND status IN ('waiting', 'calling')
        AND queue_no < ?
    ");
    $stmt->execute([$shopId, $queueDate, $queueNo]);
    $beforeMe = (int)($stmt->fetchColumn() ?: 0);
  }

  // คำนวณเวลาเฉลี่ยต่อคิว
  $avgPerQueueMin = 3.0;
  $avgSource = "default";

  $stmt = $pdo->prepare("
    SELECT AVG(sec) AS avg_sec
    FROM (
      SELECT
        CASE
          WHEN served_at IS NOT NULL AND called_at IS NOT NULL
            THEN TIMESTAMPDIFF(SECOND, called_at, served_at)
          WHEN served_at IS NOT NULL AND created_at IS NOT NULL
            THEN TIMESTAMPDIFF(SECOND, created_at, served_at)
          ELSE NULL
        END AS sec
      FROM queues
      WHERE shop_id = ?
        AND queue_date BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND ?
        AND deleted_at IS NULL
        AND status IN ('served','received')
    ) x
    WHERE sec BETWEEN 30 AND 7200
  ");
  $stmt->execute([$shopId, $queueDate, $queueDate]);
  $avgSec = $stmt->fetchColumn();

  if ($avgSec !== null) {
    $avgMin = ((float)$avgSec) / 60.0;
    if ($avgMin >= 0.5 && $avgMin <= 60) {
      $avgPerQueueMin = $avgMin;
      $avgSource = "history";
    }
  }

  if ($avgSource === "default") {
    $shopEta = $q["eta_per_queue_min"];
    if ($shopEta !== null) {
      $shopEta = (float)$shopEta;
      if ($shopEta >= 0.5 && $shopEta <= 60) {
        $avgPerQueueMin = $shopEta;
        $avgSource = "shop_setting";
      }
    }
  }

  $etaMinutes = 0;
  if (!in_array($status, ["served", "received", "cancel"], true)) {
    $etaMinutes = (int) max(0, round($beforeMe * $avgPerQueueMin));
  }

  $note = trim((string)($q["customer_note"] ?? ""));
  if ($note === "") $note = "ไม่ได้ระบุรายการ";

  $phone = trim((string)($q["customer_phone"] ?? ""));
  if ($phone === "") $phone = "ไม่ได้ระบุ";

  ok([
    "queue_id" => (int)$q["queue_id"],
    "shop_id" => $shopId,
    "shop_name" => (string)$q["shop_name"],

    "dome_id" => (int)$q["dome_id"],
    "dome_name" => (string)$q["dome_name"],
    "lock_no" => (int)$q["lock_no"],

    "queue_no" => $queueNo,
    "status" => $status,

    "current_queue" => $currentQueue,
    "waiting_count" => $waitingCount,

    "before_me" => $beforeMe,
    "eta_minutes" => $etaMinutes,

    "avg_per_queue_min" => round($avgPerQueueMin, 1),
    "eta_source" => $avgSource,

    "note" => $note,
    "phone" => $phone,

    "queue_date" => $queueDate,
    "server_time" => date("Y-m-d H:i:s")
  ]);

} catch (Throwable $e) {
  err("server error: " . $e->getMessage(), 500);
}