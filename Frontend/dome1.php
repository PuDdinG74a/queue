<?php
// dome1.php (อยู่ในโฟลเดอร์ Frontend)
require_once __DIR__ . "/../config.php";

$sql = "
  SELECT s.shop_id, s.name, s.status, l.lock_no
  FROM shops s
  INNER JOIN locks l ON l.lock_id = s.lock_id
  WHERE l.dome_id = 1
  ORDER BY l.lock_no ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$shopsByLock = [];
foreach ($rows as $r) {
  $shopsByLock[(int)$r["lock_no"]] = $r;
}

function renderStall($lockNo, $displayNo, $classes = "") {
  global $shopsByLock;

  $shop = $shopsByLock[$lockNo] ?? null;

  if ($shop) {
    $shopId = (int)$shop["shop_id"];
    $shopNameRaw = $shop["name"] ?? "ไม่ระบุชื่อร้าน";

    $name   = htmlspecialchars($shopNameRaw, ENT_QUOTES, "UTF-8");
    $href   = "shop.php?shop_id=" . $shopId;
    $cursor = "pointer";
    $titleText = $shopNameRaw;
  } else {
    $name   = "ว่าง";
    $href   = "javascript:void(0)";
    $cursor = "default";
    $titleText = "ว่าง";
  }

  $displayNoHtml = htmlspecialchars($displayNo, ENT_QUOTES, "UTF-8");
  $titleAttr = htmlspecialchars($titleText, ENT_QUOTES, "UTF-8");

  echo '<a href="'.$href.'" class="stall '.$classes.'" data-lock="'.$lockNo.'" style="cursor:'.$cursor.';"';
  echo ' title="'.$titleAttr.'" data-title="'.$titleAttr.'">';
  echo '  <div class="stall-number">'.$displayNoHtml.'</div>';
  echo '  <div class="stall-name">'.$name.'</div>';
  echo '</a>';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ผังโรงอาหารกลาง (ตลาดน้อย) โดม 1</title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css?v=<?= time() ?>">

  <!-- ✅ FIX มือถือ: Fit-to-screen + ไม่ตัด -->
  <style>
    .plan-viewport{ width:100%; overflow:hidden; }
    .fit-wrap{ width:100%; overflow:hidden; }
    .fit-scale{ transform-origin: top left; will-change: transform; }

    /* กันหัวชน */
    .page-dome1 h1{ line-height:1.2; }

    @media (max-width: 1100px){
      body.page-dome1{ padding:12px !important; }
      .page-dome1 .plan-wrapper{ max-width:100% !important; }
      .page-dome1 h1{ font-size:18px !important; }
      .page-dome1 .sub-title{ font-size:12px !important; }
    }
  </style>
</head>

<body class="page-dome1">
  <div class="plan-viewport">
    <div class="fit-wrap">
      <div class="fit-scale" id="fitScale1">
        <div class="plan-wrapper" id="fitBox1">
          <h1>ผังโรงอาหารกลาง (ตลาดน้อย) โดม 1</h1>
          <div class="sub-title">ผังแสดงเลขล็อกและชื่อร้านอาหาร</div>
          <a href="index.php" class="back-link">← กลับหน้าแรก</a>

          <div class="top-row">
            <div class="roof-box">โรงอาหาร</div>
            <div>
              <div class="roof-box" style="margin-bottom:4px;">หอพักนิสิต</div>
              <div class="road-label">ทางเดินหน้าแถวล็อก 01–08</div>
            </div>
            <div class="roof-box">หอพักนิสิต</div>
          </div>

          <div class="center-area">
            <div class="left-side">
              <div class="box-label big">
                ลานจอดรถ บริเวณด้านหน้า<br>โรงอาหารตลาดน้อย โดม 1
              </div>
              <div class="box-label">ร้าน U-Store</div>
            </div>

            <div class="stalls-zone">
              <div class="lane-wide">
                <div class="stalls-row-top">
                  <?php for($i=1;$i<=8;$i++): ?>
                    <?php renderStall($i, str_pad((string)$i, 2, "0", STR_PAD_LEFT), "stall-h stall-top-long"); ?>
                  <?php endfor; ?>
                </div>
              </div>

              <div class="walk-space"></div>

              <div class="lane">
                <div class="middle-rows">
                  <div class="mid-col-left">
                    <div class="block-vert-row">
                      <?php for($i=9;$i<=16;$i++): ?>
                        <?php renderStall($i, str_pad((string)$i, 2, "0", STR_PAD_LEFT), "stall-v"); ?>
                      <?php endfor; ?>
                    </div>

                    <div class="block-vert-row">
                      <?php for($i=20;$i<=27;$i++): ?>
                        <?php renderStall($i, (string)$i, "stall-v"); ?>
                      <?php endfor; ?>
                    </div>
                  </div>

                  <div class="mid-col-right">
                    <div class="block-3x2">
                      <?php for($i=17;$i<=19;$i++): ?>
                        <?php renderStall($i, (string)$i, "stall-h"); ?>
                      <?php endfor; ?>

                      <?php for($i=28;$i<=30;$i++): ?>
                        <?php renderStall($i, (string)$i, "stall-h"); ?>
                      <?php endfor; ?>
                    </div>
                  </div>
                </div>

                <div class="walk-space-large"></div>

                <div class="middle-rows">
                  <div class="mid-col-left">
                    <div class="block-vert-row">
                      <?php for($i=31;$i<=38;$i++): ?>
                        <?php renderStall($i, (string)$i, "stall-v"); ?>
                      <?php endfor; ?>
                    </div>

                    <div class="block-vert-row">
                      <?php for($i=42;$i<=49;$i++): ?>
                        <?php renderStall($i, (string)$i, "stall-v"); ?>
                      <?php endfor; ?>
                    </div>
                  </div>

                  <div class="mid-col-right">
                    <div class="block-3x2">
                      <?php for($i=39;$i<=41;$i++): ?>
                        <?php renderStall($i, (string)$i, "stall-h"); ?>
                      <?php endfor; ?>

                      <?php for($i=50;$i<=52;$i++): ?>
                        <?php renderStall($i, (string)$i, "stall-h"); ?>
                      <?php endfor; ?>
                    </div>
                  </div>
                </div>

              </div>

              <div class="walk-space-large"></div>

              <div class="lane-wide">
                <div class="bottom-row-wide">
                  <?php for($i=53;$i<=66;$i++): ?>
                    <?php renderStall($i, (string)$i, "stall-bottom stall-bottom-long"); ?>
                  <?php endfor; ?>

                  <a href="#" class="stall stall-bottom stall-bottom-long special-stall">
                    <div class="stall-number">น้ำ</div><div class="stall-name">น้ำ</div>
                  </a>
                  <a href="#" class="stall stall-bottom stall-bottom-long special-stall">
                    <div class="stall-number">ไฟฟ้า</div><div class="stall-name">ไฟฟ้า</div>
                  </a>
                </div>
              </div>

              <div class="bottom-road">ถนน</div>
            </div>
          </div>
        </div><!-- /fitBox1 -->
      </div><!-- /fitScale1 -->
    </div><!-- /fit-wrap -->
  </div><!-- /plan-viewport -->

  <!-- ✅ Fit-to-screen บนมือถือ -->
  <script>
    function fitPlan(scaleId, boxId){
      const scaleBox = document.getElementById(scaleId);
      const box = document.getElementById(boxId);
      if(!scaleBox || !box) return;

      const isNarrow = window.matchMedia("(max-width: 1100px)").matches;
      if(!isNarrow){
        scaleBox.style.transform = "";
        scaleBox.style.width = "";
        return;
      }

      const vw = document.documentElement.clientWidth;
      const w = box.scrollWidth || box.offsetWidth;
      if(!w) return;

      const s = Math.min(1, (vw - 16) / w);
      scaleBox.style.transform = `scale(${s})`;
      scaleBox.style.width = w + "px";
    }

    function applyFit1(){ fitPlan("fitScale1","fitBox1"); }
    window.addEventListener("load", applyFit1);
    window.addEventListener("resize", applyFit1);
  </script>
</body>
</html>