<?php
require_once __DIR__ . "/_auth.php";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

$current_page = basename($_SERVER['PHP_SELF']);

function statusLabelTH($status){
  return match($status){
    "open"   => "🟢 เปิด",
    "closed" => "🔴 ปิด",
    "break"  => "🟡 พัก",
    "full"   => "🟠 คิวเต็ม",
    default  => $status ?: "-"
  };
}
function statusBadgeClass($status){
  return match($status){
    "open"   => "b-open",
    "closed" => "b-closed",
    "break"  => "b-break",
    "full"   => "b-full",
    default  => ""
  };
}

/* =========================
   detect optional columns
========================= */
function hasColumn(PDO $pdo, string $table, string $column): bool {
  $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
  $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
  return in_array($column, $cols, true);
}

$hasIsActive = hasColumn($pdo, 'shops', 'is_active');
$hasEtaPerQueue = hasColumn($pdo, 'shops', 'eta_per_queue_min');

/* =========================
   POST Actions
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = $_POST["action"] ?? "";

  if ($action === "toggle" && isset($_POST["shop_id"])) {
    $shop_id = (int)$_POST["shop_id"];

    $stmt = $pdo->prepare("SELECT status FROM shops WHERE shop_id=? LIMIT 1");
    $stmt->execute([$shop_id]);
    $cur = (string)$stmt->fetchColumn();

    $new = ($cur === "open") ? "closed" : "open";
    $stmt = $pdo->prepare("UPDATE shops SET status=? WHERE shop_id=?");
    $stmt->execute([$new, $shop_id]);

    header("Location: admin-shops.php?ok=status_updated");
    exit;
  }

  if ($action === "soft_delete" && isset($_POST["shop_id"])) {
    $shop_id = (int)$_POST["shop_id"];

    if ($hasIsActive) {
      $stmt = $pdo->prepare("UPDATE shops SET is_active=0, status='closed' WHERE shop_id=?");
      $stmt->execute([$shop_id]);
    } else {
      $stmt = $pdo->prepare("UPDATE shops SET status='closed' WHERE shop_id=?");
      $stmt->execute([$shop_id]);
    }

    header("Location: admin-shops.php?ok=hidden");
    exit;
  }

  if ($action === "restore" && isset($_POST["shop_id"])) {
    $shop_id = (int)$_POST["shop_id"];

    if ($hasIsActive) {
      $stmt = $pdo->prepare("UPDATE shops SET is_active=1 WHERE shop_id=?");
      $stmt->execute([$shop_id]);
    }

    header("Location: admin-shops.php?ok=restored");
    exit;
  }

  if ($action === "save" && isset($_POST["mode"])) {
    $mode = $_POST["mode"];
    $shop_id = (int)($_POST["shop_id"] ?? 0);

    $name = trim($_POST["name"] ?? "");
    $status = trim($_POST["status"] ?? "closed");
    $open_time = trim($_POST["open_time"] ?? "");
    $close_time = trim($_POST["close_time"] ?? "");
    $queue_limit = (int)($_POST["queue_limit"] ?? 0);
    $lock_id = (int)($_POST["lock_id"] ?? 0);
    $type_id = (int)($_POST["type_id"] ?? 0);
    $eta_per_queue_min = (int)($_POST["eta_per_queue_min"] ?? 0);

    if ($name === "") {
      header("Location: admin-shops.php?err=missing_name");
      exit;
    }

    $allowStatus = ["open","closed","break","full"];
    if (!in_array($status, $allowStatus, true)) {
      $status = "closed";
    }

    $lock_id_sql = ($lock_id > 0) ? $lock_id : null;
    $type_id_sql = ($type_id > 0) ? $type_id : null;
    $eta_sql = ($eta_per_queue_min > 0) ? $eta_per_queue_min : null;

    if ($open_time !== "" && $close_time !== "" && $open_time >= $close_time) {
      header("Location: admin-shops.php?err=bad_time");
      exit;
    }

    if ($queue_limit < 0) {
      header("Location: admin-shops.php?err=bad_limit");
      exit;
    }

    if ($lock_id_sql !== null) {
      if ($mode === "add") {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM shops WHERE lock_id = ?" . ($hasIsActive ? " AND is_active = 1" : ""));
        $stmt->execute([$lock_id_sql]);
      } else {
        $sqlCheck = "SELECT COUNT(*) FROM shops WHERE lock_id = ? AND shop_id <> ?";
        if ($hasIsActive) {
          $sqlCheck .= " AND is_active = 1";
        }
        $stmt = $pdo->prepare($sqlCheck);
        $stmt->execute([$lock_id_sql, $shop_id]);
      }

      if ((int)$stmt->fetchColumn() > 0) {
        header("Location: admin-shops.php?err=lock_used");
        exit;
      }
    }

    if ($mode === "add") {
      if ($hasIsActive && $hasEtaPerQueue) {
        $stmt = $pdo->prepare("
          INSERT INTO shops (name, status, open_time, close_time, queue_limit, lock_id, type_id, eta_per_queue_min, is_active)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
          $name,
          $status,
          $open_time ?: null,
          $close_time ?: null,
          $queue_limit ?: null,
          $lock_id_sql,
          $type_id_sql,
          $eta_sql
        ]);
      } elseif ($hasIsActive) {
        $stmt = $pdo->prepare("
          INSERT INTO shops (name, status, open_time, close_time, queue_limit, lock_id, type_id, is_active)
          VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
          $name,
          $status,
          $open_time ?: null,
          $close_time ?: null,
          $queue_limit ?: null,
          $lock_id_sql,
          $type_id_sql
        ]);
      } elseif ($hasEtaPerQueue) {
        $stmt = $pdo->prepare("
          INSERT INTO shops (name, status, open_time, close_time, queue_limit, lock_id, type_id, eta_per_queue_min)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
          $name,
          $status,
          $open_time ?: null,
          $close_time ?: null,
          $queue_limit ?: null,
          $lock_id_sql,
          $type_id_sql,
          $eta_sql
        ]);
      } else {
        $stmt = $pdo->prepare("
          INSERT INTO shops (name, status, open_time, close_time, queue_limit, lock_id, type_id)
          VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
          $name,
          $status,
          $open_time ?: null,
          $close_time ?: null,
          $queue_limit ?: null,
          $lock_id_sql,
          $type_id_sql
        ]);
      }

      header("Location: admin-shops.php?ok=added");
      exit;
    }

    if ($mode === "edit" && $shop_id > 0) {
      if ($hasEtaPerQueue) {
        $stmt = $pdo->prepare("
          UPDATE shops
          SET name=?, status=?, open_time=?, close_time=?, queue_limit=?, lock_id=?, type_id=?, eta_per_queue_min=?
          WHERE shop_id=?
        ");
        $stmt->execute([
          $name,
          $status,
          $open_time ?: null,
          $close_time ?: null,
          $queue_limit ?: null,
          $lock_id_sql,
          $type_id_sql,
          $eta_sql,
          $shop_id
        ]);
      } else {
        $stmt = $pdo->prepare("
          UPDATE shops
          SET name=?, status=?, open_time=?, close_time=?, queue_limit=?, lock_id=?, type_id=?
          WHERE shop_id=?
        ");
        $stmt->execute([
          $name,
          $status,
          $open_time ?: null,
          $close_time ?: null,
          $queue_limit ?: null,
          $lock_id_sql,
          $type_id_sql,
          $shop_id
        ]);
      }

      header("Location: admin-shops.php?ok=updated&shop_id=" . $shop_id);
      exit;
    }
  }
}

/* =========================
   Filters
========================= */
$smart_type_id = (int)($_GET["type_id"] ?? 0);
$smart_category_id = (int)($_GET["category_id"] ?? ($_GET["cat"] ?? 0));
$smart_dome_id = 0;
if (isset($_GET["filter_dome"]) && (int)$_GET["filter_dome"] > 0) {
  $smart_dome_id = (int)$_GET["filter_dome"];
} elseif (isset($_GET["dome_id"]) && (int)$_GET["dome_id"] > 0) {
  $smart_dome_id = (int)$_GET["dome_id"];
}

$q = trim((string)($_GET["q"] ?? ""));
$filter_shop_id = (int)($_GET["shop_id"] ?? 0);
$filter_dome = (int)($_GET["filter_dome"] ?? ($smart_dome_id > 0 ? $smart_dome_id : 0));
$filter_status = trim((string)($_GET["filter_status"] ?? ""));
$filter_type = (int)($_GET["filter_type"] ?? ($smart_type_id > 0 ? $smart_type_id : 0));
$filter_category = (int)($_GET["filter_category"] ?? ($smart_category_id > 0 ? $smart_category_id : 0));
$filter_incomplete = trim((string)($_GET["filter_incomplete"] ?? ""));
$show_hidden = (int)($_GET["show_hidden"] ?? 0);

/* =========================
   Load data
========================= */
$sqlLocks = "
  SELECT l.lock_id, l.dome_id, l.lock_no, s.shop_id AS used_shop_id
  FROM locks l
  LEFT JOIN shops s ON s.lock_id = l.lock_id
";
if ($hasIsActive) {
  $sqlLocks .= " AND s.is_active = 1 ";
}
$sqlLocks .= " ORDER BY l.dome_id ASC, l.lock_no ASC";
$locks = $pdo->query($sqlLocks)->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("
  SELECT category_id, category_name
  FROM shop_categories
  ORDER BY category_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$types = $pdo->query("
  SELECT t.type_id, t.type_name, c.category_name, c.category_id
  FROM shop_types t
  LEFT JOIN shop_categories c ON c.category_id = t.category_id
  ORDER BY c.category_name ASC, t.type_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$domes = $pdo->query("
  SELECT DISTINCT d.dome_id, d.dome_name
  FROM domes d
  ORDER BY d.dome_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$activeTypeName = "";
$activeDomeName = "";
$activeCategoryName = "";
$activeShopName = "";

if ($filter_type > 0) {
  $stmt = $pdo->prepare("SELECT type_name FROM shop_types WHERE type_id = ? LIMIT 1");
  $stmt->execute([$filter_type]);
  $activeTypeName = (string)($stmt->fetchColumn() ?: "");
}
if ($filter_dome > 0) {
  $stmt = $pdo->prepare("SELECT dome_name FROM domes WHERE dome_id = ? LIMIT 1");
  $stmt->execute([$filter_dome]);
  $activeDomeName = (string)($stmt->fetchColumn() ?: "");
}
if ($filter_category > 0) {
  $stmt = $pdo->prepare("SELECT category_name FROM shop_categories WHERE category_id = ? LIMIT 1");
  $stmt->execute([$filter_category]);
  $activeCategoryName = (string)($stmt->fetchColumn() ?: "");
}
if ($filter_shop_id > 0) {
  $stmt = $pdo->prepare("SELECT name FROM shops WHERE shop_id = ? LIMIT 1");
  $stmt->execute([$filter_shop_id]);
  $activeShopName = (string)($stmt->fetchColumn() ?: "");
}

$shops_total = (int)$pdo->query("SELECT COUNT(*) FROM shops" . ($hasIsActive ? " WHERE is_active = 1" : ""))->fetchColumn();
$shops_open = (int)$pdo->query("SELECT COUNT(*) FROM shops WHERE status='open'" . ($hasIsActive ? " AND is_active = 1" : ""))->fetchColumn();
$shops_closed = (int)$pdo->query("SELECT COUNT(*) FROM shops WHERE status='closed'" . ($hasIsActive ? " AND is_active = 1" : ""))->fetchColumn();
$shops_break = (int)$pdo->query("SELECT COUNT(*) FROM shops WHERE status='break'" . ($hasIsActive ? " AND is_active = 1" : ""))->fetchColumn();
$shops_full = (int)$pdo->query("SELECT COUNT(*) FROM shops WHERE status='full'" . ($hasIsActive ? " AND is_active = 1" : ""))->fetchColumn();
$shops_no_lock = (int)$pdo->query("SELECT COUNT(*) FROM shops WHERE lock_id IS NULL" . ($hasIsActive ? " AND is_active = 1" : ""))->fetchColumn();
$shops_no_type = (int)$pdo->query("SELECT COUNT(*) FROM shops WHERE type_id IS NULL" . ($hasIsActive ? " AND is_active = 1" : ""))->fetchColumn();

$where = [];
$params = [];

if ($hasIsActive && !$show_hidden) {
  $where[] = "s.is_active = 1";
}

if ($filter_shop_id > 0) {
  $where[] = "s.shop_id = ?";
  $params[] = $filter_shop_id;
}

if ($q !== "") {
  $where[] = "(s.name LIKE ? OR t.type_name LIKE ? OR c.category_name LIKE ?)";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
}

if ($filter_dome > 0) {
  $where[] = "l.dome_id = ?";
  $params[] = $filter_dome;
}

if ($filter_status !== "") {
  $where[] = "s.status = ?";
  $params[] = $filter_status;
}

if ($filter_type > 0) {
  $where[] = "s.type_id = ?";
  $params[] = $filter_type;
}

if ($filter_category > 0) {
  $where[] = "c.category_id = ?";
  $params[] = $filter_category;
}

if ($filter_incomplete === "yes") {
  $where[] = "(s.lock_id IS NULL OR s.type_id IS NULL)";
}

$sql = "
  SELECT
    s.shop_id, s.name, s.status, s.open_time, s.close_time, s.queue_limit, s.lock_id, s.type_id
    ".($hasEtaPerQueue ? ", s.eta_per_queue_min" : ", NULL AS eta_per_queue_min")."
    ".($hasIsActive ? ", s.is_active" : ", 1 AS is_active").",
    l.dome_id, l.lock_no,
    t.type_name, c.category_name, c.category_id
  FROM shops s
  LEFT JOIN locks l ON l.lock_id = s.lock_id
  LEFT JOIN shop_types t ON t.type_id = s.type_id
  LEFT JOIN shop_categories c ON c.category_id = t.category_id
";

if ($where) {
  $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY l.dome_id ASC, l.lock_no ASC, s.shop_id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$shops = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ok = $_GET["ok"] ?? "";
$err = $_GET["err"] ?? "";
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Shops</title>
  <style>
    :root{
      --bg:#f6f7fb;
      --card:#fff;
      --text:#111827;
      --muted:#6b7280;
      --primary:#2563eb;
      --border:#e5e7eb;
      --ok:#16a34a;
      --warn:#f59e0b;
      --bad:#dc2626;
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
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:6px;
      font-size:14px;
      line-height:1.2;
      white-space:nowrap;
      color:var(--text);
      text-decoration:none;
      transition:all .15s ease;
    }
    .btn,
    .btn:link,
    .btn:visited,
    .btn:hover,
    .btn:active{
      text-decoration:none;
    }
    .btn,
    .btn:link,
    .btn:visited{
      color:var(--text);
    }
    .btn:hover{
      background:#f9fafb;
      border-color:#d1d5db;
      color:var(--text);
    }
    .btn:active{
      background:#f3f4f6;
      color:var(--text);
    }
    .btn.primary{
      background:var(--primary);
      color:#fff !important;
      border-color:transparent;
    }
    .btn.primary:link,
    .btn.primary:visited,
    .btn.primary:hover,
    .btn.primary:active{
      color:#fff !important;
      text-decoration:none;
    }
    .btn.primary:hover{
      filter:brightness(.97);
    }
    .btn.danger{
      background:#fff;
      color:var(--bad) !important;
      border-color:rgba(220,38,38,.35);
    }
    .btn.danger:link,
    .btn.danger:visited,
    .btn.danger:hover,
    .btn.danger:active{
      color:var(--bad) !important;
      text-decoration:none;
    }
    .btn.small{
      padding:6px 10px;
      font-size:12px;
      border-radius:10px;
    }

    .card{
      background:var(--card);
      border:1px solid var(--border);
      border-radius:16px;
      padding:14px;
      box-shadow:0 8px 20px rgba(0,0,0,.05);
      margin-top:12px;
    }

    .kpi{
      display:grid;
      grid-template-columns:repeat(4,1fr);
      gap:12px;
    }
    .kpi .box{
      background:#fff;
      border:1px solid var(--border);
      border-radius:16px;
      padding:14px;
    }
    .kpi .label{
      color:var(--muted);
      font-size:13px;
    }
    .kpi .value{
      font-size:26px;
      font-weight:800;
      margin-top:6px;
    }

    .grid2{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:10px;
    }

    .toolbar{
      display:grid;
      grid-template-columns:1.2fr 180px 180px 200px 200px auto auto;
      gap:10px;
      align-items:end;
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
      text-align:left;
    }
    th{
      background:#f9fafb;
      color:#374151;
      white-space:nowrap;
    }
    tr:last-child td{ border-bottom:0; }

    .badge{
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
    .b-open{ border-color:rgba(22,163,74,.35); background:rgba(22,163,74,.08); color:var(--ok); }
    .b-closed{ border-color:rgba(220,38,38,.35); background:rgba(220,38,38,.08); color:var(--bad); }
    .b-break{ border-color:rgba(245,158,11,.35); background:rgba(245,158,11,.08); color:#b45309; }
    .b-full{ border-color:rgba(245,158,11,.35); background:rgba(245,158,11,.08); color:#b45309; }

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
    .tag.warn{
      border-color:rgba(245,158,11,.35);
      background:rgba(245,158,11,.08);
      color:#8a5a00;
    }
    .tag.bad{
      border-color:rgba(220,38,38,.35);
      background:rgba(220,38,38,.08);
      color:var(--bad);
    }

    input, select, textarea{
      width:100%;
      padding:10px 12px;
      border:1px solid var(--border);
      border-radius:12px;
      font-size:14px;
      outline:none;
      background:#fff;
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
    .toast.err{
      border-color:rgba(220,38,38,.35);
      background:rgba(220,38,38,.06);
      color:#b40000;
    }

    .modal{
      position:fixed;
      inset:0;
      background:rgba(0,0,0,.35);
      display:none;
      align-items:center;
      justify-content:center;
      padding:16px;
      z-index:1000;
    }
    .modal.show{
      display:flex;
    }
    .modal .box{
      width:min(760px, 100%);
      background:#fff;
      border-radius:16px;
      border:1px solid var(--border);
      padding:14px;
      box-shadow:0 20px 60px rgba(0,0,0,.2);
      max-height:90vh;
      overflow:auto;
    }
    .modal .head{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:10px;
    }
    .modal .foot{
      display:flex;
      gap:10px;
      justify-content:flex-end;
      margin-top:12px;
      flex-wrap:wrap;
    }

    .table-wrap{
      overflow:auto;
      width:100%;
    }

    tr.selected-row td{
      background:rgba(37,99,235,.06);
    }

    @media (max-width:1180px){
      .kpi{ grid-template-columns:repeat(2,1fr); }
      .toolbar{ grid-template-columns:1fr 1fr; }
    }
    @media (max-width:980px){
      .sidebar{ display:none; }
      .grid2{ grid-template-columns:1fr; }
    }
    @media (max-width:640px){
      .kpi{ grid-template-columns:1fr; }
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
        <a class="<?= $current_page === 'admin-shop-accounts.php' ? 'active' : '' ?>" href="admin-shop-accounts.php">🔐 บัญชีร้านค้า</a>
        <a href="../logout.php">🚪 ออกจากระบบ</a>
        <a href="../Frontend/index.php">↩ กลับหน้าเว็บ</a>
      </nav>
    </aside>

    <main class="main">
      <div class="topbar">
        <div>
          <h1>🏪 จัดการร้านค้า</h1>
          <div class="muted">เพิ่ม/แก้ไข/ปรับสถานะร้าน + กำหนดประเภท + ตรวจความครบถ้วนของข้อมูลร้าน</div>

          <?php if($filter_shop_id > 0 && $activeShopName !== ""): ?>
            <div style="margin-top:8px;">
              <span class="tag ok">กำลังดูร้าน: <?= h($activeShopName) ?> (ID <?= (int)$filter_shop_id ?>)</span>
            </div>
          <?php endif; ?>

          <?php if($filter_type > 0 || $filter_dome > 0 || $filter_category > 0): ?>
            <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
              <span class="muted">กำลังกรอง:</span>
              <?php if($filter_category > 0 && $activeCategoryName !== ""): ?>
                <span class="tag ok">หมวด: <?= h($activeCategoryName) ?></span>
              <?php endif; ?>
              <?php if($filter_type > 0 && $activeTypeName !== ""): ?>
                <span class="tag ok">ประเภท: <?= h($activeTypeName) ?></span>
              <?php endif; ?>
              <?php if($filter_dome > 0 && $activeDomeName !== ""): ?>
                <span class="tag ok">โดม: <?= h($activeDomeName) ?></span>
              <?php endif; ?>
              <a class="btn small" href="admin-shops.php">ล้างตัวกรอง</a>
            </div>
          <?php endif; ?>

          <?php if($ok === "added"): ?>
            <div class="toast ok">บันทึกร้านใหม่แล้ว ✅</div>
          <?php elseif($ok === "updated"): ?>
            <div class="toast ok">อัปเดตร้านแล้ว ✅</div>
          <?php elseif($ok === "status_updated"): ?>
            <div class="toast ok">เปลี่ยนสถานะร้านแล้ว ✅</div>
          <?php elseif($ok === "hidden"): ?>
            <div class="toast ok">ซ่อนร้านแล้ว ✅</div>
          <?php elseif($ok === "restored"): ?>
            <div class="toast ok">กู้คืนร้านแล้ว ✅</div>
          <?php endif; ?>

          <?php if($err === "missing_name"): ?>
            <div class="toast err">กรอก “ชื่อร้าน” ก่อนนะ</div>
          <?php elseif($err === "bad_time"): ?>
            <div class="toast err">เวลาเปิดต้องน้อยกว่าเวลาปิด</div>
          <?php elseif($err === "bad_limit"): ?>
            <div class="toast err">จำนวนจำกัดคิวต้องไม่ติดลบ</div>
          <?php elseif($err === "lock_used"): ?>
            <div class="toast err">ล็อกนี้ถูกผูกกับร้านอื่นแล้ว</div>
          <?php endif; ?>
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <a class="btn" href="admin-domes-locks.php">🧩 จัดการโดม/ล็อก</a>
          <a class="btn" href="admin-shop-accounts.php<?= $filter_shop_id > 0 ? '?shop_id='.(int)$filter_shop_id : '' ?>">🔐 บัญชีร้านค้า</a>
          <button class="btn" onclick="location.reload()">รีเฟรช</button>
          <button class="btn primary" id="btnAdd" type="button">+ เพิ่มร้าน</button>
        </div>
      </div>

      <section class="card">
        <div class="kpi">
          <div class="box">
            <div class="label">ร้านทั้งหมด</div>
            <div class="value"><?= $shops_total ?></div>
          </div>
          <div class="box">
            <div class="label">ร้านเปิด</div>
            <div class="value"><?= $shops_open ?></div>
          </div>
          <div class="box">
            <div class="label">ร้านปิด</div>
            <div class="value"><?= $shops_closed ?></div>
          </div>
          <div class="box">
            <div class="label">ร้านพัก / คิวเต็ม</div>
            <div class="value"><?= $shops_break + $shops_full ?></div>
          </div>
          <div class="box">
            <div class="label">ยังไม่ผูกล็อก</div>
            <div class="value"><?= $shops_no_lock ?></div>
          </div>
          <div class="box">
            <div class="label">ยังไม่กำหนดประเภท</div>
            <div class="value"><?= $shops_no_type ?></div>
          </div>
        </div>
      </section>

      <section class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
          <h3 style="margin:0;">ค้นหา / กรองร้านค้า</h3>
          <div class="muted">ใช้กรองข้อมูลสำหรับจัดการร้านจำนวนมาก</div>
        </div>

        <form method="get" class="toolbar">
          <input type="text" name="q" placeholder="ค้นหาชื่อร้าน / ประเภท / หมวดหมู่" value="<?= h($q) ?>">

          <select name="filter_category">
            <option value="0">-- ทุกหมวด --</option>
            <?php foreach($categories as $cat): ?>
              <option value="<?= (int)$cat["category_id"] ?>" <?= $filter_category === (int)$cat["category_id"] ? "selected" : "" ?>>
                <?= h($cat["category_name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select name="filter_dome">
            <option value="0">-- ทุกโดม --</option>
            <?php foreach($domes as $d): ?>
              <option value="<?= (int)$d["dome_id"] ?>" <?= $filter_dome === (int)$d["dome_id"] ? "selected" : "" ?>>
                <?= h($d["dome_name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select name="filter_status">
            <option value="">-- ทุกสถานะ --</option>
            <option value="open" <?= $filter_status === "open" ? "selected" : "" ?>>เปิด</option>
            <option value="closed" <?= $filter_status === "closed" ? "selected" : "" ?>>ปิด</option>
            <option value="break" <?= $filter_status === "break" ? "selected" : "" ?>>พัก</option>
            <option value="full" <?= $filter_status === "full" ? "selected" : "" ?>>คิวเต็ม</option>
          </select>

          <select name="filter_type">
            <option value="0">-- ทุกประเภท --</option>
            <?php foreach($types as $t): ?>
              <option value="<?= (int)$t["type_id"] ?>" <?= $filter_type === (int)$t["type_id"] ? "selected" : "" ?>>
                <?= h(($t["category_name"] ? $t["category_name"]." • " : "" ).$t["type_name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select name="filter_incomplete">
            <option value="">-- ทุกความครบถ้วน --</option>
            <option value="yes" <?= $filter_incomplete === "yes" ? "selected" : "" ?>>ข้อมูลยังไม่ครบ</option>
          </select>

          <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <?php if($filter_shop_id > 0): ?>
              <input type="hidden" name="shop_id" value="<?= (int)$filter_shop_id ?>">
            <?php endif; ?>

            <?php if($hasIsActive): ?>
              <label style="display:flex; align-items:center; gap:6px; font-size:13px;">
                <input type="checkbox" name="show_hidden" value="1" <?= $show_hidden ? "checked" : "" ?> style="width:auto;">
                แสดงร้านที่ซ่อน
              </label>
            <?php endif; ?>
            <button class="btn primary" type="submit">กรอง</button>
            <a class="btn" href="admin-shops.php">ล้าง</a>
          </div>
        </form>
      </section>

      <section class="card">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th style="width:90px;">โดม</th>
                <th style="width:90px;">ล็อก</th>
                <th>ร้าน</th>
                <th style="width:140px;">เวลาเปิด-ปิด</th>
                <th style="width:130px;">สถานะ</th>
                <th style="width:460px;">การจัดการ</th>
              </tr>
            </thead>
            <tbody id="shopBody">
              <?php if(!$shops): ?>
                <tr><td colspan="6" class="muted">ไม่พบข้อมูลร้านตามเงื่อนไขที่ค้นหา</td></tr>
              <?php else: ?>
                <?php foreach($shops as $s): ?>
                  <?php
                    $is_incomplete = ((int)$s["lock_id"] <= 0 || (int)$s["type_id"] <= 0);
                    $is_hidden = isset($s["is_active"]) && (int)$s["is_active"] !== 1;
                    $isSelected = $filter_shop_id > 0 && (int)$s["shop_id"] === $filter_shop_id;
                  ?>
                  <tr
                    class="<?= $isSelected ? 'selected-row' : '' ?>"
                    data-shop='<?= h(json_encode([
                      "shop_id" => (int)$s["shop_id"],
                      "name" => $s["name"],
                      "status" => $s["status"],
                      "open_time" => $s["open_time"],
                      "close_time" => $s["close_time"],
                      "queue_limit" => $s["queue_limit"],
                      "lock_id" => $s["lock_id"],
                      "type_id" => $s["type_id"],
                      "eta_per_queue_min" => $s["eta_per_queue_min"],
                    ], JSON_UNESCAPED_UNICODE)) ?>'
                  >
                    <td><?= h($s["dome_id"] ?? "-") ?></td>
                    <td><?= h($s["lock_no"] ?? "-") ?></td>
                    <td>
                      <div style="font-weight:800;"><?= h($s["name"]) ?></div>
                      <div class="muted">
                        shop_id: <?= (int)$s["shop_id"] ?>
                        <?= $s["queue_limit"] !== null ? " • จำกัดคิว: ".(int)$s["queue_limit"] : "" ?>
                        <?= $s["eta_per_queue_min"] !== null ? " • ETA/คิว: ".(int)$s["eta_per_queue_min"]." นาที" : "" ?>
                      </div>
                      <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap;">
                        <?php if($isSelected): ?>
                          <span class="tag ok">ร้านที่เลือกอยู่</span>
                        <?php endif; ?>

                        <?php if($s["type_name"]): ?>
                          <span class="tag ok">ประเภท: <?= h($s["type_name"]) ?></span>
                        <?php else: ?>
                          <span class="tag warn">ยังไม่กำหนดประเภท</span>
                        <?php endif; ?>

                        <?php if($s["category_name"]): ?>
                          <span class="tag ok">หมวด: <?= h($s["category_name"]) ?></span>
                        <?php endif; ?>

                        <?php if((int)$s["lock_id"] <= 0): ?>
                          <span class="tag warn">ยังไม่ผูกล็อก</span>
                        <?php endif; ?>

                        <?php if($is_incomplete): ?>
                          <span class="tag bad">ข้อมูลยังไม่ครบ</span>
                        <?php endif; ?>

                        <?php if($is_hidden): ?>
                          <span class="tag bad">ถูกซ่อนอยู่</span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td><?= h($s["open_time"] ?? "-") ?>–<?= h($s["close_time"] ?? "-") ?></td>
                    <td>
                      <span class="badge <?= h(statusBadgeClass($s["status"])) ?>">
                        <?= h(statusLabelTH($s["status"])) ?>
                      </span>
                    </td>
                    <td>
                      <div style="display:flex; flex-wrap:wrap; gap:8px;">
                        <button class="btn small" type="button" onclick="editFromRow(this)">แก้ไข</button>

                        <form method="post" style="margin:0;">
                          <input type="hidden" name="action" value="toggle">
                          <input type="hidden" name="shop_id" value="<?= (int)$s["shop_id"] ?>">
                          <button class="btn small" type="submit">เปิด/ปิด</button>
                        </form>

                        <a class="btn small" href="admin-menu.php?shop_id=<?= (int)$s["shop_id"] ?>">🍜 เมนูร้าน</a>
                        <a class="btn small" href="admin-history.php?shop_id=<?= (int)$s["shop_id"] ?>">📋 คิวร้าน</a>
                        <a class="btn small" href="admin-shop-accounts.php?shop_id=<?= (int)$s["shop_id"] ?>">🔐 บัญชีร้านค้า</a>
                        <a class="btn small" href="admin-domes-locks.php">🧩 โดม/ล็อก</a>

                        <?php if($hasIsActive && $is_hidden): ?>
                          <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="shop_id" value="<?= (int)$s["shop_id"] ?>">
                            <button class="btn small" type="submit">กู้คืน</button>
                          </form>
                        <?php else: ?>
                          <form method="post" style="margin:0;" onsubmit="return confirm('ต้องการซ่อนร้านนี้ใช่ไหม?')">
                            <input type="hidden" name="action" value="soft_delete">
                            <input type="hidden" name="shop_id" value="<?= (int)$s["shop_id"] ?>">
                            <button class="btn small danger" type="submit">ซ่อน</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>

  <div class="modal" id="modal">
    <div class="box">
      <form method="post" id="shopForm">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="mode" id="fMode" value="add">
        <input type="hidden" name="shop_id" id="fShopId" value="0">

        <div class="head">
          <div>
            <div style="font-weight:900;" id="modalTitle">เพิ่มร้าน</div>
            <div class="muted">กรอกข้อมูลร้าน แล้วกดบันทึก</div>
          </div>
          <button class="btn" type="button" id="btnClose">ปิด</button>
        </div>

        <div style="margin-top:10px;" class="grid2">
          <div style="grid-column:1/-1;">
            <div class="muted">ชื่อร้าน</div>
            <input name="name" id="fName" placeholder="เช่น ร้านก๋วยเตี๋ยวเรือ" required />
          </div>

          <div style="grid-column:1/-1;">
            <div class="muted">ประเภทร้าน</div>
            <select name="type_id" id="fTypeId">
              <option value="0">-- ยังไม่ระบุ --</option>
              <?php foreach($types as $t): ?>
                <option value="<?= (int)$t["type_id"] ?>">
                  <?= h(($t["category_name"] ? $t["category_name"]." • " : "" ).$t["type_name"]) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="muted" style="margin-top:6px;">
              * ถ้าอยากเพิ่มประเภท/หมวด ไปที่เมนู “หมวดหมู่ร้าน / ประเภทร้าน”
            </div>
          </div>

          <div>
            <div class="muted">สถานะร้าน</div>
            <select name="status" id="fStatus">
              <option value="open">🟢 เปิด</option>
              <option value="closed">🔴 ปิด</option>
              <option value="break">🟡 พัก</option>
              <option value="full">🟠 คิวเต็ม</option>
            </select>
          </div>

          <div>
            <div class="muted">โดม/ล็อก (เลือกตำแหน่งร้าน)</div>
            <select name="lock_id" id="fLockId">
              <option value="0">-- ยังไม่ระบุ --</option>
              <?php foreach($locks as $l): ?>
                <?php $isUsed = !empty($l["used_shop_id"]); ?>
                <option value="<?= (int)$l["lock_id"] ?>">
                  โดม <?= h($l["dome_id"]) ?> • ล็อก <?= h($l["lock_no"]) ?><?= $isUsed ? " (มีร้านใช้แล้ว)" : "" ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <div class="muted">เวลาเปิด</div>
            <input name="open_time" id="fOpen" type="time" value="08:00" />
          </div>

          <div>
            <div class="muted">เวลาปิด</div>
            <input name="close_time" id="fClose" type="time" value="17:00" />
          </div>

          <div>
            <div class="muted">จำกัดคิวสูงสุด/วัน</div>
            <input name="queue_limit" id="fLimit" type="number" min="0" step="1" value="0" />
          </div>

          <?php if($hasEtaPerQueue): ?>
            <div>
              <div class="muted">เวลาเฉลี่ยต่อคิว (นาที)</div>
              <input name="eta_per_queue_min" id="fEta" type="number" min="0" step="1" value="0" />
            </div>
          <?php endif; ?>
        </div>

        <div class="foot">
          <button class="btn" type="button" id="btnCancel">ยกเลิก</button>
          <button class="btn primary" type="submit">💾 บันทึก</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    const modal = document.getElementById("modal");
    const btnAdd = document.getElementById("btnAdd");
    const btnClose = document.getElementById("btnClose");
    const btnCancel = document.getElementById("btnCancel");
    const modalTitle = document.getElementById("modalTitle");

    const fMode = document.getElementById("fMode");
    const fShopId = document.getElementById("fShopId");
    const fName = document.getElementById("fName");
    const fTypeId = document.getElementById("fTypeId");
    const fStatus = document.getElementById("fStatus");
    const fOpen = document.getElementById("fOpen");
    const fClose = document.getElementById("fClose");
    const fLimit = document.getElementById("fLimit");
    const fLockId = document.getElementById("fLockId");
    const fEta = document.getElementById("fEta");

    function openModal(){
      modal.classList.add("show");
      document.body.style.overflow = "hidden";
      setTimeout(() => fName.focus(), 50);
    }

    function closeModal(){
      modal.classList.remove("show");
      document.body.style.overflow = "";
    }

    function setAdd(){
      fMode.value = "add";
      fShopId.value = "0";
      modalTitle.textContent = "เพิ่มร้าน";

      fName.value = "";
      fTypeId.value = "0";
      fStatus.value = "open";
      fOpen.value = "08:00";
      fClose.value = "17:00";
      fLimit.value = "0";
      fLockId.value = "0";
      if (fEta) fEta.value = "0";
    }

    function setEdit(shop){
      fMode.value = "edit";
      fShopId.value = shop.shop_id;
      modalTitle.textContent = "แก้ไขร้าน";

      fName.value = shop.name || "";
      fTypeId.value = shop.type_id || 0;
      fStatus.value = shop.status || "closed";
      fOpen.value = shop.open_time || "08:00";
      fClose.value = shop.close_time || "17:00";
      fLimit.value = shop.queue_limit || 0;
      fLockId.value = shop.lock_id || 0;
      if (fEta) fEta.value = shop.eta_per_queue_min || 0;
    }

    window.editFromRow = (btn) => {
      const tr = btn.closest("tr");
      const raw = tr.getAttribute("data-shop");
      const shop = JSON.parse(raw);
      setEdit(shop);
      openModal();
    };

    btnAdd.addEventListener("click", () => {
      setAdd();
      openModal();
    });

    btnClose.addEventListener("click", closeModal);
    btnCancel.addEventListener("click", closeModal);

    modal.addEventListener("click", (e) => {
      if (e.target === modal) closeModal();
    });

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && modal.classList.contains("show")) {
        closeModal();
      }
    });
  </script>
</body>
</html>