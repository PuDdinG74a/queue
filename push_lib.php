<?php
require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/config.php";

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

function buildQueueUrl(int $queueId): string {
  return APP_BASE . "/Frontend/my-queue.php?queue_id=" . $queueId;
}

function hasPushPageUrlColumn(PDO $pdo): bool {
  static $checked = null;
  if ($checked !== null) {
    return $checked;
  }

  try {
    $stmt = $pdo->query("SHOW COLUMNS FROM push_subscriptions LIKE 'page_url'");
    $checked = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    return $checked;
  } catch (Throwable $e) {
    $checked = false;
    return false;
  }
}

function getPushSubscriptionsByQueueId(PDO $pdo, int $queueId): array {
  if ($queueId <= 0) {
    return [];
  }

  $hasPageUrl = hasPushPageUrlColumn($pdo);

  $sql = "
    SELECT id, endpoint, p256dh_key, auth_key, user_agent
    " . ($hasPageUrl ? ", page_url" : "") . "
    FROM push_subscriptions
    WHERE queue_id = ?
      AND is_active = 1
    ORDER BY id DESC
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([$queueId]);

  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function deactivatePushSubscription(PDO $pdo, int $id): void {
  $stmt = $pdo->prepare("
    UPDATE push_subscriptions
    SET is_active = 0, updated_at = NOW()
    WHERE id = ?
  ");
  $stmt->execute([$id]);
}

function getPushIconUrl(): string {
  return APP_BASE . "/manifest-icon-192.png";
}

function shouldDeactivateFailedSubscription(string $reason): bool {
  $reason = mb_strtolower(trim($reason));

  if ($reason === '') return false;

  $keywords = ['expired', 'gone', 'unsubscribed', 'invalid', 'not found', '410', '404'];

  foreach ($keywords as $keyword) {
    if (mb_strpos($reason, $keyword) !== false) {
      return true;
    }
  }

  return false;
}

function buildPushPayload(
  int $queueId,
  string $title,
  string $body,
  ?string $url = null,
  ?string $tag = null
): string {
  $finalUrl = trim((string)$url);
  if ($finalUrl === '') {
    $finalUrl = buildQueueUrl($queueId);
  }

  $payload = [
    "title" => trim($title) !== '' ? trim($title) : "แจ้งเตือนคิว",
    "body"  => trim($body) !== '' ? trim($body) : "มีการอัปเดตสถานะคิวของคุณ",
    "url"   => $finalUrl,
    "tag"   => $tag ?: ("queue-" . $queueId),
    "icon"  => getPushIconUrl(),
    "badge" => getPushIconUrl(),
  ];

  return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function sendQueuePush(PDO $pdo, int $queueId, string $title, string $body, ?string $url = null): void {
  $queueId = (int)$queueId;
  if ($queueId <= 0) {
    return;
  }

  $subs = getPushSubscriptionsByQueueId($pdo, $queueId);
  if (!$subs) {
    error_log("Web Push skipped: no subscriptions for queue {$queueId}");
    return;
  }

  if (
    !defined('VAPID_PUBLIC_KEY') ||
    !defined('VAPID_PRIVATE_KEY') ||
    !defined('VAPID_SUBJECT') ||
    trim((string)VAPID_PUBLIC_KEY) === '' ||
    trim((string)VAPID_PRIVATE_KEY) === '' ||
    trim((string)VAPID_SUBJECT) === ''
  ) {
    error_log("Web Push skipped: VAPID config is missing.");
    return;
  }

  $payload = buildPushPayload($queueId, $title, $body, $url);
  if ($payload === false) {
    error_log("Web Push skipped: payload encode failed.");
    return;
  }

  $auth = [
    'VAPID' => [
      'subject' => VAPID_SUBJECT,
      'publicKey' => VAPID_PUBLIC_KEY,
      'privateKey' => VAPID_PRIVATE_KEY,
    ],
  ];

  $webPush = new WebPush($auth);
  $webPush->setAutomaticPadding(false);

  $endpointMap = [];

  foreach ($subs as $s) {
    $endpoint = trim((string)($s["endpoint"] ?? ""));
    $p256dh   = trim((string)($s["p256dh_key"] ?? ""));
    $authKey  = trim((string)($s["auth_key"] ?? ""));

    if ($endpoint === '' || $p256dh === '' || $authKey === '') {
      continue;
    }

    try {
      $subscription = Subscription::create([
        "endpoint" => $endpoint,
        "keys" => [
          "p256dh" => $p256dh,
          "auth"   => $authKey,
        ],
      ]);

      $webPush->queueNotification($subscription, $payload);
      $endpointMap[$endpoint] = (int)$s["id"];
    } catch (Throwable $e) {
      error_log("Push subscription create failed for endpoint {$endpoint}: " . $e->getMessage());
    }
  }

  foreach ($webPush->flush() as $report) {
    $endpoint = (string)$report->getRequest()->getUri();

    if ($report->isSuccess()) {
      error_log("Push sent successfully for queue {$queueId} to {$endpoint}");
      continue;
    }

    $reason = (string)$report->getReason();
    error_log("Push failed for {$endpoint}: " . $reason);

    if (isset($endpointMap[$endpoint]) && shouldDeactivateFailedSubscription($reason)) {
      deactivatePushSubscription($pdo, $endpointMap[$endpoint]);
    }
  }
}