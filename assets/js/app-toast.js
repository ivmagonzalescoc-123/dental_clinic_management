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

  function getToastIcon(type) {
    switch (normalizeToastType(type)) {
      case 'success':
        return '\u2713';
      case 'danger':
        return '\u2715';
      case 'warning':
        return '!';
      default:
        return 'i';
    }
  }

  function showAppToast(message, type, options) {
    var text = String(message || '').trim();
    if (!text) return;

    var host = ensureAppToastHost();
    var toastType = normalizeToastType(type);
    var toast = document.createElement('div');
    toast.className = 'app-toast app-toast-' + toastType;
    toast.setAttribute('role', 'status');

    var icon = document.createElement('span');
    icon.className = 'app-toast-icon app-toast-icon--' + toastType;
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = getToastIcon(toastType);

    var body = document.createElement('div');
    body.className = 'app-toast-body';
    body.textContent = text;

    toast.appendChild(icon);
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

  function portalNotify(message, type, options) {
    if (typeof showAppToast === 'function') {
      showAppToast(message, type, options);
      return true;
    }
    if (typeof window.alert === 'function') {
      window.alert(String(message || ''));
    }
    return false;
  }

  window.showAppToast = showAppToast;
  window.showToast = showAppToast;
  window.portalNotify = portalNotify;
})();
