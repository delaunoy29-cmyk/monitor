// Service worker mínimo: permite instalar el panel como app y, sin conexión,
// muestra la última versión del panel en vez de una página de error.
// Los datos (api/...) nunca se guardan: siempre se piden al servidor.
const CACHE = "panel-v1";

self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", (e) => e.waitUntil(
  caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
    .then(() => self.clients.claim())
));

self.addEventListener("fetch", (e) => {
  const req = e.request;
  if (req.method !== "GET" || req.mode !== "navigate") return;
  e.respondWith(
    fetch(req).then((r) => {
      if (r.ok && !r.redirected) { const copia = r.clone(); caches.open(CACHE).then((c) => c.put("./", copia)); }
      return r;
    }).catch(() => caches.match("./").then((r) => r || Response.error()))
  );
});
