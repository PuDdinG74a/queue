<?php
require_once __DIR__ . "/_auth.php";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

$requestedShopId = isset($_GET["shop_id"]) ? (int)$_GET["shop_id"] : (int)($_POST["shop_id"] ?? 0);
$shop_id = enforceOwnerShopAccess($requestedShopId);

// โหลดรายการประเภท
$types = $pdo->query("
  SELECT t.type_id, t.type_name, c.category_name
  FROM shop_types t
  LEFT JOIN shop_categories c ON c.category_id = t.category_id
  ORDER BY c.category_name ASC, t.type_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// โหลดข้อมูลร้าน
$stmt = $pdo->prepare("
  SELECT shop_id, lock_id, type_id, name, open_time, close_time, status, queue_limit, eta_per_queue_min
  FROM shops
  WHERE shop_id = ?
  LIMIT 1
");
$stmt->execute([$shop_id]);
$shop = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shop) {
  http_response_code(404);
  echo "ไม่พบร้าน shop_id=" . $shop_id;
  exit;
}

$msg = "";
$msgColor = "#111";

function cleanTime($t, $fallback){
  $t = trim((string)$t);
  if ($t === "") return $fallback;
  if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) return $fallback;
  return substr($t, 0, 5);
}

function th_status_label($s){
  return match((string)$s){
    "open"   => "เปิดรับคิว",
    "closed" => "ปิดร้าน",
    "break"  => "พัก",
    "full"   => "คิวเต็ม",
    default  => (string)$s
  };
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = trim((string)($_POST["action"] ?? "save"));

  if ($action === "reset") {
    $stmt = $pdo->prepare("
      UPDATE shops
      SET status = 'open',
          open_time = NULL,
          close_time = NULL,
          queue_limit = NULL,
          eta_per_queue_min = NULL
      WHERE shop_id = ?
      LIMIT 1
    ");
    $stmt->execute([$shop_id]);

    $msg = "ล้างค่าตั้งค่าแล้ว";
    $msgColor = "#111";

  } else {
    $name = trim((string)($_POST["name"] ?? ""));
    $status = trim((string)($_POST["status"] ?? "open"));
    $open_time  = cleanTime($_POST["open_time"] ?? "", "08:00");
    $close_time = cleanTime($_POST["close_time"] ?? "", "17:00");

    $type_id = (int)($_POST["type_id"] ?? 0);
    $type_id_sql = ($type_id > 0) ? $type_id : null;

    $ql_raw = trim((string)($_POST["queue_limit"] ?? ""));
    $queue_limit = ($ql_raw === "") ? null : (int)$ql_raw;
    if ($queue_limit !== null && $queue_limit < 1) $queue_limit = 1;

    $eta_raw = trim((string)($_POST["eta_per_queue_min"] ?? ""));
    $eta_per_queue_min = ($eta_raw === "") ? null : (float)$eta_raw;
    if ($eta_per_queue_min !== null) {
      if ($eta_per_queue_min < 0.5) $eta_per_queue_min = 0.5;
      if ($eta_per_queue_min > 60)  $eta_per_queue_min = 60;
    }

    if ($name === "") {
      $msg = "กรุณาใส่ชื่อร้าน";
      $msgColor = "#ef4444";
    } elseif ($open_time >= $close_time) {
      $msg = "เวลาเปิดต้องน้อยกว่าเวลาปิด";
      $msgColor = "#ef4444";
    } else {
      $allowed = ["open", "closed", "break", "full"];
      if (!in_array($status, $allowed, true)) $status = "open";

      $stmt = $pdo->prepare("
        UPDATE shops
        SET name = ?,
            status = ?,
            open_time = ?,
            close_time = ?,
            type_id = ?,
            queue_limit = ?,
            eta_per_queue_min = ?
        WHERE shop_id = ?
        LIMIT 1
      ");
      $stmt->execute([
        mb_substr($name, 0, 150),
        $status,
        $open_time,
        $close_time,
        $type_id_sql,
        $queue_limit,
        $eta_per_queue_min,
        $shop_id
      ]);

      $msg = "บันทึกข้อมูลร้านแล้ว";
      $msgColor = "#16a34a";
    }
  }

  $stmt = $pdo->prepare("
    SELECT shop_id, lock_id, type_id, name, open_time, close_time, status, queue_limit, eta_per_queue_min
    FROM shops
    WHERE shop_id = ?
    LIMIT 1
  ");
  $stmt->execute([$shop_id]);
  $shop = $stmt->fetch(PDO::FETCH_ASSOC) ?: $shop;
}

$openVal  = $shop["open_time"] ? substr((string)$shop["open_time"], 0, 5) : "08:00";
$closeVal = $shop["close_time"] ? substr((string)$shop["close_time"], 0, 5) : "17:00";

$typeLabel = "ไม่ระบุ";
if (!empty($shop["type_id"])) {
  foreach ($types as $t) {
    if ((int)$t["type_id"] === (int)$shop["type_id"]) {
      $typeLabel = ($t["category_name"] ? $t["category_name"] . " • " : "") . $t["type_name"];
      break;
    }
  }
}

$st = (string)($shop["status"] ?? "open");
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ตั้งค่าร้าน | Owner</title>
  <link rel="stylesheet" href="./owner-style.css?v=1">
  <style>
    .status-pill{display:inline-flex;gap:8px;align-items:center;padding:6px 10px;border-radius:999px;font-weight:800;font-size:13px;border:1px solid #eee;background:#fff;}
    .dot{width:10px;height:10px;border-radius:999px;background:#16a34a;display:inline-block;}
    .dot.closed{background:#ef4444;}
    .dot.break{background:#f59e0b;}
    .dot.full{background:#111;}
    .radio-row{display:flex;gap:10px;flex-wrap:wrap;}
    .radio-row label{display:flex;gap:8px;align-items:center;border:1px solid #eee;border-radius:12px;padding:10px 12px;cursor:pointer;}
  </style>
</head>
<body>
  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-logo">MN</div>
        <div class="title">
          <b id="shopNameSide"><?php echo h($shop["name"]); ?></b>
          <small>Owner Panel</small>
        </div>
      </div>

      <nav class="nav">
        <a href="owner-dashboard.php?shop_id=<?php echo (int)$shop_id; ?>">📊 แดชบอร์ด</a>
        <a href="shop_owner.php?shop_id=<?php echo (int)$shop_id; ?>">🧾 ออเดอร์/คิวลูกค้า</a>
        <a href="owner-menu.php?shop_id=<?php echo (int)$shop_id; ?>">🍜 เมนูอาหาร</a>
        <a class="active" href="owner-settings.php?shop_id=<?php echo (int)$shop_id; ?>">⚙️ ตั้งค่าร้าน</a>
        <a href="owner-reports.php?shop_id=<?= (int)$shop_id ?>">📑 รายงาน</a>
        <a href="../logout.php">🚪 ออกจากระบบ</a>
      </nav>

      <div class="footer">ตั้งค่าข้อมูลร้าน</div>
    </aside>

    <main class="main">
      <div class="topbar">
        <div>
          <div class="page-title">ตั้งค่าร้าน</div>
          <div class="small">shop_id=<?php echo (int)$shop_id; ?> • lock_id=<?php echo (int)$shop["lock_id"]; ?></div>
        </div>

        <?php
          $dot = ($st === "closed") ? "closed" : (($st === "break") ? "break" : (($st === "full") ? "full" : ""));
        ?>
        <div class="status-pill">
          <span class="dot <?php echo $dot; ?>"></span>
          <span><?php echo h(th_status_label($st)); ?></span>
        </div>
      </div>

      <div class="grid">
        <section class="card col-6">
          <h3 style="margin:0 0 8px 0;">ข้อมูลร้าน (บันทึกลงฐานข้อมูลจริง)</h3>
          <div class="hr"></div>

          <div class="note" style="margin-bottom:12px;">
            การตั้งค่านี้มีผลต่อการรับคิวของลูกค้าแบบ real-time
          </div>

          <?php if ($msg !== ""): ?>
            <p class="small" style="margin:0 0 10px;color:<?php echo h($msgColor); ?>;">
              <?php echo h($msg); ?>
            </p>
          <?php endif; ?>

          <form method="post" action="owner-settings.php?shop_id=<?php echo (int)$shop_id; ?>">
            <input type="hidden" name="shop_id" value="<?php echo (int)$shop_id; ?>">
            <input type="hidden" name="action" value="save">

            <div class="field">
              <label>ชื่อร้าน</label>
              <input name="name" type="text" value="<?php echo h($shop["name"]); ?>" required>
            </div>

            <div class="field">
              <label>สถานะร้าน (มีผลต่อการรับคิว)</label>
              <div class="radio-row">
                <label><input type="radio" name="status" value="open"   <?php echo ($st === "open" ? "checked" : ""); ?>> เปิดรับคิว</label>
                <label><input type="radio" name="status" value="closed" <?php echo ($st === "closed" ? "checked" : ""); ?>> ปิดร้าน</label>
                <label><input type="radio" name="status" value="break"  <?php echo ($st === "break" ? "checked" : ""); ?>> พัก</label>
                <label><input type="radio" name="status" value="full"   <?php echo ($st === "full" ? "checked" : ""); ?>> คิวเต็ม</label>
              </div>
            </div>

            <div class="row">
              <div class="field">
                <label>เวลาเปิด</label>
                <input name="open_time" type="time" value="<?php echo h($openVal); ?>">
              </div>
              <div class="field">
                <label>เวลาปิด</label>
                <input name="close_time" type="time" value="<?php echo h($closeVal); ?>">
              </div>
            </div>

            <div class="row">
              <div class="field">
                <label>ประเภทร้าน</label>
                <select name="type_id">
                  <option value="0">-- ไม่ระบุประเภท --</option>
                  <?php foreach ($types as $t): ?>
                    <?php
                      $tid = (int)$t["type_id"];
                      $selected = ((int)$shop["type_id"] === $tid) ? "selected" : "";
                      $label = ($t["category_name"] ? $t["category_name"] . " • " : "") . $t["type_name"];
                    ?>
                    <option value="<?php echo $tid; ?>" <?php echo $selected; ?>>
                      <?php echo h($label); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="small" style="margin-top:6px;color:#6b7280;">
                  * ถ้าต้องการเพิ่ม/แก้ไขประเภท กรุณาแจ้งแอดมิน
                </div>
              </div>

              <div class="field">
                <label>queue_limit (จำกัดคิวต่อวัน)</label>
                <input name="queue_limit" type="number"
                       value="<?php echo h($shop["queue_limit"] ?? ""); ?>"
                       min="1" placeholder="เว้นว่าง = ไม่จำกัด">
              </div>
            </div>

            <div class="row">
              <div class="field">
                <label>เวลาต่อคิวโดยประมาณ (นาที/คิว)</label>
                <input name="eta_per_queue_min" type="number" step="0.5" min="0.5" max="60"
                       value="<?php echo h($shop["eta_per_queue_min"] ?? ""); ?>"
                       placeholder="เช่น 3, 5, 7.5 (เว้นว่าง = ให้ระบบคำนวณจากคิวจริง)">
                <div class="small" style="margin-top:6px;color:#6b7280;">
                  * ใช้เป็นค่าเริ่มต้นตอนข้อมูลคิวจริงยังน้อย ระบบจะพยายามใช้ค่าเฉลี่ยจากคิวที่เสิร์ฟจริงก่อน
                </div>
              </div>
            </div>

            <div class="actions">
              <button class="btn btn-primary" type="submit">บันทึก</button>
              <a class="btn btn-outline"
                 href="shop_owner.php?shop_id=<?php echo (int)$shop_id; ?>"
                 style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;">
                กลับหน้าคิว
              </a>
            </div>
          </form>
        </section>

        <section class="card col-6">
          <h3 style="margin:0 0 8px 0;">พรีวิว</h3>
          <div class="hr"></div>
          <div class="note">
            <b>ชื่อร้าน:</b> <?php echo h($shop["name"]); ?><br>
            <b>สถานะ:</b> <?php echo h(th_status_label($shop["status"])); ?><br>
            <b>เวลา:</b> <?php echo h($openVal); ?> - <?php echo h($closeVal); ?><br>
            <b>ประเภท:</b> <?php echo h($typeLabel); ?><br>
            <b>queue_limit:</b> <?php echo h($shop["queue_limit"] ?? "ไม่จำกัด"); ?><br>
            <b>ETA นาที/คิว:</b> <?php echo h($shop["eta_per_queue_min"] ?? "ไม่ระบุ"); ?>
          </div>

          <div class="hr"></div>
          <h3 style="margin:0 0 8px 0;">รีเซ็ต</h3>
          <div class="small">ล้างค่า open_time/close_time/queue_limit/eta_per_queue_min และตั้ง status กลับเป็น open</div>

          <form method="post" action="owner-settings.php?shop_id=<?php echo (int)$shop_id; ?>"
                onsubmit="return confirm('ต้องการล้างค่าตั้งค่าร้านใช่ไหม?');"
                style="margin-top:10px;">
            <input type="hidden" name="shop_id" value="<?php echo (int)$shop_id; ?>">
            <input type="hidden" name="action" value="reset">
            <button class="btn btn-danger" type="submit">ล้างค่าตั้งค่า</button>
          </form>
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