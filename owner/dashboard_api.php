<?php
// owner/dashboard_api.php
require_once __DIR__ . "/_auth.php";

header("Content-Type: application/json; charset=utf-8");

function ok($data = []){
  echo json_encode(["ok" => true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}

function err($m, $c = 400){
  http_response_code($c);
  echo json_encode(["ok" => false, "error" => $m], JSON_UNESCAPED_UNICODE);
  exit;
}

$requestedShopId = (int)($_GET["shop_id"] ?? $_POST["shop_id"] ?? 0);
$shop_id = enforceOwnerShopAccess($requestedShopId);

$today = date('Y-m-d');

function statusLabel($s){
  return match($s){
    "open"   => "เปิด",
    "closed" => "ปิด",
    "break"  => "หยุดพัก",
    "full"   => "คิวเต็ม",
    default  => "เปิด"
  };
}

try{
  // =========================
  // shop info
  // =========================
  $stmt = $pdo->prepare("
    SELECT
      s.shop_id,
      s.name,
      s.status,
      s.open_time,
      s.close_time,
      s.queue_limit,
      s.type_id,
      s.eta_per_queue_min,
      t.type_name,
      c.category_name
    FROM shops s
    LEFT JOIN shop_types t ON t.type_id = s.type_id
    LEFT JOIN shop_categories c ON c.category_id = t.category_id
    WHERE s.shop_id = ?
    LIMIT 1
  ");
  $stmt->execute([$shop_id]);
  $shop = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$shop) {
    err("ไม่พบร้าน", 404);
  }

  // =========================
  // queues today summary
  // =========================
  $stmt = $pdo->prepare("
    SELECT
      SUM(CASE WHEN status='waiting'  THEN 1 ELSE 0 END) AS waiting_cnt,
      SUM(CASE WHEN status='calling'  THEN 1 ELSE 0 END) AS calling_cnt,
      SUM(CASE WHEN status='served'   THEN 1 ELSE 0 END) AS served_cnt,
      SUM(CASE WHEN status='received' THEN 1 ELSE 0 END) AS received_cnt,
      SUM(CASE WHEN status='cancel'   THEN 1 ELSE 0 END) AS cancel_cnt,
      COUNT(*) AS total_cnt
    FROM queues
    WHERE shop_id = ? AND queue_date = ?
  ");
  $stmt->execute([$shop_id, $today]);
  $qs = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

  $waiting  = (int)($qs["waiting_cnt"] ?? 0);
  $calling  = (int)($qs["calling_cnt"] ?? 0);
  $served   = (int)($qs["served_cnt"] ?? 0);
  $received = (int)($qs["received_cnt"] ?? 0);
  $cancel   = (int)($qs["cancel_cnt"] ?? 0);
  $total    = (int)($qs["total_cnt"] ?? 0);

  $pending = $waiting + $calling;

  // =========================
  // current queue
  // =========================
  $stmt = $pdo->prepare("
    SELECT
      COALESCE(
        (SELECT MIN(queue_no)
         FROM queues
         WHERE shop_id = ? AND queue_date = ? AND status = 'calling'),
        (SELECT MAX(queue_no)
         FROM queues
         WHERE shop_id = ? AND queue_date = ? AND status IN ('served','received')),
        0
      ) AS current_no
  ");
  $stmt->execute([$shop_id, $today, $shop_id, $today]);
  $current = (int)($stmt->fetchColumn() ?: 0);

  // =========================
  // menu summary
  // =========================
  $stmt = $pdo->prepare("
    SELECT
      COUNT(*) AS menu_total,
      COALESCE(SUM(CASE WHEN is_available = 0 THEN 1 ELSE 0 END), 0) AS menu_soldout
    FROM menu_items
    WHERE shop_id = ?
  ");
  $stmt->execute([$shop_id]);
  $ms = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

  // =========================
  // ETA
  // =========================
  $avgPerQueueMin = 3.0;
  $avgSource = "ค่าเริ่มต้น";

  if (isset($shop["eta_per_queue_min"]) && $shop["eta_per_queue_min"] !== null) {
    $v = (float)$shop["eta_per_queue_min"];
    if ($v >= 0.5 && $v <= 60) {
      $avgPerQueueMin = $v;
      $avgSource = "ค่าที่ร้านกำหนด";
    }
  }

  $stmt = $pdo->prepare("
    SELECT
      AVG(
        CASE
          WHEN served_at IS NOT NULL AND called_at IS NOT NULL
            THEN TIMESTAMPDIFF(SECOND, called_at, served_at)
          WHEN served_at IS NOT NULL AND created_at IS NOT NULL
            THEN TIMESTAMPDIFF(SECOND, created_at, served_at)
          ELSE NULL
        END
      ) AS avg_sec
    FROM queues
    WHERE shop_id = ?
      AND queue_date BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND ?
      AND status IN ('served','received')
  ");
  $stmt->execute([$shop_id, $today, $today]);
  $avgSec = $stmt->fetchColumn();

  if ($avgSec !== null) {
    $m = ((float)$avgSec) / 60.0;
    if ($m >= 0.5 && $m <= 60) {
      $avgPerQueueMin = $m;
      $avgSource = "เฉลี่ยจากคิวที่จบจริง (7 วันล่าสุด)";
    }
  }

  // =========================
  // hourly chart
  // =========================
  $stmt = $pdo->prepare("
    SELECT HOUR(created_at) AS h, COUNT(*) AS c
    FROM queues
    WHERE shop_id = ? AND queue_date = ?
    GROUP BY HOUR(created_at)
    ORDER BY h ASC
  ");
  $stmt->execute([$shop_id, $today]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $START_H = 8;
  $END_H   = 20;

  $labels = [];
  $values = [];

  for ($h = $START_H; $h <= $END_H; $h++) {
    $labels[] = sprintf("%02d:00", $h);
    $values[] = 0;
  }

  foreach ($rows as $r) {
    $h = (int)($r["h"] ?? -1);
    $c = (int)($r["c"] ?? 0);

    if ($h >= $START_H && $h <= $END_H) {
      $idx = $h - $START_H;
      $values[$idx] = $c;
    }
  }

  // =========================
  // latest queues
  // =========================
  $stmt = $pdo->prepare("
    SELECT
      queue_id,
      queue_no,
      customer_name,
      customer_phone,
      customer_note,
      status,
      created_at
    FROM queues
    WHERE shop_id = ? AND queue_date = ?
    ORDER BY
      CASE status
        WHEN 'calling' THEN 1
        WHEN 'waiting' THEN 2
        WHEN 'served' THEN 3
        WHEN 'received' THEN 4
        WHEN 'cancel' THEN 5
        ELSE 6
      END,
      queue_no ASC
    LIMIT 8
  ");
  $stmt->execute([$shop_id, $today]);
  $latestQueues = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // =========================
  // daily chart (7 days)
  // =========================
  $stmt = $pdo->prepare("
    SELECT
      queue_date,
      COUNT(*) AS c
    FROM queues
    WHERE shop_id = ?
      AND queue_date BETWEEN DATE_SUB(?, INTERVAL 6 DAY) AND ?
    GROUP BY queue_date
    ORDER BY queue_date ASC
  ");
  $stmt->execute([$shop_id, $today, $today]);
  $dailyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $dailyMap = [];
  for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $dailyMap[$d] = 0;
  }

  foreach ($dailyRows as $r) {
    $d = (string)($r["queue_date"] ?? "");
    if ($d !== "" && array_key_exists($d, $dailyMap)) {
      $dailyMap[$d] = (int)($r["c"] ?? 0);
    }
  }

  $dailyLabels = [];
  $dailyValues = [];
  foreach ($dailyMap as $dateKey => $countVal) {
    $dailyLabels[] = substr($dateKey, 5); // MM-DD
    $dailyValues[] = $countVal;
  }

  // =========================
  // status chart
  // =========================
  $statusChartLabels = ["รอคิว", "กำลังเรียก", "พร้อมรับ", "รับแล้ว", "ยกเลิก"];
  $statusChartValues = [$waiting, $calling, $served, $received, $cancel];

  ok([
    "shop" => [
      "shop_id"      => (int)$shop["shop_id"],
      "name"         => $shop["name"],
      "status"       => $shop["status"],
      "status_label" => statusLabel((string)$shop["status"]),
      "open_time"    => $shop["open_time"] ? substr((string)$shop["open_time"], 0, 5) : null,
      "close_time"   => $shop["close_time"] ? substr((string)$shop["close_time"], 0, 5) : null,
      "queue_limit"  => $shop["queue_limit"] === null ? null : (int)$shop["queue_limit"],

      "type_id"       => $shop["type_id"] === null ? null : (int)$shop["type_id"],
      "type_name"     => $shop["type_name"] ?? null,
      "category_name" => $shop["category_name"] ?? null,
      "type_label"    => ($shop["type_name"] ?? null)
        ? (($shop["category_name"] ? $shop["category_name"] . " • " : "") . $shop["type_name"])
        : null,

      "avg_per_queue_min" => round($avgPerQueueMin, 1),
      "avg_source"        => $avgSource,
    ],

    "queues" => [
      "total"    => $total,
      "waiting"  => $waiting,
      "calling"  => $calling,
      "served"   => $served,
      "received" => $received,
      "cancel"   => $cancel,
      "pending"  => $pending,
      "current"  => $current
    ],

    "menu" => [
      "total"   => (int)($ms["menu_total"] ?? 0),
      "soldout" => (int)($ms["menu_soldout"] ?? 0),
    ],

    "hourly" => [
      "labels" => $labels,
      "values" => $values
    ],

    "daily" => [
      "labels" => $dailyLabels,
      "values" => $dailyValues
    ],

    "status_chart" => [
      "labels" => $statusChartLabels,
      "values" => $statusChartValues
    ],

    "latest_queues" => $latestQueues
  ]);

} catch (Throwable $e) {
  err("server error: " . $e->getMessage(), 500);
}