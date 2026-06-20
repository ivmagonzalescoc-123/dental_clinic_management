(function () {
  'use strict';

  var pendingResolve = null;
  var confirmedClose = false;

  function ensureConfirmModal() {
    var modalEl = document.getElementById('appConfirmModal');
    if (modalEl) return modalEl;

    modalEl = document.createElement('div');
    modalEl.id = 'appConfirmModal';
    modalEl.className = 'modal fade app-confirm-modal';
    modalEl.tabIndex = -1;
    modalEl.setAttribute('aria-labelledby', 'appConfirmModalTitle');
    modalEl.setAttribute('aria-hidden', 'true');
    modalEl.innerHTML =
      '<div class="modal-dialog modal-dialog-centered">' +
        '<div class="modal-content">' +
          '<div class="modal-header">' +
            '<h5 class="modal-title" id="appConfirmModalTitle">Confirm</h5>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
          '</div>' +
          '<div class="modal-body" id="appConfirmModalBody"></div>' +
          '<div class="modal-footer">' +
            '<button type="button" class="btn btn-secondary" id="appConfirmCancelBtn" data-bs-dismiss="modal">Cancel</button>' +
            '<button type="button" class="btn btn-primary" id="appConfirmOkBtn">Confirm</button>' +
          '</div>' +
        '</div>' +
      '</div>';

    document.body.appendChild(modalEl);

    modalEl.addEventListener('hidden.bs.modal', function () {
      if (!confirmedClose && pendingResolve) {
        var resolve = pendingResolve;
        pendingResolve = null;
        resolve(false);
      }
      confirmedClose = false;
    });

    modalEl.querySelector('#appConfirmOkBtn').addEventListener('click', function () {
      confirmedClose = true;
      if (pendingResolve) {
        var resolve = pendingResolve;
        pendingResolve = null;
        resolve(true);
      }
      if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
      } else {
        modalEl.classList.remove('show');
        modalEl.style.display = 'none';
      }
    });

    return modalEl;
  }

  function normalizeVariant(variant) {
    var value = String(variant || 'primary').toLowerCase();
    if (value === 'danger' || value === 'success' || value === 'warning' || value === 'primary') {
      return value;
    }
    return 'primary';
  }

  function submitConfirmedForm(form) {
    var attrs = {
      confirm: form.getAttribute('data-confirm'),
      title: form.getAttribute('data-confirm-title'),
      variant: form.getAttribute('data-confirm-variant'),
      ok: form.getAttribute('data-confirm-ok')
    };

    form.removeAttribute('data-confirm');
    form.removeAttribute('data-confirm-title');
    form.removeAttribute('data-confirm-variant');
    form.removeAttribute('data-confirm-ok');

    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }

    window.setTimeout(function () {
      if (!document.body.contains(form)) return;
      if (attrs.confirm) form.setAttribute('data-confirm', attrs.confirm);
      if (attrs.title) form.setAttribute('data-confirm-title', attrs.title);
      if (attrs.variant) form.setAttribute('data-confirm-variant', attrs.variant);
      if (attrs.ok) form.setAttribute('data-confirm-ok', attrs.ok);
    }, 0);
  }

  function showConfirmModal(options) {
    options = options || {};
    var message = String(options.message || options.body || '').trim();
    if (!message) {
      return Promise.resolve(false);
    }

    ensureConfirmModal();
    var modalEl = document.getElementById('appConfirmModal');
    var titleEl = document.getElementById('appConfirmModalTitle');
    var bodyEl = document.getElementById('appConfirmModalBody');
    var okBtn = document.getElementById('appConfirmOkBtn');
    var cancelBtn = document.getElementById('appConfirmCancelBtn');

    titleEl.textContent = String(options.title || 'Confirm');
    bodyEl.textContent = message;

    var variant = normalizeVariant(options.variant || options.confirmClass);
    okBtn.className = 'btn btn-' + variant;
    okBtn.textContent = String(options.confirmText || options.okText || 'Confirm');
    cancelBtn.textContent = String(options.cancelText || 'Cancel');

    confirmedClose = false;

    return new Promise(function (resolve) {
      pendingResolve = resolve;
      if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
      } else {
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
      }
    });
  }

  function initConfirmForms() {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!(form instanceof HTMLFormElement)) return;

      var msg = form.getAttribute('data-confirm');
      if (!msg) return;

      e.preventDefault();
      e.stopPropagation();

      showConfirmModal({
        title: form.getAttribute('data-confirm-title') || 'Confirm',
        message: msg,
        variant: form.getAttribute('data-confirm-variant') || 'primary',
        confirmText: form.getAttribute('data-confirm-ok') || 'Confirm'
      }).then(function (ok) {
        if (!ok) return;
        submitConfirmedForm(form);
      });
    }, true);
  }

  window.showConfirmModal = showConfirmModal;
  window.appConfirm = function (message, options) {
    var opts = options && typeof options === 'object' ? Object.assign({}, options) : {};
    opts.message = message;
    return showConfirmModal(opts);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initConfirmForms);
  } else {
    initConfirmForms();
  }
  window.addEventListener('page-loaded', initConfirmForms);
})();
