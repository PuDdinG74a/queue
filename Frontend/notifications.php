<?php
require_once __DIR__ . "/../config.php";
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>คิวที่ติดตามไว้</title>
  <link rel="stylesheet" href="./style.css?v=11">
  <style>
    :root{
      --yellow:#FFCD22;
      --gray:#7F7F7F;
      --bg:#f3f3f3;
      --line:#e5e5e5;
      --card:#fff;
      --shadow:0 8px 24px rgba(0,0,0,.08);
      --radius:16px;
      --danger:#ef4444;
      --dark:#111827;
    }
    *{ box-sizing:border-box; }
    body{
      margin:0;
      font-family:'Prompt',system-ui,sans-serif;
      background:linear-gradient(180deg,#fffdf7 0%,#f3f3f3 220px);
      color:#333;
    }
    a{ text-decoration:none; color:inherit; }

    .topbar{
      background:#fff;
      border-bottom:1px solid var(--line);
      position:sticky;
      top:0;
      z-index:50;
    }
    .topbar-inner{
      max-width:920px;
      margin:0 auto;
      padding:12px 18px;
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:12px;
      flex-wrap:wrap;
    }
    .topbar-title{
      display:flex;
      align-items:center;
      gap:10px;
    }
    .topbar-dot{
      width:18px;
      height:18px;
      border-radius:50%;
      background:var(--yellow);
      box-shadow:0 0 0 4px rgba(255,205,34,.25);
      flex:0 0 auto;
    }
    .topbar-title h1{
      margin:0;
      font-size:20px;
    }
    .topbar-title p{
      margin:2px 0 0;
      font-size:13px;
      color:var(--gray);
    }

    .nav-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:10px 14px;
      border-radius:999px;
      background:#fff;
      border:1px solid #ddd;
      font-size:14px;
      font-weight:700;
    }

    .page{
      max-width:920px;
      margin:20px auto 34px;
      padding:0 18px;
    }

    .card{
      background:var(--card);
      border:1px solid var(--line);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:16px;
      margin-bottom:14px;
    }

    .hero h2{
      margin:0 0 6px;
      font-size:24px;
    }
    .hero p{
      margin:0;
      color:#555;
      line-height:1.7;
    }

    .tools{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      align-items:center;
      margin-top:14px;
    }

    .btn{
      border:0;
      padding:11px 14px;
      border-radius:999px;
      font-weight:700;
      cursor:pointer;
      font-family:inherit;
      font-size:14px;
      transition:.18s ease;
    }
    .btn:hover{ transform:translateY(-1px); }
    .btn:disabled{
      opacity:.65;
      cursor:not-allowed;
      transform:none;
    }
    .btn-primary{
      background:linear-gradient(135deg,var(--yellow),#ffd84d);
      color:#333;
      border:1px solid rgba(0,0,0,.04);
    }
    .btn-light{
      background:#ececec;
      color:#111;
      border:1px solid #ddd;
    }
    .btn-dark{
      background:#111;
      color:#fff;
    }
    .btn-danger{
      background:var(--danger);
      color:#fff;
    }

    .last-updated{
      font-size:12px;
      color:#666;
      margin-left:auto;
    }

    .loading-inline{
      display:none;
      width:100%;
      font-size:12px;
      color:#666;
    }

    .error-banner{
      display:none;
      margin-bottom:14px;
      padding:12px 14px;
      border-radius:14px;
      background:#fff1f2;
      color:#9f1239;
      border:1px solid #fecdd3;
      font-weight:700;
    }

    .notify-list{
      display:grid;
      gap:12px;
    }

    .notify-item{
      background:#fff;
      border:1px solid #e5e5e5;
      border-radius:16px;
      box-shadow:var(--shadow);
      padding:16px;
    }

    .notify-item.urgent{
      border:2px solid var(--yellow);
    }

    .notify-top{
      display:flex;
      justify-content:space-between;
      align-items:flex-start;
      gap:12px;
      flex-wrap:wrap;
    }
    .notify-top h3{
      margin:0;
      font-size:18px;
    }

    .queue-no{
      font-size:34px;
      font-weight:900;
      line-height:1;
      color:#222;
    }

    .chips{
      display:flex;
      flex-wrap:wrap;
      gap:6px;
      margin-top:10px;
    }
    .chip{
      display:inline-block;
      padding:5px 10px;
      border-radius:999px;
      font-size:12px;
      font-weight:700;
      background:#f1f1f1;
      color:#555;
    }

    .b-wait{ background:#eef1ff; color:#2d3a8c; }
    .b-doing{ background:#fff4df; color:#8a4b00; }
    .b-done{ background:#e8fff0; color:#0f7a36; }
    .b-received{ background:#e6fff8; color:#00695c; }
    .b-cancel{ background:#f1f1f1; color:#666; }

    .notify-note{
      margin-top:12px;
      color:#444;
      font-size:14px;
      line-height:1.7;
      font-weight:600;
    }

    .notify-sub{
      margin-top:6px;
      color:#666;
      font-size:13px;
      line-height:1.6;
    }

    .actions{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      margin-top:14px;
    }

    .empty{
      text-align:center;
      color:#666;
      padding:32px 14px;
      border:1px dashed #cfcfcf;
      border-radius:14px;
      background:#fcfcfc;
      line-height:1.8;
    }

    @media (max-width: 760px){
      .page{ padding:0 12px; }
      .topbar-inner{ padding:12px; }
      .queue-no{ font-size:28px; }
      .actions .btn,
      .tools .btn{ width:100%; }
      .last-updated{
        width:100%;
        margin-left:0;
      }
    }
  </style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="topbar-title">
      <span class="topbar-dot"></span>
      <div>
        <h1>คิวที่ติดตามไว้</h1>
        <p>ดูรายการคิวและสถานะออเดอร์ที่บันทึกไว้ในเครื่องนี้</p>
      </div>
    </div>

    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <a href="index.php" class="nav-btn">🏠 หน้าหลัก</a>
    </div>
  </div>
</header>

<main class="page">

  <div class="error-banner" id="errorBanner">
    ไม่สามารถอัปเดตข้อมูลล่าสุดได้ในขณะนี้ ระบบจะลองใหม่อัตโนมัติ
  </div>

  <section class="card hero">
    <h2>รายการคิวของฉัน</h2>
    <p>
      หน้านี้ใช้สำหรับดูหลายคิวแบบรวดเร็ว<br>
      หากต้องการติดตามสถานะคิวและออเดอร์แบบละเอียด ให้กดปุ่ม <strong>ดูคิวนี้</strong>
    </p>

    <div class="tools">
      <button class="btn btn-light" onclick="loadNotifications(true)">รีเฟรชรายการ</button>
      <button class="btn btn-dark" onclick="clearFinishedQueues()">ล้างคิวที่จบแล้ว</button>
      <div class="last-updated" id="lastUpdated">อัปเดตล่าสุด: -</div>
      <div class="loading-inline" id="loadingInline">กำลังอัปเดตข้อมูลล่าสุด...</div>
    </div>
  </section>

  <div id="notifyList">
    <div class="card">กำลังโหลดรายการคิว...</div>
  </div>

</main>

<script>
const REFRESH_MS = 5000;

function getQueueIds(){
  try{
    const raw = localStorage.getItem("my_queue_ids");
    const arr = raw ? JSON.parse(raw) : [];
    if(!Array.isArray(arr)) return [];
    return [...new Set(arr.map(v => parseInt(v,10)).filter(v => Number.isInteger(v) && v > 0))];
  }catch(e){
    return [];
  }
}

function saveQueueIds(arr){
  const clean = [...new Set(arr.map(v => parseInt(v,10)).filter(v => Number.isInteger(v) && v > 0))];
  localStorage.setItem("my_queue_ids", JSON.stringify(clean));
}

function removeQueueId(id){
  id = parseInt(id, 10);
  const arr = getQueueIds().filter(v => v !== id);
  saveQueueIds(arr);
  localStorage.removeItem("prev_status_" + id);
  localStorage.removeItem("near_alert_shown_" + id);
  localStorage.removeItem("now_alert_shown_" + id);
}

function setLastUpdated(){
  const now = new Date();
  const hh = String(now.getHours()).padStart(2, "0");
  const mm = String(now.getMinutes()).padStart(2, "0");
  const ss = String(now.getSeconds()).padStart(2, "0");
  document.getElementById("lastUpdated").textContent = `อัปเดตล่าสุด: ${hh}:${mm}:${ss}`;
}

function setLoadingInline(show){
  const el = document.getElementById("loadingInline");
  if(el) el.style.display = show ? "block" : "none";
}

function showErrorBanner(show){
  const el = document.getElementById("errorBanner");
  if(!el) return;
  el.style.display = show ? "block" : "none";
}

function escapeHtml(text){
  const div = document.createElement("div");
  div.textContent = text ?? "";
  return div.innerHTML;
}

function statusTH(st){
  st = (st || "waiting").trim();
  if(st === "waiting") return "รอเรียก";
  if(st === "calling") return "กำลังเรียก";
  if(st === "served") return "ออเดอร์พร้อมรับ";
  if(st === "received") return "รับออเดอร์แล้ว";
  if(st === "cancel") return "ยกเลิกแล้ว";
  return "รอเรียก";
}

function badgeClass(st){
  st = (st || "waiting").trim();
  if(st === "waiting") return "b-wait";
  if(st === "calling") return "b-doing";
  if(st === "served") return "b-done";
  if(st === "received") return "b-received";
  if(st === "cancel") return "b-cancel";
  return "b-wait";
}

function noteText(data){
  if(data.status === "calling"){
    return "ถึงคิวของคุณแล้ว กรุณาไปที่ร้าน";
  }
  if(data.status === "served"){
    return "ออเดอร์ของคุณพร้อมรับแล้ว";
  }
  if(data.status === "received"){
    return "คุณรับออเดอร์เรียบร้อยแล้ว";
  }
  if(data.status === "cancel"){
    return "คิวนี้ถูกยกเลิกแล้ว";
  }

  if(data.before_me <= 2){
    return `คิวใกล้ถึงแล้ว เหลือประมาณ ${data.before_me} คิว`;
  }

  return `เหลือประมาณ ${data.before_me} คิว • รอประมาณ ${data.eta_minutes} นาที`;
}

function subText(data){
  if(data.status === "waiting" || data.status === "calling"){
    return "กดดูคิวนี้เพื่อดูสถานะละเอียดและติดตามแบบอัตโนมัติ";
  }
  if(data.status === "served"){
    return "กดดูคิวนี้เพื่อยืนยันการรับออเดอร์";
  }
  return "คุณยังสามารถเปิดดูรายละเอียดของคิวนี้ได้";
}

function normalizeData(raw){
  return {
    queue_id: Number(raw.queue_id || 0),
    shop_id: Number(raw.shop_id || 0),
    shop_name: raw.shop_name || "ร้านค้า",
    dome_id: Number(raw.dome_id || 0),
    dome_name: raw.dome_name || "",
    lock_no: Number(raw.lock_no || 0),
    queue_no: Number(raw.queue_no || 0),
    status: (raw.status || "waiting").trim(),
    before_me: Number(raw.before_me || 0),
    eta_minutes: Number(raw.eta_minutes || 0)
  };
}

async function fetchQueueData(queueId){
  const res = await fetch(`api_my_queue.php?queue_id=${queueId}&_=${Date.now()}`, { cache:"no-store" });
  const data = await res.json();
  if(!data.ok) return null;
  return normalizeData(data);
}

function goShop(shopId){
  location.href = `shop.php?shop_id=${shopId}`;
}

function forgetQueue(queueId){
  if(!confirm("ต้องการซ่อนคิวนี้ออกจากเครื่องนี้ใช่ไหม?\nระบบจะซ่อนเฉพาะในเครื่องนี้เท่านั้น")) return;
  removeQueueId(queueId);
  loadNotifications(true);
}

async function clearFinishedQueues(){
  const ids = getQueueIds();
  if(ids.length === 0){
    alert("ยังไม่มีคิวในรายการ");
    return;
  }

  if(!confirm("ต้องการล้างคิวที่จบแล้วทั้งหมดใช่ไหม?\nระบบจะลบเฉพาะคิวที่รับออเดอร์แล้วหรือยกเลิกแล้วออกจากเครื่องนี้")) return;

  try{
    const results = await Promise.all(ids.map(id => fetchQueueData(id)));
    const items = results.filter(Boolean);

    const remainIds = items
      .filter(x => ["waiting","calling","served"].includes(x.status))
      .map(x => x.queue_id);

    saveQueueIds(remainIds);

    items.forEach(x => {
      if(!["waiting","calling","served"].includes(x.status)){
        localStorage.removeItem("prev_status_" + x.queue_id);
        localStorage.removeItem("near_alert_shown_" + x.queue_id);
        localStorage.removeItem("now_alert_shown_" + x.queue_id);
      }
    });

    loadNotifications(true);
  }catch(e){
    alert("ล้างรายการไม่สำเร็จ");
  }
}

function render(items){
  const box = document.getElementById("notifyList");

  if(!items.length){
    box.innerHTML = `
      <div class="empty">
        ยังไม่มีคิวที่ถูกบันทึกไว้ในเครื่องนี้<br>
        เมื่อคุณกดรับคิว ระบบจะแสดงรายการคิวที่นี่
      </div>
    `;
    return;
  }

  box.innerHTML = `
    <div class="notify-list">
      ${items.map(data => {
        const urgent = (data.status === "calling" || (data.status === "waiting" && data.before_me <= 2)) ? "urgent" : "";
        return `
          <div class="notify-item ${urgent}">
            <div class="notify-top">
              <div>
                <h3>${escapeHtml(data.shop_name)}</h3>
                <div class="chips">
                  <span class="chip">โดม ${escapeHtml(data.dome_name || data.dome_id || "-")}</span>
                  <span class="chip">ล็อก ${data.lock_no > 0 ? data.lock_no : "-"}</span>
                  <span class="chip ${badgeClass(data.status)}">${statusTH(data.status)}</span>
                </div>
              </div>
              <div class="queue-no">#${data.queue_no}</div>
            </div>

            <div class="notify-note">${escapeHtml(noteText(data))}</div>
            <div class="notify-sub">${escapeHtml(subText(data))}</div>

            <div class="actions">
              <a class="btn btn-primary" href="my-queue.php?queue_id=${data.queue_id}">ดูคิวนี้</a>
              <button class="btn btn-light" onclick="goShop(${data.shop_id})">ไปหน้าร้าน</button>
              <button class="btn btn-danger" onclick="forgetQueue(${data.queue_id})">ซ่อนจากเครื่องนี้</button>
            </div>
          </div>
        `;
      }).join("")}
    </div>
  `;
}

let isLoading = false;

async function loadNotifications(manual=false){
  if(isLoading) return;

  try{
    isLoading = true;
    setLoadingInline(true);

    const ids = getQueueIds();

    if(!ids.length){
      render([]);
      setLastUpdated();
      showErrorBanner(false);
      return;
    }

    const results = await Promise.all(ids.map(id => fetchQueueData(id)));
    let items = results.filter(Boolean);

    const validIds = items.map(x => x.queue_id);
    saveQueueIds(validIds);

    items.sort((a, b) => {
      const rank = s => {
        if(s === "calling") return 1;
        if(s === "waiting") return 2;
        if(s === "served") return 3;
        if(s === "received") return 4;
        if(s === "cancel") return 5;
        return 6;
      };

      const diff = rank(a.status) - rank(b.status);
      if(diff !== 0) return diff;

      if(a.status === "waiting" && b.status === "waiting"){
        return a.before_me - b.before_me;
      }

      return a.queue_id - b.queue_id;
    });

    render(items);
    setLastUpdated();
    showErrorBanner(false);
  }catch(e){
    if(manual){
      alert("โหลดรายการคิวไม่สำเร็จ");
    }
    showErrorBanner(true);
  }finally{
    isLoading = false;
    setLoadingInline(false);
  }
}

loadNotifications();
setInterval(loadNotifications, REFRESH_MS);
</script>

</body>
</html>