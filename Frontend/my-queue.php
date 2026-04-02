<?php
require_once __DIR__ . "/../config.php";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

$queue_id = isset($_GET["queue_id"]) ? (int)$_GET["queue_id"] : 0;

// ถ้าไม่ได้ส่ง queue_id มา ให้กลับไปหน้าคิวที่ติดตามไว้
if ($queue_id <= 0) {
  header("Location: notifications.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>คิวของฉัน</title>
  <link rel="stylesheet" href="./style.css?v=13">
  <link rel="manifest" href="<?= h(APP_BASE) ?>/manifest.json">
  <meta name="theme-color" content="#FFCD22">
  <style>
    :root{
      --yellow:#FFCD22;
      --gray:#7F7F7F;
      --line:#e5e5e5;
      --card:#fff;
      --shadow:0 8px 24px rgba(0,0,0,.08);
      --radius:16px;
      --danger:#ef4444;
      --success:#10b981;
      --dark:#111827;
      --soft:#f8f8f8;
      --pink:#fff1f2;
      --pink-border:#fecdd3;
      --pink-text:#9f1239;
    }

    *{ box-sizing:border-box; }

    body{
      font-family:'Prompt',system-ui,sans-serif;
      margin:0;
      background:linear-gradient(180deg,#fffdf7 0%,#f3f3f3 220px);
      color:#333;
    }

    .topbar{
      background:#fff;
      border-bottom:1px solid var(--line);
      position:sticky;
      top:0;
      z-index:50;
    }
    .topbar-inner{
      max-width:860px;
      margin:0 auto;
      padding:12px 18px;
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:12px;
      flex-wrap:wrap;
    }

    .brand{
      display:flex;
      align-items:center;
      gap:10px;
    }
    .dot{
      width:18px;
      height:18px;
      border-radius:50%;
      background:var(--yellow);
      box-shadow:0 0 0 4px rgba(255,205,34,.25);
      flex:0 0 auto;
    }
    .brand h1{
      margin:0;
      font-size:20px;
    }
    .brand p{
      margin:2px 0 0;
      font-size:13px;
      color:var(--gray);
    }

    .nav{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
    }
    .nav a{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:10px 14px;
      border-radius:999px;
      font-size:14px;
      font-weight:700;
      background:#fff;
      border:1px solid #ddd;
      color:#333;
      text-decoration:none;
    }

    .container{
      max-width:860px;
      margin:20px auto 34px;
      padding:0 18px;
    }

    .card,
    .queue-card{
      background:var(--card);
      border:1px solid var(--line);
      border-radius:var(--radius);
      padding:16px;
      box-shadow:var(--shadow);
      margin-bottom:14px;
    }

    .queue-card.near{
      border:2px solid var(--yellow);
      animation:pulse 1.2s infinite;
    }

    @keyframes pulse{
      0%{ box-shadow:0 0 0 0 rgba(255,205,34,.55), 0 8px 24px rgba(0,0,0,.08); }
      70%{ box-shadow:0 0 0 12px rgba(255,205,34,0), 0 8px 24px rgba(0,0,0,.08); }
      100%{ box-shadow:0 0 0 0 rgba(255,205,34,0), 0 8px 24px rgba(0,0,0,.08); }
    }

    .section-title{
      margin:0 0 6px;
      font-size:20px;
    }

    .muted{ color:#666; }
    .small{ font-size:13px; color:#666; }
    .tiny{ font-size:12px; color:#777; }

    .big{
      font-size:50px;
      font-weight:900;
      margin:6px 0;
      line-height:1;
      color:#222;
    }

    .badge{
      display:inline-block;
      padding:6px 10px;
      border-radius:999px;
      font-size:14px;
      font-weight:800;
    }
    .b-wait{ background:#eef1ff; color:#2d3a8c; }
    .b-doing{ background:#fff4df; color:#8a4b00; }
    .b-done{ background:#e8fff0; color:#0f7a36; }
    .b-received{ background:#e6fff8; color:#00695c; }
    .b-cancel{ background:#f1f1f1; color:#666; }

    .chips{
      display:flex;
      flex-wrap:wrap;
      gap:6px;
      margin-top:8px;
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

    .status-guide{
      margin-top:12px;
      padding:12px 14px;
      background:#fff8de;
      border:1px solid #f2df9b;
      border-radius:14px;
      color:#5f4b00;
      font-size:14px;
      line-height:1.6;
      font-weight:600;
    }

    .btn{
      border:0;
      padding:12px 14px;
      border-radius:999px;
      font-weight:700;
      cursor:pointer;
      font-family:inherit;
      font-size:14px;
      transition:.18s ease;
    }
    .btn:hover{ transform:translateY(-1px); }
    .btn:disabled{
      opacity:.6;
      cursor:not-allowed;
      transform:none;
    }

    .btn-primary{
      background:linear-gradient(135deg,var(--yellow),#ffd84d);
      color:#333;
      border:1px solid rgba(0,0,0,.04);
    }
    .btn-secondary{
      background:#eaeaea;
      color:#111;
      border:1px solid #ddd;
    }
    .btn-danger{
      background:var(--danger);
      color:#fff;
    }
    .btn-dark{
      background:#111;
      color:#fff;
    }
    .btn-green{
      background:var(--success);
      color:#fff;
    }
    .btn-outline{
      background:#fff;
      color:#333;
      border:1px solid #ddd;
    }

    .status-grid{
      display:grid;
      grid-template-columns:repeat(2, minmax(0,1fr));
      gap:10px;
      margin-top:12px;
    }
    .status-box{
      background:#fafafa;
      border:1px solid #ececec;
      border-radius:14px;
      padding:12px;
    }
    .status-box .label{
      font-size:12px;
      color:#777;
      margin-bottom:6px;
    }
    .status-box .value{
      font-size:15px;
      font-weight:900;
      color:#222;
    }

    .metrics{
      display:grid;
      grid-template-columns:repeat(3, minmax(0,1fr));
      gap:10px;
      margin-top:14px;
    }
    .metric{
      background:#fafafa;
      border:1px solid #ececec;
      border-radius:14px;
      padding:12px;
    }
    .metric .label{
      font-size:13px;
      color:#666;
      margin-bottom:6px;
    }
    .metric .value{
      font-size:22px;
      font-weight:900;
      color:#222;
      line-height:1.2;
      word-break:break-word;
    }
    .metric .sub{
      font-size:12px;
      color:#777;
      margin-top:4px;
      line-height:1.5;
    }

    .detail-grid{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:12px;
      margin-top:12px;
    }

    .box{
      margin-top:6px;
      padding:12px;
      background:#f7f7f7;
      border-radius:12px;
      border:1px solid #ececec;
      line-height:1.7;
      word-break:break-word;
      white-space:pre-wrap;
    }

    .progress-wrap{
      margin-top:14px;
    }
    .progress-head{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:12px;
      margin-bottom:8px;
      flex-wrap:wrap;
    }
    .progress-track{
      width:100%;
      height:10px;
      background:#ececec;
      border-radius:999px;
      overflow:hidden;
    }
    .progress-bar{
      height:100%;
      width:0%;
      background:linear-gradient(90deg,var(--yellow),#ffd84d);
      border-radius:999px;
      transition:width .35s ease;
    }

    .info-bar{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
      margin-top:12px;
      padding:10px 12px;
      border-radius:12px;
      background:#f8f8f8;
      border:1px solid #ececec;
    }

    .actions-primary,
    .actions-secondary,
    .actions-danger{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      margin-top:12px;
    }

    .error-banner{
      display:none;
      margin-bottom:14px;
      padding:12px 14px;
      border-radius:14px;
      background:var(--pink);
      color:var(--pink-text);
      border:1px solid var(--pink-border);
      font-weight:700;
    }

    .loading-inline{
      display:none;
      font-size:13px;
      color:#666;
    }

    .empty-card{
      text-align:center;
      padding:26px 18px;
    }
    .empty-card h3{
      margin:0 0 8px;
      font-size:22px;
    }
    .empty-card p{
      margin:0;
      color:#666;
      line-height:1.7;
    }

    .overlay{
      position:fixed;
      inset:0;
      background:rgba(0,0,0,.50);
      display:none;
      align-items:center;
      justify-content:center;
      padding:16px;
      z-index:9999;
      backdrop-filter: blur(2px);
    }
    .popup{
      background:#fff;
      border-radius:20px;
      padding:22px 20px;
      max-width:420px;
      width:100%;
      border:3px solid var(--yellow);
      box-shadow:0 18px 40px rgba(0,0,0,.25);
      text-align:center;
      animation:popIn .35s ease;
    }
    .popup h3{
      margin:0 0 10px;
      font-size:24px;
      line-height:1.3;
    }
    .popup p{
      margin:0 0 14px;
      color:#555;
      line-height:1.7;
      font-size:17px;
    }

    @keyframes popIn{
      from{
        transform:scale(.82);
        opacity:0;
      }
      to{
        transform:scale(1);
        opacity:1;
      }
    }

    @media (max-width: 700px){
      .metrics{
        grid-template-columns:1fr;
      }
      .detail-grid{
        grid-template-columns:1fr;
      }
      .status-grid{
        grid-template-columns:1fr;
      }
    }

    @media (max-width: 600px){
      .container{ padding:0 12px; }
      .topbar-inner{ padding:12px; }
      .big{ font-size:40px; }
      .actions-primary .btn,
      .actions-secondary .btn,
      .actions-danger .btn{
        width:100%;
      }
      .popup h3{ font-size:22px; }
      .popup p{ font-size:16px; }
      .info-bar{
        align-items:flex-start;
        flex-direction:column;
      }
    }
  </style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <span class="dot"></span>
      <div>
        <h1>คิวของฉัน</h1>
        <p>ติดตามสถานะคิวและออเดอร์ของรายการนี้</p>
      </div>
    </div>

    <div class="nav">
      <a href="index.php">🏠 หน้าหลัก</a>
      <a href="notifications.php">📋 คิวที่ติดตามไว้</a>
    </div>
  </div>
</header>

<div class="container">

  <div class="card">
    <h2 class="section-title">ติดตามสถานะคิว</h2>
    <div class="muted">
      หากต้องการติดตามคิวแบบทันที แนะนำให้เปิดหน้านี้ไว้ และเปิดเสียงหรือแจ้งเตือนบนอุปกรณ์
    </div>

    <div class="status-grid">
      <div class="status-box">
        <div class="label">เสียงแจ้งเตือน</div>
        <div class="value" id="soundStatusText">ยังไม่ได้เปิด</div>
      </div>
      <div class="status-box">
        <div class="label">Push Notification</div>
        <div class="value" id="browserNotifyStatusText">ยังไม่ได้เปิด</div>
      </div>
    </div>

    <div class="actions-secondary">
      <button class="btn btn-primary" id="enableSoundBtn">🔔 เปิดเสียงแจ้งเตือน</button>
      <button class="btn btn-outline" id="enableBrowserNotifyBtn">📱 เปิด Push Notification</button>
    </div>

    <div class="small" style="margin-top:8px;">
      หากเปิด Push แล้ว ระบบจะแจ้งเตือนให้แม้ไม่ได้อยู่ในหน้านี้ตลอดเวลา
    </div>
  </div>

  <div class="error-banner" id="errorBanner">
    ไม่สามารถอัปเดตข้อมูลล่าสุดได้ในขณะนี้ ระบบจะลองใหม่อัตโนมัติ
  </div>

  <div id="queueBox">
    <div class="card">กำลังโหลดข้อมูลคิว...</div>
  </div>

</div>

<div class="overlay" id="overlayNear">
  <div class="popup">
    <h3>คิวใกล้ถึงแล้ว!</h3>
    <p id="overlayNearText">เตรียมตัวไปรอหน้าร้านได้เลย</p>
    <button class="btn btn-primary" onclick="closeNearPopup()">รับทราบ</button>
  </div>
</div>

<div class="overlay" id="overlayNow">
  <div class="popup">
    <h3>ถึงคิวแล้ว! 🎉</h3>
    <p id="overlayNowText">ตอนนี้ร้านกำลังเรียกคิวของคุณ</p>
    <button class="btn btn-primary" onclick="closeNowPopup()">รับทราบ</button>
  </div>
</div>

<div class="overlay" id="overlayStatus">
  <div class="popup">
    <h3 id="statusPopupTitle">อัปเดตสถานะ</h3>
    <p id="statusPopupBody">สถานะมีการเปลี่ยนแปลง</p>
    <button class="btn btn-primary" onclick="closeStatusPopup()">รับทราบ</button>
  </div>
</div>

<script>
const incomingQueueId = <?php echo (int)$queue_id; ?>;
const APP_BASE = <?= json_encode(APP_BASE, JSON_UNESCAPED_UNICODE) ?>;
const VAPID_PUBLIC_KEY = <?= json_encode(VAPID_PUBLIC_KEY, JSON_UNESCAPED_UNICODE) ?>;
const REFRESH_MS = 4000;

// =========================
// customer token
// =========================
function getOrCreateCustomerToken(){
  let token = "";

  try{
    token = (localStorage.getItem("customer_token") || "").trim();
  }catch(e){
    token = "";
  }

  if(!token){
    token = "cust_" + Date.now() + "_" + Math.random().toString(36).slice(2, 12);
    try{
      localStorage.setItem("customer_token", token);
    }catch(e){
      console.warn("ไม่สามารถบันทึก customer_token ลง localStorage ได้", e);
    }
  }

  return token;
}

function ensureCustomerToken(){
  let token = "";

  try{
    token = (localStorage.getItem("customer_token") || "").trim();
  }catch(e){
    token = "";
  }

  if(!token){
    token = getOrCreateCustomerToken();
  }

  return token;
}

const CUSTOMER_TOKEN = ensureCustomerToken();

function goNotifications(){ location.href = "notifications.php"; }
function goShop(shopId){ location.href = `shop.php?shop_id=${shopId}`; }

function getQueueIds(){
  try{
    const raw = localStorage.getItem("my_queue_ids");
    const arr = raw ? JSON.parse(raw) : [];
    if(!Array.isArray(arr)) return [];
    return arr.map(v => parseInt(v, 10)).filter(v => Number.isInteger(v) && v > 0);
  }catch(e){
    return [];
  }
}

function saveQueueIds(arr){
  const clean = [...new Set(arr.map(v => parseInt(v,10)).filter(v => Number.isInteger(v) && v > 0))];
  localStorage.setItem("my_queue_ids", JSON.stringify(clean));
}

function addQueueId(id){
  id = parseInt(id,10);
  if(!Number.isInteger(id) || id <= 0) return;
  const arr = getQueueIds();
  if(!arr.includes(id)){
    arr.unshift(id);
    saveQueueIds(arr);
  }
}

function removeQueueId(id){
  id = parseInt(id, 10);
  const arr = getQueueIds().filter(v => v !== id);
  saveQueueIds(arr);
  localStorage.removeItem("prev_status_" + id);
  localStorage.removeItem("near_alert_shown_" + id);
  localStorage.removeItem("now_alert_shown_" + id);
}

if(incomingQueueId > 0){ addQueueId(incomingQueueId); }

function openNearPopup(text){
  document.getElementById("overlayNearText").textContent = text || "เตรียมตัวไปรอหน้าร้านได้เลย";
  document.getElementById("overlayNear").style.display = "flex";
  window.scrollTo({ top:0, behavior:"smooth" });
}

function closeNearPopup(){ document.getElementById("overlayNear").style.display = "none"; }

function openNowPopup(text){
  document.getElementById("overlayNowText").textContent = text || "ตอนนี้ร้านกำลังเรียกคิวของคุณ";
  document.getElementById("overlayNow").style.display = "flex";
  window.scrollTo({ top:0, behavior:"smooth" });
}

function closeNowPopup(){ document.getElementById("overlayNow").style.display = "none"; }

function openStatusPopup(title, body){
  document.getElementById("statusPopupTitle").textContent = title || "อัปเดตสถานะ";
  document.getElementById("statusPopupBody").textContent = body || "";
  document.getElementById("overlayStatus").style.display = "flex";
  window.scrollTo({ top:0, behavior:"smooth" });
}

function closeStatusPopup(){ document.getElementById("overlayStatus").style.display = "none"; }

let audioEnabled = localStorage.getItem("audio_enabled") === "1";
let hasActivePushSubscription = false;

const enableBtn = document.getElementById("enableSoundBtn");
const enableBrowserBtn = document.getElementById("enableBrowserNotifyBtn");
const soundStatusText = document.getElementById("soundStatusText");
const browserNotifyStatusText = document.getElementById("browserNotifyStatusText");

function updateNotifyStatusUI(){
  if(soundStatusText){
    soundStatusText.textContent = audioEnabled ? "เปิดแล้ว" : "ยังไม่ได้เปิด";
  }

  if(enableBtn){
    if(audioEnabled){
      enableBtn.textContent = "✅ เปิดเสียงแล้ว";
      enableBtn.disabled = true;
    }else{
      enableBtn.textContent = "🔔 เปิดเสียงแจ้งเตือน";
      enableBtn.disabled = false;
    }
  }

  if(!("Notification" in window) || !("serviceWorker" in navigator) || !("PushManager" in window)){
    if(browserNotifyStatusText) browserNotifyStatusText.textContent = "อุปกรณ์ไม่รองรับ";
    if(enableBrowserBtn){
      enableBrowserBtn.textContent = "📱 อุปกรณ์ไม่รองรับ";
      enableBrowserBtn.disabled = true;
    }
    return;
  }

  if(Notification.permission === "denied"){
    if(browserNotifyStatusText) browserNotifyStatusText.textContent = "ถูกบล็อกอยู่";
    if(enableBrowserBtn){
      enableBrowserBtn.textContent = "❌ การแจ้งเตือนถูกบล็อก";
      enableBrowserBtn.disabled = true;
    }
    return;
  }

  if(hasActivePushSubscription){
    if(browserNotifyStatusText) browserNotifyStatusText.textContent = "เปิด Push แล้ว";
    if(enableBrowserBtn){
      enableBrowserBtn.textContent = "✅ เปิด Push แล้ว";
      enableBrowserBtn.disabled = true;
    }
    return;
  }

  if(Notification.permission === "granted"){
    if(browserNotifyStatusText) browserNotifyStatusText.textContent = "อนุญาตแล้ว แต่ยังไม่ได้สมัคร Push";
    if(enableBrowserBtn){
      enableBrowserBtn.textContent = "📱 เปิด Push Notification";
      enableBrowserBtn.disabled = false;
    }
  }else{
    if(browserNotifyStatusText) browserNotifyStatusText.textContent = "ยังไม่ได้อนุญาต";
    if(enableBrowserBtn){
      enableBrowserBtn.textContent = "📱 เปิด Push Notification";
      enableBrowserBtn.disabled = false;
    }
  }
}

function beep(times = 4, gapMs = 120, freq = 900){
  if(!audioEnabled) return;
  const AudioCtx = window.AudioContext || window.webkitAudioContext;
  if(!AudioCtx) return;

  const ctx = new AudioCtx();
  let t = ctx.currentTime;

  for(let i=0;i<times;i++){
    const o = ctx.createOscillator();
    const g = ctx.createGain();

    o.type = "sine";
    o.frequency.value = freq;

    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(0.16, t + 0.02);
    g.gain.exponentialRampToValueAtTime(0.0001, t + 0.14);

    o.connect(g);
    g.connect(ctx.destination);

    o.start(t);
    o.stop(t + 0.14);

    t += (gapMs / 1000);
  }

  setTimeout(() => { try{ ctx.close(); }catch(e){} }, 1200);
}

if(enableBtn){
  enableBtn.addEventListener("click", async () => {
    try{
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if(AudioCtx){
        const ctx = new AudioCtx();
        await ctx.resume();
        ctx.close();
      }
      audioEnabled = true;
      localStorage.setItem("audio_enabled","1");
      beep(1, 120, 900);
      updateNotifyStatusUI();
    }catch(e){
      alert("เปิดเสียงไม่สำเร็จ กรุณาลองกดใหม่อีกครั้ง");
    }
  });
}

function urlBase64ToUint8Array(base64String) {
  const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);

  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}

async function registerServiceWorker() {
  if (!("serviceWorker" in navigator)) {
    throw new Error("เบราว์เซอร์ไม่รองรับ service worker");
  }

  const swUrl = `${APP_BASE}/service-worker.js`;
  return navigator.serviceWorker.register(swUrl, {
    scope: `${APP_BASE}/`
  });
}

async function getPushSubscription() {
  const reg = await navigator.serviceWorker.ready;
  return reg.pushManager.getSubscription();
}

// =========================
// push notification
// =========================
async function bindExistingSubscriptionToCurrentQueue(queueId) {
  if (!queueId) return false;
  if (!("serviceWorker" in navigator) || !("PushManager" in window)) return false;

  const currentCustomerToken = ensureCustomerToken();
  if (!currentCustomerToken) return false;

  await registerServiceWorker();

  const reg = await navigator.serviceWorker.ready;
  const subscription = await reg.pushManager.getSubscription();

  if (!subscription) {
    return false;
  }

  const res = await fetch(`${APP_BASE}/Frontend/subscribe_push.php`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify({
      queue_id: queueId,
      customer_token: currentCustomerToken,
      page_url: `${APP_BASE}/Frontend/my-queue.php?queue_id=${queueId}`,
      subscription: subscription.toJSON()
    })
  });

  let data = {};
  try{
    data = await res.json();
  }catch(e){
    throw new Error("รูปแบบข้อมูลตอบกลับจากระบบไม่ถูกต้อง");
  }

  if (!res.ok || !data.ok) {
    throw new Error(data.error || "ผูก subscription กับคิวปัจจุบันไม่สำเร็จ");
  }

  hasActivePushSubscription = true;
  updateNotifyStatusUI();
  return true;
}

async function syncPushStatusUI() {
  try {
    hasActivePushSubscription = false;

    if (!("Notification" in window) || !("serviceWorker" in navigator) || !("PushManager" in window)) {
      updateNotifyStatusUI();
      return;
    }

    await registerServiceWorker();
    const subscription = await getPushSubscription();
    hasActivePushSubscription = !!subscription;
    updateNotifyStatusUI();

    const currentCustomerToken = ensureCustomerToken();

    if (subscription && incomingQueueId > 0 && currentCustomerToken) {
      await bindExistingSubscriptionToCurrentQueue(incomingQueueId);
    }
  } catch (e) {
    console.error("syncPushStatusUI error:", e);
    updateNotifyStatusUI();
  }
}

async function subscribeWebPush(queueId) {
  if (!("Notification" in window) || !("serviceWorker" in navigator) || !("PushManager" in window)) {
    alert("อุปกรณ์นี้ยังไม่รองรับ Push Notification");
    return false;
  }

  if (!VAPID_PUBLIC_KEY) {
    alert("ระบบยังไม่ได้ตั้งค่า VAPID key");
    return false;
  }

  const currentCustomerToken = ensureCustomerToken();
  if (!currentCustomerToken) {
    alert("ไม่สามารถสร้าง customer_token ได้");
    return false;
  }

  const permission = await Notification.requestPermission();
  if (permission !== "granted") {
    updateNotifyStatusUI();
    alert("ยังไม่ได้รับอนุญาตการแจ้งเตือน");
    return false;
  }

  await registerServiceWorker();

  const reg = await navigator.serviceWorker.ready;
  let subscription = await reg.pushManager.getSubscription();

  if (!subscription) {
    subscription = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
    });
  }

  const res = await fetch(`${APP_BASE}/Frontend/subscribe_push.php`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify({
      queue_id: queueId,
      customer_token: currentCustomerToken,
      page_url: `${APP_BASE}/Frontend/my-queue.php?queue_id=${queueId}`,
      subscription: subscription.toJSON()
    })
  });

  let data = {};
  try{
    data = await res.json();
  }catch(e){
    throw new Error("รูปแบบข้อมูลตอบกลับจากระบบไม่ถูกต้อง");
  }

  if (!res.ok || !data.ok) {
    throw new Error(data.error || "บันทึก push subscription ไม่สำเร็จ");
  }

  hasActivePushSubscription = true;
  updateNotifyStatusUI();
  return true;
}

async function enableBrowserNotifications(){
  try{
    if(!incomingQueueId){
      alert("ไม่พบ queue_id");
      return;
    }

    const currentCustomerToken = ensureCustomerToken();
    if(!currentCustomerToken){
      alert("ไม่สามารถสร้าง customer_token ได้");
      return;
    }

    const ok = await subscribeWebPush(incomingQueueId);
    if(ok){
      alert("เปิด Push Notification สำเร็จ");
    }
  }catch(e){
    console.error(e);
    alert(e.message || "ไม่สามารถเปิด Push Notification ได้");
  }
}

if(enableBrowserBtn){
  enableBrowserBtn.addEventListener("click", enableBrowserNotifications);
}

function pushNotify(title, body){
  if(!("Notification" in window)) return;
  if(Notification.permission !== "granted") return;

  try{
    new Notification(title, { body });
  }catch(e){
    console.error("Notification error:", e);
  }
}

function escapeHtml(text){
  const div = document.createElement("div");
  div.textContent = text ?? "";
  return div.innerHTML;
}

function formatTimeTH(dateObj){
  try{
    return dateObj.toLocaleTimeString("th-TH", {
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit"
    }) + " น.";
  }catch(e){
    return "-";
  }
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

function statusGuideText(st, beforeMe){
  st = (st || "waiting").trim();

  if(st === "waiting"){
    if(beforeMe <= 2){
      return "คิวของคุณใกล้ถึงแล้ว กรุณาเตรียมตัวไปที่ร้าน";
    }
    return "กรุณารอติดตามสถานะคิว ระบบจะอัปเดตให้อัตโนมัติ";
  }
  if(st === "calling"){
    return "ขณะนี้ร้านกำลังเรียกคิวของคุณ กรุณาไปที่ร้านทันที";
  }
  if(st === "served"){
    return "ร้านเตรียมออเดอร์เรียบร้อยแล้ว กรุณารับออเดอร์และกดยืนยันเมื่อรับแล้ว";
  }
  if(st === "received"){
    return "รายการนี้เสร็จสมบูรณ์แล้ว ขอบคุณที่ใช้บริการ";
  }
  if(st === "cancel"){
    return "คิวนี้ถูกยกเลิกแล้ว หากต้องการใช้บริการกรุณากดรับคิวใหม่";
  }

  return "กรุณาติดตามสถานะคิวของคุณ";
}

function makeEtaText(data){
  if(data.status === "served") return "ออเดอร์พร้อมรับแล้ว";
  if(data.status === "received") return "รับออเดอร์แล้ว ✅";
  if(data.status === "cancel") return "คิวนี้ถูกยกเลิกแล้ว";
  if(data.status === "calling") return "ถึงคิวของคุณแล้ว";
  return `ประมาณ ${data.eta_minutes} นาที`;
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
    current_queue: Number(raw.current_queue || 0),
    waiting_count: Number(raw.waiting_count || 0),
    before_me: Number(raw.before_me || 0),
    eta_minutes: Number(raw.eta_minutes || 0),
    note: raw.note || "ไม่ได้ระบุรายการ",
    phone: raw.phone || "ไม่ได้ระบุ",
    updated_at_text: formatTimeTH(new Date())
  };
}

function canCancel(status){ return status === "waiting" || status === "calling"; }
function canReceive(status){ return status === "calling" || status === "served"; }

function getProgress(data){
  if(data.status === "received") return 100;
  if(data.status === "served") return 100;
  if(data.status === "calling") return 100;
  if(data.status === "cancel") return 0;

  let progress = Math.max(8, 100 - (data.before_me * 18));
  if(data.before_me <= 1) progress = Math.max(progress, 85);
  if(data.before_me === 0) progress = 100;
  return Math.min(100, progress);
}

function renderEmptyState(){
  const box = document.getElementById("queueBox");
  box.innerHTML = `
    <div class="card empty-card">
      <h3>ไม่พบข้อมูลคิว</h3>
      <p>
        ยังไม่พบคิวที่ต้องการแสดง<br>
        กรุณากลับไปเลือกร้านและกดรับคิวใหม่อีกครั้ง
      </p>
      <div class="actions-secondary" style="justify-content:center; margin-top:16px;">
        <a class="btn btn-primary" href="index.php" style="text-decoration:none;">กลับไปหน้าหลัก</a>
        <a class="btn btn-outline" href="notifications.php" style="text-decoration:none;">ไปหน้าคิวที่ติดตามไว้</a>
      </div>
    </div>
  `;
}

function renderQueue(data){
  const box = document.getElementById("queueBox");
  if(!data || !data.queue_id){
    renderEmptyState();
    return;
  }

  const nearClass = (data.before_me <= 2 && !["served","received","cancel"].includes(data.status)) ? "near" : "";
  const progress = getProgress(data);
  const currentQueueText = Number(data.current_queue || 0) > 0 ? `#${Number(data.current_queue)}` : "-";

  box.innerHTML = `
    <div class="queue-card ${nearClass}">
      <h2 style="margin:0 0 8px;">${escapeHtml(data.shop_name || "ร้านค้า")}</h2>

      <div class="chips">
        <span class="chip">โดม ${escapeHtml(data.dome_name || data.dome_id || "-")}</span>
        <span class="chip">ล็อก ${Number(data.lock_no || 0) > 0 ? Number(data.lock_no) : "-"}</span>
      </div>

      <div class="muted" style="margin-top:12px;">เลขคิวของฉัน</div>
      <div class="big">#${Number(data.queue_no || 0)}</div>

      <div class="progress-wrap">
        <div class="progress-head">
          <div class="small">ความคืบหน้าของคิวโดยประมาณ</div>
          <div class="tiny">อัปเดตล่าสุด: ${escapeHtml(data.updated_at_text)}</div>
        </div>
        <div class="progress-track">
          <div class="progress-bar" style="width:${progress}%"></div>
        </div>
      </div>

      <div style="margin-top:10px;">
        <span class="badge ${badgeClass(data.status)}">${statusTH(data.status)}</span>
      </div>

      <div class="status-guide">
        ${escapeHtml(statusGuideText(data.status, data.before_me))}
      </div>

      <div class="metrics">
        <div class="metric">
          <div class="label">เหลือก่อนถึงคิวคุณ</div>
          <div class="value">${Math.max(0, Number(data.before_me || 0))}</div>
          <div class="sub">คิว</div>
        </div>

        <div class="metric">
          <div class="label">เวลารอโดยประมาณ</div>
          <div class="value">${escapeHtml(makeEtaText(data))}</div>
          <div class="sub">อาจเปลี่ยนแปลงตามการให้บริการจริง</div>
        </div>

        <div class="metric">
          <div class="label">คิวที่ร้านกำลังเรียก</div>
          <div class="value">${escapeHtml(currentQueueText)}</div>
          <div class="sub">ข้อมูลล่าสุดของร้าน</div>
        </div>
      </div>

      <div class="detail-grid">
        <div>
          <div class="muted">เบอร์โทรลูกค้า</div>
          <div class="box">${escapeHtml(data.phone || "ไม่ได้ระบุ")}</div>
        </div>

        <div>
          <div class="muted">จำนวนคิวรอทั้งหมดของร้านวันนี้</div>
          <div class="box"><strong>${Number(data.waiting_count || 0)}</strong> คิว</div>
        </div>
      </div>

      <div style="margin-top:12px;">
        <div class="muted">รายการออเดอร์</div>
        <div class="box">${escapeHtml(data.note || "ไม่ได้ระบุรายการ")}</div>
      </div>

      <div class="info-bar">
        <div>
          <div class="small">สถานะจะรีเฟรชอัตโนมัติทุก ${Math.floor(REFRESH_MS / 1000)} วินาที</div>
          <div class="loading-inline" id="loadingInline">กำลังอัปเดตข้อมูลล่าสุด...</div>
        </div>
        <button class="btn btn-outline" onclick="fetchAndUpdateCurrent(true)">รีเฟรชตอนนี้</button>
      </div>

      <div class="actions-primary">
        ${canReceive(data.status) ? `<button class="btn btn-green" onclick="receiveCurrentQueue(${Number(data.queue_id || 0)})">✅ ยืนยันรับออเดอร์แล้ว</button>` : ``}
        ${canCancel(data.status) ? `<button class="btn btn-danger" onclick="cancelCurrentQueue(${Number(data.queue_id || 0)})">ยกเลิกคิว</button>` : ``}
      </div>

      <div class="actions-secondary">
        <button class="btn btn-primary" onclick="goShop(${Number(data.shop_id || 0)})">ดูหน้าร้าน</button>
        <button class="btn btn-secondary" onclick="goNotifications()">ดูคิวอื่นที่ติดตามไว้</button>
        <button class="btn btn-dark" onclick="forgetCurrentQueue(${Number(data.queue_id || 0)})">ซ่อนคิวนี้</button>
      </div>
    </div>
  `;
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

function forgetCurrentQueue(queueId){
  if(!confirm("ต้องการซ่อนคิวนี้ออกจากเครื่องนี้ใช่ไหม?\nรายการนี้จะไม่ถูกลบออกจากระบบจริง")) return;
  removeQueueId(queueId);
  location.href = "notifications.php";
}

async function cancelCurrentQueue(queueId){
  if(!confirm("ต้องการยกเลิกคิวนี้ใช่ไหม?\nเมื่อยกเลิกแล้ว ระบบของร้านจะอัปเดตทันที")) return;

  try{
    const form = new FormData();
    form.append("queue_id", queueId);

    const res = await fetch("cancel_queue.php", {
      method: "POST",
      body: form
    });

    const data = await res.json();
    if(!data.ok){
      alert(data.error || "ยกเลิกคิวไม่สำเร็จ");
      return;
    }

    alert("ยกเลิกคิวสำเร็จ");
    fetchAndUpdateCurrent(true);
  }catch(e){
    alert("เกิดข้อผิดพลาดในการยกเลิกคิว");
  }
}

async function receiveCurrentQueue(queueId){
  if(!confirm("ยืนยันว่าคุณได้รับออเดอร์เรียบร้อยแล้วใช่ไหม?")) return;

  try{
    const form = new FormData();
    form.append("queue_id", queueId);

    const res = await fetch("receive_queue.php", {
      method: "POST",
      body: form
    });

    const data = await res.json();
    if(!data.ok){
      alert(data.error || "ยืนยันรับออเดอร์ไม่สำเร็จ");
      return;
    }

    alert("ยืนยันรับออเดอร์เรียบร้อยแล้ว");
    fetchAndUpdateCurrent(true);
  }catch(e){
    alert("เกิดข้อผิดพลาดในการยืนยันรับออเดอร์");
  }
}

function handleStatusChangeNotify(data, firstLoad){
  const key = "prev_status_" + data.queue_id;
  const prev = localStorage.getItem(key);

  if(firstLoad){
    localStorage.setItem(key, data.status);
    return;
  }

  if(prev === data.status) return;
  localStorage.setItem(key, data.status);

  if(data.status === "calling"){
    beep(4, 120, 900);
    openNowPopup(`${data.shop_name} กำลังเรียกคิวของคุณ (#${data.queue_no})`);
    pushNotify("ถึงคิวแล้ว!", `${data.shop_name} กำลังเรียกคิวของคุณ (#${data.queue_no})`);
  }else if(data.status === "served"){
    beep(3, 140, 820);
    openStatusPopup("ออเดอร์พร้อมรับแล้ว ✅", `${data.shop_name} • คิว #${data.queue_no} ออเดอร์พร้อมรับแล้ว`);
    pushNotify("ออเดอร์พร้อมรับแล้ว ✅", `${data.shop_name} • คิว #${data.queue_no} ออเดอร์พร้อมรับแล้ว`);
  }else if(data.status === "received"){
    beep(2, 150, 700);
    openStatusPopup("รับออเดอร์แล้ว ✅", `${data.shop_name} • คุณยืนยันรับออเดอร์แล้ว`);
    pushNotify("รับออเดอร์แล้ว ✅", `${data.shop_name} • คุณยืนยันรับออเดอร์แล้ว`);
  }else if(data.status === "cancel"){
    beep(2, 220, 320);
    openStatusPopup("ยกเลิกแล้ว ❌", `${data.shop_name} • คิวของคุณถูกยกเลิก (#${data.queue_no})`);
    pushNotify("ยกเลิกแล้ว ❌", `${data.shop_name} • คิวของคุณถูกยกเลิก (#${data.queue_no})`);
  }
}

function handleAlerts(data){
  if(["served","received","cancel"].includes(data.status)) return;

  if(data.before_me <= 2 && data.before_me > 0 && !localStorage.getItem("near_alert_shown_" + data.queue_id)){
    localStorage.setItem("near_alert_shown_" + data.queue_id, "1");
    openNearPopup(`${data.shop_name} • เหลือประมาณ ${data.before_me} คิว`);
    beep(2, 150, 760);
    pushNotify("คิวใกล้ถึงแล้ว", `${data.shop_name} • เหลือประมาณ ${data.before_me} คิว`);
  }

  const isNow = (data.status === "calling");
  if(isNow && !localStorage.getItem("now_alert_shown_" + data.queue_id)){
    localStorage.setItem("now_alert_shown_" + data.queue_id, "1");
    openNowPopup(`${data.shop_name} • ร้านกำลังเรียกคิวของคุณแล้ว`);
    beep(4, 120, 900);
    pushNotify("ถึงคิวแล้ว!", `${data.shop_name} • ร้านกำลังเรียกคิวของคุณแล้ว`);
  }
}

let firstLoad = true;
let isFetching = false;

async function fetchQueueData(queueId){
  const res = await fetch(`api_my_queue.php?queue_id=${queueId}&_=${Date.now()}`, { cache:"no-store" });
  const data = await res.json();
  if(!data.ok) return null;
  return normalizeData(data);
}

async function fetchAndUpdateCurrent(manual=false){
  if(isFetching) return;

  try{
    if(!incomingQueueId){
      renderEmptyState();
      return;
    }

    isFetching = true;
    setLoadingInline(true);

    const data = await fetchQueueData(incomingQueueId);

    renderQueue(data);

    if(data){
      handleStatusChangeNotify(data, firstLoad);
      handleAlerts(data);
      showErrorBanner(false);
    }else{
      showErrorBanner(true);
    }

    firstLoad = false;
  }catch(e){
    console.error("fetchAndUpdateCurrent error:", e);
    if(manual){
      alert("ไม่สามารถดึงข้อมูลล่าสุดได้ กรุณาลองใหม่อีกครั้ง");
    }
    showErrorBanner(true);
  }finally{
    isFetching = false;
    setLoadingInline(false);
  }
}

(async () => {
  try {
    const reg = await registerServiceWorker();
    console.log("Service Worker registered:", reg.scope);
  } catch (e) {
    console.error("Service Worker register failed:", e);
  }
})();

updateNotifyStatusUI();
async function syncPushStatusUI() {
  try {
    hasActivePushSubscription = false;

    if (!("Notification" in window) || !("serviceWorker" in navigator) || !("PushManager" in window)) {
      updateNotifyStatusUI();
      return;
    }

    await registerServiceWorker();
    const subscription = await getPushSubscription();

    hasActivePushSubscription = !!subscription;
    updateNotifyStatusUI();

    const currentCustomerToken = ensureCustomerToken();

    if (subscription && incomingQueueId > 0 && currentCustomerToken) {
      console.log("กำลัง bind subscription กับ queue:", incomingQueueId);
      await bindExistingSubscriptionToCurrentQueue(incomingQueueId);
    }

  } catch (e) {
    console.error("syncPushStatusUI error:", e);
    updateNotifyStatusUI();
  }
}
syncPushStatusUI();
fetchAndUpdateCurrent();
setInterval(fetchAndUpdateCurrent, REFRESH_MS);
</script>
</body>
</html>