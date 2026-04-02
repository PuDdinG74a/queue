<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/config.php";

$dome_no = isset($_GET["dome_no"]) ? (int)$_GET["dome_no"] : 0;
if ($dome_no <= 0) {
  http_response_code(400);
  echo json_encode(["error" => "dome_no required"]);
  exit;
}

$stmt = $pdo->prepare("
  SELECT shop_id, name, status, dome_no, lock_no
  FROM shops
  WHERE dome_no = ?
  ORDER BY lock_no ASC
");
$stmt->execute([$dome_no]);
$rows = $stmt->fetchAll();

echo json_encode([
  "dome_no" => $dome_no,
  "shops" => $rows
], JSON_UNESCAPED_UNICODE);
