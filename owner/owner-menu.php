<?php
// owner/owner-menu.php
require_once __DIR__ . "/_auth.php";

$requestedShopId = isset($_GET["shop_id"]) ? (int)$_GET["shop_id"] : 0;
$shop_id = enforceOwnerShopAccess($requestedShopId);

// ดึงชื่อร้านไว้โชว์ sidebar
$stmt = $pdo->prepare("SELECT shop_id, name FROM shops WHERE shop_id = ? LIMIT 1");
$stmt->execute([$shop_id]);
$shop = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shop) {
  http_response_code(404);
  echo "ไม่พบร้าน shop_id=" . $shop_id;
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
  <title>เมนูอาหาร | Owner</title>
  <link rel="stylesheet" href="./owner-style.css?v=1">
  <style>
    .row2{display:flex;gap:12px;flex-wrap:wrap;}
    .row2 .field{flex:1;min-width:180px;}
    .hint{font-size:13px;color:#666;margin-top:6px;line-height:1.35;}
    .img-mini{width:44px;height:44px;border-radius:10px;background:#f3f3f3;overflow:hidden;display:flex;align-items:center;justify-content:center;}
    .img-mini img{width:100%;height:100%;object-fit:cover;display:block;}

    .preview-wrap{display:flex;gap:10px;align-items:center;margin-top:8px;}
    .preview{
      width:84px;height:84px;border-radius:14px;background:#f3f3f3;
      overflow:hidden;display:flex;align-items:center;justify-content:center;border:1px dashed #e5e7eb;
    }
    .preview img{width:100%;height:100%;object-fit:cover;display:block;}
    .preview .ph{font-size:12px;color:#777;text-align:center;padding:8px;}
  </style>
</head>
<body>
  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-logo">MN</div>
        <div class="title">
          <b id="shopNameSide"><?= h($shop["name"]) ?></b>
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

      <div class="footer">จัดการเมนู • เพิ่ม/แก้ไข/ลบ</div>
    </aside>

    <main class="main">
      <div class="topbar">
        <div class="page-title">เมนูอาหารของร้าน</div>
        <div class="badge"><span class="dot"></span><span>บันทึกลงฐานข้อมูล</span></div>
      </div>

      <div class="grid">
        <section class="card col-6">
          <h3 style="margin:0 0 8px 0;">เพิ่มเมนูใหม่</h3>
          <div class="small">กรอกข้อมูล แล้วกด “เพิ่มเมนู”</div>
          <div class="hr"></div>

          <div class="field">
            <label for="mName">ชื่อเมนู</label>
            <input id="mName" type="text" placeholder="เช่น วาฟเฟิลชาไทย">
          </div>

          <div class="field">
            <label for="mImageFile">รูปภาพ (ถ่าย/อัปโหลดไฟล์)</label>
            <input
              id="mImageFile"
              type="file"
              accept="image/*"
              capture="environment"
            >
            <div class="hint">
              * กดเลือกไฟล์ หรือถ้าใช้มือถือจะมีตัวเลือก “ถ่ายรูป” ได้ด้วย<br>
              * รองรับ jpg/png/webp ขนาดไม่เกิน ~5MB
            </div>

            <div class="preview-wrap">
              <div class="preview" id="imgPreview">
                <div class="ph">ยังไม่ได้เลือกรูป</div>
              </div>
              <button class="btn btn-outline" id="clearImageBtn" type="button" style="height:42px;">ล้างรูป</button>
            </div>
          </div>

          <div class="field">
            <label for="mPriceMode">โหมดราคา</label>
            <select id="mPriceMode">
              <option value="single">ราคาเดียว</option>
              <option value="range">ธรรมดา/พิเศษ</option>
            </select>
          </div>

          <div class="field" id="priceSingleBox">
            <label for="mPrice">ราคา (บาท)</label>
            <input id="mPrice" type="number" min="0" step="0.5" placeholder="เช่น 10">
          </div>

          <div class="row2" id="priceRangeBox" style="display:none;">
            <div class="field">
              <label for="mPriceMin">ราคาธรรมดา</label>
              <input id="mPriceMin" type="number" min="0" step="0.5" placeholder="เช่น 10">
            </div>
            <div class="field">
              <label for="mPriceMax">ราคาพิเศษ</label>
              <input id="mPriceMax" type="number" min="0" step="0.5" placeholder="เช่น 15">
            </div>
          </div>

          <div class="field">
            <label for="mAvail">สถานะเมนู</label>
            <select id="mAvail">
              <option value="1">มีขาย</option>
              <option value="0">หมด</option>
            </select>
          </div>

          <div class="actions">
            <button class="btn btn-primary" id="addBtn" type="button">เพิ่มเมนู</button>
          </div>

          <p id="msg" class="small" style="margin-top:10px;"></p>
        </section>

        <section class="card col-6">
          <h3 style="margin:0 0 8px 0;">รายการเมนู</h3>
          <div class="small">แก้ไข/ลบ ได้จากปุ่มด้านขวา</div>
          <div class="hr"></div>

          <table class="table">
            <thead>
              <tr>
                <th>เมนู</th>
                <th style="width:140px;">ราคา</th>
                <th style="width:110px;">สถานะ</th>
                <th style="width:170px;">จัดการ</th>
              </tr>
            </thead>
            <tbody id="menuBody"></tbody>
          </table>

          <div class="note" style="margin-top:12px;">
            * เมนูนี้จะแสดงในหน้าลูกค้า (Frontend/shop.php) อัตโนมัติ
          </div>
        </section>
      </div>
    </main>
  </div>

<script>
  const shopId = <?= (int)$shop_id ?>;

  const mName = document.getElementById("mName");
  const mImageFile = document.getElementById("mImageFile");
  const imgPreview = document.getElementById("imgPreview");
  const clearImageBtn = document.getElementById("clearImageBtn");

  const mPriceMode = document.getElementById("mPriceMode");
  const priceSingleBox = document.getElementById("priceSingleBox");
  const priceRangeBox = document.getElementById("priceRangeBox");

  const mPrice = document.getElementById("mPrice");
  const mPriceMin = document.getElementById("mPriceMin");
  const mPriceMax = document.getElementById("mPriceMax");

  const mAvail = document.getElementById("mAvail");
  const msg = document.getElementById("msg");
  const menuBody = document.getElementById("menuBody");
  const addBtn = document.getElementById("addBtn");

  let isLoading = false;

  function setMsg(text, color = "#111"){
    msg.textContent = text;
    msg.style.color = color;
  }

  function escapeHtml(str){
    return String(str).replace(/[&<>"']/g, m => ({
      "&":"&amp;",
      "<":"&lt;",
      ">":"&gt;",
      '"':"&quot;",
      "'":"&#039;"
    }[m]));
  }

  function togglePriceUI(){
    if (mPriceMode.value === "range") {
      priceSingleBox.style.display = "none";
      priceRangeBox.style.display = "flex";
    } else {
      priceSingleBox.style.display = "block";
      priceRangeBox.style.display = "none";
    }
  }

  mPriceMode.addEventListener("change", togglePriceUI);
  togglePriceUI();

  function renderPreview(file){
    if (!file) {
      imgPreview.innerHTML = `<div class="ph">ยังไม่ได้เลือกรูป</div>`;
      return;
    }
    const url = URL.createObjectURL(file);
    imgPreview.innerHTML = `<img src="${url}" alt="">`;
  }

  mImageFile.addEventListener("change", () => {
    const f = mImageFile.files?.[0] || null;
    renderPreview(f);
  });

  clearImageBtn.addEventListener("click", () => {
    mImageFile.value = "";
    renderPreview(null);
  });

  async function apiList(){
    try {
      const res = await fetch(`menu_api.php?action=list&shop_id=${shopId}`, { cache: "no-store" });
      return await res.json();
    } catch (e) {
      return { ok:false, error:"โหลดข้อมูลไม่ได้" };
    }
  }

  async function apiPost(action, data, file = null){
    const fd = new FormData();
    fd.append("action", action);
    fd.append("shop_id", shopId);

    for (const k in data) {
      fd.append(k, data[k]);
    }

    if (file) fd.append("image_file", file);

    try {
      const res = await fetch("menu_api.php", { method: "POST", body: fd });
      return await res.json();
    } catch (e) {
      return { ok:false, error:"เชื่อมต่อเซิร์ฟเวอร์ไม่ได้" };
    }
  }

  function formatPrice(it){
    if (it.price_min && it.price_max) {
      return `${Number(it.price_min)} / ${Number(it.price_max)}`;
    }
    return `${Number(it.price || 0)} บาท`;
  }

  function renderThumb(url){
    if (!url) return `<div class="img-mini">-</div>`;
    return `<div class="img-mini"><img src="${escapeHtml(url)}"></div>`;
  }

  async function render(){
    menuBody.innerHTML = `<tr><td colspan="4" class="small">กำลังโหลด...</td></tr>`;

    const data = await apiList();

    if (!data.ok) {
      menuBody.innerHTML = `<tr><td colspan="4">โหลดไม่สำเร็จ</td></tr>`;
      setMsg(data.error || "โหลดไม่ได้", "#ef4444");
      return;
    }

    const items = data.items || [];
    menuBody.innerHTML = "";

    if (!items.length) {
      menuBody.innerHTML = `<tr><td colspan="4">ยังไม่มีเมนู</td></tr>`;
      return;
    }

    items.forEach(it => {
      const tr = document.createElement("tr");

      tr.innerHTML = `
        <td>
          <div style="display:flex;gap:10px;align-items:center;">
            ${renderThumb(it.image_url)}
            <div>
              <b>${escapeHtml(it.item_name)}</b>
              <div class="small">ID: ${it.item_id}</div>
            </div>
          </div>
        </td>
        <td>${formatPrice(it)}</td>
        <td style="font-weight:900;color:${it.is_available==1?'#16a34a':'#ef4444'};">
          ${it.is_available==1?'มีขาย':'หมด'}
        </td>
        <td>
          <div class="actions">
            <button class="btn btn-outline" data-act="edit" data-id="${it.item_id}">แก้ไข</button>
            <button class="btn btn-danger" data-act="del" data-id="${it.item_id}">ลบ</button>
          </div>
        </td>
      `;

      menuBody.appendChild(tr);
    });
  }

  addBtn.addEventListener("click", async () => {
    if (isLoading) return;

    const name = mName.value.trim();
    if (!name) return setMsg("กรอกชื่อเมนู", "#ef4444");

    isLoading = true;
    addBtn.disabled = true;
    addBtn.textContent = "กำลังเพิ่ม...";

    const file = mImageFile.files?.[0] || null;

    let payload = {
      item_name: name,
      is_available: mAvail.value,
      price_mode: mPriceMode.value
    };

    if (mPriceMode.value === "range") {
      payload.price_min = mPriceMin.value;
      payload.price_max = mPriceMax.value;
    } else {
      payload.price = mPrice.value;
    }

    const res = await apiPost("add", payload, file);

    isLoading = false;
    addBtn.disabled = false;
    addBtn.textContent = "เพิ่มเมนู";

    if (!res.ok) {
      setMsg(res.error || "เพิ่มไม่สำเร็จ", "#ef4444");
      return;
    }

    setMsg("เพิ่มเมนูแล้ว ✅", "#16a34a");

    mName.value = "";
    mImageFile.value = "";
    renderPreview(null);
    mPrice.value = "";
    mPriceMin.value = "";
    mPriceMax.value = "";

    render();
  });

  menuBody.addEventListener("click", async (e) => {
    const btn = e.target.closest("button");
    if (!btn) return;

    const act = btn.dataset.act;
    const id = btn.dataset.id;

    btn.disabled = true;

    if (act === "del") {
      if (!confirm("ลบเมนูนี้?")) {
        btn.disabled = false;
        return;
      }

      const res = await apiPost("delete", { item_id:id });
      if (!res.ok) {
        setMsg(res.error, "#ef4444");
        btn.disabled = false;
        return;
      }

      render();
    }

    if (act === "edit") {
      location.href = `owner-menu-edit.php?shop_id=${shopId}&item_id=${id}`;
    }
  });

  render();
</script>
<script>
  window.OWNER_NOTIFY_SHOP_ID = <?= (int)$shop_id ?>;
</script>
<script src="owner-notify.js"></script>
</body>
</html>