(function () {
  'use strict';

  function getToggle(item) {
    return item.querySelector('[data-portal-dropdown-toggle]');
  }

  function closeDropdown(item) {
    if (!item || !item.classList.contains('is-open')) return;
    item.classList.remove('is-open');
    var toggle = getToggle(item);
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
  }

  function closeAll(except) {
    var openItems = document.querySelectorAll('.header-dropdown.is-open');
    for (var i = 0; i < openItems.length; i++) {
      if (openItems[i] !== except) closeDropdown(openItems[i]);
    }
  }

  function isMobileHeader() {
    return window.innerWidth <= 991.98;
  }

  window.closePortalHeaderDropdowns = function () {
    closeAll(null);
  };

  function openDropdown(item) {
    item.classList.add('is-open');
    var toggle = getToggle(item);
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'true');
      toggle.dispatchEvent(new CustomEvent('portaldropdown:open', { bubbles: true }));
    }
  }

  function initDropdowns() {
    var toggles = document.querySelectorAll('[data-portal-dropdown-toggle]');
    for (var i = 0; i < toggles.length; i++) {
      (function (toggle) {
        var item = toggle.closest('.header-dropdown');
        if (!item) return;

        toggle.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();
          if (isMobileHeader() && typeof window.closePortalHeaderSearch === 'function') {
            window.closePortalHeaderSearch();
          }
          var willOpen = !item.classList.contains('is-open');
          closeAll(item);
          if (willOpen) {
            openDropdown(item);
          } else {
            closeDropdown(item);
          }
        });
      })(toggles[i]);
    }

    document.addEventListener('click', function (event) {
      if (event.target.closest && event.target.closest('.header-dropdown')) return;
      closeAll(null);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' || event.key === 'Esc') {
        closeAll(null);
        if (typeof window.closePortalHeaderSearch === 'function') {
          window.closePortalHeaderSearch();
        }
      }
    });

    window.addEventListener('resize', function () {
      closeAll(null);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDropdowns);
  } else {
    initDropdowns();
  }
})();
