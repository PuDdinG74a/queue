<?php
require_once __DIR__ . "/../config.php";

$queue_id = isset($_GET["queue_id"]) ? (int)$_GET["queue_id"] : 0;
if ($queue_id <= 0) {
  http_response_code(400);
  echo "เปิดไม่ถูกวิธี: confirm-queue.php?queue_id=123";
  exit;
}

$today = date('Y-m-d');

function h($s){
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

function statusTH($st){
  $st = trim((string)$st);
  if($st === "") $st = "waiting";

  return match($st){
    "waiting"  => "รอเรียก",
    "calling"  => "กำลังเรียก",
    "served"   => "พร้อมรับออเดอร์",
    "received" => "รับออเดอร์แล้ว",
    "cancel"   => "ยกเลิก",
    default    => "รอเรียก"
  };
}

function badgeClass($st){
  $st = trim((string)$st);
  if($st === "") $st = "waiting";

  return match($st){
    "waiting"  => "b-wait",
    "calling"  => "b-doing",
    "served"   => "b-done",
    "received" => "b-done",
    "cancel"   => "b-cancel",
    default    => "b-wait"
  };
}

function formatMinutesTH(int $minutes): string {
  if ($minutes <= 0) return "ใกล้ถึงคิวแล้ว";
  if ($minutes < 60) return $minutes . " นาที";

  $h = intdiv($minutes, 60);
  $m = $minutes % 60;

  if ($m === 0) return $h . " ชม.";

  return $h . " ชม. " . $m . " นาที";
}

/* =========================
   ดึงข้อมูลคิว
========================= */

$stmt = $pdo->prepare("
  SELECT
    q.queue_id, q.shop_id, q.queue_date, q.queue_no, q.status,
    q.customer_note, q.customer_phone,
    q.created_at, q.called_at, q.served_at,
    s.name AS shop_name,
    s.status AS shop_status,
    s.eta_per_queue_min,
    l.lock_no,
    d.dome_id,
    d.dome_name
  FROM queues q
  JOIN shops s ON s.shop_id = q.shop_id
  LEFT JOIN locks l ON l.lock_id = s.lock_id
  LEFT JOIN domes d ON d.dome_id = l.dome_id
  WHERE q.queue_id = ?
  LIMIT 1
");

$stmt->execute([$queue_id]);
$q = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$q){
  http_response_code(404);
  echo "ไม่พบคิวนี้";
  exit;
}

/* =========================
   กันเปิดคิวเก่า
========================= */

if (($q["queue_date"] ?? "") !== $today) {
  http_response_code(403);
  echo "คิวนี้ไม่ใช่ของวันนี้";
  exit;
}

$shopId = (int)$q["shop_id"];
$status = trim((string)($q["status"] ?? "waiting"));
if($status === "") $status = "waiting";

/* =========================
   สถานะ
========================= */

$isServed   = ($status === 'served');
$isReceived = ($status === 'received');
$isCancel   = ($status === 'cancel');

/* =========================
   คิวที่ร้านกำลังเรียก
========================= */

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

$stmt->execute([$shopId, $today, $shopId, $today]);
$current = (int)($stmt->fetchColumn() ?: 0);

/* =========================
   จำนวนคิวที่ยังไม่เสร็จ
========================= */

$stmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM queues
  WHERE shop_id=? AND queue_date=? AND status IN ('waiting','calling')
");

$stmt->execute([$shopId, $today]);
$waiting = (int)($stmt->fetchColumn() ?: 0);

/* =========================
   รายการอาหาร
========================= */

$orderText = trim((string)($q["customer_note"] ?? ""));
$orderText = ($orderText === "") ? "ไม่ได้ระบุรายการอาหาร" : $orderText;

/* =========================
   เบอร์โทร
========================= */

$phone = trim((string)($q["customer_phone"] ?? ""));
$phone = ($phone === "") ? "ไม่ได้ระบุ" : $phone;

/* =========================
   ตำแหน่งร้าน
========================= */

$locationLabel = "-";
$locationParts = [];

if (!empty($q["dome_name"])) {
  $locationParts[] = $q["dome_name"];
}

if (!empty($q["lock_no"])) {
  $locationParts[] = "ล็อก " . $q["lock_no"];
}

if (!empty($locationParts)) {
  $locationLabel = implode(" • ", $locationParts);
}

/* =========================
   ETA
========================= */

$queueNo = (int)$q["queue_no"];
$beforeMe = 0;
$minutes = 0;

$avgPerQueueMin = 3.0;
$avgSource = "ค่าเริ่มต้น";

if (!in_array($status, ['served','received','cancel'], true)) {

  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM queues
    WHERE shop_id=? AND queue_date=?
      AND status IN ('waiting','calling')
      AND queue_no < ?
  ");

  $stmt->execute([$shopId, $today, $queueNo]);
  $beforeMe = (int)($stmt->fetchColumn() ?: 0);
}

if (in_array($status, ['served','received','cancel'], true)) {
  $minutes = 0;
} else {
  $minutes = (int)max(0, round($beforeMe * $avgPerQueueMin));
}
?>
<style>
  :root {
  --primary: #FACC15;
  --primary-dark: #EAB308;
  --primary-soft: #FEF9C3;

  --bg: #F8FAFC;
  --card: #FFFFFF;

  --text: #0F172A;
  --muted: #64748B;

  --border: #E2E8F0;

  --success: #22C55E;
  --warning: #F59E0B;
  --danger: #EF4444;
}

/* ================= BASE ================= */
body{
  margin:0;
  font-family: "Prompt", sans-serif;
  background:var(--bg);
  color:var(--text);
}

.container{
  max-width:480px;
  margin:auto;
  padding:16px;
}

/* ================= CARD ================= */
.card{
  background:var(--card);
  border-radius:20px;
  padding:16px;
  margin-bottom:16px;
  box-shadow:0 6px 16px rgba(0,0,0,0.05);
}

/* ================= HERO ================= */
.hero{
  text-align:center;
  background:linear-gradient(135deg,var(--primary),var(--primary-soft));
  color:#000;
}

.hero h2{
  margin:0;
  font-size:20px;
}

.hero p{
  margin-top:6px;
  font-size:14px;
  color:#444;
}

/* ================= QUEUE ================= */
.queue-hero{
  text-align:center;
}

.queue-no{
  font-size:52px;
  font-weight:800;
  margin:8px 0;
  color:var(--text);
}

.muted{
  color:var(--muted);
  font-size:14px;
}

.small{
  font-size:13px;
  color:var(--muted);
}

/* ================= BADGE ================= */
.badge{
  display:inline-block;
  padding:6px 12px;
  border-radius:999px;
  font-size:13px;
  font-weight:600;
}

.b-wait{
  background:#FEF3C7;
  color:#92400E;
}

.b-doing{
  background:#DBEAFE;
  color:#1D4ED8;
}

.b-done{
  background:#DCFCE7;
  color:#166534;
}

.b-cancel{
  background:#FEE2E2;
  color:#991B1B;
}

/* ================= INFO ================= */
.info-block{
  text-align:center;
}

.info-title{
  font-size:14px;
  color:var(--muted);
  margin-bottom:6px;
}

/* ================= BUTTON ================= */
.btn{
  width:100%;
  padding:14px;
  border:none;
  border-radius:14px;
  font-size:15px;
  font-weight:600;
  margin-bottom:10px;
  cursor:pointer;
  transition:0.2s;
}

.btn-primary{
  background:var(--primary);
  color:#000;
}

.btn-primary:hover{
  background:var(--primary-dark);
}

.btn-secondary{
  background:#F1F5F9;
  color:var(--text);
}

.btn-secondary:hover{
  background:#E2E8F0;
}
</style>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>รับคิวสำเร็จ</title>
<link rel="stylesheet" href="./style.css?v=6">
</head>

<body>

<div class="container">

<div class="card hero">
<h2>รับคิวสำเร็จแล้ว</h2>
<p>ระบบบันทึกคิวของคุณเรียบร้อยแล้ว</p>
</div>

<div class="card queue-hero">

<div class="muted"><?=h($q["shop_name"])?></div>

<div class="queue-no">
#<?= (int)$q["queue_no"] ?>
</div>

<div>
<span class="badge <?=h(badgeClass($status))?>">
<?=h(statusTH($status))?>
</span>
</div>

<div class="small">
ตำแหน่งร้าน: <?=h($locationLabel)?>
</div>

</div>

<div class="card">

<div class="info-block">
<div class="info-title">เวลารอโดยประมาณ</div>

<?php if($isReceived): ?>

<div style="font-weight:800;">รับออเดอร์แล้ว ✅</div>
<div class="small">คิวนี้เสร็จสิ้นแล้ว</div>

<?php elseif($isServed): ?>

<div style="font-weight:800;">พร้อมรับออเดอร์แล้ว ✅</div>
<div class="small">ร้านเตรียมออเดอร์เรียบร้อยแล้ว</div>

<?php elseif($isCancel): ?>

<div style="font-weight:800;">คิวนี้ถูกยกเลิก ❌</div>
<div class="small">กรุณากลับไปหน้าร้านเพื่อรับคิวใหม่</div>

<?php else: ?>

<div style="font-weight:800;">
<?=h(formatMinutesTH($minutes))?>
</div>

<div class="small">
เหลือประมาณ <?=$beforeMe?> คิว
</div>

<?php endif; ?>

</div>

</div>

<div class="card">

<button class="btn btn-primary"
onclick="location.href='my-queue.php?queue_id=<?=(int)$q['queue_id']?>'">

ดูคิวของฉัน

</button>

<button class="btn btn-secondary"
onclick="location.href='shop.php?shop_id=<?=(int)$q['shop_id']?>'">

กลับไปหน้าร้าน

</button>

</div>

</div>

</body>
</html>