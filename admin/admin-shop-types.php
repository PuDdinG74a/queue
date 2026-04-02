<?php
require_once __DIR__ . "/_auth.php";

function h($str){ return htmlspecialchars((string)$str, ENT_QUOTES, "UTF-8"); }

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (empty($_SESSION["csrf_token"])) {
  $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["csrf_token"];

$err = "";
$ok  = "";

/* =========================
   helper
========================= */
function normalizeName(string $name): string {
  $name = trim($name);
  $name = preg_replace('/\s+/u', ' ', $name);
  return $name;
}

function buildTypeRedirect(array $extra = []): string {
  $base = [];

  if (isset($_GET["q"]) && $_GET["q"] !== "") {
    $base["q"] = $_GET["q"];
  }
  if (isset($_GET["cat"]) && (int)$_GET["cat"] > 0) {
    $base["cat"] = (int)$_GET["cat"];
  }

  $currentDome = 0;
  if (isset($_GET["dome_id"]) && (int)$_GET["dome_id"] > 0) {
    $currentDome = (int)$_GET["dome_id"];
  } elseif (isset($_GET["filter_dome"]) && (int)$_GET["filter_dome"] > 0) {
    $currentDome = (int)$_GET["filter_dome"];
  }

  if ($currentDome > 0) {
    $base["dome_id"] = $currentDome;
  }

  foreach ($extra as $k => $v) {
    if ($v === null || $v === "") {
      unset($base[$k]);
    } else {
      $base[$k] = $v;
    }
  }

  $qs = http_build_query($base);
  return "admin-shop-types.php" . ($qs ? "?".$qs : "");
}

/* =========================
   dropdowns
========================= */
$cats = $pdo->query("
  SELECT category_id, category_name
  FROM shop_categories
  ORDER BY category_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$domes = $pdo->query("
  SELECT dome_id, dome_name
  FROM domes
  ORDER BY dome_name ASC, dome_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   current filters
========================= */
$q = trim($_GET["q"] ?? "");
$catFilter  = (int)($_GET["cat"] ?? 0);

$domeFilter = 0;
if (isset($_GET["dome_id"]) && (int)$_GET["dome_id"] > 0) {
  $domeFilter = (int)$_GET["dome_id"];
} elseif (isset($_GET["filter_dome"]) && (int)$_GET["filter_dome"] > 0) {
  $domeFilter = (int)$_GET["filter_dome"];
}

/* =========================
   CREATE
========================= */
if (isset($_POST["create"])) {
  $token = $_POST["csrf_token"] ?? "";
  if (!hash_equals($_SESSION["csrf_token"] ?? "", $token)) {
    $err = "CSRF token ไม่ถูกต้อง";
  } else {
    $category_id = (int)($_POST["category_id"] ?? 0);
    $type_name   = normalizeName($_POST["type_name"] ?? "");

    if ($category_id <= 0 || $type_name === "") {
      $err = "กรุณาเลือกหมวด และกรอกชื่อประเภท";
    } elseif (mb_strlen($type_name, "UTF-8") > 100) {
      $err = "ชื่อประเภทร้านยาวเกินไป";
    } else {
      $stmt = $pdo->prepare("
        SELECT type_id
        FROM shop_types
        WHERE category_id = ?
          AND TRIM(type_name) = TRIM(?)
        LIMIT 1
      ");
      $stmt->execute([$category_id, $type_name]);
      $dup = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($dup) {
        $err = "มีชื่อประเภทร้านนี้อยู่แล้วในหมวดที่เลือก";
      } else {
        $stmt = $pdo->prepare("
          INSERT INTO shop_types (category_id, type_name)
          VALUES (?, ?)
        ");
        $stmt->execute([$category_id, $type_name]);

        header("Location: " . buildTypeRedirect(["ok" => "created"]));
        exit;
      }
    }
  }
}

/* =========================
   UPDATE
========================= */
if (isset($_POST["update"])) {
  $token = $_POST["csrf_token"] ?? "";
  if (!hash_equals($_SESSION["csrf_token"] ?? "", $token)) {
    $err = "CSRF token ไม่ถูกต้อง";
  } else {
    $type_id     = (int)($_POST["type_id"] ?? 0);
    $category_id = (int)($_POST["category_id"] ?? 0);
    $type_name   = normalizeName($_POST["type_name"] ?? "");

    if ($type_id <= 0 || $category_id <= 0 || $type_name === "") {
      $err = "ข้อมูลไม่ครบ";
    } elseif (mb_strlen($type_name, "UTF-8") > 100) {
      $err = "ชื่อประเภทร้านยาวเกินไป";
    } else {
      $stmt = $pdo->prepare("
        SELECT type_id
        FROM shop_types
        WHERE category_id = ?
          AND TRIM(type_name) = TRIM(?)
          AND type_id <> ?
        LIMIT 1
      ");
      $stmt->execute([$category_id, $type_name, $type_id]);
      $dup = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($dup) {
        $err = "มีชื่อประเภทร้านนี้อยู่แล้วในหมวดที่เลือก";
      } else {
        $stmt = $pdo->prepare("
          UPDATE shop_types
          SET category_id = ?, type_name = ?
          WHERE type_id = ?
        ");
        $stmt->execute([$category_id, $type_name, $type_id]);

        header("Location: " . buildTypeRedirect(["ok" => "updated", "edit" => null]));
        exit;
      }
    }
  }
}

/* =========================
   DELETE (POST only)
========================= */
if (isset($_POST["delete"])) {
  $token = $_POST["csrf_token"] ?? "";
  if (!hash_equals($_SESSION["csrf_token"] ?? "", $token)) {
    $err = "CSRF token ไม่ถูกต้อง";
  } else {
    $id = (int)($_POST["type_id"] ?? 0);

    if ($id > 0) {
      $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM shops
        WHERE type_id = ?
      ");
      $stmt->execute([$id]);
      $usedCount = (int)$stmt->fetchColumn();

      if ($usedCount > 0) {
        header("Location: " . buildTypeRedirect(["err" => "used"]));
        exit;
      }

      try {
        $stmt = $pdo->prepare("DELETE FROM shop_types WHERE type_id = ?");
        $stmt->execute([$id]);
        header("Location: " . buildTypeRedirect(["ok" => "deleted"]));
        exit;
      } catch (PDOException $e) {
        header("Location: " . buildTypeRedirect(["err" => "used"]));
        exit;
      }
    }
  }
}

/* =========================
   flash
========================= */
if (($_GET["ok"] ?? "") === "created") $ok = "เพิ่มประเภทร้านเรียบร้อย";
if (($_GET["ok"] ?? "") === "updated") $ok = "แก้ไขประเภทร้านเรียบร้อย";
if (($_GET["ok"] ?? "") === "deleted") $ok = "ลบประเภทร้านเรียบร้อย";

if (($_GET["err"] ?? "") === "used") {
  $err = "ลบไม่ได้: ประเภทนี้ถูกใช้งานอยู่ในตารางร้านค้า (shops) ให้ย้าย/ลบร้านก่อน";
}

/* =========================
   Search + Filter
========================= */
$params = [];
$sql = "
  SELECT
    t.type_id,
    t.type_name,
    t.category_id,
    c.category_name,
    COUNT(DISTINCT s.shop_id) AS shop_count,
    COUNT(DISTINCT l.dome_id) AS dome_count,
    GROUP_CONCAT(DISTINCT s.name ORDER BY s.name ASC SEPARATOR '||') AS shop_names
  FROM shop_types t
  INNER JOIN shop_categories c ON c.category_id = t.category_id
  LEFT JOIN shops s ON s.type_id = t.type_id
  LEFT JOIN locks l ON l.lock_id = s.lock_id
";

$where = [];

if ($q !== "") {
  $where[] = "t.type_name LIKE ?";
  $params[] = "%$q%";
}

if ($catFilter > 0) {
  $where[] = "t.category_id = ?";
  $params[] = $catFilter;
}

if ($domeFilter > 0) {
  $where[] = "l.dome_id = ?";
  $params[] = $domeFilter;
}

if ($where) {
  $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= "
  GROUP BY t.type_id, t.type_name, t.category_id, c.category_name
  ORDER BY c.category_name ASC, t.type_name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   KPI
========================= */
if ($domeFilter > 0) {
  $stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT t.type_id)
    FROM shop_types t
    LEFT JOIN shops s ON s.type_id = t.type_id
    LEFT JOIN locks l ON l.lock_id = s.lock_id
    WHERE l.dome_id = ?
  ");
  $stmt->execute([$domeFilter]);
  $totalTypes = (int)$stmt->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT t.type_id)
    FROM shop_types t
    INNER JOIN shops s ON s.type_id = t.type_id
    INNER JOIN locks l ON l.lock_id = s.lock_id
    WHERE l.dome_id = ?
  ");
  $stmt->execute([$domeFilter]);
  $usedTypes = (int)$stmt->fetchColumn();

  $unusedTypes = max(0, $totalTypes - $usedTypes);
} else {
  $stmt = $pdo->query("SELECT COUNT(*) FROM shop_types");
  $totalTypes = (int)$stmt->fetchColumn();

  $stmt = $pdo->query("
    SELECT COUNT(*)
    FROM shop_types t
    WHERE EXISTS (
      SELECT 1
      FROM shops s
      WHERE s.type_id = t.type_id
    )
  ");
  $usedTypes = (int)$stmt->fetchColumn();
  $unusedTypes = max(0, $totalTypes - $usedTypes);
}

/* =========================
   Active labels
========================= */
$activeCatName = "";
$activeDomeName = "";

if ($catFilter > 0) {
  $stmt = $pdo->prepare("SELECT category_name FROM shop_categories WHERE category_id = ? LIMIT 1");
  $stmt->execute([$catFilter]);
  $activeCatName = (string)($stmt->fetchColumn() ?: "");
}

if ($domeFilter > 0) {
  $stmt = $pdo->prepare("SELECT dome_name FROM domes WHERE dome_id = ? LIMIT 1");
  $stmt->execute([$domeFilter]);
  $activeDomeName = (string)($stmt->fetchColumn() ?: "");
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin | ประเภทร้าน</title>
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

    .btn{
      padding:10px 12px;
      border-radius:12px;
      border:1px solid var(--border);
      background:#fff;
      cursor:pointer;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      color:var(--text);
      white-space:nowrap;
      font-size:14px;
    }
    .btn.primary{ background:var(--primary); color:#fff; border-color:transparent; }
    .btn.danger{ background:#fff; border-color:rgba(220,38,38,.35); color:var(--bad); }
    .btn.danger:hover{ background:rgba(220,38,38,.08); }

    .grid{ display:grid; grid-template-columns:repeat(12, 1fr); gap:12px; margin-top:12px; }
    .card{ grid-column:span 12; background:var(--card); border:1px solid var(--border); border-radius:16px; padding:14px; box-shadow:0 8px 20px rgba(0,0,0,.05); }
    .muted{ color:var(--muted); font-size:13px; }

    .kpi{ display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
    .kpi .box{ border:1px solid var(--border); border-radius:16px; padding:14px; background:#fff; }
    .kpi .label{ color:var(--muted); font-size:13px; }
    .kpi .value{ font-size:26px; font-weight:900; margin-top:6px; }

    .row{ display:flex; gap:10px; flex-wrap:wrap; align-items:end; }
    .field{ display:flex; flex-direction:column; gap:6px; min-width:260px; }
    label{ font-size:12px; color:var(--muted); }

    input, select{
      width:360px; max-width:100%;
      border:1px solid var(--border); border-radius:12px; padding:10px 12px; outline:none; background:#fff;
    }
    input:focus, select:focus{ border-color:rgba(37,99,235,.6); box-shadow:0 0 0 3px rgba(37,99,235,.12); }

    .table-wrap{ overflow:auto; }
    table{ width:100%; min-width:1200px; border-collapse:separate; border-spacing:0; overflow:hidden; border-radius:14px; border:1px solid var(--border); background:#fff; }
    th,td{ padding:10px 12px; font-size:14px; border-bottom:1px solid var(--border); vertical-align:top; }
    th{ background:#f9fafb; text-align:left; color:#374151; }
    tr:last-child td{ border-bottom:0; }

    .actions{ display:flex; gap:8px; flex-wrap:wrap; }

    .pill{ display:inline-flex; gap:6px; align-items:center; padding:6px 10px; border-radius:999px; border:1px solid var(--border); background:#fff; font-size:12px; }
    .pill.ok{ border-color:rgba(22,163,74,.35); background:rgba(22,163,74,.08); color:var(--ok); }
    .pill.err{ border-color:rgba(220,38,38,.35); background:rgba(220,38,38,.08); color:var(--bad); }
    .pill.used{ border-color:rgba(22,163,74,.35); background:rgba(22,163,74,.08); color:var(--ok); }
    .pill.unused{ border-color:rgba(245,158,11,.35); background:rgba(245,158,11,.08); color:#8a5a00; }

    .shop-preview{
      color:var(--muted);
      font-size:13px;
      line-height:1.5;
      margin-top:4px;
    }

    .modal{
      position:fixed;
      inset:0;
      background:rgba(0,0,0,.42);
      display:none;
      align-items:center;
      justify-content:center;
      padding:16px;
      z-index:1000;
    }
    .modal.show{
      display:flex;
    }
    .modal-box{
      width:min(720px, 100%);
      max-height:90vh;
      overflow:auto;
      background:#fff;
      border:1px solid var(--border);
      border-radius:18px;
      padding:16px;
      box-shadow:0 24px 80px rgba(0,0,0,.24);
    }
    .modal-head{
      display:flex;
      justify-content:space-between;
      align-items:flex-start;
      gap:12px;
      margin-bottom:12px;
    }
    .modal-title{
      font-size:20px;
      font-weight:800;
      margin:0;
    }
    .modal-sub{
      color:var(--muted);
      font-size:13px;
      margin-top:4px;
    }
    .modal-actions{
      display:flex;
      justify-content:flex-end;
      gap:10px;
      flex-wrap:wrap;
      margin-top:16px;
    }
    .form-grid{
      display:grid;
      grid-template-columns:1fr;
      gap:12px;
    }

    @media (max-width: 980px){
      .sidebar{ display:none; }
      .kpi{ grid-template-columns:1fr; }
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
      <a class="active" href="admin-shop-types.php">🧩 ประเภทร้าน</a>
      <a href="admin-history.php">🧾 ประวัติคิว</a>
      <a href="admin-reports.php">📈 รายงาน</a>
      <a href="admin-shop-accounts.php">🔐 บัญชีร้านค้า</a>
      <a href="../logout.php">🚪 ออกจากระบบ</a>
      <a href="../Frontend/index.php">↩ กลับหน้าเว็บ</a>
    </nav>

    <div style="margin-top:14px;">
      <div class="muted" style="color:rgba(255,255,255,.75);">
        * filter ตามโดม = ใช้ดูว่าประเภทนี้มีร้านอยู่ในโดมใดบ้าง
      </div>
    </div>
  </aside>

  <main class="main">
    <div class="topbar">
      <div>
        <h1>🧩 ประเภทร้านค้า</h1>
        <div class="muted">จัดการประเภทร้าน (shop_types) ผูกกับหมวด (shop_categories)</div>

        <?php if($catFilter > 0 || $domeFilter > 0): ?>
          <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
            <?php if($catFilter > 0 && $activeCatName !== ""): ?>
              <span class="pill used">หมวด: <?= h($activeCatName) ?></span>
            <?php endif; ?>
            <?php if($domeFilter > 0 && $activeDomeName !== ""): ?>
              <span class="pill used">โดม: <?= h($activeDomeName) ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <button class="btn" onclick="location.reload()">รีเฟรช</button>
        <button class="btn" type="button" onclick="openCreateTypeModal()">➕ เพิ่มประเภท</button>
        <a class="btn primary" href="admin-shop-categories.php">ไปจัดการหมวด</a>
      </div>
    </div>

    <div class="grid">

      <?php if($ok): ?>
        <section class="card">
          <span class="pill ok">✅ <?= h($ok) ?></span>
        </section>
      <?php endif; ?>

      <?php if($err): ?>
        <section class="card">
          <span class="pill err">⚠️ <?= h($err) ?></span>
        </section>
      <?php endif; ?>

      <section class="card">
        <div class="kpi">
          <div class="box">
            <div class="label">ประเภทร้านทั้งหมด<?= $domeFilter > 0 ? " (ในโดมที่เลือก)" : "" ?></div>
            <div class="value"><?= (int)$totalTypes ?></div>
          </div>
          <div class="box">
            <div class="label">ประเภทที่มีร้านใช้งาน</div>
            <div class="value"><?= (int)$usedTypes ?></div>
          </div>
          <div class="box">
            <div class="label">ประเภทที่ยังไม่ถูกใช้งาน</div>
            <div class="value"><?= (int)$unusedTypes ?></div>
          </div>
        </div>
      </section>

      <section class="card">
        <form method="get" class="row" style="align-items:center;">
          <div class="field" style="flex:1; min-width:260px;">
            <label>ค้นหาประเภท</label>
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="เช่น ก๋วยเตี๋ยว / ข้าวราดแกง">
          </div>

          <div class="field" style="min-width:260px;">
            <label>กรองตามหมวด</label>
            <select name="cat">
              <option value="0">ทั้งหมด</option>
              <?php foreach($cats as $c): ?>
                <option value="<?= (int)$c["category_id"] ?>" <?= $catFilter === (int)$c["category_id"] ? "selected" : "" ?>>
                  <?= h($c["category_name"]) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field" style="min-width:260px;">
            <label>กรองตามโดม</label>
            <select name="dome_id">
              <option value="0">ทั้งหมด</option>
              <?php foreach($domes as $d): ?>
                <option value="<?= (int)$d["dome_id"] ?>" <?= $domeFilter === (int)$d["dome_id"] ? "selected" : "" ?>>
                  <?= h($d["dome_name"]) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="actions">
            <button class="btn" type="submit">🔎 ค้นหา</button>
            <a class="btn" href="admin-shop-types.php">↩ ล้าง</a>
          </div>
        </form>
      </section>

      <section class="card">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th style="width:90px;">ID</th>
                <th style="width:180px;">ชื่อประเภท</th>
                <th style="width:220px;">หมวดหมู่</th>
                <th style="width:170px;">จำนวนร้าน</th>
                <th style="width:170px;">จำนวนโดม</th>
                <th>ร้านที่ใช้ประเภทนี้</th>
                <th style="width:360px;">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php if(!$rows): ?>
                <tr><td colspan="7" class="muted">ยังไม่มีข้อมูล</td></tr>
              <?php else: ?>
                <?php foreach($rows as $r): ?>
                  <?php
                    $shopCount = (int)$r["shop_count"];
                    $domeCount = (int)$r["dome_count"];
                    $shopNamesRaw = trim((string)($r["shop_names"] ?? ""));
                    $shopNames = $shopNamesRaw !== "" ? explode("||", $shopNamesRaw) : [];
                    $previewNames = array_slice($shopNames, 0, 3);
                    $remain = max(0, count($shopNames) - count($previewNames));
                  ?>
                  <tr>
                    <td><?= (int)$r["type_id"] ?></td>
                    <td><?= h($r["type_name"]) ?></td>
                    <td><?= h($r["category_name"]) ?></td>
                    <td>
                      <?php if($shopCount > 0): ?>
                        <span class="pill used">ใช้งาน <?= $shopCount ?> ร้าน</span>
                      <?php else: ?>
                        <span class="pill unused">ยังไม่ถูกใช้งาน</span>
                      <?php endif; ?>
                    </td>
                    <td><?= $domeCount ?></td>
                    <td>
                      <?php if(!$shopNames): ?>
                        <div class="muted">ยังไม่มีร้านใช้งาน</div>
                      <?php else: ?>
                        <div><?= h(implode(", ", $previewNames)) ?></div>
                        <?php if($remain > 0): ?>
                          <div class="shop-preview">และอีก <?= $remain ?> ร้าน</div>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="actions">
                        <button
                          type="button"
                          class="btn"
                          onclick='openEditTypeModal(<?= json_encode([
                            "type_id" => (int)$r["type_id"],
                            "category_id" => (int)$r["category_id"],
                            "type_name" => (string)$r["type_name"]
                          ], JSON_UNESCAPED_UNICODE) ?>)'>
                          ✏️ แก้ไข
                        </button>

                        <a class="btn"
                           href="admin-shops.php?<?= http_build_query(array_filter([
                             'type_id' => (int)$r["type_id"],
                             'cat' => $catFilter > 0 ? $catFilter : null,
                             'filter_dome' => $domeFilter > 0 ? $domeFilter : null
                           ])) ?>">
                          🏪 ดูร้าน
                        </a>

                        <form method="post" style="margin:0;" onsubmit="return confirm('ยืนยันลบประเภทนี้? ถ้ามีร้านใช้งานอยู่จะลบไม่ได้');">
                          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                          <input type="hidden" name="type_id" value="<?= (int)$r["type_id"] ?>">
                          <button class="btn danger" type="submit" name="delete">🗑 ลบ</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="muted" style="margin-top:10px;">
          * ปุ่ม “ดูร้าน” จะพาไปหน้าจัดการร้านโดยส่ง type_id และโดมที่กรองอยู่ไปต่อ
        </div>
      </section>

    </div>
  </main>

</div>

<div class="modal" id="typeModal">
  <div class="modal-box">
    <div class="modal-head">
      <div>
        <h3 class="modal-title" id="typeModalTitle">จัดการประเภทร้าน</h3>
        <div class="modal-sub" id="typeModalSub">กรอกข้อมูลแล้วบันทึก</div>
      </div>
      <button type="button" class="btn" onclick="closeTypeModal()">ปิด</button>
    </div>

    <form method="post" id="createTypeForm" style="display:none;">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

      <div class="form-grid">
        <div class="field">
          <label>หมวดหมู่</label>
          <select name="category_id" id="create_type_category_id" required>
            <option value="">-- เลือกหมวด --</option>
            <?php foreach($cats as $c): ?>
              <option value="<?= (int)$c["category_id"] ?>" <?= $catFilter === (int)$c["category_id"] ? "selected" : "" ?>>
                <?= h($c["category_name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label>ชื่อประเภทร้าน</label>
          <input type="text" name="type_name" id="create_type_name" maxlength="100" placeholder="เช่น ก๋วยเตี๋ยว/เมนูเส้น" required>
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeTypeModal()">ยกเลิก</button>
        <button type="submit" name="create" class="btn primary">➕ เพิ่มประเภท</button>
      </div>
    </form>

    <form method="post" id="editTypeForm" style="display:none;">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <input type="hidden" name="type_id" id="edit_type_id">

      <div class="form-grid">
        <div class="field">
          <label>หมวดหมู่</label>
          <select name="category_id" id="edit_type_category_id" required>
            <option value="">-- เลือกหมวด --</option>
            <?php foreach($cats as $c): ?>
              <option value="<?= (int)$c["category_id"] ?>">
                <?= h($c["category_name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label>ชื่อประเภทร้าน</label>
          <input type="text" name="type_name" id="edit_type_name" maxlength="100" required>
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeTypeModal()">ยกเลิก</button>
        <button type="submit" name="update" class="btn primary">💾 บันทึก</button>
      </div>
    </form>
  </div>
</div>

<script>
  const typeModal = document.getElementById('typeModal');
  const createTypeForm = document.getElementById('createTypeForm');
  const editTypeForm = document.getElementById('editTypeForm');
  const typeModalTitle = document.getElementById('typeModalTitle');
  const typeModalSub = document.getElementById('typeModalSub');

  function openTypeModal(){
    typeModal.classList.add('show');
    document.body.style.overflow = 'hidden';
  }

  function closeTypeModal(){
    typeModal.classList.remove('show');
    document.body.style.overflow = '';
    resetTypeModal();
  }

  function resetTypeModal(){
    createTypeForm.style.display = 'none';
    editTypeForm.style.display = 'none';

    createTypeForm.reset();
    editTypeForm.reset();

    document.getElementById('create_type_name').value = '';
    document.getElementById('edit_type_id').value = '';
    document.getElementById('edit_type_name').value = '';
  }

  function openCreateTypeModal(){
    typeModalTitle.textContent = 'เพิ่มประเภทร้าน';
    typeModalSub.textContent = 'เพิ่มข้อมูลประเภทใหม่ให้กับระบบ';
    createTypeForm.style.display = 'block';
    editTypeForm.style.display = 'none';
    openTypeModal();
  }

  function openEditTypeModal(data){
    typeModalTitle.textContent = 'แก้ไขประเภทร้าน';
    typeModalSub.textContent = 'ปรับข้อมูลประเภทที่มีอยู่ในระบบ';
    createTypeForm.style.display = 'none';
    editTypeForm.style.display = 'block';

    document.getElementById('edit_type_id').value = data.type_id || '';
    document.getElementById('edit_type_category_id').value = data.category_id || '';
    document.getElementById('edit_type_name').value = data.type_name || '';

    openTypeModal();
  }

  typeModal.addEventListener('click', function(e){
    if (e.target === typeModal) {
      closeTypeModal();
    }
  });

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && typeModal.classList.contains('show')) {
      closeTypeModal();
    }
  });
</script>
</body>
</html>