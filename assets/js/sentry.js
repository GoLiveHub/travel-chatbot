// Sentry error tracking — placeholder
// To enable: set SENTRY_DSN env var in render.yaml or .env
// npm install @sentry/browser && import * as Sentry from '@sentry/browser';
(function () {
  var dsn = window.SENTRY_DSN || '';
  if (!dsn) return;

  // Placeholder — uncomment below after installing @sentry/browser:
  // Sentry.init({ dsn: dsn, tracesSampleRate: 0.2, environment: 'production' });

  // Lightweight error forwarding fallback (no Sentry SDK needed)
  window.addEventListener('error', function (e) {
    var payload = {
      message: e.message,
      filename: e.filename,
      lineno: e.lineno,
      colno: e.colno,
      stack: e.error ? e.error.stack : '',
      url: location.href,
      ts: Date.now(),
    };
    // Fire-and-forget beacon (no blocking)
    if (navigator.sendBeacon) {
      navigator.sendBeacon('/api/health.php', JSON.stringify(payload));
    }
  });

  window.addEventListener('unhandledrejection', function (e) {
    var payload = {
      message: 'UnhandledPromiseRejection',
      reason: String(e.reason || ''),
      url: location.href,
      ts: Date.now(),
    };
    if (navigator.sendBeacon) {
      navigator.sendBeacon('/api/health.php', JSON.stringify(payload));
    }
  });
})();
