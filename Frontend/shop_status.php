<?php
// Frontend/shop_status.php
require_once __DIR__ . "/../config.php";

header("Content-Type: application/json; charset=utf-8");

$shop_id = isset($_GET["shop_id"]) ? (int)$_GET["shop_id"] : 0;
if ($shop_id <= 0) {
  http_response_code(400);
  echo json_encode([
    "ok" => false,
    "error" => "shop_id ไม่ถูกต้อง"
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$today = date('Y-m-d');

function status_label($s) {
  return match($s) {
    "open"   => "เปิดรับคิว",
    "closed" => "ปิด",
    "break"  => "หยุดพัก",
    "full"   => "คิวเต็ม",
    default  => "เปิดรับคิว",
  };
}

function hasColumn(PDO $pdo, string $table, string $column): bool {
  try {
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
      if (($col["Field"] ?? "") === $column) {
        return true;
      }
    }
  } catch (Throwable $e) {
  }
  return false;
}

try {
  $hasEtaPerQueue = hasColumn($pdo, 'shops', 'eta_per_queue_min');
  $etaSelect = $hasEtaPerQueue ? ", s.eta_per_queue_min" : ", NULL AS eta_per_queue_min";

  // ร้าน + ประเภท/หมวด + limit + ตำแหน่งร้าน
  $stmt = $pdo->prepare("
    SELECT
      s.shop_id,
      s.status,
      s.queue_limit,
      s.type_id
      {$etaSelect},
      t.type_name,
      c.category_name,
      l.lock_no,
      d.dome_id,
      d.dome_name
    FROM shops s
    LEFT JOIN shop_types t ON t.type_id = s.type_id
    LEFT JOIN shop_categories c ON c.category_id = t.category_id
    LEFT JOIN locks l ON l.lock_id = s.lock_id
    LEFT JOIN domes d ON d.dome_id = l.dome_id
    WHERE s.shop_id = ?
    LIMIT 1
  ");
  $stmt->execute([$shop_id]);
  $shop = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$shop) {
    http_response_code(404);
    echo json_encode([
      "ok" => false,
      "error" => "ไม่พบร้าน"
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $status = (string)($shop["status"] ?? "open");
  $queueLimit = $shop["queue_limit"];

  // type label
  $typeLabel = "-";
  if (!empty($shop["type_name"])) {
    $typeLabel = (!empty($shop["category_name"]) ? $shop["category_name"] . " • " : "") . $shop["type_name"];
  }

  // location label
  $locationLabel = "-";
  $locationParts = [];
  if (!empty($shop["dome_name"])) {
    $locationParts[] = $shop["dome_name"];
  }
  if (!empty($shop["lock_no"])) {
    $locationParts[] = "ล็อก " . $shop["lock_no"];
  }
  if (!empty($locationParts)) {
    $locationLabel = implode(" • ", $locationParts);
  }

  // currentQueue
  // 1) ถ้ามี calling ให้ใช้คิวที่กำลังเรียก
  // 2) ถ้าไม่มี calling ให้ใช้คิวล่าสุดที่จบแล้ว (served / received)
  // 3) ถ้ายังไม่มีเลย = 0
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
  $currentQueue = (int)($stmt->fetchColumn() ?: 0);

  // waitingCount = waiting + calling
  $stmt = $pdo->prepare("
    SELECT COUNT(*) AS waiting_count
    FROM queues
    WHERE shop_id = ? AND queue_date = ? AND status IN ('waiting','calling')
  ");
  $stmt->execute([$shop_id, $today]);
  $waitingCount = (int)($stmt->fetchColumn() ?: 0);

  // เต็มตาม limit
  $isFullByLimit = ($queueLimit !== null && $waitingCount >= (int)$queueLimit);

  // สถานะที่ใช้แสดงผลหน้า shop
  $displayStatus = $status;
  if ($displayStatus === "open" && $isFullByLimit) {
    $displayStatus = "full";
  }

  // รับคิวได้ไหม
  $canTakeQueue = ($status === "open") && !$isFullByLimit;

  // ข้อความอธิบาย
  if ($status !== "open") {
    $reasonText = "ตอนนี้รับคิวไม่ได้ (" . status_label($status) . ")";
  } elseif ($isFullByLimit) {
    $reasonText = "วันนี้คิวเต็มแล้ว" . ($queueLimit !== null ? " (จำกัด " . (int)$queueLimit . " คิว)" : "");
  } else {
    $reasonText = "สามารถกดรับคิวได้";
  }

  // eta
  $etaPerQueueMin = (int)($shop["eta_per_queue_min"] ?? 0);
  $estimatedWaitMin = ($etaPerQueueMin > 0) ? ($waitingCount * $etaPerQueueMin) : 0;

  echo json_encode([
    "ok" => true,
    "shop_id" => (int)$shop_id,

    "currentQueue" => $currentQueue,
    "waitingCount" => $waitingCount,

    "status" => $status,
    "displayStatus" => $displayStatus,
    "statusLabel" => status_label($displayStatus),

    "queueLimit" => $queueLimit,
    "canTakeQueue" => $canTakeQueue,
    "reasonText" => $reasonText,

    "type_label" => $typeLabel,
    "location_label" => $locationLabel,

    "dome_id" => isset($shop["dome_id"]) ? (int)$shop["dome_id"] : null,
    "dome_name" => $shop["dome_name"] ?? null,
    "lock_no" => isset($shop["lock_no"]) ? (int)$shop["lock_no"] : null,

    "eta_per_queue_min" => $etaPerQueueMin,
    "estimated_wait_min" => $estimatedWaitMin
  ], JSON_UNESCAPED_UNICODE);

  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "error" => "server error"
  ], JSON_UNESCAPED_UNICODE);
  exit;
}