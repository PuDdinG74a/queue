<?php
require_once __DIR__ . "/_auth.php";

function h($s){
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

$requestedShopId = isset($_GET["shop_id"]) ? (int)$_GET["shop_id"] : 0;
$shop_id = enforceOwnerShopAccess($requestedShopId);

// โหลดข้อมูลร้าน
$stmt = $pdo->prepare("
  SELECT shop_id, name
  FROM shops
  WHERE shop_id = ?
  LIMIT 1
");
$stmt->execute([$shop_id]);
$shop = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shop) {
  http_response_code(404);
  echo "ไม่พบร้าน";
  exit;
}

// ==========================
// filter
// ==========================
$today = date("Y-m-d");
$defaultFrom = date("Y-m-d", strtotime("-6 days"));

$dateFrom = trim((string)($_GET["date_from"] ?? $defaultFrom));
$dateTo   = trim((string)($_GET["date_to"] ?? $today));
$reportType = trim((string)($_GET["report_type"] ?? "queue_summary"));
$exportCsv = isset($_GET["export"]) && $_GET["export"] === "csv";

$allowedReportTypes = [
  "queue_summary",
  "queue_daily",
  "queue_detail",
  "queue_wait_time",
  "menu_status"
];

if (!in_array($reportType, $allowedReportTypes, true)) {
  $reportType = "queue_summary";
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
  $dateFrom = $defaultFrom;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
  $dateTo = $today;
}

if ($dateFrom > $dateTo) {
  [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$reportTitle = match($reportType) {
  "queue_summary"   => "สรุปคิวตามช่วงวันที่",
  "queue_daily"     => "สรุปคิวรายวัน",
  "queue_detail"    => "รายละเอียดรายการคิว",
  "queue_wait_time" => "รายงานเวลารอ/เวลาจัดการคิว",
  "menu_status"     => "รายงานสถานะเมนู",
  default           => "รายงาน"
};

$headers = [];
$rows = [];
$note = "";

// ==========================
// report queries
// ==========================
if ($reportType === "queue_summary") {
  $stmt = $pdo->prepare("
    SELECT
      COUNT(*) AS total_queue,
      SUM(CASE WHEN status = 'waiting'  THEN 1 ELSE 0 END) AS waiting_count,
      SUM(CASE WHEN status = 'calling'  THEN 1 ELSE 0 END) AS calling_count,
      SUM(CASE WHEN status = 'served'   THEN 1 ELSE 0 END) AS served_count,
      SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) AS received_count,
      SUM(CASE WHEN status = 'cancel'   THEN 1 ELSE 0 END) AS cancel_count
    FROM queues
    WHERE shop_id = ?
      AND queue_date BETWEEN ? AND ?
  ");
  $stmt->execute([$shop_id, $dateFrom, $dateTo]);
  $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

  $headers = [
    "จำนวนคิวทั้งหมด",
    "รอเรียก",
    "กำลังเรียก",
    "ออเดอร์พร้อมรับ",
    "รับออเดอร์แล้ว",
    "ยกเลิก"
  ];

  $rows[] = [
    (int)($data["total_queue"] ?? 0),
    (int)($data["waiting_count"] ?? 0),
    (int)($data["calling_count"] ?? 0),
    (int)($data["served_count"] ?? 0),
    (int)($data["received_count"] ?? 0),
    (int)($data["cancel_count"] ?? 0),
  ];

  $note = "แสดงสรุปจำนวนคิวทั้งหมดของร้านในช่วงวันที่ที่เลือก";
}

if ($reportType === "queue_daily") {
  $stmt = $pdo->prepare("
    SELECT
      queue_date,
      COUNT(*) AS total_queue,
      SUM(CASE WHEN status = 'waiting'  THEN 1 ELSE 0 END) AS waiting_count,
      SUM(CASE WHEN status = 'calling'  THEN 1 ELSE 0 END) AS calling_count,
      SUM(CASE WHEN status = 'served'   THEN 1 ELSE 0 END) AS served_count,
      SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) AS received_count,
      SUM(CASE WHEN status = 'cancel'   THEN 1 ELSE 0 END) AS cancel_count
    FROM queues
    WHERE shop_id = ?
      AND queue_date BETWEEN ? AND ?
    GROUP BY queue_date
    ORDER BY queue_date ASC
  ");
  $stmt->execute([$shop_id, $dateFrom, $dateTo]);
  $dataRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $headers = [
    "วันที่",
    "จำนวนคิวทั้งหมด",
    "รอเรียก",
    "กำลังเรียก",
    "ออเดอร์พร้อมรับ",
    "รับออเดอร์แล้ว",
    "ยกเลิก"
  ];

  foreach ($dataRows as $r) {
    $rows[] = [
      $r["queue_date"] ?? "-",
      (int)($r["total_queue"] ?? 0),
      (int)($r["waiting_count"] ?? 0),
      (int)($r["calling_count"] ?? 0),
      (int)($r["served_count"] ?? 0),
      (int)($r["received_count"] ?? 0),
      (int)($r["cancel_count"] ?? 0),
    ];
  }

  $note = "แสดงสรุปจำนวนคิวแยกรายวันตามช่วงวันที่ที่เลือก";
}

if ($reportType === "queue_detail") {
  $stmt = $pdo->prepare("
    SELECT
      queue_date,
      queue_no,
      customer_name,
      customer_phone,
      customer_note,
      status,
      created_at,
      called_at,
      served_at
    FROM queues
    WHERE shop_id = ?
      AND queue_date BETWEEN ? AND ?
    ORDER BY queue_date DESC, queue_no ASC
  ");
  $stmt->execute([$shop_id, $dateFrom, $dateTo]);
  $dataRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $headers = [
    "วันที่",
    "เลขคิว",
    "ชื่อลูกค้า",
    "เบอร์โทร",
    "หมายเหตุ/รายการ",
    "สถานะ",
    "เวลาเข้าคิว",
    "เวลาเรียกคิว",
    "เวลาออเดอร์พร้อมรับ"
  ];

  foreach ($dataRows as $r) {
    $statusTH = match((string)($r["status"] ?? "")) {
      "waiting"  => "รอเรียก",
      "calling"  => "กำลังเรียก",
      "served"   => "ออเดอร์พร้อมรับ",
      "received" => "รับออเดอร์แล้ว",
      "cancel"   => "ยกเลิก",
      default    => "-"
    };

    $rows[] = [
      $r["queue_date"] ?? "-",
      "#" . (int)($r["queue_no"] ?? 0),
      $r["customer_name"] ?: "-",
      $r["customer_phone"] ?: "-",
      $r["customer_note"] ?: "-",
      $statusTH,
      $r["created_at"] ?: "-",
      $r["called_at"] ?: "-",
      $r["served_at"] ?: "-",
    ];
  }

  $note = "แสดงรายละเอียดคิวทั้งหมดของร้านตามช่วงวันที่ที่เลือก";
}

if ($reportType === "queue_wait_time") {
  $stmt = $pdo->prepare("
    SELECT
      queue_date,
      COUNT(*) AS total_done,
      ROUND(AVG(
        CASE
          WHEN called_at IS NOT NULL
            THEN TIMESTAMPDIFF(MINUTE, created_at, called_at)
          ELSE NULL
        END
      ), 2) AS avg_wait_to_call,
      ROUND(AVG(
        CASE
          WHEN served_at IS NOT NULL AND called_at IS NOT NULL
            THEN TIMESTAMPDIFF(MINUTE, called_at, served_at)
          WHEN served_at IS NOT NULL
            THEN TIMESTAMPDIFF(MINUTE, created_at, served_at)
          ELSE NULL
        END
      ), 2) AS avg_process_time
    FROM queues
    WHERE shop_id = ?
      AND queue_date BETWEEN ? AND ?
      AND status IN ('served', 'received')
    GROUP BY queue_date
    ORDER BY queue_date ASC
  ");
  $stmt->execute([$shop_id, $dateFrom, $dateTo]);
  $dataRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $headers = [
    "วันที่",
    "จำนวนคิวที่จบแล้ว",
    "เฉลี่ยเวลารอก่อนถูกเรียก (นาที)",
    "เฉลี่ยเวลาจัดการคิว (นาที)"
  ];

  foreach ($dataRows as $r) {
    $rows[] = [
      $r["queue_date"] ?? "-",
      (int)($r["total_done"] ?? 0),
      $r["avg_wait_to_call"] !== null ? $r["avg_wait_to_call"] : "-",
      $r["avg_process_time"] !== null ? $r["avg_process_time"] : "-",
    ];
  }

  $note = "คำนวณจากคิวที่มีสถานะ served หรือ received เท่านั้น";
}

if ($reportType === "menu_status") {
  $stmt = $pdo->prepare("
    SELECT
      item_id,
      item_name,
      price,
      price_min,
      price_max,
      is_available,
      image_url
    FROM menu_items
    WHERE shop_id = ?
    ORDER BY item_id DESC
  ");
  $stmt->execute([$shop_id]);
  $dataRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $headers = [
    "รหัสเมนู",
    "ชื่อเมนู",
    "ราคา",
    "สถานะ",
    "รูปภาพ"
  ];

  foreach ($dataRows as $r) {
    $priceText = "-";
    if ($r["price_min"] !== null && $r["price_max"] !== null) {
      $priceText = $r["price_min"] . " / " . $r["price_max"] . " (ธรรมดา/พิเศษ)";
    } elseif ($r["price"] !== null) {
      $priceText = $r["price"] . " บาท";
    }

    $rows[] = [
      (int)($r["item_id"] ?? 0),
      $r["item_name"] ?: "-",
      $priceText,
      ((int)($r["is_available"] ?? 0) === 1) ? "มีขาย" : "หมด",
      $r["image_url"] ?: "-"
    ];
  }

  $note = "แสดงสถานะเมนูปัจจุบันของร้าน ณ ตอนที่เปิดรายงาน";
}

// ==========================
// export csv
// ==========================
if ($exportCsv) {
  $filename = "owner_report_" . $reportType . "_" . $dateFrom . "_to_" . $dateTo . ".csv";

  header("Content-Type: text/csv; charset=UTF-8");
  header("Content-Disposition: attachment; filename=\"" . $filename . "\"");

  $out = fopen("php://output", "w");

  // UTF-8 BOM สำหรับ Excel ภาษาไทย
  fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

  fputcsv($out, [$reportTitle]);
  fputcsv($out, ["ร้าน", $shop["name"] ?? "-"]);
  fputcsv($out, ["ช่วงวันที่", $dateFrom . " ถึง " . $dateTo]);
  if ($note !== "") {
    fputcsv($out, ["หมายเหตุ", $note]);
  }
  fputcsv($out, []);

  if (!empty($headers)) {
    fputcsv($out, $headers);
  }

  foreach ($rows as $row) {
    fputcsv($out, $row);
  }

  fclose($out);
  exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>รายงานร้านค้า | Owner</title>
  <link rel="stylesheet" href="./owner-style.css?v=1">
  <style>
    .filters{
      display:grid;
      grid-template-columns: repeat(4, minmax(180px, 1fr));
      gap:12px;
      align-items:end;
    }
    .note-box{
      margin-top:10px;
      padding:12px 14px;
      border-radius:12px;
      background:#fafafa;
      border:1px solid #eee;
      color:#555;
      font-size:13px;
      line-height:1.45;
    }
    .table-wrap{
      overflow:auto;
      border:1px solid #eee;
      border-radius:14px;
      background:#fff;
    }
    .table-wrap table{
      min-width:900px;
    }
    .empty-state{
      padding:18px;
      color:#666;
      font-size:14px;
    }
    @media (max-width: 900px){
      .filters{
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-logo">MN</div>
        <div class="title">
          <b><?= h($shop["name"]) ?></b>
          <small>Owner Panel</small>
        </div>
      </div>

      <nav class="nav">
        <a href="owner-dashboard.php?shop_id=<?= (int)$shop_id ?>">📊 แดชบอร์ด</a>
        <a href="shop_owner.php?shop_id=<?= (int)$shop_id ?>">🧾 ออเดอร์/คิวลูกค้า</a>
        <a href="owner-menu.php?shop_id=<?= (int)$shop_id ?>">🍜 เมนูอาหาร</a>
        <a href="owner-settings.php?shop_id=<?= (int)$shop_id ?>">⚙️ ตั้งค่าร้าน</a>
        <a class="active" href="owner-reports.php?shop_id=<?= (int)$shop_id ?>">📑 รายงาน</a>
        <a href="../logout.php">🚪 ออกจากระบบ</a>
      </nav>

      <div class="footer">รายงานเฉพาะร้านของคุณ</div>
    </aside>

    <main class="main">
      <div class="topbar">
        <div>
          <div class="page-title">รายงานร้านค้า</div>
          <div class="small">เลือกช่วงวันที่และประเภทรายงานที่ต้องการดู</div>
        </div>
      </div>

      <div class="grid">
        <section class="card col-12">
          <h3 style="margin:0 0 8px 0;">ตัวกรองรายงาน</h3>
          <div class="hr"></div>

          <form method="get" action="owner-reports.php">
            <input type="hidden" name="shop_id" value="<?= (int)$shop_id ?>">

            <div class="filters">
              <div class="field">
                <label>ประเภทรายงาน</label>
                <select name="report_type">
                  <option value="queue_summary" <?= $reportType === "queue_summary" ? "selected" : "" ?>>สรุปคิวตามช่วงวันที่</option>
                  <option value="queue_daily" <?= $reportType === "queue_daily" ? "selected" : "" ?>>สรุปคิวรายวัน</option>
                  <option value="queue_detail" <?= $reportType === "queue_detail" ? "selected" : "" ?>>รายละเอียดรายการคิว</option>
                  <option value="queue_wait_time" <?= $reportType === "queue_wait_time" ? "selected" : "" ?>>เวลารอ/เวลาจัดการคิว</option>
                  <option value="menu_status" <?= $reportType === "menu_status" ? "selected" : "" ?>>สถานะเมนู</option>
                </select>
              </div>

              <div class="field">
                <label>จากวันที่</label>
                <input type="date" name="date_from" value="<?= h($dateFrom) ?>">
              </div>

              <div class="field">
                <label>ถึงวันที่</label>
                <input type="date" name="date_to" value="<?= h($dateTo) ?>">
              </div>

              <div class="actions">
                <button class="btn btn-primary" type="submit">แสดงรายงาน</button>
              </div>
            </div>
          </form>
        </section>

        <section class="card col-12">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
            <div>
              <h3 style="margin:0 0 8px 0;"><?= h($reportTitle) ?></h3>
              <div class="small">
                ช่วงวันที่: <b><?= h($dateFrom) ?></b> ถึง <b><?= h($dateTo) ?></b>
              </div>
            </div>

            <div class="actions">
              <a
                class="btn btn-outline"
                href="owner-reports.php?shop_id=<?= (int)$shop_id ?>&report_type=<?= h($reportType) ?>&date_from=<?= h($dateFrom) ?>&date_to=<?= h($dateTo) ?>&export=csv"
                style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;"
              >
                Export CSV
              </a>
            </div>
          </div>

          <?php if ($note !== ""): ?>
            <div class="note-box"><?= h($note) ?></div>
          <?php endif; ?>

          <div class="hr"></div>

          <?php if (count($rows) === 0): ?>
            <div class="empty-state">ไม่พบข้อมูลในช่วงวันที่หรือเงื่อนไขที่เลือก</div>
          <?php else: ?>
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <?php foreach ($headers as $head): ?>
                      <th><?= h($head) ?></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $row): ?>
                    <tr>
                      <?php foreach ($row as $cell): ?>
                        <td><?= h($cell) ?></td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      </div>
    </main>
  </div>

  <script>
    window.OWNER_NOTIFY_SHOP_ID = <?= (int)$shop_id ?>;
  </script>
  <script src="owner-notify.js"></script>
</body>
</html>