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

$tempPasswordFlash = $_SESSION["owner_temp_password"] ?? "";
$tempUsernameFlash = $_SESSION["owner_temp_username"] ?? "";
$tempShopNameFlash = $_SESSION["owner_temp_shop_name"] ?? "";

unset($_SESSION["owner_temp_password"], $_SESSION["owner_temp_username"], $_SESSION["owner_temp_shop_name"]);

/* =========================
   helper
========================= */
function hq($str){ return htmlspecialchars((string)$str, ENT_QUOTES, "UTF-8"); }

function normalizeText(string $text): string {
  $text = trim($text);
  $text = preg_replace('/\s+/u', ' ', $text);
  return $text;
}

function normalizeUsername(string $username): string {
  $username = trim($username);
  $username = preg_replace('/\s+/u', '', $username);
  return mb_strtolower($username, 'UTF-8');
}

function generateTempPassword(int $length = 10): string {
  $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789@#$%';
  $max = strlen($chars) - 1;
  $out = '';
  for ($i = 0; $i < $length; $i++) {
    $out .= $chars[random_int(0, $max)];
  }
  return $out;
}

function shopStatusLabelTH($status){
  return match($status){
    "open"   => "🟢 เปิด",
    "closed" => "🔴 ปิด",
    "break"  => "🟡 พัก",
    "full"   => "🟠 คิวเต็ม",
    default  => $status ?: "-"
  };
}

function accountBadgeClass(?array $row): string {
  if (!$row || empty($row["user_id"])) return "none";
  return ((int)$row["is_active"] === 1) ? "active" : "inactive";
}

function accountBadgeText(?array $row): string {
  if (!$row || empty($row["user_id"])) return "ยังไม่มีบัญชี";
  return ((int)$row["is_active"] === 1) ? "ใช้งาน" : "ปิดใช้งาน";
}

function preserveQuery(array $overrides = []): string {
  $params = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($params[$k]);
    else $params[$k] = $v;
  }
  $query = http_build_query($params);
  return $query ? ('?' . $query) : '';
}

function hasColumn(PDO $pdo, string $table, string $column): bool {
  $sql = "
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$table, $column]);
  return (int)$stmt->fetchColumn() > 0;
}

$hasUpdatedAt = hasColumn($pdo, "users", "updated_at");
$hasLastLogin = hasColumn($pdo, "users", "last_login_at");

/* =========================
   CREATE OWNER ACCOUNT
========================= */
if (isset($_POST["create_account"])) {
  $token = $_POST["csrf_token"] ?? "";
  if (!hash_equals($_SESSION["csrf_token"] ?? "", $token)) {
    $err = "CSRF token ไม่ถูกต้อง";
  } else {
    $shop_id    = (int)($_POST["shop_id"] ?? 0);
    $full_name  = normalizeText($_POST["full_name"] ?? "");
    $username   = normalizeUsername($_POST["username"] ?? "");
    $password   = (string)($_POST["password"] ?? "");
    $confirm    = (string)($_POST["confirm_password"] ?? "");

    if ($shop_id <= 0) {
      $err = "กรุณาเลือกร้านค้า";
    } elseif ($full_name === "") {
      $err = "กรุณากรอกชื่อเจ้าของร้าน / ผู้ใช้งาน";
    } elseif (mb_strlen($full_name, "UTF-8") > 100) {
      $err = "ชื่อเจ้าของร้าน / ผู้ใช้งานยาวเกินไป";
    } elseif ($username === "") {
      $err = "กรุณากรอกชื่อผู้ใช้";
    } elseif (mb_strlen($username, "UTF-8") > 50) {
      $err = "ชื่อผู้ใช้ยาวเกินไป";
    } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
      $err = "ชื่อผู้ใช้ใช้ได้เฉพาะ a-z, A-Z, 0-9, จุด, ขีดล่าง, ขีดกลาง";
    } elseif ($password === "") {
      $err = "กรุณากรอกรหัสผ่าน";
    } elseif (strlen($password) < 8) {
      $err = "รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร";
    } elseif ($password !== $confirm) {
      $err = "ยืนยันรหัสผ่านไม่ตรงกัน";
    } else {
      $stmt = $pdo->prepare("
        SELECT user_id
        FROM users
        WHERE role = 'owner'
          AND shop_id = ?
        LIMIT 1
      ");
      $stmt->execute([$shop_id]);
      $dupShop = $stmt->fetch(PDO::FETCH_ASSOC);

      $stmt = $pdo->prepare("
        SELECT user_id
        FROM users
        WHERE username = ?
        LIMIT 1
      ");
      $stmt->execute([$username]);
      $dupUser = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($dupShop) {
        $err = "ร้านนี้มีบัญชี owner อยู่แล้ว";
      } elseif ($dupUser) {
        $err = "ชื่อผู้ใช้นี้ถูกใช้งานแล้ว";
      } else {
        $stmt = $pdo->prepare("SELECT name FROM shops WHERE shop_id = ? LIMIT 1");
        $stmt->execute([$shop_id]);
        $shopName = (string)($stmt->fetchColumn() ?: "");

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
          INSERT INTO users (
            full_name, username, password_hash, role, shop_id, is_active
          ) VALUES (?, ?, ?, 'owner', ?, 1)
        ");
        $stmt->execute([$full_name, $username, $hash, $shop_id]);

        $_SESSION["owner_temp_password"] = $password;
        $_SESSION["owner_temp_username"] = $username;
        $_SESSION["owner_temp_shop_name"] = $shopName;

        header("Location: admin-shop-accounts.php?ok=created&shop_id=" . $shop_id);
        exit;
      }
    }
  }
}

/* =========================
   UPDATE OWNER ACCOUNT
========================= */
if (isset($_POST["update_account"])) {
  $token = $_POST["csrf_token"] ?? "";
  if (!hash_equals($_SESSION["csrf_token"] ?? "", $token)) {
    $err = "CSRF token ไม่ถูกต้อง";
  } else {
    $user_id    = (int)($_POST["user_id"] ?? 0);
    $full_name  = normalizeText($_POST["full_name"] ?? "");
    $username   = normalizeUsername($_POST["username"] ?? "");

    if ($user_id <= 0) {
      $err = "ไม่พบบัญชีที่ต้องการแก้ไข";
    } elseif ($full_name === "") {
      $err = "กรุณากรอกชื่อเจ้าของร้าน / ผู้ใช้งาน";
    } elseif (mb_strlen($full_name, "UTF-8") > 100) {
      $err = "ชื่อเจ้าของร้าน / ผู้ใช้งานยาวเกินไป";
    } elseif ($username === "") {
      $err = "กรุณากรอกชื่อผู้ใช้";
    } elseif (mb_strlen($username, "UTF-8") > 50) {
      $err = "ชื่อผู้ใช้ยาวเกินไป";
    } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
      $err = "ชื่อผู้ใช้ใช้ได้เฉพาะ a-z, A-Z, 0-9, จุด, ขีดล่าง, ขีดกลาง";
    } else {
      $stmt = $pdo->prepare("
        SELECT user_id
        FROM users
        WHERE username = ?
          AND user_id <> ?
        LIMIT 1
      ");
      $stmt->execute([$username, $user_id]);
      $dup = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($dup) {
        $err = "ชื่อผู้ใช้นี้ถูกใช้งานแล้ว";
      } else {
        $stmt = $pdo->prepare("
          UPDATE users
          SET full_name = ?, username = ?
          WHERE user_id = ?
            AND role = 'owner'
          LIMIT 1
        ");
        $stmt->execute([$full_name, $username, $user_id]);

        header("Location: admin-shop-accounts.php?ok=updated");
        exit;
      }
    }
  }
}

/* =========================
   RESET PASSWORD
========================= */
if (isset($_POST["reset_password"])) {
  $token = $_POST["csrf_token"] ?? "";
  if (!hash_equals($_SESSION["csrf_token"] ?? "", $token)) {
    $err = "CSRF token ไม่ถูกต้อง";
  } else {
    $user_id = (int)($_POST["user_id"] ?? 0);
    $mode    = $_POST["reset_mode"] ?? "auto";
    $newPass = (string)($_POST["new_password"] ?? "");
    $confirm = (string)($_POST["confirm_new_password"] ?? "");

    if ($user_id <= 0) {
      $err = "ไม่พบบัญชีที่ต้องการรีเซ็ต";
    } else {
      if ($mode === "manual") {
        if ($newPass === "") {
          $err = "กรุณากรอกรหัสผ่านใหม่";
        } elseif (strlen($newPass) < 8) {
          $err = "รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร";
        } elseif ($newPass !== $confirm) {
          $err = "ยืนยันรหัสผ่านใหม่ไม่ตรงกัน";
        }
      } else {
        $newPass = generateTempPassword(10);
      }

      if ($err === "") {
        $stmt = $pdo->prepare("
          SELECT u.username, s.name AS shop_name, s.shop_id
          FROM users u
          LEFT JOIN shops s ON s.shop_id = u.shop_id
          WHERE u.user_id = ?
            AND u.role = 'owner'
          LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);

        $hash = password_hash($newPass, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
          UPDATE users
          SET password_hash = ?
          WHERE user_id = ?
            AND role = 'owner'
          LIMIT 1
        ");
        $stmt->execute([$hash, $user_id]);

        $_SESSION["owner_temp_password"] = $newPass;
        $_SESSION["owner_temp_username"] = (string)($acc["username"] ?? "");
        $_SESSION["owner_temp_shop_name"] = (string)($acc["shop_name"] ?? "");

        header("Location: admin-shop-accounts.php?ok=reset");
        exit;
      }
    }
  }
}

/* =========================
   TOGGLE ACTIVE
========================= */
if (isset($_POST["toggle_status"])) {
  $token = $_POST["csrf_token"] ?? "";
  if (!hash_equals($_SESSION["csrf_token"] ?? "", $token)) {
    $err = "CSRF token ไม่ถูกต้อง";
  } else {
    $user_id   = (int)($_POST["user_id"] ?? 0);
    $newStatus = (int)($_POST["new_status"] ?? 0);

    if ($user_id > 0) {
      $stmt = $pdo->prepare("
        UPDATE users
        SET is_active = ?
        WHERE user_id = ?
          AND role = 'owner'
        LIMIT 1
      ");
      $stmt->execute([$newStatus === 1 ? 1 : 0, $user_id]);

      header("Location: admin-shop-accounts.php?ok=status");
      exit;
    }
  }
}

/* =========================
   flash
========================= */
if (($_GET["ok"] ?? "") === "created") $ok = "สร้างบัญชีร้านค้าเรียบร้อย";
if (($_GET["ok"] ?? "") === "updated") $ok = "แก้ไขบัญชีร้านค้าเรียบร้อย";
if (($_GET["ok"] ?? "") === "reset")   $ok = "รีเซ็ตรหัสผ่านเรียบร้อย";
if (($_GET["ok"] ?? "") === "status")  $ok = "อัปเดตสถานะบัญชีเรียบร้อย";

/* =========================
   filters
========================= */
$q = trim($_GET["q"] ?? "");
$filter_dome = (int)($_GET["filter_dome"] ?? 0);
$filter_lock = (int)($_GET["filter_lock"] ?? 0);
$filter_shop_id = (int)($_GET["shop_id"] ?? 0);
$account_status = $_GET["account_status"] ?? "all";
$params = [];

/* =========================
   domes
========================= */
$domes = $pdo->query("
  SELECT dome_id, dome_name
  FROM domes
  ORDER BY dome_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   locks for filter
========================= */
$sqlLocks = "
  SELECT l.lock_id, l.lock_no, d.dome_name
  FROM locks l
  LEFT JOIN domes d ON d.dome_id = l.dome_id
  WHERE 1=1
";
$lockParams = [];

if ($filter_dome > 0) {
  $sqlLocks .= " AND l.dome_id = ? ";
  $lockParams[] = $filter_dome;
}

$sqlLocks .= " ORDER BY l.lock_no ASC, l.lock_id ASC ";
$stmt = $pdo->prepare($sqlLocks);
$stmt->execute($lockParams);
$locks = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   shops for create form
========================= */
$sqlShopList = "
  SELECT
    s.shop_id,
    s.name AS shop_name,
    s.status AS shop_status,
    l.lock_id,
    l.lock_no,
    d.dome_id,
    d.dome_name
  FROM shops s
  LEFT JOIN locks l ON l.lock_id = s.lock_id
  LEFT JOIN domes d ON d.dome_id = l.dome_id
  WHERE 1=1
";
$shopListParams = [];

if ($filter_dome > 0) {
  $sqlShopList .= " AND d.dome_id = ? ";
  $shopListParams[] = $filter_dome;
}
if ($filter_lock > 0) {
  $sqlShopList .= " AND l.lock_id = ? ";
  $shopListParams[] = $filter_lock;
}
if ($filter_shop_id > 0) {
  $sqlShopList .= " AND s.shop_id = ? ";
  $shopListParams[] = $filter_shop_id;
}

$sqlShopList .= " ORDER BY d.dome_id ASC, l.lock_no ASC, s.name ASC ";
$stmt = $pdo->prepare($sqlShopList);
$stmt->execute($shopListParams);
$shopsForCreate = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   KPI
========================= */
$kpiWhere = " WHERE 1=1 ";
$kpiParams = [];

if ($filter_dome > 0) {
  $kpiWhere .= " AND d.dome_id = ? ";
  $kpiParams[] = $filter_dome;
}
if ($filter_lock > 0) {
  $kpiWhere .= " AND l.lock_id = ? ";
  $kpiParams[] = $filter_lock;
}
if ($filter_shop_id > 0) {
  $kpiWhere .= " AND s.shop_id = ? ";
  $kpiParams[] = $filter_shop_id;
}

$stmt = $pdo->prepare("
  SELECT COUNT(DISTINCT s.shop_id)
  FROM shops s
  LEFT JOIN locks l ON l.lock_id = s.lock_id
  LEFT JOIN domes d ON d.dome_id = l.dome_id
  $kpiWhere
");
$stmt->execute($kpiParams);
$totalShops = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
  SELECT COUNT(DISTINCT s.shop_id)
  FROM shops s
  LEFT JOIN locks l ON l.lock_id = s.lock_id
  LEFT JOIN domes d ON d.dome_id = l.dome_id
  INNER JOIN users u
    ON u.shop_id = s.shop_id
   AND u.role = 'owner'
  $kpiWhere
");
$stmt->execute($kpiParams);
$hasAccount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
  SELECT COUNT(DISTINCT s.shop_id)
  FROM shops s
  LEFT JOIN locks l ON l.lock_id = s.lock_id
  LEFT JOIN domes d ON d.dome_id = l.dome_id
  INNER JOIN users u
    ON u.shop_id = s.shop_id
   AND u.role = 'owner'
   AND u.is_active = 0
  $kpiWhere
");
$stmt->execute($kpiParams);
$inactiveAccount = (int)$stmt->fetchColumn();

$noAccount = max(0, $totalShops - $hasAccount);

/* =========================
   main table
========================= */
$sql = "
  SELECT
    s.shop_id,
    s.name AS shop_name,
    s.status AS shop_status,
    l.lock_id,
    l.lock_no,
    d.dome_id,
    d.dome_name,
    u.user_id,
    u.full_name,
    u.username,
    u.is_active,
    u.created_at
";

if ($hasUpdatedAt) {
  $sql .= ", u.updated_at ";
} else {
  $sql .= ", NULL AS updated_at ";
}

if ($hasLastLogin) {
  $sql .= ", u.last_login_at ";
} else {
  $sql .= ", NULL AS last_login_at ";
}

$sql .= "
  FROM shops s
  LEFT JOIN locks l
    ON l.lock_id = s.lock_id
  LEFT JOIN domes d
    ON d.dome_id = l.dome_id
  LEFT JOIN users u
    ON u.shop_id = s.shop_id
   AND u.role = 'owner'
  WHERE 1=1
";

if ($filter_dome > 0) {
  $sql .= " AND d.dome_id = ? ";
  $params[] = $filter_dome;
}
if ($filter_lock > 0) {
  $sql .= " AND l.lock_id = ? ";
  $params[] = $filter_lock;
}
if ($filter_shop_id > 0) {
  $sql .= " AND s.shop_id = ? ";
  $params[] = $filter_shop_id;
}
if ($q !== "") {
  $sql .= " AND (
    s.name LIKE ?
    OR u.username LIKE ?
    OR u.full_name LIKE ?
    OR d.dome_name LIKE ?
    OR CAST(l.lock_no AS CHAR) LIKE ?
  ) ";
  $params[] = "%$q%";
  $params[] = "%$q%";
  $params[] = "%$q%";
  $params[] = "%$q%";
  $params[] = "%$q%";
}

if ($account_status === "has") {
  $sql .= " AND u.user_id IS NOT NULL ";
} elseif ($account_status === "none") {
  $sql .= " AND u.user_id IS NULL ";
} elseif ($account_status === "active") {
  $sql .= " AND u.user_id IS NOT NULL AND u.is_active = 1 ";
} elseif ($account_status === "inactive") {
  $sql .= " AND u.user_id IS NOT NULL AND u.is_active = 0 ";
}

$sql .= "
  ORDER BY d.dome_id ASC, l.lock_no ASC, s.name ASC, s.shop_id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   active labels
========================= */
$activeDomeName = "";
if ($filter_dome > 0) {
  $stmt = $pdo->prepare("SELECT dome_name FROM domes WHERE dome_id = ? LIMIT 1");
  $stmt->execute([$filter_dome]);
  $activeDomeName = (string)($stmt->fetchColumn() ?: "");
}

$activeLockNo = "";
if ($filter_lock > 0) {
  $stmt = $pdo->prepare("SELECT lock_no FROM locks WHERE lock_id = ? LIMIT 1");
  $stmt->execute([$filter_lock]);
  $activeLockNo = (string)($stmt->fetchColumn() ?: "");
}

$activeShopName = "";
if ($filter_shop_id > 0) {
  $stmt = $pdo->prepare("SELECT name FROM shops WHERE shop_id = ? LIMIT 1");
  $stmt->execute([$filter_shop_id]);
  $activeShopName = (string)($stmt->fetchColumn() ?: "");
}

$selectedShopId = $filter_shop_id > 0 ? $filter_shop_id : 0;
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>จัดการบัญชีร้านค้า | Admin</title>
  <style>
    :root { --bg:#f6f7fb; --card:#fff; --text:#111827; --muted:#6b7280; --primary:#2563eb; --border:#e5e7eb; --ok:#16a34a; --bad:#dc2626; --warn:#f59e0b; }
    *{ box-sizing:border-box; }
    html{ scroll-behavior:smooth; }
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
    .btn.warn{ border-color:rgba(245,158,11,.35); color:#92400e; background:#fff; }
    .btn.danger{ border-color:rgba(220,38,38,.35); color:var(--bad); background:#fff; }
    .btn.success{ border-color:rgba(22,163,74,.35); color:#166534; background:#fff; }

    .grid{ display:grid; grid-template-columns:repeat(12, 1fr); gap:12px; margin-top:12px; }
    .card{ grid-column:span 12; background:var(--card); border:1px solid var(--border); border-radius:16px; padding:14px; box-shadow:0 8px 20px rgba(0,0,0,.05); }

    .kpi{ display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
    .kpi .box{ border:1px solid var(--border); border-radius:16px; padding:14px; background:#fff; }
    .kpi .label{ color:var(--muted); font-size:13px; }
    .kpi .value{ font-size:26px; font-weight:900; margin-top:6px; }

    .row{ display:flex; gap:12px; flex-wrap:wrap; align-items:end; }
    .field{ display:flex; flex-direction:column; gap:6px; min-width:220px; flex:1; }
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
    .alert.note{ background:rgba(37,99,235,.08); border-color:rgba(37,99,235,.20); color:#1e3a8a; }
    .alert.pass{ background:rgba(245,158,11,.10); border-color:rgba(245,158,11,.25); color:#7c2d12; }

    .table-wrap{ overflow:auto; border-radius:14px; border:1px solid var(--border); background:#fff; }
    table{ width:100%; min-width:1480px; border-collapse:separate; border-spacing:0; }
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
    .badge.active{
      color:#0c7a32;
      border-color:rgba(22,163,74,.35);
      background:rgba(22,163,74,.08);
    }
    .badge.inactive{
      color:#7f1d1d;
      border-color:rgba(239,68,68,.30);
      background:rgba(239,68,68,.08);
    }
    .badge.none{
      color:#8a5a00;
      border-color:rgba(245,158,11,.35);
      background:rgba(245,158,11,.08);
    }
    .badge.shop-open{
      color:#0c7a32;
      border-color:rgba(22,163,74,.35);
      background:rgba(22,163,74,.08);
    }
    .badge.shop-closed{
      color:#7f1d1d;
      border-color:rgba(239,68,68,.30);
      background:rgba(239,68,68,.08);
    }
    .badge.shop-break{
      color:#92400e;
      border-color:rgba(245,158,11,.35);
      background:rgba(245,158,11,.10);
    }
    .badge.shop-full{
      color:#9a3412;
      border-color:rgba(249,115,22,.35);
      background:rgba(249,115,22,.10);
    }

    .muted{ color:var(--muted); font-size:13px; }
    .mono{ font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .copy-box{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      align-items:center;
      margin-top:8px;
    }
    .copy-code{
      padding:10px 12px;
      border-radius:12px;
      background:#fff;
      border:1px dashed rgba(124,45,18,.35);
      font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size:15px;
      font-weight:700;
    }
    tr.selected-row td{
      background:rgba(37,99,235,.06);
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
      width:min(860px, 100%);
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
      grid-template-columns:repeat(2, minmax(0,1fr));
      gap:12px;
    }
    .form-grid .full{
      grid-column:1 / -1;
    }

    @media (max-width: 1100px){
      .sidebar{ display:none; }
      .field{ min-width:220px; }
      .kpi{ grid-template-columns:1fr 1fr; }
    }

    @media (max-width: 700px){
      .form-grid{ grid-template-columns:1fr; }
    }

    @media (max-width: 680px){
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
        <a href="admin-shop-types.php">🧩 ประเภทร้าน</a>
        <a href="admin-history.php">🧾 ประวัติคิว</a>
        <a href="admin-reports.php">📈 รายงาน</a>
        <a class="active" href="admin-shop-accounts.php">🔐 บัญชีร้านค้า</a>
        <a href="../logout.php">🚪 ออกจากระบบ</a>
        <a href="../Frontend/index.php">↩ กลับหน้าเว็บ</a>
      </nav>

      <div style="margin-top:14px;">
        <div style="color:rgba(255,255,255,.75); font-size:13px;">
          * บัญชีร้านค้าในระบบนี้ผูกกับร้าน และแสดงระดับโดม/ล็อก/ร้าน
        </div>
      </div>
    </aside>

    <main class="main">
      <div class="topbar">
        <div>
          <h1>🔐 จัดการบัญชีร้านค้า</h1>
          <div class="sub">ตารางหลัก: <b>users</b> (เฉพาะ role = owner)</div>

          <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
            <?php if($filter_dome > 0 && $activeDomeName !== ""): ?>
              <span class="badge active">โดม: <?= h($activeDomeName) ?></span>
            <?php endif; ?>
            <?php if($filter_lock > 0 && $activeLockNo !== ""): ?>
              <span class="badge active">ล็อก: <?= h($activeLockNo) ?></span>
            <?php endif; ?>
            <?php if($selectedShopId > 0 && $activeShopName !== ""): ?>
              <span class="badge active">ร้าน: <?= h($activeShopName) ?> (ID <?= (int)$selectedShopId ?>)</span>
            <?php elseif($selectedShopId > 0): ?>
              <span class="badge active">กำลังเลือกร้าน ID: <?= (int)$selectedShopId ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
          <button class="btn" onclick="location.reload()">รีเฟรช</button>
          <a class="btn primary" href="admin-shops.php<?= $selectedShopId > 0 ? '?shop_id='.(int)$selectedShopId : '' ?>">ไปหน้าจัดการร้าน</a>
        </div>
      </div>

      <div class="grid">

        <?php if($ok): ?>
          <section class="card alert ok">
            ✅ <?= h($ok) ?>
          </section>
        <?php endif; ?>

        <?php if($tempPasswordFlash !== ""): ?>
          <section class="card alert pass">
            <div><b>รหัสผ่านใหม่ล่าสุดสำหรับแจ้งร้านค้า</b></div>
            <div style="margin-top:6px;">ร้าน: <b><?= h($tempShopNameFlash ?: "-") ?></b></div>
            <div style="margin-top:4px;">Username: <b class="mono"><?= h($tempUsernameFlash ?: "-") ?></b></div>
            <div class="copy-box">
              <div class="copy-code" id="latest-password"><?= h($tempPasswordFlash) ?></div>
              <button class="btn" type="button" onclick="copyLatestPassword()">คัดลอกรหัส</button>
            </div>
            <div class="muted" style="margin-top:8px;">
              ระบบไม่สามารถแสดงรหัสผ่านเดิมได้ หากร้านลืมรหัส ต้องใช้การรีเซ็ตแล้วแจ้งรหัสใหม่ให้ร้านค้าแทน
            </div>
          </section>
        <?php endif; ?>

        <?php if($err): ?>
          <section class="card alert err">⚠️ <?= h($err) ?></section>
        <?php endif; ?>

        <section class="card alert note">
          <b>แนวคิดของหน้านี้:</b> บัญชีร้านค้าต้องผูกกับ <b>ร้านที่อยู่ในล็อก</b> ไม่ใช่แค่เลือกโดมอย่างเดียว และเพื่อความปลอดภัย <b>แอดมินไม่สามารถดูรหัสผ่านเดิมได้</b> แต่สามารถรีเซ็ตรหัสใหม่ให้ร้านได้ทันที
        </section>

        <section class="card">
          <div class="kpi">
            <div class="box">
              <div class="label">ร้านทั้งหมด<?= ($filter_dome > 0 || $filter_lock > 0 || $filter_shop_id > 0) ? " (ตามตัวกรอง)" : "" ?></div>
              <div class="value"><?= (int)$totalShops ?></div>
            </div>
            <div class="box">
              <div class="label">ร้านที่มีบัญชีแล้ว</div>
              <div class="value"><?= (int)$hasAccount ?></div>
            </div>
            <div class="box">
              <div class="label">ร้านที่ยังไม่มีบัญชี</div>
              <div class="value"><?= (int)$noAccount ?></div>
            </div>
            <div class="box">
              <div class="label">บัญชีที่ปิดใช้งาน</div>
              <div class="value"><?= (int)$inactiveAccount ?></div>
            </div>
          </div>
        </section>

        <section class="card">
          <form method="get" class="row">
            <div class="field" style="max-width:360px;">
              <label>ค้นหาร้าน / ผู้ใช้ / ชื่อเจ้าของ / โดม / ล็อก</label>
              <input name="q" value="<?= h($q) ?>" placeholder="เช่น coffee, owner01, ล็อก 12">
            </div>

            <div class="field" style="max-width:220px;">
              <label>เลือกโดม</label>
              <select name="filter_dome" onchange="this.form.submit()">
                <option value="0">ทุกโดม</option>
                <?php foreach($domes as $d): ?>
                  <option value="<?= (int)$d["dome_id"] ?>" <?= $filter_dome === (int)$d["dome_id"] ? "selected" : "" ?>>
                    <?= h($d["dome_name"]) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field" style="max-width:220px;">
              <label>เลือกล็อก</label>
              <select name="filter_lock">
                <option value="0">ทุกล็อก</option>
                <?php foreach($locks as $lk): ?>
                  <option value="<?= (int)$lk["lock_id"] ?>" <?= $filter_lock === (int)$lk["lock_id"] ? "selected" : "" ?>>
                    ล็อก <?= (int)$lk["lock_no"] ?><?= $lk["dome_name"] ? " • " . h($lk["dome_name"]) : "" ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field" style="max-width:220px;">
              <label>สถานะบัญชี</label>
              <select name="account_status">
                <option value="all" <?= $account_status === "all" ? "selected" : "" ?>>ทั้งหมด</option>
                <option value="has" <?= $account_status === "has" ? "selected" : "" ?>>มีบัญชีแล้ว</option>
                <option value="none" <?= $account_status === "none" ? "selected" : "" ?>>ยังไม่มีบัญชี</option>
                <option value="active" <?= $account_status === "active" ? "selected" : "" ?>>บัญชีใช้งาน</option>
                <option value="inactive" <?= $account_status === "inactive" ? "selected" : "" ?>>บัญชีปิดใช้งาน</option>
              </select>
            </div>

            <?php if($filter_shop_id > 0): ?>
              <input type="hidden" name="shop_id" value="<?= (int)$filter_shop_id ?>">
            <?php endif; ?>

            <div class="right-actions">
              <button class="btn" type="submit">🔎 ค้นหา</button>
              <a class="btn" href="admin-shop-accounts.php">↩ ล้าง</a>
            </div>
          </form>
        </section>

        <section class="card">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th style="width:80px;">Shop ID</th>
                  <th style="width:220px;">ร้านค้า</th>
                  <th style="width:140px;">โดม / ล็อก</th>
                  <th style="width:120px;">สถานะร้าน</th>
                  <th style="width:180px;">ชื่อเจ้าของ/ผู้ใช้</th>
                  <th style="width:150px;">Username</th>
                  <th style="width:120px;">สถานะบัญชี</th>
                  <th style="width:170px;">สร้างบัญชีเมื่อ</th>
                  <th style="width:170px;">ใช้งานล่าสุด</th>
                  <th style="width:460px;">จัดการ</th>
                </tr>
              </thead>
              <tbody>
                <?php if(!$rows): ?>
                  <tr><td colspan="10" style="color:var(--muted);">ไม่พบข้อมูล</td></tr>
                <?php else: ?>
                  <?php foreach($rows as $r): ?>
                    <?php
                      $shopBadgeClass = match($r["shop_status"]){
                        "open" => "shop-open",
                        "closed" => "shop-closed",
                        "break" => "shop-break",
                        "full" => "shop-full",
                        default => "none"
                      };
                      $rowClass = ((int)$selectedShopId > 0 && (int)$selectedShopId === (int)$r["shop_id"]) ? "selected-row" : "";
                    ?>
                    <tr class="<?= $rowClass ?>">
                      <td><?= (int)$r["shop_id"] ?></td>

                      <td>
                        <div style="font-weight:800;"><?= h($r["shop_name"]) ?></div>
                        <?php if((int)$selectedShopId === (int)$r["shop_id"]): ?>
                          <div style="margin-top:6px;"><span class="badge active">ร้านที่เลือกอยู่</span></div>
                        <?php endif; ?>
                      </td>

                      <td>
                        <?= h($r["dome_name"] ?: "-") ?><br>
                        <span class="muted">ล็อก <?= $r["lock_no"] !== null ? (int)$r["lock_no"] : "-" ?></span>
                      </td>

                      <td>
                        <span class="badge <?= h($shopBadgeClass) ?>">
                          <?= h(shopStatusLabelTH($r["shop_status"])) ?>
                        </span>
                      </td>

                      <td>
                        <?php if(!empty($r["user_id"])): ?>
                          <?= h($r["full_name"]) ?>
                        <?php else: ?>
                          <span class="muted">-</span>
                        <?php endif; ?>
                      </td>

                      <td>
                        <?php if(!empty($r["user_id"])): ?>
                          <span class="mono"><?= h($r["username"]) ?></span>
                        <?php else: ?>
                          <span class="muted">-</span>
                        <?php endif; ?>
                      </td>

                      <td>
                        <span class="badge <?= h(accountBadgeClass($r)) ?>">
                          <?= h(accountBadgeText($r)) ?>
                        </span>
                      </td>

                      <td class="muted">
                        <?= !empty($r["created_at"]) ? h($r["created_at"]) : "-" ?>
                      </td>

                      <td class="muted">
                        <?= !empty($r["last_login_at"]) ? h($r["last_login_at"]) : "-" ?>
                      </td>

                      <td>
                        <div class="manage">
                          <?php if(empty($r["user_id"])): ?>
                            <button
                              type="button"
                              class="btn"
                              onclick='openCreateModal(<?= json_encode([
                                "shop_id"   => (int)$r["shop_id"],
                                "shop_name" => (string)$r["shop_name"],
                                "dome_name" => (string)($r["dome_name"] ?? ""),
                                "lock_no"   => $r["lock_no"] !== null ? (int)$r["lock_no"] : null
                              ], JSON_UNESCAPED_UNICODE) ?>)'>
                              ➕ สร้างบัญชีร้านนี้
                            </button>
                          <?php else: ?>
                            <button
                              type="button"
                              class="btn"
                              onclick='openEditModal(<?= json_encode([
                                "user_id"   => (int)$r["user_id"],
                                "shop_id"   => (int)$r["shop_id"],
                                "shop_name" => (string)$r["shop_name"],
                                "dome_name" => (string)($r["dome_name"] ?? ""),
                                "lock_no"   => $r["lock_no"] !== null ? (int)$r["lock_no"] : null,
                                "full_name" => (string)($r["full_name"] ?? ""),
                                "username"  => (string)($r["username"] ?? "")
                              ], JSON_UNESCAPED_UNICODE) ?>)'>
                              ✏️ แก้ไข
                            </button>

                            <form method="post" style="margin:0;" onsubmit="return confirm('ยืนยันรีเซ็ตรหัสผ่านอัตโนมัติสำหรับร้านนี้?');">
                              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                              <input type="hidden" name="user_id" value="<?= (int)$r["user_id"] ?>">
                              <input type="hidden" name="reset_mode" value="auto">
                              <button class="btn warn" type="submit" name="reset_password">♻ รีเซ็ตอัตโนมัติ</button>
                            </form>

                            <?php if((int)$r["is_active"] === 1): ?>
                              <form method="post" style="margin:0;" onsubmit="return confirm('ยืนยันปิดการใช้งานบัญชีนี้?');">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$r["user_id"] ?>">
                                <input type="hidden" name="new_status" value="0">
                                <button class="btn danger" type="submit" name="toggle_status">🔒 ปิดบัญชี</button>
                              </form>
                            <?php else: ?>
                              <form method="post" style="margin:0;" onsubmit="return confirm('ยืนยันเปิดใช้งานบัญชีนี้?');">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$r["user_id"] ?>">
                                <input type="hidden" name="new_status" value="1">
                                <button class="btn success" type="submit" name="toggle_status">🔓 เปิดบัญชี</button>
                              </form>
                            <?php endif; ?>

                            <a class="btn" href="admin-shops.php?shop_id=<?= (int)$r["shop_id"] ?>">🏪 ดูร้าน</a>
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

      </div>
    </main>
  </div>

  <div class="modal" id="accountModal">
    <div class="modal-box">
      <div class="modal-head">
        <div>
          <h3 class="modal-title" id="accountModalTitle">จัดการบัญชีร้านค้า</h3>
          <div class="modal-sub" id="accountModalSub">กรอกข้อมูลให้ครบแล้วบันทึก</div>
        </div>
        <button type="button" class="btn" onclick="closeAccountModal()">ปิด</button>
      </div>

      <form method="post" id="createAccountForm" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="shop_id" id="create_shop_id">

        <div class="form-grid">
          <div class="field full">
            <label>ร้านค้า</label>
            <input type="text" id="create_shop_label" disabled>
          </div>

          <div class="field">
            <label>ชื่อเจ้าของร้าน / ผู้ใช้งาน</label>
            <input type="text" name="full_name" id="create_full_name" maxlength="100" required>
          </div>

          <div class="field">
            <label>Username</label>
            <input type="text" name="username" id="create_username" maxlength="50" required>
          </div>

          <div class="field">
            <label>รหัสผ่าน</label>
            <input type="text" name="password" minlength="8" required>
          </div>

          <div class="field">
            <label>ยืนยันรหัสผ่าน</label>
            <input type="text" name="confirm_password" minlength="8" required>
          </div>
        </div>

        <div class="modal-actions">
          <button type="button" class="btn" onclick="closeAccountModal()">ยกเลิก</button>
          <button type="submit" name="create_account" class="btn primary">➕ สร้างบัญชี</button>
        </div>
      </form>

      <form method="post" id="editAccountForm" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="user_id" id="edit_user_id">
        <input type="hidden" name="reset_mode" id="edit_reset_mode" value="manual">

        <div class="form-grid">
          <div class="field full">
            <label>ร้านค้า</label>
            <input type="text" id="edit_shop_label" disabled>
          </div>

          <div class="field">
            <label>ชื่อเจ้าของร้าน / ผู้ใช้งาน</label>
            <input type="text" name="full_name" id="edit_full_name" maxlength="100" required>
          </div>

          <div class="field">
            <label>Username</label>
            <input type="text" name="username" id="edit_username" maxlength="50" required>
          </div>

          <div class="field">
            <label>รหัสผ่านใหม่</label>
            <input type="text" name="new_password" id="edit_new_password" minlength="8" placeholder="กรอกเมื่อต้องการรีเซ็ต">
          </div>

          <div class="field">
            <label>ยืนยันรหัสผ่านใหม่</label>
            <input type="text" name="confirm_new_password" id="edit_confirm_new_password" minlength="8" placeholder="กรอกเมื่อต้องการรีเซ็ต">
          </div>
        </div>

        <div class="modal-actions">
          <button type="button" class="btn" onclick="closeAccountModal()">ยกเลิก</button>
          <button type="submit" name="update_account" class="btn primary">💾 บันทึกข้อมูลบัญชี</button>
          <button type="submit" name="reset_password" class="btn warn" onclick="return prepareResetMode()">♻ รีเซ็ตรหัสผ่าน</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function copyLatestPassword() {
      const el = document.getElementById('latest-password');
      if (!el) return;
      const text = el.textContent || '';
      navigator.clipboard.writeText(text).then(() => {
        alert('คัดลอกรหัสผ่านแล้ว');
      }).catch(() => {
        alert('คัดลอกไม่สำเร็จ กรุณาคัดลอกด้วยตนเอง');
      });
    }

    const accountModal = document.getElementById('accountModal');
    const createForm = document.getElementById('createAccountForm');
    const editForm = document.getElementById('editAccountForm');
    const modalTitle = document.getElementById('accountModalTitle');
    const modalSub = document.getElementById('accountModalSub');

    function openAccountModal(){
      accountModal.classList.add('show');
      document.body.style.overflow = 'hidden';
    }

    function closeAccountModal(){
      accountModal.classList.remove('show');
      document.body.style.overflow = '';
      resetAccountModalForms();
    }

    function resetAccountModalForms(){
      createForm.style.display = 'none';
      editForm.style.display = 'none';

      createForm.reset();
      editForm.reset();

      document.getElementById('create_shop_id').value = '';
      document.getElementById('create_shop_label').value = '';
      document.getElementById('create_full_name').value = '';
      document.getElementById('create_username').value = '';

      document.getElementById('edit_user_id').value = '';
      document.getElementById('edit_shop_label').value = '';
      document.getElementById('edit_full_name').value = '';
      document.getElementById('edit_username').value = '';
      document.getElementById('edit_new_password').value = '';
      document.getElementById('edit_confirm_new_password').value = '';
    }

    function shopLabel(data){
      let text = data.shop_name || '';
      if (data.dome_name) text += ' • ' + data.dome_name;
      if (data.lock_no !== null && data.lock_no !== undefined && data.lock_no !== '') {
        text += ' / ล็อก ' + data.lock_no;
      }
      return text;
    }

    function openCreateModal(data){
      modalTitle.textContent = 'สร้างบัญชีร้านค้า';
      modalSub.textContent = 'เพิ่มบัญชี owner ให้ร้านที่เลือก';
      createForm.style.display = 'block';
      editForm.style.display = 'none';

      document.getElementById('create_shop_id').value = data.shop_id || '';
      document.getElementById('create_shop_label').value = shopLabel(data);
      document.getElementById('create_full_name').value = data.shop_name || '';
      document.getElementById('create_username').value = '';

      openAccountModal();
    }

    function openEditModal(data){
      modalTitle.textContent = 'แก้ไขบัญชีร้านค้า';
      modalSub.textContent = 'แก้ไขข้อมูลบัญชีของร้านที่เลือก';
      createForm.style.display = 'none';
      editForm.style.display = 'block';

      document.getElementById('edit_user_id').value = data.user_id || '';
      document.getElementById('edit_shop_label').value = shopLabel(data);
      document.getElementById('edit_full_name').value = data.full_name || '';
      document.getElementById('edit_username').value = data.username || '';
      document.getElementById('edit_new_password').value = '';
      document.getElementById('edit_confirm_new_password').value = '';

      openAccountModal();
    }

    function prepareResetMode(){
      const p1 = document.getElementById('edit_new_password').value.trim();
      const p2 = document.getElementById('edit_confirm_new_password').value.trim();

      if (!p1 || !p2) {
        alert('กรุณากรอกรหัสผ่านใหม่และยืนยันรหัสผ่านก่อนรีเซ็ต');
        return false;
      }

      document.getElementById('edit_reset_mode').value = 'manual';
      return true;
    }

    accountModal.addEventListener('click', function(e){
      if (e.target === accountModal) {
        closeAccountModal();
      }
    });

    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && accountModal.classList.contains('show')) {
        closeAccountModal();
      }
    });
  </script>
</body>
</html>