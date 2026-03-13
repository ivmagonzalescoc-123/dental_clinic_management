(function () {
  function ensureIdleToastStyles() {
    if (document.getElementById('idleToastStyles')) {
      return;
    }

    var style = document.createElement('style');
    style.id = 'idleToastStyles';
    style.textContent = '' +
      '@keyframes idleSlideIn { from { opacity: 0; transform: translateY(-10px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }' +
      '@keyframes idlePulse { 0% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.45); } 70% { box-shadow: 0 0 0 10px rgba(255, 193, 7, 0); } 100% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0); } }' +
      '.idle-toast-show { animation: idleSlideIn 0.25s ease-out, idlePulse 1.25s ease-in-out infinite; }' +
      '.idle-progress-wrap { height: 6px; background: rgba(0,0,0,0.08); border-radius: 999px; overflow: hidden; margin-top: 8px; }' +
      '.idle-progress-bar { height: 6px; width: 100%; background: linear-gradient(90deg, #ffc107 0%, #fd7e14 100%); transition: width 1s linear; }';
    document.head.appendChild(style);
  }

  function createToastShell() {
    ensureIdleToastStyles();

    var container = document.getElementById('idleToastContainer');
    if (!container) {
      container = document.createElement('div');
      container.id = 'idleToastContainer';
      container.style.position = 'fixed';
      container.style.top = '20px';
      container.style.right = '20px';
      container.style.zIndex = '1100';
      container.style.maxWidth = '360px';
      document.body.appendChild(container);
    }

    var toast = document.createElement('div');
    toast.id = 'idleToast';
    toast.className = 'alert alert-warning shadow-sm mb-0 d-none';
    toast.innerHTML = '<div class="fw-semibold mb-1">Session idle warning</div>' +
      '<div id="idleToastMessage" class="small"></div>' +
      '<div class="idle-progress-wrap"><div id="idleProgressBar" class="idle-progress-bar"></div></div>' +
      '<div class="mt-2 d-flex justify-content-end gap-2">' +
      '<button type="button" id="idleStayBtn" class="btn btn-sm btn-primary">Stay logged in</button>' +
      '<button type="button" id="idleLogoutBtn" class="btn btn-sm btn-outline-secondary">Logout now</button>' +
      '</div>';
    container.appendChild(toast);

    return {
      toast: toast,
      message: toast.querySelector('#idleToastMessage'),
      progressBar: toast.querySelector('#idleProgressBar'),
      stayBtn: toast.querySelector('#idleStayBtn'),
      logoutBtn: toast.querySelector('#idleLogoutBtn')
    };
  }

  function initSessionIdleMonitor(options) {
    if (!options || !options.logoutFn || !options.pingUrl) {
      return;
    }

    var idleLimitSeconds = Number(options.idleLimitSeconds || 3600);
    var countdownSeconds = Number(options.countdownSeconds || 60);
    var roleLabel = String(options.roleLabel || 'User');
    var pingUrl = String(options.pingUrl);
    var logoutFn = options.logoutFn;

    var warningShown = false;
    var warningStartedAt = 0;
    var lastActivityAt = Date.now();
    var lastPingAt = 0;
    var countdownTimer = null;

    var ui = createToastShell();

    function pingSession(force) {
      var now = Date.now();
      if (!force && now - lastPingAt < 30000) {
        return;
      }
      lastPingAt = now;
      fetch(pingUrl, { method: 'POST' }).catch(function () {});
    }

    function hideWarning() {
      warningShown = false;
      warningStartedAt = 0;
      if (countdownTimer) {
        clearInterval(countdownTimer);
        countdownTimer = null;
      }
      ui.toast.classList.add('d-none');
      ui.toast.classList.remove('idle-toast-show');
      ui.message.textContent = '';
      if (ui.progressBar) {
        ui.progressBar.style.width = '100%';
      }
    }

    function renderCountdown() {
      var elapsed = Math.floor((Date.now() - warningStartedAt) / 1000);
      var remaining = Math.max(0, countdownSeconds - elapsed);
      ui.message.textContent = roleLabel + ' session will be logged out in ' + remaining + 's due to inactivity.';
      if (ui.progressBar) {
        var pct = (remaining / countdownSeconds) * 100;
        ui.progressBar.style.width = pct + '%';
      }
      if (remaining <= 0) {
        hideWarning();
        logoutFn();
      }
    }

    function showWarning() {
      if (warningShown) {
        return;
      }
      warningShown = true;
      warningStartedAt = Date.now();
      ui.toast.classList.remove('d-none');
      ui.toast.classList.add('idle-toast-show');
      renderCountdown();
      countdownTimer = setInterval(renderCountdown, 1000);
    }

    function handleActivity() {
      lastActivityAt = Date.now();
      if (warningShown) {
        hideWarning();
      }
      pingSession(false);
    }

    ui.stayBtn.addEventListener('click', function () {
      lastActivityAt = Date.now();
      hideWarning();
      pingSession(true);
    });

    ui.logoutBtn.addEventListener('click', function () {
      hideWarning();
      logoutFn();
    });

    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (evt) {
      document.addEventListener(evt, handleActivity, { passive: true });
    });

    setInterval(function () {
      var idleSeconds = Math.floor((Date.now() - lastActivityAt) / 1000);
      if (!warningShown && idleSeconds >= idleLimitSeconds) {
        showWarning();
      }
    }, 1000);

    setInterval(function () {
      if (!warningShown) {
        pingSession(false);
      }
    }, 60000);
  }

  window.initSessionIdleMonitor = initSessionIdleMonitor;
})();