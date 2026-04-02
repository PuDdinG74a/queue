<?php
require_once __DIR__ . "/_auth.php";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

$current_page = basename($_SERVER['PHP_SELF']);

// ===== helper: detect column name (ทำให้ทน schema) =====
function getColumns(PDO $pdo, string $table): array {
  $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function pickColumn(array $cols, array $candidates): ?string {
  $names = array_map(fn($c)=>$c["Field"], $cols);
  foreach($candidates as $cand){
    if (in_array($cand, $names, true)) return $cand;
  }
  return null;
}

// ===== detect domes columns =====
$domeCols = getColumns($pdo, "domes");
$domeIdCol   = pickColumn($domeCols, ["dome_id","id"]);
$domeNameCol = pickColumn($domeCols, ["name","dome_name","title"]);
$domeDescCol = pickColumn($domeCols, ["description","detail","note"]);

if (!$domeIdCol || !$domeNameCol) {
  die("ตาราง domes ต้องมีคอลัมน์ id และชื่อโดม (เช่น dome_id และ name/dome_name)");
}

// ===== detect locks columns =====
$lockCols = getColumns($pdo, "locks");
$lockIdCol     = pickColumn($lockCols, ["lock_id","id"]);
$lockDomeCol   = pickColumn($lockCols, ["dome_id"]);
$lockNoCol     = pickColumn($lockCols, ["lock_no","lock_number","no"]);
$lockStatusCol = pickColumn($lockCols, ["status","lock_status","is_active"]);

if (!$lockIdCol || !$lockDomeCol || !$lockNoCol) {
  die("ตาราง locks ต้องมี lock_id, dome_id, lock_no");
}

$msg = $_GET["msg"] ?? "";

// ===== Actions =====
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = $_POST["action"] ?? "";

  // --- Dome CRUD ---
  if ($action === "add_dome") {
    $name = trim($_POST["dome_name"] ?? "");
    $desc = trim($_POST["dome_desc"] ?? "");

    if ($name === "") {
      header("Location: admin-domes-locks.php?msg=empty_dome");
      exit;
    }

    if ($domeDescCol) {
      $stmt = $pdo->prepare("INSERT INTO domes (`$domeNameCol`, `$domeDescCol`) VALUES (?, ?)");
      $stmt->execute([$name, $desc]);
    } else {
      $stmt = $pdo->prepare("INSERT INTO domes (`$domeNameCol`) VALUES (?)");
      $stmt->execute([$name]);
    }

    header("Location: admin-domes-locks.php?msg=dome_added");
    exit;
  }

  if ($action === "edit_dome") {
    $dome_id = (int)($_POST["dome_id"] ?? 0);
    $name = trim($_POST["dome_name"] ?? "");
    $desc = trim($_POST["dome_desc"] ?? "");

    if ($dome_id <= 0 || $name === "") {
      header("Location: admin-domes-locks.php?msg=bad_dome");
      exit;
    }

    if ($domeDescCol) {
      $stmt = $pdo->prepare("UPDATE domes SET `$domeNameCol`=?, `$domeDescCol`=? WHERE `$domeIdCol`=?");
      $stmt->execute([$name, $desc, $dome_id]);
    } else {
      $stmt = $pdo->prepare("UPDATE domes SET `$domeNameCol`=? WHERE `$domeIdCol`=?");
      $stmt->execute([$name, $dome_id]);
    }

    header("Location: admin-domes-locks.php?msg=dome_updated");
    exit;
  }

  if ($action === "delete_dome") {
    $dome_id = (int)($_POST["dome_id"] ?? 0);
    if ($dome_id <= 0) {
      header("Location: admin-domes-locks.php?msg=bad_dome");
      exit;
    }

    // ป้องกัน: ถ้าโดมนี้ยังมีล็อกอยู่ ไม่ให้ลบ
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM locks WHERE `$lockDomeCol`=?");
    $stmt->execute([$dome_id]);
    $cnt = (int)$stmt->fetchColumn();
    if ($cnt > 0) {
      header("Location: admin-domes-locks.php?msg=dome_has_locks");
      exit;
    }

    $stmt = $pdo->prepare("DELETE FROM domes WHERE `$domeIdCol`=?");
    $stmt->execute([$dome_id]);

    header("Location: admin-domes-locks.php?msg=dome_deleted");
    exit;
  }

  // --- Lock CRUD ---
  if ($action === "add_lock") {
    $dome_id = (int)($_POST["lock_dome_id"] ?? 0);
    $lock_no = (int)($_POST["lock_no"] ?? 0);
    $status  = trim($_POST["lock_status"] ?? "");

    if ($dome_id <= 0 || $lock_no <= 0) {
      header("Location: admin-domes-locks.php?msg=bad_lock");
      exit;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM locks WHERE `$lockDomeCol`=? AND `$lockNoCol`=?");
    $stmt->execute([$dome_id, $lock_no]);
    if ((int)$stmt->fetchColumn() > 0) {
      header("Location: admin-domes-locks.php?msg=lock_dup");
      exit;
    }

    if ($lockStatusCol) {
      // รองรับกรณี status แบบข้อความ หรือ is_active แบบตัวเลข
      $isBinaryActive = false;
      foreach ($lockCols as $c) {
        if ($c["Field"] === $lockStatusCol) {
          $type = strtolower((string)$c["Type"]);
          $isBinaryActive = str_contains($type, 'tinyint') || str_contains($type, 'int');
          break;
        }
      }

      if ($isBinaryActive) {
        $statusVal = ($status === "inactive") ? 0 : 1;
      } else {
        $statusVal = $status ?: "active";
      }

      $stmt = $pdo->prepare("INSERT INTO locks (`$lockDomeCol`, `$lockNoCol`, `$lockStatusCol`) VALUES (?, ?, ?)");
      $stmt->execute([$dome_id, $lock_no, $statusVal]);
    } else {
      $stmt = $pdo->prepare("INSERT INTO locks (`$lockDomeCol`, `$lockNoCol`) VALUES (?, ?)");
      $stmt->execute([$dome_id, $lock_no]);
    }

    header("Location: admin-domes-locks.php?msg=lock_added");
    exit;
  }

  if ($action === "edit_lock") {
    $lock_id = (int)($_POST["lock_id"] ?? 0);
    $dome_id = (int)($_POST["lock_dome_id"] ?? 0);
    $lock_no = (int)($_POST["lock_no"] ?? 0);
    $status  = trim($_POST["lock_status"] ?? "");

    if ($lock_id <= 0 || $dome_id <= 0 || $lock_no <= 0) {
      header("Location: admin-domes-locks.php?msg=bad_lock");
      exit;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM locks WHERE `$lockDomeCol`=? AND `$lockNoCol`=? AND `$lockIdCol`<>?");
    $stmt->execute([$dome_id, $lock_no, $lock_id]);
    if ((int)$stmt->fetchColumn() > 0) {
      header("Location: admin-domes-locks.php?msg=lock_dup");
      exit;
    }

    if ($lockStatusCol) {
      $isBinaryActive = false;
      foreach ($lockCols as $c) {
        if ($c["Field"] === $lockStatusCol) {
          $type = strtolower((string)$c["Type"]);
          $isBinaryActive = str_contains($type, 'tinyint') || str_contains($type, 'int');
          break;
        }
      }

      if ($isBinaryActive) {
        $statusVal = ($status === "inactive") ? 0 : 1;
      } else {
        $statusVal = $status ?: "active";
      }

      $stmt = $pdo->prepare("UPDATE locks SET `$lockDomeCol`=?, `$lockNoCol`=?, `$lockStatusCol`=? WHERE `$lockIdCol`=?");
      $stmt->execute([$dome_id, $lock_no, $statusVal, $lock_id]);
    } else {
      $stmt = $pdo->prepare("UPDATE locks SET `$lockDomeCol`=?, `$lockNoCol`=? WHERE `$lockIdCol`=?");
      $stmt->execute([$dome_id, $lock_no, $lock_id]);
    }

    header("Location: admin-domes-locks.php?msg=lock_updated");
    exit;
  }

  if ($action === "delete_lock") {
    $lock_id = (int)($_POST["lock_id"] ?? 0);
    if ($lock_id <= 0) {
      header("Location: admin-domes-locks.php?msg=bad_lock");
      exit;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM shops WHERE lock_id=?");
    $stmt->execute([$lock_id]);
    if ((int)$stmt->fetchColumn() > 0) {
      header("Location: admin-domes-locks.php?msg=lock_in_use");
      exit;
    }

    $stmt = $pdo->prepare("DELETE FROM locks WHERE `$lockIdCol`=?");
    $stmt->execute([$lock_id]);

    header("Location: admin-domes-locks.php?msg=lock_deleted");
    exit;
  }
}

// ===== Filters =====
$filter_dome   = (int)($_GET["filter_dome"] ?? 0);
$filter_linked = trim((string)($_GET["filter_linked"] ?? ""));
$q             = trim((string)($_GET["q"] ?? ""));

// ===== Fetch Domes + lock counts =====
$domes = $pdo->query("
  SELECT d.`$domeIdCol` AS dome_id, d.`$domeNameCol` AS dome_name
  ".($domeDescCol ? ", d.`$domeDescCol` AS dome_desc" : ", '' AS dome_desc")."
  FROM domes d
  ORDER BY d.`$domeIdCol` ASC
")->fetchAll(PDO::FETCH_ASSOC);

$lockCounts = [];
$stmt = $pdo->query("
  SELECT `$lockDomeCol` AS dome_id, COUNT(*) AS cnt
  FROM locks
  GROUP BY `$lockDomeCol`
");
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r){
  $lockCounts[(int)$r["dome_id"]] = (int)$r["cnt"];
}

// ===== KPI =====
$totalDomes = (int)$pdo->query("SELECT COUNT(*) FROM domes")->fetchColumn();
$totalLocks = (int)$pdo->query("SELECT COUNT(*) FROM locks")->fetchColumn();
$totalLinkedLocks = (int)$pdo->query("
  SELECT COUNT(*)
  FROM locks l
  INNER JOIN shops s ON s.lock_id = l.`$lockIdCol`
")->fetchColumn();
$totalUnlinkedLocks = max(0, $totalLocks - $totalLinkedLocks);

$totalInactiveLocks = 0;
if ($lockStatusCol) {
  $isBinaryActive = false;
  foreach ($lockCols as $c) {
    if ($c["Field"] === $lockStatusCol) {
      $type = strtolower((string)$c["Type"]);
      $isBinaryActive = str_contains($type, 'tinyint') || str_contains($type, 'int');
      break;
    }
  }

  if ($isBinaryActive) {
    $totalInactiveLocks = (int)$pdo->query("SELECT COUNT(*) FROM locks WHERE `$lockStatusCol` = 0")->fetchColumn();
  } else {
    $totalInactiveLocks = (int)$pdo->query("SELECT COUNT(*) FROM locks WHERE `$lockStatusCol` IN ('inactive','disabled','off')")->fetchColumn();
  }
}

// ===== Summary by dome =====
$domeSummary = $pdo->query("
  SELECT
    d.`$domeIdCol` AS dome_id,
    d.`$domeNameCol` AS dome_name,
    COUNT(DISTINCT l.`$lockIdCol`) AS total_locks,
    COUNT(DISTINCT s.shop_id) AS total_linked
  FROM domes d
  LEFT JOIN locks l ON l.`$lockDomeCol` = d.`$domeIdCol`
  LEFT JOIN shops s ON s.lock_id = l.`$lockIdCol`
  GROUP BY d.`$domeIdCol`, d.`$domeNameCol`
  ORDER BY d.`$domeIdCol` ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ===== Fetch Locks + shop info + filter =====
$where = [];
$params = [];

if ($filter_dome > 0) {
  $where[] = "l.`$lockDomeCol` = ?";
  $params[] = $filter_dome;
}

if ($filter_linked === "linked") {
  $where[] = "s.shop_id IS NOT NULL";
} elseif ($filter_linked === "unlinked") {
  $where[] = "s.shop_id IS NULL";
}

if ($q !== "") {
  $where[] = "(CAST(l.`$lockNoCol` AS CHAR) LIKE ? OR d.`$domeNameCol` LIKE ? OR s.name LIKE ?)";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
}

$sqlLocks = "
  SELECT
    l.`$lockIdCol` AS lock_id,
    l.`$lockDomeCol` AS dome_id,
    l.`$lockNoCol` AS lock_no
    ".($lockStatusCol ? ", l.`$lockStatusCol` AS lock_status" : ", '' AS lock_status").",
    d.`$domeNameCol` AS dome_name,
    s.shop_id,
    s.name AS shop_name
  FROM locks l
  LEFT JOIN domes d ON d.`$domeIdCol` = l.`$lockDomeCol`
  LEFT JOIN shops s ON s.lock_id = l.`$lockIdCol`
";

if ($where) {
  $sqlLocks .= " WHERE " . implode(" AND ", $where);
}

$sqlLocks .= " ORDER BY l.`$lockDomeCol` ASC, l.`$lockNoCol` ASC";

$stmt = $pdo->prepare($sqlLocks);
$stmt->execute($params);
$locks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// helper แปลงสถานะล็อก
function lockStatusText($raw): string {
  if ($raw === "" || $raw === null) return "-";
  if ($raw === 1 || $raw === "1") return "active";
  if ($raw === 0 || $raw === "0") return "inactive";
  return (string)$raw;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Domes & Locks</title>
  <style>
    :root{
      --bg:#f6f7fb;
      --card:#fff;
      --text:#111827;
      --muted:#6b7280;
      --primary:#2563eb;
      --border:#e5e7eb;
      --bad:#dc2626;
      --ok:#16a34a;
      --warn:#f59e0b;
    }

    *{ box-sizing:border-box; }

    body{
      margin:0;
      font-family:system-ui,-apple-system,"Segoe UI",sans-serif;
      background:var(--bg);
      color:var(--text);
    }

    .app{ display:flex; min-height:100vh; }

    .sidebar{
      width:260px;
      background:#0f172a;
      color:#fff;
      padding:16px;
    }

    .brand{
      display:flex;
      gap:10px;
      align-items:center;
      padding:10px 10px 16px;
      border-bottom:1px solid rgba(255,255,255,.12);
    }

    .logo{
      width:40px;
      height:40px;
      border-radius:12px;
      background:#1d4ed8;
      display:grid;
      place-items:center;
      font-weight:800;
    }

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

    .nav{
      margin-top:12px;
      display:flex;
      flex-direction:column;
      gap:6px;
    }

    .nav a{
      color:#fff;
      text-decoration:none;
      padding:10px 12px;
      border-radius:12px;
      display:flex;
      gap:10px;
      align-items:center;
    }

    .nav a:hover{ background:rgba(255,255,255,.08); }

    .nav a.active{
      background:rgba(37,99,235,.25);
      border:1px solid rgba(37,99,235,.35);
    }

    .main{ flex:1; padding:16px; }

    .topbar{
      display:flex;
      justify-content:space-between;
      align-items:flex-start;
      gap:12px;
      flex-wrap:wrap;
    }

    h1{ margin:0; font-size:22px; }

    .muted{ color:var(--muted); font-size:13px; }

    .btn{
      padding:10px 12px;
      border-radius:12px;
      border:1px solid var(--border);
      background:#fff;
      cursor:pointer;
      font-size:14px;
      text-decoration:none;
      color:var(--text);
      display:inline-flex;
      align-items:center;
      gap:6px;
    }

    .btn.primary{
      background:var(--primary);
      color:#fff;
      border-color:transparent;
    }

    .btn.danger{
      color:var(--bad);
      border-color:rgba(220,38,38,.35);
    }

    .btn.small{
      padding:6px 10px;
      border-radius:10px;
      font-size:12px;
    }

    .card{
      background:var(--card);
      border:1px solid var(--border);
      border-radius:16px;
      padding:14px;
      box-shadow:0 8px 20px rgba(0,0,0,.05);
      margin-top:12px;
    }

    .grid2{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:12px;
    }

    .grid4{
      display:grid;
      grid-template-columns:repeat(4,1fr);
      gap:12px;
    }

    .kpi-box{
      background:#fff;
      border:1px solid var(--border);
      border-radius:16px;
      padding:14px;
    }

    .kpi-box .label{
      color:var(--muted);
      font-size:13px;
    }

    .kpi-box .value{
      font-size:26px;
      font-weight:800;
      margin-top:6px;
    }

    table{
      width:100%;
      border-collapse:separate;
      border-spacing:0;
      overflow:hidden;
      border-radius:14px;
      border:1px solid var(--border);
      background:#fff;
    }

    th,td{
      padding:10px 12px;
      font-size:14px;
      border-bottom:1px solid var(--border);
      vertical-align:top;
    }

    th{
      background:#f9fafb;
      text-align:left;
      color:#374151;
      white-space:nowrap;
    }

    tr:last-child td{ border-bottom:0; }

    input,select,textarea{
      width:100%;
      padding:10px 12px;
      border:1px solid var(--border);
      border-radius:12px;
      font-size:14px;
      background:#fff;
    }

    textarea{
      min-height:70px;
      resize:vertical;
    }

    .toast{
      padding:10px 12px;
      border-radius:12px;
      border:1px solid var(--border);
      background:#fff;
      margin-top:10px;
    }

    .toast.ok{
      border-color:rgba(22,163,74,.35);
      background:rgba(22,163,74,.06);
      color:#0c7a32;
    }

    .toast.warn{
      border-color:rgba(245,158,11,.35);
      background:rgba(245,158,11,.06);
      color:#8a5a00;
    }

    .tag{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:4px 10px;
      border-radius:999px;
      font-size:12px;
      border:1px solid var(--border);
      background:#fff;
      font-weight:700;
    }

    .tag.ok{
      border-color:rgba(22,163,74,.35);
      background:rgba(22,163,74,.08);
      color:var(--ok);
    }

    .tag.bad{
      border-color:rgba(220,38,38,.35);
      background:rgba(220,38,38,.08);
      color:var(--bad);
    }

    .tag.warn{
      border-color:rgba(245,158,11,.35);
      background:rgba(245,158,11,.08);
      color:#8a5a00;
    }

    .toolbar{
      display:grid;
      grid-template-columns:1fr 180px 180px 140px;
      gap:10px;
      margin-top:10px;
    }

    .table-wrap{
      overflow:auto;
      width:100%;
    }

    .summary-cards{
      display:grid;
      grid-template-columns:repeat(3,1fr);
      gap:12px;
    }

    .summary-card{
      border:1px solid var(--border);
      border-radius:14px;
      background:#fff;
      padding:12px;
    }

    .summary-card h4{
      margin:0 0 8px;
      font-size:15px;
    }

    .summary-card .mini{
      font-size:12px;
      color:var(--muted);
      margin-top:6px;
    }

    @media (max-width:1180px){
      .grid4{ grid-template-columns:repeat(2,1fr); }
      .toolbar{ grid-template-columns:1fr 1fr; }
      .summary-cards{ grid-template-columns:1fr; }
    }

    @media (max-width:980px){
      .sidebar{ display:none; }
      .grid2{ grid-template-columns:1fr; }
    }

    @media (max-width:640px){
      .grid4{ grid-template-columns:1fr; }
      .toolbar{ grid-template-columns:1fr; }
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
        <div class="admin-user-name">👤 <?= h($admin_name) ?></div>
        <div class="admin-user-role">สิทธิ์: Admin</div>
      </div>

      <nav class="nav">
        <a class="<?= $current_page === 'admin-dashboard.php' ? 'active' : '' ?>" href="admin-dashboard.php">📊 แดชบอร์ด</a>
        <a class="<?= $current_page === 'admin-domes-locks.php' ? 'active' : '' ?>" href="admin-domes-locks.php">🧩 โดม/ล็อก</a>
        <a class="<?= $current_page === 'admin-shops.php' ? 'active' : '' ?>" href="admin-shops.php">🏪 จัดการร้านค้า</a>
        <a class="<?= $current_page === 'admin-shop-categories.php' ? 'active' : '' ?>" href="admin-shop-categories.php">📁 หมวดหมู่ร้าน</a>
        <a class="<?= $current_page === 'admin-shop-types.php' ? 'active' : '' ?>" href="admin-shop-types.php">🧩 ประเภทร้าน</a>
        <a class="<?= $current_page === 'admin-history.php' ? 'active' : '' ?>" href="admin-history.php">🧾 ประวัติคิว</a>
        <a class="<?= $current_page === 'admin-reports.php' ? 'active' : '' ?>" href="admin-reports.php">📈 รายงาน</a>
        <a href="admin-shop-accounts.php">🔐 บัญชีร้านค้า</a>
        <a href="../logout.php">🚪 ออกจากระบบ</a>
        <a href="../Frontend/index.php">↩ กลับหน้าเว็บ</a>
      </nav>

      <div style="margin-top:14px;">
        <div class="muted" style="color:rgba(255,255,255,.75);">
          * จัดการโครงสร้างพื้นที่ของตลาด และตรวจการผูกล็อกกับร้าน
        </div>
      </div>
    </aside>

    <main class="main">
      <div class="topbar">
        <div>
          <h1>🧩 จัดการโดม/ล็อก</h1>
          <div class="muted">เพิ่ม/แก้ไข/ลบ โดม และ ล็อก • ล็อกที่ถูกผูกกับร้านจะไม่ให้ลบ</div>

          <?php if($msg): ?>
            <div class="toast <?= in_array($msg, ["dome_added","dome_updated","dome_deleted","lock_added","lock_updated","lock_deleted"], true) ? "ok" : "warn" ?>">
              <?php
                $map = [
                  "dome_added"=>"เพิ่มโดมแล้ว ✅",
                  "dome_updated"=>"แก้ไขโดมแล้ว ✅",
                  "dome_deleted"=>"ลบโดมแล้ว ✅",
                  "lock_added"=>"เพิ่มล็อกแล้ว ✅",
                  "lock_updated"=>"แก้ไขล็อกแล้ว ✅",
                  "lock_deleted"=>"ลบล็อกแล้ว ✅",
                  "dome_has_locks"=>"ลบไม่ได้: โดมนี้ยังมีล็อกอยู่",
                  "lock_in_use"=>"ลบไม่ได้: ล็อกนี้ถูกผูกกับร้านอยู่",
                  "lock_dup"=>"เพิ่ม/แก้ไขไม่ได้: เลขล็อกซ้ำในโดมเดียวกัน",
                  "bad_lock"=>"ข้อมูลล็อกไม่ครบ",
                  "bad_dome"=>"ข้อมูลโดมไม่ครบ",
                  "empty_dome"=>"กรอกชื่อโดมก่อน",
                ];
                echo h($map[$msg] ?? $msg);
              ?>
            </div>
          <?php endif; ?>
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <a class="btn" href="admin-shops.php">🏪 ไปจัดการร้าน</a>
        </div>
      </div>

      <!-- KPI -->
      <section class="card">
        <div class="grid4">
          <div class="kpi-box">
            <div class="label">จำนวนโดมทั้งหมด</div>
            <div class="value"><?= $totalDomes ?></div>
          </div>
          <div class="kpi-box">
            <div class="label">จำนวนล็อกทั้งหมด</div>
            <div class="value"><?= $totalLocks ?></div>
          </div>
          <div class="kpi-box">
            <div class="label">ล็อกที่ผูกร้านแล้ว</div>
            <div class="value"><?= $totalLinkedLocks ?></div>
          </div>
          <div class="kpi-box">
            <div class="label">ล็อกว่าง</div>
            <div class="value"><?= $totalUnlinkedLocks ?></div>
          </div>
        </div>

        <?php if($lockStatusCol): ?>
          <div style="margin-top:12px;" class="muted">
            ล็อก inactive: <b><?= $totalInactiveLocks ?></b>
          </div>
        <?php endif; ?>
      </section>

      <!-- Summary by dome -->
      <section class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
          <h3 style="margin:0;">สรุปตามโดม</h3>
          <div class="muted">ใช้ดูความพร้อมของพื้นที่แต่ละโดม</div>
        </div>

        <div class="summary-cards">
          <?php if(!$domeSummary): ?>
            <div class="muted">ยังไม่มีข้อมูลโดม</div>
          <?php else: ?>
            <?php foreach($domeSummary as $d): ?>
              <?php
                $remain = max(0, (int)$d["total_locks"] - (int)$d["total_linked"]);
              ?>
              <div class="summary-card">
                <h4><?= h($d["dome_name"]) ?></h4>
                <div>จำนวนล็อกทั้งหมด: <b><?= (int)$d["total_locks"] ?></b></div>
                <div>ผูกร้านแล้ว: <b><?= (int)$d["total_linked"] ?></b></div>
                <div>ว่างอยู่: <b><?= $remain ?></b></div>
                <div class="mini">dome_id: <?= (int)$d["dome_id"] ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </section>

      <div class="grid2">
        <!-- ===== DOMES ===== -->
        <section class="card">
          <h3 style="margin:0 0 10px;">โดม</h3>

          <form method="post" style="display:grid; gap:10px; grid-template-columns:1fr 1fr;">
            <input type="hidden" name="action" value="add_dome">

            <div style="grid-column:1/-1;">
              <div class="muted">ชื่อโดม</div>
              <input name="dome_name" placeholder="เช่น โดม 1 / โดม 2" />
            </div>

            <div style="grid-column:1/-1;">
              <div class="muted">รายละเอียด (ถ้ามี)</div>
              <textarea name="dome_desc" placeholder="คำอธิบายเพิ่มเติม..."></textarea>
            </div>

            <div style="grid-column:1/-1; display:flex; justify-content:flex-end;">
              <button class="btn primary" type="submit">+ เพิ่มโดม</button>
            </div>
          </form>

          <div style="margin-top:12px;">
            <table>
              <thead>
                <tr>
                  <th style="width:80px;">ID</th>
                  <th>ชื่อโดม</th>
                  <th style="width:120px;">จำนวนล็อก</th>
                  <th style="width:240px;">จัดการ</th>
                </tr>
              </thead>
              <tbody>
                <?php if(!$domes): ?>
                  <tr><td colspan="4" class="muted">ยังไม่มีข้อมูลโดม</td></tr>
                <?php else: ?>
                  <?php foreach($domes as $d): ?>
                    <tr>
                      <td><?= (int)$d["dome_id"] ?></td>
                      <td>
                        <div style="font-weight:800;"><?= h($d["dome_name"]) ?></div>
                        <?php if(trim((string)$d["dome_desc"]) !== ""): ?>
                          <div class="muted"><?= h($d["dome_desc"]) ?></div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="tag ok">🔢 <?= (int)($lockCounts[(int)$d["dome_id"]] ?? 0) ?> ล็อก</span>
                      </td>
                      <td>
                        <details>
                          <summary class="btn small">✏️ แก้ไข</summary>
                          <form method="post" style="margin-top:8px; display:grid; gap:8px;">
                            <input type="hidden" name="action" value="edit_dome">
                            <input type="hidden" name="dome_id" value="<?= (int)$d["dome_id"] ?>">
                            <input name="dome_name" value="<?= h($d["dome_name"]) ?>" />
                            <?php if($domeDescCol): ?>
                              <textarea name="dome_desc"><?= h($d["dome_desc"]) ?></textarea>
                            <?php endif; ?>
                            <button class="btn primary" type="submit">บันทึก</button>
                          </form>
                        </details>

                        <form method="post" style="display:inline;" onsubmit="return confirm('ลบโดมนี้? (ต้องไม่มีล็อกอยู่ในโดม)')">
                          <input type="hidden" name="action" value="delete_dome">
                          <input type="hidden" name="dome_id" value="<?= (int)$d["dome_id"] ?>">
                          <button class="btn small danger" type="submit">🗑 ลบ</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>

        <!-- ===== LOCKS ===== -->
        <section class="card">
          <h3 style="margin:0 0 10px;">ล็อก</h3>

          <form method="post" style="display:grid; gap:10px; grid-template-columns:1fr 1fr;">
            <input type="hidden" name="action" value="add_lock">

            <div>
              <div class="muted">เลือกโดม</div>
              <select name="lock_dome_id" required>
                <option value="">-- เลือกโดม --</option>
                <?php foreach($domes as $d): ?>
                  <option value="<?= (int)$d["dome_id"] ?>"><?= h($d["dome_name"]) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <div class="muted">เลขล็อก</div>
              <input name="lock_no" type="number" min="1" placeholder="เช่น 1" required />
            </div>

            <?php if($lockStatusCol): ?>
              <div style="grid-column:1/-1;">
                <div class="muted">สถานะล็อก (ถ้ามีคอลัมน์)</div>
                <select name="lock_status">
                  <option value="active">active</option>
                  <option value="inactive">inactive</option>
                </select>
              </div>
            <?php endif; ?>

            <div style="grid-column:1/-1; display:flex; justify-content:flex-end;">
              <button class="btn primary" type="submit">+ เพิ่มล็อก</button>
            </div>
          </form>
        </section>
      </div>

      <!-- Filter -->
      <section class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
          <h3 style="margin:0;">ค้นหา / กรองข้อมูลล็อก</h3>
          <div class="muted">ช่วยให้แอดมินค้นหาเลขล็อกหรือดูเฉพาะล็อกว่างได้เร็วขึ้น</div>
        </div>

        <form method="get" class="toolbar">
          <input type="text" name="q" placeholder="ค้นหาเลขล็อก / ชื่อโดม / ชื่อร้าน" value="<?= h($q) ?>">

          <select name="filter_dome">
            <option value="0">-- ทุกโดม --</option>
            <?php foreach($domes as $d): ?>
              <option value="<?= (int)$d["dome_id"] ?>" <?= $filter_dome === (int)$d["dome_id"] ? "selected" : "" ?>>
                <?= h($d["dome_name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select name="filter_linked">
            <option value="">-- ทุกสถานะการผูก --</option>
            <option value="linked" <?= $filter_linked === "linked" ? "selected" : "" ?>>ผูกร้านแล้ว</option>
            <option value="unlinked" <?= $filter_linked === "unlinked" ? "selected" : "" ?>>ยังไม่ผูกร้าน</option>
          </select>

          <div style="display:flex; gap:8px;">
            <button class="btn primary" type="submit">กรอง</button>
            <a class="btn" href="admin-domes-locks.php">ล้าง</a>
          </div>
        </form>
      </section>

      <!-- Lock table -->
      <section class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
          <h3 style="margin:0;">รายการล็อกทั้งหมด</h3>
          <div class="muted">แสดงความสัมพันธ์ระหว่างโดม ล็อก และร้าน</div>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th style="width:80px;">lock_id</th>
                <th>โดม</th>
                <th style="width:80px;">ล็อก</th>
                <th style="width:130px;">สถานะล็อก</th>
                <th>ร้านที่ผูกอยู่</th>
                <th style="width:300px;">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php if(!$locks): ?>
                <tr><td colspan="6" class="muted">ไม่พบข้อมูลตามเงื่อนไขที่ค้นหา</td></tr>
              <?php else: ?>
                <?php foreach($locks as $l): ?>
                  <?php
                    $lockStatusText = lockStatusText($l["lock_status"]);
                    $isActiveLock = true;
                    if ($lockStatusCol) {
                      $isActiveLock = !in_array(strtolower($lockStatusText), ["inactive","0","disabled","off"], true);
                    }
                  ?>
                  <tr>
                    <td><?= (int)$l["lock_id"] ?></td>
                    <td>
                      <div style="font-weight:700;"><?= h($l["dome_name"] ?: ("โดม ".$l["dome_id"])) ?></div>
                      <div class="muted">dome_id: <?= (int)$l["dome_id"] ?></div>
                    </td>
                    <td><?= (int)$l["lock_no"] ?></td>
                    <td>
                      <?php if($lockStatusCol): ?>
                        <span class="tag <?= $isActiveLock ? "ok" : "warn" ?>">
                          <?= h($lockStatusText) ?>
                        </span>
                      <?php else: ?>
                        <span class="muted">-</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if(!empty($l["shop_id"])): ?>
                        <span class="tag ok">🏪 <?= h($l["shop_name"]) ?></span>
                        <div class="muted">shop_id: <?= (int)$l["shop_id"] ?></div>
                        <div style="margin-top:6px;">
                          <a class="btn small" href="admin-shops.php">ไปหน้าจัดการร้าน</a>
                        </div>
                      <?php else: ?>
                        <span class="tag bad">ยังไม่ผูกร้าน</span>
                        <div style="margin-top:6px;">
                          <a class="btn small" href="admin-shops.php">ไปผูกร้าน</a>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <details>
                        <summary class="btn small">✏️ แก้ไข</summary>
                        <form method="post" style="margin-top:8px; display:grid; gap:8px;">
                          <input type="hidden" name="action" value="edit_lock">
                          <input type="hidden" name="lock_id" value="<?= (int)$l["lock_id"] ?>">

                          <select name="lock_dome_id" required>
                            <?php foreach($domes as $d): ?>
                              <option value="<?= (int)$d["dome_id"] ?>" <?= ((int)$d["dome_id"] === (int)$l["dome_id"]) ? "selected" : "" ?>>
                                <?= h($d["dome_name"]) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>

                          <input name="lock_no" type="number" min="1" value="<?= (int)$l["lock_no"] ?>" required />

                          <?php if($lockStatusCol): ?>
                            <select name="lock_status">
                              <option value="active" <?= strtolower($lockStatusText) === "active" || $lockStatusText === "1" ? "selected" : "" ?>>active</option>
                              <option value="inactive" <?= strtolower($lockStatusText) === "inactive" || $lockStatusText === "0" ? "selected" : "" ?>>inactive</option>
                            </select>
                          <?php endif; ?>

                          <button class="btn primary" type="submit">บันทึก</button>
                        </form>
                      </details>

                      <form method="post" style="display:inline;" onsubmit="return confirm('ลบล็อกนี้? (ถ้าผูกร้านอยู่จะลบไม่ได้)')">
                        <input type="hidden" name="action" value="delete_lock">
                        <input type="hidden" name="lock_id" value="<?= (int)$l["lock_id"] ?>">
                        <button class="btn small danger" type="submit">🗑 ลบ</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="muted" style="margin-top:10px;">
          * หมายเหตุ: การผูกล็อกกับร้านทำผ่านหน้า Admin Shops โดยกำหนดค่า lock_id ให้ร้าน
        </div>
      </section>
    </main>
  </div>
</body>
</html>