<?php
require_once __DIR__ . "/../config.php";

header("Content-Type: application/json; charset=utf-8");

function ok(array $data = []): void {
  echo json_encode(["ok" => true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}

function fail(string $msg, int $code = 400): void {
  http_response_code($code);
  echo json_encode(["ok" => false, "error" => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  fail("Method not allowed", 405);
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$queue_id = (int)($data["queue_id"] ?? 0);
$endpoint = trim((string)($data["endpoint"] ?? ""));

if ($queue_id <= 0 || $endpoint === "") {
  fail("ข้อมูลไม่ถูกต้อง");
}

$stmt = $pdo->prepare("
  UPDATE push_subscriptions
  SET is_active = 0, updated_at = NOW()
  WHERE queue_id = ? AND endpoint = ?
");
$stmt->execute([$queue_id, $endpoint]);

ok(["message" => "ยกเลิก push subscription แล้ว"]);