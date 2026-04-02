<?php
session_start();
require_once __DIR__ . "/../config.php";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

function hasColumn(PDO $pdo, string $table, string $column): bool {
  try{
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
      if (($col['Field'] ?? '') === $column) return true;
    }
  }catch(Throwable $e){
  }
  return false;
}

function status_label($s) {
  return match($s) {
    "open"   => "เปิดรับคิว",
    "closed" => "ปิดร้าน",
    "break"  => "หยุดพัก",
    "full"   => "คิวเต็ม",
    default  => "เปิดรับคิว",
  };
}

function status_class($s) {
  return match($s) {
    "open"   => "open",
    "closed" => "closed",
    "break"  => "break",
    "full"   => "full",
    default  => "open",
  };
}

function render_price($it) {
  if (isset($it["price"]) && $it["price"] !== null && $it["price"] !== '') {
    return (int)$it["price"] . " บาท";
  }
  if (
    isset($it["price_min"], $it["price_max"]) &&
    $it["price_min"] !== null && $it["price_max"] !== null &&
    $it["price_min"] !== '' && $it["price_max"] !== ''
  ) {
    return (int)$it["price_min"] . " / " . (int)$it["price_max"] . " บาท";
  }
  return "-";
}

function fmt_time($t){
  if(!$t) return "-";
  return substr((string)$t, 0, 5);
}

function format_wait_text(int $minutes): string {
  if ($minutes <= 0) return "รับคิวได้ทันที";
  if ($minutes < 60) return "ประมาณ {$minutes} นาที";

  $h = intdiv($minutes, 60);
  $m = $minutes % 60;

  if ($m === 0) return "ประมาณ {$h} ชม.";
  return "ประมาณ {$h} ชม. {$m} นาที";
}

$shop_id = isset($_GET["shop_id"]) ? (int)$_GET["shop_id"] : 0;
if ($shop_id <= 0) {
  http_response_code(400);
  echo "กรุณาเปิดแบบนี้: shop.php?shop_id=1";
  exit;
}

$today = date('Y-m-d');
$hasEtaPerQueue = hasColumn($pdo, 'shops', 'eta_per_queue_min');
$etaSelect = $hasEtaPerQueue ? ", s.eta_per_queue_min" : ", NULL AS eta_per_queue_min";

// 1) ข้อมูลร้าน + ประเภท/หมวด + ตำแหน่ง
$stmt = $pdo->prepare("
  SELECT
    s.shop_id, s.name, s.status, s.open_time, s.close_time, s.queue_limit, s.type_id, s.lock_id
    {$etaSelect},
    t.type_name, c.category_name,
    l.lock_no,
    d.dome_id, d.dome_name
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
  echo "ไม่พบร้าน shop_id = " . h($shop_id);
  exit;
}

// 2) เมนูร้าน
$stmt = $pdo->prepare("
  SELECT item_id, item_name, price, price_min, price_max, image_url, is_available
  FROM menu_items
  WHERE shop_id = ? AND (is_available = 1 OR is_available IS NULL)
  ORDER BY item_id ASC
");
$stmt->execute([$shop_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3) คิวที่กำลังเรียก
$stmt = $pdo->prepare("
  SELECT
    COALESCE(
      (SELECT MIN(queue_no)
       FROM queues
       WHERE shop_id=? AND queue_date=? AND status='calling'),
      (SELECT MAX(queue_no)
       FROM queues
       WHERE shop_id=? AND queue_date=? AND status IN ('served','received')),
      0
    ) AS current_no
");
$stmt->execute([$shop_id, $today, $shop_id, $today]);
$currentQueue = (int)($stmt->fetchColumn() ?: 0);

// 4) คิวที่รอ
$stmt = $pdo->prepare("
  SELECT COUNT(*) AS waiting_count
  FROM queues
  WHERE shop_id = ? AND queue_date = ? AND status IN ('waiting','calling')
");
$stmt->execute([$shop_id, $today]);
$waitingCount = (int)($stmt->fetchColumn() ?: 0);

// 5) label ต่าง ๆ
$typeLabel = "-";
if (!empty($shop["type_name"])) {
  $typeLabel = (!empty($shop["category_name"]) ? $shop["category_name"] . " • " : "") . $shop["type_name"];
}

$locationLabel = "-";
if (!empty($shop["dome_name"]) || !empty($shop["lock_no"])) {
  $parts = [];
  if (!empty($shop["dome_name"])) $parts[] = $shop["dome_name"];
  if (!empty($shop["lock_no"])) $parts[] = "ล็อก " . $shop["lock_no"];
  $locationLabel = implode(" • ", $parts);
}

// 6) เต็มตาม limit
$queueLimit = $shop["queue_limit"];
$isFullByLimit = ($queueLimit !== null && $waitingCount >= (int)$queueLimit);

$displayStatus = (string)($shop["status"] ?? "open");
if ($displayStatus === "open" && $isFullByLimit) {
  $displayStatus = "full";
}

$canTakeQueue = ((string)$shop["status"] === "open") && !$isFullByLimit;

// 7) ข้อความสถานะ
$reasonText = "";
if ((string)$shop["status"] !== "open") {
  $reasonText = "ตอนนี้รับคิวไม่ได้ (" . status_label((string)$shop["status"]) . ")";
} elseif ($isFullByLimit) {
  $reasonText = "วันนี้คิวเต็มแล้ว" . ($queueLimit !== null ? " (จำกัด " . (int)$queueLimit . " คิว)" : "");
} else {
  $reasonText = "สามารถรับคิวและส่งออเดอร์ได้";
}

// 8) เวลารอโดยประมาณ
$etaPerQueueMin = (int)($shop["eta_per_queue_min"] ?? 0);
$estimatedWaitMin = ($etaPerQueueMin > 0) ? ($waitingCount * $etaPerQueueMin) : 0;

if ($etaPerQueueMin > 0) {
  $estimatedWaitText = format_wait_text($estimatedWaitMin);
  $estimatedWaitSub = "คำนวณจาก {$waitingCount} คิว × {$etaPerQueueMin} นาที/คิว";
} else {
  $estimatedWaitText = ($waitingCount <= 0) ? "รับคิวได้ทันที" : "มีคิวรอ {$waitingCount} คิว";
  $estimatedWaitSub = "เวลาจริงอาจเปลี่ยนแปลงตามการให้บริการของร้าน";
}

$lastQueueId = isset($_SESSION["last_queue_id"]) ? (int)$_SESSION["last_queue_id"] : 0;
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= h($shop["name"]) ?> | รับคิวและสั่งออเดอร์</title>
  <link rel="stylesheet" href="./style.css?v=3">

  <style>
    :root{
      --yellow:#FFCD22;
      --yellow-soft:#fff7d8;
      --bg:#f6f6f6;
      --card:#ffffff;
      --line:#e8e8e8;
      --text:#232323;
      --muted:#666;
      --shadow:0 8px 24px rgba(0,0,0,.06);
      --radius:18px;
      --success-bg:#e8fff0;
      --success-text:#0f7a36;
      --danger-bg:#ffecec;
      --danger-text:#b00020;
      --warn-bg:#fff4df;
      --warn-text:#8a4b00;
      --full-bg:#eef1ff;
      --full-text:#2d3a8c;
    }

    *{ box-sizing:border-box; }

    body{
      margin:0;
      font-family:'Prompt',system-ui,sans-serif;
      background:linear-gradient(180deg,#fffdf7 0%, #f6f6f6 220px);
      color:var(--text);
    }

    a{ text-decoration:none; color:inherit; }

    .container{
      max-width:980px;
      margin:0 auto;
      padding:16px;
    }

    .card{
      background:var(--card);
      border:1px solid var(--line);
      border-radius:var(--radius);
      padding:16px;
      box-shadow:var(--shadow);
      margin-bottom:14px;
    }

    .topbar-actions{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
    }

    .nav-btn,
    .btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:44px;
      padding:10px 14px;
      border-radius:999px;
      font-weight:700;
      border:1px solid #ddd;
      cursor:pointer;
      font:inherit;
      transition:.18s ease;
      text-decoration:none;
    }

    .nav-btn{
      background:#fff;
      color:#222;
    }

    .btn{
      width:100%;
      border-radius:14px;
    }

    .btn-primary{
      background:linear-gradient(135deg,var(--yellow),#ffd84d);
      color:#222;
      border-color:#f0cd4a;
      box-shadow:0 6px 14px rgba(255,205,34,.22);
      font-size:16px;
      font-weight:800;
      min-height:48px;
    }

    .btn-secondary{
      background:#f3f3f3;
      color:#111;
      min-height:48px;
    }

    .nav-btn:hover,
    .btn:hover{
      transform:translateY(-1px);
    }

    .hero-head{
      display:flex;
      justify-content:space-between;
      gap:12px;
      align-items:flex-start;
      flex-wrap:wrap;
    }

    .shop-title{
      margin:0 0 8px;
      font-size:28px;
      line-height:1.25;
    }

    .badge{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:34px;
      padding:6px 12px;
      border-radius:999px;
      font-size:14px;
      font-weight:800;
    }

    .open{ background:var(--success-bg); color:var(--success-text); }
    .closed{ background:var(--danger-bg); color:var(--danger-text); }
    .break{ background:var(--warn-bg); color:var(--warn-text); }
    .full{ background:var(--full-bg); color:var(--full-text); }

    .subline{
      color:var(--muted);
      font-size:14px;
      line-height:1.7;
      margin:0;
    }

    .status-box{
      margin-top:12px;
      padding:13px 14px;
      border-radius:14px;
      font-size:14px;
      line-height:1.6;
      font-weight:700;
    }

    .status-box.ready{
      border:1px solid #f0dea0;
      background:#fff9e8;
      color:#6a5400;
    }

    .status-box.blocked{
      border:1px solid #f3c1c1;
      background:#fff3f3;
      color:#9f1f1f;
    }

    .stats-grid{
      display:grid;
      grid-template-columns:repeat(4,1fr);
      gap:12px;
    }

    .stat-box{
      background:#fafafa;
      border:1px solid #ececec;
      border-radius:16px;
      padding:14px;
      min-height:110px;
    }

    .stat-label{
      color:#777;
      font-size:13px;
      margin:0 0 6px;
    }

    .stat-value{
      font-size:30px;
      font-weight:800;
      line-height:1.15;
      margin:0;
    }

    .stat-value.wait{
      font-size:22px;
    }

    .stat-sub{
      color:#666;
      font-size:12px;
      line-height:1.55;
      margin:8px 0 0;
    }

    .queue-form-grid{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:12px;
    }

    .field{
      margin-bottom:12px;
    }

    .field label{
      display:block;
      font-size:14px;
      font-weight:700;
      margin-bottom:6px;
      color:#333;
    }

    .input,
    textarea{
      width:100%;
      border:1px solid #ddd;
      border-radius:14px;
      padding:12px 14px;
      font:inherit;
      background:#fff;
    }

    .input:focus,
    textarea:focus{
      outline:none;
      border-color:#e2b400;
      box-shadow:0 0 0 4px rgba(255,205,34,.18);
    }

    textarea{
      resize:vertical;
      min-height:110px;
    }

    .small{
      font-size:13px;
      color:#666;
      line-height:1.6;
    }

    .form-actions{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:10px;
      margin-top:10px;
    }

    .menu-head{
      display:flex;
      justify-content:space-between;
      align-items:flex-end;
      gap:10px;
      flex-wrap:wrap;
      margin-bottom:12px;
    }

    .menu{
      display:grid;
      grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));
      gap:12px;
    }

    .menu-item{
      border:1px solid #eee;
      border-radius:16px;
      padding:12px;
      background:#fff;
    }

    .menu-thumb{
      height:140px;
      border-radius:12px;
      background:#f2f2f2;
      display:flex;
      align-items:center;
      justify-content:center;
      color:#777;
      font-size:13px;
      margin-bottom:10px;
      overflow:hidden;
    }

    .menu-thumb img{
      width:100%;
      height:100%;
      object-fit:cover;
      display:block;
    }

    .menu-name{
      font-weight:800;
      margin:0 0 6px;
      line-height:1.45;
      font-size:16px;
    }

    .menu-price{
      margin:0;
      color:#333;
      font-size:14px;
    }

    .menu-empty{
      padding:12px 14px;
      border-radius:14px;
      background:#fafafa;
      border:1px dashed #d8d8d8;
      color:#666;
      font-size:14px;
    }

    .confirm-overlay{
      position:fixed;
      inset:0;
      background:rgba(0,0,0,.38);
      display:none;
      align-items:center;
      justify-content:center;
      z-index:99999;
      padding:16px;
    }

    .confirm-box{
      width:min(100%, 420px);
      background:#fff;
      border-radius:20px;
      box-shadow:0 18px 45px rgba(0,0,0,.18);
      border:1px solid #eee;
      padding:20px 18px 16px;
    }

    .confirm-icon{
      width:68px;
      height:68px;
      border-radius:999px;
      background:#fff7d8;
      display:flex;
      align-items:center;
      justify-content:center;
      font-size:30px;
      margin:0 auto 12px;
    }

    .confirm-title{
      text-align:center;
      font-size:22px;
      font-weight:800;
      margin:0 0 8px;
      color:#222;
    }

    .confirm-text{
      text-align:center;
      color:#555;
      font-size:14px;
      line-height:1.6;
      margin:0 0 14px;
    }

    .confirm-info{
      background:#fafafa;
      border:1px solid #eee;
      border-radius:14px;
      padding:12px;
      margin-bottom:14px;
    }

    .confirm-info div{
      font-size:14px;
      color:#333;
      line-height:1.7;
    }

    .confirm-actions{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:10px;
    }

    @media (max-width: 860px){
      .stats-grid{
        grid-template-columns:repeat(2,1fr);
      }

      .queue-form-grid{
        grid-template-columns:1fr;
      }

      .form-actions{
        grid-template-columns:1fr;
      }
    }

    @media (max-width: 640px){
      .container{
        padding:12px;
      }

      .shop-title{
        font-size:24px;
      }

      .stats-grid{
        grid-template-columns:1fr 1fr;
      }

      .topbar-actions{
        display:grid;
        grid-template-columns:1fr 1fr 1fr;
        gap:8px;
      }

      .nav-btn{
        width:100%;
        padding:10px 8px;
        font-size:14px;
      }

      .menu{
        grid-template-columns:1fr 1fr;
      }

      .menu-thumb{
        height:120px;
      }

      .stat-value{
        font-size:24px;
      }

      .stat-value.wait{
        font-size:18px;
      }
    }

    @media (max-width: 520px){
      .confirm-actions{
        grid-template-columns:1fr;
      }
    }

    @media (max-width: 420px){
      .menu{
        grid-template-columns:1fr;
      }

      .stats-grid{
        grid-template-columns:1fr 1fr;
      }
    }
  </style>
</head>

<body>
  <div class="container">

    <div class="card">
      <div class="topbar-actions">
        <button class="nav-btn" onclick="goBack()">⬅ ย้อนกลับ</button>
        <button class="nav-btn" onclick="goHome()">🏠 หน้าหลัก</button>
        <button class="nav-btn" onclick="goMyQueue()" <?= ($lastQueueId > 0 ? '' : 'disabled') ?> style="<?= ($lastQueueId > 0 ? '' : 'opacity:.55;cursor:not-allowed;') ?>">📋 คิวของฉัน</button>
      </div>
    </div>

    <div class="card">
      <div class="hero-head">
        <div>
          <h1 class="shop-title"><?= h($shop["name"]) ?></h1>
          <p class="subline"><b>ประเภท:</b> <span id="shopTypeLabel"><?= h($typeLabel) ?></span></p>
          <p class="subline"><b>ตำแหน่งร้าน:</b> <span id="shopLocationLabel"><?= h($locationLabel) ?></span></p>
          <p class="subline">
            <b>เวลาเปิด–ปิด:</b>
            <?= h(fmt_time($shop["open_time"])) ?> – <?= h(fmt_time($shop["close_time"])) ?>
            <?php if($shop["queue_limit"] !== null): ?>
              • <b>จำกัดคิว:</b> <?= (int)$shop["queue_limit"] ?> คิว/วัน
            <?php endif; ?>
          </p>
        </div>

        <div>
          <span id="shopStatusBadge" class="badge <?= h(status_class($displayStatus)) ?>">
            <?= h(status_label($displayStatus)) ?>
          </span>
        </div>
      </div>

      <div id="shopReasonBox" class="status-box <?= $canTakeQueue ? 'ready' : 'blocked' ?>">
        <span id="shopReasonText"><?= h($reasonText) ?></span>
      </div>
    </div>

    <div class="card">
      <div class="stats-grid">
        <div class="stat-box">
          <p class="stat-label">คิวที่กำลังเรียก</p>
          <p id="currentQueueNo" class="stat-value"><?= ($currentQueue > 0) ? (int)$currentQueue : "-" ?></p>
          <p class="stat-sub">คิวที่ร้านกำลังให้บริการตอนนี้</p>
        </div>

        <div class="stat-box">
          <p class="stat-label">คิวที่รอ</p>
          <p id="waitingCountNo" class="stat-value"><?= (int)$waitingCount ?></p>
          <p class="stat-sub">รวม waiting และ calling</p>
        </div>

        <div class="stat-box">
          <p class="stat-label">เวลารอ</p>
          <p id="estimatedWaitText" class="stat-value wait"><?= h($estimatedWaitText) ?></p>
          <p id="estimatedWaitSub" class="stat-sub"><?= h($estimatedWaitSub) ?></p>
        </div>

        <div class="stat-box">
          <p class="stat-label">ตำแหน่งร้าน</p>
          <p id="shopLocationBig" class="stat-value wait"><?= h($locationLabel) ?></p>
          <p class="stat-sub">ใช้ประกอบการเดินหาร้าน</p>
        </div>
      </div>
    </div>

    <div class="card">
      <h2 style="margin:0 0 8px;">รับคิวและสั่งออเดอร์</h2>
      <p class="small" style="margin:0 0 14px;">กรอกข้อมูลและรายการอาหารด้านล่าง แล้วกดรับคิวและส่งออเดอร์ได้ทันที เมื่อรับคิวแล้วสามารถติดตามสถานะได้ที่หน้า “คิวของฉัน”</p>

      <form id="takeQueueForm" method="post" action="take_queue.php" style="margin:0;">
        <input type="hidden" name="shop_id" value="<?= (int)$shop["shop_id"] ?>">

        <div class="queue-form-grid">
          <div class="field">
            <label for="customerName">ชื่อเล่นสำหรับเรียกคิว <span style="color:#b00020;">*</span></label>
            <input
              id="customerName"
              class="input"
              type="text"
              name="customer_name"
              placeholder="เช่น มายด์, แป้ง, บีม"
              required
            >
          </div>

          <div class="field">
            <label for="customerPhone">เบอร์โทรศัพท์ 10 หลัก <span style="color:#b00020;">*</span></label>
            <input
              id="customerPhone"
              class="input"
              type="tel"
              name="customer_phone"
              placeholder="เช่น 0891234567"
              inputmode="numeric"
              pattern="[0-9]{10}"
              maxlength="10"
              minlength="10"
              required
            >
            <div class="small" style="margin-top:6px;">กรอกเบอร์โทร 10 หลัก ตัวเลขเท่านั้น</div>
          </div>
        </div>

        <div class="field">
          <label for="customerNote">รายการออเดอร์ / หมายเหตุถึงร้าน</label>
          <textarea
            id="customerNote"
            name="customer_note"
            placeholder="เช่น ข้าวมันไก่ธรรมดา 1 ไม่เอาหนัง / ข้าวผัดไม่ใส่หอม เพิ่มไข่ดาว"
          ></textarea>
          <div class="small" style="margin-top:6px;">พิมพ์รายการอาหารหรือรายละเอียดเพิ่มเติมที่ต้องการให้ร้านเห็น</div>
        </div>

        <div class="form-actions">
          <button
            id="takeQueueBtn"
            class="btn btn-primary"
            type="submit"
            <?= $canTakeQueue ? "" : "disabled" ?>
            style="<?= $canTakeQueue ? "" : "opacity:.55;cursor:not-allowed;" ?>"
          >
            รับคิวและส่งออเดอร์
          </button>

          <button
            class="btn btn-secondary"
            type="button"
            onclick="goMyQueue()"
            <?= ($lastQueueId > 0) ? "" : "disabled" ?>
            style="<?= ($lastQueueId > 0) ? "" : "opacity:.55;cursor:not-allowed;" ?>"
          >
            ไปหน้าคิวของฉัน
          </button>
        </div>

        <p id="takeQueueWarn" class="small" style="margin:12px 0 0;color:#b00020;<?= $canTakeQueue ? 'display:none;' : '' ?>">
          <?= h($reasonText) ?>
        </p>

        <p class="small" style="margin:10px 0 0;">หน้านี้อัปเดตสถานะอัตโนมัติทุก 6 วินาที โดยไม่ต้องรีโหลดหน้า</p>
      </form>
    </div>

    <div class="card">
      <div class="menu-head">
        <div>
          <h2 style="margin:0 0 6px;">เมนูของร้าน</h2>
          <p class="small" style="margin:0;">ดูรายการอาหารก่อนกดรับคิวและส่งออเดอร์</p>
        </div>
        <div class="small">ทั้งหมด <?= count($items) ?> รายการ</div>
      </div>

      <?php if (count($items) === 0): ?>
        <div class="menu-empty">ยังไม่มีเมนู (ร้านยังไม่ได้ตั้งค่าเมนู)</div>
      <?php else: ?>
        <div class="menu">
          <?php foreach ($items as $it): ?>
            <div class="menu-item">
              <div class="menu-thumb">
                <?php if (!empty($it["image_url"])): ?>
                  <img src="<?= h($it["image_url"]) ?>" alt="<?= h($it["item_name"]) ?>">
                <?php else: ?>
                  ไม่มีรูปภาพ
                <?php endif; ?>
              </div>
              <p class="menu-name"><?= h($it["item_name"]) ?></p>
              <p class="menu-price">ราคา <?= h(render_price($it)) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>

  <div id="queueConfirmOverlay" class="confirm-overlay">
    <div class="confirm-box">
      <div class="confirm-icon">📋</div>
      <h3 class="confirm-title">ยืนยันการรับคิวและออเดอร์</h3>
      <p class="confirm-text">
        กรุณาตรวจสอบข้อมูลก่อนกดยืนยัน ระบบจะบันทึกคิวและออเดอร์ของคุณทันที
      </p>

      <div class="confirm-info">
        <div><b>ร้าน:</b> <?= h($shop["name"]) ?></div>
        <div><b>เวลารอโดยประมาณ:</b> <span id="confirmWaitText"><?= h($estimatedWaitText) ?></span></div>
        <div><b>ชื่อสำหรับเรียกคิว:</b> <span id="confirmCustomerName">-</span></div>
        <div><b>เบอร์โทร:</b> <span id="confirmCustomerPhone">-</span></div>
      </div>

      <div class="confirm-actions">
        <button type="button" id="cancelQueueBtn" class="btn btn-secondary">ยกเลิก</button>
        <button type="button" id="confirmQueueBtn" class="btn btn-primary">ยืนยันรับคิวและออเดอร์</button>
      </div>
    </div>
  </div>

  <script>
    const shopId = <?= (int)$shop_id ?>;
    const lastQueueId = <?= (int)$lastQueueId ?>;
    const etaPerQueueMin = <?= (int)$etaPerQueueMin ?>;

    let isSubmitting = false;

    function goBack(){
      if (window.history.length > 1) {
        window.history.back();
      } else {
        location.href = "index.php";
      }
    }

    function goHome(){
      location.href = "index.php";
    }

    function goMyQueue(){
      if(!lastQueueId) return;
      location.href = `my-queue.php?queue_id=${lastQueueId}`;
    }

    function setBadgeClass(el, status){
      el.classList.remove("open","closed","break","full");
      el.classList.add(status);
    }

    function formatWaitText(minutes){
      minutes = Number(minutes) || 0;
      if(minutes <= 0) return "รับคิวได้ทันที";
      if(minutes < 60) return `ประมาณ ${minutes} นาที`;

      const h = Math.floor(minutes / 60);
      const m = minutes % 60;
      if(m === 0) return `ประมาณ ${h} ชม.`;
      return `ประมาณ ${h} ชม. ${m} นาที`;
    }

    function updateEstimatedWait(waitingCount){
      const textEl = document.getElementById("estimatedWaitText");
      const subEl  = document.getElementById("estimatedWaitSub");
      const confirmWaitEl = document.getElementById("confirmWaitText");

      if(!textEl || !subEl) return;

      const count = Number(waitingCount) || 0;
      let waitText = "";

      if(etaPerQueueMin > 0){
        const totalMin = count * etaPerQueueMin;
        waitText = formatWaitText(totalMin);
        textEl.textContent = waitText;
        subEl.textContent = `คำนวณจาก ${count} คิว × ${etaPerQueueMin} นาที/คิว`;
      }else{
        waitText = (count <= 0) ? "รับคิวได้ทันที" : `มีคิวรอ ${count} คิว`;
        textEl.textContent = waitText;
        subEl.textContent = "เวลาจริงอาจเปลี่ยนแปลงตามการให้บริการของร้าน";
      }

      if(confirmWaitEl){
        confirmWaitEl.textContent = waitText;
      }
    }

    const form = document.getElementById("takeQueueForm");
    const takeBtn = document.getElementById("takeQueueBtn");
    const customerNameEl = document.getElementById("customerName");
    const customerPhoneEl = document.getElementById("customerPhone");

    const overlay = document.getElementById("queueConfirmOverlay");
    const confirmQueueBtn = document.getElementById("confirmQueueBtn");
    const cancelQueueBtn = document.getElementById("cancelQueueBtn");
    const confirmCustomerName = document.getElementById("confirmCustomerName");
    const confirmCustomerPhone = document.getElementById("confirmCustomerPhone");

    function openConfirmPopup(){
      overlay.style.display = "flex";
    }

    function closeConfirmPopup(){
      overlay.style.display = "none";
    }

    function normalizePhone(phone){
      return String(phone || "").replace(/\D/g, "");
    }

    form.addEventListener("submit", function(e){
      e.preventDefault();

      if(isSubmitting) return;

      const name = customerNameEl.value.trim();
      const phone = normalizePhone(customerPhoneEl.value);

      if(!name){
        alert("กรุณากรอกชื่อก่อนรับคิว");
        customerNameEl.focus();
        return;
      }

      if(phone.length !== 10){
        alert("กรุณากรอกเบอร์โทรศัพท์ให้ครบ 10 หลัก");
        customerPhoneEl.focus();
        return;
      }

      customerPhoneEl.value = phone;

      confirmCustomerName.textContent = name || "-";
      confirmCustomerPhone.textContent = phone;

      openConfirmPopup();
    });

    confirmQueueBtn.addEventListener("click", function(){
      if(isSubmitting) return;

      isSubmitting = true;
      closeConfirmPopup();

      takeBtn.disabled = true;
      takeBtn.textContent = "กำลังส่งคิวและออเดอร์...";
      takeBtn.style.opacity = ".7";

      form.submit();
    });

    cancelQueueBtn.addEventListener("click", function(){
      closeConfirmPopup();
    });

    overlay.addEventListener("click", function(e){
      if(e.target === overlay){
        closeConfirmPopup();
      }
    });

    customerPhoneEl.addEventListener("input", function(){
      this.value = normalizePhone(this.value).slice(0, 10);
    });

    const badgeEl   = document.getElementById("shopStatusBadge");
    const reasonEl  = document.getElementById("shopReasonText");
    const reasonBox = document.getElementById("shopReasonBox");
    const currentEl = document.getElementById("currentQueueNo");
    const waitingEl = document.getElementById("waitingCountNo");
    const warnEl    = document.getElementById("takeQueueWarn");
    const typeEl    = document.getElementById("shopTypeLabel");

    async function refreshStatus(){
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 2500);

      try{
        const res = await fetch(`shop_status.php?shop_id=${shopId}&_=${Date.now()}`, {
          cache: "no-store",
          credentials: "same-origin",
          signal: controller.signal
        });

        const data = await res.json();
        if(!data.ok) return;

        if(currentEl){
          currentEl.textContent = Number(data.currentQueue) > 0 ? data.currentQueue : "-";
        }

        if(waitingEl){
          waitingEl.textContent = data.waitingCount;
        }

        updateEstimatedWait(data.waitingCount);

        if(badgeEl){
          setBadgeClass(badgeEl, data.displayStatus);
          badgeEl.textContent = data.statusLabel;
        }

        if(reasonEl){
          reasonEl.textContent = data.reasonText;
        }

        if(reasonBox){
          reasonBox.classList.remove("ready", "blocked");
          reasonBox.classList.add(data.canTakeQueue ? "ready" : "blocked");
        }

        if(typeEl && data.type_label !== undefined){
          typeEl.textContent = data.type_label || "-";
        }

        if(takeBtn){
          if(data.canTakeQueue && !isSubmitting){
            takeBtn.disabled = false;
            takeBtn.textContent = "รับคิวและส่งออเดอร์";
            takeBtn.style.opacity = "";
            takeBtn.style.cursor = "";
            if(warnEl) warnEl.style.display = "none";
          }else if(!data.canTakeQueue){
            takeBtn.disabled = true;
            takeBtn.style.opacity = ".55";
            takeBtn.style.cursor = "not-allowed";
            if(warnEl){
              warnEl.textContent = data.reasonText;
              warnEl.style.display = "block";
            }
          }
        }

      }catch(e){
      }finally{
        clearTimeout(timeout);
      }
    }

    updateEstimatedWait(<?= (int)$waitingCount ?>);
    refreshStatus();
    setInterval(refreshStatus, 6000);
  </script>
</body>
</html>