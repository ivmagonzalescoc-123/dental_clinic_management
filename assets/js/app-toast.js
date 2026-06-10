(function () {
  'use strict';

  function ensureAppToastHost() {
    var host = document.getElementById('appToastHost');
    if (host) return host;

    host = document.createElement('div');
    host.id = 'appToastHost';
    host.className = 'app-toast-host';
    if (!document.getElementById('header')) {
      host.classList.add('app-toast-host--auth');
    }
    host.setAttribute('aria-live', 'polite');
    host.setAttribute('aria-atomic', 'true');
    document.body.appendChild(host);
    return host;
  }

  function normalizeToastType(type) {
    var value = String(type || 'info').toLowerCase();
    if (value === 'error') return 'danger';
    if (value === 'success' || value === 'danger' || value === 'warning' || value === 'info') {
      return value;
    }
    return 'info';
  }

  function showAppToast(message, type, options) {
    var text = String(message || '').trim();
    if (!text) return;

    var host = ensureAppToastHost();
    var toast = document.createElement('div');
    toast.className = 'app-toast app-toast-' + normalizeToastType(type);
    toast.setAttribute('role', 'status');

    var body = document.createElement('div');
    body.className = 'app-toast-body';
    body.textContent = text;

    toast.appendChild(body);
    host.appendChild(toast);

    requestAnimationFrame(function () {
      toast.classList.add('is-visible');
    });

    var removed = false;
    var opts = options || {};
    var removeToast = function () {
      if (removed) return;
      removed = true;
      toast.classList.remove('is-visible');
      setTimeout(function () {
        try { toast.remove(); } catch (e) {}
      }, 180);
    };

    setTimeout(removeToast, Number(opts.delay || 3200));
  }

  window.showAppToast = showAppToast;
  window.showToast = showAppToast;
})();
