<?php
require_once __DIR__ . "/../config.php";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

date_default_timezone_set('Asia/Bangkok');
$today = date('Y-m-d');

$q = trim($_GET['q'] ?? '');
$categoryId = (int)($_GET['category_id'] ?? 0);
$typeId = (int)($_GET['type_id'] ?? 0);
$hasSearch = ($q !== '' || $categoryId > 0 || $typeId > 0);

// -------------------------
// โหลดหมวดหมู่ / ประเภท
// -------------------------
$categories = $pdo->query("
    SELECT category_id, category_name
    FROM shop_categories
    ORDER BY category_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$types = $pdo->query("
    SELECT t.type_id, t.type_name, t.category_id, c.category_name
    FROM shop_types t
    LEFT JOIN shop_categories c ON c.category_id = t.category_id
    ORDER BY c.category_name ASC, t.type_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$filteredTypes = $types;
if ($categoryId > 0) {
    $filteredTypes = array_values(array_filter($types, function($t) use ($categoryId){
        return (int)($t['category_id'] ?? 0) === $categoryId;
    }));
}

// ถ้า category เปลี่ยนแล้ว type ไม่อยู่ในหมวดนั้น ให้ reset
if ($typeId > 0 && $categoryId > 0) {
    $typeStillValid = false;
    foreach ($filteredTypes as $t) {
        if ((int)$t['type_id'] === $typeId) {
            $typeStillValid = true;
            break;
        }
    }
    if (!$typeStillValid) {
        $typeId = 0;
    }
}

// -------------------------
// ข้อมูลโดม
// -------------------------
$stmt = $pdo->prepare("
    SELECT 
        d.dome_id,
        d.dome_name,
        d.total_locks,
        COUNT(DISTINCT s.shop_id) AS total_shops,
        SUM(CASE WHEN s.shop_id IS NOT NULL AND s.status = 'open' THEN 1 ELSE 0 END) AS open_shops,
        SUM(CASE WHEN s.shop_id IS NOT NULL AND s.status = 'closed' THEN 1 ELSE 0 END) AS closed_shops,
        SUM(CASE WHEN s.shop_id IS NOT NULL AND s.status = 'break' THEN 1 ELSE 0 END) AS break_shops,
        SUM(CASE WHEN s.shop_id IS NOT NULL AND s.status = 'full' THEN 1 ELSE 0 END) AS full_shops
    FROM domes d
    LEFT JOIN locks l ON l.dome_id = d.dome_id
    LEFT JOIN shops s ON s.lock_id = l.lock_id
    GROUP BY d.dome_id, d.dome_name, d.total_locks
    ORDER BY d.dome_id ASC
");
$stmt->execute();
$domes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -------------------------
// ร้านเปิดอยู่ตอนนี้
// -------------------------
$stmt = $pdo->prepare("
    SELECT
        s.shop_id,
        s.name AS shop_name,
        s.status,
        s.open_time,
        s.close_time,
        l.lock_no,
        d.dome_id,
        d.dome_name,
        c.category_name,
        t.type_name,
        COALESCE(qs.active_queue_count, 0) AS active_queue_count,
        qs.current_call_no
    FROM shops s
    INNER JOIN locks l ON l.lock_id = s.lock_id
    INNER JOIN domes d ON d.dome_id = l.dome_id
    LEFT JOIN shop_types t ON t.type_id = s.type_id
    LEFT JOIN shop_categories c ON c.category_id = t.category_id
    LEFT JOIN (
        SELECT 
            q.shop_id,
            SUM(CASE WHEN q.status IN ('waiting','calling') THEN 1 ELSE 0 END) AS active_queue_count,
            MAX(CASE WHEN q.status = 'calling' THEN q.queue_no ELSE NULL END) AS current_call_no
        FROM queues q
        WHERE q.queue_date = :today_featured
        GROUP BY q.shop_id
    ) qs ON qs.shop_id = s.shop_id
    WHERE s.status = 'open'
    ORDER BY 
        COALESCE(qs.active_queue_count, 0) ASC,
        d.dome_id ASC,
        l.lock_no ASC,
        s.name ASC
    LIMIT 6
");
$stmt->execute([':today_featured' => $today]);
$featuredShops = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -------------------------
// ค้นหาร้าน
// -------------------------
$searchResults = [];
$params = [':today_search' => $today];
$where = [];

if ($q !== '') {
    $where[] = "(
        s.name LIKE :kw1
        OR c.category_name LIKE :kw2
        OR t.type_name LIKE :kw3
        OR EXISTS (
            SELECT 1
            FROM menu_items mi2
            WHERE mi2.shop_id = s.shop_id
              AND mi2.is_available = 1
              AND mi2.item_name LIKE :kw4
        )
    )";
    $params[':kw1'] = "%{$q}%";
    $params[':kw2'] = "%{$q}%";
    $params[':kw3'] = "%{$q}%";
    $params[':kw4'] = "%{$q}%";
}

if ($categoryId > 0) {
    $where[] = "c.category_id = :category_id";
    $params[':category_id'] = $categoryId;
}

if ($typeId > 0) {
    $where[] = "t.type_id = :type_id";
    $params[':type_id'] = $typeId;
}

$sqlSearch = "
    SELECT DISTINCT
        s.shop_id,
        s.name AS shop_name,
        s.status,
        s.open_time,
        s.close_time,
        l.lock_no,
        d.dome_id,
        d.dome_name,
        c.category_name,
        t.type_name,
        COALESCE(qs.active_queue_count, 0) AS active_queue_count,
        qs.current_call_no
    FROM shops s
    INNER JOIN locks l ON l.lock_id = s.lock_id
    INNER JOIN domes d ON d.dome_id = l.dome_id
    LEFT JOIN shop_types t ON t.type_id = s.type_id
    LEFT JOIN shop_categories c ON c.category_id = t.category_id
    LEFT JOIN (
        SELECT 
            q.shop_id,
            SUM(CASE WHEN q.status IN ('waiting','calling') THEN 1 ELSE 0 END) AS active_queue_count,
            MAX(CASE WHEN q.status = 'calling' THEN q.queue_no ELSE NULL END) AS current_call_no
        FROM queues q
        WHERE q.queue_date = :today_search
        GROUP BY q.shop_id
    ) qs ON qs.shop_id = s.shop_id
";

if ($where) {
    $sqlSearch .= " WHERE " . implode(" AND ", $where);
}

$sqlSearch .= "
    ORDER BY
        CASE s.status
            WHEN 'open' THEN 1
            WHEN 'break' THEN 2
            WHEN 'full' THEN 3
            WHEN 'closed' THEN 4
            ELSE 5
        END ASC,
        COALESCE(qs.active_queue_count, 0) ASC,
        d.dome_id ASC,
        l.lock_no ASC,
        s.name ASC
    LIMIT 30
";

if ($hasSearch) {
    $stmt = $pdo->prepare($sqlSearch);
    $stmt->execute($params);
    $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function statusMeta(string $status): array {
    $status = trim($status);
    if ($status === 'open') return ['open', 'เปิดรับคิว'];
    if ($status === 'break') return ['break', 'พัก'];
    if ($status === 'full') return ['full', 'คิวเต็ม'];
    return ['closed', 'ปิด'];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>หน้าแรก | ระบบจัดการคิว</title>
  <link rel="stylesheet" href="./style.css?v=8">
  <style>
    :root{
      --yellow:#FFCD22;
      --yellow-soft:#fff8db;
      --line:#e7e7e7;
      --card:#ffffff;
      --text:#232323;
      --muted:#666;
      --shadow:0 8px 24px rgba(0,0,0,.06);
      --radius:18px;
      --success-bg:#eaf8ee;
      --success-text:#1f7a35;
      --danger-bg:#fdecec;
      --danger-text:#b42318;
      --warn-bg:#fff7e6;
      --warn-text:#ad6800;
      --full-bg:#fff1f0;
      --full-text:#cf1322;
    }

    *{ box-sizing:border-box; }
    body{
      margin:0;
      font-family:'Prompt',system-ui,sans-serif;
      background:linear-gradient(180deg,#fffdf7 0%, #f5f5f5 240px);
      color:var(--text);
    }
    a{ text-decoration:none; color:inherit; }

    .topbar{
      background:rgba(255,255,255,.95);
      backdrop-filter:blur(8px);
      border-bottom:1px solid var(--line);
      position:sticky;
      top:0;
      z-index:50;
    }
    .topbar-inner{
      max-width:1100px;
      margin:0 auto;
      padding:12px 18px;
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:12px;
    }
    .topbar-title{
      display:flex;
      align-items:center;
      gap:10px;
      min-width:0;
    }
    .topbar-dot{
      width:18px;
      height:18px;
      border-radius:50%;
      background:var(--yellow);
      box-shadow:0 0 0 4px rgba(255,205,34,.24);
      flex-shrink:0;
    }
    .topbar-title h1{
      margin:0;
      font-size:21px;
      line-height:1.2;
    }
    .topbar-title p{
      margin:2px 0 0;
      font-size:13px;
      color:#777;
    }

    .topbar-right{
      display:flex;
      align-items:center;
      gap:10px;
      flex-shrink:0;
    }
    .notify-shortcut{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:10px 14px;
      border-radius:999px;
      background:#fff8df;
      border:1px solid #f1de96;
      color:#5a4a00;
      font-size:14px;
      font-weight:700;
      white-space:nowrap;
    }
    .bell-link{
      position:relative;
      width:46px;
      height:46px;
      border-radius:14px;
      border:1px solid #ececec;
      background:#fff;
      display:flex;
      align-items:center;
      justify-content:center;
      box-shadow:0 4px 12px rgba(0,0,0,.06);
      font-size:22px;
      flex-shrink:0;
    }
    .bell-count{
      position:absolute;
      top:-6px;
      right:-6px;
      min-width:22px;
      height:22px;
      padding:0 6px;
      border-radius:999px;
      background:#e53935;
      color:#fff;
      font-size:11px;
      font-weight:800;
      display:none;
      align-items:center;
      justify-content:center;
      border:2px solid #fff;
    }

    .page{
      max-width:1100px;
      margin:18px auto 34px;
      padding:0 18px;
    }

    .search-card,
    .result-card,
    .featured-wrap,
    .zone-card{
      background:var(--card);
      border:1px solid var(--line);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
    }

    .search-card,
    .result-card,
    .featured-wrap{
      padding:18px;
      margin-bottom:16px;
    }

    .section-title{
      margin:0 0 8px;
      font-size:20px;
      line-height:1.3;
    }
    .section-desc{
      margin:0 0 14px;
      color:#555;
      font-size:14px;
      line-height:1.6;
    }

    .search-form{
      display:grid;
      grid-template-columns:minmax(0,1.6fr) minmax(0,1fr) minmax(0,1fr) auto;
      gap:10px;
      align-items:center;
    }
    .input,.select{
      width:100%;
      min-height:46px;
      padding:11px 12px;
      border-radius:12px;
      border:1px solid #ddd;
      background:#fff;
      font:inherit;
    }
    .input:focus,.select:focus{
      outline:none;
      border-color:#e2b400;
      box-shadow:0 0 0 4px rgba(255,205,34,.18);
    }

    .btn-main,.btn-light{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:44px;
      padding:10px 16px;
      border-radius:999px;
      font-weight:700;
      border:1px solid rgba(0,0,0,.05);
      cursor:pointer;
      white-space:nowrap;
      font:inherit;
      transition:.18s ease;
    }
    .btn-main{
      background:linear-gradient(135deg,var(--yellow),#ffd84d);
      color:#333;
      box-shadow:0 5px 12px rgba(255,205,34,.24);
    }
    .btn-light{
      background:#f3f3f3;
      color:#333;
      border:1px solid #ddd;
    }
    .btn-main:hover,.btn-light:hover{ transform:translateY(-1px); }

    .filter-actions{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
    }

    .quick-links{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      margin-top:12px;
    }
    .quick-chip{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:38px;
      padding:8px 12px;
      border-radius:999px;
      background:#faf7ea;
      border:1px solid #eee0ab;
      color:#5b4b00;
      font-size:13px;
      font-weight:700;
    }

    .result-header{
      display:flex;
      justify-content:space-between;
      align-items:flex-end;
      gap:10px;
      flex-wrap:wrap;
      margin-bottom:14px;
    }
    .result-meta{
      color:#666;
      font-size:14px;
    }

    .search-results{
      display:grid;
      gap:12px;
    }
    .shop-card{
      background:#fbfbfb;
      border:1px solid #e8e8e8;
      border-radius:16px;
      padding:14px;
      cursor:pointer;
      transition:.18s ease;
    }
    .shop-card:hover{
      transform:translateY(-1px);
      background:#fff;
    }

    .shop-top{
      display:flex;
      justify-content:space-between;
      align-items:flex-start;
      gap:10px;
      flex-wrap:wrap;
    }
    .shop-top h4{
      margin:0;
      font-size:17px;
      line-height:1.45;
    }

    .chips{
      display:flex;
      flex-wrap:wrap;
      gap:6px;
      margin-top:10px;
    }
    .chip{
      display:inline-flex;
      align-items:center;
      min-height:28px;
      padding:5px 10px;
      border-radius:999px;
      background:#f2f2f2;
      font-size:12px;
      font-weight:700;
      color:#555;
    }
    .chip.open{ background:var(--success-bg); color:var(--success-text); }
    .chip.closed{ background:var(--danger-bg); color:var(--danger-text); }
    .chip.break{ background:var(--warn-bg); color:var(--warn-text); }
    .chip.full{ background:var(--full-bg); color:var(--full-text); }

    .shop-grid{
      display:grid;
      grid-template-columns:repeat(2,1fr);
      gap:10px;
      margin-top:12px;
    }
    .info-box{
      background:#fff;
      border:1px solid #ececec;
      border-radius:12px;
      padding:10px;
    }
    .info-box span{
      display:block;
      font-size:12px;
      color:#777;
      margin-bottom:3px;
    }
    .info-box strong{
      display:block;
      font-size:16px;
      line-height:1.3;
    }

    .empty{
      padding:16px;
      border:1px dashed #d6d6d6;
      border-radius:14px;
      color:#666;
      background:#fcfcfc;
      font-size:14px;
      line-height:1.7;
    }

    .featured-grid{
      display:grid;
      grid-template-columns:repeat(3,1fr);
      gap:12px;
    }
    .featured-card{
      background:#fff;
      border:1px solid #e8e8e8;
      border-radius:16px;
      padding:14px;
    }
    .featured-card .top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:10px;
      margin-bottom:10px;
    }
    .featured-card h4{
      margin:0;
      font-size:16px;
      line-height:1.45;
    }
    .meta-line{
      color:#666;
      font-size:13px;
      margin-top:6px;
      line-height:1.6;
    }

    .zone-grid{
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(290px,1fr));
      gap:16px;
      margin-top:8px;
    }
    .zone-card{
      display:flex;
      overflow:hidden;
      transition:.18s ease;
      cursor:pointer;
    }
    .zone-card:hover{ transform:translateY(-2px); }
    .zone-strip{
      width:8px;
      background:linear-gradient(180deg,var(--yellow),#f2b700);
      flex-shrink:0;
    }
    .zone-body{
      flex:1;
      padding:16px;
    }
    .zone-label{
      display:inline-block;
      padding:4px 10px;
      border-radius:999px;
      background:rgba(127,127,127,.08);
      color:#555;
      font-size:12px;
      font-weight:700;
      margin-bottom:6px;
    }
    .zone-body h3{
      margin:0 0 6px;
      font-size:18px;
      line-height:1.4;
    }
    .zone-body p{
      margin:0 0 12px;
      color:#555;
      line-height:1.6;
      font-size:14px;
    }
    .meta-grid{
      display:grid;
      grid-template-columns:repeat(2,1fr);
      gap:10px;
      margin-bottom:12px;
    }
    .meta-box{
      background:#fafafa;
      border:1px solid #e8e8e8;
      border-radius:12px;
      padding:10px;
      text-align:center;
    }
    .meta-box span{
      display:block;
      font-size:11px;
      color:#777;
    }
    .meta-box strong{
      display:block;
      margin-top:3px;
      font-size:17px;
    }
    .meta-highlight{
      background:#fff9e8;
      border-color:#f0dea0;
    }

    @media (max-width: 980px){
      .featured-grid{
        grid-template-columns:repeat(2,1fr);
      }
    }

    @media (max-width: 860px){
      .topbar-inner{
        flex-wrap:wrap;
        align-items:flex-start;
      }
      .search-form{
        grid-template-columns:1fr;
      }
      .filter-actions{
        width:100%;
      }
      .filter-actions .btn-main,
      .filter-actions .btn-light{
        flex:1;
      }
    }

    @media (max-width: 700px){
      .featured-grid{
        grid-template-columns:1fr;
      }
      .shop-grid{
        grid-template-columns:1fr;
      }
    }

    @media (max-width: 600px){
      .page{ padding:0 12px; }
      .topbar-inner{ padding:12px; }
      .topbar-title h1{ font-size:18px; }
      .notify-shortcut{ width:100%; }
      .meta-grid{ grid-template-columns:1fr 1fr; }
    }
  </style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="topbar-title">
      <span class="topbar-dot"></span>
      <div>
        <h1>โรงอาหารกลาง (ตลาดน้อย)</h1>
        <p>มหาวิทยาลัยมหาสารคาม</p>
      </div>
    </div>

    <div class="topbar-right">
      <a href="notifications.php" class="notify-shortcut">ดูคิวที่ติดตาม</a>

      <a href="notifications.php" class="bell-link" aria-label="หน้าแจ้งเตือน" title="แจ้งเตือนคิวของฉัน">
        🔔
        <span class="bell-count" id="bellCount">0</span>
      </a>
    </div>
  </div>
</header>

<main class="page">

  <section class="search-card">
    <h3 class="section-title">ค้นหาร้านจากชื่อร้าน / หมวดหมู่ / ประเภท / เมนู</h3>
    <p class="section-desc">พิมพ์คำที่ต้องการ เช่น ชานม, กาแฟ, ข้าวมันไก่, ของหวาน หรือเลือกหมวดหมู่เพื่อค้นหาให้ตรงขึ้น</p>

    <form method="get" class="search-form">
      <input
        type="text"
        name="q"
        class="input"
        placeholder="พิมพ์ชื่อร้าน หรือชื่อเมนู เช่น ชานม"
        value="<?= h($q) ?>"
      >

      <select name="category_id" class="select" onchange="this.form.submit()">
        <option value="0">-- ทุกหมวดหมู่ --</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['category_id'] ?>" <?= $categoryId === (int)$c['category_id'] ? 'selected' : '' ?>>
            <?= h($c['category_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="type_id" class="select" <?= ($categoryId > 0 && empty($filteredTypes)) ? 'disabled' : '' ?>>
        <option value="0">-- ทุกประเภทร้าน --</option>
        <?php foreach ($filteredTypes as $t): ?>
          <option value="<?= (int)$t['type_id'] ?>" <?= $typeId === (int)$t['type_id'] ? 'selected' : '' ?>>
            <?= h($t['type_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div class="filter-actions">
        <button type="submit" class="btn-main">ค้นหา</button>
        <a href="index.php" class="btn-light">ล้างค่า</a>
      </div>
    </form>

    <div class="quick-links">
      <a class="quick-chip" href="index.php?q=ชานม">ชานม</a>
      <a class="quick-chip" href="index.php?q=กาแฟ">กาแฟ</a>
      <a class="quick-chip" href="index.php?q=ข้าวมันไก่">ข้าวมันไก่</a>
      <a class="quick-chip" href="index.php?q=อาหารตามสั่ง">อาหารตามสั่ง</a>
      <a class="quick-chip" href="index.php?q=ของหวาน">ของหวาน</a>
    </div>
  </section>

  <?php if ($hasSearch): ?>
    <section class="result-card">
      <div class="result-header">
        <div>
          <h3 class="section-title" style="margin-bottom:4px;">ผลการค้นหา</h3>
          <div class="result-meta">
            <?php if ($q !== ''): ?>
              คำค้นหา: “<?= h($q) ?>”
            <?php else: ?>
              แสดงผลตามหมวดหมู่หรือประเภทร้านที่เลือก
            <?php endif; ?>
          </div>
        </div>

        <div class="result-meta">พบ <?= count($searchResults) ?> ร้าน</div>
      </div>

      <?php if (!empty($searchResults)): ?>
        <div class="search-results">
          <?php foreach ($searchResults as $shop): ?>
            <?php
              [$statusClass, $statusText] = statusMeta((string)$shop['status']);
              $queueCount = (int)($shop['active_queue_count'] ?? 0);
              $currentCall = $shop['current_call_no'] !== null ? '#' . (int)$shop['current_call_no'] : '-';
            ?>
            <article class="shop-card" onclick="location.href='shop.php?shop_id=<?= (int)$shop['shop_id'] ?>'">
              <div class="shop-top">
                <div>
                  <h4><?= h($shop['shop_name']) ?></h4>

                  <div class="chips">
                    <span class="chip">โดม <?= h($shop['dome_id']) ?></span>
                    <span class="chip">ล็อก <?= h($shop['lock_no']) ?></span>
                    <?php if (!empty($shop['category_name'])): ?>
                      <span class="chip"><?= h($shop['category_name']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($shop['type_name'])): ?>
                      <span class="chip"><?= h($shop['type_name']) ?></span>
                    <?php endif; ?>
                    <span class="chip <?= h($statusClass) ?>"><?= h($statusText) ?></span>
                  </div>
                </div>

                <a href="shop.php?shop_id=<?= (int)$shop['shop_id'] ?>" class="btn-main" onclick="event.stopPropagation();">เข้าหน้าร้าน</a>
              </div>

              <div class="shop-grid">
                <div class="info-box">
                  <span>เวลาเปิด–ปิด</span>
                  <strong>
                    <?= !empty($shop['open_time']) ? h(substr($shop['open_time'],0,5)) : '-' ?>
                    -
                    <?= !empty($shop['close_time']) ? h(substr($shop['close_time'],0,5)) : '-' ?>
                  </strong>
                </div>

                <div class="info-box">
                  <span>จำนวนคิวที่กำลังรอ</span>
                  <strong><?= $queueCount ?> คิว</strong>
                </div>

                <div class="info-box">
                  <span>คิวที่ร้านกำลังเรียก</span>
                  <strong><?= h($currentCall) ?></strong>
                </div>

                <div class="info-box">
                  <span>บริเวณร้าน</span>
                  <strong><?= h($shop['dome_name']) ?></strong>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty">
          ไม่พบร้านที่ตรงกับเงื่อนไขที่ค้นหา<br>
          ลองเปลี่ยนคำค้น เช่น “ชานม”, “กาแฟ”, “อาหารตามสั่ง” หรือเลือกหมวดหมู่ใหม่
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if (!$hasSearch && !empty($featuredShops)): ?>
    <section class="featured-wrap">
      <h3 class="section-title">ร้านเปิดอยู่ตอนนี้</h3>
      <p class="section-desc">ร้านที่กำลังเปิดให้บริการ เรียงจากร้านที่มีคิวรอน้อยก่อน เพื่อช่วยให้ตัดสินใจได้เร็วขึ้น</p>

      <div class="featured-grid">
        <?php foreach ($featuredShops as $shop): ?>
          <?php
            [$statusClass, $statusText] = statusMeta((string)$shop['status']);
            $queueCount = (int)($shop['active_queue_count'] ?? 0);
            $currentCall = $shop['current_call_no'] !== null ? '#' . (int)$shop['current_call_no'] : '-';
          ?>
          <article class="featured-card">
            <div class="top">
              <div>
                <h4><?= h($shop['shop_name']) ?></h4>
                <div class="meta-line">โดม <?= h($shop['dome_id']) ?> · ล็อก <?= h($shop['lock_no']) ?></div>
              </div>
              <span class="chip <?= h($statusClass) ?>"><?= h($statusText) ?></span>
            </div>

            <div class="chips">
              <?php if (!empty($shop['category_name'])): ?>
                <span class="chip"><?= h($shop['category_name']) ?></span>
              <?php endif; ?>
              <?php if (!empty($shop['type_name'])): ?>
                <span class="chip"><?= h($shop['type_name']) ?></span>
              <?php endif; ?>
              <span class="chip">คิวรอ <?= $queueCount ?> คิว</span>
            </div>

            <div class="meta-line">
              เวลาเปิด–ปิด:
              <?= !empty($shop['open_time']) ? h(substr($shop['open_time'],0,5)) : '-' ?>
              -
              <?= !empty($shop['close_time']) ? h(substr($shop['close_time'],0,5)) : '-' ?>
            </div>

            <div class="meta-line">คิวที่กำลังเรียก: <?= h($currentCall) ?></div>

            <div style="margin-top:12px;">
              <a href="shop.php?shop_id=<?= (int)$shop['shop_id'] ?>" class="btn-main">เข้าหน้าร้าน</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if (!$hasSearch): ?>
    <section>
      <h3 class="section-title" style="margin-bottom:8px;">เลือกดูร้านตามโดม</h3>
      <p class="section-desc" style="margin-bottom:16px;">เหมาะสำหรับผู้ใช้ที่ต้องการดูร้านตามตำแหน่งจริงภายในโรงอาหาร</p>

      <div class="zone-grid">
        <?php foreach ($domes as $d): ?>
          <?php $link = ((int)$d['dome_id'] === 1) ? 'dome1.php' : 'dome2.php'; ?>
          <article class="zone-card" onclick="location.href='<?= h($link) ?>'">
            <div class="zone-strip"></div>
            <div class="zone-body">
              <div class="zone-label"><?= h($d['dome_name']) ?></div>
              <h3>ดูร้านใน<?= h($d['dome_name']) ?></h3>
              <p>เลือกเพื่อเข้าสู่หน้าร้านในบริเวณนี้และดูสถานะร้านภายในโดม</p>

              <div class="meta-grid">
                <div class="meta-box meta-highlight">
                  <span>ร้านที่เปิด</span>
                  <strong><?= (int)$d['open_shops'] ?></strong>
                </div>
                <div class="meta-box">
                  <span>คิวเต็ม</span>
                  <strong><?= (int)$d['full_shops'] ?></strong>
                </div>
                <div class="meta-box">
                  <span>จำนวนล็อก</span>
                  <strong><?= (int)$d['total_locks'] ?></strong>
                </div>
                <div class="meta-box">
                  <span>ร้านพัก/ปิด</span>
                  <strong><?= (int)$d['break_shops'] + (int)$d['closed_shops'] ?></strong>
                </div>
              </div>

              <a href="<?= h($link) ?>" class="btn-main" onclick="event.stopPropagation();">ดูร้านใน<?= h($d['dome_name']) ?></a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

</main>

<script>
function getQueueIds(){
  try{
    const raw = localStorage.getItem("my_queue_ids");
    const arr = raw ? JSON.parse(raw) : [];
    if(!Array.isArray(arr)) return [];
    return [...new Set(
      arr.map(v => parseInt(v, 10)).filter(v => Number.isInteger(v) && v > 0)
    )];
  }catch(e){
    return [];
  }
}

async function getActiveQueueCount(){
  const ids = getQueueIds();
  if(ids.length === 0) return 0;

  try{
    const results = await Promise.all(
      ids.map(async (id) => {
        try{
          const res = await fetch(`api_my_queue.php?queue_id=${id}&_=${Date.now()}`, { cache: "no-store" });
          const data = await res.json();
          if(!data.ok) return null;
          const status = String(data.status || "").trim();
          return (status === "waiting" || status === "calling") ? id : null;
        }catch(err){
          return null;
        }
      })
    );
    return results.filter(Boolean).length;
  }catch(e){
    return 0;
  }
}

async function updateBellCount(){
  const badge = document.getElementById("bellCount");
  if(!badge) return;

  const count = await getActiveQueueCount();
  if(count > 0){
    badge.style.display = "flex";
    badge.textContent = count > 99 ? "99+" : String(count);
  }else{
    badge.style.display = "none";
    badge.textContent = "0";
  }
}

updateBellCount();
window.addEventListener("focus", updateBellCount);
document.addEventListener("visibilitychange", function(){
  if(!document.hidden) updateBellCount();
});
window.addEventListener("storage", function(e){
  if(e.key === "my_queue_ids") updateBellCount();
});
setInterval(updateBellCount, 5000);
</script>

</body>
</html>