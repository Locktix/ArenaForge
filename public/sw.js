// ArenaForge — Service Worker
// Stratégie : cache-first pour les assets statiques, network-only pour PHP/API.

const CACHE_NAME = 'arenaforge-v1';

const PRECACHE = [
    './manifest.json',
    '../assets/css/main.css',
    '../assets/js/sfx.js',
    '../assets/js/music.js',
    '../assets/js/toast.js',
    '../assets/js/notifications.js',
    '../assets/svg/logo/logo.svg',
    '../assets/svg/logo/favicon.svg',
];

self.addEventListener('install', (e) => {
    e.waitUntil(
        caches.open(CACHE_NAME)
            .then((c) => c.addAll(PRECACHE))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);

    // Network-only : pages PHP et endpoints API (données dynamiques)
    if (url.pathname.endsWith('.php') || url.pathname.includes('/api/')) {
        return;
    }

    // Network-only : requêtes non-GET (POST, etc.)
    if (e.request.method !== 'GET') {
        return;
    }

    // Cache-first puis mise à jour silencieuse pour tous les assets statiques
    e.respondWith(
        caches.open(CACHE_NAME).then((cache) =>
            cache.match(e.request).then((cached) => {
                const networkFetch = fetch(e.request).then((res) => {
                    if (res && res.status === 200) {
                        cache.put(e.request, res.clone());
                    }
                    return res;
                }).catch(() => cached);

                return cached || networkFetch;
            })
        )
    );
});
