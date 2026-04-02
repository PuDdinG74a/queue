(function () {
  if (typeof window.OWNER_NOTIFY_SHOP_ID === "undefined") return;

  const shopId = Number(window.OWNER_NOTIFY_SHOP_ID || 0);
  if (!shopId) return;

  const POLL_MS = 4000;
  const STORAGE_KEY = `owner_last_total_${shopId}`;
  const SOUND_UNLOCK_KEY = `owner_sound_unlocked_${shopId}`;

  let isChecking = false;
  let started = false;
  let audioUnlocked = localStorage.getItem(SOUND_UNLOCK_KEY) === "1";

  function getStoredTotal() {
    const v = localStorage.getItem(STORAGE_KEY);
    return v === null ? null : Number(v);
  }

  function setStoredTotal(total) {
    localStorage.setItem(STORAGE_KEY, String(total));
  }

  function unlockAudio() {
    audioUnlocked = true;
    localStorage.setItem(SOUND_UNLOCK_KEY, "1");
  }

  function tryUnlockAudioOnce() {
    const handler = () => {
      unlockAudio();
      window.removeEventListener("click", handler);
      window.removeEventListener("touchstart", handler);
      window.removeEventListener("keydown", handler);
    };

    window.addEventListener("click", handler, { once: true });
    window.addEventListener("touchstart", handler, { once: true });
    window.addEventListener("keydown", handler, { once: true });
  }

  function playAlertPattern() {
    if (!audioUnlocked) return;

    try {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return;

      const ctx = new AudioCtx();

      const pattern = [
        { t: 0.00, dur: 0.20, freq: 880 },
        { t: 0.28, dur: 0.20, freq: 880 },
        { t: 0.56, dur: 0.20, freq: 660 },
        { t: 0.84, dur: 0.35, freq: 990 }
      ];

      pattern.forEach((p) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = "square";
        osc.frequency.value = p.freq;

        gain.gain.setValueAtTime(0.0001, ctx.currentTime + p.t);
        gain.gain.exponentialRampToValueAtTime(0.18, ctx.currentTime + p.t + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + p.t + p.dur);

        osc.connect(gain);
        gain.connect(ctx.destination);

        osc.start(ctx.currentTime + p.t);
        osc.stop(ctx.currentTime + p.t + p.dur + 0.02);
      });

      const totalDur = 1.5;
      setTimeout(() => {
        ctx.close().catch(() => {});
      }, totalDur * 1000 + 200);
    } catch (e) {
      console.log("playAlertPattern error:", e);
    }
  }

  function ensurePopup() {
    let overlay = document.getElementById("ownerQueueNotifyOverlay");
    if (overlay) return overlay;

    overlay = document.createElement("div");
    overlay.id = "ownerQueueNotifyOverlay";
    overlay.style.position = "fixed";
    overlay.style.inset = "0";
    overlay.style.background = "rgba(0,0,0,.38)";
    overlay.style.display = "none";
    overlay.style.alignItems = "center";
    overlay.style.justifyContent = "center";
    overlay.style.zIndex = "999999";

    overlay.innerHTML = `
      <div id="ownerQueueNotifyBox" style="
        width:min(92vw, 420px);
        background:#fff;
        border-radius:22px;
        box-shadow:0 20px 50px rgba(0,0,0,.22);
        padding:22px 18px 18px;
        text-align:center;
        border:1px solid #eee;
      ">
        <div style="
          width:72px;
          height:72px;
          margin:0 auto 14px;
          border-radius:999px;
          background:#fff4df;
          display:flex;
          align-items:center;
          justify-content:center;
          font-size:34px;
        ">🔔</div>

        <div style="font-size:24px;font-weight:900;color:#111;margin-bottom:8px;">
          มีคิวใหม่เข้า
        </div>

        <div id="ownerQueueNotifyText" style="
          font-size:15px;
          line-height:1.5;
          color:#444;
          margin-bottom:16px;
        ">
          มีลูกค้าเข้าคิวใหม่
        </div>

        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
          <button id="ownerQueueNotifyOk" type="button" style="
            min-width:120px;
            border:none;
            background:#111;
            color:#fff;
            border-radius:14px;
            padding:12px 16px;
            font-size:15px;
            font-weight:800;
            cursor:pointer;
          ">รับทราบ</button>

          <button id="ownerQueueNotifyGo" type="button" style="
            min-width:120px;
            border:1px solid #ddd;
            background:#fff;
            color:#111;
            border-radius:14px;
            padding:12px 16px;
            font-size:15px;
            font-weight:800;
            cursor:pointer;
          ">ไปหน้าคิว</button>
        </div>

        <div id="ownerQueueNotifySoundHint" style="
          margin-top:12px;
          font-size:12px;
          color:#777;
        "></div>
      </div>
    `;

    document.body.appendChild(overlay);

    const okBtn = document.getElementById("ownerQueueNotifyOk");
    const goBtn = document.getElementById("ownerQueueNotifyGo");

    okBtn.addEventListener("click", () => {
      overlay.style.display = "none";
      unlockAudio();
    });

    goBtn.addEventListener("click", () => {
      overlay.style.display = "none";
      unlockAudio();
      window.location.href = `shop_owner.php?shop_id=${shopId}`;
    });

    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) {
        overlay.style.display = "none";
        unlockAudio();
      }
    });

    return overlay;
  }

  function showPopup(diff) {
    const overlay = ensurePopup();
    const text = document.getElementById("ownerQueueNotifyText");
    const hint = document.getElementById("ownerQueueNotifySoundHint");

    text.textContent =
      diff === 1
        ? "มีลูกค้าเข้าคิวใหม่ 1 คิว"
        : `มีลูกค้าเข้าคิวใหม่ ${diff} คิว`;

    hint.textContent = audioUnlocked
      ? "ระบบส่งเสียงเตือนแล้ว"
      : "แตะหน้าจอหรือกดปุ่มสักครั้ง เพื่อเปิดใช้งานเสียงแจ้งเตือนครั้งถัดไป";

    overlay.style.display = "flex";
  }

  async function fetchQueueTotal() {
    const res = await fetch(`dashboard_api.php?shop_id=${shopId}`, {
      cache: "no-store",
      headers: { "X-Requested-With": "XMLHttpRequest" }
    });

    if (!res.ok) throw new Error("fetch fail");

    const data = await res.json();
    if (!data.ok) throw new Error(data.error || "api fail");

    return Number(data.queues?.total ?? 0);
  }

  async function checkNewQueue() {
    if (isChecking || document.hidden) return;
    isChecking = true;

    try {
      const total = await fetchQueueTotal();
      const prevTotal = getStoredTotal();

      if (prevTotal === null) {
        setStoredTotal(total);
        isChecking = false;
        return;
      }

      if (total > prevTotal) {
        const diff = total - prevTotal;
        showPopup(diff);
        playAlertPattern();
      }

      setStoredTotal(total);
    } catch (e) {
      console.log("owner notify error:", e);
    } finally {
      isChecking = false;
    }
  }

  function startOwnerNotify() {
    if (started) return;
    started = true;

    tryUnlockAudioOnce();
    checkNewQueue();
    setInterval(checkNewQueue, POLL_MS);

    document.addEventListener("visibilitychange", () => {
      if (!document.hidden) {
        checkNewQueue();
      }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", startOwnerNotify);
  } else {
    startOwnerNotify();
  }
})();