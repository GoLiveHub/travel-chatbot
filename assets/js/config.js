// Global configuration — sets API_BASE from data attribute or defaults to /api
(function () {
  var body = document.body;
  window.API_BASE = (body && body.dataset.apiBase) || '/api';
})();
