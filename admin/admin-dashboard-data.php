<?php
require_once __DIR__ . "/_auth.php";

header('Content-Type: application/json; charset=utf-8');

function h($str){
  return htmlspecialchars((string)$str, ENT_QUOTES, "UTF-8");
}

function statusBadgeClass($status){
  return match($status){
    "open"     => "b-open",
    "closed"   => "b-closed",
    "break"    => "b-break",
    "full"     => "b-full",
    "waiting"  => "b-waiting",
    "calling"  => "b-calling",
    "served"   => "b-served",
    "received" => "b-received",
    "cancel"   => "b-cancel",
    default    => ""
  };
}

function statusLabelTH($status){
  return match($status){
    "open"     => "เปิด",
    "closed"   => "ปิด",
    "break"    => "พัก",
    "full"     => "ออเดอร์เต็ม",
    "waiting"  => "รอดำเนินการ",
    "calling"  => "กำลังดำเนินการ",
    "served"   => "เสร็จสิ้น",
    "received" => "รับออเดอร์แล้ว",
    "cancel"   => "ยกเลิก",
    default    => $status ?: "-"
  };
}

function fetchValue(PDO $pdo, string $sql, array $params = []): int|float|string|null {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchColumn();
}

function fetchAllRows(PDO $pdo, string $sql, array $params = []): array {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function pct(int|float $num, int|float $den, int $decimals = 1): string {
  if ((float)$den <= 0) return "0";
  return number_format(((float)$num / (float)$den) * 100, $decimals);
}

function thDateLabel(string $ymd): string {
  $ts = strtotime($ymd);
  if (!$ts) return $ymd;
  return date('d/m/Y', $ts);
}

function renderDonut(array $segments, string $centerBig, string $centerSmall): string {
  $stroke = 18;
  $radius = 56;
  $circ = 2 * pi() * $radius;

  $total = 0;
  foreach ($segments as $s) {
    $total += (float)($s['value'] ?? 0);
  }

  ob_start();
  ?>
  <div class="donut-wrap">
    <div class="donut-box">
      <svg viewBox="0 0 170 170" aria-hidden="true">
        <circle cx="85" cy="85" r="<?= $radius ?>" fill="none" stroke="#e9eef6" stroke-width="<?= $stroke ?>"></circle>
        <?php
        if ($total > 0) {
          $offset = 0;
          foreach ($segments as $seg) {
            $val = (float)($seg['value'] ?? 0);
            if ($val <= 0) continue;
            $len = ($val / $total) * $circ;
            ?>
            <circle
              cx="85" cy="85" r="<?= $radius ?>"
              fill="none"
              stroke="<?= h($seg['color']) ?>"
              stroke-width="<?= $stroke ?>"
              stroke-linecap="round"
              stroke-dasharray="<?= $len ?> <?= $circ - $len ?>"
              stroke-dashoffset="<?= -$offset ?>"
            ></circle>
            <?php
            $offset += $len;
          }
        }
        ?>
      </svg>
      <div class="donut-center">
        <div class="big"><?= h($centerBig) ?></div>
        <div class="small"><?= h($centerSmall) ?></div>
      </div>
    </div>

    <div class="legend-list">
      <?php foreach ($segments as $seg): ?>
        <?php
          $val = (int)($seg['value'] ?? 0);
          if ($val <= 0) continue;
          $percent = $total > 0 ? number_format(($val / $total) * 100, 1) : '0.0';
        ?>
        <div class="legend-item">
          <div class="legend-left">
            <span class="legend-dot" style="background:<?= h($seg['color']) ?>;"></span>
            <span class="legend-name"><?= h($seg['label']) ?></span>
          </div>
          <div class="legend-value"><?= $val ?> (<?= $percent ?>%)</div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php
  return ob_get_clean();
}

function renderLineChart(array $rows, string $note = ''): string {
  if (!$rows) {
    return '<div class="empty-state">ยังไม่มีข้อมูล</div>' . ($note ? '<div class="soft-note">'.h($note).'</div>' : '');
  }

  $w = 640;
  $h = 240;
  $left = 38;
  $right = 16;
  $top = 20;
  $bottom = 42;

  $plotW = $w - $left - $right;
  $plotH = $h - $top - $bottom;

  $max = 1;
  foreach ($rows as $r) {
    $max = max($max, (int)$r['count']);
  }

  $points = [];
  $countRows = count($rows);
  foreach ($rows as $i => $r) {
    $x = $left + ($countRows > 1 ? ($plotW * $i / ($countRows - 1)) : $plotW / 2);
    $y = $top + $plotH - (($r['count'] / $max) * $plotH);
    $points[] = [$x, $y, $r];
  }

  $polyline = implode(' ', array_map(fn($p) => round($p[0], 2) . ',' . round($p[1], 2), $points));

  ob_start();
  ?>
  <svg class="svg-chart" viewBox="0 0 <?= $w ?> <?= $h ?>" role="img" aria-label="Line chart">
    <?php for ($i = 0; $i <= 4; $i++): ?>
      <?php $y = $top + ($plotH * $i / 4); ?>
      <line x1="<?= $left ?>" y1="<?= $y ?>" x2="<?= $w - $right ?>" y2="<?= $y ?>" stroke="#e9eef6" stroke-width="1"></line>
    <?php endfor; ?>

    <polyline
      fill="none"
      stroke="#2563eb"
      stroke-width="4"
      stroke-linecap="round"
      stroke-linejoin="round"
      points="<?= $polyline ?>"
    ></polyline>

    <?php foreach ($points as $p): ?>
      <circle cx="<?= $p[0] ?>" cy="<?= $p[1] ?>" r="4.5" fill="#2563eb"></circle>
    <?php endforeach; ?>

    <?php foreach ($points as $p): ?>
      <text x="<?= $p[0] ?>" y="<?= $h - 14 ?>" text-anchor="middle" font-size="12" fill="#64748b"><?= h($p[2]['label']) ?></text>
      <text x="<?= $p[0] ?>" y="<?= $p[1] - 10 ?>" text-anchor="middle" font-size="11" fill="#0f172a"><?= (int)$p[2]['count'] ?></text>
    <?php endforeach; ?>
  </svg>
  <?php if ($note !== ''): ?>
    <div class="soft-note"><?= h($note) ?></div>
  <?php endif; ?>
  <?php
  return ob_get_clean();
}

function renderBarChart(array $rows, string $labelKey, string $valueKey, string $note = '', string $color = '#2563eb'): string {
  if (!$rows) {
    return '<div class="empty-state">ยังไม่มีข้อมูล</div>' . ($note ? '<div class="soft-note">'.h($note).'</div>' : '');
  }

  $max = 1;
  foreach ($rows as $r) {
    $max = max($max, (int)$r[$valueKey]);
  }

  ob_start();
  ?>
  <div class="progress-list">
    <?php foreach($rows as $r): ?>
      <?php
        $val = (int)$r[$valueKey];
        $pctVal = $max > 0 ? (($val / $max) * 100) : 0;
      ?>
      <div class="progress-item">
        <div class="progress-head">
          <div class="progress-title"><?= h($r[$labelKey] ?: '-') ?></div>
          <div class="progress-value"><?= $val ?> รายการ</div>
        </div>
        <div class="progress-track">
          <div class="progress-fill" style="width:<?= $pctVal ?>%; background:linear-gradient(90deg, <?= h($color) ?>, #2563eb);"></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($note !== ''): ?>
    <div class="soft-note"><?= h($note) ?></div>
  <?php endif; ?>
  <?php
  return ob_get_clean();
}

function renderPieChart(
  array $rows,
  string $labelKey,
  string $valueKey,
  string $note = '',
  string $emptyText = 'ยังไม่มีข้อมูล',
  int $maxSlices = 5
): string {
  $prepared = [];

  foreach ($rows as $r) {
    $label = trim((string)($r[$labelKey] ?? '')) ?: '-';
    $value = (int)($r[$valueKey] ?? 0);
    if ($value <= 0) continue;

    $prepared[] = [
      'label' => $label,
      'value' => $value
    ];
  }

  if (!$prepared) {
    return '<div class="empty-state">'.h($emptyText).'</div>' . ($note ? '<div class="soft-note">'.h($note).'</div>' : '');
  }

  usort($prepared, function($a, $b){
    return $b['value'] <=> $a['value'];
  });

  $topItems = array_slice($prepared, 0, $maxSlices);
  $otherItems = array_slice($prepared, $maxSlices);

  $otherTotal = 0;
  foreach ($otherItems as $item) {
    $otherTotal += (int)$item['value'];
  }

  if ($otherTotal > 0) {
    $topItems[] = [
      'label' => 'อื่น ๆ',
      'value' => $otherTotal
    ];
  }

  $colors = [
    '#2563eb', '#16a34a', '#f59e0b', '#ef4444', '#7c3aed',
    '#06b6d4', '#84cc16', '#ec4899', '#f97316', '#0ea5e9'
  ];

  $segments = [];
  $grandTotal = 0;

  foreach ($topItems as $i => $item) {
    $grandTotal += (int)$item['value'];
    $segments[] = [
      'label' => $item['label'],
      'value' => (int)$item['value'],
      'color' => $colors[$i % count($colors)]
    ];
  }

  return renderDonut($segments, (string)$grandTotal, "ออเดอร์ทั้งหมด")
    . ($note ? '<div class="soft-note">'.h($note).'</div>' : '');
}

/* =========================
   period filter
========================= */
$period = $_GET['period'] ?? 'today';
$today = date('Y-m-d');
$nowTH = date('d/m/Y H:i:s');

$startDate = $today;
$endDate   = $today;
$daysCount = 1;
$periodLabel = 'วันนี้';

if ($period === 'custom') {
  $startDate = trim((string)($_GET['start_date'] ?? ''));
  $endDate   = trim((string)($_GET['end_date'] ?? ''));

  $isValidStart = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate);
  $isValidEnd   = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate);

  if (!$isValidStart || !$isValidEnd) {
    echo json_encode([
      "ok" => false,
      "message" => "กรุณาระบุวันที่เริ่มต้นและวันที่สิ้นสุดให้ถูกต้อง"
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($startDate > $endDate) {
    echo json_encode([
      "ok" => false,
      "message" => "วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด"
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $diffDays = (int)floor((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
  $daysCount = max(1, $diffDays);
  $periodLabel = 'กำหนดเอง: ' . thDateLabel($startDate) . ' - ' . thDateLabel($endDate);
} else {
  if (!in_array($period, ['today', '7d', '30d'], true)) {
    $period = 'today';
  }

  switch ($period) {
    case '30d':
      $startDate = date('Y-m-d', strtotime('-29 days'));
      $endDate   = $today;
      $daysCount = 30;
      $periodLabel = '30 วันล่าสุด';
      break;

    case '7d':
      $startDate = date('Y-m-d', strtotime('-6 days'));
      $endDate   = $today;
      $daysCount = 7;
      $periodLabel = '7 วันล่าสุด';
      break;

    default:
      $startDate = $today;
      $endDate   = $today;
      $daysCount = 1;
      $periodLabel = 'วันนี้';
      break;
  }
}

/* =========================
   current shop status (always current)
========================= */
$shops_total  = (int)fetchValue($pdo, "SELECT COUNT(*) FROM shops");
$shops_open   = (int)fetchValue($pdo, "SELECT COUNT(*) FROM shops WHERE status='open'");
$shops_closed = (int)fetchValue($pdo, "SELECT COUNT(*) FROM shops WHERE status='closed'");
$shops_break  = (int)fetchValue($pdo, "SELECT COUNT(*) FROM shops WHERE status='break'");
$shops_full   = (int)fetchValue($pdo, "SELECT COUNT(*) FROM shops WHERE status='full'");
$openRate     = pct($shops_open, $shops_total, 1);

/* =========================
   order KPIs by selected period
========================= */
$queues_total = (int)fetchValue($pdo, "
  SELECT COUNT(*)
  FROM queues
  WHERE queue_date BETWEEN ? AND ?
    AND deleted_at IS NULL
", [$startDate, $endDate]);

$queues_waiting = (int)fetchValue($pdo, "
  SELECT COUNT(*)
  FROM queues
  WHERE queue_date BETWEEN ? AND ?
    AND status='waiting'
    AND deleted_at IS NULL
", [$startDate, $endDate]);

$queues_calling = (int)fetchValue($pdo, "
  SELECT COUNT(*)
  FROM queues
  WHERE queue_date BETWEEN ? AND ?
    AND status='calling'
    AND deleted_at IS NULL
", [$startDate, $endDate]);

$queues_received = (int)fetchValue($pdo, "
  SELECT COUNT(*)
  FROM queues
  WHERE queue_date BETWEEN ? AND ?
    AND status='received'
    AND deleted_at IS NULL
", [$startDate, $endDate]);

$queues_served = (int)fetchValue($pdo, "
  SELECT COUNT(*)
  FROM queues
  WHERE queue_date BETWEEN ? AND ?
    AND status='served'
    AND deleted_at IS NULL
", [$startDate, $endDate]);

$queues_cancel = (int)fetchValue($pdo, "
  SELECT COUNT(*)
  FROM queues
  WHERE queue_date BETWEEN ? AND ?
    AND status='cancel'
    AND deleted_at IS NULL
", [$startDate, $endDate]);

$serviceRate = pct($queues_served, $queues_total, 1);
$cancelRate  = pct($queues_cancel, $queues_total, 1);

/* =========================
   recent orders (always recent)
========================= */
$recent = fetchAllRows($pdo, "
  SELECT 
    q.queue_id, q.queue_no, q.status, q.created_at, q.queue_date,
    q.customer_name, q.customer_phone,
    s.shop_id,
    s.name AS shop_name
  FROM queues q
  INNER JOIN shops s ON s.shop_id = q.shop_id
  WHERE q.deleted_at IS NULL
  ORDER BY q.created_at DESC
  LIMIT 10
");

/* =========================
   shop monitor (today for current shop table)
========================= */
$shops = fetchAllRows($pdo, "
  SELECT
    s.shop_id,
    s.name,
    s.status,
    s.open_time,
    s.close_time,
    s.queue_limit,
    s.eta_per_queue_min,
    d.dome_name,
    l.dome_id,
    l.lock_no,
    c.category_name,
    t.type_name,

    SUM(
      CASE
        WHEN q.queue_date = ?
         AND q.status = 'waiting'
         AND q.deleted_at IS NULL
        THEN 1 ELSE 0
      END
    ) AS waiting_count,

    SUM(
      CASE
        WHEN q.queue_date = ?
         AND q.deleted_at IS NULL
        THEN 1 ELSE 0
      END
    ) AS today_queue_count

  FROM shops s
  LEFT JOIN locks l ON l.lock_id = s.lock_id
  LEFT JOIN domes d ON d.dome_id = l.dome_id
  LEFT JOIN shop_types t ON t.type_id = s.type_id
  LEFT JOIN shop_categories c ON c.category_id = t.category_id
  LEFT JOIN queues q ON q.shop_id = s.shop_id
  GROUP BY
    s.shop_id, s.name, s.status, s.open_time, s.close_time,
    s.queue_limit, s.eta_per_queue_min,
    d.dome_name, l.dome_id, l.lock_no, c.category_name, t.type_name
  ORDER BY l.dome_id ASC, l.lock_no ASC, s.shop_id ASC
", [$today, $today]);

$shopsPreview = array_slice($shops, 0, 10);

/* =========================
   time series by selected period
========================= */
$tmpSeries = fetchAllRows($pdo, "
  SELECT queue_date, COUNT(*) AS total_queues
  FROM queues
  WHERE queue_date BETWEEN ? AND ?
    AND deleted_at IS NULL
  GROUP BY queue_date
  ORDER BY queue_date ASC
", [$startDate, $endDate]);

$seriesMap = [];
foreach($tmpSeries as $r){
  $seriesMap[$r["queue_date"]] = (int)$r["total_queues"];
}

$seriesRows = [];
$totalSeries = 0;

$currentTs = strtotime($startDate);
$endTs = strtotime($endDate);

while ($currentTs <= $endTs) {
  $d = date('Y-m-d', $currentTs);
  $count = $seriesMap[$d] ?? 0;

  $seriesRows[] = [
    "date"  => $d,
    "label" => date('d/m', $currentTs),
    "count" => $count
  ];

  $totalSeries += $count;
  $currentTs = strtotime('+1 day', $currentTs);
}

$avgSeries = $daysCount > 0 ? round($totalSeries / $daysCount, 1) : 0;

/* =========================
   Top shops by selected period
========================= */
$topShops = fetchAllRows($pdo, "
  SELECT
    s.shop_id,
    s.name AS shop_name,
    s.status,
    COUNT(q.queue_id) AS total_queues
  FROM shops s
  LEFT JOIN queues q
    ON q.shop_id = s.shop_id
   AND q.queue_date BETWEEN ? AND ?
   AND q.deleted_at IS NULL
  GROUP BY s.shop_id, s.name, s.status
  ORDER BY total_queues DESC, s.name ASC
  LIMIT 5
", [$startDate, $endDate]);

$topShopName = $topShops[0]["shop_name"] ?? "-";
$topShopQueues = isset($topShops[0]["total_queues"]) ? (int)$topShops[0]["total_queues"] : 0;

/* =========================
   Category stats by selected period
========================= */
$categoryStats = fetchAllRows($pdo, "
  SELECT
    COALESCE(c.category_name, 'ไม่ระบุหมวดหมู่') AS category_name,
    COUNT(q.queue_id) AS total_queues
  FROM shop_categories c
  LEFT JOIN shop_types t ON t.category_id = c.category_id
  LEFT JOIN shops s ON s.type_id = t.type_id
  LEFT JOIN queues q
    ON q.shop_id = s.shop_id
   AND q.queue_date BETWEEN ? AND ?
   AND q.deleted_at IS NULL
  GROUP BY c.category_id, c.category_name
  ORDER BY total_queues DESC, c.category_name ASC
", [$startDate, $endDate]);

$topCategoryName = $categoryStats[0]["category_name"] ?? "-";
$topCategoryQueues = isset($categoryStats[0]["total_queues"]) ? (int)$categoryStats[0]["total_queues"] : 0;

/* =========================
   Type stats by selected period
========================= */
$typeStats = fetchAllRows($pdo, "
  SELECT
    COALESCE(t.type_name, 'ไม่ระบุประเภท') AS type_name,
    COUNT(q.queue_id) AS total_queues
  FROM shop_types t
  LEFT JOIN shops s ON s.type_id = t.type_id
  LEFT JOIN queues q
    ON q.shop_id = s.shop_id
   AND q.queue_date BETWEEN ? AND ?
   AND q.deleted_at IS NULL
  GROUP BY t.type_id, t.type_name
  ORDER BY total_queues DESC, t.type_name ASC
", [$startDate, $endDate]);

$topTypeName = $typeStats[0]["type_name"] ?? "-";
$topTypeQueues = isset($typeStats[0]["total_queues"]) ? (int)$typeStats[0]["total_queues"] : 0;

/* =========================
   Dome stats by selected period
========================= */
$domeStats = fetchAllRows($pdo, "
  SELECT
    d.dome_id,
    d.dome_name,
    COUNT(DISTINCT s.shop_id) AS total_shops,
    COUNT(q.queue_id) AS total_queues
  FROM domes d
  LEFT JOIN locks l ON l.dome_id = d.dome_id
  LEFT JOIN shops s ON s.lock_id = l.lock_id
  LEFT JOIN queues q
    ON q.shop_id = s.shop_id
   AND q.queue_date BETWEEN ? AND ?
   AND q.deleted_at IS NULL
  GROUP BY d.dome_id, d.dome_name
  ORDER BY d.dome_id ASC
", [$startDate, $endDate]);

$peakDomeName = "-";
$peakDomeQueues = 0;
foreach($domeStats as $d){
  $q = (int)$d["total_queues"];
  if($q > $peakDomeQueues){
    $peakDomeQueues = $q;
    $peakDomeName = $d["dome_name"];
  }
}

/* =========================
   Unassigned locks (current)
========================= */
$unassignedLocks = fetchAllRows($pdo, "
  SELECT
    l.lock_id,
    l.lock_no,
    l.is_active,
    l.note,
    d.dome_id,
    d.dome_name
  FROM locks l
  INNER JOIN domes d ON d.dome_id = l.dome_id
  LEFT JOIN shops s ON s.lock_id = l.lock_id
  WHERE s.shop_id IS NULL
  ORDER BY d.dome_id ASC, l.lock_no ASC
  LIMIT 10
");

$unassignedActiveCount = 0;
foreach($unassignedLocks as $r){
  if ((int)$r["is_active"] === 1) {
    $unassignedActiveCount++;
  }
}

/* =========================
   Alerts (current/today)
========================= */
$shopsNoMenu = fetchAllRows($pdo, "
  SELECT s.shop_id, s.name
  FROM shops s
  LEFT JOIN menu_items m ON m.shop_id = s.shop_id
  GROUP BY s.shop_id, s.name
  HAVING COUNT(m.item_id) = 0
  ORDER BY s.name ASC
  LIMIT 5
");

$shopsOpenNoQueue = fetchAllRows($pdo, "
  SELECT
    s.shop_id, s.name
  FROM shops s
  LEFT JOIN queues q
    ON q.shop_id = s.shop_id
   AND q.queue_date = ?
   AND q.deleted_at IS NULL
  WHERE s.status = 'open'
  GROUP BY s.shop_id, s.name
  HAVING COUNT(q.queue_id) = 0
  ORDER BY s.name ASC
  LIMIT 5
", [$today]);

$shopsWaitingOverLimit = fetchAllRows($pdo, "
  SELECT
    s.shop_id,
    s.name,
    s.queue_limit,
    COUNT(q.queue_id) AS waiting_count
  FROM shops s
  LEFT JOIN queues q
    ON q.shop_id = s.shop_id
   AND q.queue_date = ?
   AND q.status = 'waiting'
   AND q.deleted_at IS NULL
  WHERE s.queue_limit IS NOT NULL
    AND s.queue_limit > 0
  GROUP BY s.shop_id, s.name, s.queue_limit
  HAVING COUNT(q.queue_id) >= s.queue_limit
  ORDER BY waiting_count DESC, s.name ASC
  LIMIT 5
", [$today]);

$totalAlertItems = count($shopsNoMenu) + count($shopsOpenNoQueue) + count($shopsWaitingOverLimit);

/* =========================
   insight text
========================= */
$kpiInsight = "{$periodLabel} มีออเดอร์ {$queues_total} รายการ • เสร็จสิ้น {$queues_served} ({$serviceRate}%) • ยกเลิก {$queues_cancel} ({$cancelRate}%)";
$queueSeriesInsight = "{$periodLabel} เฉลี่ย {$avgSeries} ออเดอร์/วัน";
$topInsight = $topShopQueues > 0
  ? "ร้านที่มีออเดอร์มากที่สุดในช่วง {$periodLabel} คือ {$topShopName} จำนวน {$topShopQueues} รายการ"
  : "ช่วง {$periodLabel} ยังไม่มีร้านที่มีออเดอร์";
$categoryInsight = $topCategoryQueues > 0
  ? "หมวดหมู่ที่มีออเดอร์มากที่สุดคือ {$topCategoryName}"
  : "ยังไม่มีข้อมูลออเดอร์แยกตามหมวดหมู่";
$typeInsight = $topTypeQueues > 0
  ? "ประเภทที่มีออเดอร์มากที่สุดคือ {$topTypeName}"
  : "ยังไม่มีข้อมูลออเดอร์แยกตามประเภท";
$domeInsight = $peakDomeQueues > 0
  ? "โดมที่มีออเดอร์มากที่สุดคือ {$peakDomeName} จำนวน {$peakDomeQueues} รายการ"
  : "ยังไม่พบออเดอร์ในแต่ละโดม";
$unassignedInsight = "ล็อกว่างทั้งหมด " . count($unassignedLocks) . " ล็อก • ใช้งานได้ {$unassignedActiveCount} ล็อก";
$alertsInsight = $totalAlertItems > 0
  ? "พบรายการที่ควรตรวจสอบ {$totalAlertItems} รายการ"
  : "ไม่พบรายการผิดปกติในขณะนี้";
$recentInsight = "ติดตามความเคลื่อนไหวล่าสุดของออเดอร์ในระบบ";
$shopsInsight = $shops_total > 0
  ? "แสดง 10 ร้านแรกจากทั้งหมด {$shops_total} ร้าน"
  : "ยังไม่มีข้อมูลร้านค้า";

/* =========================
   donut data
========================= */
$shopStatusSegments = [
  ["label" => "เปิด", "value" => $shops_open, "color" => "#22c55e"],
  ["label" => "ปิด", "value" => $shops_closed, "color" => "#ef4444"],
  ["label" => "พัก", "value" => $shops_break, "color" => "#f59e0b"],
  ["label" => "ออเดอร์เต็ม", "value" => $shops_full, "color" => "#8b5cf6"],
];

$queueStatusSegments = [
  ["label" => "รอดำเนินการ", "value" => $queues_waiting, "color" => "#3b82f6"],
  ["label" => "กำลังดำเนินการ", "value" => $queues_calling, "color" => "#8b5cf6"],
  ["label" => "รับออเดอร์แล้ว", "value" => $queues_received, "color" => "#06b6d4"],
  ["label" => "เสร็จสิ้น", "value" => $queues_served, "color" => "#22c55e"],
  ["label" => "ยกเลิก", "value" => $queues_cancel, "color" => "#ef4444"],
];

/* =========================
   HTML blocks
========================= */
ob_start();
?>
<div class="kpi">
  <div class="box">
    <div class="kpi-top">
      <div class="kpi-text">
        <div class="label">ร้านทั้งหมด</div>
        <div class="value"><?= $shops_total ?></div>
      </div>
      <div class="kpi-icon">🏪</div>
    </div>
    <div class="kpi-note">ร้านในระบบทั้งหมด</div>
  </div>

  <div class="box">
    <div class="kpi-top">
      <div class="kpi-text">
        <div class="label">ร้านที่เปิด</div>
        <div class="value"><?= $shops_open ?></div>
      </div>
      <div class="kpi-icon ok">🟢</div>
    </div>
    <div class="kpi-note">คิดเป็น <?= $openRate ?>%</div>
  </div>

  <div class="box">
    <div class="kpi-top">
      <div class="kpi-text">
        <div class="label">ออเดอร์<?= h($periodLabel) ?></div>
        <div class="value"><?= $queues_total ?></div>
      </div>
      <div class="kpi-icon">🧾</div>
    </div>
    <div class="kpi-note">จำนวนออเดอร์ทั้งหมดในช่วงที่เลือก</div>
  </div>

  <div class="box">
    <div class="kpi-top">
      <div class="kpi-text">
        <div class="label">ออเดอร์รอดำเนินการ</div>
        <div class="value"><?= $queues_waiting ?></div>
      </div>
      <div class="kpi-icon warn">⏳</div>
    </div>
    <div class="kpi-note">ออเดอร์ที่ยังรอดำเนินการ</div>
  </div>

  <div class="box">
    <div class="kpi-top">
      <div class="kpi-text">
        <div class="label">ออเดอร์เสร็จสิ้น</div>
        <div class="value"><?= $queues_served ?></div>
      </div>
      <div class="kpi-icon ok">✅</div>
    </div>
    <div class="kpi-note">อัตราสำเร็จ <?= $serviceRate ?>%</div>
  </div>

  <div class="box">
    <div class="kpi-top">
      <div class="kpi-text">
        <div class="label">แจ้งเตือน</div>
        <div class="value"><?= $totalAlertItems ?></div>
      </div>
      <div class="kpi-icon bad">⚠️</div>
    </div>
    <div class="kpi-note">รายการที่ควรตรวจสอบ</div>
  </div>
</div>

<div class="overview-panels">
  <div class="overview-panel">
    <h4>สถานะร้าน</h4>
    <?= renderDonut($shopStatusSegments, (string)$shops_open, "ร้านที่เปิด") ?>
  </div>

  <div class="overview-panel">
    <h4>สถานะออเดอร์</h4>
    <?= renderDonut($queueStatusSegments, (string)$queues_total, "ออเดอร์ทั้งหมด") ?>
  </div>

  <div class="overview-panel">
    <h4>ภาพรวมระบบ</h4>
    <div class="quick-stats">
      <div class="quick-stat">
        <div class="s-label">อัตราดำเนินการสำเร็จ</div>
        <div class="s-value"><?= $serviceRate ?>%</div>
        <div class="s-sub"><?= h($periodLabel) ?></div>
      </div>
      <div class="quick-stat">
        <div class="s-label">อัตราการยกเลิก</div>
        <div class="s-value"><?= $cancelRate ?>%</div>
        <div class="s-sub"><?= h($periodLabel) ?></div>
      </div>
      <div class="quick-stat">
        <div class="s-label">ล็อกว่าง</div>
        <div class="s-value"><?= count($unassignedLocks) ?></div>
        <div class="s-sub">ใช้งานได้ <?= $unassignedActiveCount ?> ล็อก</div>
      </div>
    </div>
  </div>
</div>

<div class="soft-note"><?= h($kpiInsight) ?></div>
<?php
$kpiHtml = ob_get_clean();

$queue7Html = renderLineChart($seriesRows, $queueSeriesInsight);

ob_start();
?>
<div class="progress-list">
  <?php if(!$topShops): ?>
    <div class="empty-state">ยังไม่มีข้อมูล</div>
  <?php else: ?>
    <?php
      $topShopMax = 1;
      foreach($topShops as $r){
        $topShopMax = max($topShopMax, (int)$r["total_queues"]);
      }
    ?>
    <?php foreach($topShops as $i => $r): ?>
      <?php
        $count = (int)$r["total_queues"];
        $percent = $topShopMax > 0 ? (($count / $topShopMax) * 100) : 0;
        $share = $queues_total > 0 ? number_format(($count / $queues_total) * 100, 1) : '0.0';
      ?>
      <div class="progress-item">
        <div class="progress-head">
          <div class="progress-title">
            <?= ($i + 1) ?>.
            <a class="content-link" href="admin-shops.php?shop_id=<?= (int)$r["shop_id"] ?>">
              <?= h($r["shop_name"]) ?>
            </a>
          </div>
          <div class="progress-value"><?= $count ?> รายการ</div>
        </div>
        <div class="progress-track">
          <div class="progress-fill" style="width:<?= $percent ?>%"></div>
        </div>
        <div class="soft-note" style="margin-top:6px;">คิดเป็น <?= $share ?>% ของออเดอร์ทั้งหมดในช่วงนี้</div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<div class="soft-note"><?= h($topInsight) ?></div>
<?php
$topHtml = ob_get_clean();

$categoryHtml = renderPieChart(
  $categoryStats,
  'category_name',
  'total_queues',
  $categoryInsight,
  'ยังไม่มีข้อมูลออเดอร์ตามหมวดหมู่'
);

$typeHtml = renderPieChart(
  $typeStats,
  'type_name',
  'total_queues',
  $typeInsight,
  'ยังไม่มีข้อมูลออเดอร์ตามประเภท'
);

ob_start();
?>
<div class="progress-list">
  <?php if(!$domeStats): ?>
    <div class="empty-state">ยังไม่มีข้อมูล</div>
  <?php else: ?>
    <?php
      $domeMax = 1;
      foreach($domeStats as $r){
        $domeMax = max($domeMax, (int)$r["total_queues"]);
      }
    ?>
    <?php foreach($domeStats as $r): ?>
      <?php
        $count = (int)$r["total_queues"];
        $percent = $domeMax > 0 ? (($count / $domeMax) * 100) : 0;
        $share = $queues_total > 0 ? number_format(($count / $queues_total) * 100, 1) : '0.0';
      ?>
      <div class="progress-item">
        <div class="progress-head">
          <div class="progress-title"><?= h($r["dome_name"] ?: '-') ?></div>
          <div class="progress-value"><?= $count ?> รายการ</div>
        </div>
        <div class="progress-track">
          <div class="progress-fill" style="width:<?= $percent ?>%; background:linear-gradient(90deg,#16a34a,#2563eb);"></div>
        </div>
        <div class="soft-note" style="margin-top:6px;">คิดเป็น <?= $share ?>% ของออเดอร์ทั้งหมดในช่วงนี้</div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<div class="soft-note"><?= h($domeInsight) ?></div>
<?php
$domeHtml = ob_get_clean();

ob_start();
?>
<table>
  <thead>
    <tr>
      <th>โดม</th>
      <th>ล็อก</th>
      <th>สถานะ</th>
      <th>หมายเหตุ</th>
    </tr>
  </thead>
  <tbody>
    <?php if(!$unassignedLocks): ?>
      <tr><td colspan="4" class="muted">ไม่มีล็อกว่าง</td></tr>
    <?php else: ?>
      <?php foreach($unassignedLocks as $r): ?>
        <tr>
          <td><?= h($r["dome_name"]) ?></td>
          <td><?= h($r["lock_no"]) ?></td>
          <td><?= ((int)$r["is_active"] === 1) ? "ใช้งานได้" : "ปิดใช้งาน" ?></td>
          <td><?= h($r["note"] ?: "-") ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table>
<div class="soft-note"><?= h($unassignedInsight) ?></div>
<?php
$unassignedHtml = ob_get_clean();

ob_start();
?>
<div class="alert-grid">
  <div class="alert-box">
    <h4>ร้านที่ไม่มีเมนู</h4>
    <?php if(!$shopsNoMenu): ?>
      <div class="muted">ไม่พบปัญหา</div>
    <?php else: ?>
      <ul>
        <?php foreach($shopsNoMenu as $r): ?>
          <li>
            <a class="content-link" href="admin-shops.php?shop_id=<?= (int)$r["shop_id"] ?>">
              <?= h($r["name"]) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="alert-box">
    <h4>ร้านเปิดแต่ไม่มีออเดอร์วันนี้</h4>
    <?php if(!$shopsOpenNoQueue): ?>
      <div class="muted">ไม่พบรายการ</div>
    <?php else: ?>
      <ul>
        <?php foreach($shopsOpenNoQueue as $r): ?>
          <li>
            <a class="content-link" href="admin-shops.php?shop_id=<?= (int)$r["shop_id"] ?>">
              <?= h($r["name"]) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="alert-box">
    <h4>ร้านที่ออเดอร์รอแตะลิมิต</h4>
    <?php if(!$shopsWaitingOverLimit): ?>
      <div class="muted">ไม่พบรายการ</div>
    <?php else: ?>
      <ul>
        <?php foreach($shopsWaitingOverLimit as $r): ?>
          <li>
            <a class="content-link" href="admin-shops.php?shop_id=<?= (int)$r["shop_id"] ?>">
              <?= h($r["name"]) ?>
            </a>
            (<?= (int)$r["waiting_count"] ?>/<?= (int)$r["queue_limit"] ?>)
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<div class="soft-note"><?= h($alertsInsight) ?></div>
<?php
$alertsHtml = ob_get_clean();

ob_start();
?>
<table>
  <thead>
    <tr>
      <th>เวลา</th>
      <th>วันที่ออเดอร์</th>
      <th>ร้าน</th>
      <th>เลขออเดอร์</th>
      <th>ลูกค้า</th>
      <th>เบอร์โทร</th>
      <th>สถานะ</th>
    </tr>
  </thead>
  <tbody>
    <?php if(!$recent): ?>
      <tr><td colspan="7" class="muted">ยังไม่มีข้อมูลออเดอร์</td></tr>
    <?php else: ?>
      <?php foreach($recent as $r): ?>
        <tr>
          <td><?= h($r["created_at"]) ?></td>
          <td><?= h($r["queue_date"]) ?></td>
          <td>
            <a class="content-link" href="admin-shops.php?shop_id=<?= (int)$r["shop_id"] ?>">
              <?= h($r["shop_name"]) ?>
            </a>
          </td>
          <td>#<?= str_pad((string)$r["queue_no"], 3, "0", STR_PAD_LEFT) ?></td>
          <td><?= h($r["customer_name"] ?: "-") ?></td>
          <td><?= h($r["customer_phone"] ?: "-") ?></td>
          <td>
            <span class="badge <?= h(statusBadgeClass($r["status"])) ?>">
              <?= h(statusLabelTH($r["status"])) ?>
            </span>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table>
<div class="table-actions">
  <a class="link-btn" href="admin-history.php">ดูทั้งหมด</a>
</div>
<div class="soft-note"><?= h($recentInsight) ?></div>
<?php
$recentHtml = ob_get_clean();

ob_start();
?>
<table>
  <thead>
    <tr>
      <th>ร้าน</th>
      <th>โดม</th>
      <th>ล็อก</th>
      <th>หมวดหมู่</th>
      <th>ประเภท</th>
      <th>เวลาเปิด-ปิด</th>
      <th>ออเดอร์รอ</th>
      <th>ออเดอร์วันนี้</th>
      <th>จำกัดออเดอร์</th>
      <th>ETA/ออเดอร์</th>
      <th>สถานะ</th>
    </tr>
  </thead>
  <tbody>
    <?php if(!$shopsPreview): ?>
      <tr><td colspan="11" class="muted">ยังไม่มีร้านค้า</td></tr>
    <?php else: ?>
      <?php foreach($shopsPreview as $s): ?>
        <tr>
          <td>
            <a class="content-link" href="admin-shops.php?shop_id=<?= (int)$s["shop_id"] ?>">
              <?= h($s["name"]) ?>
            </a>
          </td>
          <td><?= h($s["dome_name"] ?: ("โดม " . $s["dome_id"])) ?></td>
          <td><?= h($s["lock_no"] ?? "-") ?></td>
          <td><?= h($s["category_name"] ?: "-") ?></td>
          <td><?= h($s["type_name"] ?: "-") ?></td>
          <td><?= h($s["open_time"] ?: "-") ?>–<?= h($s["close_time"] ?: "-") ?></td>
          <td><?= (int)$s["waiting_count"] ?></td>
          <td><?= (int)$s["today_queue_count"] ?></td>
          <td><?= h($s["queue_limit"] ?? "-") ?></td>
          <td><?= h($s["eta_per_queue_min"] ?? "-") ?></td>
          <td>
            <span class="badge <?= h(statusBadgeClass($s["status"])) ?>">
              <?= h(statusLabelTH($s["status"])) ?>
            </span>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table>
<div class="table-actions">
  <a class="link-btn" href="admin-shops.php">ดูทั้งหมด</a>
</div>
<div class="soft-note"><?= h($shopsInsight) ?></div>
<?php
$shopsHtml = ob_get_clean();

echo json_encode([
  "ok" => true,
  "updated_at" => $nowTH,
  "period" => $period,
  "period_label" => $periodLabel,
  "start_date" => $startDate,
  "end_date" => $endDate,
  "kpi_html" => $kpiHtml,
  "queue7_html" => $queue7Html,
  "top_html" => $topHtml,
  "category_html" => $categoryHtml,
  "type_html" => $typeHtml,
  "dome_html" => $domeHtml,
  "unassigned_html" => $unassignedHtml,
  "alerts_html" => $alertsHtml,
  "recent_html" => $recentHtml,
  "shops_html" => $shopsHtml
], JSON_UNESCAPED_UNICODE);