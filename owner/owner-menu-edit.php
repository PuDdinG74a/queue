<?php
// owner/owner-menu-edit.php
require_once __DIR__ . "/_auth.php";

$requestedShopId = isset($_GET["shop_id"]) ? (int)$_GET["shop_id"] : 0;
$shop_id = enforceOwnerShopAccess($requestedShopId);
$item_id = isset($_GET["item_id"]) ? (int)$_GET["item_id"] : 0;

if ($item_id <= 0) {
  http_response_code(400);
  echo "เปิดไม่ถูกวิธี: owner-menu-edit.php?shop_id=124&item_id=1";
  exit;
}

// ดึงชื่อร้าน
$stmt = $pdo->prepare("SELECT name FROM shops WHERE shop_id = ? LIMIT 1");
$stmt->execute([$shop_id]);
$shop = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shop) {
  http_response_code(404);
  echo "ไม่พบร้าน";
  exit;
}

function h($s){
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>แก้ไขเมนู | Owner</title>
  <link rel="stylesheet" href="./owner-style.css?v=1">
  <style>
    .img-preview{
      width:100%;
      max-width:420px;
      height:220px;
      background:#f3f3f3;
      border-radius:14px;
      display:flex;
      align-items:center;
      justify-content:center;
      overflow:hidden;
      border:1px solid #eee;
    }
    .img-preview img{width:100%;height:100%;object-fit:cover;display:block;}
    .row2{display:flex;gap:12px;flex-wrap:wrap;}
    .row2 .field{flex:1;min-width:220px;}
    .hint{font-size:13px;color:#666;margin-top:6px;line-height:1.35;}
    .mini-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;}
    .mono{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;}
  </style>
</head>
<body>
  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-logo">MN</div>
        <div class="title">
          <b><?= h($shop["name"]) ?></b>
          <small>Owner Panel</small>
        </div>
      </div>

      <nav class="nav">
        <a href="owner-dashboard.php?shop_id=<?= (int)$shop_id ?>">📊 แดชบอร์ด</a>
        <a href="shop_owner.php?shop_id=<?= (int)$shop_id ?>">🧾 ออเดอร์/คิวลูกค้า</a>
        <a class="active" href="owner-menu.php?shop_id=<?= (int)$shop_id ?>">🍜 เมนูอาหาร</a>
        <a href="owner-settings.php?shop_id=<?= (int)$shop_id ?>">⚙️ ตั้งค่าร้าน</a>
        <a href="owner-reports.php?shop_id=<?= (int)$shop_id ?>">📑 รายงาน</a>
        <a href="../logout.php">🚪 ออกจากระบบ</a>
      </nav>

      <div class="footer">แก้ไขเมนู • ราคา/รูป/สถานะ</div>
    </aside>

    <main class="main">
      <div class="topbar">
        <div>
          <div class="page-title">แก้ไขเมนู</div>
          <div class="small">item_id = <?= (int)$item_id ?> • shop_id = <?= (int)$shop_id ?></div>
        </div>
        <div class="actions">
          <button class="btn btn-outline" onclick="location.href='owner-menu.php?shop_id=<?= (int)$shop_id ?>'">← กลับ</button>
        </div>
      </div>

      <div class="grid">
        <section class="card col-6">
          <h3 style="margin:0 0 8px;">ข้อมูลเมนู</h3>
          <div class="small">แก้ไขแล้วกด “บันทึก”</div>
          <div class="hr"></div>

          <div class="field">
            <label>ชื่อเมนู</label>
            <input id="itemName" type="text" placeholder="เช่น วาฟเฟิลชาไทย">
          </div>

          <div class="field">
            <label>รูปภาพเมนู (ถ่าย/อัปโหลด)</label>
            <input id="imageFile" type="file" accept="image/*" capture="environment">
            <div class="hint">
              * เลือกรูปจากเครื่อง หรือกดถ่ายรูปได้เลย (มือถือจะเปิดกล้อง)<br>
              * ถ้าไม่เลือกไฟล์ใหม่ ระบบจะใช้รูปเดิม
            </div>
          </div>

          <input type="hidden" id="imageUrlHidden" value="">

          <div class="field">
            <label>โหมดราคา</label>
            <select id="priceMode">
              <option value="single">ราคาเดียว</option>
              <option value="range">ธรรมดา/พิเศษ</option>
            </select>
          </div>

          <div id="priceSingle" class="field">
            <label>ราคา</label>
            <input id="price" type="number" min="0" step="0.5" placeholder="เช่น 10">
          </div>

          <div id="priceRange" class="row2" style="display:none;">
            <div class="field">
              <label>ราคาธรรมดา</label>
              <input id="priceMin" type="number" min="0" step="0.5" placeholder="เช่น 10">
            </div>
            <div class="field">
              <label>ราคาพิเศษ</label>
              <input id="priceMax" type="number" min="0" step="0.5" placeholder="เช่น 15">
            </div>
          </div>

          <div class="field">
            <label>สถานะเมนู</label>
            <select id="avail">
              <option value="1">มีขาย</option>
              <option value="0">หมด</option>
            </select>
          </div>

          <div class="actions">
            <button class="btn btn-primary" id="saveBtn" type="button">บันทึก</button>
            <button class="btn btn-danger" id="delBtn" type="button">ลบเมนูนี้</button>
          </div>

          <p id="msg" class="small" style="margin-top:10px;"></p>
        </section>

        <section class="card col-6">
          <h3 style="margin:0 0 8px;">พรีวิวรูป</h3>
          <div class="small">เลือกไฟล์แล้วจะเห็นตัวอย่างทันที</div>
          <div class="hr"></div>

          <div class="img-preview" id="imgBox">ยังไม่มีรูป</div>

          <div class="mini-actions">
            <button class="btn btn-outline" id="clearFileBtn" type="button">ล้างไฟล์ที่เลือก</button>
          </div>

          <div class="hint">
            รูปเดิม (ถ้ามี): <span class="mono" id="oldUrlText">-</span>
          </div>
        </section>
      </div>
    </main>
  </div>

<script>
  const shopId = <?= (int)$shop_id ?>;
  const itemId = <?= (int)$item_id ?>;

  const itemName = document.getElementById("itemName");
  const imageFile = document.getElementById("imageFile");
  const imageUrlHidden = document.getElementById("imageUrlHidden");
  const oldUrlText = document.getElementById("oldUrlText");

  const priceMode = document.getElementById("priceMode");
  const priceSingle = document.getElementById("priceSingle");
  const priceRange = document.getElementById("priceRange");
  const price = document.getElementById("price");
  const priceMin = document.getElementById("priceMin");
  const priceMax = document.getElementById("priceMax");
  const avail = document.getElementById("avail");
  const msg = document.getElementById("msg");
  const imgBox = document.getElementById("imgBox");
  const clearFileBtn = document.getElementById("clearFileBtn");

  const saveBtn = document.getElementById("saveBtn");
  const delBtn = document.getElementById("delBtn");

  let isLoading = false;

  function setMsg(t, c = "#111"){
    msg.textContent = t;
    msg.style.color = c;
  }

  function togglePriceUI(){
    if (priceMode.value === "range") {
      priceSingle.style.display = "none";
      priceRange.style.display = "flex";
    } else {
      priceSingle.style.display = "block";
      priceRange.style.display = "none";
    }
  }
  priceMode.addEventListener("change", togglePriceUI);

  function renderImgFromUrl(url){
    if (!url) {
      imgBox.innerHTML = "ยังไม่มีรูป";
      return;
    }
    const safe = String(url).replace(/"/g, "&quot;");
    imgBox.innerHTML = `<img src="${safe}" alt="" onerror="this.remove();this.parentElement.textContent='โหลดรูปไม่สำเร็จ';">`;
  }

  function renderImgFromFile(file){
    if (!file) {
      renderImgFromUrl(imageUrlHidden.value.trim());
      return;
    }
    const reader = new FileReader();
    reader.onload = () => {
      imgBox.innerHTML = `<img src="${reader.result}" alt="">`;
    };
    reader.readAsDataURL(file);
  }

  imageFile.addEventListener("change", () => {
    const f = imageFile.files?.[0] || null;
    renderImgFromFile(f);
  });

  clearFileBtn.addEventListener("click", () => {
    imageFile.value = "";
    renderImgFromUrl(imageUrlHidden.value.trim());
    setMsg("ล้างไฟล์แล้ว", "#111");
  });

  async function apiGet(){
    try {
      const res = await fetch(`menu_api.php?action=get&shop_id=${shopId}&item_id=${itemId}`, { cache: "no-store" });
      return await res.json();
    } catch {
      return { ok:false, error:"โหลดข้อมูลไม่ได้" };
    }
  }

  async function apiPostFormData(action, fd){
    fd.append("action", action);
    fd.append("shop_id", shopId);
    try {
      const res = await fetch("menu_api.php", { method: "POST", body: fd });
      return await res.json();
    } catch {
      return { ok:false, error:"เชื่อมต่อเซิร์ฟเวอร์ไม่ได้" };
    }
  }

  async function load(){
    setMsg("กำลังโหลด...");
    const data = await apiGet();

    if (!data.ok) {
      setMsg(data.error || "โหลดไม่สำเร็จ", "#ef4444");
      return;
    }

    const it = data.item;

    itemName.value = it.item_name || "";
    avail.value = String(it.is_available ?? "1");

    const oldUrl = it.image_url || "";
    imageUrlHidden.value = oldUrl;
    oldUrlText.textContent = oldUrl || "-";

    if (it.price_min !== null && it.price_max !== null) {
      priceMode.value = "range";
      priceMin.value = it.price_min;
      priceMax.value = it.price_max;
      price.value = "";
    } else {
      priceMode.value = "single";
      price.value = it.price ?? "";
      priceMin.value = "";
      priceMax.value = "";
    }

    togglePriceUI();
    renderImgFromUrl(oldUrl);
    setMsg("");
  }

  saveBtn.addEventListener("click", async () => {
    if (isLoading) return;

    const name = itemName.value.trim();
    if (!name) {
      setMsg("กรุณาใส่ชื่อเมนู", "#ef4444");
      return;
    }

    isLoading = true;
    saveBtn.disabled = true;
    saveBtn.textContent = "กำลังบันทึก...";

    const mode = priceMode.value;
    const fd = new FormData();

    fd.append("item_id", itemId);
    fd.append("item_name", name);
    fd.append("is_available", avail.value);
    fd.append("price_mode", mode);

    if (mode === "range") {
      if (!priceMin.value || !priceMax.value) {
        setMsg("กรอกราคาให้ครบ", "#ef4444");
        resetBtn();
        return;
      }
      fd.append("price", "");
      fd.append("price_min", priceMin.value);
      fd.append("price_max", priceMax.value);
    } else {
      if (!price.value) {
        setMsg("กรอกราคา", "#ef4444");
        resetBtn();
        return;
      }
      fd.append("price", price.value);
      fd.append("price_min", "");
      fd.append("price_max", "");
    }

    const f = imageFile.files?.[0] || null;
    if (f) {
      fd.append("image_file", f);
    } else {
      fd.append("image_url", imageUrlHidden.value.trim());
    }

    const res = await apiPostFormData("update", fd);

    resetBtn();

    if (!res.ok) {
      setMsg(res.error || "บันทึกไม่สำเร็จ", "#ef4444");
      return;
    }

    if (res.image_url !== undefined) {
      imageUrlHidden.value = res.image_url || "";
      oldUrlText.textContent = res.image_url || "-";
      renderImgFromUrl(res.image_url || "");
    }

    imageFile.value = "";
    setMsg("บันทึกแล้ว ✅", "#16a34a");
  });

  function resetBtn(){
    isLoading = false;
    saveBtn.disabled = false;
    saveBtn.textContent = "บันทึก";
  }

  delBtn.addEventListener("click", async () => {
    if (isLoading) return;
    if (!confirm("ต้องการลบเมนูนี้ใช่ไหม?")) return;

    isLoading = true;
    delBtn.disabled = true;
    delBtn.textContent = "กำลังลบ...";

    const fd = new FormData();
    fd.append("item_id", itemId);

    const res = await apiPostFormData("delete", fd);

    if (!res.ok) {
      setMsg(res.error || "ลบไม่สำเร็จ", "#ef4444");
      delBtn.disabled = false;
      delBtn.textContent = "ลบเมนูนี้";
      isLoading = false;
      return;
    }

    location.href = `owner-menu.php?shop_id=${shopId}`;
  });

  load();
</script>
<script>
  window.OWNER_NOTIFY_SHOP_ID = <?= (int)$shop_id ?>;
</script>
<script src="owner-notify.js"></script>
</body>
</html>