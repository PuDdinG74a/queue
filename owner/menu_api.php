<?php
// owner/menu_api.php
require_once __DIR__ . "/_auth.php";

header("Content-Type: application/json; charset=utf-8");

$action = trim((string)($_GET["action"] ?? $_POST["action"] ?? ""));
if ($action === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "action ว่าง"], JSON_UNESCAPED_UNICODE);
  exit;
}

function ok($data = []) {
  echo json_encode(["ok" => true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}

function err($msg, $code = 400) {
  http_response_code($code);
  echo json_encode(["ok" => false, "error" => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

$requestedShopId = (int)($_GET["shop_id"] ?? $_POST["shop_id"] ?? 0);
$shop_id = enforceOwnerShopAccess($requestedShopId);

// =============================
// Upload Helper
// - รับไฟล์ชื่อ image_file
// - เซฟ: (project_root)/uploads/menu/
// - เก็บ URL: /FINAL_PROJECT/uploads/menu/xxx.jpg
// =============================
function project_root_dir(): string {
  $root = realpath(__DIR__ . "/..");
  return $root ?: (__DIR__ . "/..");
}

function project_base_url(): string {
  $script = $_SERVER["SCRIPT_NAME"] ?? "";
  $base = rtrim(dirname(dirname($script)), "/");
  return $base === "" ? "" : $base;
}

function last_mkdir_error(): string {
  $e = error_get_last();
  if (!$e) return "";
  return ($e["message"] ?? "");
}

function ensure_upload_dir(string $dir): void {
  if (is_dir($dir)) {
    if (!is_writable($dir)) {
      err("โฟลเดอร์อัปโหลดเขียนไม่ได้ (permission): {$dir}", 500);
    }
    return;
  }

  @mkdir($dir, 0777, true);

  if (!is_dir($dir)) {
    $why = last_mkdir_error();
    $whyText = $why ? " • สาเหตุ: {$why}" : "";
    err("สร้างโฟลเดอร์อัปโหลดไม่สำเร็จ: {$dir}{$whyText}", 500);
  }

  if (!is_writable($dir)) {
    err("โฟลเดอร์อัปโหลดเขียนไม่ได้ (permission): {$dir}", 500);
  }
}

function handle_upload_image(int $shop_id): ?string {
  if (!isset($_FILES["image_file"]) || !is_array($_FILES["image_file"])) {
    return null;
  }

  $f = $_FILES["image_file"];
  $errNo = (int)($f["error"] ?? UPLOAD_ERR_NO_FILE);

  if ($errNo === UPLOAD_ERR_NO_FILE) return null;

  if ($errNo !== UPLOAD_ERR_OK) {
    err("อัปโหลดรูปไม่สำเร็จ (error code: {$errNo})", 400);
  }

  $tmp = (string)($f["tmp_name"] ?? "");
  if ($tmp === "" || !is_uploaded_file($tmp)) {
    err("อัปโหลดรูปไม่สำเร็จ (ไฟล์ชั่วคราวไม่ถูกต้อง)", 400);
  }

  $size = (int)($f["size"] ?? 0);
  if ($size <= 0) err("ไฟล์รูปไม่ถูกต้อง", 400);
  if ($size > 5 * 1024 * 1024) err("ไฟล์ใหญ่เกิน 5MB", 400);

  $mime = "";
  if (function_exists("finfo_open")) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
      $realMime = finfo_file($finfo, $tmp);
      finfo_close($finfo);
      $mime = (string)$realMime;
    }
  }

  if ($mime === "") {
    $mime = mime_content_type($tmp) ?: "";
  }

  $ext = match ($mime) {
    "image/jpeg" => "jpg",
    "image/png"  => "png",
    "image/webp" => "webp",
    default      => ""
  };

  if ($ext === "") {
    err("รองรับเฉพาะ JPG/PNG/WEBP เท่านั้น", 400);
  }

  $rootDir = project_root_dir();
  $uploadDir = $rootDir . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "menu";
  ensure_upload_dir($uploadDir);

  $name = "shop{$shop_id}_" . date("Ymd_His") . "_" . bin2hex(random_bytes(4)) . "." . $ext;
  $dest = $uploadDir . DIRECTORY_SEPARATOR . $name;

  if (!move_uploaded_file($tmp, $dest)) {
    err("ย้ายไฟล์รูปไม่สำเร็จ (permission/path): {$dest}", 500);
  }

  $base = project_base_url();
  return $base . "/uploads/menu/" . $name;
}

try {
  // เช็กว่าร้านมีอยู่จริง
  $stmt = $pdo->prepare("
    SELECT shop_id
    FROM shops
    WHERE shop_id = ?
    LIMIT 1
  ");
  $stmt->execute([$shop_id]);
  if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
    err("ไม่พบร้าน", 404);
  }

  // ===== LIST =====
  if ($action === "list") {
    $stmt = $pdo->prepare("
      SELECT item_id, item_name, price, price_min, price_max, image_url, is_available
      FROM menu_items
      WHERE shop_id = ?
      ORDER BY item_id DESC
    ");
    $stmt->execute([$shop_id]);

    ok(["items" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
  }

  // ===== GET ONE =====
  if ($action === "get") {
    $item_id = (int)($_GET["item_id"] ?? $_POST["item_id"] ?? 0);
    if ($item_id <= 0) err("item_id ไม่ถูกต้อง");

    $stmt = $pdo->prepare("
      SELECT item_id, item_name, price, price_min, price_max, image_url, is_available
      FROM menu_items
      WHERE shop_id = ? AND item_id = ?
      LIMIT 1
    ");
    $stmt->execute([$shop_id, $item_id]);
    $it = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$it) err("ไม่พบเมนูนี้", 404);

    ok(["item" => $it]);
  }

  // ===== ADD =====
  if ($action === "add") {
    $name = mb_substr(trim((string)($_POST["item_name"] ?? "")), 0, 150);
    $mode = (string)($_POST["price_mode"] ?? "single");

    $price = (string)($_POST["price"] ?? "");
    $pmin  = (string)($_POST["price_min"] ?? "");
    $pmax  = (string)($_POST["price_max"] ?? "");

    $image_url_text = trim((string)($_POST["image_url"] ?? ""));
    $avail = (int)($_POST["is_available"] ?? 1);
    if (!in_array($avail, [0, 1], true)) $avail = 1;

    if ($name === "") err("กรุณาใส่ชื่อเมนู");
    if (!in_array($mode, ["single", "range"], true)) $mode = "single";

    $price = ($price === "") ? null : (float)$price;
    $pmin  = ($pmin === "")  ? null : (float)$pmin;
    $pmax  = ($pmax === "")  ? null : (float)$pmax;

    if ($mode === "range") {
      if ($pmin === null || $pmax === null) err("กรุณาใส่ราคาธรรมดา/พิเศษให้ครบ");
      if ($pmin <= 0 || $pmax <= 0) err("ราคาต้องมากกว่า 0");
      if ($pmax < $pmin) err("ราคาพิเศษต้องไม่น้อยกว่าราคาธรรมดา");
      $price = null;
    } else {
      if ($price === null || $price <= 0) err("กรุณาใส่ราคาให้ถูกต้อง");
      $pmin = null;
      $pmax = null;
    }

    // กันชื่อซ้ำในร้านเดียวกัน
    $stmt = $pdo->prepare("
      SELECT item_id
      FROM menu_items
      WHERE shop_id = ? AND item_name = ?
      LIMIT 1
    ");
    $stmt->execute([$shop_id, $name]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
      err("มีเมนูชื่อนี้อยู่แล้ว");
    }

    $uploadedUrl = handle_upload_image($shop_id);
    $finalImageUrl = $uploadedUrl !== null
      ? $uploadedUrl
      : (($image_url_text === "") ? null : $image_url_text);

    $stmt = $pdo->prepare("
      INSERT INTO menu_items (shop_id, item_name, price, price_min, price_max, image_url, is_available)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$shop_id, $name, $price, $pmin, $pmax, $finalImageUrl, $avail]);

    ok([
      "item_id" => (int)$pdo->lastInsertId(),
      "image_url" => $finalImageUrl
    ]);
  }

  // ===== UPDATE =====
  if ($action === "update") {
    $item_id = (int)($_POST["item_id"] ?? 0);
    if ($item_id <= 0) err("item_id ไม่ถูกต้อง");

    $stmt = $pdo->prepare("
      SELECT item_id, image_url
      FROM menu_items
      WHERE shop_id = ? AND item_id = ?
      LIMIT 1
    ");
    $stmt->execute([$shop_id, $item_id]);
    $existingItem = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingItem) {
      err("ไม่พบเมนูนี้", 404);
    }

    $name = mb_substr(trim((string)($_POST["item_name"] ?? "")), 0, 150);
    $mode = (string)($_POST["price_mode"] ?? "single");

    $price = (string)($_POST["price"] ?? "");
    $pmin  = (string)($_POST["price_min"] ?? "");
    $pmax  = (string)($_POST["price_max"] ?? "");

    $hasImageUrlField = array_key_exists("image_url", $_POST);
    $image_url_text = $hasImageUrlField ? trim((string)($_POST["image_url"] ?? "")) : null;

    $avail = (int)($_POST["is_available"] ?? 1);
    if (!in_array($avail, [0, 1], true)) $avail = 1;

    if ($name === "") err("กรุณาใส่ชื่อเมนู");
    if (!in_array($mode, ["single", "range"], true)) $mode = "single";

    $price = ($price === "") ? null : (float)$price;
    $pmin  = ($pmin === "")  ? null : (float)$pmin;
    $pmax  = ($pmax === "")  ? null : (float)$pmax;

    if ($mode === "range") {
      if ($pmin === null || $pmax === null) err("กรุณาใส่ราคาธรรมดา/พิเศษให้ครบ");
      if ($pmin <= 0 || $pmax <= 0) err("ราคาต้องมากกว่า 0");
      if ($pmax < $pmin) err("ราคาพิเศษต้องไม่น้อยกว่าราคาธรรมดา");
      $price = null;
    } else {
      if ($price === null || $price <= 0) err("กรุณาใส่ราคาให้ถูกต้อง");
      $pmin = null;
      $pmax = null;
    }

    // กันชื่อซ้ำในร้านเดียวกัน (ยกเว้น item เดิม)
    $stmt = $pdo->prepare("
      SELECT item_id
      FROM menu_items
      WHERE shop_id = ? AND item_name = ? AND item_id <> ?
      LIMIT 1
    ");
    $stmt->execute([$shop_id, $name, $item_id]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
      err("มีเมนูชื่อนี้อยู่แล้ว");
    }

    $uploadedUrl = handle_upload_image($shop_id);

    if ($uploadedUrl !== null) {
      $finalImageUrl = $uploadedUrl;

      $stmt = $pdo->prepare("
        UPDATE menu_items
        SET item_name = ?, price = ?, price_min = ?, price_max = ?, image_url = ?, is_available = ?
        WHERE shop_id = ? AND item_id = ?
        LIMIT 1
      ");
      $stmt->execute([$name, $price, $pmin, $pmax, $finalImageUrl, $avail, $shop_id, $item_id]);

      ok(["image_url" => $finalImageUrl]);
    }

    if ($hasImageUrlField) {
      $finalImageUrl = ($image_url_text === "") ? null : $image_url_text;

      $stmt = $pdo->prepare("
        UPDATE menu_items
        SET item_name = ?, price = ?, price_min = ?, price_max = ?, image_url = ?, is_available = ?
        WHERE shop_id = ? AND item_id = ?
        LIMIT 1
      ");
      $stmt->execute([$name, $price, $pmin, $pmax, $finalImageUrl, $avail, $shop_id, $item_id]);

      ok(["image_url" => $finalImageUrl]);
    }

    $stmt = $pdo->prepare("
      UPDATE menu_items
      SET item_name = ?, price = ?, price_min = ?, price_max = ?, is_available = ?
      WHERE shop_id = ? AND item_id = ?
      LIMIT 1
    ");
    $stmt->execute([$name, $price, $pmin, $pmax, $avail, $shop_id, $item_id]);

    ok();
  }

  // ===== DELETE =====
  if ($action === "delete") {
    $item_id = (int)($_POST["item_id"] ?? 0);
    if ($item_id <= 0) err("item_id ไม่ถูกต้อง");

    $stmt = $pdo->prepare("
      SELECT item_id
      FROM menu_items
      WHERE shop_id = ? AND item_id = ?
      LIMIT 1
    ");
    $stmt->execute([$shop_id, $item_id]);

    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
      err("ไม่พบเมนูนี้", 404);
    }

    $stmt = $pdo->prepare("
      DELETE FROM menu_items
      WHERE shop_id = ? AND item_id = ?
      LIMIT 1
    ");
    $stmt->execute([$shop_id, $item_id]);

    ok();
  }

  err("action ไม่ถูกต้อง", 400);

} catch (Throwable $e) {
  err("server error: " . $e->getMessage(), 500);
}