<?php
require_once __DIR__ . "/_auth.php";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

$current_page = basename($_SERVER['PHP_SELF']);

/* =========================
   helper: detect column
========================= */
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
function hasColumn(array $cols, string $column): bool {
  foreach($cols as $c){
    if (($c["Field"] ?? "") === $column) return true;
  }
  return false;
}

/* =========================
   upload helper
========================= */
function saveUploadedMenuImage(array $file): array {
  if (($file["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    return ["ok" => true, "path" => null];
  }

  if (($file["error"] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    return ["ok" => false, "error" => "อัปโหลดรูปไม่สำเร็จ (error code: ".$file["error"].")"];
  }

  if (!is_uploaded_file($file["tmp_name"])) {
    return ["ok" => false, "error" => "ไฟล์อัปโหลดไม่ถูกต้อง"];
  }

  $maxSize = 5 * 1024 * 1024; // 5MB
  if (($file["size"] ?? 0) > $maxSize) {
    return ["ok" => false, "error" => "รูปภาพต้องมีขนาดไม่เกิน 5MB"];
  }

  $ext = strtolower(pathinfo($file["name"] ?? "", PATHINFO_EXTENSION));
  $allowedExt = ["jpg","jpeg","png","gif","webp"];

  if (!in_array($ext, $allowedExt, true)) {
    return ["ok" => false, "error" => "อนุญาตเฉพาะไฟล์ jpg, jpeg, png, gif, webp"];
  }

  $imgInfo = @getimagesize($file["tmp_name"]);
  if ($imgInfo === false) {
    return ["ok" => false, "error" => "ไฟล์ที่อัปโหลดไม่ใช่รูปภาพ"];
  }

  $uploadDirFs = realpath(__DIR__ . "/..");
  if ($uploadDirFs === false) {
    return ["ok" => false, "error" => "หาโฟลเดอร์โปรเจกต์ไม่พบ"];
  }

  $uploadDirFs .= DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "menu";

  if (!is_dir($uploadDirFs)) {
    if (!mkdir($uploadDirFs, 0777, true) && !is_dir($uploadDirFs)) {
      return ["ok" => false, "error" => "สร้างโฟลเดอร์อัปโหลดไม่สำเร็จ: ".$uploadDirFs];
    }
  }

  $newName = "menu_" . date("Ymd_His") . "_" . bin2hex(random_bytes(4)) . "." . $ext;
  $targetFs = $uploadDirFs . DIRECTORY_SEPARATOR . $newName;

  if (!move_uploaded_file($file["tmp_name"], $targetFs)) {
    return ["ok" => false, "error" => "ย้ายไฟล์อัปโหลดไม่สำเร็จ"];
  }

  // ใช้ path แบบ relative ให้หน้า admin / owner / frontend เรียกใช้ได้ง่าย
  $relativePath = "../uploads/menu/" . $newName;

  return ["ok" => true, "path" => $relativePath];
}

/* =========================
   detect table / columns
========================= */
$menuTable = "menu_items";
$menuCols = getColumns($pdo, $menuTable);

$idCol          = pickColumn($menuCols, ["item_id","menu_id","id"]);
$shopIdCol      = pickColumn($menuCols, ["shop_id"]);
$nameCol        = pickColumn($menuCols, ["item_name","name","menu_name"]);
$priceCol       = pickColumn($menuCols, ["price","item_price"]);
$priceMinCol    = pickColumn($menuCols, ["price_min","min_price"]);
$priceMaxCol    = pickColumn($menuCols, ["price_max","max_price"]);
$descCol        = pickColumn($menuCols, ["description","detail","item_desc","menu_desc"]);
$imageCol       = pickColumn($menuCols, ["image","image_url","photo","photo_url","item_image"]);
$availableCol   = pickColumn($menuCols, ["is_available","available","status","is_active"]);
$createdAtCol   = pickColumn($menuCols, ["created_at"]);
$updatedAtCol   = pickColumn($menuCols, ["updated_at"]);

if (!$idCol || !$shopIdCol || !$nameCol) {
  die("ตาราง menu_items ต้องมีอย่างน้อยคอลัมน์ item_id/menu_id, shop_id และ item_name/name");
}

if (!$priceCol && !$priceMinCol && !$priceMaxCol) {
  die("ตาราง menu_items ต้องมี price หรือ price_min/price_max อย่างน้อยหนึ่งแบบ");
}

/* =========================
   shop_id + shop info
========================= */
$shop_id = (int)($_GET["shop_id"] ?? $_POST["shop_id"] ?? 0);
if ($shop_id <= 0) {
  http_response_code(400);
  exit("กรุณาเปิดแบบนี้: admin-menu.php?shop_id=1");
}

$shopStmt = $pdo->prepare("
  SELECT s.shop_id, s.name, s.status, s.open_time, s.close_time,
         l.dome_id, l.lock_no,
         t.type_name, c.category_name
  FROM shops s
  LEFT JOIN locks l ON l.lock_id = s.lock_id
  LEFT JOIN shop_types t ON t.type_id = s.type_id
  LEFT JOIN shop_categories c ON c.category_id = t.category_id
  WHERE s.shop_id = ?
  LIMIT 1
");
$shopStmt->execute([$shop_id]);
$shop = $shopStmt->fetch(PDO::FETCH_ASSOC);

if (!$shop) {
  http_response_code(404);
  exit("ไม่พบร้าน shop_id=".$shop_id);
}

/* =========================
   helper badge
========================= */
function shopStatusLabelTH($status){
  return match($status){
    "open"   => "🟢 เปิด",
    "closed" => "🔴 ปิด",
    "break"  => "🟡 พัก",
    "full"   => "🟠 คิวเต็ม",
    default  => $status ?: "-"
  };
}

/* =========================
   available parse helpers
========================= */
function isBinaryLikeColumn(array $cols, ?string $field): bool {
  if (!$field) return false;
  foreach ($cols as $c) {
    if (($c["Field"] ?? "") === $field) {
      $type = strtolower((string)($c["Type"] ?? ""));
      return str_contains($type, "tinyint") || str_contains($type, "int") || str_contains($type, "bit");
    }
  }
  return false;
}

$availableIsBinary = isBinaryLikeColumn($menuCols, $availableCol);

function normalizeAvailableValue($raw, bool $isBinary): int {
  if ($raw === null || $raw === "") return 1;
  if ($isBinary) {
    return ((string)$raw === "0") ? 0 : 1;
  }
  $raw = strtolower((string)$raw);
  return in_array($raw, ["inactive","hidden","off","0","unavailable"], true) ? 0 : 1;
}

function availableText($raw, bool $isBinary): string {
  return normalizeAvailableValue($raw, $isBinary) === 1 ? "พร้อมขาย" : "ไม่พร้อมขาย";
}

/* =========================
   price format helper
========================= */
function parsePriceOrNull($value): ?float {
  $value = trim((string)$value);
  if ($value === "") return null;
  if (!is_numeric($value)) return null;
  $num = (float)$value;
  if ($num < 0) return null;
  return $num;
}

function renderPriceText($row): string {
  $price    = isset($row["price"]) && $row["price"] !== null && $row["price"] !== "" ? (float)$row["price"] : null;
  $priceMin = isset($row["price_min"]) && $row["price_min"] !== null && $row["price_min"] !== "" ? (float)$row["price_min"] : null;
  $priceMax = isset($row["price_max"]) && $row["price_max"] !== null && $row["price_max"] !== "" ? (float)$row["price_max"] : null;

  if ($priceMin !== null && $priceMax !== null) {
    if ($priceMin == $priceMax) {
      return number_format($priceMin, 2);
    }
    return number_format($priceMin, 2) . " - " . number_format($priceMax, 2);
  }

  if ($price !== null) {
    return number_format($price, 2);
  }

  if ($priceMin !== null) {
    return "เริ่ม " . number_format($priceMin, 2);
  }

  if ($priceMax !== null) {
    return "ไม่เกิน " . number_format($priceMax, 2);
  }

  return "-";
}

/* =========================
   actions
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = $_POST["action"] ?? "";

  if ($action === "save") {
    $mode = $_POST["mode"] ?? "add";
    $menu_id = (int)($_POST["menu_id"] ?? 0);

    $item_name    = trim((string)($_POST["item_name"] ?? ""));
    $priceInput   = trim((string)($_POST["price"] ?? ""));
    $priceMinInput= trim((string)($_POST["price_min"] ?? ""));
    $priceMaxInput= trim((string)($_POST["price_max"] ?? ""));
    $description  = trim((string)($_POST["description"] ?? ""));
    $imageManual  = trim((string)($_POST["image"] ?? ""));
    $available    = trim((string)($_POST["available"] ?? "1"));

    if ($item_name === "") {
      header("Location: admin-menu.php?shop_id={$shop_id}&err=missing_name");
      exit;
    }

    $priceVal    = parsePriceOrNull($priceInput);
    $priceMinVal = parsePriceOrNull($priceMinInput);
    $priceMaxVal = parsePriceOrNull($priceMaxInput);

    $hasSinglePrice = ($priceInput !== "");
    $hasRangePrice  = ($priceMinInput !== "" || $priceMaxInput !== "");

    if ($hasSinglePrice && $priceVal === null) {
      header("Location: admin-menu.php?shop_id={$shop_id}&err=bad_price");
      exit;
    }

    if ($hasRangePrice) {
      if ($priceMinVal === null || $priceMaxVal === null) {
        header("Location: admin-menu.php?shop_id={$shop_id}&err=bad_price_range");
        exit;
      }
      if ($priceMinVal > $priceMaxVal) {
        header("Location: admin-menu.php?shop_id={$shop_id}&err=bad_price_range");
        exit;
      }
    }

    if (!$hasSinglePrice && !$hasRangePrice) {
      header("Location: admin-menu.php?shop_id={$shop_id}&err=missing_price");
      exit;
    }

    $finalImage = ($imageManual !== "") ? $imageManual : null;

    if ($imageCol && isset($_FILES["image_file"]) && ($_FILES["image_file"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      $upload = saveUploadedMenuImage($_FILES["image_file"]);
      if (!$upload["ok"]) {
        header("Location: admin-menu.php?shop_id={$shop_id}&err=upload_failed&msg=".urlencode($upload["error"]));
        exit;
      }
      $finalImage = $upload["path"];
    }

    $finalPrice    = null;
    $finalPriceMin = null;
    $finalPriceMax = null;

    if ($hasRangePrice) {
      $finalPriceMin = $priceMinVal;
      $finalPriceMax = $priceMaxVal;

      if ($priceCol && $priceMinVal == $priceMaxVal) {
        $finalPrice = $priceMinVal;
      }
    } else {
      $finalPrice = $priceVal;

      if ($priceMinCol) $finalPriceMin = $priceVal;
      if ($priceMaxCol) $finalPriceMax = $priceVal;
    }

    $fields = [];
    $values = [];

    $fields[] = "`$shopIdCol`";
    $values[] = $shop_id;

    $fields[] = "`$nameCol`";
    $values[] = $item_name;

    if ($priceCol) {
      $fields[] = "`$priceCol`";
      $values[] = $finalPrice;
    }

    if ($priceMinCol) {
      $fields[] = "`$priceMinCol`";
      $values[] = $finalPriceMin;
    }

    if ($priceMaxCol) {
      $fields[] = "`$priceMaxCol`";
      $values[] = $finalPriceMax;
    }

    if ($descCol) {
      $fields[] = "`$descCol`";
      $values[] = ($description !== "" ? $description : null);
    }

    if ($imageCol) {
      $fields[] = "`$imageCol`";
      $values[] = $finalImage;
    }

    if ($availableCol) {
      $fields[] = "`$availableCol`";
      $values[] = $availableIsBinary
        ? (($available === "0") ? 0 : 1)
        : (($available === "0") ? "inactive" : "active");
    }

    if ($mode === "add") {
      $sql = "INSERT INTO `$menuTable` (".implode(", ", $fields).") VALUES (".implode(", ", array_fill(0, count($fields), "?")).")";
      $stmt = $pdo->prepare($sql);
      $stmt->execute($values);

      header("Location: admin-menu.php?shop_id={$shop_id}&ok=added");
      exit;
    }

    if ($mode === "edit" && $menu_id > 0) {
      $setParts = [];
      $setVals = [];

      $setParts[] = "`$nameCol`=?";
      $setVals[] = $item_name;

      if ($priceCol) {
        $setParts[] = "`$priceCol`=?";
        $setVals[] = $finalPrice;
      }

      if ($priceMinCol) {
        $setParts[] = "`$priceMinCol`=?";
        $setVals[] = $finalPriceMin;
      }

      if ($priceMaxCol) {
        $setParts[] = "`$priceMaxCol`=?";
        $setVals[] = $finalPriceMax;
      }

      if ($descCol) {
        $setParts[] = "`$descCol`=?";
        $setVals[] = ($description !== "" ? $description : null);
      }

      if ($imageCol) {
        $setParts[] = "`$imageCol`=?";
        $setVals[] = $finalImage;
      }

      if ($availableCol) {
        $setParts[] = "`$availableCol`=?";
        $setVals[] = $availableIsBinary
          ? (($available === "0") ? 0 : 1)
          : (($available === "0") ? "inactive" : "active");
      }

      if ($updatedAtCol) {
        $setParts[] = "`$updatedAtCol`=NOW()";
      }

      $setVals[] = $menu_id;
      $setVals[] = $shop_id;

      $sql = "UPDATE `$menuTable`
              SET ".implode(", ", $setParts)."
              WHERE `$idCol`=? AND `$shopIdCol`=?";
      $stmt = $pdo->prepare($sql);
      $stmt->execute($setVals);

      header("Location: admin-menu.php?shop_id={$shop_id}&ok=updated");
      exit;
    }
  }

  if ($action === "delete") {
    $menu_id = (int)($_POST["menu_id"] ?? 0);
    if ($menu_id > 0) {
      $stmt = $pdo->prepare("DELETE FROM `$menuTable` WHERE `$idCol`=? AND `$shopIdCol`=?");
      $stmt->execute([$menu_id, $shop_id]);
    }
    header("Location: admin-menu.php?shop_id={$shop_id}&ok=deleted");
    exit;
  }

  if ($action === "toggle_available" && $availableCol) {
    $menu_id = (int)($_POST["menu_id"] ?? 0);
    if ($menu_id > 0) {
      $stmt = $pdo->prepare("SELECT `$availableCol` FROM `$menuTable` WHERE `$idCol`=? AND `$shopIdCol`=? LIMIT 1");
      $stmt->execute([$menu_id, $shop_id]);
      $current = $stmt->fetchColumn();

      if ($availableIsBinary) {
        $newVal = (normalizeAvailableValue($current, true) === 1) ? 0 : 1;
      } else {
        $newVal = (normalizeAvailableValue($current, false) === 1) ? "inactive" : "active";
      }

      $stmt = $pdo->prepare("UPDATE `$menuTable` SET `$availableCol`=? WHERE `$idCol`=? AND `$shopIdCol`=?");
      $stmt->execute([$newVal, $menu_id, $shop_id]);
    }

    header("Location: admin-menu.php?shop_id={$shop_id}&ok=toggled");
    exit;
  }
}

/* =========================
   filters
========================= */
$q = trim((string)($_GET["q"] ?? ""));
$filter_available = trim((string)($_GET["filter_available"] ?? ""));

/* =========================
   KPI
========================= */
$menuCountStmt = $pdo->prepare("SELECT COUNT(*) FROM `$menuTable` WHERE `$shopIdCol`=?");
$menuCountStmt->execute([$shop_id]);
$totalMenu = (int)$menuCountStmt->fetchColumn();

$availableMenu = 0;
$unavailableMenu = 0;

if ($availableCol) {
  if ($availableIsBinary) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$menuTable` WHERE `$shopIdCol`=? AND `$availableCol`=1");
    $stmt->execute([$shop_id]);
    $availableMenu = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$menuTable` WHERE `$shopIdCol`=? AND `$availableCol`=0");
    $stmt->execute([$shop_id]);
    $unavailableMenu = (int)$stmt->fetchColumn();
  } else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$menuTable` WHERE `$shopIdCol`=? AND `$availableCol` IN ('active','available','show','1')");
    $stmt->execute([$shop_id]);
    $availableMenu = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$menuTable` WHERE `$shopIdCol`=? AND `$availableCol` IN ('inactive','hidden','off','0','unavailable')");
    $stmt->execute([$shop_id]);
    $unavailableMenu = (int)$stmt->fetchColumn();
  }
} else {
  $availableMenu = $totalMenu;
}

/* =========================
   load menu list
========================= */
$where = ["m.`$shopIdCol` = ?"];
$params = [$shop_id];

if ($q !== "") {
  $searchParts = ["m.`$nameCol` LIKE ?"];
  $params[] = "%{$q}%";

  if ($descCol) {
    $searchParts[] = "m.`$descCol` LIKE ?";
    $params[] = "%{$q}%";
  }

  $where[] = "(".implode(" OR ", $searchParts).")";
}

if ($filter_available !== "" && $availableCol) {
  if ($availableIsBinary) {
    $where[] = "m.`$availableCol` = ?";
    $params[] = ($filter_available === "1") ? 1 : 0;
  } else {
    if ($filter_available === "1") {
      $where[] = "m.`$availableCol` IN ('active','available','show','1')";
    } elseif ($filter_available === "0") {
      $where[] = "m.`$availableCol` IN ('inactive','hidden','off','0','unavailable')";
    }
  }
}

$selectParts = [
  "m.`$idCol` AS menu_id",
  "m.`$shopIdCol` AS shop_id",
  "m.`$nameCol` AS item_name"
];

$selectParts[] = $priceCol ? "m.`$priceCol` AS price" : "NULL AS price";
$selectParts[] = $priceMinCol ? "m.`$priceMinCol` AS price_min" : "NULL AS price_min";
$selectParts[] = $priceMaxCol ? "m.`$priceMaxCol` AS price_max" : "NULL AS price_max";
$selectParts[] = $descCol ? "m.`$descCol` AS description" : "NULL AS description";
$selectParts[] = $imageCol ? "m.`$imageCol` AS image" : "NULL AS image";
$selectParts[] = $availableCol ? "m.`$availableCol` AS available_raw" : "1 AS available_raw";
$selectParts[] = $createdAtCol ? "m.`$createdAtCol` AS created_at" : "NULL AS created_at";

$sql = "
  SELECT ".implode(", ", $selectParts)."
  FROM `$menuTable` m
  WHERE ".implode(" AND ", $where)."
  ORDER BY m.`$idCol` DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ok = $_GET["ok"] ?? "";
$err = $_GET["err"] ?? "";
$msg = trim((string)($_GET["msg"] ?? ""));
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Menu</title>
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
      text-decoration:none;
      color:var(--text);
      display:inline-flex;
      align-items:center;
      gap:6px;
      font-size:14px;
    }
    .btn.primary{
      background:var(--primary);
      color:#fff;
      border-color:transparent;
    }
    .btn.danger{
      background:#fff;
      color:var(--bad);
      border-color:rgba(220,38,38,.35);
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
      grid-template-columns:repeat(3,1fr);
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

    .toolbar{
      display:grid;
      grid-template-columns:1fr 180px auto;
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

    input, select, textarea{
      width:100%;
      padding:10px 12px;
      border:1px solid var(--border);
      border-radius:12px;
      font-size:14px;
      outline:none;
      background:#fff;
    }

    textarea{
      min-height:90px;
      resize:vertical;
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
    .modal .box{
      width:min(820px, 100%);
      background:#fff;
      border-radius:16px;
      border:1px solid var(--border);
      padding:14px;
      box-shadow:0 20px 60px rgba(0,0,0,.2);
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

    .shop-mini{
      margin-top:10px;
      display:flex;
      gap:8px;
      flex-wrap:wrap;
    }

    .preview-img{
      width:70px;
      height:70px;
      object-fit:cover;
      border-radius:12px;
      border:1px solid var(--border);
      background:#fff;
    }

    .modal-preview{
      width:120px;
      height:120px;
      object-fit:cover;
      border-radius:14px;
      border:1px solid var(--border);
      display:none;
      margin-top:8px;
      background:#fff;
    }

    @media (max-width:980px){
      .sidebar{ display:none; }
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
        <a href="admin-dashboard.php">📊 แดชบอร์ด</a>
        <a href="admin-domes-locks.php">🧩 โดม/ล็อก</a>
        <a class="active" href="admin-shops.php">🏪 จัดการร้านค้า</a>
        <a href="admin-shop-categories.php">📁 หมวดหมู่ร้าน</a>
        <a href="admin-shop-types.php">🧩 ประเภทร้าน</a>
        <a href="admin-history.php">🧾 ประวัติคิว</a>
        <a href="admin-reports.php">📈 รายงาน</a>
        <a href="admin-shop-accounts.php">🔐 บัญชีร้านค้า</a>
        <a href="../logout.php">🚪 ออกจากระบบ</a>
        <a href="../Frontend/index.php">↩ กลับหน้าเว็บ</a>
      </nav>
    </aside>

    <main class="main">
      <div class="topbar">
        <div>
          <h1>🍜 จัดการเมนูร้าน</h1>
          <div class="muted">บริหารเมนูของร้านแบบเจาะรายร้าน</div>

          <div class="shop-mini">
            <span class="tag ok">ร้าน: <?= h($shop["name"]) ?></span>
            <span class="tag">สถานะ: <?= h(shopStatusLabelTH($shop["status"])) ?></span>
            <span class="tag">โดม <?= h($shop["dome_id"] ?? "-") ?> • ล็อก <?= h($shop["lock_no"] ?? "-") ?></span>
            <?php if(!empty($shop["type_name"])): ?>
              <span class="tag"><?= h($shop["type_name"]) ?><?= !empty($shop["category_name"]) ? " (".h($shop["category_name"]).")" : "" ?></span>
            <?php endif; ?>
          </div>

          <?php if($ok === "added"): ?>
            <div class="toast ok">เพิ่มเมนูแล้ว ✅</div>
          <?php elseif($ok === "updated"): ?>
            <div class="toast ok">แก้ไขเมนูแล้ว ✅</div>
          <?php elseif($ok === "deleted"): ?>
            <div class="toast ok">ลบเมนูแล้ว ✅</div>
          <?php elseif($ok === "toggled"): ?>
            <div class="toast ok">เปลี่ยนสถานะเมนูแล้ว ✅</div>
          <?php endif; ?>

          <?php if($err === "missing_name"): ?>
            <div class="toast err">กรอกชื่อเมนูก่อน</div>
          <?php elseif($err === "bad_price"): ?>
            <div class="toast err">ราคาเดี่ยวไม่ถูกต้อง</div>
          <?php elseif($err === "bad_price_range"): ?>
            <div class="toast err">ราคาช่วงไม่ถูกต้อง กรุณากรอกราคาต่ำสุดและสูงสุดให้ครบ และราคาต่ำสุดต้องไม่มากกว่าราคาสูงสุด</div>
          <?php elseif($err === "missing_price"): ?>
            <div class="toast err">กรุณากรอกราคาอย่างน้อย 1 แบบ (ราคาเดียว หรือ ราคาต่ำสุด/สูงสุด)</div>
          <?php elseif($err === "upload_failed"): ?>
            <div class="toast err">อัปโหลดรูปไม่สำเร็จ<?= $msg !== "" ? " : ".h($msg) : "" ?></div>
          <?php endif; ?>
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <a class="btn" href="admin-shops.php">↩ กลับหน้าจัดการร้าน</a>
          <button class="btn primary" id="btnAdd">+ เพิ่มเมนู</button>
        </div>
      </div>

      <section class="card">
        <div class="kpi">
          <div class="box">
            <div class="label">เมนูทั้งหมด</div>
            <div class="value"><?= $totalMenu ?></div>
          </div>
          <div class="box">
            <div class="label">เมนูที่พร้อมขาย</div>
            <div class="value"><?= $availableMenu ?></div>
          </div>
          <div class="box">
            <div class="label">เมนูที่ไม่พร้อมขาย</div>
            <div class="value"><?= $unavailableMenu ?></div>
          </div>
        </div>
      </section>

      <section class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
          <h3 style="margin:0;">ค้นหา / กรองเมนู</h3>
          <div class="muted">รองรับทั้งราคาเดียว และช่วงราคา</div>
        </div>

        <form method="get" class="toolbar">
          <input type="hidden" name="shop_id" value="<?= (int)$shop_id ?>">
          <input type="text" name="q" placeholder="ค้นหาชื่อเมนู / รายละเอียด" value="<?= h($q) ?>">

          <select name="filter_available">
            <option value="">-- ทุกสถานะเมนู --</option>
            <option value="1" <?= $filter_available === "1" ? "selected" : "" ?>>พร้อมขาย</option>
            <option value="0" <?= $filter_available === "0" ? "selected" : "" ?>>ไม่พร้อมขาย</option>
          </select>

          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button class="btn primary" type="submit">กรอง</button>
            <a class="btn" href="admin-menu.php?shop_id=<?= (int)$shop_id ?>">ล้าง</a>
          </div>
        </form>
      </section>

      <section class="card">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th style="width:90px;">รหัส</th>
                <th style="width:90px;">รูป</th>
                <th>เมนู</th>
                <th style="width:180px;">ราคา</th>
                <th style="width:130px;">สถานะ</th>
                <th style="width:250px;">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php if(!$menus): ?>
                <tr><td colspan="6" class="muted">ยังไม่มีเมนูของร้านนี้</td></tr>
              <?php else: ?>
                <?php foreach($menus as $m): ?>
                  <?php $isAvailable = normalizeAvailableValue($m["available_raw"], $availableIsBinary) === 1; ?>
                  <tr
                    data-menu='<?= h(json_encode([
                      "menu_id" => (int)$m["menu_id"],
                      "item_name" => $m["item_name"],
                      "price" => $m["price"],
                      "price_min" => $m["price_min"],
                      "price_max" => $m["price_max"],
                      "description" => $m["description"],
                      "image" => $m["image"],
                      "available" => $isAvailable ? "1" : "0",
                    ], JSON_UNESCAPED_UNICODE)) ?>'
                  >
                    <td><?= (int)$m["menu_id"] ?></td>
                    <td>
                      <?php if(!empty($m["image"])): ?>
                        <img src="<?= h($m["image"]) ?>" alt="" class="preview-img">
                      <?php else: ?>
                        <div class="muted">ไม่มีรูป</div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div style="font-weight:800;"><?= h($m["item_name"]) ?></div>
                      <?php if(!empty($m["description"])): ?>
                        <div class="muted" style="margin-top:4px;"><?= nl2br(h($m["description"])) ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?= h(renderPriceText($m)) ?></td>
                    <td>
                      <span class="tag <?= $isAvailable ? 'ok' : 'bad' ?>">
                        <?= $isAvailable ? 'พร้อมขาย' : 'ไม่พร้อมขาย' ?>
                      </span>
                    </td>
                    <td>
                      <div style="display:flex; flex-wrap:wrap; gap:8px;">
                        <button class="btn small" type="button" onclick="editFromRow(this)">แก้ไข</button>

                        <?php if($availableCol): ?>
                          <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="toggle_available">
                            <input type="hidden" name="shop_id" value="<?= (int)$shop_id ?>">
                            <input type="hidden" name="menu_id" value="<?= (int)$m["menu_id"] ?>">
                            <button class="btn small" type="submit">สลับสถานะ</button>
                          </form>
                        <?php endif; ?>

                        <form method="post" style="margin:0;" onsubmit="return confirm('ลบเมนูนี้ใช่ไหม?')">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="shop_id" value="<?= (int)$shop_id ?>">
                          <input type="hidden" name="menu_id" value="<?= (int)$m["menu_id"] ?>">
                          <button class="btn small danger" type="submit">ลบ</button>
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
    </main>
  </div>

  <div class="modal" id="modal">
    <div class="box">
      <form method="post" id="menuForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="mode" id="fMode" value="add">
        <input type="hidden" name="shop_id" value="<?= (int)$shop_id ?>">
        <input type="hidden" name="menu_id" id="fMenuId" value="0">

        <div class="head">
          <div>
            <div style="font-weight:900;" id="modalTitle">เพิ่มเมนู</div>
            <div class="muted">กรอกข้อมูลเมนูของร้านนี้</div>
          </div>
          <button class="btn" type="button" id="btnClose">ปิด</button>
        </div>

        <div style="margin-top:10px; display:grid; gap:10px; grid-template-columns:1fr 1fr;">
          <div style="grid-column:1/-1;">
            <div class="muted">ชื่อเมนู</div>
            <input name="item_name" id="fName" placeholder="เช่น ข้าวกะเพราไก่" required>
          </div>

          <div>
            <div class="muted">ราคาเดียว</div>
            <input name="price" id="fPrice" type="number" min="0" step="0.01" placeholder="เช่น 50.00">
          </div>

          <div>
            <div class="muted">สถานะเมนู</div>
            <?php if($availableCol): ?>
              <select name="available" id="fAvailable">
                <option value="1">พร้อมขาย</option>
                <option value="0">ไม่พร้อมขาย</option>
              </select>
            <?php else: ?>
              <input value="พร้อมขาย" disabled>
            <?php endif; ?>
          </div>

          <div>
            <div class="muted">ราคาต่ำสุด</div>
            <input name="price_min" id="fPriceMin" type="number" min="0" step="0.01" placeholder="เช่น 40.00">
          </div>

          <div>
            <div class="muted">ราคาสูงสุด</div>
            <input name="price_max" id="fPriceMax" type="number" min="0" step="0.01" placeholder="เช่น 60.00">
          </div>

          <div style="grid-column:1/-1;">
            <div class="muted">หมายเหตุราคา</div>
            <div class="muted">
              ถ้าเป็นเมนูราคาเดียว ให้กรอกเฉพาะช่อง “ราคาเดียว”
              <br>
              ถ้าเป็นเมนูหลายราคา/เริ่มต้น-สูงสุด ให้กรอก “ราคาต่ำสุด” และ “ราคาสูงสุด”
            </div>
          </div>

          <?php if($imageCol): ?>
            <div style="grid-column:1/-1;">
              <div class="muted">อัปโหลดรูปภาพเมนู</div>
              <input type="file" name="image_file" id="fImageFile" accept="image/*">
              <div class="muted" style="margin-top:6px;">รองรับ jpg, jpeg, png, gif, webp ขนาดไม่เกิน 5MB</div>
            </div>

            <div style="grid-column:1/-1;">
              <div class="muted">หรือกรอก URL/Path รูปภาพ</div>
              <input name="image" id="fImage" placeholder="../uploads/menu/example.jpg หรือ https://...">
              <img id="fImagePreview" class="modal-preview" alt="">
            </div>
          <?php endif; ?>

          <?php if($descCol): ?>
            <div style="grid-column:1/-1;">
              <div class="muted">รายละเอียด</div>
              <textarea name="description" id="fDesc" placeholder="คำอธิบายเพิ่มเติม..."></textarea>
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
    const fMenuId = document.getElementById("fMenuId");
    const fName = document.getElementById("fName");
    const fPrice = document.getElementById("fPrice");
    const fPriceMin = document.getElementById("fPriceMin");
    const fPriceMax = document.getElementById("fPriceMax");
    const fDesc = document.getElementById("fDesc");
    const fImage = document.getElementById("fImage");
    const fImageFile = document.getElementById("fImageFile");
    const fAvailable = document.getElementById("fAvailable");
    const fImagePreview = document.getElementById("fImagePreview");

    function openModal(){
      modal.style.display = "flex";
      setTimeout(() => fName.focus(), 50);
    }
    function closeModal(){
      modal.style.display = "none";
    }

    function updateImagePreview(src){
      if (!fImagePreview) return;
      if (src && src.trim() !== "") {
        fImagePreview.src = src;
        fImagePreview.style.display = "block";
      } else {
        fImagePreview.src = "";
        fImagePreview.style.display = "none";
      }
    }

    function setAdd(){
      fMode.value = "add";
      fMenuId.value = "0";
      modalTitle.textContent = "เพิ่มเมนู";
      fName.value = "";
      if (fPrice) fPrice.value = "";
      if (fPriceMin) fPriceMin.value = "";
      if (fPriceMax) fPriceMax.value = "";
      if (fDesc) fDesc.value = "";
      if (fImage) fImage.value = "";
      if (fImageFile) fImageFile.value = "";
      if (fAvailable) fAvailable.value = "1";
      updateImagePreview("");
    }

    function setEdit(menu){
      fMode.value = "edit";
      fMenuId.value = menu.menu_id || 0;
      modalTitle.textContent = "แก้ไขเมนู";
      fName.value = menu.item_name || "";
      if (fPrice) fPrice.value = menu.price ?? "";
      if (fPriceMin) fPriceMin.value = menu.price_min ?? "";
      if (fPriceMax) fPriceMax.value = menu.price_max ?? "";
      if (fDesc) fDesc.value = menu.description || "";
      if (fImage) fImage.value = menu.image || "";
      if (fImageFile) fImageFile.value = "";
      if (fAvailable) fAvailable.value = menu.available || "1";
      updateImagePreview(menu.image || "");
    }

    window.editFromRow = (btn) => {
      const tr = btn.closest("tr");
      const raw = tr.getAttribute("data-menu");
      const menu = JSON.parse(raw);
      setEdit(menu);
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

    if (fImage) {
      fImage.addEventListener("input", () => {
        updateImagePreview(fImage.value);
      });
    }

    if (fImageFile) {
      fImageFile.addEventListener("change", () => {
        const file = fImageFile.files && fImageFile.files[0] ? fImageFile.files[0] : null;
        if (!file) {
          updateImagePreview(fImage ? fImage.value : "");
          return;
        }
        const reader = new FileReader();
        reader.onload = function(e){
          updateImagePreview(String(e.target.result || ""));
        };
        reader.readAsDataURL(file);
      });
    }
  </script>
</body>
</html>