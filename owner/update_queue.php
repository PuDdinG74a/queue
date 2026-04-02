<?php
// owner/update_queue.php
require_once __DIR__ . "/_auth.php";
require_once __DIR__ . "/../push_lib.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo "Method not allowed";
  exit;
}

$requestedShopId = (int)($_POST["shop_id"] ?? 0);
$shop_id = enforceOwnerShopAccess($requestedShopId);

$queue_id = (int)($_POST["queue_id"] ?? 0);
$mode     = trim((string)($_POST["mode"] ?? ""));

$today = date('Y-m-d');

function goBack(int $shop_id): void {
  header("Location: shop_owner.php?shop_id=" . $shop_id);
  exit;
}

function queueUrl(int $queueId): string {
  return APP_BASE . "/Frontend/my-queue.php?queue_id=" . $queueId;
}

/**
 * แจ้งเตือนคิวที่ใกล้ถึง
 * ตอนนี้ตั้งไว้ที่ "เหลืออีก 2 คิว"
 */
function sendNearQueueAlerts(PDO $pdo, int $shop_id, string $today, string $shopName, int $currentQueueNo): void {
  if ($currentQueueNo <= 0) {
    return;
  }

  $stmt = $pdo->prepare("
    SELECT queue_id, queue_no
    FROM queues
    WHERE shop_id = ?
      AND queue_date = ?
      AND status = 'waiting'
    ORDER BY queue_no ASC
  ");
  $stmt->execute([$shop_id, $today]);
  $waitingQueues = $stmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($waitingQueues as $row) {
    $targetQueueId = (int)($row["queue_id"] ?? 0);
    $targetQueueNo = (int)($row["queue_no"] ?? 0);

    if ($targetQueueId <= 0 || $targetQueueNo <= 0) {
      continue;
    }

    $diff = $targetQueueNo - $currentQueueNo;

    // เหลืออีก 2 คิวพอดี
    if ($diff === 2) {
      sendQueuePush(
        $pdo,
        $targetQueueId,
        "ใกล้ถึงคิวแล้ว ⏳",
        "{$shopName} อีก 2 คิวจะถึงคิวของคุณ เตรียมตัวได้เลย (คิว #{$targetQueueNo})",
        queueUrl($targetQueueId)
      );
    }
  }
}

try {
  $pdo->beginTransaction();

  $stmt = $pdo->prepare("
    SELECT shop_id, name
    FROM shops
    WHERE shop_id = ?
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([$shop_id]);
  $shop = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$shop) {
    $pdo->rollBack();
    http_response_code(404);
    echo "ไม่พบร้าน";
    exit;
  }

  $shopName = trim((string)($shop["name"] ?? "ร้านค้า"));
  $pushJobs = [];

  // =========================
  // 1) เรียกคิวถัดไป
  // - ปิด calling เดิมเป็น served
  // - ดึง waiting ถัดไปขึ้นเป็น calling
  // - ส่งแจ้งเตือนก่อนถึงคิว (เหลือ 2 คิว)
  // =========================
  if ($mode === "call_next") {

    // ปิดคิวที่กำลังเรียกอยู่ก่อน ให้เป็น served
    $stmt = $pdo->prepare("
      SELECT queue_id, queue_no
      FROM queues
      WHERE shop_id = ?
        AND queue_date = ?
        AND status = 'calling'
      ORDER BY queue_no ASC
      LIMIT 1
      FOR UPDATE
    ");
    $stmt->execute([$shop_id, $today]);
    $callingRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($callingRow) {
      $oldQueueId = (int)$callingRow["queue_id"];
      $oldQueueNo = (int)$callingRow["queue_no"];

      $stmt = $pdo->prepare("
        UPDATE queues
        SET status = 'served',
            served_at = NOW()
        WHERE queue_id = ?
          AND shop_id = ?
          AND queue_date = ?
          AND status = 'calling'
        LIMIT 1
      ");
      $stmt->execute([$oldQueueId, $shop_id, $today]);

      if ($stmt->rowCount() > 0) {
        $pushJobs[] = [
          "queue_id" => $oldQueueId,
          "title"    => "ออเดอร์พร้อมรับแล้ว ✅",
          "body"     => "{$shopName} ออเดอร์ของคุณพร้อมรับแล้ว (คิว #{$oldQueueNo})",
          "url"      => queueUrl($oldQueueId),
        ];
      }
    }

    // เรียก waiting ถัดไป
    $stmt = $pdo->prepare("
      SELECT queue_id, queue_no
      FROM queues
      WHERE shop_id = ?
        AND queue_date = ?
        AND status = 'waiting'
      ORDER BY queue_no ASC
      LIMIT 1
      FOR UPDATE
    ");
    $stmt->execute([$shop_id, $today]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $newCallingQueueNo = 0;

    if ($row) {
      $qid = (int)$row["queue_id"];
      $qno = (int)$row["queue_no"];
      $newCallingQueueNo = $qno;

      $stmt = $pdo->prepare("
        UPDATE queues
        SET status = 'calling',
            called_at = NOW()
        WHERE queue_id = ?
          AND shop_id = ?
          AND queue_date = ?
          AND status = 'waiting'
        LIMIT 1
      ");
      $stmt->execute([$qid, $shop_id, $today]);

      if ($stmt->rowCount() > 0) {
        $pushJobs[] = [
          "queue_id" => $qid,
          "title"    => "ถึงคิวแล้ว!",
          "body"     => "{$shopName} กำลังเรียกคิว #{$qno} ของคุณ",
          "url"      => queueUrl($qid),
        ];
      }
    }

    $pdo->commit();

    foreach ($pushJobs as $job) {
      sendQueuePush(
        $pdo,
        (int)$job["queue_id"],
        $job["title"],
        $job["body"],
        $job["url"]
      );
    }

    // แจ้งเตือนคิวที่เหลืออีก 2 คิว
    if ($newCallingQueueNo > 0) {
      sendNearQueueAlerts($pdo, $shop_id, $today, $shopName, $newCallingQueueNo);
    }

    goBack($shop_id);
  }

  // =========================
  // 2) เปลี่ยนสถานะทีละคิว
  // waiting -> calling
  // calling -> served
  // =========================
  if ($mode === "next") {

    if ($queue_id <= 0) {
      $pdo->rollBack();
      http_response_code(400);
      echo "queue_id ไม่ถูกต้อง";
      exit;
    }

    $stmt = $pdo->prepare("
      SELECT queue_id, queue_no, status
      FROM queues
      WHERE queue_id = ?
        AND shop_id = ?
        AND queue_date = ?
      LIMIT 1
      FOR UPDATE
    ");
    $stmt->execute([$queue_id, $shop_id, $today]);
    $q = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$q) {
      $pdo->commit();
      goBack($shop_id);
    }

    $status  = trim((string)($q["status"] ?? "waiting"));
    $queueNo = (int)($q["queue_no"] ?? 0);
    $newCallingQueueNo = 0;

    if ($status === "waiting") {

      // กันการเรียกคิวซ้อน
      $stmt = $pdo->prepare("
        SELECT queue_id
        FROM queues
        WHERE shop_id = ?
          AND queue_date = ?
          AND status = 'calling'
          AND queue_id <> ?
        ORDER BY queue_no ASC
        LIMIT 1
        FOR UPDATE
      ");
      $stmt->execute([$shop_id, $today, $queue_id]);
      $existingCalling = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($existingCalling) {
        $pdo->commit();
        goBack($shop_id);
      }

      $stmt = $pdo->prepare("
        UPDATE queues
        SET status = 'calling',
            called_at = NOW()
        WHERE queue_id = ?
          AND shop_id = ?
          AND queue_date = ?
          AND status = 'waiting'
        LIMIT 1
      ");
      $stmt->execute([$queue_id, $shop_id, $today]);

      if ($stmt->rowCount() > 0) {
        $newCallingQueueNo = $queueNo;

        $pushJobs[] = [
          "queue_id" => $queue_id,
          "title"    => "ถึงคิวแล้ว!",
          "body"     => "{$shopName} กำลังเรียกคิว #{$queueNo} ของคุณ",
          "url"      => queueUrl($queue_id),
        ];
      }

    } elseif ($status === "calling") {

      $stmt = $pdo->prepare("
        UPDATE queues
        SET status = 'served',
            served_at = NOW()
        WHERE queue_id = ?
          AND shop_id = ?
          AND queue_date = ?
          AND status = 'calling'
        LIMIT 1
      ");
      $stmt->execute([$queue_id, $shop_id, $today]);

      if ($stmt->rowCount() > 0) {
        $pushJobs[] = [
          "queue_id" => $queue_id,
          "title"    => "ออเดอร์พร้อมรับแล้ว ✅",
          "body"     => "{$shopName} ออเดอร์ของคุณพร้อมรับแล้ว (คิว #{$queueNo})",
          "url"      => queueUrl($queue_id),
        ];
      }
    }

    $pdo->commit();

    foreach ($pushJobs as $job) {
      sendQueuePush(
        $pdo,
        (int)$job["queue_id"],
        $job["title"],
        $job["body"],
        $job["url"]
      );
    }

    // แจ้งเตือนคิวที่เหลืออีก 2 คิว
    if ($newCallingQueueNo > 0) {
      sendNearQueueAlerts($pdo, $shop_id, $today, $shopName, $newCallingQueueNo);
    }

    goBack($shop_id);
  }

  // =========================
  // 3) เปลี่ยนเป็น served ตรง ๆ
  // =========================
  if ($mode === "served") {

    if ($queue_id <= 0) {
      $pdo->rollBack();
      http_response_code(400);
      echo "queue_id ไม่ถูกต้อง";
      exit;
    }

    $stmt = $pdo->prepare("
      SELECT queue_id, queue_no
      FROM queues
      WHERE queue_id = ?
        AND shop_id = ?
        AND queue_date = ?
      LIMIT 1
      FOR UPDATE
    ");
    $stmt->execute([$queue_id, $shop_id, $today]);
    $q = $stmt->fetch(PDO::FETCH_ASSOC);
    $queueNo = (int)($q["queue_no"] ?? 0);

    $stmt = $pdo->prepare("
      UPDATE queues
      SET status = 'served',
          served_at = NOW()
      WHERE queue_id = ?
        AND shop_id = ?
        AND queue_date = ?
        AND status = 'calling'
      LIMIT 1
    ");
    $stmt->execute([$queue_id, $shop_id, $today]);

    if ($stmt->rowCount() > 0) {
      $pushJobs[] = [
        "queue_id" => $queue_id,
        "title"    => "ออเดอร์พร้อมรับแล้ว ✅",
        "body"     => "{$shopName} ออเดอร์ของคุณพร้อมรับแล้ว (คิว #{$queueNo})",
        "url"      => queueUrl($queue_id),
      ];
    }

    $pdo->commit();

    foreach ($pushJobs as $job) {
      sendQueuePush(
        $pdo,
        (int)$job["queue_id"],
        $job["title"],
        $job["body"],
        $job["url"]
      );
    }

    goBack($shop_id);
  }

  // =========================
  // 4) ยกเลิกคิว
  // =========================
  if ($mode === "cancel") {

    if ($queue_id <= 0) {
      $pdo->rollBack();
      http_response_code(400);
      echo "queue_id ไม่ถูกต้อง";
      exit;
    }

    $stmt = $pdo->prepare("
      SELECT queue_id, queue_no
      FROM queues
      WHERE queue_id = ?
        AND shop_id = ?
        AND queue_date = ?
      LIMIT 1
      FOR UPDATE
    ");
    $stmt->execute([$queue_id, $shop_id, $today]);
    $q = $stmt->fetch(PDO::FETCH_ASSOC);
    $queueNo = (int)($q["queue_no"] ?? 0);

    $stmt = $pdo->prepare("
      UPDATE queues
      SET status = 'cancel'
      WHERE queue_id = ?
        AND shop_id = ?
        AND queue_date = ?
        AND status IN ('waiting','calling','served')
      LIMIT 1
    ");
    $stmt->execute([$queue_id, $shop_id, $today]);

    if ($stmt->rowCount() > 0) {
      $pushJobs[] = [
        "queue_id" => $queue_id,
        "title"    => "คิวถูกยกเลิก",
        "body"     => "{$shopName} ยกเลิกคิว #{$queueNo} แล้ว",
        "url"      => queueUrl($queue_id),
      ];
    }

    $pdo->commit();

    foreach ($pushJobs as $job) {
      sendQueuePush(
        $pdo,
        (int)$job["queue_id"],
        $job["title"],
        $job["body"],
        $job["url"]
      );
    }

    goBack($shop_id);
  }

  $pdo->rollBack();
  http_response_code(400);
  echo "mode ไม่ถูกต้อง";
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  echo "DB Error: " . $e->getMessage();
  exit;
}