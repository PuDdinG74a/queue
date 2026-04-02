<?php
// owner/owner-dashboard.php
require_once __DIR__ . "/_auth.php";

$requestedShopId = isset($_GET["shop_id"]) ? (int)$_GET["shop_id"] : 0;
$shop_id = enforceOwnerShopAccess($requestedShopId);

$today = date('Y-m-d');

// ✅ shop + type/category + eta_per_queue_min
$stmt = $pdo->prepare("
  SELECT
    s.shop_id, s.name, s.status, s.open_time, s.close_time, s.queue_limit, s.type_id,
    s.eta_per_queue_min,
    t.type_name, c.category_name
  FROM shops s
  LEFT JOIN shop_types t ON t.type_id = s.type_id
  LEFT JOIN shop_categories c ON c.category_id = t.category_id
  WHERE s.shop_id = ?
  LIMIT 1
");
$stmt->execute([$shop_id]);
$shop = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shop) {
  http_response_code(404);
  echo "ไม่พบร้าน";
  exit;
}

// ✅ queues summary today
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

// ✅ current queue
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
$currentNo = (int)($stmt->fetchColumn() ?: 0);

$pending = $waiting + $calling;

// ✅ menu summary
$stmt = $pdo->prepare("
  SELECT
    COUNT(*) AS menu_total,
    COALESCE(SUM(CASE WHEN is_available = 0 THEN 1 ELSE 0 END), 0) AS menu_soldout
  FROM menu_items
  WHERE shop_id = ?
");
$stmt->execute([$shop_id]);
$ms = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$menuTotal   = (int)($ms["menu_total"] ?? 0);
$menuSoldout = (int)($ms["menu_soldout"] ?? 0);

function statusLabel($s){
  return match($s){
    "open"   => "เปิด",
    "closed" => "ปิด",
    "break"  => "หยุดพัก",
    "full"   => "คิวเต็ม",
    default  => "เปิด"
  };
}

// ==========================
// ✅ ETA
// ==========================
$avgPerQueueMin = 3.0;
$avgSource = "ค่าเริ่มต้น";

// 1) จากร้านกำหนด
if (isset($shop["eta_per_queue_min"]) && $shop["eta_per_queue_min"] !== null) {
  $v = (float)$shop["eta_per_queue_min"];
  if ($v >= 0.5 && $v <= 60) {
    $avgPerQueueMin = $v;
    $avgSource = "ค่าที่ร้านกำหนด";
  }
}

// 2) จากคิวที่จบจริงย้อนหลัง 7 วัน
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

$etaTotalMin = (int)round($pending * $avgPerQueueMin);

function h($s){
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>แดชบอร์ด | Owner</title>
  <link rel="stylesheet" href="./owner-style.css?v=2">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    .kpi{
      display:grid;
      grid-template-columns: repeat(9, minmax(120px, 1fr));
      gap:10px;
      align-items:stretch;
    }
    @media (max-width: 1280px){
      .kpi{ grid-template-columns: repeat(5, minmax(140px, 1fr)); }
    }
    @media (max-width: 720px){
      .kpi{ grid-template-columns: repeat(2, minmax(140px, 1fr)); }
    }

    .kpi .box{
      border:1px solid #eee;
      border-radius:14px;
      padding:10px 12px;
      background:#fff;
      min-height:86px;
      display:flex;
      flex-direction:column;
      justify-content:space-between;
    }
    .kpi .lab{
      font-size:12px;
      color:#666;
      line-height:1.25;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .kpi .num{
      font-size:26px;
      font-weight:900;
      line-height:1;
      margin-top:4px;
    }

    .pill{
      display:inline-flex;
      gap:8px;
      align-items:center;
      padding:6px 10px;
      border-radius:999px;
      font-weight:800;
      font-size:13px;
      border:1px solid #eee;
      background:#fff;
    }
    .dot{
      width:10px;
      height:10px;
      border-radius:999px;
      background:#16a34a;
      display:inline-block;
    }
    .dot.closed{background:#ef4444;}
    .dot.break{background:#f59e0b;}
    .dot.full{background:#111;}

    .grid2{
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(320px,1fr));
      gap:12px;
    }
    .muted{color:#666;}
    .eta-hint{
      font-size:11px;
      color:#666;
      margin-top:6px;
      line-height:1.25;
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
      </div>
    </div>

    <nav class="nav">
      <a class="active" href="owner-dashboard.php?shop_id=<?= (int)$shop_id ?>">📊 แดชบอร์ด</a>
      <a href="shop_owner.php?shop_id=<?= (int)$shop_id ?>">🧾 ออเดอร์/คิวลูกค้า</a>
      <a href="owner-menu.php?shop_id=<?= (int)$shop_id ?>">🍜 เมนูอาหาร</a>
      <a href="owner-settings.php?shop_id=<?= (int)$shop_id ?>">⚙️ ตั้งค่าร้าน</a>
      <a href="owner-reports.php?shop_id=<?= (int)$shop_id ?>">📑 รายงาน</a>
      <a href="../logout.php">🚪 ออกจากระบบ</a>
    </nav>

    <div class="footer">ภาพรวมร้าน • วันนี้</div>
  </aside>

  <main class="main">
    <div class="topbar">
      <div>
        <div class="page-title">แดชบอร์ด</div>
        <div class="small">วันที่: <b><?= h($today) ?></b> • shop_id=<?= (int)$shop_id ?></div>
      </div>

      <?php
        $st = (string)($shop["status"] ?? "open");
        $dot = ($st === "closed") ? "closed" : (($st === "break") ? "break" : (($st === "full") ? "full" : ""));
      ?>
      <div class="pill">
        <span class="dot <?= h($dot) ?>"></span>
        <span id="shopStatusText"><?= h(statusLabel($st)) ?></span>
      </div>
    </div>

    <div class="grid">
      <section class="card col-12">
        <h3 style="margin:0 0 8px 0;">ภาพรวมคิวและออเดอร์ (วันนี้)</h3>
        <div class="small muted">อัปเดตอัตโนมัติทุก 6 วินาที</div>
        <div class="hr"></div>

        <div class="kpi">
          <div class="box">
            <div class="lab">คิวทั้งหมด</div>
            <div class="num" id="kTotal"><?= (int)$total ?></div>
          </div>

          <div class="box">
            <div class="lab">รอเรียก (waiting)</div>
            <div class="num" id="kWaiting"><?= (int)$waiting ?></div>
          </div>

          <div class="box">
            <div class="lab">กำลังเรียก (calling)</div>
            <div class="num" id="kCalling"><?= (int)$calling ?></div>
          </div>

          <div class="box">
            <div class="lab">ออเดอร์พร้อมรับ (served)</div>
            <div class="num" id="kServed"><?= (int)$served ?></div>
          </div>

          <div class="box">
            <div class="lab">รับออเดอร์แล้ว (received)</div>
            <div class="num" id="kReceived"><?= (int)$received ?></div>
          </div>

          <div class="box">
            <div class="lab">ยกเลิก (cancel)</div>
            <div class="num" id="kCancel"><?= (int)$cancel ?></div>
          </div>

          <div class="box">
            <div class="lab">คิวปัจจุบัน</div>
            <div class="num" id="kCurrent"><?= (int)$currentNo ?></div>
          </div>

          <div class="box">
            <div class="lab">คิวรอรวม</div>
            <div class="num" id="kPending"><?= (int)$pending ?></div>
          </div>

          <div class="box">
            <div class="lab">เวลารอรวม (นาที)</div>
            <div class="num" id="kEtaTotal"><?= (int)$etaTotalMin ?></div>
            <div class="eta-hint" id="etaHint">
              เฉลี่ย <?= number_format($avgPerQueueMin, 1) ?> นาที/คิว (<?= h($avgSource) ?>)
            </div>
          </div>
        </div>

        <div class="hr"></div>
        <h3 style="margin:0 0 8px 0;">คิวรายชั่วโมง (วันนี้)</h3>
        <div class="small muted" style="margin-bottom:10px;">จำนวนคิวที่ถูกสร้างในแต่ละชั่วโมง</div>
        <div style="background:#fff;border:1px solid #eee;border-radius:16px;padding:12px;">
          <canvas id="qLine" height="90"></canvas>
        </div>

        <div class="hr"></div>
        <div class="grid2">
          <div>
            <h3 style="margin:0 0 8px 0;">สัดส่วนสถานะคิวและออเดอร์</h3>
            <div class="small muted" style="margin-bottom:10px;">สรุปภาพรวมสถานะคิวของวันนี้</div>
            <div style="background:#fff;border:1px solid #eee;border-radius:16px;padding:12px;">
              <canvas id="statusChart" height="220"></canvas>
            </div>
          </div>

          <div>
            <h3 style="margin:0 0 8px 0;">จำนวนคิวย้อนหลัง 7 วัน</h3>
            <div class="small muted" style="margin-bottom:10px;">เปรียบเทียบจำนวนคิวของแต่ละวัน</div>
            <div style="background:#fff;border:1px solid #eee;border-radius:16px;padding:12px;">
              <canvas id="dailyChart" height="220"></canvas>
            </div>
          </div>
        </div>
      </section>

      <section class="card col-12">
        <div class="grid2">
          <div>
            <h3 style="margin:0 0 8px 0;">ภาพรวมเมนู</h3>
            <div class="hr"></div>
            <div class="kpi" style="grid-template-columns:repeat(2,minmax(180px,1fr));">
              <div class="box">
                <div class="lab">เมนูทั้งหมด</div>
                <div class="num" id="kMenuTotal"><?= (int)$menuTotal ?></div>
              </div>
              <div class="box">
                <div class="lab">เมนูหมด (is_available=0)</div>
                <div class="num" id="kMenuSoldout"><?= (int)$menuSoldout ?></div>
              </div>
            </div>
            <p class="small muted" style="margin-top:10px;">* หน้า Frontend/shop.php จะแสดงเฉพาะเมนูที่มีขาย</p>
          </div>

          <div>
            <h3 style="margin:0 0 8px 0;">ข้อมูลร้าน</h3>
            <div class="hr"></div>
            <div class="note" id="shopInfoBox">
              <b>ชื่อร้าน:</b> <?= h($shop["name"]) ?><br>
              <b>เวลา:</b> <?= h($shop["open_time"] ?? "-") ?> - <?= h($shop["close_time"] ?? "-") ?><br>
              <b>จำกัดคิว:</b> <?= ($shop["queue_limit"] === null) ? "ไม่จำกัด" : (int)$shop["queue_limit"] . " คิว/วัน" ?><br>
              <b>สถานะ:</b> <?= h(statusLabel((string)$shop["status"])) ?>
            </div>
          </div>
        </div>
      </section>
    </div>
  </main>
</div>

<script>
  const shopId = <?= (int)$shop_id ?>;
  const el = (id) => document.getElementById(id);

  let AVG_PER_QUEUE_MIN = <?= json_encode($avgPerQueueMin) ?>;
  let qChart = null;
  let statusChart = null;
  let dailyChart = null;

  function initChart(labels, values){
    const canvas = document.getElementById("qLine");
    if (!canvas) return;

    qChart = new Chart(canvas, {
      type: "line",
      data: {
        labels: labels,
        datasets: [{
          label: "จำนวนคิว (ต่อชั่วโมง)",
          data: values,
          tension: 0.35,
          fill: false
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: true }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { precision: 0 }
          }
        }
      }
    });
  }

  function updateChart(labels, values){
    if (!qChart) {
      initChart(labels, values);
      return;
    }
    qChart.data.labels = labels;
    qChart.data.datasets[0].data = values;
    qChart.update();
  }

  function renderStatusChart(labels, values){
    const canvas = document.getElementById("statusChart");
    if (!canvas) return;

    const safeValues = Array.isArray(values) ? values : [];
    const total = safeValues.reduce((sum, val) => sum + Number(val || 0), 0);

    if (!statusChart) {
      statusChart = new Chart(canvas, {
        type: "doughnut",
        data: {
          labels: labels,
          datasets: [{
            data: safeValues
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: "bottom",
              labels: {
                generateLabels(chart) {
                  const data = chart.data;
                  const dataset = data.datasets[0] || { data: [] };
                  const sum = dataset.data.reduce((a, b) => a + Number(b || 0), 0);

                  return data.labels.map((label, i) => {
                    const value = Number(dataset.data[i] || 0);
                    const percent = sum > 0 ? ((value / sum) * 100).toFixed(1) : "0.0";
                    const meta = chart.getDatasetMeta(0);
                    const style = meta.controller.getStyle(i);

                    return {
                      text: `${label} ${percent}%`,
                      fillStyle: style.backgroundColor,
                      strokeStyle: style.borderColor,
                      lineWidth: style.borderWidth,
                      hidden: !chart.getDataVisibility(i),
                      index: i
                    };
                  });
                }
              }
            },
            tooltip: {
              callbacks: {
                label: function(context){
                  const value = Number(context.raw || 0);
                  const percent = total > 0 ? ((value / total) * 100).toFixed(1) : "0.0";
                  return `${context.label}: ${value} รายการ (${percent}%)`;
                }
              }
            }
          }
        }
      });
    } else {
      statusChart.data.labels = labels;
      statusChart.data.datasets[0].data = safeValues;
      statusChart.update();
    }
  }

  function renderDailyChart(labels, values){
    const canvas = document.getElementById("dailyChart");
    if (!canvas) return;

    if (!dailyChart) {
      dailyChart = new Chart(canvas, {
        type: "bar",
        data: {
          labels: labels,
          datasets: [{
            label: "จำนวนคิว",
            data: values
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: true }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: { precision: 0 }
            }
          }
        }
      });
    } else {
      dailyChart.data.labels = labels;
      dailyChart.data.datasets[0].data = values;
      dailyChart.update();
    }
  }

  function updateEtaTotal(pending, avgMin, sourceLabel){
    const eta = Math.round(Number(pending) * Number(avgMin));
    if (el("kEtaTotal")) el("kEtaTotal").textContent = eta;

    if (el("etaHint")) {
      const s = sourceLabel ? ` (${sourceLabel})` : "";
      el("etaHint").textContent = `เฉลี่ย ${Number(avgMin).toFixed(1)} นาที/คิว${s}`;
    }
  }

  async function fetchDash(){
    try{
      const res = await fetch(`dashboard_api.php?shop_id=${shopId}`, { cache: "no-store" });

      if (!res.ok) return;

      const data = await res.json();
      if (!data.ok) return;

      const q = data.queues || {};
      const waiting  = Number(q.waiting ?? 0);
      const calling  = Number(q.calling ?? 0);
      const served   = Number(q.served ?? 0);
      const received = Number(q.received ?? 0);
      const cancel   = Number(q.cancel ?? 0);
      const total    = Number(q.total ?? 0);
      const pending  = Number(q.pending ?? (waiting + calling));
      const current  = Number(q.current ?? 0);

      if (el("kTotal"))    el("kTotal").textContent = total;
      if (el("kWaiting"))  el("kWaiting").textContent = waiting;
      if (el("kCalling"))  el("kCalling").textContent = calling;
      if (el("kServed"))   el("kServed").textContent = served;
      if (el("kReceived")) el("kReceived").textContent = received;
      if (el("kCancel"))   el("kCancel").textContent = cancel;
      if (el("kCurrent"))  el("kCurrent").textContent = current;
      if (el("kPending"))  el("kPending").textContent = pending;

      if (data.menu) {
        if (el("kMenuTotal"))   el("kMenuTotal").textContent = Number(data.menu.total ?? 0);
        if (el("kMenuSoldout")) el("kMenuSoldout").textContent = Number(data.menu.soldout ?? 0);
      }

      if (data.shop) {
        if (el("shopStatusText")) el("shopStatusText").textContent = data.shop.status_label ?? "";

        if (data.shop.avg_per_queue_min !== undefined && data.shop.avg_per_queue_min !== null) {
          const v = Number(data.shop.avg_per_queue_min);
          if (!Number.isNaN(v) && v >= 0.5 && v <= 60) {
            AVG_PER_QUEUE_MIN = v;
          }
        }

        const etaSource = data.shop.avg_source ? data.shop.avg_source : "";
        updateEtaTotal(pending, AVG_PER_QUEUE_MIN, etaSource);
      }

      if (data.hourly && Array.isArray(data.hourly.labels) && Array.isArray(data.hourly.values)) {
        updateChart(data.hourly.labels, data.hourly.values);
      }

      if (data.status_chart && Array.isArray(data.status_chart.labels) && Array.isArray(data.status_chart.values)) {
        renderStatusChart(data.status_chart.labels, data.status_chart.values);
      }

      if (data.daily && Array.isArray(data.daily.labels) && Array.isArray(data.daily.values)) {
        renderDailyChart(data.daily.labels, data.daily.values);
      }

    } catch (e) {
      console.error(e);
    }
  }

  fetchDash();
  setInterval(fetchDash, 6000);
</script>
<script>
  window.OWNER_NOTIFY_SHOP_ID = <?= (int)$shop_id ?>;
</script>
<script src="owner-notify.js"></script>
</body>
</html>