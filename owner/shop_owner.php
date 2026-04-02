<?php
require_once __DIR__ . "/_auth.php";

$requestedShopId = isset($_GET["shop_id"]) ? (int)$_GET["shop_id"] : 0;
$shop_id = enforceOwnerShopAccess($requestedShopId);

// ใช้วันที่จาก PHP กัน CURDATE เพี้ยน
$today = date('Y-m-d');

// รับ filter
$statusFilter = trim((string)($_GET["status"] ?? "all"));
$allowedFilters = ["all", "waiting", "calling", "served", "received", "cancel"];
if (!in_array($statusFilter, $allowedFilters, true)) {
  $statusFilter = "all";
}

// ร้าน
$stmt = $pdo->prepare("SELECT shop_id, name, status FROM shops WHERE shop_id = ?");
$stmt->execute([$shop_id]);
$shop = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shop) {
  http_response_code(404);
  echo "ไม่พบร้าน shop_id=" . $shop_id;
  exit;
}

// คิววันนี้ทั้งหมด สำหรับ summary
$stmt = $pdo->prepare("
  SELECT queue_id, queue_no, customer_name, customer_phone, customer_note, status, created_at
  FROM queues
  WHERE shop_id = ? AND queue_date = ?
  ORDER BY queue_no ASC
");
$stmt->execute([$shop_id, $today]);
$allOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// summary
$sumWaiting  = 0;
$sumCalling  = 0;
$sumServed   = 0;
$sumReceived = 0;
$sumCancel   = 0;
$callingNo   = "-";
$nextQueueNo = "-";

foreach ($allOrders as $o) {
  $st = trim((string)($o["status"] ?? ""));
  if ($st === "") $st = "waiting";

  if ($st === "waiting") {
    $sumWaiting++;
    if ($nextQueueNo === "-") {
      $nextQueueNo = "#" . (int)$o["queue_no"];
    }
  } elseif ($st === "calling") {
    $sumCalling++;
    if ($callingNo === "-") {
      $callingNo = "#" . (int)$o["queue_no"];
    }
  } elseif ($st === "served") {
    $sumServed++;
  } elseif ($st === "received") {
    $sumReceived++;
  } elseif ($st === "cancel") {
    $sumCancel++;
  }
}
$sumAll = count($allOrders);

// รายการคิวที่แสดงตาม filter
if ($statusFilter === "all") {
  $orders = $allOrders;
} else {
  $orders = array_values(array_filter($allOrders, function ($o) use ($statusFilter) {
    $st = trim((string)($o["status"] ?? ""));
    if ($st === "") $st = "waiting";
    return $st === $statusFilter;
  }));
}

function h($s){
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

function th_status($s){
  $s = trim((string)$s);
  if ($s === "") $s = "waiting";

  return match($s){
    "waiting"  => "รอเรียก",
    "calling"  => "กำลังเรียก",
    "served"   => "ออเดอร์พร้อมรับ",
    "received" => "รับออเดอร์แล้ว",
    "cancel"   => "ยกเลิก",
    default    => $s ?: "-"
  };
}

function status_color($s){
  $s = trim((string)$s);
  if ($s === "") $s = "waiting";

  return match($s){
    "waiting"  => "font-weight:900;color:#ef4444;",
    "calling"  => "font-weight:900;color:#111;",
    "served"   => "font-weight:900;color:#16a34a;",
    "received" => "font-weight:900;color:#089981;",
    "cancel"   => "font-weight:900;color:#6b7280;",
    default    => ""
  };
}

function filterLabel($status){
  return match($status){
    "waiting"  => "รอเรียก",
    "calling"  => "กำลังเรียก",
    "served"   => "ออเดอร์พร้อมรับ",
    "received" => "รับออเดอร์แล้ว",
    "cancel"   => "ยกเลิก",
    default    => "ทั้งหมด"
  };
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ออเดอร์/คิวลูกค้า | Owner</title>
  <link rel="stylesheet" href="./owner-style.css?v=4">
  <style>
    .note-cell{white-space:pre-wrap;}

    .status-chip{
      display:inline-block;
      padding:6px 10px;
      border-radius:999px;
      font-size:12px;
      font-weight:800;
    }
    .st-wait{background:#eef1ff;color:#2d3a8c;}
    .st-call{background:#fff4df;color:#8a4b00;}
    .st-served{background:#e8fff0;color:#0f7a36;}
    .st-received{background:#e6fff8;color:#00695c;}
    .st-cancel{background:#f1f1f1;color:#666;}

    .filter-bar{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      margin-top:12px;
    }
    .filter-chip{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:8px 12px;
      border-radius:999px;
      border:1px solid #ddd;
      background:#fff;
      color:#333;
      font-size:13px;
      font-weight:700;
      text-decoration:none;
    }
    .filter-chip.active{
      background:#111;
      color:#fff;
      border-color:#111;
    }

    .result-note{
      margin-top:10px;
      font-size:13px;
      color:#666;
    }

    .row-calling{
      background:#fff3cd;
      border-left:5px solid #f59e0b;
    }

    .row-next{
      background:#eefcff;
    }

    .actions{
      display:flex;
      gap:6px;
      flex-wrap:wrap;
    }

    .actions form{
      margin:0;
    }

    .actions .btn{
      white-space:nowrap;
    }

    @media (max-width:700px){
      table{
        font-size:13px;
      }

      .actions{
        flex-direction:column;
      }

      .actions .btn{
        width:100%;
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
          <b id="shopNameSide"><?= h($shop["name"]) ?></b>
          <small>Owner Panel</small>
        </div>
      </div>

      <nav class="nav">
        <a href="owner-dashboard.php?shop_id=<?= (int)$shop_id ?>">📊 แดชบอร์ด</a>
        <a class="active" href="shop_owner.php?shop_id=<?= (int)$shop_id ?>">🧾 ออเดอร์/คิวลูกค้า</a>
        <a href="owner-menu.php?shop_id=<?= (int)$shop_id ?>">🍜 เมนูอาหาร</a>
        <a href="owner-settings.php?shop_id=<?= (int)$shop_id ?>">⚙️ ตั้งค่าร้าน</a>
        <a href="owner-reports.php?shop_id=<?= (int)$shop_id ?>">📑 รายงาน</a>
        <a href="../logout.php">🚪 ออกจากระบบ</a>
      </nav>

      <div class="footer">จัดการคิวลูกค้า • เรียกคิว • ออเดอร์พร้อมรับ</div>
    </aside>

    <main class="main">
      <div class="topbar">
        <div>
          <div class="page-title">ออเดอร์/คิวลูกค้า</div>
          <div class="small">
            วันที่: <b><?= h($today) ?></b> | shop_id = <?= (int)$shop_id ?>
          </div>
        </div>

        <div class="actions">
          <form method="post" action="update_queue.php" style="margin:0;">
            <input type="hidden" name="shop_id" value="<?= (int)$shop_id ?>">
            <input type="hidden" name="mode" value="call_next">
            <button class="btn btn-primary" type="submit" style="font-size:18px;padding:14px 20px;" onclick="this.disabled=true;this.form.submit();">
              🔔 เรียกคิวถัดไป
            </button>
          </form>

          <button class="btn btn-outline" onclick="location.reload()">รีเฟรช</button>
        </div>
      </div>

      <div class="grid">
        <section class="card col-12">
          <div class="row">
            <div>
              <div class="small">รอเรียก</div>
              <div style="font-size:34px;font-weight:900;"><?= (int)$sumWaiting ?></div>
            </div>
            <div>
              <div class="small">กำลังเรียก</div>
              <div style="font-size:34px;font-weight:900;"><?= (int)$sumCalling ?></div>
            </div>
            <div>
              <div class="small">คิวที่กำลังเรียก</div>
              <div style="font-size:34px;font-weight:900;"><?= h($callingNo) ?></div>
            </div>
            <div>
              <div class="small">คิวถัดไป</div>
              <div style="font-size:34px;font-weight:900;"><?= h($nextQueueNo) ?></div>
            </div>
            <div>
              <div class="small">ออเดอร์พร้อมรับ</div>
              <div style="font-size:34px;font-weight:900;"><?= (int)$sumServed ?></div>
            </div>
            <div>
              <div class="small">รับออเดอร์แล้ว</div>
              <div style="font-size:34px;font-weight:900;"><?= (int)$sumReceived ?></div>
            </div>
            <div>
              <div class="small">ยกเลิก</div>
              <div style="font-size:34px;font-weight:900;"><?= (int)$sumCancel ?></div>
            </div>
            <div>
              <div class="small">ทั้งหมด</div>
              <div style="font-size:34px;font-weight:900;"><?= (int)$sumAll ?></div>
            </div>
          </div>

          <div class="hr"></div>

          <div class="note">
            สถานะที่ใช้: <b>waiting → calling → served → received</b> และ <b>cancel</b> สำหรับคิวที่ถูกยกเลิก
          </div>

          <div class="small" style="margin-top:8px;color:#666;">
            * กรุณากด <b>เรียกคิวถัดไป</b> ตามลำดับคิว และกด <b>ออเดอร์พร้อมรับ</b> เมื่อทำรายการเสร็จ
          </div>

          <div class="filter-bar">
            <a class="filter-chip <?= $statusFilter === 'all' ? 'active' : '' ?>"
               href="shop_owner.php?shop_id=<?= (int)$shop_id ?>&status=all">ทั้งหมด</a>

            <a class="filter-chip <?= $statusFilter === 'waiting' ? 'active' : '' ?>"
               href="shop_owner.php?shop_id=<?= (int)$shop_id ?>&status=waiting">รอเรียก</a>

            <a class="filter-chip <?= $statusFilter === 'calling' ? 'active' : '' ?>"
               href="shop_owner.php?shop_id=<?= (int)$shop_id ?>&status=calling">กำลังเรียก</a>

            <a class="filter-chip <?= $statusFilter === 'served' ? 'active' : '' ?>"
               href="shop_owner.php?shop_id=<?= (int)$shop_id ?>&status=served">ออเดอร์พร้อมรับ</a>

            <a class="filter-chip <?= $statusFilter === 'received' ? 'active' : '' ?>"
               href="shop_owner.php?shop_id=<?= (int)$shop_id ?>&status=received">รับออเดอร์แล้ว</a>

            <a class="filter-chip <?= $statusFilter === 'cancel' ? 'active' : '' ?>"
               href="shop_owner.php?shop_id=<?= (int)$shop_id ?>&status=cancel">ยกเลิก</a>
          </div>
        </section>

        <section class="card col-12">
          <h3 style="margin:0 0 8px 0;">รายการคิววันนี้</h3>
          <div class="small">กำลังแสดงรายการ: <b><?= h(filterLabel($statusFilter)) ?></b></div>
          <div class="hr"></div>

          <table class="table">
            <thead>
              <tr>
                <th style="width:90px;">คิว</th>
                <th>ลูกค้า</th>
                <th style="width:140px;">เบอร์</th>
                <th style="width:180px;">สถานะ</th>
                <th style="width:240px;">โน้ต</th>
                <th style="width:320px;">จัดการ</th>
              </tr>
            </thead>

            <tbody>
              <?php if (count($orders) === 0): ?>
                <tr><td colspan="6" class="small">ยังไม่มีรายการในสถานะนี้</td></tr>
              <?php else: ?>
                <?php $highlightedNext = false; ?>
                <?php foreach ($orders as $o): ?>
                  <?php
                    $st = trim((string)($o["status"] ?? "waiting"));
                    if ($st === "") $st = "waiting";

                    $chipClass = "st-wait";
                    if ($st === "calling") $chipClass = "st-call";
                    else if ($st === "served") $chipClass = "st-served";
                    else if ($st === "received") $chipClass = "st-received";
                    else if ($st === "cancel") $chipClass = "st-cancel";

                    $rowClass = "";
                    if ($st === "calling") {
                      $rowClass = "row-calling";
                    } elseif (!$highlightedNext && $st === "waiting") {
                      $rowClass = "row-next";
                      $highlightedNext = true;
                    }
                  ?>
                  <tr class="<?= h($rowClass) ?>">
                    <td style="font-weight:900;">#<?= (int)$o["queue_no"] ?></td>
                    <td>
                      <div style="font-weight:900;"><?= h($o["customer_name"] ?? "-") ?></div>
                      <div class="small"><?= h($o["created_at"] ?? "") ?></div>
                    </td>
                    <td class="small"><?= h($o["customer_phone"] ?? "-") ?></td>
                    <td style="<?= status_color($st) ?>">
                      <span class="status-chip <?= $chipClass ?>">
                        <?= h(th_status($st)) ?>
                      </span>
                    </td>
                    <td class="small note-cell"><?= h($o["customer_note"] ?: "-") ?></td>
                    <td>
                      <div class="actions">
                        <?php if ($st === "waiting"): ?>
                          <button class="btn btn-outline" type="button" disabled>
                            รอเรียกจากปุ่มด้านบน
                          </button>

                          <form method="post" action="update_queue.php" style="margin:0;" onsubmit="return confirm('ต้องการยกเลิกคิวนี้ใช่ไหม?');">
                            <input type="hidden" name="shop_id" value="<?= (int)$shop_id ?>">
                            <input type="hidden" name="queue_id" value="<?= (int)$o["queue_id"] ?>">
                            <input type="hidden" name="mode" value="cancel">
                            <button class="btn btn-danger" type="submit" onclick="this.disabled=true;this.form.submit();">ยกเลิก</button>
                          </form>

                        <?php elseif ($st === "calling"): ?>
                          <form method="post" action="update_queue.php" style="margin:0;">
                            <input type="hidden" name="shop_id" value="<?= (int)$shop_id ?>">
                            <input type="hidden" name="queue_id" value="<?= (int)$o["queue_id"] ?>">
                            <input type="hidden" name="mode" value="served">
                            <button class="btn btn-primary" type="submit" onclick="this.disabled=true;this.form.submit();">ออเดอร์พร้อมรับ</button>
                          </form>

                          <form method="post" action="update_queue.php" style="margin:0;" onsubmit="return confirm('ต้องการยกเลิกคิวนี้ใช่ไหม?');">
                            <input type="hidden" name="shop_id" value="<?= (int)$shop_id ?>">
                            <input type="hidden" name="queue_id" value="<?= (int)$o["queue_id"] ?>">
                            <input type="hidden" name="mode" value="cancel">
                            <button class="btn btn-danger" type="submit" onclick="this.disabled=true;this.form.submit();">ยกเลิก</button>
                          </form>

                        <?php elseif ($st === "served"): ?>
                          <button class="btn btn-outline" type="button" disabled>รอลูกค้ารับออเดอร์</button>

                        <?php elseif ($st === "received"): ?>
                          <button class="btn btn-outline" type="button" disabled>รับออเดอร์แล้ว</button>

                        <?php elseif ($st === "cancel"): ?>
                          <button class="btn btn-outline" type="button" disabled>สถานะสิ้นสุดแล้ว</button>

                        <?php else: ?>
                          <button class="btn btn-outline" type="button" disabled>สถานะสิ้นสุดแล้ว</button>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>

          <p class="result-note">
            *ฝั่งร้านใช้ปุ่ม <b>เรียกคิวถัดไป</b> และ <b>ออเดอร์พร้อมรับ</b> ส่วนหลังจากนั้นลูกค้าจะกดยืนยัน <b>รับออเดอร์แล้ว</b> จากฝั่งผู้ใช้
          </p>
        </section>
      </div>
    </main>
  </div>

  <script>
    setTimeout(function(){
      const rowCalling = document.querySelector('.row-calling');
      if (rowCalling) {
        rowCalling.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }, 500);

    setInterval(function(){
      if (!document.hidden) {
        location.reload();
      }
    }, 5000);
  </script>

  <script>
    window.OWNER_NOTIFY_SHOP_ID = <?= (int)$shop_id ?>;
  </script>
  <script src="owner-notify.js"></script>
</body>
</html>