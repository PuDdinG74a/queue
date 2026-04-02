<?php
require_once __DIR__ . "/_auth.php";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

/* =========================
   CSRF
========================= */
if (empty($_SESSION["csrf_token"])) {
  $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["csrf_token"];

/* =========================
   filter source lists
========================= */
$domesList = $pdo->query("
  SELECT dome_id, dome_name
  FROM domes
  ORDER BY dome_name ASC, dome_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$categoriesList = $pdo->query("
  SELECT category_id, category_name
  FROM shop_categories
  ORDER BY category_name ASC, category_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$typesList = $pdo->query("
  SELECT type_id, type_name, category_id
  FROM shop_types
  ORDER BY type_name ASC, type_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$shopsList = $pdo->query("
  SELECT s.shop_id, s.name, l.dome_id, t.type_id, c.category_id
  FROM shops s
  LEFT JOIN locks l ON l.lock_id = s.lock_id
  LEFT JOIN shop_types t ON t.type_id = s.type_id
  LEFT JOIN shop_categories c ON c.category_id = t.category_id
  ORDER BY s.name ASC, s.shop_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   receive filters
========================= */
$domeId     = $_GET["dome_id"] ?? "all";
$categoryId = $_GET["category_id"] ?? "all";
$typeId     = $_GET["type_id"] ?? "all";
$shopId     = $_GET["shop_id"] ?? "all";

$status = $_GET["status"] ?? "all";
$start  = $_GET["start"] ?? date("Y-m-d", strtotime("-7 days"));
$end    = $_GET["end"] ?? date("Y-m-d");
$q      = trim($_GET["q"] ?? "");

$viewMode = $_GET["view_mode"] ?? "active";
$allowViewModes = ["active","all","trashed"];
if (!in_array($viewMode, $allowViewModes, true)) {
  $viewMode = "active";
}

$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

if ($start > $end) {
  $tmp = $start;
  $start = $end;
  $end = $tmp;
}

/* =========================
   helper
========================= */
function minutesDiff($a, $b){
  if (!$a || !$b) return null;
  $m = (int)round((strtotime($b) - strtotime($a))/60);
  if ($m < 0) $m = 0;
  return $m;
}
function statusTH($st){
  return match($st){
    "waiting"  => "รอดำเนินการ",
    "called"   => "กำลังดำเนินการ",
    "calling"  => "กำลังดำเนินการ",
    "served"   => "เสร็จสิ้น",
    "received" => "รับออเดอร์แล้ว",
    "cancel"   => "ยกเลิก",
    default    => $st ?: "-"
  };
}
function keepQuery(array $override = []){
  $base = $_GET;
  foreach($override as $k=>$v){
    if ($v === null) unset($base[$k]);
    else $base[$k] = $v;
  }
  return http_build_query($base);
}
function badgeClassByStatus(?string $status, ?string $deletedAt): string {
  if (!empty($deletedAt)) return "t-deleted";
  return match($status){
    "waiting"  => "t-waiting",
    "called"   => "t-called",
    "calling"  => "t-called",
    "served"   => "t-served",
    "received" => "t-received",
    "cancel"   => "t-cancel",
    default    => ""
  };
}
function badgeTextByStatus(?string $status, ?string $deletedAt): string {
  if (!empty($deletedAt)) return "อยู่ในถัง";
  return statusTH((string)$status);
}

/* =========================
   POST: soft delete / restore only
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $token = $_POST["csrf_token"] ?? "";
  if (!hash_equals($_SESSION["csrf_token"] ?? "", $token)) {
    http_response_code(403);
    exit("CSRF token ไม่ถูกต้อง");
  }

  $action = $_POST["action"] ?? "";
  $queue_id = (int)($_POST["queue_id"] ?? 0);

  $back = "admin-history.php?" . keepQuery([]);

  if ($queue_id <= 0) {
    header("Location: ".$back);
    exit;
  }

  if ($action === "trash") {
    $stmt = $pdo->prepare("
      UPDATE queues
      SET deleted_at = NOW()
      WHERE queue_id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$queue_id]);

    header("Location: ".$back."&msg=trashed");
    exit;
  }

  if ($action === "restore") {
    $stmt = $pdo->prepare("
      UPDATE queues
      SET deleted_at = NULL
      WHERE queue_id = ? AND deleted_at IS NOT NULL
    ");
    $stmt->execute([$queue_id]);

    header("Location: ".$back."&msg=restored");
    exit;
  }

  header("Location: ".$back);
  exit;
}

/* =========================
   build WHERE
========================= */
$fromJoin = "
  FROM queues q
  INNER JOIN shops s ON s.shop_id = q.shop_id
  LEFT JOIN locks l ON l.lock_id = s.lock_id
  LEFT JOIN domes d ON d.dome_id = l.dome_id
  LEFT JOIN shop_types t ON t.type_id = s.type_id
  LEFT JOIN shop_categories c ON c.category_id = t.category_id
";

$where = "WHERE q.queue_date >= :start AND q.queue_date <= :end";
$params = [
  "start" => $start,
  "end"   => $end
];

if ($domeId !== "all") {
  $where .= " AND d.dome_id = :dome_id";
  $params["dome_id"] = (int)$domeId;
}

if ($categoryId !== "all") {
  $where .= " AND c.category_id = :category_id";
  $params["category_id"] = (int)$categoryId;
}

if ($typeId !== "all") {
  $where .= " AND t.type_id = :type_id";
  $params["type_id"] = (int)$typeId;
}

if ($shopId !== "all") {
  $where .= " AND s.shop_id = :shop_id";
  $params["shop_id"] = (int)$shopId;
}

if ($status !== "all") {
  $where .= " AND q.status = :status";
  $params["status"] = $status;
}

if ($q !== "") {
  $where .= " AND (
    CAST(q.queue_id AS CHAR) LIKE :q1
    OR CAST(q.queue_no AS CHAR) LIKE :q2
    OR q.customer_name LIKE :q3
    OR q.customer_phone LIKE :q4
    OR q.customer_note LIKE :q5
    OR s.name LIKE :q6
  )";
  $params["q1"] = "%".$q."%";
  $params["q2"] = "%".$q."%";
  $params["q3"] = "%".$q."%";
  $params["q4"] = "%".$q."%";
  $params["q5"] = "%".$q."%";
  $params["q6"] = "%".$q."%";
}

if ($viewMode === "active") {
  $where .= " AND q.deleted_at IS NULL";
} elseif ($viewMode === "trashed") {
  $where .= " AND q.deleted_at IS NOT NULL";
}

/* =========================
   KPI
========================= */
$kpiSql = "
  SELECT
    COUNT(*) AS total_rows,
    SUM(CASE WHEN q.status='waiting' AND q.deleted_at IS NULL THEN 1 ELSE 0 END) AS waiting_rows,
    SUM(CASE WHEN (q.status='called' OR q.status='calling') AND q.deleted_at IS NULL THEN 1 ELSE 0 END) AS called_rows,
    SUM(CASE WHEN (q.status='served' OR q.status='received') AND q.deleted_at IS NULL THEN 1 ELSE 0 END) AS served_rows,
    SUM(CASE WHEN q.deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS trashed_rows
  $fromJoin
  $where
";
$kpiStmt = $pdo->prepare($kpiSql);
foreach($params as $k=>$v){
  $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
  $kpiStmt->bindValue(":".$k, $v, $type);
}
$kpiStmt->execute();
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [
  "total_rows" => 0,
  "waiting_rows" => 0,
  "called_rows" => 0,
  "served_rows" => 0,
  "trashed_rows" => 0,
];

/* =========================
   count total rows
========================= */
$countStmt = $pdo->prepare("SELECT COUNT(*) $fromJoin $where");
foreach($params as $k=>$v){
  $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
  $countStmt->bindValue(":".$k, $v, $type);
}
$countStmt->execute();
$totalRows = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

/* =========================
   load data
========================= */
$dataSql = "
  SELECT
    q.queue_id,
    q.shop_id,
    s.name AS shop_name,
    q.queue_date,
    q.queue_no,
    q.customer_name,
    q.customer_phone,
    q.customer_note,
    q.status,
    q.created_at,
    q.called_at,
    q.served_at,
    q.deleted_at,
    d.dome_id,
    d.dome_name,
    c.category_id,
    c.category_name,
    t.type_id,
    t.type_name
  $fromJoin
  $where
  ORDER BY
    (q.deleted_at IS NOT NULL) ASC,
    q.queue_date DESC,
    q.queue_no DESC,
    q.created_at DESC
  LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($dataSql);
foreach($params as $k=>$v){
  $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
  $stmt->bindValue(":".$k, $v, $type);
}
$stmt->bindValue(":limit", (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue(":offset", (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$msg = $_GET["msg"] ?? "";
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin History</title>
  <style>
    :root { --bg:#f6f7fb; --card:#fff; --text:#111827; --muted:#6b7280; --primary:#2563eb; --border:#e5e7eb; --ok:#16a34a; --warn:#f59e0b; --bad:#dc2626; }
    *{ box-sizing:border-box; }
    body{ margin:0; font-family:system-ui, -apple-system, "Segoe UI", sans-serif; background:var(--bg); color:var(--text); }
    .app{ display:flex; min-height:100vh; }
    .sidebar{ width:260px; min-width:260px; max-width:260px; flex:0 0 260px; background:#0f172a; color:#fff; padding:16px; }
    .brand{ display:flex; gap:10px; align-items:center; padding:10px 10px 16px; border-bottom:1px solid rgba(255,255,255,.12); }
    .logo{ width:40px; height:40px; border-radius:12px; background:#1d4ed8; display:grid; place-items:center; font-weight:800; }
    .brand small{ color:rgba(255,255,255,.7); }

    .admin-user-box{
      margin-top:14px;
      margin-bottom:14px;
      padding:12px;
      border-radius:14px;
      background:rgba(255,255,255,.08);
      border:1px solid rgba(255,255,255,.12);
    }
    .admin-user-label{
      font-size:12px;
      color:rgba(255,255,255,.72);
      margin-bottom:6px;
    }
    .admin-user-name{
      font-size:14px;
      font-weight:800;
      color:#fff;
      line-height:1.3;
      word-break:break-word;
    }
    .admin-user-role{
      margin-top:4px;
      font-size:12px;
      color:rgba(255,255,255,.72);
    }

    .nav{ margin-top:12px; display:flex; flex-direction:column; gap:6px; }
    .nav a{ color:#fff; text-decoration:none; padding:10px 12px; border-radius:12px; display:flex; gap:10px; align-items:center; }
    .nav a:hover{ background:rgba(255,255,255,.08); }
    .nav a.active{ background:rgba(37,99,235,.25); border:1px solid rgba(37,99,235,.35); }

    .main{ flex:1; min-width:0; padding:16px; }
    .topbar{ display:flex; justify-content:space-between; align-items:center; gap:12px; }
    h1{ margin:0; font-size:20px; }
    .muted{ color:var(--muted); font-size:13px; }

    .btn{
      padding:10px 12px;
      border-radius:12px;
      border:1px solid var(--border);
      background:#fff;
      cursor:pointer;
      text-decoration:none;
      color:var(--text);
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:6px;
      font-size:14px;
      white-space:nowrap;
    }
    .btn.small{
      padding:6px 10px;
      border-radius:10px;
      font-size:12px;
    }
    .btn.primary{ background:var(--primary); color:#fff; border-color:transparent; }
    .btn.danger{ border-color:rgba(220,38,38,.35); color:var(--bad); }

    .card{ background:var(--card); border:1px solid var(--border); border-radius:16px; padding:14px; box-shadow:0 8px 20px rgba(0,0,0,.05); margin-top:12px; }

    .kpi{ display:grid; grid-template-columns:repeat(5, 1fr); gap:12px; }
    .kpi .box{ border:1px solid var(--border); border-radius:16px; padding:14px; background:#fff; }
    .kpi .label{ color:var(--muted); font-size:13px; }
    .kpi .value{ font-size:26px; font-weight:900; margin-top:6px; }

    .filters{
      display:grid;
      grid-template-columns:repeat(4, minmax(180px,1fr));
      gap:10px;
      align-items:end;
    }
    .field{
      display:flex;
      flex-direction:column;
      gap:6px;
      min-width:0;
    }
    select,input{
      width:100%;
      padding:10px 12px;
      border:1px solid var(--border);
      border-radius:12px;
      font-size:14px;
      min-width:0;
    }

    .search-span-2{ grid-column:span 2; }
    .actions-span-2{
      grid-column:span 2;
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      align-items:end;
    }

    .table-wrap{
      overflow:auto;
      width:100%;
    }
    table{
      width:100%;
      min-width:1500px;
      border-collapse:separate;
      border-spacing:0;
      overflow:hidden;
      border-radius:14px;
      border:1px solid var(--border);
    }
    th,td{ padding:10px 12px; font-size:14px; border-bottom:1px solid var(--border); vertical-align:top; }
    th{ background:#f9fafb; text-align:left; color:#374151; }
    tr:last-child td{ border-bottom:0; }

    .tag{
      display:inline-block;
      padding:4px 10px;
      border-radius:999px;
      font-size:12px;
      border:1px solid var(--border);
      background:#fff;
      font-weight:700;
      white-space:nowrap;
    }
    .t-waiting{ border-color:rgba(245,158,11,.35); background:rgba(245,158,11,.08); color:#8a5a00; }
    .t-called{ border-color:rgba(37,99,235,.35); background:rgba(37,99,235,.08); color:#1c4fbf; }
    .t-served{ border-color:rgba(22,163,74,.35); background:rgba(22,163,74,.08); color:#0c7a32; }
    .t-received{ border-color:rgba(14,165,233,.35); background:rgba(14,165,233,.08); color:#0369a1; }
    .t-cancel{ border-color:rgba(107,114,128,.35); background:rgba(107,114,128,.10); color:#4b5563; }
    .t-deleted{ border-color:rgba(220,38,38,.35); background:rgba(220,38,38,.06); color:#b91c1c; }

    .toast{ padding:10px 12px; border-radius:12px; border:1px solid var(--border); background:#fff; margin-top:10px; display:inline-block; }
    .toast.ok{ border-color:rgba(22,163,74,.35); background:rgba(22,163,74,.06); color:#0c7a32; }
    .toast.warn{ border-color:rgba(245,158,11,.35); background:rgba(245,158,11,.06); color:#8a5a00; }

    .pager{ display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin-top:12px; }
    .pager a, .pager span{
      display:inline-flex; align-items:center; justify-content:center;
      min-width:36px; height:36px; padding:0 10px;
      border:1px solid var(--border); border-radius:12px; text-decoration:none; color:var(--text); background:#fff;
    }
    .pager .active{ background:rgba(37,99,235,.10); border-color:rgba(37,99,235,.35); color:#1d4ed8; font-weight:700; }
    .pager .disabled{ opacity:.5; pointer-events:none; }

    @media (max-width: 980px){
      .sidebar{ display:none; }
      .kpi{ grid-template-columns:1fr 1fr; }
      .filters{ grid-template-columns:1fr; }
      .search-span-2,
      .actions-span-2{ grid-column:span 1; }
    }
  </style>
</head>
<body>
  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <div class="logo">MN</div>
        <div>
          <div style="font-weight:800;">ตลาดน้อย</div>
          <small>Admin Panel</small>
        </div>
      </div>

      <div class="admin-user-box">
        <div class="admin-user-label">ผู้ใช้งานปัจจุบัน</div>
        <div class="admin-user-name">👤 <?= h($admin_name ?? "Admin") ?></div>
        <div class="admin-user-role">สิทธิ์: Admin</div>
      </div>

      <nav class="nav">
        <a href="admin-dashboard.php">📊 แดชบอร์ด</a>
        <a href="admin-domes-locks.php">🧩 โดม/ล็อก</a>
        <a href="admin-shops.php">🏪 จัดการร้านค้า</a>
        <a href="admin-shop-categories.php">📁 หมวดหมู่ร้าน</a>
        <a href="admin-shop-types.php">🧩 ประเภทร้าน</a>
        <a class="active" href="admin-history.php">🧾 ประวัติออเดอร์</a>
        <a href="admin-reports.php">📈 รายงาน</a>
        <a href="admin-shop-accounts.php">🔐 บัญชีร้านค้า</a>
        <a href="../logout.php">🚪 ออกจากระบบ</a>
        <a href="../Frontend/index.php">↩ กลับหน้าเว็บ</a>
      </nav>
      <div style="margin-top:14px;">
        <div class="muted" style="color:rgba(255,255,255,.75);">
          * หน้าตรวจสอบข้อมูลออเดอร์ย้อนหลังของระบบ
        </div>
      </div>
    </aside>

    <main class="main">
      <div class="topbar">
        <div>
          <h1>🧾 ประวัติออเดอร์</h1>
          <div class="muted">ตรวจสอบข้อมูลออเดอร์ย้อนหลังแบบกรองตามโดม / หมวดหมู่ / ประเภท / ร้าน</div>

          <?php if($msg === "trashed"): ?>
            <div class="toast.warn">ย้ายรายการเข้าถังแล้ว ✅ (กู้คืนได้)</div>
          <?php elseif($msg === "restored"): ?>
            <div class="toast.ok">กู้คืนรายการแล้ว ✅</div>
          <?php endif; ?>
        </div>

        <div style="display:flex; gap:8px;">
          <button class="btn" onclick="location.reload()">รีเฟรช</button>
          <button class="btn primary" onclick="document.getElementById('filterForm').submit()">ค้นหา / กรอง</button>
        </div>
      </div>

      <section class="card">
        <div class="kpi">
          <div class="box">
            <div class="label">ออเดอร์ทั้งหมด</div>
            <div class="value"><?= (int)$kpi["total_rows"] ?></div>
          </div>
          <div class="box">
            <div class="label">รอดำเนินการ</div>
            <div class="value"><?= (int)$kpi["waiting_rows"] ?></div>
          </div>
          <div class="box">
            <div class="label">กำลังดำเนินการ</div>
            <div class="value"><?= (int)$kpi["called_rows"] ?></div>
          </div>
          <div class="box">
            <div class="label">เสร็จสิ้น / รับแล้ว</div>
            <div class="value"><?= (int)$kpi["served_rows"] ?></div>
          </div>
          <div class="box">
            <div class="label">อยู่ในถัง</div>
            <div class="value"><?= (int)$kpi["trashed_rows"] ?></div>
          </div>
        </div>
      </section>

      <section class="card">
        <form id="filterForm" method="get" class="filters">
          <div class="field">
            <div class="muted">โดม</div>
            <select name="dome_id">
              <option value="all" <?= $domeId==="all" ? "selected" : "" ?>>ทั้งหมด</option>
              <?php foreach($domesList as $d): ?>
                <option value="<?= (int)$d["dome_id"] ?>" <?= ((string)$domeId === (string)$d["dome_id"]) ? "selected" : "" ?>>
                  <?= h($d["dome_name"]) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <div class="muted">หมวดหมู่ร้าน</div>
            <select name="category_id">
              <option value="all" <?= $categoryId==="all" ? "selected" : "" ?>>ทั้งหมด</option>
              <?php foreach($categoriesList as $c): ?>
                <option value="<?= (int)$c["category_id"] ?>" <?= ((string)$categoryId === (string)$c["category_id"]) ? "selected" : "" ?>>
                  <?= h($c["category_name"]) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <div class="muted">ประเภทร้าน</div>
            <select name="type_id">
              <option value="all" <?= $typeId==="all" ? "selected" : "" ?>>ทั้งหมด</option>
              <?php foreach($typesList as $t): ?>
                <option value="<?= (int)$t["type_id"] ?>" <?= ((string)$typeId === (string)$t["type_id"]) ? "selected" : "" ?>>
                  <?= h($t["type_name"]) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <div class="muted">ร้าน</div>
            <select name="shop_id">
              <option value="all" <?= $shopId==="all" ? "selected" : "" ?>>ทั้งหมด</option>
              <?php foreach($shopsList as $s): ?>
                <option value="<?= (int)$s["shop_id"] ?>" <?= ((string)$shopId === (string)$s["shop_id"]) ? "selected" : "" ?>>
                  <?= h($s["name"]) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <div class="muted">วันที่เริ่ม</div>
            <input type="date" name="start" value="<?= h($start) ?>">
          </div>

          <div class="field">
            <div class="muted">วันที่สิ้นสุด</div>
            <input type="date" name="end" value="<?= h($end) ?>">
          </div>

          <div class="field">
            <div class="muted">สถานะออเดอร์</div>
            <select name="status">
              <option value="all" <?= $status==="all" ? "selected" : "" ?>>ทั้งหมด</option>
              <option value="waiting" <?= $status==="waiting" ? "selected" : "" ?>>รอดำเนินการ</option>
              <option value="called" <?= $status==="called" ? "selected" : "" ?>>กำลังดำเนินการ</option>
              <option value="calling" <?= $status==="calling" ? "selected" : "" ?>>กำลังดำเนินการ</option>
              <option value="served" <?= $status==="served" ? "selected" : "" ?>>เสร็จสิ้น</option>
              <option value="received" <?= $status==="received" ? "selected" : "" ?>>รับออเดอร์แล้ว</option>
              <option value="cancel" <?= $status==="cancel" ? "selected" : "" ?>>ยกเลิก</option>
            </select>
          </div>

          <div class="field">
            <div class="muted">มุมมองข้อมูล</div>
            <select name="view_mode">
              <option value="active" <?= $viewMode==="active" ? "selected" : "" ?>>เฉพาะรายการปกติ</option>
              <option value="all" <?= $viewMode==="all" ? "selected" : "" ?>>ทั้งหมด</option>
              <option value="trashed" <?= $viewMode==="trashed" ? "selected" : "" ?>>เฉพาะในถัง</option>
            </select>
          </div>

          <div class="field search-span-2">
            <div class="muted">ค้นหา</div>
            <input
              name="q"
              value="<?= h($q) ?>"
              placeholder="ค้นหา order_id / เลขออเดอร์ / ชื่อลูกค้า / เบอร์โทร / รายละเอียดออเดอร์ / ชื่อร้าน"
            >
          </div>

          <div class="actions-span-2">
            <button class="btn primary" type="submit">ค้นหา / กรอง</button>
            <a class="btn" href="admin-history.php">ล้างทั้งหมด</a>
          </div>

          <input type="hidden" name="page" value="1">
        </form>
      </section>

      <section class="card">
        <div class="muted" style="margin-bottom:10px;">
          พบทั้งหมด <?= $totalRows ?> รายการ • หน้า <?= $page ?>/<?= $totalPages ?> • หน้าละ <?= $perPage ?>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th style="width:90px;">order_id</th>
                <th style="width:160px;">โดม</th>
                <th style="width:180px;">ร้าน</th>
                <th style="width:180px;">หมวดหมู่/ประเภท</th>
                <th style="width:90px;">เลขออเดอร์</th>
                <th style="width:110px;">วันที่</th>
                <th style="width:120px;">ลูกค้า</th>
                <th style="width:120px;">เบอร์โทร</th>
                <th style="min-width:220px;">รายละเอียดออเดอร์</th>
                <th style="width:160px;">สร้างออเดอร์เมื่อ</th>
                <th style="width:160px;">เริ่มดำเนินการเมื่อ</th>
                <th style="width:160px;">เสร็จสิ้นเมื่อ</th>
                <th style="width:120px;">สถานะ</th>
                <th style="width:160px;">ระยะเวลา</th>
                <th style="width:170px;">จัดการข้อมูล</th>
              </tr>
            </thead>
            <tbody>
              <?php if(!$rows): ?>
                <tr><td colspan="15" class="muted">ไม่พบข้อมูลตามตัวกรอง</td></tr>
              <?php else: ?>
                <?php foreach($rows as $r): ?>
                  <?php
                    $wCalled = minutesDiff($r["created_at"], $r["called_at"]);
                    $wServed = minutesDiff($r["created_at"], $r["served_at"]);
                    $waitText = $wServed !== null
                      ? ($wServed . " นาที/ออเดอร์")
                      : ($wCalled !== null ? ($wCalled . " นาที (ถึงขั้นตอนดำเนินการ)") : "-");

                    $isDeleted = !empty($r["deleted_at"]);
                    $tagClass = badgeClassByStatus($r["status"], $r["deleted_at"]);
                    $tagText  = badgeTextByStatus($r["status"], $r["deleted_at"]);
                  ?>
                  <tr style="<?= $isDeleted ? "opacity:.75;" : "" ?>">
                    <td><?= h($r["queue_id"]) ?></td>

                    <td>
                      <div style="font-weight:800;"><?= h($r["dome_name"] ?: "-") ?></div>
                      <div class="muted">dome_id: <?= h($r["dome_id"] ?: "-") ?></div>
                    </td>

                    <td>
                      <div style="font-weight:800;"><?= h($r["shop_name"]) ?></div>
                      <div class="muted">shop_id: <?= (int)$r["shop_id"] ?></div>
                    </td>

                    <td>
                      <div><?= h($r["category_name"] ?: "-") ?></div>
                      <div class="muted"><?= h($r["type_name"] ?: "-") ?></div>
                    </td>

                    <td>#<?= str_pad((string)$r["queue_no"], 3, "0", STR_PAD_LEFT) ?></td>
                    <td><?= h($r["queue_date"]) ?></td>
                    <td><?= h($r["customer_name"] ?: "-") ?></td>
                    <td><?= h($r["customer_phone"] ?: "-") ?></td>
                    <td><?= nl2br(h($r["customer_note"] ?: "-")) ?></td>
                    <td><?= h($r["created_at"] ?: "-") ?></td>
                    <td><?= h($r["called_at"] ?: "-") ?></td>
                    <td><?= h($r["served_at"] ?: "-") ?></td>
                    <td><span class="tag <?= h($tagClass) ?>"><?= h($tagText) ?></span></td>
                    <td><?= h($waitText) ?></td>
                    <td>
                      <?php if(!$isDeleted): ?>
                        <form method="post" style="margin:0;" onsubmit="return confirm('ย้ายออเดอร์นี้เข้าถัง? (กู้คืนได้)')">
                          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                          <input type="hidden" name="action" value="trash">
                          <input type="hidden" name="queue_id" value="<?= (int)$r["queue_id"] ?>">
                          <button class="btn small danger" type="submit">🗑 เข้าถัง</button>
                        </form>
                      <?php else: ?>
                        <form method="post" style="margin:0;" onsubmit="return confirm('กู้คืนออเดอร์นี้?')">
                          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                          <input type="hidden" name="action" value="restore">
                          <input type="hidden" name="queue_id" value="<?= (int)$r["queue_id"] ?>">
                          <button class="btn small primary" type="submit">♻️ กู้คืน</button>
                        </form>
                        <div class="muted" style="margin-top:6px;">
                          ลบเมื่อ: <?= h($r["deleted_at"]) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="pager">
          <?php
            $prev = max(1, $page-1);
            $next = min($totalPages, $page+1);
            $disabledPrev = ($page <= 1) ? "disabled" : "";
            $disabledNext = ($page >= $totalPages) ? "disabled" : "";

            $window = 2;
            $startPage = max(1, $page - $window);
            $endPage = min($totalPages, $page + $window);
          ?>

          <a class="<?= $disabledPrev ?>" href="<?= h("admin-history.php?".keepQuery(["page"=>$prev])) ?>">« ก่อนหน้า</a>

          <?php if($startPage > 1): ?>
            <a href="<?= h("admin-history.php?".keepQuery(["page"=>1])) ?>">1</a>
            <?php if($startPage > 2): ?><span class="disabled">…</span><?php endif; ?>
          <?php endif; ?>

          <?php for($p=$startPage; $p<=$endPage; $p++): ?>
            <?php if($p == $page): ?>
              <span class="active"><?= $p ?></span>
            <?php else: ?>
              <a href="<?= h("admin-history.php?".keepQuery(["page"=>$p])) ?>"><?= $p ?></a>
            <?php endif; ?>
          <?php endfor; ?>

          <?php if($endPage < $totalPages): ?>
            <?php if($endPage < $totalPages-1): ?><span class="disabled">…</span><?php endif; ?>
            <a href="<?= h("admin-history.php?".keepQuery(["page"=>$totalPages])) ?>"><?= $totalPages ?></a>
          <?php endif; ?>

          <a class="<?= $disabledNext ?>" href="<?= h("admin-history.php?".keepQuery(["page"=>$next])) ?>">ถัดไป »</a>
        </div>
      </section>
    </main>
  </div>
</body>
</html>