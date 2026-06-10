(function () {
  'use strict';

  var EYE_OPEN = '<svg class="password-toggle-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>';
  var EYE_CLOSED = '<svg class="password-toggle-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>';

  function hasExistingToggle(input) {
    var parent = input.parentElement;
    if (!parent) return false;
    if (parent.querySelector('[data-password-toggle-btn]')) return true;
    if (parent.querySelector('#toggle-password')) return true;
    return false;
  }

  function createToggleButton(isBootstrap) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = isBootstrap ? 'btn btn-outline-secondary password-toggle-btn' : 'password-toggle-btn password-toggle-btn--auth';
    btn.setAttribute('data-password-toggle-btn', '1');
    btn.setAttribute('aria-label', 'Show password');
    btn.setAttribute('aria-pressed', 'false');
    btn.innerHTML = isBootstrap
      ? '<i class="bi bi-eye password-toggle-icon-bootstrap"></i>'
      : EYE_OPEN;
    return btn;
  }

  function setToggleState(btn, input, isBootstrap, visible) {
    input.type = visible ? 'text' : 'password';
    btn.setAttribute('aria-pressed', visible ? 'true' : 'false');
    btn.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
    if (isBootstrap) {
      var icon = btn.querySelector('i');
      if (icon) {
        icon.classList.toggle('bi-eye', !visible);
        icon.classList.toggle('bi-eye-slash', visible);
      }
      return;
    }
    btn.innerHTML = visible ? EYE_CLOSED : EYE_OPEN;
  }

  function enhancePasswordInput(input) {
    if (!(input instanceof HTMLInputElement)) return;
    if (input.type !== 'password') return;
    if (input.dataset.pwToggleReady === '1') return;
    if (hasExistingToggle(input)) return;

    var isBootstrap = input.classList.contains('form-control');
    var parent = input.parentElement;
    if (!parent) return;

    input.dataset.pwToggleReady = '1';

    var wrap = document.createElement('div');
    wrap.className = 'password-toggle-wrap' + (isBootstrap ? ' password-toggle-wrap--bootstrap' : ' password-toggle-wrap--auth');

    parent.insertBefore(wrap, input);
    wrap.appendChild(input);

    if (!isBootstrap && !/\bpr-1[0-2]\b/.test(input.className)) {
      input.classList.add('password-toggle-input--auth');
    }

    var btn = createToggleButton(isBootstrap);
    wrap.appendChild(btn);

    btn.addEventListener('click', function () {
      var visible = input.type === 'password';
      setToggleState(btn, input, isBootstrap, visible);
    });
  }

  function initPasswordToggles(root) {
    var scope = root instanceof HTMLElement ? root : document;
    scope.querySelectorAll('input[type="password"]').forEach(enhancePasswordInput);
  }

  window.initPasswordToggles = initPasswordToggles;

  document.addEventListener('DOMContentLoaded', function () {
    initPasswordToggles(document);
  });
})();
