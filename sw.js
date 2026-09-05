/*  sw.js — Service Worker for caching LLM model files
    Intercepts requests to Hugging Face CDN and caches model weights
    so subsequent loads are instant (no re-download).                  */

const CACHE_NAME = 'travel-llm-model-v1';
const MODEL_ORIGIN = 'https://huggingface.co';
const CDN_ORIGIN = 'https://cdn.jsdelivr.net';
const HF_API = 'https://huggingface.co/api';

self.addEventListener('install', (e) => {
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  e.waitUntil(clients.claim());
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);

  const isModelFile =
    url.hostname === 'huggingface.co' && url.pathname.includes('resolve') ||
    url.hostname === 'cdn.jsdelivr.net' && url.pathname.includes('transformers');

  if (!isModelFile) return;

  e.respondWith(
    caches.open(CACHE_NAME).then(async (cache) => {
      const cached = await cache.match(e.request);
      if (cached) return cached;

      try {
        const response = await fetch(e.request);
        if (response.ok) {
          cache.put(e.request, response.clone());
        }
        return response;
      } catch (err) {
        return new Response('Model not available offline', { status: 503 });
      }
    })
  );
});

self.addEventListener('message', (e) => {
  if (e.data && e.data.type === 'CLEAR_CACHE') {
    caches.delete(CACHE_NAME).then(() => {
      self.clients.matchAll().then((clients) => {
        clients.forEach((c) => c.postMessage({ type: 'CACHE_CLEARED' }));
      });
    });
  }

  if (e.data && e.data.type === 'CACHE_URLS') {
    const urls = e.data.urls || [];
    caches.open(CACHE_NAME).then(async (cache) => {
      for (const url of urls) {
        try {
          const resp = await fetch(url);
          if (resp.ok) await cache.put(url, resp);
        } catch (_) {}
      }
      self.clients.matchAll().then((clients) => {
        clients.forEach((c) => c.postMessage({ type: 'URLS_CACHED', count: urls.length }));
      });
    });
  }
});
