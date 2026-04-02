<?php
require_once __DIR__ . "/../config.php";

$domeId = 2;

$stmt = $pdo->prepare("
  SELECT
    l.lock_id, l.lock_no, l.is_active,
    s.shop_id, s.name AS shop_name, s.status AS shop_status
  FROM locks l
  LEFT JOIN shops s ON s.lock_id = l.lock_id
  WHERE l.dome_id = ?
  ORDER BY l.lock_no ASC
");
$stmt->execute([$domeId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$byNo = [];
foreach ($rows as $r) {
  $no = (int)$r["lock_no"];
  if (!isset($byNo[$no]) || (!empty($r["shop_id"]))) {
    $byNo[$no] = $r;
  }
}

function d2_shop_name($lockNo, $byNo){
  $no  = (int)$lockNo;
  $row = $byNo[$no] ?? null;

  $raw = "";
  if ($row && !empty($row["shop_id"]) && isset($row["shop_name"])) {
    $raw = trim((string)$row["shop_name"]);
  }
  $raw = preg_replace('/\s+/u', ' ', $raw);
  if ($raw === "") $raw = "ว่าง";
  return $raw;
}

function d2_stall($lockNo, $extraClass, $byNo){
  $no  = (int)$lockNo;
  $no2 = str_pad((string)$no, 2, "0", STR_PAD_LEFT);

  $row = $byNo[$no] ?? null;
  $hasShop = $row && !empty($row["shop_id"]);

  $rawName = d2_shop_name($no, $byNo);
  $safeName  = htmlspecialchars($rawName, ENT_QUOTES, "UTF-8");
  $safeTitle = $safeName;

  $href = $hasShop ? "shop.php?shop_id=".(int)$row["shop_id"]
                   : "shop.php?dome_id=2&lock_no=".$no2;

  return '
    <a class="d2-stall '.$extraClass.'" href="'.$href.'" title="'.$safeTitle.'">
      <div class="d2-number">'.$no2.'</div>
      <div class="d2-name">'.$safeName.'</div>
    </a>
  ';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ผังโรงอาหารกลาง (ตลาดน้อย) — โดม 2</title>
  <link rel="stylesheet" href="style.css?v=<?= time() ?>">
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="page-dome2">

  <div class="plan-viewport" id="vp-d2">
    <div class="plan-wrapper dome2-ui" id="wrap-d2">

      <div class="page-head">
        <a href="index.php" class="back-link">← กลับหน้าแรก</a>
        <div class="head-title">
          <h1>ผังโรงอาหารกลาง (ตลาดน้อย) — โดม 2</h1>
          <p>คลิกที่ล็อกเพื่อดูรายละเอียดร้านค้า</p>
        </div>
      </div>

      <!-- ✅ โซนนี้จะถูก scale ให้พอดีหน้าจอ (ตำแหน่งไม่เพี้ยน) -->
      <div class="d2-stage" id="stage-d2">
        <div class="dome2-layout">

          <!-- ซ้าย: ทางเชื่อม (ต้องอยู่ซ้ายเสมอ) -->
          <aside class="left-connector">
            <div class="connector-box">
              ทางเชื่อม<br>ระหว่างโดม 1<br>กับโดม 2
            </div>
          </aside>

          <!-- กลาง: แผนผัง -->
          <main class="map">

            <div class="roof-row">
              <div class="roof">หอพักนิสิต</div>
              <div class="roof">หอพักนิสิต</div>
            </div>

            <div class="wall">กำแพง</div>

            <!-- แถวบน: 01–16 + ห้องประปา + ห้องน้ำ/ซักผ้า “แถวเดียวกัน” -->
            <div class="row-top">
              <div class="top-stalls">
                <?php for($i=1;$i<=16;$i++) echo d2_stall($i,"dark",$byNo); ?>
                <div class="room-water">ห้อง<br>ประปา</div>
              </div>

              <div class="top-houses">
                <div class="house">ห้องน้ำ</div>
                <div class="house">ร้านซักผ้า 24 ชม.</div>
              </div>
            </div>

            <!-- กลาง -->
            <div class="row-mid">
              <div class="block dark g6 block-left">
                <?php for($i=17;$i<=22;$i++) echo d2_stall($i,"dark",$byNo); ?>
                <?php for($i=37;$i<=42;$i++) echo d2_stall($i,"dark",$byNo); ?>
              </div>

              <div class="block g4 block-mid1">
                <?php for($i=23;$i<=26;$i++) echo d2_stall($i,"",$byNo); ?>
                <?php for($i=43;$i<=46;$i++) echo d2_stall($i,"",$byNo); ?>
              </div>

              <div class="block g4 block-mid2">
                <?php for($i=27;$i<=30;$i++) echo d2_stall($i,"",$byNo); ?>
                <?php for($i=47;$i<=50;$i++) echo d2_stall($i,"",$byNo); ?>
              </div>

              <div class="block dark g6 block-right">
                <?php for($i=31;$i<=36;$i++) echo d2_stall($i,"dark",$byNo); ?>
                <?php for($i=51;$i<=56;$i++) echo d2_stall($i,"dark",$byNo); ?>
              </div>
            </div>

            <!-- แถวล่าง -->
            <div class="row-bottom">
              <div class="room-electric">ห้อง<br>ไฟฟ้า</div>
              <div class="bottom-stalls">
                <?php for($i=57;$i<=72;$i++) echo d2_stall($i,"dark",$byNo); ?>
              </div>
            </div>

            <div class="roads">
              <div class="road">ถนน</div>
              <div class="road">ถนน</div>
              <div class="road">ถนน</div>
            </div>

          </main>

          <!-- ขวา: (ถ้าคุณมี legend ก็ใส่ไว้คอลัมน์ขวาตรงนี้) -->
          <!-- <aside class="right-side legend">...</aside> -->

        </div>
      </div>

    </div>
  </div>

  <!-- ✅ Auto-scale: Notebook = 1, Mobile = ย่อให้พอดี viewport -->
  <script>
    (function(){
      const vp = document.getElementById('vp-d2');
      const stage = document.getElementById('stage-d2');
      const root = document.documentElement;

      function fit(){
        if(!vp || !stage) return;

        // reset ก่อนวัด
        root.style.setProperty('--d2-scale', '1');

        requestAnimationFrame(() => {
          const vpW = vp.clientWidth;
          const naturalW = stage.scrollWidth; // กว้างจริงตอน scale=1
          if(!vpW || !naturalW) return;

          // ลดลงให้พอดี แต่ไม่ขยายเกิน 1
          const s = Math.min(1, vpW / naturalW);

          // กันเล็กเกินจนอ่านไม่ออก (ปรับได้)
          const clamped = Math.max(0.55, s);

          root.style.setProperty('--d2-scale', clamped.toFixed(4));
        });
      }

      window.addEventListener('resize', fit, {passive:true});
      window.addEventListener('orientationchange', fit, {passive:true});
      fit();
      setTimeout(fit, 250);
    })();
  </script>

</body>
</html>