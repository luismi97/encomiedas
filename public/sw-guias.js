/**
 * Service worker del modo sin conexión (copia del de retailpos, public/sw-pos.js).
 *
 * - Pre-cachea /guias-offline, el núcleo de la cola y el CSS compilado (este
 *   último resuelto desde /build/manifest.json, porque el nombre lleva hash).
 * - Sin conexión, CUALQUIER navegación que falle redirige a /guias-offline en
 *   vez de mostrar «sitio inaccesible».
 * - Al cambiar de versión MIGRA lo del cache anterior antes de borrarlo: un
 *   precache fallido dejaba el equipo sin página offline.
 * - Acepta el mensaje 'enc-precache' (lo manda cada página al cargar con red)
 *   para volver a llenar el cache: el equipo se repara solo con usarse.
 *
 * Requiere HTTPS (o localhost): sin eso el navegador no registra service workers.
 *
 * SUBIR LA VERSIÓN al cambiar public/js/guias-offline.js o el HTML de la
 * pantalla offline: /js/* se sirve CACHE-FIRST y un equipo que solo abre la
 * pantalla sin conexión se quedaría con la versión vieja.
 */
const CACHE = 'enc-guias-offline-v2';
const PAGE = '/guias-offline';
const PRECACHE = ['/js/guias-offline.js'];

// La página se guarda «limpia»: sin el flag redirected (una respuesta
// redirigida en cache no se puede servir a una navegación) y nunca el login
// (redirected => sesión vencida: eso no es la pantalla offline).
async function precachePage(cache) {
    try {
        const res = await fetch(PAGE, { credentials: 'same-origin', cache: 'no-store' });
        if (!res.ok || res.redirected) return false;
        const body = await res.blob();
        await cache.put(PAGE, new Response(body, {
            status: 200,
            headers: { 'Content-Type': res.headers.get('Content-Type') || 'text/html; charset=utf-8' },
        }));
        return true;
    } catch (e) { return false; }
}

async function precacheAll() {
    const cache = await caches.open(CACHE);
    await precachePage(cache);
    await Promise.allSettled(PRECACHE.map(async (url) => {
        if (!(await cache.match(url))) await cache.add(url);
    }));
    try {
        const manifest = await (await fetch('/build/manifest.json')).json();
        const urls = [];
        for (const entry of Object.values(manifest)) {
            if (entry.file && entry.file.endsWith('.css')) urls.push('/build/' + entry.file);
            (entry.css || []).forEach((c) => urls.push('/build/' + c));
        }
        await Promise.allSettled([...new Set(urls)].map(async (u) => {
            if (!(await cache.match(u))) await cache.add(u);
        }));
    } catch (e) { /* sin manifest (vite dev): el cache en caliente lo cubre */ }
}

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(precacheAll());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = (await caches.keys()).filter((k) => k.startsWith('enc-guias-offline') && k !== CACHE);
        const current = await caches.open(CACHE);
        for (const key of keys) {
            const old = await caches.open(key);
            for (const req of await old.keys()) {
                if (!(await current.match(req))) {
                    const res = await old.match(req);
                    if (res) await current.put(req, res);
                }
            }
        }
        await Promise.all(keys.map((k) => caches.delete(k)));
        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    if (event.data === 'enc-precache') {
        event.waitUntil(precacheAll());
    }
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // Navegaciones: red primero; sin red, la misma página desde cache o
    // redirigir a la pantalla offline.
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req)
                .then((res) => {
                    if (res.ok && !res.redirected && url.pathname === PAGE) {
                        const copy = res.clone();
                        caches.open(CACHE).then((c) => c.put(PAGE, copy));
                    }
                    return res;
                })
                .catch(async () => {
                    const hit = await caches.match(url.pathname === PAGE ? PAGE : req);
                    if (hit) return hit;
                    // Redirigir y no servir el contenido bajo otra URL: la
                    // pantalla offline corriendo en /invoices confundiría los
                    // guardas contra rebotes del watchdog.
                    if (url.pathname !== PAGE && (await caches.match(PAGE))) {
                        return Response.redirect(PAGE, 302);
                    }
                    return new Response('Sin conexión y sin la pantalla offline guardada. Abrí /guias-offline una vez con internet.', {
                        status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' },
                    });
                })
        );
        return;
    }

    // Assets compilados y el núcleo de la cola: cache primero.
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/js/')) {
        event.respondWith(
            caches.match(req).then((hit) => hit || fetch(req).then((res) => {
                if (res.ok) {
                    const copy = res.clone();
                    caches.open(CACHE).then((c) => c.put(req, copy));
                }
                return res;
            }))
        );
    }
});
