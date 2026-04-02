<?php
// config.php

date_default_timezone_set('Asia/Bangkok');

$host = "localhost";
$db   = "queue_system";
$user = "root";
$pass = "";
$charset = "utf8mb4";

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
  $pdo = new PDO($dsn, $user, $pass, $options);
  $pdo->exec("SET time_zone = '+07:00'");
} catch (PDOException $e) {
  die("เชื่อมต่อฐานข้อมูลไม่สำเร็จ: " . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| Push Notification Config
|--------------------------------------------------------------------------
*/
if (!defined('APP_BASE')) {
  define('APP_BASE', '/final_project');
}

/*
|--------------------------------------------------------------------------
| VAPID Keys
|--------------------------------------------------------------------------
| ใส่ public/private key จริงที่ generate มาเป็น "คู่เดียวกัน"
|--------------------------------------------------------------------------
*/
if (!defined('VAPID_PUBLIC_KEY')) {
  define('VAPID_PUBLIC_KEY', 'BDi4qdEmnWdDLqSYUlIVt3u5gsUly1LLZVoQuISE4J1QXqpMRMYZcK6x4v9k96XyMWHLoEddsZXNjASHG3-Os-E');
}

if (!defined('VAPID_PRIVATE_KEY')) {
  define('VAPID_PRIVATE_KEY', 'UbbnTbVVYwSMBTQwePkyvMJoGk74wmKuTRkBAGHkhxQ');
}

if (!defined('VAPID_SUBJECT')) {
  define('VAPID_SUBJECT', 'mailto:65010912533@msu.ac.th');
}