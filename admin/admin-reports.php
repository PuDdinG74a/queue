<?php
require_once __DIR__ . "/_auth.php";
require_once __DIR__ . "/_reports_data.php";

$current_page = basename($_SERVER["PHP_SELF"]);
$report = getReportData($pdo, getReportFilters());
extract($report);

function h($str){
  return htmlspecialchars((string)$str, ENT_QUOTES, "UTF-8");
}

$view = $filters["view"] ?? "queue_detail";
$groupBy = $filters["group_by"] ?? "dome";
$q = trim((string)($filters["q"] ?? ""));

$viewTitle = match($view){
  "summary" => "รายงานสรุปภาพรวม",
  "by_shop" => "รายงานวิเคราะห์รายร้าน",
  "by_group" => "รายงานวิเคราะห์ตามกลุ่มร้าน",
  "service_performance" => "รายงานประสิทธิภาพการจัดการออเดอร์",
  default => "รายงานรายละเอียดออเดอร์"
};

$exportQuery = http_build_query($filters);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>รายงาน | Admin</title>
  <style>
    :root{
      --bg:#f5f7fb;
      --card:#ffffff;
      --text:#0f172a;
      --muted:#667085;
      --border:#dbe2ea;
      --border-strong:#cfd8e3;
      --primary:#2563eb;
      --sidebar:#0f172a;
    }

    *{ box-sizing:border-box; }

    body{
      margin:0;
      font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;
      background:var(--bg);
      color:var(--text);
    }

    .app{
      display:flex;
      min-height:100vh;
    }

    .sidebar{
      width:260px;
      min-width:260px;
      background:var(--sidebar);
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

    .brand small{ color:rgba(255,255,255,.75); }

    .admin-user-box{
      margin:14px 0;
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
      line-height:1.35;
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
      align-items:center;
      gap:10px;
    }

    .nav a:hover{ background:rgba(255,255,255,.08); }

    .nav a.active{
      background:rgba(37,99,235,.22);
      border:1px solid rgba(37,99,235,.35);
    }

    .main{
      flex:1;
      min-width:0;
      padding:18px;
    }

    .page-head{
      display:flex;
      justify-content:space-between;
      align-items:flex-start;
      gap:12px;
      flex-wrap:wrap;
      margin-bottom:14px;
    }

    .page-head h1{
      margin:0;
      font-size:30px;
      line-height:1.15;
    }

    .sub{
      color:var(--muted);
      margin-top:4px;
      font-size:14px;
    }

    .top-actions{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
    }

    .card{
      background:var(--card);
      border:1px solid var(--border);
      border-radius:24px;
      padding:16px;
      box-shadow:0 1px 2px rgba(16,24,40,.03);
      margin-bottom:14px;
    }

    .btn{
      padding:10px 16px;
      border-radius:14px;
      border:1px solid var(--border-strong);
      background:#fff;
      color:var(--text);
      font-size:14px;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      cursor:pointer;
      min-height:42px;
    }

    .btn:hover{ filter:brightness(.98); }

    .btn.primary{
      background:var(--primary);
      color:#fff;
      border-color:transparent;
    }

    .filters-grid{
      display:grid;
      grid-template-columns:repeat(4, minmax(0, 1fr));
      gap:14px;
    }

    .field{
      display:flex;
      flex-direction:column;
      gap:7px;
      min-width:0;
    }

    .field.full{
      grid-column:1 / -1;
    }

    label{
      font-size:14px;
      color:#4b5563;
      font-weight:500;
    }

    input, select{
      width:100%;
      border:1px solid var(--border-strong);
      border-radius:16px;
      padding:12px 14px;
      background:#fff;
      outline:none;
      min-height:46px;
      font-size:15px;
      color:var(--text);
    }

    input:focus, select:focus{
      border-color:rgba(37,99,235,.55);
      box-shadow:0 0 0 4px rgba(37,99,235,.1);
    }

    .filter-actions{
      margin-top:14px;
      display:flex;
      gap:10px;
      flex-wrap:wrap;
    }

    .stats-grid{
      display:grid;
      grid-template-columns:repeat(5, minmax(0, 1fr));
      gap:12px;
    }

    .stat-box{
      border:1px solid var(--border);
      border-radius:18px;
      padding:14px 16px;
      background:#fff;
    }

    .stat-label{
      color:#6b7280;
      font-size:14px;
      margin-bottom:8px;
    }

    .stat-value{
      font-size:18px;
      font-weight:800;
      line-height:1.2;
    }

    .section-title{
      margin:0 0 12px;
      font-size:20px;
      font-weight:800;
    }

    .table-meta{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
      margin-bottom:12px;
      color:var(--muted);
      font-size:14px;
    }

    .chip{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:6px 10px;
      border-radius:999px;
      border:1px solid var(--border);
      background:#fff;
      font-size:12px;
      font-weight:700;
      color:#475467;
    }

    .chip-wrap{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
    }

    .table-wrap{
      overflow:auto;
      border:1px solid var(--border);
      border-radius:18px;
      background:#fff;
    }

    table{
      width:100%;
      min-width:1100px;
      border-collapse:separate;
      border-spacing:0;
    }

    th, td{
      padding:14px 12px;
      border-bottom:1px solid var(--border);
      text-align:left;
      vertical-align:top;
      font-size:14px;
    }

    th{
      background:#f8fafc;
      color:#374151;
      font-weight:700;
      white-space:nowrap;
    }

    tbody tr:last-child td{
      border-bottom:none;
    }

    .muted{
      color:var(--muted);
      font-size:13px;
    }

    .badge{
      display:inline-block;
      padding:6px 10px;
      border-radius:999px;
      font-size:12px;
      font-weight:700;
      border:1px solid var(--border);
      background:#fff;
      white-space:nowrap;
    }

    .badge.waiting{
      background:#eff6ff;
      color:#1d4ed8;
      border-color:#bfdbfe;
    }
    .badge.calling{
      background:#fff7ed;
      color:#b45309;
      border-color:#fed7aa;
    }
    .badge.served{
      background:#f0fdf4;
      color:#15803d;
      border-color:#bbf7d0;
    }
    .badge.received{
      background:#dcfce7;
      color:#166534;
      border-color:#86efac;
    }
    .badge.cancel{
      background:#fef2f2;
      color:#b91c1c;
      border-color:#fecaca;
    }

    .two-col{
      display:grid;
      grid-template-columns:1.1fr .9fr;
      gap:14px;
    }

    .insight-list{
      margin:0;
      padding-left:18px;
      color:#374151;
      line-height:1.7;
      font-size:14px;
    }

    .kpi-grid{
      display:grid;
      grid-template-columns:repeat(4, minmax(0,1fr));
      gap:12px;
      margin-bottom:14px;
    }

    .kpi-card{
      border:1px solid var(--border);
      border-radius:18px;
      padding:14px 16px;
      background:#fff;
    }

    .kpi-card .label{
      color:#6b7280;
      font-size:13px;
      margin-bottom:8px;
    }

    .kpi-card .value{
      font-size:26px;
      font-weight:900;
      line-height:1.15;
    }

    .report-print-header{
      display:none;
    }

    .print-footer{
      display:none;
    }

    @media (max-width: 1280px){
      .filters-grid{
        grid-template-columns:repeat(2, minmax(0, 1fr));
      }
      .stats-grid{
        grid-template-columns:repeat(2, minmax(0, 1fr));
      }
      .kpi-grid{
        grid-template-columns:repeat(2, minmax(0,1fr));
      }
      .two-col{
        grid-template-columns:1fr;
      }
    }

    @media (max-width: 980px){
      .sidebar{ display:none; }
    }

    @media (max-width: 700px){
      .filters-grid,
      .stats-grid,
      .kpi-grid{
        grid-template-columns:1fr;
      }
      .main{
        padding:12px;
      }
      .page-head h1{
        font-size:26px;
      }
    }

    @page{
      size:auto;
      margin:14mm 10mm 14mm 10mm;
    }

    @media print{
      html, body{
        background:#fff !important;
        color:#000 !important;
      }

      .sidebar,
      .top-actions,
      .filters,
      .filter-actions,
      .btn{
        display:none !important;
      }

      .main{
        padding:0 !important;
      }

      .page-head{
        margin-bottom:10px !important;
      }

      .page-head h1,
      .page-head .sub{
        display:none !important;
      }

      .report-print-header{
        display:block !important;
        margin-bottom:12px;
        padding-bottom:10px;
        border-bottom:1px solid #999;
      }

      .report-print-header h2{
        margin:0 0 8px;
        font-size:20px;
        line-height:1.2;
        color:#000;
      }

      .report-print-header .meta{
        display:grid;
        grid-template-columns:repeat(2, minmax(0,1fr));
        gap:4px 12px;
        font-size:12px;
        color:#222;
      }

      .card{
        box-shadow:none !important;
        border:1px solid #bbb !important;
        border-radius:10px !important;
        break-inside:avoid;
        page-break-inside:avoid;
        margin-bottom:10px !important;
      }

      .table-wrap{
        border:1px solid #bbb !important;
        border-radius:0 !important;
        overflow:visible !important;
      }

      table{
        min-width:0 !important;
        width:100% !important;
        border-collapse:collapse !important;
        page-break-inside:auto;
      }

      th, td{
        border:1px solid #ccc !important;
        padding:6px 7px !important;
        font-size:11px !important;
        color:#000 !important;
        word-break:break-word;
      }

      th{
        background:#eee !important;
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
      }

      tr{
        page-break-inside:avoid;
        page-break-after:auto;
      }

      thead{
        display:table-header-group;
      }

      .stats-grid,
      .kpi-grid{
        grid-template-columns:repeat(4, minmax(0,1fr)) !important;
        gap:8px !important;
      }

      .stat-box,
      .kpi-card{
        border:1px solid #bbb !important;
        border-radius:8px !important;
        padding:8px 10px !important;
        background:#fff !important;
      }

      .stat-label,
      .kpi-card .label{
        font-size:11px !important;
        color:#333 !important;
      }

      .stat-value,
      .kpi-card .value{
        font-size:18px !important;
        color:#000 !important;
      }

      .table-meta{
        margin-bottom:8px !important;
      }

      .chip{
        border:1px solid #bbb !important;
        background:#fff !important;
        color:#000 !important;
        font-size:11px !important;
      }

      .muted{
        color:#333 !important;
      }

      .badge{
        border:1px solid #999 !important;
        background:#fff !important;
        color:#000 !important;
        font-size:11px !important;
      }

      .print-footer{
        display:block !important;
        position:fixed;
        bottom:0;
        left:0;
        right:0;
        text-align:center;
        font-size:11px;
        color:#444;
        border-top:1px solid #bbb;
        padding-top:4px;
        background:#fff;
      }
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
        <a class="<?= $current_page === 'admin-dashboard.php' ? 'active' : '' ?>" href="admin-dashboard.php">📊 แดชบอร์ด</a>
        <a class="<?= $current_page === 'admin-domes-locks.php' ? 'active' : '' ?>" href="admin-domes-locks.php">🧩 โดม/ล็อก</a>
        <a class="<?= $current_page === 'admin-shops.php' ? 'active' : '' ?>" href="admin-shops.php">🏪 จัดการร้านค้า</a>
        <a class="<?= $current_page === 'admin-shop-categories.php' ? 'active' : '' ?>" href="admin-shop-categories.php">📁 หมวดหมู่ร้าน</a>
        <a class="<?= $current_page === 'admin-shop-types.php' ? 'active' : '' ?>" href="admin-shop-types.php">🧩 ประเภทร้าน</a>
        <a class="<?= $current_page === 'admin-history.php' ? 'active' : '' ?>" href="admin-history.php">🧾 ประวัติคิว</a>
        <a class="<?= $current_page === 'admin-reports.php' ? 'active' : '' ?>" href="admin-reports.php">📈 รายงาน</a>
        <a href="admin-shop-accounts.php">🔐 บัญชีร้านค้า</a>
        <a href="../logout.php">🚪 ออกจากระบบ</a>
        <a href="../Frontend/index.php">↩ กลับหน้าเว็บ</a>
      </nav>
    </aside>

    <main class="main">
      <div class="page-head">
        <div>
          <h1>📈 รายงาน</h1>
          <div class="sub">เลือกมุมมองรายงาน กรองข้อมูล และส่งออกตามเงื่อนไขที่เลือก</div>
        </div>

        <div class="top-actions">
          <button class="btn" type="button" onclick="location.reload()">รีเฟรช</button>
          <button class="btn" type="button" onclick="window.print()">พิมพ์</button>
        </div>
      </div>

      <div class="report-print-header">
        <h2><?= h($viewTitle) ?></h2>
        <div class="meta">
          <div><strong>ช่วงข้อมูล:</strong> <?= h($rangeLabel) ?></div>
          <div><strong>วันที่พิมพ์:</strong> <?= h(date("d/m/Y H:i")) ?></div>
          <div><strong>ผู้พิมพ์:</strong> <?= h($admin_name ?? "Admin") ?></div>
          <div><strong>คำค้นหา:</strong> <?= h($q !== "" ? $q : "-") ?></div>
          <div><strong>โดม:</strong> <?= h($filters["dome"] ?? "all") ?></div>
          <div><strong>ร้าน:</strong> <?= h($filters["shop"] ?? "all") ?></div>
          <div><strong>หมวดหมู่:</strong> <?= h($filters["category"] ?? "all") ?></div>
          <div><strong>ประเภท:</strong> <?= h($filters["type"] ?? "all") ?></div>
          <div><strong>สถานะ:</strong> <?= h(($filters["status"] ?? "all") === "all" ? "ทั้งหมด" : statusTextTH($filters["status"])) ?></div>
          <?php if ($view === "by_group"): ?>
            <div><strong>จัดกลุ่มตาม:</strong> <?= h($groupBy) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <section class="card filters">
        <form method="get" id="filterForm">
          <div class="filters-grid">
            <div class="field">
              <label>มุมมองรายงาน</label>
              <select name="view" id="viewSelect" onchange="toggleReportOptions()">
                <option value="queue_detail" <?= $view === "queue_detail" ? "selected" : "" ?>>รายละเอียดออเดอร์</option>
                <option value="summary" <?= $view === "summary" ? "selected" : "" ?>>สรุปภาพรวม</option>
                <option value="by_shop" <?= $view === "by_shop" ? "selected" : "" ?>>วิเคราะห์รายร้าน</option>
                <option value="by_group" <?= $view === "by_group" ? "selected" : "" ?>>วิเคราะห์ตามโดม / หมวดหมู่ / ประเภท</option>
                <option value="service_performance" <?= $view === "service_performance" ? "selected" : "" ?>>ประสิทธิภาพการจัดการออเดอร์</option>
              </select>
            </div>

            <div class="field" id="groupByWrap">
              <label>จัดกลุ่มตาม</label>
              <select name="group_by">
                <option value="dome" <?= $groupBy === "dome" ? "selected" : "" ?>>โดม</option>
                <option value="category" <?= $groupBy === "category" ? "selected" : "" ?>>หมวดหมู่ร้าน</option>
                <option value="type" <?= $groupBy === "type" ? "selected" : "" ?>>ประเภทร้าน</option>
              </select>
            </div>

            <div class="field">
              <label>โดม</label>
              <select name="dome">
                <option value="all" <?= ($filters["dome"] ?? "all") === "all" ? "selected" : "" ?>>ทั้งหมด</option>
                <?php foreach($domesList as $d): ?>
                  <option value="<?= (int)$d["dome_id"] ?>" <?= ((string)($filters["dome"] ?? "") === (string)$d["dome_id"]) ? "selected" : "" ?>>
                    <?= h($d["dome_name"]) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label>หมวดหมู่ร้าน</label>
              <select name="category">
                <option value="all" <?= ($filters["category"] ?? "all") === "all" ? "selected" : "" ?>>ทั้งหมด</option>
                <?php foreach($categoriesList as $c): ?>
                  <option value="<?= (int)$c["category_id"] ?>" <?= ((string)($filters["category"] ?? "") === (string)$c["category_id"]) ? "selected" : "" ?>>
                    <?= h($c["category_name"]) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label>ประเภทร้าน</label>
              <select name="type">
                <option value="all" <?= ($filters["type"] ?? "all") === "all" ? "selected" : "" ?>>ทั้งหมด</option>
                <?php foreach($typesList as $t): ?>
                  <option value="<?= (int)$t["type_id"] ?>" <?= ((string)($filters["type"] ?? "") === (string)$t["type_id"]) ? "selected" : "" ?>>
                    <?= h($t["type_name"]) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label>ร้าน</label>
              <select name="shop">
                <option value="all" <?= ($filters["shop"] ?? "all") === "all" ? "selected" : "" ?>>ทั้งหมด</option>
                <?php foreach($shopsList as $s): ?>
                  <option value="<?= (int)$s["shop_id"] ?>" <?= ((string)($filters["shop"] ?? "") === (string)$s["shop_id"]) ? "selected" : "" ?>>
                    <?= h($s["name"]) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label>สถานะออเดอร์</label>
              <select name="status">
                <option value="all" <?= ($filters["status"] ?? "all") === "all" ? "selected" : "" ?>>ทั้งหมด</option>
                <option value="waiting" <?= ($filters["status"] ?? "") === "waiting" ? "selected" : "" ?>>รอคิว</option>
                <option value="calling" <?= ($filters["status"] ?? "") === "calling" ? "selected" : "" ?>>กำลังเรียก</option>
                <option value="served" <?= ($filters["status"] ?? "") === "served" ? "selected" : "" ?>>ให้บริการแล้ว</option>
                <option value="received" <?= ($filters["status"] ?? "") === "received" ? "selected" : "" ?>>รับออเดอร์แล้ว</option>
                <option value="cancel" <?= ($filters["status"] ?? "") === "cancel" ? "selected" : "" ?>>ยกเลิก</option>
              </select>
            </div>

            <div class="field">
              <label>วันที่เริ่ม</label>
              <input type="date" name="date_from" value="<?= h($filters["date_from"] ?? "") ?>">
            </div>

            <div class="field">
              <label>วันที่สิ้นสุด</label>
              <input type="date" name="date_to" value="<?= h($filters["date_to"] ?? "") ?>">
            </div>

            <div class="field full">
              <label>ค้นหา</label>
              <input
                type="text"
                name="q"
                value="<?= h($q) ?>"
                placeholder="ค้นหา order_id / เลขออเดอร์ / ชื่อลูกค้า / เบอร์โทร / รายละเอียดออเดอร์ / ชื่อร้าน"
              >
            </div>
          </div>

          <div class="filter-actions">
            <button class="btn primary" type="submit">ค้นหา / กรอง</button>
            <a class="btn" href="admin-reports.php">ล้างทั้งหมด</a>
            <a class="btn" href="admin-reports-export.php?<?= h($exportQuery) ?>">Export CSV</a>
            <button class="btn" type="button" onclick="window.print()">พิมพ์รายงาน</button>
          </div>
        </form>
      </section>

      <section class="card">
        <div class="stats-grid">
          <div class="stat-box">
            <div class="stat-label">ออเดอร์ทั้งหมด</div>
            <div class="stat-value"><?= (int)$total ?></div>
          </div>
          <div class="stat-box">
            <div class="stat-label">ออเดอร์รอดำเนินการ</div>
            <div class="stat-value"><?= (int)$waitingCnt ?></div>
          </div>
          <div class="stat-box">
            <div class="stat-label">ออเดอร์กำลังดำเนินการ</div>
            <div class="stat-value"><?= (int)$callingCnt ?></div>
          </div>
          <div class="stat-box">
            <div class="stat-label">ออเดอร์เสร็จสิ้น</div>
            <div class="stat-value"><?= (int)$receivedCnt ?></div>
          </div>
          <div class="stat-box">
            <div class="stat-label">ยกเลิก</div>
            <div class="stat-value"><?= (int)$cancelCnt ?></div>
          </div>
        </div>
      </section>

      <section class="card">
        <div class="table-meta">
          <div>
            <h2 class="section-title" style="margin-bottom:6px;"><?= h($viewTitle) ?></h2>
            <div class="muted">ช่วงข้อมูล: <?= h($rangeLabel) ?></div>
          </div>

          <div class="chip-wrap">
            <span class="chip">ออเดอร์ทั้งหมด <?= (int)$total ?> รายการ</span>
            <span class="chip">อัตราสำเร็จ <?= h($successRate) ?>%</span>
            <span class="chip">อัตรายกเลิก <?= h($cancelRate) ?>%</span>
          </div>
        </div>

        <?php if ($view === "queue_detail"): ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>order_id</th>
                  <th>โดม</th>
                  <th>ร้าน</th>
                  <th>หมวดหมู่/ประเภท</th>
                  <th>เลขออเดอร์</th>
                  <th>วันที่</th>
                  <th>ลูกค้า</th>
                  <th>เบอร์โทร</th>
                  <th>รายละเอียดออเดอร์</th>
                  <th>รับออเดอร์เมื่อ</th>
                  <th>เริ่มดำเนินการเมื่อ</th>
                  <th>เสร็จสิ้นเมื่อ</th>
                  <th>สถานะ</th>
                  <th>ระยะเวลา</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$queueDetails): ?>
                  <tr><td colspan="14" class="muted">ไม่พบข้อมูล</td></tr>
                <?php else: ?>
                  <?php foreach($queueDetails as $row): ?>
                    <tr>
                      <td><?= h($row["queue_id"] ?? "-") ?></td>
                      <td><?= h($row["dome_name"] ?? "-") ?></td>
                      <td><?= h($row["shop_name"] ?? "-") ?></td>
                      <td>
                        <?= h($row["category_name"] ?? "-") ?><br>
                        <span class="muted"><?= h($row["type_name"] ?? "-") ?></span>
                      </td>
                      <td><?= h($row["queue_no"] ?? "-") ?></td>
                      <td><?= h($row["queue_date"] ?? "-") ?></td>
                      <td><?= h($row["customer_name"] ?? "-") ?></td>
                      <td><?= h($row["customer_phone"] ?? "-") ?></td>
                      <td><?= h($row["customer_note"] ?? $row["note"] ?? "-") ?></td>
                      <td><?= h($row["created_at"] ?? "-") ?></td>
                      <td><?= h(($row["called_at"] ?? "") ?: "-") ?></td>
                      <td><?= h(($row["served_at"] ?? "") ?: "-") ?></td>
                      <td>
                        <span class="badge <?= h($row["status"] ?? "") ?>">
                          <?= h(statusTextTH($row["status"] ?? "")) ?>
                        </span>
                      </td>
                      <td><?= h($row["duration_text"] ?? "-") ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

        <?php elseif ($view === "summary"): ?>
          <div class="kpi-grid">
            <div class="kpi-card"><div class="label">ออเดอร์ทั้งหมด</div><div class="value"><?= (int)$total ?> รายการ</div></div>
            <div class="kpi-card"><div class="label">ออเดอร์รอคิว</div><div class="value"><?= (int)$waitingCnt ?> รายการ</div></div>
            <div class="kpi-card"><div class="label">ออเดอร์กำลังดำเนินการ</div><div class="value"><?= (int)$callingCnt ?> รายการ</div></div>
            <div class="kpi-card"><div class="label">ออเดอร์ให้บริการแล้ว</div><div class="value"><?= (int)$servedCnt ?> รายการ</div></div>
            <div class="kpi-card"><div class="label">ออเดอร์รับแล้ว</div><div class="value"><?= (int)$receivedCnt ?> รายการ</div></div>
            <div class="kpi-card"><div class="label">ออเดอร์ยกเลิก</div><div class="value"><?= (int)$cancelCnt ?> รายการ</div></div>
            <div class="kpi-card"><div class="label">ออเดอร์ค้าง</div><div class="value"><?= (int)$pendingCnt ?> รายการ</div></div>
            <div class="kpi-card"><div class="label">อัตราสำเร็จ</div><div class="value"><?= h($successRate) ?>%</div></div>
            <div class="kpi-card"><div class="label">อัตรายกเลิก</div><div class="value"><?= h($cancelRate) ?>%</div></div>
            <div class="kpi-card"><div class="label">เวลาเฉลี่ยจนเสร็จต่อออเดอร์</div><div class="value"><?= $avgMin === null ? "-" : h($avgMin) . " นาที/ออเดอร์" ?></div></div>
            <div class="kpi-card"><div class="label">ร้านที่มีออเดอร์สูงสุด</div><div class="value" style="font-size:18px;"><?= h($topShop["shop_name"] ?? $topShop["name"] ?? "-") ?></div></div>
            <div class="kpi-card"><div class="label">ออเดอร์เฉลี่ยต่อวัน</div><div class="value"><?= h($avgPerDay) ?> รายการ/วัน</div></div>
          </div>

          <div class="two-col">
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>วันที่</th>
                    <th>ออเดอร์ทั้งหมด</th>
                    <th>รอคิว</th>
                    <th>กำลังเรียก</th>
                    <th>ออเดอร์สำเร็จ</th>
                    <th>ยกเลิก</th>
                    <th>อัตราสำเร็จ (%)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if(!$byDay): ?>
                    <tr><td colspan="7" class="muted">ไม่พบข้อมูล</td></tr>
                  <?php else: ?>
                    <?php foreach($byDay as $row):
                      $dTotal = (int)$row["total_cnt"];
                      $dDone = (int)($row["received_cnt"] ?? 0);
                    ?>
                      <tr>
                        <td><?= h($row["day"]) ?></td>
                        <td><?= $dTotal ?></td>
                        <td><?= (int)$row["waiting_cnt"] ?></td>
                        <td><?= (int)$row["calling_cnt"] ?></td>
                        <td><?= $dDone ?></td>
                        <td><?= (int)$row["cancel_cnt"] ?></td>
                        <td><?= pct($dDone, $dTotal) ?>%</td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <div class="card" style="margin:0; padding:14px;">
              <h3 style="margin:0 0 10px; font-size:18px;">ข้อสังเกตจากข้อมูล</h3>
              <?php if(!$insights): ?>
                <div class="muted">ยังไม่มีข้อมูลเพียงพอ</div>
              <?php else: ?>
                <ul class="insight-list">
                  <?php foreach($insights as $item): ?>
                    <li><?= h($item) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <div style="margin-top:12px;" class="muted">
                วันที่ออเดอร์สูงสุด: <strong><?= h($peakDay["day"] ?? "-") ?></strong>
              </div>
            </div>
          </div>

        <?php elseif ($view === "by_shop"): ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>ร้าน</th>
                  <th>โดม</th>
                  <th>หมวดหมู่</th>
                  <th>ประเภท</th>
                  <th>จำนวนออเดอร์</th>
                  <th>รอ</th>
                  <th>กำลังเรียก</th>
                  <th>ออเดอร์สำเร็จ</th>
                  <th>ออเดอร์ยกเลิก</th>
                  <th>อัตราสำเร็จ</th>
                  <th>เวลาเฉลี่ยต่อออเดอร์</th>
                  <th>ออเดอร์เฉลี่ย/วัน</th>
                  <th>สัดส่วน (%)</th>
                </tr>
              </thead>
              <tbody>
                <?php if(!$byShop): ?>
                  <tr><td colspan="13" class="muted">ไม่พบข้อมูล</td></tr>
                <?php else: ?>
                  <?php
                  $dayCount = max(1, count($byDay));
                  foreach($byShop as $row):
                    $cnt = (int)$row["total_cnt"];
                    $done = (int)($row["received_cnt"] ?? 0);
                  ?>
                    <tr>
                      <td><?= h($row["shop_name"] ?? "-") ?></td>
                      <td><?= h($row["dome_name"] ?? "-") ?></td>
                      <td><?= h($row["category_name"] ?? "-") ?></td>
                      <td><?= h($row["type_name"] ?? "-") ?></td>
                      <td><?= $cnt ?> รายการ</td>
                      <td><?= (int)($row["waiting_cnt"] ?? 0) ?></td>
                      <td><?= (int)($row["calling_cnt"] ?? 0) ?></td>
                      <td><?= $done ?> รายการ</td>
                      <td><?= (int)($row["cancel_cnt"] ?? 0) ?> รายการ</td>
                      <td><?= pct($done, $cnt) ?>%</td>
                      <td><?= $row["avg_min"] === null ? "-" : (int)$row["avg_min"] . " นาที/ออเดอร์" ?></td>
                      <td><?= round($cnt / $dayCount, 1) ?> รายการ/วัน</td>
                      <td><?= pct($cnt, $total) ?>%</td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

        <?php elseif ($view === "by_group"): ?>
          <?php
            $groupRows = [];
            $groupLabel = "โดม";

            if ($groupBy === "category") {
              $groupRows = $byCategory ?? [];
              $groupLabel = "หมวดหมู่ร้าน";
            } elseif ($groupBy === "type") {
              $groupRows = $byType ?? [];
              $groupLabel = "ประเภทร้าน";
            } else {
              $groupRows = $byDome ?? [];
              $groupLabel = "โดม";
            }
          ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><?= h($groupLabel) ?></th>
                  <th>จำนวนออเดอร์</th>
                  <th>จำนวนร้าน</th>
                  <th>ออเดอร์เฉลี่ยต่อร้าน</th>
                  <th>ออเดอร์สำเร็จ</th>
                  <th>ออเดอร์ยกเลิก</th>
                  <th>อัตราสำเร็จ</th>
                  <th>เวลาเฉลี่ยต่อออเดอร์</th>
                </tr>
              </thead>
              <tbody>
                <?php if(!$groupRows): ?>
                  <tr><td colspan="8" class="muted">ไม่พบข้อมูล</td></tr>
                <?php else: ?>
                  <?php foreach($groupRows as $row):
                    $name = $groupBy === "category"
                      ? ($row["category_name"] ?? "-")
                      : ($groupBy === "type" ? ($row["type_name"] ?? "-") : ($row["dome_name"] ?? "-"));

                    $cnt = (int)($row["total_cnt"] ?? 0);
                    $shopCnt = (int)($row["shop_cnt"] ?? 0);
                    $done = (int)($row["received_cnt"] ?? 0);
                    $avgPerShop = $shopCnt > 0 ? round($cnt / $shopCnt, 1) : 0;
                  ?>
                    <tr>
                      <td><?= h($name) ?></td>
                      <td><?= $cnt ?> รายการ</td>
                      <td><?= $shopCnt ?></td>
                      <td><?= $avgPerShop ?> รายการ/ร้าน</td>
                      <td><?= $done ?> รายการ</td>
                      <td><?= (int)($row["cancel_cnt"] ?? 0) ?> รายการ</td>
                      <td><?= pct($done, $cnt) ?>%</td>
                      <td><?= isset($row["avg_min"]) && $row["avg_min"] !== null ? (int)$row["avg_min"] . " นาที/ออเดอร์" : "-" ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

        <?php elseif ($view === "service_performance"): ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>ร้าน</th>
                  <th>โดม</th>
                  <th>จำนวนออเดอร์</th>
                  <th>เวลาเฉลี่ยถึงเรียก</th>
                  <th>เวลาเฉลี่ยจนเสร็จ</th>
                  <th>เวลาสั้นสุด</th>
                  <th>เวลานานสุด</th>
                  <th>ออเดอร์สำเร็จ</th>
                  <th>ออเดอร์ยกเลิก</th>
                  <th>อัตราสำเร็จ</th>
                </tr>
              </thead>
              <tbody>
                <?php if(!$byShop): ?>
                  <tr><td colspan="10" class="muted">ไม่พบข้อมูล</td></tr>
                <?php else: ?>
                  <?php foreach($byShop as $row):
                    $cnt = (int)($row["total_cnt"] ?? 0);
                    $done = (int)($row["received_cnt"] ?? 0);

                    $avgDone = $row["avg_min"] ?? null;
                    $avgCall = isset($row["avg_call_min"]) && $row["avg_call_min"] !== null ? (float)$row["avg_call_min"] : null;
                    $minDone = isset($row["min_done_min"]) && $row["min_done_min"] !== null ? (int)$row["min_done_min"] : null;
                    $maxDone = isset($row["max_done_min"]) && $row["max_done_min"] !== null ? (int)$row["max_done_min"] : null;
                  ?>
                    <tr>
                      <td><?= h($row["shop_name"] ?? "-") ?></td>
                      <td><?= h($row["dome_name"] ?? "-") ?></td>
                      <td><?= $cnt ?> รายการ</td>
                      <td><?= $avgCall === null ? "-" : $avgCall . " นาที/ออเดอร์" ?></td>
                      <td><?= $avgDone === null ? "-" : $avgDone . " นาที/ออเดอร์" ?></td>
                      <td><?= $minDone === null ? "-" : $minDone . " นาที" ?></td>
                      <td><?= $maxDone === null ? "-" : $maxDone . " นาที" ?></td>
                      <td><?= $done ?> รายการ</td>
                      <td><?= (int)($row["cancel_cnt"] ?? 0) ?></td>
                      <td><?= pct($done, $cnt) ?>%</td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </main>
  </div>

  <div class="print-footer">
    ระบบคิวร้านค้า | <?= h($viewTitle) ?>
  </div>

  <script>
    function toggleReportOptions(){
      const view = document.getElementById("viewSelect").value;
      const groupByWrap = document.getElementById("groupByWrap");
      groupByWrap.style.display = (view === "by_group") ? "flex" : "none";
    }
    toggleReportOptions();
  </script>
</body>
</html>
