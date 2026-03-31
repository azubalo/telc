const CACHE_NAME = 'design-jobs-v1';
const SHELL_FILES = ['./index.html', './manifest.json'];

// Install - cache app shell
self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(SHELL_FILES))
      .then(() => self.skipWaiting())
  );
});

// Activate - clean old caches
self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Fetch - stale-while-revalidate for API, cache-first for shell
self.addEventListener('fetch', e => {
  const url = e.request.url;

  // API requests: stale-while-revalidate
  if (url.includes('arbeitnow.com') || url.includes('remoteok.com') ||
      url.includes('allorigins.win') || url.includes('corsproxy.io') ||
      url.includes('codetabs.com') || url.includes('indeed.com')) {
    e.respondWith(
      caches.open(CACHE_NAME).then(cache =>
        cache.match(e.request).then(cached => {
          const fetchPromise = fetch(e.request).then(response => {
            if (response.ok) cache.put(e.request, response.clone());
            return response;
          }).catch(() => cached);
          return cached || fetchPromise;
        })
      )
    );
    return;
  }

  // App shell: cache-first
  e.respondWith(
    caches.match(e.request).then(cached => cached || fetch(e.request))
  );
});
