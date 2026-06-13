(function () {
  'use strict';

  var pageLoadingCount = 0;
  var pageLoadingEl = null;
  var lastClickActionEl = null;
  var pendingActionEl = null;
  var pendingActionExpires = 0;
  var nativeFetch = window.fetch.bind(window);
  var confirmModalWrapped = false;
  var autoLoadingInitialized = false;

  function resolveActionElement(el) {
    if (!el) return null;
    if (el instanceof HTMLButtonElement || el instanceof HTMLInputElement || el instanceof HTMLAnchorElement) {
      return el;
    }
    if (el instanceof HTMLFormElement) {
      return el.querySelector('[type="submit"], button:not([type="button"])');
    }
    return null;
  }

  function defaultLoadingText(el) {
    if (el && el.getAttribute('data-loading-text')) {
      return el.getAttribute('data-loading-text');
    }
    return 'Processing...';
  }

  function buildLoadingHtml(text) {
    return (
      '<span class="app-spinner me-2" role="status" aria-hidden="true"></span>' +
      '<span>' + String(text || 'Processing...') + '</span>'
    );
  }

  function ensurePageLoadingEl() {
    if (pageLoadingEl) return pageLoadingEl;
    pageLoadingEl = document.createElement('div');
    pageLoadingEl.className = 'app-page-loading';
    pageLoadingEl.setAttribute('aria-live', 'polite');
    pageLoadingEl.innerHTML =
      '<div class="app-page-loading__panel">' +
        '<span class="app-spinner" role="status" aria-hidden="true"></span>' +
        '<span class="app-page-loading-text">Processing...</span>' +
      '</div>';
    return pageLoadingEl;
  }

  function setPageLoading(loading, text) {
    if (loading) {
      pageLoadingCount += 1;
      var host = ensurePageLoadingEl();
      var label = host.querySelector('.app-page-loading-text');
      if (label) label.textContent = String(text || 'Processing...');
      if (!host.parentNode) document.body.appendChild(host);
      return;
    }

    pageLoadingCount = Math.max(0, pageLoadingCount - 1);
    if (pageLoadingCount === 0 && pageLoadingEl && pageLoadingEl.parentNode) {
      pageLoadingEl.parentNode.removeChild(pageLoadingEl);
    }
  }

  function setActionLoading(el, loading, options) {
    options = options || {};
    var target = resolveActionElement(el);

    if (!target) {
      setPageLoading(loading, options.text || 'Processing...');
      return;
    }

    if (loading) {
      if (target.dataset.actionLoadingActive === '1') return;
      target.dataset.actionLoadingActive = '1';
      target.classList.add('is-action-loading');

      if (target.tagName === 'INPUT') {
        if (!target.dataset.actionLoadingPrevValue) {
          target.dataset.actionLoadingPrevValue = target.value;
        }
        target.value = options.text || defaultLoadingText(target);
      } else {
        if (!target.dataset.actionLoadingPrevHtml) {
          target.dataset.actionLoadingPrevHtml = target.innerHTML;
        }
        target.innerHTML = buildLoadingHtml(options.text || defaultLoadingText(target));
      }

      target.disabled = true;
      target.setAttribute('aria-busy', 'true');
      return;
    }

    if (target.dataset.actionLoadingActive !== '1') return;

    target.dataset.actionLoadingActive = '0';
    target.classList.remove('is-action-loading');
    target.disabled = false;
    target.removeAttribute('aria-busy');

    if (target.tagName === 'INPUT') {
      if (target.dataset.actionLoadingPrevValue) {
        target.value = target.dataset.actionLoadingPrevValue;
        delete target.dataset.actionLoadingPrevValue;
      }
    } else if (target.dataset.actionLoadingPrevHtml) {
      target.innerHTML = target.dataset.actionLoadingPrevHtml;
      delete target.dataset.actionLoadingPrevHtml;
    }
  }

  function runWithActionLoading(el, fn, options) {
    options = options || {};
    setActionLoading(el, true, options);
    return Promise.resolve()
      .then(fn)
      .finally(function () {
        if (!options.keepLoading) {
          setActionLoading(el, false);
        }
      });
  }

  function bindAsyncClick(el, handler, options) {
    var target = typeof el === 'string' ? document.querySelector(el) : el;
    if (!target) return;
    target.addEventListener('click', function (event) {
      if (target.dataset.actionLoadingActive === '1') {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
      }
      var result = handler(event, target);
      if (result && typeof result.then === 'function') {
        event.preventDefault();
        runWithActionLoading(target, function () { return result; }, options);
      }
    });
  }

  function bindAsyncSubmit(form, handler, options) {
    var target = typeof form === 'string' ? document.querySelector(form) : form;
    if (!target) return;
    target.addEventListener('submit', function (event) {
      event.preventDefault();
      if (target.dataset.actionLoadingActive === '1') return;
      var submitter = event.submitter || target.querySelector('[type="submit"]');
      runWithActionLoading(submitter || target, function () {
        return handler(event, target, submitter);
      }, options);
    });
  }

  function isIgnoredActionTrigger(el) {
    if (!el || el.dataset.noActionLoading === '1') return true;
    if (el.classList.contains('btn-close') || el.getAttribute('data-bs-dismiss')) return true;
    if (el.classList.contains('toggle-sidebar-btn')) return true;
    if (el.id === 'appConfirmCancelBtn') return true;
    return false;
  }

  function isActionTrigger(el) {
    if (!el || isIgnoredActionTrigger(el)) return false;
    if (el.tagName === 'INPUT' && el.type === 'submit') return true;
    if (el.tagName === 'A' && el.classList.contains('btn')) return true;
    if (el.tagName !== 'BUTTON') return false;

    if (el.type === 'submit' || el.type === '' || el.type == null) return true;
    if (el.type === 'button') {
      if (el.dataset.actionLoading === '1') return true;
      if (el.hasAttribute('onclick')) return true;
      if (el.classList.contains('btn-primary') || el.classList.contains('btn-danger') ||
          el.classList.contains('btn-success') || el.classList.contains('btn-warning') ||
          el.classList.contains('btn-info')) {
        return true;
      }
    }
    return false;
  }

  function markPendingAction(el, ttlMs) {
    if (!el || !isActionTrigger(el)) return;
    pendingActionEl = el;
    pendingActionExpires = Date.now() + (ttlMs || 15000);
  }

  function clearPendingAction() {
    pendingActionEl = null;
    pendingActionExpires = 0;
  }

  function consumePendingActionEl() {
    if (pendingActionEl && Date.now() <= pendingActionExpires) {
      var el = pendingActionEl;
      pendingActionEl = null;
      pendingActionExpires = 0;
      return el;
    }
    clearPendingAction();
    return null;
  }

  function shouldAutoLoadFetch(input, init) {
    var method = ((init && init.method) || 'GET').toUpperCase();
    if (method === 'GET' || method === 'HEAD') return false;

    var url = String(typeof input === 'string' ? input : (input && input.url) || '');
    if (url.indexOf('/notifications/') !== -1) return false;
    if (/session[_-]?idle|session[_-]?ping|ping[_-]?session/i.test(url)) return false;

    return !!pendingActionEl && Date.now() <= pendingActionExpires;
  }

  function wrapFetchForAutoLoading() {
    if (window.__appRequestFetchWrapped) return;
    window.__appRequestFetchWrapped = true;

    window.fetch = function (input, init) {
      if (!shouldAutoLoadFetch(input, init)) {
        return nativeFetch(input, init);
      }

      var actionEl = consumePendingActionEl();
      if (!actionEl) {
        return nativeFetch(input, init);
      }

      if (actionEl.dataset.actionLoadingActive === '1') {
        return nativeFetch(input, init);
      }

      return runWithActionLoading(actionEl, function () {
        return nativeFetch(input, init);
      });
    };
  }

  function wrapConfirmModalForAutoLoading() {
    if (confirmModalWrapped || typeof window.showConfirmModal !== 'function') return;
    confirmModalWrapped = true;

    var originalShowConfirmModal = window.showConfirmModal;
    window.showConfirmModal = function (options) {
      var originEl = lastClickActionEl;
      return originalShowConfirmModal(options).then(function (ok) {
        if (ok && originEl && isActionTrigger(originEl)) {
          markPendingAction(originEl, 15000);
        } else if (!ok) {
          clearPendingAction();
        }
        return ok;
      });
    };
  }

  function initAutoActionLoading() {
    if (autoLoadingInitialized) return;
    autoLoadingInitialized = true;

    document.addEventListener('click', function (event) {
      var el = event.target && event.target.closest
        ? event.target.closest('button, input[type="submit"], a.btn')
        : null;
      if (!el || !isActionTrigger(el)) return;
      lastClickActionEl = el;
      markPendingAction(el, 15000);
    }, true);

    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!(form instanceof HTMLFormElement)) return;
      if (form.dataset.noActionLoading === '1') return;

      var submitter = event.submitter || form.querySelector('[type="submit"], button:not([type="button"])');
      if (submitter && isActionTrigger(submitter)) {
        lastClickActionEl = submitter;
        markPendingAction(submitter, 15000);
      }
    }, true);

    wrapFetchForAutoLoading();
    wrapConfirmModalForAutoLoading();

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', wrapConfirmModalForAutoLoading);
    }
    window.addEventListener('load', wrapConfirmModalForAutoLoading);
  }

  window.setActionLoading = setActionLoading;
  window.runWithActionLoading = runWithActionLoading;
  window.bindAsyncClick = bindAsyncClick;
  window.bindAsyncSubmit = bindAsyncSubmit;
  window.markPendingAction = markPendingAction;
  window.clearPendingAction = clearPendingAction;
  window.actionFetch = function (url, options, actionEl, loadingOptions) {
    if (actionEl) {
      markPendingAction(actionEl, 15000);
    }
    return runWithActionLoading(actionEl, function () {
      return nativeFetch(url, options);
    }, loadingOptions);
  };

  initAutoActionLoading();
})();
