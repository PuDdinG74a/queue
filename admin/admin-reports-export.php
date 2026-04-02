<?php
require_once __DIR__ . "/_auth.php";
require_once __DIR__ . "/_reports_data.php";

$filters = getReportFilters();
$report  = getReportData($pdo, $filters);

function csvRow($out, array $row): void {
  fputcsv($out, $row);
}

function labelFromList(array $list, string $keyField, string $nameField, $selected, string $default = "ทั้งหมด"): string {
  if ($selected === "all" || $selected === "" || $selected === null) {
    return $default;
  }
  foreach ($list as $item) {
    if ((string)($item[$keyField] ?? "") === (string)$selected) {
      return (string)($item[$nameField] ?? $default);
    }
  }
  return $default;
}

/* ✅ เปลี่ยนเป็นภาษาฝั่ง "ออเดอร์" */
function statusFilterLabel(string $status): string {
  return match($status) {
    "waiting"  => "รอดำเนินการ",
    "calling"  => "กำลังดำเนินการ",
    "served"   => "เสร็จสิ้น",
    "received" => "รับออเดอร์แล้ว",
    "cancel"   => "ยกเลิก",
    default    => "ทั้งหมด",
  };
}

$view = $filters["view"] ?? "queue_detail";
$groupBy = $filters["group_by"] ?? "dome";

$viewTitle = match($view){
  "summary" => "รายงานสรุปออเดอร์",
  "by_shop" => "รายงานวิเคราะห์ออเดอร์รายร้าน",
  "by_group" => "รายงานวิเคราะห์ออเดอร์ตามกลุ่มร้าน",
  "service_performance" => "รายงานประสิทธิภาพออเดอร์",
  default => "รายงานรายละเอียดออเดอร์"
};

$groupLabel = match($groupBy){
  "category" => "หมวดหมู่ร้าน",
  "type"     => "ประเภทร้าน",
  default    => "โดม"
};

$shopLabel = labelFromList($report["shopsList"], "shop_id", "name", $filters["shop"] ?? "all");
$domeLabel = labelFromList($report["domesList"], "dome_id", "dome_name", $filters["dome"] ?? "all");
$categoryLabel = labelFromList($report["categoriesList"], "category_id", "category_name", $filters["category"] ?? "all");
$typeLabel = labelFromList($report["typesList"], "type_id", "type_name", $filters["type"] ?? "all");
$statusLabel = statusFilterLabel($filters["status"] ?? "all");

$filename = "admin_orders_" . ($view ?: "detail") . "_" . date("Ymd_His") . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

$out = fopen("php://output", "w");
fwrite($out, "\xEF\xBB\xBF");

/* ===== HEADER ===== */
csvRow($out, [$viewTitle]);
csvRow($out, ["ช่วงข้อมูล", $report["rangeLabel"] ?? "-"]);
csvRow($out, ["ร้าน", $shopLabel]);
csvRow($out, ["โดม", $domeLabel]);
csvRow($out, ["หมวดหมู่", $categoryLabel]);
csvRow($out, ["ประเภทร้าน", $typeLabel]);
csvRow($out, ["สถานะออเดอร์", $statusLabel]);
csvRow($out, ["คำค้นหา", trim((string)($filters["q"] ?? "")) !== "" ? $filters["q"] : "-"]);
csvRow($out, ["มุมมองรายงาน", $viewTitle]);

if ($view === "by_group") {
  csvRow($out, ["จัดกลุ่มตาม", $groupLabel]);
}

csvRow($out, ["ผู้ส่งออก", $admin_name ?? "Admin"]);
csvRow($out, ["วันเวลาที่ส่งออก", date("Y-m-d H:i:s")]);
csvRow($out, []);

/* ===== DETAIL ===== */
if ($view === "queue_detail") {

  csvRow($out, ["รายละเอียดออเดอร์"]);
  csvRow($out, [
    "order_id", "โดม", "ร้าน", "หมวดหมู่", "ประเภท", "เลขออเดอร์", "วันที่",
    "ลูกค้า", "เบอร์โทร", "รายละเอียดออเดอร์",
    "สร้างออเดอร์เมื่อ", "เริ่มดำเนินการเมื่อ", "เสร็จสิ้นเมื่อ",
    "สถานะ", "ระยะเวลา"
  ]);

  foreach ($report["queueDetails"] as $row) {
    csvRow($out, [
      $row["queue_id"] ?? "-",
      $row["dome_name"] ?? "-",
      $row["shop_name"] ?? "-",
      $row["category_name"] ?? "-",
      $row["type_name"] ?? "-",
      $row["queue_no"] ?? "-",
      $row["queue_date"] ?? "-",
      $row["customer_name"] ?? "-",
      $row["customer_phone"] ?? "-",
      $row["customer_note"] ?? "-",
      $row["created_at"] ?? "-",
      $row["called_at"] ?: "-",
      $row["served_at"] ?: "-",
      statusTextTH($row["status"] ?? ""),
      $row["duration_text"] ?? "-",
    ]);
  }

/* ===== SUMMARY ===== */
} elseif ($view === "summary") {

  csvRow($out, ["สรุป KPI"]);
  csvRow($out, ["ออเดอร์ทั้งหมด", $report["total"] . " รายการ"]);
  csvRow($out, ["รอดำเนินการ", $report["waitingCnt"] . " รายการ"]);
  csvRow($out, ["กำลังดำเนินการ", $report["callingCnt"] . " รายการ"]);
  csvRow($out, ["เสร็จสิ้น", $report["servedCnt"] . " รายการ"]);
  csvRow($out, ["รับแล้ว", $report["receivedCnt"] . " รายการ"]);
  csvRow($out, ["ยกเลิก", $report["cancelCnt"] . " รายการ"]);
  csvRow($out, ["ออเดอร์ค้าง", $report["pendingCnt"] . " รายการ"]);
  csvRow($out, ["อัตราสำเร็จ", $report["successRate"] . "%"]);
  csvRow($out, ["อัตรายกเลิก", $report["cancelRate"] . "%"]);
  csvRow($out, ["เวลาเฉลี่ยต่อออเดอร์", $report["avgMin"] === null ? "-" : $report["avgMin"] . " นาที"]);
  csvRow($out, ["ร้านออเดอร์สูงสุด", $report["topShop"]["shop_name"] ?? "-"]);
  csvRow($out, ["วันที่ออเดอร์สูงสุด", $report["peakDay"]["day"] ?? "-"]);
  csvRow($out, ["ออเดอร์เฉลี่ยต่อวัน", $report["avgPerDay"] . " รายการ/วัน"]);
  csvRow($out, []);

  csvRow($out, ["ข้อสังเกต"]);
  foreach ($report["insights"] as $item) {
    csvRow($out, [$item]);
  }

/* ===== BY SHOP ===== */
} elseif ($view === "by_shop") {

  csvRow($out, ["วิเคราะห์ออเดอร์รายร้าน"]);
  csvRow($out, [
    "ร้าน","โดม","หมวดหมู่","ประเภท",
    "จำนวนออเดอร์","รอ","กำลังดำเนินการ",
    "สำเร็จ","ยกเลิก","อัตราสำเร็จ(%)",
    "เวลาเฉลี่ยต่อออเดอร์","เวลาเฉลี่ยถึงเรียก",
    "สั้นสุด","นานสุด","สัดส่วน(%)"
  ]);

  foreach ($report["byShop"] as $row) {
    $cnt  = (int)($row["total_cnt"] ?? 0);
    $done = (int)($row["received_cnt"] ?? 0);

    csvRow($out, [
      $row["shop_name"],
      $row["dome_name"],
      $row["category_name"],
      $row["type_name"],
      $cnt,
      $row["waiting_cnt"],
      $row["calling_cnt"],
      $row["served_cnt"],
      $done,
      pct($done, $cnt),
      $row["avg_min"] === null ? "-" : $row["avg_min"] . " นาที/ออเดอร์",
      $row["avg_call_min"] === null ? "-" : $row["avg_call_min"] . " นาที",
      $row["min_done_min"] === null ? "-" : $row["min_done_min"],
      $row["max_done_min"] === null ? "-" : $row["max_done_min"],
      pct($cnt, $report["total"]),
    ]);
  }

/* ===== PERFORMANCE ===== */
} elseif ($view === "service_performance") {

  csvRow($out, ["ประสิทธิภาพออเดอร์"]);
  csvRow($out, [
    "ร้าน","โดม","จำนวนออเดอร์",
    "เวลาเฉลี่ยถึงเรียก",
    "เวลาเฉลี่ยจนเสร็จ",
    "สั้นสุด","นานสุด",
    "สำเร็จ","ยกเลิก","อัตราสำเร็จ(%)"
  ]);

  foreach ($report["byShop"] as $row) {
    $cnt  = (int)($row["total_cnt"] ?? 0);
    $done = (int)($row["received_cnt"] ?? 0);

    csvRow($out, [
      $row["shop_name"],
      $row["dome_name"],
      $cnt . " รายการ",
      $row["avg_call_min"] === null ? "-" : $row["avg_call_min"] . " นาที",
      $row["avg_min"] === null ? "-" : $row["avg_min"] . " นาที/ออเดอร์",
      $row["min_done_min"] ?? "-",
      $row["max_done_min"] ?? "-",
      $done,
      $row["cancel_cnt"] ?? 0,
      pct($done, $cnt),
    ]);
  }

}

fclose($out);
exit;