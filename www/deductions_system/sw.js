/**
 * sw.js - Service Worker للـ PWA
 * يوفر التخزين المؤقت والعمل بدون إنترنت
 */

const CACHE_VERSION = 'v1.0.0';
const CACHE_STATIC = `static-${CACHE_VERSION}`;
const CACHE_DYNAMIC = `dynamic-${CACHE_VERSION}`;

// ملفات ثابتة تُخزّن مسبقاً
const STATIC_ASSETS = [
    '/',
    '/index.php',
    '/offline.html',
    '/manifest.json',
    '/assets/css/header.css',
    '/assets/css/dashboard.css',
    '/assets/css/mobile.css',
    '/assets/fontawesome/css/all.min.css',
    '/assets/icons/icon-192.png',
    '/assets/icons/icon-512.png',
];

// ============================================================
// التثبيت
// ============================================================
self.addEventListener('install', event => {
    console.log('[SW] Installing...');
    event.waitUntil(
        caches.open(CACHE_STATIC).then(cache => {
            return cache.addAll(STATIC_ASSETS.filter(url => url));
        }).then(() => self.skipWaiting())
    );
});

// ============================================================
// التنشيط
// ============================================================
self.addEventListener('activate', event => {
    console.log('[SW] Activating...');
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.filter(key => key !== CACHE_STATIC && key !== CACHE_DYNAMIC)
                    .map(key => caches.delete(key))
            );
        }).then(() => self.clients.claim())
    );
});

// ============================================================
// اعتراض الطلبات
// ============================================================
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);
    
    // تجاهل الطلبات غير GET
    if (request.method !== 'GET') return;
    
    // تجاهل الطلبات الخارجية
    if (url.origin !== location.origin) return;
    
    // تجاهل API (يجب أن تكون حية دائماً)
    if (url.pathname.startsWith('/api/')) return;
    
    // تجاهل POST
    if (url.pathname.endsWith('.php') && request.method === 'POST') return;
    
    // استراتيجية: Network First مع fallback للكاش
    event.respondWith(
        fetch(request)
            .then(response => {
                // خزّن نسخة في الكاش
                if (response && response.status === 200) {
                    const clone = response.clone();
                    caches.open(CACHE_DYNAMIC).then(cache => {
                        cache.put(request, clone);
                    });
                }
                return response;
            })
            .catch(() => {
                // فشل الاتصال → ابحث في الكاش
                return caches.match(request).then(cached => {
                    if (cached) return cached;
                    
                    // صفحة عدم الاتصال
                    if (request.mode === 'navigate') {
                        return caches.match('/offline.html');
                    }
                    
                    return new Response('Offline', { status: 503 });
                });
            })
    );
});

// ============================================================
// رسائل من الصفحة
// ============================================================
self.addEventListener('message', event => {
    if (event.data === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

// ============================================================
// Push Notifications (اختياري)
// ============================================================
self.addEventListener('push', event => {
    const data = event.data ? event.data.json() : {};
    const title = data.title || 'نظام الاقتطاعات';
    const options = {
        body: data.body || 'لديك إشعار جديد',
        icon: '/assets/icons/icon-192.png',
        badge: '/assets/icons/icon-96.png',
        dir: 'rtl',
        lang: 'ar',
        vibrate: [200, 100, 200],
        data: data.url ? { url: data.url } : {},
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    if (event.notification.data && event.notification.data.url) {
        event.waitUntil(clients.openWindow(event.notification.data.url));
    }
});