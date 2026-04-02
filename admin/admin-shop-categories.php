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

/* =========================
   CREATE
========================= */
if (isset($_POST["create"])) {
  $token = $_POST["csrf_token"] ?? "";
  if (!hash_equals($_SESSION["csrf_token"] ?? "", $token)) {
    $err = "CSRF token ไม่ถูกต้อง";
  } else {
    $name = normalizeName($_POST["category_name"] ?? "");

    if ($name === "") {
      $err = "กรุณากรอกชื่อหมวดหมู่";
    } elseif (mb_strlen($name, "UTF-8") > 100) {
      $err = "ชื่อหมวดหมู่ยาวเกินไป";
    } else {
      $stmt = $pdo->prepare("
        SELECT category_id
        FROM shop_categories
        WHERE TRIM(category_name) = TRIM(?)
        LIMIT 1
      ");
      $stmt->execute([$name]);
      $dup = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($dup) {
        $err = "มีชื่อหมวดหมู่นี้อยู่แล้ว";
      } else {
        $stmt = $pdo->prepare("INSERT INTO shop_categories (category_name) VALUES (?)");
        $stmt->execute([$name]);
        header("Location: admin-shop-categories.php?ok=created");
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
    $id   = (int)($_POST["category_id"] ?? 0);
    $name = normalizeName($_POST["category_name"] ?? "");

    if ($id <= 0 || $name === "") {
      $err = "ข้อมูลไม่ครบ";
    } elseif (mb_strlen($name, "UTF-8") > 100) {
      $err = "ชื่อหมวดหมู่ยาวเกินไป";
    } else {
      $stmt = $pdo->prepare("
        SELECT category_id
        FROM shop_categories
        WHERE TRIM(category_name) = TRIM(?)
          AND category_id <> ?
        LIMIT 1
      ");
      $stmt->execute([$name, $id]);
      $dup = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($dup) {
        $err = "มีชื่อหมวดหมู่นี้อยู่แล้ว";
      } else {
        $stmt = $pdo->prepare("UPDATE shop_categories SET category_name=? WHERE category_id=?");
        $stmt->execute([$name, $id]);
        header("Location: admin-shop-categories.php?ok=updated");
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
    $id = (int)($_POST["category_id"] ?? 0);

    if ($id > 0) {
      $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM shop_types
        WHERE category_id = ?
      ");
      $stmt->execute([$id]);
      $usedCount = (int)$stmt->fetchColumn();

      if ($usedCount > 0) {
        header("Location: admin-shop-categories.php?err=used");
        exit;
      }

      try {
        $stmt = $pdo->prepare("DELETE FROM shop_categories WHERE category_id=?");
        $stmt->execute([$id]);
        header("Location: admin-shop-categories.php?ok=deleted");
        exit;
      } catch (PDOException $e) {
        header("Location: admin-shop-categories.php?err=used");
        exit;
      }
    }
  }
}

/* =========================
   flash
========================= */
if (($_GET["ok"] ?? "") === "created") $ok = "เพิ่มหมวดหมู่เรียบร้อย";
if (($_GET["ok"] ?? "") === "updated") $ok = "แก้ไขหมวดหมู่เรียบร้อย";
if (($_GET["ok"] ?? "") === "deleted") $ok = "ลบหมวดหมู่เรียบร้อย";

if (($_GET["err"] ?? "") === "used") {
  $err = "ลบไม่ได้: หมวดนี้ถูกใช้งานอยู่ใน “ประเภทร้าน” (shop_types)";
}

/* =========================
   filters
========================= */
$q = trim($_GET["q"] ?? "");
$filter_dome = (int)($_GET["filter_dome"] ?? 0);
$params = [];

/* =========================
   domes for filter
========================= */
$domes = $pdo->query("
  SELECT dome_id, dome_name
  FROM domes
  ORDER BY dome_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   search / summary table
========================= */
$sql = "
  SELECT
    c.category_id,
    c.category_name,
    COUNT(DISTINCT t.type_id) AS type_count,
    COUNT(DISTINCT s.shop_id) AS shop_count,
    COUNT(DISTINCT l.dome_id) AS dome_count,
    GROUP_CONCAT(DISTINCT d.dome_name ORDER BY d.dome_id SEPARATOR ', ') AS dome_list
  FROM shop_categories c
  LEFT JOIN shop_types t
    ON t.category_id = c.category_id
  LEFT JOIN shops s
    ON s.type_id = t.type_id
  LEFT JOIN locks l
    ON l.lock_id = s.lock_id
  LEFT JOIN domes d
    ON d.dome_id = l.dome_id
  WHERE 1=1
";

if ($filter_dome > 0) {
  $sql .= " AND l.dome_id = ? ";
  $params[] = $filter_dome;
}

if ($q !== "") {
  $sql .= " AND c.category_name LIKE ? ";
  $params[] = "%$q%";
}

$sql .= "
  GROUP BY c.category_id
  ORDER BY c.category_name ASC, c.category_id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   KPI
========================= */
if ($filter_dome > 0) {
  $stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT c.category_id)
    FROM shop_categories c
    INNER JOIN shop_types t ON t.category_id = c.category_id
    INNER JOIN shops s ON s.type_id = t.type_id
    INNER JOIN locks l ON l.lock_id = s.lock_id
    WHERE l.dome_id = ?
  ");
  $stmt->execute([$filter_dome]);
  $totalCategories = (int)$stmt->fetchColumn();

  $usedCategories = $totalCategories;
  $unusedCategories = 0;
} else {
  $stmt = $pdo->query("SELECT COUNT(*) FROM shop_categories");
  $totalCategories = (int)$stmt->fetchColumn();

  $stmt = $pdo->query("
    SELECT COUNT(*)
    FROM shop_categories c
    WHERE EXISTS (
      SELECT 1
      FROM shop_types t
      WHERE t.category_id = c.category_id
    )
  ");
  $usedCategories = (int)$stmt->fetchColumn();
  $unusedCategories = max(0, $totalCategories - $usedCategories);
}

/* =========================
   active dome label
========================= */
$activeDomeName = "";
if ($filter_dome > 0) {
  $stmt = $pdo->prepare("SELECT dome_name FROM domes WHERE dome_id = ? LIMIT 1");
  $stmt->execute([$filter_dome]);
  $activeDomeName = (string)($stmt->fetchColumn() ?: "");
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>จัดการหมวดหมู่ร้าน | Admin</title>
  <style>
    :root { --bg:#f6f7fb; --card:#fff; --text:#111827; --muted:#6b7280; --primary:#2563eb; --border:#e5e7eb; --ok:#16a34a; --bad:#dc2626; --warn:#f59e0b; }
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

    .topbar{ display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
    h1{ margin:0; font-size:22px; }
    .sub{ color:var(--muted); margin-top:4px; font-size:13px; }

    .btn{
      padding:10px 12px;
      border-radius:12px;
      border:1px solid var(--border);
      background:#fff;
      cursor:pointer;
      text-decoration:none;
      display:inline-flex;
      gap:8px;
      align-items:center;
      justify-content:center;
      color:var(--text);
      font-size:14px;
      white-space:nowrap;
    }
    .btn.primary{ background:var(--primary); color:#fff; border-color:transparent; }
    .btn.danger{ border-color:rgba(220,38,38,.35); color:var(--bad); background:#fff; }
    .btn.danger:hover{ background:rgba(220,38,38,.08); }

    .grid{ display:grid; grid-template-columns:repeat(12, 1fr); gap:12px; margin-top:12px; }
    .card{ grid-column:span 12; background:var(--card); border:1px solid var(--border); border-radius:16px; padding:14px; box-shadow:0 8px 20px rgba(0,0,0,.05); }

    .kpi{ display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
    .kpi .box{ border:1px solid var(--border); border-radius:16px; padding:14px; background:#fff; }
    .kpi .label{ color:var(--muted); font-size:13px; }
    .kpi .value{ font-size:26px; font-weight:900; margin-top:6px; }

    .row{ display:flex; gap:12px; flex-wrap:wrap; align-items:end; }
    .field{ display:flex; flex-direction:column; gap:6px; min-width:240px; flex:1; }
    label{ font-size:13px; color:var(--muted); }

    input, select{
      width:100%;
      border:1px solid var(--border);
      border-radius:14px;
      padding:12px 12px;
      outline:none;
      background:#fff;
      font-size:15px;
    }
    input:focus, select:focus{ border-color:rgba(37,99,235,.6); box-shadow:0 0 0 4px rgba(37,99,235,.12); }

    .right-actions{ margin-left:auto; display:flex; gap:10px; flex-wrap:wrap; }

    .alert{ border-radius:14px; padding:10px 12px; border:1px solid var(--border); }
    .alert.ok{ background:rgba(16,185,129,.10); border-color:rgba(16,185,129,.28); color:#065f46; }
    .alert.err{ background:rgba(239,68,68,.10); border-color:rgba(239,68,68,.28); color:#7f1d1d; }

    .table-wrap{ overflow:auto; border-radius:14px; border:1px solid var(--border); background:#fff; }
    table{ width:100%; min-width:1120px; border-collapse:separate; border-spacing:0; }
    th,td{ padding:14px 14px; font-size:15px; border-bottom:1px solid var(--border); vertical-align:top; }
    th{ background:#f9fafb; text-align:left; color:#374151; }
    tr:last-child td{ border-bottom:0; }

    .manage{ display:flex; gap:10px; flex-wrap:wrap; }
    .badge{
      display:inline-block;
      padding:4px 10px;
      border-radius:999px;
      font-size:12px;
      font-weight:700;
      border:1px solid var(--border);
      background:#fff;
      white-space:nowrap;
    }
    .badge.used{
      color:#0c7a32;
      border-color:rgba(22,163,74,.35);
      background:rgba(22,163,74,.08);
    }
    .badge.unused{
      color:#8a5a00;
      border-color:rgba(245,158,11,.35);
      background:rgba(245,158,11,.08);
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
      width:min(680px, 100%);
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
      .field{ min-width:220px; }
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
        <a class="active" href="admin-shop-categories.php">📁 หมวดหมู่ร้าน</a>
        <a href="admin-shop-types.php">🧩 ประเภทร้าน</a>
        <a href="admin-history.php">🧾 ประวัติคิว</a>
        <a href="admin-reports.php">📈 รายงาน</a>
        <a href="admin-shop-accounts.php">🔐 บัญชีร้านค้า</a>
        <a href="../logout.php">🚪 ออกจากระบบ</a>
        <a href="../Frontend/index.php">↩ กลับหน้าเว็บ</a>
      </nav>

      <div style="margin-top:14px;">
        <div style="color:rgba(255,255,255,.75); font-size:13px;">
          * หน้านี้เป็นข้อมูลแม่ของระบบ (Master Data)
        </div>
      </div>
    </aside>

    <main class="main">
      <div class="topbar">
        <div>
          <h1>📁 จัดการหมวดหมู่ร้านค้า</h1>
          <div class="sub">ตาราง: <b>shop_categories</b></div>

          <?php if($filter_dome > 0 && $activeDomeName !== ""): ?>
            <div style="margin-top:8px;">
              <span class="badge used">กำลังกรองตามโดม: <?= h($activeDomeName) ?></span>
            </div>
          <?php endif; ?>
        </div>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
          <button class="btn" onclick="location.reload()">รีเฟรช</button>
          <button class="btn" type="button" onclick="openCreateModal()">➕ เพิ่มหมวดหมู่</button>
          <a class="btn primary" href="admin-shop-types.php">ไปหน้าประเภทร้าน</a>
        </div>
      </div>

      <div class="grid">

        <?php if($ok): ?>
          <section class="card alert ok">✅ <?= h($ok) ?></section>
        <?php endif; ?>

        <?php if($err): ?>
          <section class="card alert err">⚠️ <?= h($err) ?></section>
        <?php endif; ?>

        <section class="card">
          <div class="kpi">
            <div class="box">
              <div class="label">หมวดหมู่ทั้งหมด<?= $filter_dome > 0 ? " (ในโดมที่เลือก)" : "" ?></div>
              <div class="value"><?= (int)$totalCategories ?></div>
            </div>
            <div class="box">
              <div class="label">หมวดที่มีการใช้งาน</div>
              <div class="value"><?= (int)$usedCategories ?></div>
            </div>
            <div class="box">
              <div class="label">หมวดที่ยังไม่ถูกใช้งาน<?= $filter_dome > 0 ? " (ไม่คำนวณแยก)" : "" ?></div>
              <div class="value"><?= (int)$unusedCategories ?></div>
            </div>
          </div>
        </section>

        <section class="card">
          <form method="get" class="row">
            <div class="field" style="max-width:420px;">
              <label>ค้นหาหมวดหมู่</label>
              <input name="q" value="<?= h($q) ?>" placeholder="พิมพ์ชื่อหมวด เช่น อาหาร / เครื่องดื่ม">
            </div>

            <div class="field" style="max-width:260px;">
              <label>เลือกโดม</label>
              <select name="filter_dome">
                <option value="0">ทุกโดม</option>
                <?php foreach($domes as $d): ?>
                  <option value="<?= (int)$d["dome_id"] ?>" <?= $filter_dome === (int)$d["dome_id"] ? "selected" : "" ?>>
                    <?= h($d["dome_name"]) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="right-actions">
              <button class="btn" type="submit">🔎 ค้นหา</button>
              <a class="btn" href="admin-shop-categories.php">↩ ล้าง</a>
            </div>
          </form>
        </section>

        <section class="card">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th style="width:80px;">ID</th>
                  <th>ชื่อหมวดหมู่</th>
                  <th style="width:120px;">ประเภท</th>
                  <th style="width:120px;">ร้าน</th>
                  <th style="width:100px;">โดม</th>
                  <th style="width:220px;">โดมที่ใช้</th>
                  <th style="width:460px;">จัดการ</th>
                </tr>
              </thead>
              <tbody>
                <?php if(!$rows): ?>
                  <tr><td colspan="7" style="color:var(--muted);">ไม่พบข้อมูล</td></tr>
                <?php else: ?>
                  <?php foreach($rows as $r): ?>
                    <tr>
                      <td><?= (int)$r["category_id"] ?></td>
                      <td style="font-weight:700;"><?= h($r["category_name"]) ?></td>

                      <td>
                        <span class="badge used"><?= (int)$r["type_count"] ?> ประเภท</span>
                      </td>

                      <td>
                        <?php if((int)$r["shop_count"] > 0): ?>
                          <span class="badge used"><?= (int)$r["shop_count"] ?> ร้าน</span>
                        <?php else: ?>
                          <span class="badge unused">0 ร้าน</span>
                        <?php endif; ?>
                      </td>

                      <td>
                        <?php if((int)$r["dome_count"] > 0): ?>
                          <span class="badge used"><?= (int)$r["dome_count"] ?> โดม</span>
                        <?php else: ?>
                          <span class="badge unused">0 โดม</span>
                        <?php endif; ?>
                      </td>

                      <td style="font-size:13px; color:#374151;">
                        <?= h($r["dome_list"] ?: "-") ?>
                      </td>

                      <td>
                        <div class="manage">
                          <a class="btn"
                            href="admin-shop-types.php?cat=<?= (int)$r["category_id"] ?>&filter_dome=<?= (int)$filter_dome ?>">
                            🧩 ดูประเภทร้าน
                          </a>

                          <a class="btn"
                            href="admin-shops.php?category_id=<?= (int)$r["category_id"] ?>&filter_dome=<?= (int)$filter_dome ?>">
                            🏪 ดูร้านในหมวด
                          </a>

                          <button
                            type="button"
                            class="btn"
                            onclick='openEditModal(<?= json_encode([
                              "category_id" => (int)$r["category_id"],
                              "category_name" => (string)$r["category_name"]
                            ], JSON_UNESCAPED_UNICODE) ?>)'>
                            ✏️ แก้ไข
                          </button>

                          <form method="post" style="margin:0;" onsubmit="return confirm('ยืนยันลบหมวดนี้? ถ้ามีประเภทร้านใช้งานอยู่จะลบไม่ได้');">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="category_id" value="<?= (int)$r["category_id"] ?>">
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
        </section>

      </div>
    </main>
  </div>

  <div class="modal" id="categoryModal">
    <div class="modal-box">
      <div class="modal-head">
        <div>
          <h3 class="modal-title" id="categoryModalTitle">จัดการหมวดหมู่ร้าน</h3>
          <div class="modal-sub" id="categoryModalSub">กรอกข้อมูลแล้วบันทึก</div>
        </div>
        <button type="button" class="btn" onclick="closeCategoryModal()">ปิด</button>
      </div>

      <form method="post" id="createCategoryForm" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

        <div class="form-grid">
          <div class="field">
            <label>ชื่อหมวดหมู่ร้าน</label>
            <input type="text" name="category_name" id="create_category_name" maxlength="100" placeholder="เช่น อาหาร / เครื่องดื่ม / ของหวาน" required>
          </div>
        </div>

        <div class="modal-actions">
          <button type="button" class="btn" onclick="closeCategoryModal()">ยกเลิก</button>
          <button type="submit" name="create" class="btn primary">➕ เพิ่มหมวดหมู่</button>
        </div>
      </form>

      <form method="post" id="editCategoryForm" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="category_id" id="edit_category_id">

        <div class="form-grid">
          <div class="field">
            <label>แก้ไขชื่อหมวดหมู่ร้าน</label>
            <input type="text" name="category_name" id="edit_category_name" maxlength="100" required>
          </div>
        </div>

        <div class="modal-actions">
          <button type="button" class="btn" onclick="closeCategoryModal()">ยกเลิก</button>
          <button type="submit" name="update" class="btn primary">💾 บันทึก</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    const categoryModal = document.getElementById('categoryModal');
    const createCategoryForm = document.getElementById('createCategoryForm');
    const editCategoryForm = document.getElementById('editCategoryForm');
    const categoryModalTitle = document.getElementById('categoryModalTitle');
    const categoryModalSub = document.getElementById('categoryModalSub');

    function openCategoryModal(){
      categoryModal.classList.add('show');
      document.body.style.overflow = 'hidden';
    }

    function closeCategoryModal(){
      categoryModal.classList.remove('show');
      document.body.style.overflow = '';
      resetCategoryModal();
    }

    function resetCategoryModal(){
      createCategoryForm.style.display = 'none';
      editCategoryForm.style.display = 'none';

      createCategoryForm.reset();
      editCategoryForm.reset();

      document.getElementById('create_category_name').value = '';
      document.getElementById('edit_category_id').value = '';
      document.getElementById('edit_category_name').value = '';
    }

    function openCreateModal(){
      categoryModalTitle.textContent = 'เพิ่มหมวดหมู่ร้าน';
      categoryModalSub.textContent = 'เพิ่มข้อมูลแม่ของระบบ';
      createCategoryForm.style.display = 'block';
      editCategoryForm.style.display = 'none';
      document.getElementById('create_category_name').value = '';
      openCategoryModal();
    }

    function openEditModal(data){
      categoryModalTitle.textContent = 'แก้ไขหมวดหมู่ร้าน';
      categoryModalSub.textContent = 'ปรับชื่อหมวดหมู่ที่มีอยู่ในระบบ';
      createCategoryForm.style.display = 'none';
      editCategoryForm.style.display = 'block';
      document.getElementById('edit_category_id').value = data.category_id || '';
      document.getElementById('edit_category_name').value = data.category_name || '';
      openCategoryModal();
    }

    categoryModal.addEventListener('click', function(e){
      if (e.target === categoryModal) {
        closeCategoryModal();
      }
    });

    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && categoryModal.classList.contains('show')) {
        closeCategoryModal();
      }
    });
  </script>
</body>
</html>