self.addEventListener("install", (event) => {
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener("push", (event) => {
  let data = {
    title: "แจ้งเตือนคิว",
    body: "มีการอัปเดตสถานะคิวของคุณ",
    url: "/queue/Frontend/index.php",
    tag: "queue-default",
    icon: "/queue/manifest-icon-192.png",
    badge: "/queue/manifest-icon-192.png"
  };

  try {
    const incoming = event.data ? event.data.json() : {};
    data = { ...data, ...incoming };
  } catch (e) {
    // ใช้ค่า default ต่อ
  }

  const options = {
    body: data.body || "มีการอัปเดตสถานะคิวของคุณ",
    tag: data.tag || "queue-default",
    renotify: true,
    icon: data.icon || "/queue/manifest-icon-192.png",
    badge: data.badge || "/queue/manifest-icon-192.png",
    vibrate: [200, 100, 200],
    requireInteraction: true,
    data: {
      url: data.url || "/queue/Frontend/index.php"
    }
  };

  event.waitUntil(
    self.registration.showNotification(
      data.title || "แจ้งเตือนคิว",
      options
    )
  );
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();

  const targetUrl =
    event.notification.data && event.notification.data.url
      ? event.notification.data.url
      : "/queue/Frontend/index.php";

  event.waitUntil(
    clients.matchAll({ type: "window", includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        try {
          const clientUrl = new URL(client.url);

          if ("focus" in client && clientUrl.pathname.startsWith("/queue/")) {
            return client.focus().then(() => {
              if ("navigate" in client) {
                return client.navigate(targetUrl);
              }
            });
          }
        } catch (e) {}
      }

      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
    })
  );
});