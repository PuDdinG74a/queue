<?php

function pct($num, $den){
  if ((float)$den <= 0) return 0;
  return round(((float)$num / (float)$den) * 100, 1);
}

function hasColumn(PDO $pdo, string $table, string $column): bool {
  $allowedTables = ['queues', 'shops', 'locks', 'domes', 'shop_types', 'shop_categories'];

  if (!in_array($table, $allowedTables, true)) {
    return false;
  }

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

function statusTextTH(?string $status): string {
  return match((string)$status){
    "waiting"  => "รอดำเนินการ",
    "calling"  => "กำลังดำเนินการ",
    "served"   => "เสร็จสิ้น",
    "received" => "รับออเดอร์แล้ว",
    "cancel"   => "ยกเลิก",
    default    => $status ?: "-"
  };
}

function getReportFilters(): array {
  $today = date("Y-m-d");

  $range    = $_GET["range"] ?? "7days";
  $month    = $_GET["month"] ?? date("Y-m");
  $dateFrom = $_GET["date_from"] ?? date("Y-m-d", strtotime("-6 days"));
  $dateTo   = $_GET["date_to"] ?? $today;

  return [
    "range"     => $range,
    "month"     => $month,
    "date_from" => $dateFrom,
    "date_to"   => $dateTo,
    "shop"      => $_GET["shop"] ?? "all",
    "dome"      => $_GET["dome"] ?? "all",
    "category"  => $_GET["category"] ?? "all",
    "type"      => $_GET["type"] ?? "all",
    "status"    => $_GET["status"] ?? "all",
    "view"      => $_GET["view"] ?? "queue_detail",
    "group_by"  => $_GET["group_by"] ?? "dome",
    "q"         => trim($_GET["q"] ?? ""),
  ];
}

function buildReportDateRange(array $filters): array {
  $today = date("Y-m-d");

  switch ($filters["range"]) {
    case "today":
      $from = $today;
      $to   = $today;
      $label = "วันนี้";
      break;

    case "30days":
      $from = date("Y-m-d", strtotime("-29 days"));
      $to   = $today;
      $label = "30 วันล่าสุด";
      break;

    case "month":
      $month = preg_match('/^\d{4}\-\d{2}$/', $filters["month"]) ? $filters["month"] : date("Y-m");
      $from = $month . "-01";
      $to   = date("Y-m-t", strtotime($from));
      $label = "เดือน " . date("m/Y", strtotime($from));
      break;

    case "custom":
      $from = $filters["date_from"] ?: $today;
      $to   = $filters["date_to"] ?: $today;
      if ($from > $to) {
        [$from, $to] = [$to, $from];
      }
      $label = "ช่วงวันที่ " . date("d/m/Y", strtotime($from)) . " - " . date("d/m/Y", strtotime($to));
      break;

    case "7days":
    default:
      $from = date("Y-m-d", strtotime("-6 days"));
      $to   = $today;
      $label = "7 วันล่าสุด";
      break;
  }

  return [$from, $to, $label];
}

function getReportData(PDO $pdo, array $filters): array {
  [$dateFrom, $dateTo, $rangeLabel] = buildReportDateRange($filters);

  $hasServedAt      = hasColumn($pdo, "queues", "served_at");
  $hasCalledAt      = hasColumn($pdo, "queues", "called_at");
  $hasCustomerNote  = hasColumn($pdo, "queues", "customer_note");
  $hasCustomerPhone = hasColumn($pdo, "queues", "customer_phone");
  $hasCustomerName  = hasColumn($pdo, "queues", "customer_name");

  $shopsList = $pdo->query("
    SELECT shop_id, name
    FROM shops
    ORDER BY name ASC
  ")->fetchAll(PDO::FETCH_ASSOC);

  $domesList = $pdo->query("
    SELECT dome_id, dome_name
    FROM domes
    ORDER BY dome_name ASC
  ")->fetchAll(PDO::FETCH_ASSOC);

  $categoriesList = $pdo->query("
    SELECT category_id, category_name
    FROM shop_categories
    ORDER BY category_name ASC
  ")->fetchAll(PDO::FETCH_ASSOC);

  $typesList = $pdo->query("
    SELECT type_id, type_name
    FROM shop_types
    ORDER BY type_name ASC
  ")->fetchAll(PDO::FETCH_ASSOC);

  $where = [];
  $params = [];

  $where[] = "q.queue_date BETWEEN ? AND ?";
  $params[] = $dateFrom;
  $params[] = $dateTo;

  if ($filters["shop"] !== "all") {
    $where[] = "q.shop_id = ?";
    $params[] = (int)$filters["shop"];
  }

  if ($filters["dome"] !== "all") {
    $where[] = "d.dome_id = ?";
    $params[] = (int)$filters["dome"];
  }

  if ($filters["category"] !== "all") {
    $where[] = "c.category_id = ?";
    $params[] = (int)$filters["category"];
  }

  if ($filters["type"] !== "all") {
    $where[] = "t.type_id = ?";
    $params[] = (int)$filters["type"];
  }

  if ($filters["status"] !== "all") {
    $where[] = "q.status = ?";
    $params[] = $filters["status"];
  }

  if (!empty($filters["q"])) {
    $searchParts = [
      "CAST(q.queue_id AS CHAR) LIKE ?",
      "CAST(q.queue_no AS CHAR) LIKE ?",
      "s.name LIKE ?"
    ];

    if ($hasCustomerName) {
      $searchParts[] = "q.customer_name LIKE ?";
    }
    if ($hasCustomerPhone) {
      $searchParts[] = "q.customer_phone LIKE ?";
    }
    if ($hasCustomerNote) {
      $searchParts[] = "q.customer_note LIKE ?";
    }

    $where[] = "(" . implode(" OR ", $searchParts) . ")";

    $kw = "%" . $filters["q"] . "%";
    $params[] = $kw; // queue_id
    $params[] = $kw; // queue_no
    $params[] = $kw; // shop name

    if ($hasCustomerName) {
      $params[] = $kw;
    }
    if ($hasCustomerPhone) {
      $params[] = $kw;
    }
    if ($hasCustomerNote) {
      $params[] = $kw;
    }
  }

  $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

  $baseJoin = "
    FROM queues q
    LEFT JOIN shops s ON s.shop_id = q.shop_id
    LEFT JOIN locks l ON l.lock_id = s.lock_id
    LEFT JOIN domes d ON d.dome_id = l.dome_id
    LEFT JOIN shop_types t ON t.type_id = s.type_id
    LEFT JOIN shop_categories c ON c.category_id = t.category_id
  ";

  $avgExpr = $hasServedAt
    ? "ROUND(AVG(CASE WHEN q.status IN ('served','received') AND q.served_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, q.created_at, q.served_at) END),1) AS avg_min"
    : "NULL AS avg_min";

  $sqlSummary = "
    SELECT
      COUNT(*) AS total_cnt,
      SUM(q.status='waiting') AS waiting_cnt,
      SUM(q.status='calling') AS calling_cnt,
      SUM(q.status='served') AS served_cnt,
      SUM(q.status='received') AS received_cnt,
      SUM(q.status='cancel') AS cancel_cnt,
      $avgExpr
    $baseJoin
    $whereSql
  ";
  $stmt = $pdo->prepare($sqlSummary);
  $stmt->execute($params);
  $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

  $total       = (int)($summary["total_cnt"] ?? 0);
  $waitingCnt  = (int)($summary["waiting_cnt"] ?? 0);
  $callingCnt  = (int)($summary["calling_cnt"] ?? 0);
  $servedCnt   = (int)($summary["served_cnt"] ?? 0);
  $receivedCnt = (int)($summary["received_cnt"] ?? 0);
  $cancelCnt   = (int)($summary["cancel_cnt"] ?? 0);
  $pendingCnt  = $waitingCnt + $callingCnt;
  $avgMin      = $summary["avg_min"] !== null ? (float)$summary["avg_min"] : null;
  $successRate = pct($receivedCnt, $total);
  $cancelRate  = pct($cancelCnt, $total);

  $sqlByDay = "
    SELECT
      q.queue_date AS day,
      COUNT(*) AS total_cnt,
      SUM(q.status='waiting') AS waiting_cnt,
      SUM(q.status='calling') AS calling_cnt,
      SUM(q.status='served') AS served_cnt,
      SUM(q.status='received') AS received_cnt,
      SUM(q.status='cancel') AS cancel_cnt
    $baseJoin
    $whereSql
    GROUP BY q.queue_date
    ORDER BY q.queue_date ASC
  ";
  $stmt = $pdo->prepare($sqlByDay);
  $stmt->execute($params);
  $byDay = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $maxDay = 0;
  foreach ($byDay as $r) {
    $maxDay = max($maxDay, (int)$r["total_cnt"]);
  }

  $avgPerDay = count($byDay) > 0 ? round($total / count($byDay), 1) : 0;
  $peakDay = null;
  if ($byDay) {
    $sortedPeak = $byDay;
    usort($sortedPeak, fn($a, $b) => (int)$b["total_cnt"] <=> (int)$a["total_cnt"]);
    $peakDay = $sortedPeak[0] ?? null;
  }

  $avgCallExpr = $hasCalledAt
    ? "ROUND(AVG(CASE WHEN q.called_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, q.created_at, q.called_at) END),1) AS avg_call_min"
    : "NULL AS avg_call_min";

  $avgDoneExpr = $hasServedAt
    ? "ROUND(AVG(CASE WHEN q.status IN ('served','received') AND q.served_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, q.created_at, q.served_at) END),1) AS avg_min"
    : "NULL AS avg_min";

  $minDoneExpr = $hasServedAt
    ? "MIN(CASE WHEN q.served_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, q.created_at, q.served_at) END) AS min_done_min"
    : "NULL AS min_done_min";

  $maxDoneExpr = $hasServedAt
    ? "MAX(CASE WHEN q.served_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, q.created_at, q.served_at) END) AS max_done_min"
    : "NULL AS max_done_min";

  $sqlByShop = "
    SELECT
      s.shop_id,
      s.name AS shop_name,
      COALESCE(d.dome_name, '-') AS dome_name,
      COALESCE(c.category_name, '-') AS category_name,
      COALESCE(t.type_name, '-') AS type_name,
      COUNT(*) AS total_cnt,
      SUM(q.status='waiting') AS waiting_cnt,
      SUM(q.status='calling') AS calling_cnt,
      SUM(q.status='served') AS served_cnt,
      SUM(q.status='received') AS received_cnt,
      SUM(q.status='cancel') AS cancel_cnt,
      $avgCallExpr,
      $avgDoneExpr,
      $minDoneExpr,
      $maxDoneExpr
    $baseJoin
    $whereSql
    GROUP BY s.shop_id, s.name, d.dome_name, c.category_name, t.type_name
    ORDER BY total_cnt DESC, s.name ASC
  ";
  $stmt = $pdo->prepare($sqlByShop);
  $stmt->execute($params);
  $byShop = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $maxShop = 0;
  foreach ($byShop as $r) {
    $maxShop = max($maxShop, (int)$r["total_cnt"]);
  }
  $topShop = $byShop[0] ?? null;

  $groupAvgExpr = $hasServedAt
    ? "ROUND(AVG(CASE WHEN q.status IN ('served','received') AND q.served_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, q.created_at, q.served_at) END),1) AS avg_min"
    : "NULL AS avg_min";

  $sqlByDome = "
    SELECT
      COALESCE(d.dome_name, '-') AS dome_name,
      COUNT(*) AS total_cnt,
      COUNT(DISTINCT s.shop_id) AS shop_cnt,
      SUM(q.status='received') AS received_cnt,
      SUM(q.status='cancel') AS cancel_cnt,
      $groupAvgExpr
    $baseJoin
    $whereSql
    GROUP BY d.dome_id, d.dome_name
    ORDER BY total_cnt DESC, dome_name ASC
  ";
  $stmt = $pdo->prepare($sqlByDome);
  $stmt->execute($params);
  $byDome = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $sqlByCategory = "
    SELECT
      COALESCE(c.category_name, '-') AS category_name,
      COUNT(*) AS total_cnt,
      COUNT(DISTINCT s.shop_id) AS shop_cnt,
      SUM(q.status='received') AS received_cnt,
      SUM(q.status='cancel') AS cancel_cnt,
      $groupAvgExpr
    $baseJoin
    $whereSql
    GROUP BY c.category_id, c.category_name
    ORDER BY total_cnt DESC, category_name ASC
  ";
  $stmt = $pdo->prepare($sqlByCategory);
  $stmt->execute($params);
  $byCategory = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $maxCategory = 0;
  foreach ($byCategory as $r) {
    $maxCategory = max($maxCategory, (int)$r["total_cnt"]);
  }

  $sqlByType = "
    SELECT
      COALESCE(t.type_name, '-') AS type_name,
      COUNT(*) AS total_cnt,
      COUNT(DISTINCT s.shop_id) AS shop_cnt,
      SUM(q.status='received') AS received_cnt,
      SUM(q.status='cancel') AS cancel_cnt,
      $groupAvgExpr
    $baseJoin
    $whereSql
    GROUP BY t.type_id, t.type_name
    ORDER BY total_cnt DESC, type_name ASC
  ";
  $stmt = $pdo->prepare($sqlByType);
  $stmt->execute($params);
  $byType = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $maxType = 0;
  foreach ($byType as $r) {
    $maxType = max($maxType, (int)$r["total_cnt"]);
  }

  $customerNoteSelect = $hasCustomerNote
    ? "COALESCE(q.customer_note, '-') AS customer_note,"
    : "'-' AS customer_note,";

  if ($hasServedAt && $hasCalledAt) {
    $durationExpr = "
      CASE
        WHEN q.served_at IS NOT NULL THEN CONCAT(TIMESTAMPDIFF(MINUTE, q.created_at, q.served_at), ' นาที/ออเดอร์')
        WHEN q.called_at IS NOT NULL THEN CONCAT(TIMESTAMPDIFF(MINUTE, q.created_at, q.called_at), ' นาที (ถึงขั้นตอนดำเนินการ)')
        ELSE '-'
      END
    ";
  } elseif ($hasCalledAt) {
    $durationExpr = "
      CASE
        WHEN q.called_at IS NOT NULL THEN CONCAT(TIMESTAMPDIFF(MINUTE, q.created_at, q.called_at), ' นาที (ถึงขั้นตอนดำเนินการ)')
        ELSE '-'
      END
    ";
  } elseif ($hasServedAt) {
    $durationExpr = "
      CASE
        WHEN q.served_at IS NOT NULL THEN CONCAT(TIMESTAMPDIFF(MINUTE, q.created_at, q.served_at), ' นาที/ออเดอร์')
        ELSE '-'
      END
    ";
  } else {
    $durationExpr = "'-'";
  }

  $calledAtSelect = $hasCalledAt
    ? "CASE WHEN q.called_at IS NULL THEN '' ELSE DATE_FORMAT(q.called_at, '%d/%m/%Y %H:%i') END AS called_at,"
    : "'' AS called_at,";

  $servedAtSelect = $hasServedAt
    ? "CASE WHEN q.served_at IS NULL THEN '' ELSE DATE_FORMAT(q.served_at, '%d/%m/%Y %H:%i') END AS served_at,"
    : "'' AS served_at,";

  $customerPhoneSelect = $hasCustomerPhone
    ? "COALESCE(q.customer_phone, '-') AS customer_phone,"
    : "'-' AS customer_phone,";

  $customerNameSelect = $hasCustomerName
    ? "COALESCE(q.customer_name, '-') AS customer_name,"
    : "'-' AS customer_name,";

  $sqlDetails = "
    SELECT
      q.queue_id,
      q.queue_date,
      q.queue_no,
      COALESCE(s.name, '-') AS shop_name,
      COALESCE(d.dome_name, '-') AS dome_name,
      COALESCE(c.category_name, '-') AS category_name,
      COALESCE(t.type_name, '-') AS type_name,
      $customerNameSelect
      $customerPhoneSelect
      $customerNoteSelect
      q.status,
      DATE_FORMAT(q.created_at, '%d/%m/%Y %H:%i') AS created_at,
      $calledAtSelect
      $servedAtSelect
      $durationExpr AS duration_text
    $baseJoin
    $whereSql
    ORDER BY q.queue_date DESC, q.queue_no DESC, q.queue_id DESC
    LIMIT 500
  ";
  $stmt = $pdo->prepare($sqlDetails);
  $stmt->execute($params);
  $queueDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $insights = [];

  if ($total > 0) {
    $insights[] = "มีออเดอร์ทั้งหมด {$total} รายการในช่วงที่เลือก";
    $insights[] = "อัตราการดำเนินการสำเร็จอยู่ที่ {$successRate}%";
    $insights[] = "อัตราการยกเลิกอยู่ที่ {$cancelRate}%";
  }

  if ($topShop && !empty($topShop["shop_name"])) {
    $insights[] = "ร้านที่มีออเดอร์สูงสุดคือ " . $topShop["shop_name"] . " จำนวน " . (int)$topShop["total_cnt"] . " รายการ";
  }

  if ($peakDay && !empty($peakDay["day"])) {
    $insights[] = "วันที่มีออเดอร์สูงสุดคือ " . $peakDay["day"] . " จำนวน " . (int)$peakDay["total_cnt"] . " รายการ";
  }

  if ($avgMin !== null) {
    $insights[] = "เวลาเฉลี่ยจนเสร็จต่อออเดอร์ประมาณ {$avgMin} นาที";
  }

  if ($pendingCnt > 0) {
    $insights[] = "ยังมีออเดอร์ค้างอยู่ {$pendingCnt} รายการ (รอดำเนินการ + กำลังดำเนินการ)";
  }

  return [
    "filters"        => array_merge($filters, [
      "date_from" => $dateFrom,
      "date_to"   => $dateTo,
    ]),
    "rangeLabel"     => $rangeLabel,
    "shopsList"      => $shopsList,
    "domesList"      => $domesList,
    "categoriesList" => $categoriesList,
    "typesList"      => $typesList,

    "total"          => $total,
    "waitingCnt"     => $waitingCnt,
    "callingCnt"     => $callingCnt,
    "servedCnt"      => $servedCnt,
    "receivedCnt"    => $receivedCnt,
    "cancelCnt"      => $cancelCnt,
    "pendingCnt"     => $pendingCnt,
    "successRate"    => $successRate,
    "cancelRate"     => $cancelRate,
    "avgMin"         => $avgMin,
    "avgPerDay"      => $avgPerDay,
    "topShop"        => $topShop,
    "peakDay"        => $peakDay,
    "insights"       => $insights,
    "hasServedAt"    => $hasServedAt,

    "byDay"          => $byDay,
    "maxDay"         => $maxDay,
    "byShop"         => $byShop,
    "maxShop"        => $maxShop,
    "byDome"         => $byDome,
    "byCategory"     => $byCategory,
    "maxCategory"    => $maxCategory,
    "byType"         => $byType,
    "maxType"        => $maxType,
    "queueDetails"   => $queueDetails,
  ];
}