<?php
require_once __DIR__ . "/_auth.php";

function h($str){
  return htmlspecialchars((string)$str, ENT_QUOTES, "UTF-8");
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Dashboard</title>
  <style>
    :root{
      --bg:#f6f7fb;
      --card:#fff;
      --text:#111827;
      --muted:#6b7280;
      --primary:#2563eb;
      --border:#e5e7eb;
      --ok:#16a34a;
      --ok-soft:#f0fdf4;
      --ok-border:#bbf7d0;
      --warn:#f59e0b;
      --warn-soft:#fff7ed;
      --warn-border:#fed7aa;
      --bad:#dc2626;
      --bad-soft:#fef2f2;
      --bad-border:#fecaca;
      --purple:#7c3aed;
      --cyan:#0891b2;
    }

    *{ box-sizing:border-box; }

    body{
      margin:0;
      font-family:system-ui, -apple-system, "Segoe UI", sans-serif;
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
      max-width:260px;
      flex:0 0 260px;
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

    .brand small{
      color:rgba(255,255,255,.7);
    }

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

    .nav a:hover{
      background:rgba(255,255,255,.08);
    }

    .nav a.active{
      background:rgba(37,99,235,.25);
      border:1px solid rgba(37,99,235,.35);
    }

    .main{
      flex:1;
      min-width:0;
      padding:16px;
    }

    .topbar{
      display:flex;
      justify-content:space-between;
      align-items:flex-start;
      gap:12px;
      flex-wrap:wrap;
    }

    h1{
      margin:0;
      font-size:20px;
    }

    .muted{
      color:var(--muted);
      font-size:13px;
    }

    .live-row{
      margin-top:8px;
      color:var(--muted);
      font-size:13px;
      display:flex;
      align-items:center;
      gap:8px;
      flex-wrap:wrap;
    }

    .live-dot{
      display:inline-block;
      width:8px;
      height:8px;
      border-radius:999px;
      background:#16a34a;
      flex:0 0 auto;
    }

    .btn{
      padding:10px 12px;
      border-radius:12px;
      border:1px solid var(--border);
      background:#fff;
      cursor:pointer;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      color:var(--text);
      white-space:nowrap;
      font-size:14px;
    }

    .btn.primary{
      background:var(--primary);
      color:#fff;
      border-color:transparent;
    }

    .btn.refreshing{
      opacity:.8;
      pointer-events:none;
    }

    .top-actions{
      display:flex;
      gap:8px;
      align-items:center;
      flex-wrap:wrap;
    }

    .range-switch{
      display:flex;
      gap:6px;
      flex-wrap:wrap;
      align-items:center;
      margin-top:10px;
    }

    .range-btn{
      border:1px solid var(--border);
      background:#fff;
      color:var(--text);
      border-radius:999px;
      padding:8px 12px;
      font-size:13px;
      font-weight:700;
      cursor:pointer;
    }

    .range-btn.active{
      background:var(--primary);
      color:#fff;
      border-color:var(--primary);
    }

    .custom-range{
      display:none;
      align-items:flex-end;
      gap:10px;
      flex-wrap:wrap;
      margin-top:12px;
      padding:12px;
      border:1px solid var(--border);
      border-radius:16px;
      background:#fff;
    }

    .custom-range .field{
      display:flex;
      flex-direction:column;
      gap:6px;
      min-width:160px;
    }

    .custom-range label{
      font-size:12px;
      color:var(--muted);
      font-weight:800;
    }

    .custom-range input[type="date"]{
      border:1px solid var(--border);
      border-radius:12px;
      padding:10px 12px;
      font-size:14px;
      background:#fff;
      color:var(--text);
      outline:none;
    }

    .custom-range input[type="date"]:focus{
      border-color:rgba(37,99,235,.6);
      box-shadow:0 0 0 3px rgba(37,99,235,.12);
    }

    .custom-range .inline-actions{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      align-items:center;
    }

    .grid{
      display:grid;
      grid-template-columns:repeat(12, 1fr);
      gap:12px;
      margin-top:12px;
    }

    .card{
      grid-column:span 12;
      background:var(--card);
      border:1px solid var(--border);
      border-radius:16px;
      padding:14px;
      box-shadow:0 8px 20px rgba(0,0,0,.05);
    }

    .card.loading{
      opacity:.72;
    }

    .span-3{ grid-column:span 3; }
    .span-4{ grid-column:span 4; }
    .span-5{ grid-column:span 5; }
    .span-6{ grid-column:span 6; }
    .span-7{ grid-column:span 7; }
    .span-8{ grid-column:span 8; }
    .span-12{ grid-column:span 12; }

    .card-title{
      display:flex;
      justify-content:space-between;
      align-items:flex-start;
      gap:10px;
      flex-wrap:wrap;
      margin-bottom:10px;
    }

    .card-title h3{
      margin:0;
      font-size:16px;
      font-weight:800;
      line-height:1.3;
    }

    .section-label{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin:18px 0 10px;
      flex-wrap:wrap;
    }

    .section-label h2{
      margin:0;
      font-size:17px;
      font-weight:900;
      letter-spacing:-.01em;
    }

    .section-label .sub{
      color:var(--muted);
      font-size:13px;
    }

    .kpi{
      display:grid;
      grid-template-columns:repeat(6, minmax(0, 1fr));
      gap:12px;
    }

    .kpi .box{
      border:1px solid var(--border);
      border-radius:16px;
      padding:14px;
      background:#fff;
      min-height:110px;
      display:flex;
      flex-direction:column;
      justify-content:space-between;
    }

    .kpi-top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:10px;
    }

    .kpi-text{
      min-width:0;
    }

    .kpi .label{
      color:var(--muted);
      font-size:12px;
      line-height:1.4;
      font-weight:700;
    }

    .kpi .value{
      font-size:28px;
      font-weight:900;
      line-height:1;
      color:var(--text);
      margin-top:12px;
    }

    .kpi-icon{
      width:40px;
      height:40px;
      border-radius:12px;
      display:grid;
      place-items:center;
      font-size:18px;
      font-weight:900;
      flex:0 0 auto;
      background:#eff6ff;
      color:#2563eb;
      border:1px solid #dbeafe;
    }

    .kpi-icon.ok{
      background:#f0fdf4;
      color:#16a34a;
      border-color:#bbf7d0;
    }

    .kpi-icon.warn{
      background:#fff7ed;
      color:#d97706;
      border-color:#fed7aa;
    }

    .kpi-icon.bad{
      background:#fef2f2;
      color:#dc2626;
      border-color:#fecaca;
    }

    .kpi-note{
      margin-top:6px;
      font-size:12px;
      color:var(--muted);
      font-weight:600;
    }

    .loading-box{
      border:1px dashed var(--border);
      border-radius:14px;
      padding:16px;
      background:#fafcff;
      color:var(--muted);
      font-size:14px;
      text-align:center;
    }

    .notice-strip{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
      margin-bottom:14px;
      padding:14px 16px;
      border-radius:18px;
      border:1px solid #dbeafe;
      background:#eff6ff;
    }

    .notice-strip.success{
      border-color:var(--ok-border);
      background:var(--ok-soft);
    }

    .notice-strip.warning{
      border-color:var(--warn-border);
      background:var(--warn-soft);
    }

    .notice-strip.error{
      border-color:var(--bad-border);
      background:var(--bad-soft);
    }

    .notice-title{
      margin:0;
      font-size:15px;
      font-weight:900;
    }

    .notice-text{
      margin-top:4px;
      font-size:13px;
      color:var(--muted);
      line-height:1.5;
    }

    .overview-panels{
      display:grid;
      grid-template-columns:1.1fr 1.1fr .9fr;
      gap:12px;
      margin-top:14px;
    }

    .overview-panel{
      border:1px solid var(--border);
      border-radius:16px;
      padding:14px;
      background:#fff;
    }

    .overview-panel h4{
      margin:0 0 10px;
      font-size:14px;
      font-weight:800;
    }

    .donut-wrap{
      display:flex;
      align-items:center;
      gap:14px;
      flex-wrap:wrap;
    }

    .donut-box{
      position:relative;
      width:170px;
      height:170px;
      flex:0 0 auto;
    }

    .donut-box svg{
      width:170px;
      height:170px;
      display:block;
      transform:rotate(-90deg);
    }

    .donut-center{
      position:absolute;
      inset:0;
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      text-align:center;
      pointer-events:none;
    }

    .donut-center .big{
      font-size:24px;
      font-weight:900;
      line-height:1;
    }

    .donut-center .small{
      margin-top:4px;
      font-size:12px;
      color:var(--muted);
      font-weight:700;
    }

    .legend-list{
      display:flex;
      flex-direction:column;
      gap:8px;
      min-width:180px;
      flex:1;
    }

    .legend-item{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      font-size:13px;
    }

    .legend-left{
      display:flex;
      align-items:center;
      gap:8px;
      min-width:0;
    }

    .legend-dot{
      width:10px;
      height:10px;
      border-radius:999px;
      flex:0 0 auto;
    }

    .legend-name{
      color:var(--text);
      font-weight:700;
    }

    .legend-value{
      color:var(--muted);
      font-weight:800;
      white-space:nowrap;
    }

    .quick-stats{
      display:grid;
      gap:10px;
    }

    .quick-stat{
      border:1px solid var(--border);
      border-radius:14px;
      padding:12px;
      background:#fbfdff;
    }

    .quick-stat .s-label{
      font-size:12px;
      color:var(--muted);
      font-weight:700;
    }

    .quick-stat .s-value{
      margin-top:6px;
      font-size:22px;
      font-weight:900;
    }

    .quick-stat .s-sub{
      margin-top:4px;
      font-size:12px;
      color:var(--muted);
    }

    .svg-chart{
      width:100%;
      height:auto;
      display:block;
    }

    .progress-list{
      display:flex;
      flex-direction:column;
      gap:12px;
    }

    .progress-item{
      border:1px solid var(--border);
      border-radius:14px;
      padding:12px;
      background:#fff;
    }

    .progress-head{
      display:flex;
      justify-content:space-between;
      gap:10px;
      margin-bottom:8px;
      align-items:center;
    }

    .progress-title{
      font-size:13px;
      font-weight:800;
      line-height:1.4;
    }

    .progress-value{
      font-size:13px;
      color:var(--muted);
      font-weight:800;
      white-space:nowrap;
    }

    .progress-track{
      width:100%;
      height:10px;
      border-radius:999px;
      background:#e9eef6;
      overflow:hidden;
    }

    .progress-fill{
      height:100%;
      border-radius:999px;
      background:linear-gradient(90deg,#34d399,#2563eb);
    }

    .alert-grid{
      display:grid;
      grid-template-columns:repeat(3, minmax(0,1fr));
      gap:12px;
    }

    .alert-box{
      border:1px solid var(--border);
      background:#fbfdff;
      border-radius:16px;
      padding:14px;
    }

    .alert-box h4{
      margin:0 0 10px;
      font-size:15px;
      line-height:1.3;
      font-weight:900;
    }

    .alert-box ul{
      margin:0;
      padding-left:18px;
    }

    .alert-box li{
      margin:6px 0;
      color:var(--text);
      line-height:1.45;
    }

    .soft-note{
      margin-top:12px;
      color:var(--muted);
      font-size:12px;
      line-height:1.6;
    }

    .table-actions{
      display:flex;
      justify-content:flex-end;
      margin-top:12px;
    }

    .link-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      border:1px solid var(--border);
      background:#fff;
      color:var(--text);
      text-decoration:none;
      border-radius:12px;
      padding:9px 12px;
      font-size:13px;
      font-weight:800;
    }

    .link-btn:hover{
      background:#f8fafc;
    }

    .empty-state{
      border:1px dashed var(--border);
      border-radius:14px;
      padding:16px;
      color:var(--muted);
      text-align:center;
      font-size:13px;
      background:#fafcff;
    }

    .table-wrap{
      overflow:auto;
      width:100%;
      -webkit-overflow-scrolling:touch;
      border-radius:14px;
    }

    table{
      width:100%;
      border-collapse:separate;
      border-spacing:0;
      border:1px solid var(--border);
      border-radius:14px;
      overflow:hidden;
      background:#fff;
      min-width:680px;
    }

    th,td{
      padding:11px 12px;
      font-size:14px;
      border-bottom:1px solid var(--border);
      text-align:left;
      vertical-align:top;
    }

    th{
      background:#f8fafc;
      color:#374151;
      white-space:nowrap;
      font-weight:800;
      position:sticky;
      top:0;
      z-index:1;
    }

    tr:last-child td{
      border-bottom:0;
    }

    .badge{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:6px;
      padding:6px 12px;
      border-radius:999px;
      font-size:12px;
      border:1px solid var(--border);
      background:#fff;
      font-weight:800;
      min-width:72px;
    }

    .b-open{ border-color:rgba(22,163,74,.30); background:rgba(22,163,74,.08); color:var(--ok); }
    .b-closed{ border-color:rgba(220,38,38,.30); background:rgba(220,38,38,.08); color:var(--bad); }
    .b-break{ border-color:rgba(245,158,11,.30); background:rgba(245,158,11,.08); color:#b45309; }
    .b-full{ border-color:rgba(245,158,11,.30); background:rgba(245,158,11,.08); color:#b45309; }
    .b-waiting{ border-color:rgba(37,99,235,.30); background:rgba(37,99,235,.08); color:#1d4ed8; }
    .b-calling{ border-color:rgba(124,58,237,.30); background:rgba(124,58,237,.08); color:var(--purple); }
    .b-served{ border-color:rgba(22,163,74,.30); background:rgba(22,163,74,.08); color:var(--ok); }
    .b-received{ border-color:rgba(14,165,233,.30); background:rgba(14,165,233,.08); color:#0284c7; }
    .b-cancel{ border-color:rgba(220,38,38,.30); background:rgba(220,38,38,.08); color:var(--bad); }

    .content-link{
      color:#1d4ed8;
      font-weight:800;
      text-decoration:none;
    }

    .content-link:hover{
      text-decoration:underline;
    }

    .footer-note{
      margin-top:16px;
      color:var(--muted);
      font-size:12px;
      text-align:center;
    }

    .mobile-shortcuts{
      display:none;
      position:sticky;
      bottom:0;
      z-index:20;
      background:rgba(246,247,251,.96);
      backdrop-filter:blur(8px);
      border-top:1px solid var(--border);
      margin-top:18px;
      padding:10px 0 calc(10px + env(safe-area-inset-bottom));
    }

    .mobile-shortcuts .inner{
      display:grid;
      grid-template-columns:repeat(4,1fr);
      gap:8px;
    }

    .mobile-shortcuts a{
      text-decoration:none;
      background:#fff;
      border:1px solid var(--border);
      border-radius:14px;
      padding:10px 8px;
      text-align:center;
      font-size:12px;
      line-height:1.35;
      box-shadow:0 4px 14px rgba(15,23,42,.05);
      font-weight:700;
      color:var(--text);
    }

    @media (max-width:1400px){
      .kpi{ grid-template-columns:repeat(3, minmax(0, 1fr)); }
      .overview-panels{ grid-template-columns:1fr; }
    }

    @media (max-width:1200px){
      .span-8,.span-7,.span-6,.span-5,.span-4,.span-3{ grid-column:span 12; }
      .alert-grid{ grid-template-columns:1fr; }
    }

    @media (max-width:980px){
      .sidebar{ display:none; }
      .main{ padding:14px; }
      .kpi{ grid-template-columns:1fr; }
      .mobile-shortcuts{ display:block; }
    }

    @media (max-width:768px){
      .kpi{ grid-template-columns:repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width:640px){
      .top-actions{ width:100%; }
      .top-actions .btn{ flex:1 1 calc(50% - 8px); }
      .card{ padding:14px; }
      .card-title h3{ font-size:16px; }
      .notice-strip{ flex-direction:column; }
      .custom-range .field{ min-width:100%; }
      .custom-range .inline-actions{ width:100%; }
      .custom-range .inline-actions .btn{ flex:1 1 calc(50% - 8px); }
    }

    @media (max-width:520px){
      .kpi{ grid-template-columns:1fr; }
      .range-switch{ width:100%; }
      .range-btn{ flex:1 1 calc(50% - 6px); text-align:center; }
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
        <a class="<?= $current_page === 'admin-history.php' ? 'active' : '' ?>" href="admin-history.php">🧾 ประวัติออเดอร์</a>
        <a class="<?= $current_page === 'admin-reports.php' ? 'active' : '' ?>" href="admin-reports.php">📈 รายงาน</a>
        <a class="<?= $current_page === 'admin-shop-accounts.php' ? 'active' : '' ?>" href="admin-shop-accounts.php">🔐 บัญชีร้านค้า</a>
        <a href="../logout.php">🚪 ออกจากระบบ</a>
        <a href="../Frontend/index.php">↩ กลับหน้าเว็บ</a>
      </nav>

      <div style="margin-top:14px;">
        <div class="muted" style="color:rgba(255,255,255,.75);">
          * ดูภาพรวมร้านค้า ออเดอร์ และสถานะระบบจากหน้าเดียว
        </div>
      </div>
    </aside>

    <main class="main">
      <div class="topbar">
        <div>
          <h1>📊 แดชบอร์ดผู้ดูแลระบบ</h1>
          <div class="muted">ดูภาพรวมร้านค้า ออเดอร์ และสถานะระบบจากหน้าเดียว</div>

          <div class="live-row">
            <span class="live-dot"></span>
            <span>อัปเดตล่าสุด: <strong id="updatedAt">กำลังโหลด...</strong></span>
            <span>•</span>
            <span>สถานะ: <strong id="syncStatus">กำลังเชื่อมต่อ...</strong></span>
          </div>

          <div class="range-switch">
            <button type="button" class="range-btn active" data-period="today">วันนี้</button>
            <button type="button" class="range-btn" data-period="7d">7 วัน</button>
            <button type="button" class="range-btn" data-period="30d">30 วัน</button>
            <button type="button" class="range-btn" data-period="custom">กำหนดเอง</button>
          </div>

          <div class="custom-range" id="customRangeBox">
            <div class="field">
              <label for="startDate">จากวันที่</label>
              <input type="date" id="startDate">
            </div>
            <div class="field">
              <label for="endDate">ถึงวันที่</label>
              <input type="date" id="endDate">
            </div>
            <div class="inline-actions">
              <button type="button" class="btn primary" id="applyCustomRange">ใช้งานช่วงวันที่</button>
              <button type="button" class="btn" id="clearCustomRange">ล้างค่า</button>
            </div>
          </div>
        </div>

        <div class="top-actions">
          <button type="button" class="btn" id="refreshBtn">🔄 รีเฟรช</button>
          <a class="btn" href="admin-history.php">🧾 ประวัติออเดอร์</a>
          <a class="btn" href="admin-reports.php">📈 รายงาน</a>
          <a class="btn primary" href="admin-shops.php">🏪 จัดการร้าน</a>
        </div>
      </div>

      <div id="systemNotice" class="notice-strip">
        <div>
          <div class="notice-title">กำลังโหลดสถานะระบบ</div>
          <div class="notice-text">ระบบกำลังดึงข้อมูลล่าสุดจากฐานข้อมูล</div>
        </div>
      </div>

      <div class="section-label">
        <div>
          <h2>ตัวชี้วัดออเดอร์หลัก</h2>
          <div class="sub" id="periodLabel">ภาพรวมออเดอร์ที่สำคัญ</div>
        </div>
      </div>

      <div class="grid">
        <section class="card span-12 dash-card">
          <div class="card-title">
            <h3>ภาพรวมออเดอร์</h3>
            <div class="muted" id="periodTitle">สรุปสถานะและจำนวนออเดอร์</div>
          </div>
          <div id="kpiWrap" class="loading-box">กำลังโหลดข้อมูล...</div>
        </section>
      </div>

      <div class="section-label">
        <div>
          <h2>เฝ้าระวังและติดตาม</h2>
          <div class="sub">เฉพาะสิ่งที่ควรตรวจสอบในตอนนี้</div>
        </div>
      </div>

      <div class="grid">
        <section class="card span-7 dash-card">
          <div class="card-title">
            <h3>แจ้งเตือนออเดอร์</h3>
            <div class="muted">รายการที่ควรตรวจสอบทันที</div>
          </div>
          <div id="alertsWrap" class="loading-box">กำลังโหลดข้อมูล...</div>
        </section>

        <section class="card span-5 dash-card">
          <div class="card-title">
            <h3>Top 5 ร้าน (ออเดอร์สูงสุด)</h3>
            <div class="muted">ร้านที่มีออเดอร์มากที่สุด</div>
          </div>
          <div id="topWrap" class="loading-box">กำลังโหลดข้อมูล...</div>
        </section>
      </div>

      <div class="section-label">
        <div>
          <h2>แนวโน้มออเดอร์</h2>
          <div class="sub">วิเคราะห์พฤติกรรมการสั่งซื้อ</div>
        </div>
      </div>

      <div class="grid">
        <section class="card span-4 dash-card">
          <div class="card-title">
            <h3>ออเดอร์ตามช่วงเวลา</h3>
            <div class="muted">แนวโน้มตามวัน</div>
          </div>
          <div id="queue7Wrap" class="loading-box">กำลังโหลดข้อมูล...</div>
        </section>

        <section class="card span-4 dash-card">
          <div class="card-title">
            <h3>ออเดอร์ตามโดม</h3>
            <div class="muted">เปรียบเทียบออเดอร์แต่ละโดม</div>
          </div>
          <div id="domeWrap" class="loading-box">กำลังโหลดข้อมูล...</div>
        </section>

        <section class="card span-4 dash-card">
          <div class="card-title">
            <h3>ออเดอร์ตามประเภทร้าน</h3>
            <div class="muted">สัดส่วนออเดอร์แยกตามประเภท</div>
          </div>
          <div id="typeWrap" class="loading-box">กำลังโหลดข้อมูล...</div>
        </section>

        <section class="card span-6 dash-card">
          <div class="card-title">
            <h3>ออเดอร์ตามหมวดหมู่ร้าน</h3>
            <div class="muted">สัดส่วนออเดอร์แยกตามหมวดหมู่</div>
          </div>
          <div id="categoryWrap" class="loading-box">กำลังโหลดข้อมูล...</div>
        </section>

        <section class="card span-6 dash-card">
          <div class="card-title">
            <h3>ล็อกว่าง</h3>
            <div class="muted">พื้นที่ที่ยังไม่ผูกกับร้าน</div>
          </div>
          <div id="unassignedWrap" class="loading-box">กำลังโหลดข้อมูล...</div>
        </section>
      </div>

      <div class="section-label">
        <div>
          <h2>ข้อมูลออเดอร์</h2>
          <div class="sub">รายละเอียดสำหรับวิเคราะห์และจัดการ</div>
        </div>
      </div>

      <div class="grid">
        <section class="card span-6 dash-card">
          <div class="card-title">
            <h3>ออเดอร์ล่าสุด</h3>
            <div class="muted">10 รายการล่าสุด</div>
          </div>
          <div id="recentWrap" class="loading-box">กำลังโหลดข้อมูล...</div>
        </section>

        <section class="card span-6 dash-card">
          <div class="card-title">
            <h3>สถานะร้านทั้งหมด</h3>
            <div class="muted">แสดง 10 ร้านแรก</div>
          </div>
          <div id="shopsWrap" class="loading-box">กำลังโหลดข้อมูล...</div>
        </section>
      </div>

      <div class="footer-note">
        แดชบอร์ดนี้เน้นการติดตามออเดอร์ การเฝ้าระวัง และการวิเคราะห์แบบเรียลไทม์
      </div>

      <div class="mobile-shortcuts">
        <div class="inner">
          <a href="admin-dashboard.php">📊<br>แดชบอร์ด</a>
          <a href="admin-shops.php">🏪<br>ร้านค้า</a>
          <a href="admin-history.php">🧾<br>ออเดอร์</a>
          <a href="admin-reports.php">📈<br>รายงาน</a>
        </div>
      </div>
    </main>
  </div>

  <script>
    let isUpdating = false;
    let currentPeriod = 'today';
    let customStartDate = '';
    let customEndDate = '';

    function setCardsLoading(isLoading) {
      document.querySelectorAll('.dash-card').forEach(card => {
        card.classList.toggle('loading', isLoading);
      });

      const refreshBtn = document.getElementById('refreshBtn');
      if (refreshBtn) {
        refreshBtn.classList.toggle('refreshing', isLoading);
        refreshBtn.textContent = isLoading ? '⏳ กำลังรีเฟรช...' : '🔄 รีเฟรช';
      }

      const syncStatus = document.getElementById('syncStatus');
      if (syncStatus && isLoading) {
        syncStatus.textContent = 'กำลังอัปเดตข้อมูล';
      }
    }

    function wrapTables(containerId) {
      const el = document.getElementById(containerId);
      if (!el) return;

      const directTable = el.querySelector(':scope > table');
      if (directTable && !directTable.parentElement.classList.contains('table-wrap')) {
        const wrapper = document.createElement('div');
        wrapper.className = 'table-wrap';
        directTable.parentNode.insertBefore(wrapper, directTable);
        wrapper.appendChild(directTable);
      }
    }

    function applyPostRenderUI() {
      ['unassignedWrap', 'recentWrap', 'shopsWrap'].forEach(wrapTables);
    }

    function updateSystemNotice(mode, title, text) {
      const box = document.getElementById('systemNotice');
      if (!box) return;

      box.className = 'notice-strip ' + mode;
      box.innerHTML = `
        <div>
          <div class="notice-title">${title}</div>
          <div class="notice-text">${text}</div>
        </div>
      `;
    }

    function updateRangeButtons() {
      document.querySelectorAll('.range-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.period === currentPeriod);
      });

      const customBox = document.getElementById('customRangeBox');
      if (customBox) {
        customBox.style.display = currentPeriod === 'custom' ? 'flex' : 'none';
      }
    }

    function updatePeriodTexts() {
      const map = {
        today: 'ช่วงเวลาปัจจุบัน: วันนี้',
        '7d': 'ช่วงเวลาปัจจุบัน: 7 วันล่าสุด',
        '30d': 'ช่วงเวลาปัจจุบัน: 30 วันล่าสุด',
        custom: (customStartDate && customEndDate)
          ? `ช่วงเวลาปัจจุบัน: ${customStartDate} ถึง ${customEndDate}`
          : 'ช่วงเวลาปัจจุบัน: กำหนดเอง'
      };

      const label = document.getElementById('periodLabel');
      const title = document.getElementById('periodTitle');

      if (label) label.textContent = map[currentPeriod] || 'ภาพรวมออเดอร์ที่สำคัญ';
      if (title) title.textContent = map[currentPeriod] || 'สรุปสถานะและจำนวนออเดอร์';
    }

    function buildQueryString() {
      const params = new URLSearchParams();
      params.set('period', currentPeriod);

      if (currentPeriod === 'custom') {
        params.set('start_date', customStartDate || '');
        params.set('end_date', customEndDate || '');
      }

      return params.toString();
    }

    async function refreshDashboardLive(showLoading = true) {
      if (isUpdating) return;

      if (currentPeriod === 'custom' && (!customStartDate || !customEndDate)) {
        updateSystemNotice(
          'warning',
          'กรุณาเลือกช่วงวันที่',
          'กรุณาระบุวันที่เริ่มต้นและวันที่สิ้นสุดก่อนใช้งานแบบกำหนดเอง'
        );
        return;
      }

      isUpdating = true;
      if (showLoading) setCardsLoading(true);

      try {
        const res = await fetch('admin-dashboard-data.php?' + buildQueryString(), {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        if (!res.ok) throw new Error('โหลดข้อมูลไม่สำเร็จ');

        const data = await res.json();
        if (!data.ok) throw new Error(data.message || 'ข้อมูลไม่ถูกต้อง');

        const setHTML = (id, html) => {
          const el = document.getElementById(id);
          if (el) el.innerHTML = html || '<div class="loading-box">ไม่มีข้อมูล</div>';
        };

        setHTML('kpiWrap', data.kpi_html);
        setHTML('queue7Wrap', data.queue7_html);
        setHTML('topWrap', data.top_html);
        setHTML('categoryWrap', data.category_html);
        setHTML('typeWrap', data.type_html);
        setHTML('domeWrap', data.dome_html);
        setHTML('unassignedWrap', data.unassigned_html);
        setHTML('alertsWrap', data.alerts_html);
        setHTML('recentWrap', data.recent_html);
        setHTML('shopsWrap', data.shops_html);

        applyPostRenderUI();

        const updatedAt = document.getElementById('updatedAt');
        if (updatedAt) updatedAt.textContent = data.updated_at || '-';

        const syncStatus = document.getElementById('syncStatus');
        if (syncStatus) syncStatus.textContent = 'เชื่อมต่อปกติ';

        updatePeriodTexts();

        updateSystemNotice(
          'success',
          'ระบบพร้อมใช้งาน',
          'โหลดข้อมูลสำเร็จแล้ว สามารถติดตามภาพรวมออเดอร์และตรวจสอบจุดที่ต้องดูแลได้ทันที'
        );
      } catch (err) {
        console.error('Realtime refresh error:', err);

        const syncStatus = document.getElementById('syncStatus');
        if (syncStatus) syncStatus.textContent = 'โหลดข้อมูลไม่สำเร็จ';

        updateSystemNotice(
          'error',
          'เกิดปัญหาในการดึงข้อมูล',
          'ไม่สามารถโหลดข้อมูลล่าสุดได้ในขณะนี้ กรุณากดรีเฟรชอีกครั้ง'
        );
      } finally {
        isUpdating = false;
        setCardsLoading(false);
      }
    }

    document.getElementById('refreshBtn')?.addEventListener('click', () => {
      refreshDashboardLive(true);
    });

    document.querySelectorAll('.range-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        currentPeriod = btn.dataset.period || 'today';
        updateRangeButtons();

        if (currentPeriod !== 'custom') {
          refreshDashboardLive(true);
        } else {
          updateSystemNotice(
            'warning',
            'เลือกช่วงวันที่เอง',
            'กรุณาระบุจากวันที่และถึงวันที่ แล้วกดใช้งานช่วงวันที่'
          );
        }
      });
    });

    document.getElementById('applyCustomRange')?.addEventListener('click', () => {
      const start = document.getElementById('startDate')?.value || '';
      const end = document.getElementById('endDate')?.value || '';

      if (!start || !end) {
        updateSystemNotice('warning', 'กรอกวันที่ไม่ครบ', 'กรุณาเลือกทั้งจากวันที่และถึงวันที่');
        return;
      }

      if (start > end) {
        updateSystemNotice('warning', 'ช่วงวันที่ไม่ถูกต้อง', 'จากวันที่ต้องไม่มากกว่าถึงวันที่');
        return;
      }

      customStartDate = start;
      customEndDate = end;
      currentPeriod = 'custom';
      updateRangeButtons();
      refreshDashboardLive(true);
    });

    document.getElementById('clearCustomRange')?.addEventListener('click', () => {
      document.getElementById('startDate').value = '';
      document.getElementById('endDate').value = '';
      customStartDate = '';
      customEndDate = '';
      currentPeriod = 'today';
      updateRangeButtons();
      refreshDashboardLive(true);
    });

    updateRangeButtons();
    updatePeriodTexts();
    refreshDashboardLive(true);
    setInterval(() => refreshDashboardLive(false), 10000);

    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) {
        refreshDashboardLive(false);
      }
    });
  </script>
</body>
</html>