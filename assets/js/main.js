/**
* Template Name: NiceAdmin
* Updated: Nov 17 2023 with Bootstrap v5.3.2
* Template URL: https://bootstrapmade.com/nice-admin-bootstrap-admin-html-template/
* Author: BootstrapMade.com
* License: https://bootstrapmade.com/license/
*/
(function() {
  "use strict";

  /**
   * Easy selector helper function
   */
  const select = (el, all = false) => {
    el = el.trim()
    if (all) {
      return [...document.querySelectorAll(el)]
    } else {
      return document.querySelector(el)
    }
  }

  /**
   * Easy event listener function
   */
  const on = (type, el, listener, all = false) => {
    if (all) {
      select(el, all).forEach(e => e.addEventListener(type, listener))
    } else {
      select(el, all).addEventListener(type, listener)
    }
  }

  /**
   * Easy on scroll event listener 
   */
  const onscroll = (el, listener) => {
    el.addEventListener('scroll', listener)
  }

  function ensureAppToastHost() {
    let host = document.getElementById('appToastHost')
    if (host) return host

    host = document.createElement('div')
    host.id = 'appToastHost'
    host.className = 'app-toast-host'
    host.setAttribute('aria-live', 'polite')
    host.setAttribute('aria-atomic', 'true')
    document.body.appendChild(host)
    return host
  }

  function toastTypeFromAlertClass(alertEl) {
    if (!alertEl || !alertEl.classList) return 'info'
    if (alertEl.classList.contains('alert-success')) return 'success'
    if (alertEl.classList.contains('alert-danger')) return 'danger'
    if (alertEl.classList.contains('alert-warning')) return 'warning'
    return 'info'
  }

  function getAlertText(alertEl) {
    if (!alertEl) return ''
    const clone = alertEl.cloneNode(true)
    clone.querySelectorAll('.btn-close,[data-bs-dismiss="alert"]').forEach((el) => el.remove())
    return String(clone.textContent || '').replace(/\s+/g, ' ').trim()
  }

  function showAppToast(message, type = 'info', options = {}) {
    const text = String(message || '').trim()
    if (!text) return

    const host = ensureAppToastHost()
    const toast = document.createElement('div')
    toast.className = 'app-toast app-toast-' + String(type || 'info')
    toast.setAttribute('role', 'status')

    const body = document.createElement('div')
    body.className = 'app-toast-body'
    body.textContent = text

    const closeBtn = document.createElement('button')
    closeBtn.type = 'button'
    closeBtn.className = 'app-toast-close'
    closeBtn.setAttribute('aria-label', 'Dismiss notification')
    closeBtn.innerHTML = '&times;'

    toast.appendChild(body)
    toast.appendChild(closeBtn)
    host.appendChild(toast)

    requestAnimationFrame(() => toast.classList.add('is-visible'))

    let removed = false
    const removeToast = () => {
      if (removed) return
      removed = true
      toast.classList.remove('is-visible')
      setTimeout(() => {
        try { toast.remove() } catch (e) {}
      }, 180)
    }

    closeBtn.addEventListener('click', removeToast)
    const delay = Number(options.delay || 3200)
    setTimeout(removeToast, delay)
  }

  function convertInlineAlertsToToasts(scope) {
    const root = scope instanceof HTMLElement ? scope : document
    const alerts = root.querySelectorAll('.alert.alert-dismissible.fade.show:not([data-toastified="1"])')
    alerts.forEach((alertEl) => {
      if (!(alertEl instanceof HTMLElement)) return
      if (alertEl.closest('.modal')) return
      if (alertEl.closest('.app-toast-host')) return
      if (alertEl.getAttribute('data-keep-inline-alert') === '1') return

      const message = getAlertText(alertEl)
      const type = toastTypeFromAlertClass(alertEl)
      alertEl.setAttribute('data-toastified', '1')
      if (message) showAppToast(message, type)
      alertEl.remove()
    })
  }

  window.showAppToast = showAppToast

  /**
   * Sidebar toggle
   */
  if (select('.toggle-sidebar-btn')) {
    on('click', '.toggle-sidebar-btn', function(e) {
      var body = select('body')
      if (body && body.classList.contains('patient-portal') && window.innerWidth <= 991) {
        e.preventDefault()
        return
      }
      body.classList.toggle('toggle-sidebar')
    })
  }

  document.addEventListener('click', function (e) {
    if (window.innerWidth > 991) return
    var body = select('body')
    if (!body || body.classList.contains('patient-portal')) return
    if (!body.classList.contains('toggle-sidebar')) return
    if (e.target.closest('#sidebar') || e.target.closest('.toggle-sidebar-btn')) return
    body.classList.remove('toggle-sidebar')
  })

  /**
   * Header search clear buttons (all portals)
   */
  document.querySelectorAll('.header-search').forEach(function(host) {
    const input = host.querySelector('.header-search-input, .unified-table-search-input')
    const clearBtn = host.querySelector('.header-search-clear, .unified-table-search-clear')
    if (!input || !clearBtn) return

    const syncClear = function() {
      clearBtn.style.display = input.value ? '' : 'none'
    }

    input.addEventListener('input', syncClear)
    input.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        input.value = ''
        syncClear()
        input.dispatchEvent(new Event('input', { bubbles: true }))
      }
    })
    clearBtn.addEventListener('click', function(e) {
      e.preventDefault()
      input.value = ''
      syncClear()
      input.dispatchEvent(new Event('input', { bubbles: true }))
      input.focus()
    })
  })

  /**
   * Navbar links active state on scroll
   */
  let navbarlinks = select('#navbar .scrollto', true)
  const navbarlinksActive = () => {
    let position = window.scrollY + 200
    navbarlinks.forEach(navbarlink => {
      if (!navbarlink.hash) return
      let section = select(navbarlink.hash)
      if (!section) return
      if (position >= section.offsetTop && position <= (section.offsetTop + section.offsetHeight)) {
        navbarlink.classList.add('active')
      } else {
        navbarlink.classList.remove('active')
      }
    })
  }
  window.addEventListener('load', navbarlinksActive)
  onscroll(document, navbarlinksActive)

  /**
   * Toggle .header-scrolled class to #header when page is scrolled
   */
  let selectHeader = select('#header')
  if (selectHeader) {
    const headerScrolled = () => {
      if (window.scrollY > 100) {
        selectHeader.classList.add('header-scrolled')
      } else {
        selectHeader.classList.remove('header-scrolled')
      }
    }
    window.addEventListener('load', headerScrolled)
    onscroll(document, headerScrolled)
  }

  /**
   * Back to top button
   */
  let backtotop = select('.back-to-top')
  if (backtotop) {
    const toggleBacktotop = () => {
      if (window.scrollY > 100) {
        backtotop.classList.add('active')
      } else {
        backtotop.classList.remove('active')
      }
    }
    window.addEventListener('load', toggleBacktotop)
    onscroll(document, toggleBacktotop)
  }

  /**
   * Initiate tooltips
   */
  var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
  var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl)
  })

  /**
   * Initiate quill editors
   */
  if (select('.quill-editor-default')) {
    new Quill('.quill-editor-default', {
      theme: 'snow'
    });
  }

  if (select('.quill-editor-bubble')) {
    new Quill('.quill-editor-bubble', {
      theme: 'bubble'
    });
  }

  if (select('.quill-editor-full')) {
    new Quill(".quill-editor-full", {
      modules: {
        toolbar: [
          [{
            font: []
          }, {
            size: []
          }],
          ["bold", "italic", "underline", "strike"],
          [{
              color: []
            },
            {
              background: []
            }
          ],
          [{
              script: "super"
            },
            {
              script: "sub"
            }
          ],
          [{
              list: "ordered"
            },
            {
              list: "bullet"
            },
            {
              indent: "-1"
            },
            {
              indent: "+1"
            }
          ],
          ["direction", {
            align: []
          }],
          ["link", "image", "video"],
          ["clean"]
        ]
      },
      theme: "snow"
    });
  }

  /**
   * Initiate TinyMCE Editor
   */
  const useDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const isSmallScreen = window.matchMedia('(max-width: 1023.5px)').matches;

  tinymce.init({
    selector: 'textarea.tinymce-editor',
    plugins: 'preview importcss searchreplace autolink autosave save directionality code visualblocks visualchars fullscreen image link media template codesample table charmap pagebreak nonbreaking anchor insertdatetime advlist lists wordcount help charmap quickbars emoticons',
    editimage_cors_hosts: ['picsum.photos'],
    menubar: 'file edit view insert format tools table help',
    toolbar: 'undo redo | bold italic underline strikethrough | fontfamily fontsize blocks | alignleft aligncenter alignright alignjustify | outdent indent |  numlist bullist | forecolor backcolor removeformat | pagebreak | charmap emoticons | fullscreen  preview save print | insertfile image media template link anchor codesample | ltr rtl',
    toolbar_sticky: true,
    toolbar_sticky_offset: isSmallScreen ? 102 : 108,
    autosave_ask_before_unload: true,
    autosave_interval: '30s',
    autosave_prefix: '{path}{query}-{id}-',
    autosave_restore_when_empty: false,
    autosave_retention: '2m',
    image_advtab: true,
    link_list: [{
        title: 'My page 1',
        value: 'https://www.tiny.cloud'
      },
      {
        title: 'My page 2',
        value: 'http://www.moxiecode.com'
      }
    ],
    image_list: [{
        title: 'My page 1',
        value: 'https://www.tiny.cloud'
      },
      {
        title: 'My page 2',
        value: 'http://www.moxiecode.com'
      }
    ],
    image_class_list: [{
        title: 'None',
        value: ''
      },
      {
        title: 'Some class',
        value: 'class-name'
      }
    ],
    importcss_append: true,
    file_picker_callback: (callback, value, meta) => {
      /* Provide file and text for the link dialog */
      if (meta.filetype === 'file') {
        callback('https://www.google.com/logos/google.jpg', {
          text: 'My text'
        });
      }

      /* Provide image and alt text for the image dialog */
      if (meta.filetype === 'image') {
        callback('https://www.google.com/logos/google.jpg', {
          alt: 'My alt text'
        });
      }

      /* Provide alternative source and posted for the media dialog */
      if (meta.filetype === 'media') {
        callback('movie.mp4', {
          source2: 'alt.ogg',
          poster: 'https://www.google.com/logos/google.jpg'
        });
      }
    },
    templates: [{
        title: 'New Table',
        description: 'creates a new table',
        content: '<div class="mceTmpl"><table width="98%%"  border="0" cellspacing="0" cellpadding="0"><tr><th scope="col"> </th><th scope="col"> </th></tr><tr><td> </td><td> </td></tr></table></div>'
      },
      {
        title: 'Starting my story',
        description: 'A cure for writers block',
        content: 'Once upon a time...'
      },
      {
        title: 'New list with dates',
        description: 'New List with dates',
        content: '<div class="mceTmpl"><span class="cdate">cdate</span><br><span class="mdate">mdate</span><h2>My List</h2><ul><li></li><li></li></ul></div>'
      }
    ],
    template_cdate_format: '[Date Created (CDATE): %m/%d/%Y : %H:%M:%S]',
    template_mdate_format: '[Date Modified (MDATE): %m/%d/%Y : %H:%M:%S]',
    height: 600,
    image_caption: true,
    quickbars_selection_toolbar: 'bold italic | quicklink h2 h3 blockquote quickimage quicktable',
    noneditable_class: 'mceNonEditable',
    toolbar_mode: 'sliding',
    contextmenu: 'link image table',
    skin: useDarkMode ? 'oxide-dark' : 'oxide',
    content_css: useDarkMode ? 'dark' : 'default',
    content_style: 'body { font-family:Helvetica,Arial,sans-serif; font-size:16px }'
  });

  /**
   * Initiate Bootstrap validation check
   */
  var needsValidation = document.querySelectorAll('.needs-validation')

  Array.prototype.slice.call(needsValidation)
    .forEach(function(form) {
      form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
          event.preventDefault()
          event.stopPropagation()
        }

        form.classList.add('was-validated')
      }, false)
    })

  /**
   * Initiate Datatables
   */
  function removeDuplicateSearchToolbarsNearDatatable() {
    document.querySelectorAll('.datatable-wrapper').forEach((wrapper) => {
      const host = wrapper.closest('.table-responsive') || wrapper.parentElement
      if (!host || !host.parentElement) return
      host.parentElement.querySelectorAll(':scope > .unified-table-search-toolbar').forEach((toolbar) => {
        toolbar.remove()
      })
    })
  }

  const datatables = select('.datatable:not([data-skip-datatable])', true)
  datatables.forEach(datatable => {
    if (datatable.dataset.skipDatatable === '1') return
    new simpleDatatables.DataTable(datatable, {
      perPageSelect: [5, 10, 15, ["All", -1]],
      columns: [{
          select: 2,
          sortSequence: ["desc", "asc"]
        },
        {
          select: 3,
          sortSequence: ["desc"]
        },
        {
          select: 4,
          cellClass: "green",
          headerClass: "red"
        }
      ]
    })
  })
  removeDuplicateSearchToolbarsNearDatatable()

  function ensureUnifiedTableSearch(tableEl, options = {}) {
    if (!(tableEl instanceof HTMLTableElement)) return
    if (tableEl.dataset.unifiedSearchInit === '1') return
    if (!options.force && tableEl.dataset.skipTableSearch === '1') return
    if (tableEl.classList.contains('datatable') || tableEl.classList.contains('datatable-table')) return
    if (tableEl.closest('.datatable-wrapper')) return
    if (tableEl.dataset.skipDatatable === '1') return
    if (tableEl.closest('.modal')) return
    if (tableEl.closest('.fc')) return
    if (tableEl.classList.contains('doctor-availability-calendar') || tableEl.classList.contains('calendar-table')) return
    if (tableEl.getAttribute('role') === 'presentation') return

    const tbody = tableEl.tBodies && tableEl.tBodies[0] ? tableEl.tBodies[0] : null
    if (!tbody) return

    const host = tableEl.closest('.table-responsive') || tableEl.parentElement
    if (!host || !host.parentElement) return

    const existingToolbar = host.parentElement.querySelector(':scope > .unified-table-search-toolbar')
    if (existingToolbar) {
      tableEl.dataset.unifiedSearchInit = '1'
      return existingToolbar.querySelector('.unified-table-search-input')
    }

    const toolbar = document.createElement('div')
    toolbar.className = 'unified-table-search-toolbar'

    const searchWrap = document.createElement('div')
    searchWrap.className = 'unified-table-search-wrap'

    const searchIcon = document.createElement('i')
    searchIcon.className = 'bi bi-search unified-table-search-icon'
    searchIcon.setAttribute('aria-hidden', 'true')

    const searchInput = document.createElement('input')
    searchInput.type = 'search'
    searchInput.className = 'form-control unified-table-search-input'
    searchInput.placeholder = options.placeholder || tableEl.dataset.searchPlaceholder || 'Search table...'
    searchInput.setAttribute('aria-label', searchInput.placeholder)

    const clearBtn = document.createElement('button')
    clearBtn.type = 'button'
    clearBtn.className = 'unified-table-search-clear'
    clearBtn.setAttribute('aria-label', 'Clear search')
    clearBtn.innerHTML = '&times;'
    clearBtn.style.display = 'none'

    searchWrap.appendChild(searchIcon)
    searchWrap.appendChild(searchInput)
    searchWrap.appendChild(clearBtn)
    toolbar.appendChild(searchWrap)
    host.parentElement.insertBefore(toolbar, host)

    const filterRows = () => {
      const term = String(searchInput.value || '').toLowerCase().trim()
      clearBtn.style.display = term ? '' : 'none'

      if (typeof options.onFilter === 'function') {
        options.onFilter(term, tableEl, searchInput)
        return
      }

      const rows = Array.from(tbody.querySelectorAll(':scope > tr')).filter((row) => !row.classList.contains('unified-table-no-results'))
      let visibleCount = 0

      rows.forEach((row) => {
        const text = String(row.textContent || '').toLowerCase()
        const match = !term || text.includes(term)
        row.style.display = match ? '' : 'none'
        if (match) visibleCount++
      })

      let noResultRow = tbody.querySelector(':scope > tr.unified-table-no-results')
      if (term && rows.length > 0 && visibleCount === 0) {
        if (!noResultRow) {
          noResultRow = document.createElement('tr')
          noResultRow.className = 'unified-table-no-results'
          const td = document.createElement('td')
          td.colSpan = tableEl.tHead && tableEl.tHead.rows[0] ? tableEl.tHead.rows[0].cells.length : 1
          td.className = 'text-center text-muted py-3'
          td.textContent = 'No matching records found.'
          noResultRow.appendChild(td)
          tbody.appendChild(noResultRow)
        }
        noResultRow.style.display = ''
      } else if (noResultRow) {
        noResultRow.remove()
      }
    }

    searchInput.addEventListener('input', filterRows)
    clearBtn.addEventListener('click', () => {
      searchInput.value = ''
      filterRows()
      searchInput.focus()
    })
    searchInput.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        searchInput.value = ''
        filterRows()
      }
    })

    tableEl.dataset.unifiedSearchInit = '1'
    filterRows()
    return searchInput
  }

  function initUnifiedTableSearch(root) {
    const scope = root instanceof HTMLElement ? root : document
    const tables = scope.querySelectorAll('#main .table-responsive table, #main table')
    const seen = new Set()
    tables.forEach((table) => {
      if (!(table instanceof HTMLTableElement) || seen.has(table)) return
      seen.add(table)
      ensureUnifiedTableSearch(table)
    })
  }

  function styleDatatableSearchInputs() {
    document.querySelectorAll('.datatable-wrapper .datatable-input').forEach((input) => {
      if (input.dataset.unifiedStyled === '1') return
      input.dataset.unifiedStyled = '1'
      input.classList.add('unified-table-search-input')
      if (!input.placeholder || input.placeholder === 'Search...') {
        input.placeholder = 'Search table...'
      }
      const parent = input.parentElement
      if (parent && parent.classList.contains('datatable-search') && !parent.querySelector('.unified-table-search-icon')) {
        parent.classList.add('unified-datatable-search')
        const icon = document.createElement('i')
        icon.className = 'bi bi-search unified-table-search-icon'
        icon.setAttribute('aria-hidden', 'true')
        parent.insertBefore(icon, input)
      }
    })
  }

  window.attachUnifiedTableSearch = ensureUnifiedTableSearch
  window.initUnifiedTableSearch = initUnifiedTableSearch

  window.addEventListener('load', () => {
    removeDuplicateSearchToolbarsNearDatatable()
    initUnifiedTableSearch(document)
    styleDatatableSearchInputs()
  })
  document.addEventListener('DOMContentLoaded', () => {
    removeDuplicateSearchToolbarsNearDatatable()
    initUnifiedTableSearch(document)
    setTimeout(() => {
      removeDuplicateSearchToolbarsNearDatatable()
      styleDatatableSearchInputs()
    }, 50)
  })

  /**
   * Autoresize echart charts
   */
  const mainContainer = select('#main');
  if (mainContainer) {
    setTimeout(() => {
      new ResizeObserver(function() {
        select('.echart', true).forEach(getEchart => {
          echarts.getInstanceByDom(getEchart).resize();
        })
      }).observe(mainContainer);
    }, 200);
  }

  // Notifications poller (simple polling, returns unread count and items)
  // Derive app root reliably from the script src or pathname.
  let appRoot = '';
  try {
    const script = document.querySelector('script[src*="assets/js/main.js"]');
    if (script && script.src) {
      const p = new URL(script.src, window.location.origin).pathname;
      const segs = p.split('/').filter(Boolean);
      if (segs.length && segs[0] !== 'assets') {
        appRoot = '/' + segs[0];
      } else {
        appRoot = '';
      }
    } else {
      const segs = window.location.pathname.split('/').filter(Boolean);
      appRoot = segs.length ? ('/' + segs[0]) : '';
    }
  } catch (e) {
    const segs = window.location.pathname.split('/').filter(Boolean);
    appRoot = segs.length ? ('/' + segs[0]) : '';
  }
  // Expose for debugging: show computed app root in console
  try { console.debug('appRoot computed:', appRoot); } catch(e) {}

  function currentPortalNotificationsUrl() {
    const p = String(window.location.pathname || '').toLowerCase();
    if (p.includes('/admin/')) return appRoot + '/admin/admin.php?page=notifications';
    if (p.includes('/doctor/')) return appRoot + '/doctor/doctor.php?page=notifications';
    if (p.includes('/secretary/')) return appRoot + '/secretary/secretary.php?page=notifications';
    if (p.includes('/patient/')) return appRoot + '/patient/patient.php?page=notifications';
    return '#';
  }

  // Normalize table badge colors: only status badges keep accent color.
  const statusBadgeTerms = [
    'pending',
    'confirmed',
    'checked in',
    'checked-in',
    'in progress',
    'in-progress',
    'completed',
    'cancelled',
    'canceled',
    'no show',
    'no-show',
    'approved',
    'rejected',
    'active',
    'inactive',
    'scheduled',
    'rescheduled',
    'followup',
    'follow-up',
    'done',
    'arrived',
    'ready for doctor',
    'in queue',
    'queued'
  ];

  function looksLikeStatusBadge(text) {
    const normalized = String(text || '').toLowerCase().replace(/\s+/g, ' ').trim();
    if (!normalized) return false;
    return statusBadgeTerms.some(term => normalized === term || normalized.includes(term));
  }

  function getStatusColumnIndexes(tableEl) {
    const indexes = [];
    if (!(tableEl instanceof HTMLElement)) return indexes;
    const headers = tableEl.querySelectorAll('thead th');
    headers.forEach((th, idx) => {
      const headerText = String(th.textContent || '').toLowerCase().replace(/\s+/g, ' ').trim();
      if (headerText.includes('status')) {
        indexes.push(idx);
      }
    });
    return indexes;
  }

  function normalizeTableBadges() {
    const tables = document.querySelectorAll('#main table, #main .table');
    tables.forEach((table) => {
      if (!(table instanceof HTMLElement)) return;
      const statusColumns = getStatusColumnIndexes(table);
      const badges = table.querySelectorAll('tbody td .badge');

      badges.forEach((badge) => {
        if (!(badge instanceof HTMLElement)) return;
        const td = badge.closest('td');
        const tr = td ? td.parentElement : null;
        let keepByStatusColumn = false;

        if (td && tr && statusColumns.length) {
          const rowCells = Array.from(tr.children).filter((el) => el instanceof HTMLTableCellElement);
          const colIdx = rowCells.indexOf(td);
          keepByStatusColumn = colIdx >= 0 && statusColumns.includes(colIdx);
        }

        const manualStatus = badge.classList.contains('status-badge') || badge.getAttribute('data-status-badge') === '1';
        const keepStatusColor = keepByStatusColumn || manualStatus || looksLikeStatusBadge(badge.textContent || '');
        badge.classList.toggle('table-badge-status', keepStatusColor);
        badge.classList.toggle('table-badge-neutral', !keepStatusColor);
      });
    });
  }

  let badgeNormalizeTimer = null;
  const queueBadgeNormalization = () => {
    if (badgeNormalizeTimer) clearTimeout(badgeNormalizeTimer);
    badgeNormalizeTimer = setTimeout(normalizeTableBadges, 60);
  };

  window.addEventListener('load', queueBadgeNormalization);
  document.addEventListener('DOMContentLoaded', queueBadgeNormalization);
  document.addEventListener('DOMContentLoaded', () => convertInlineAlertsToToasts(document));

  const mainRoot = document.getElementById('main');
  if (mainRoot && typeof MutationObserver !== 'undefined') {
    new MutationObserver(() => {
      queueBadgeNormalization();
      convertInlineAlertsToToasts(mainRoot);
      initUnifiedTableSearch(mainRoot);
    }).observe(mainRoot, {
      childList: true,
      subtree: true
    });
  }
  
  window.updateNotificationBadge = function (unread) {
    const nb = document.getElementById('navNotificationBadge');
    const subtitle = document.getElementById('navNotificationSubtitle');
    const count = parseInt(unread, 10) || 0;
    if (!nb) return;
    if (count <= 0) {
      nb.hidden = true;
      nb.setAttribute('aria-hidden', 'true');
      nb.textContent = '0';
      nb.classList.remove('has-count');
      if (subtitle) subtitle.textContent = 'You are all caught up';
      return;
    }
    nb.hidden = false;
    nb.setAttribute('aria-hidden', 'false');
    nb.textContent = count > 9 ? '9+' : String(count);
    nb.classList.add('has-count');
    if (subtitle) {
      subtitle.textContent = count === 1 ? '1 unread notification' : count + ' unread notifications';
    }
  };

  window.markAllNotificationsRead = async function () {
    try {
      await fetch(appRoot + '/assets/api/notifications/mark_all_read.php', { method: 'POST' });
    } catch (e) {
      // silent
    }
    window.updateNotificationBadge(0);
    await fetchNotifications();
  };

  async function fetchNotifications() {
    try {
      const resp = await fetch(appRoot + '/assets/api/notifications/get_notifications.php', { cache: 'no-store' });
      if (!resp.ok) return;
      const j = await resp.json();
      if (!j.success) return;

      function escapeHtml(value) {
        return String(value || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');
      }

      function highlightDateTimes(value) {
        const escaped = escapeHtml(value);
        const dateTimeRegex = /(\b\d{4}-\d{2}-\d{2}(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?\b|\b\d{1,2}:\d{2}(?::\d{2})?\s?(?:AM|PM|am|pm)?\b|\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*\s+\d{1,2},?\s+\d{4}\b)/g;
        return escaped.replace(dateTimeRegex, '<span class="notification-datetime">$1</span>');
      }

      const unread = parseInt(j.unread || 0, 10) || 0;
      window.updateNotificationBadge(unread);

      const list = document.getElementById('navNotificationList');
      const emptyEl = document.getElementById('navNotificationEmpty');
      if (list) {
        list.innerHTML = '';
        const rows = (j.notifications || []).slice(0, 8);
        if (!rows.length) {
          if (emptyEl) emptyEl.hidden = false;
        } else {
          if (emptyEl) emptyEl.hidden = true;
          rows.forEach((n) => {
            const rawLink = String(n.link || '').trim();
            const hasValidRawLink = rawLink !== '' && rawLink !== '#' && !/^javascript:/i.test(rawLink);
            const fallbackLink = currentPortalNotificationsUrl();
            const isNavigable = hasValidRawLink || fallbackLink !== '#';
            let resolvedHref = fallbackLink !== '#' ? fallbackLink : '#';
            if (resolvedHref === '#' && hasValidRawLink) {
              if (/^https?:\/\//i.test(rawLink)) {
                resolvedHref = rawLink;
              } else if (rawLink.startsWith('/')) {
                resolvedHref = rawLink.startsWith(appRoot + '/') ? rawLink : (appRoot + rawLink);
              } else {
                resolvedHref = rawLink;
              }
            }

            const isUnread = String(n.is_read) === '0' || n.is_read === 0 || n.is_read === false;
            const item = document.createElement(isNavigable ? 'a' : 'div');
            if (isNavigable) item.href = resolvedHref;
            item.className = 'notification-dropdown-item' + (isUnread ? ' is-unread' : '');
            item.dataset.notificationId = n.notification_id || '';

            const dot = document.createElement('span');
            dot.className = 'notification-dropdown-item__dot';
            dot.setAttribute('aria-hidden', 'true');

            const content = document.createElement('div');
            content.className = 'notification-dropdown-item__content';
            const titleHtml = escapeHtml(n.title || 'Notification');
            const bodyHtml = highlightDateTimes(n.body || '');
            content.innerHTML = '<div class="notification-dropdown-item__title">' + titleHtml + '</div><div class="notification-dropdown-item__body">' + bodyHtml + '</div>';

            const time = document.createElement('small');
            time.className = 'notification-time';
            time.textContent = n.created_at ? new Date(n.created_at).toLocaleString() : '';

            item.appendChild(dot);
            item.appendChild(content);
            item.appendChild(time);

            if (isNavigable) {
              item.addEventListener('click', async function (e) {
                e.preventDefault();
                const nid = this.dataset.notificationId;
                if (nid) {
                  try {
                    await fetch(appRoot + '/assets/api/notifications/mark_read.php', {
                      method: 'POST',
                      body: new URLSearchParams({ notification_id: nid })
                    });
                  } catch (err) {
                    // silent
                  }
                }
                const href = this.getAttribute('href') || '#';
                if (href && href !== '#') window.location.href = href;
              });
            }

            list.appendChild(item);
          });
        }
      }
    } catch (e) {
      // silent
    }
  }

  function initPatientHeaderSearch() {
    if (!document.body.classList.contains('patient-portal')) return;

    const header = document.getElementById('header');
    if (!header) return;

    const toggle = header.querySelector('.header-search-toggle');
    const wrap = header.querySelector('.header-search-wrap');
    const input = header.querySelector('.header-search-input, .unified-table-search-input');
    const searchHost = header.querySelector('.header-search');
    if (!toggle || !wrap || !searchHost) return;

    const syncLayout = function () {
      if (window.innerWidth > 991) {
        header.classList.remove('header-search-open');
        toggle.setAttribute('aria-expanded', 'false');
        header.style.removeProperty('--patient-search-expand-width');
        return;
      }
      const headerRect = header.getBoundingClientRect();
      const hostRect = searchHost.getBoundingClientRect();
      const expandWidth = Math.max(140, hostRect.right - headerRect.left - 8);
      header.style.setProperty('--patient-search-expand-width', expandWidth + 'px');
    };

    const closeSearch = function () {
      header.classList.remove('header-search-open');
      toggle.setAttribute('aria-expanded', 'false');
      if (input) input.blur();
      const menu = wrap.querySelector('.search-suggest-menu');
      if (menu) menu.style.display = 'none';
    };

    const openSearch = function () {
      if (window.innerWidth > 991) return;
      syncLayout();
      header.classList.add('header-search-open');
      toggle.setAttribute('aria-expanded', 'true');
      if (input) {
        requestAnimationFrame(function () {
          input.focus();
        });
      }
    };

    toggle.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (window.innerWidth > 991) return;
      if (header.classList.contains('header-search-open')) return;
      openSearch();
    });

    wrap.addEventListener('click', function (e) {
      e.stopPropagation();
    });

    document.addEventListener('click', function (e) {
      if (window.innerWidth > 991) return;
      if (!header.classList.contains('header-search-open')) return;
      if (e.target.closest('#header .header-search')) return;
      closeSearch();
    });

    if (input) {
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && header.classList.contains('header-search-open')) {
          closeSearch();
        }
      });
    }

    window.addEventListener('resize', syncLayout);
    syncLayout();
  }

  function initNotificationDropdown() {
    const toggle = document.getElementById('navNotificationsToggle');
    const markAllBtn = document.getElementById('navMarkAllRead');

    if (toggle) {
      toggle.addEventListener('show.bs.dropdown', function () {
        if (typeof window.markAllNotificationsRead === 'function') {
          window.markAllNotificationsRead();
        }
      });
    }

    if (markAllBtn) {
      markAllBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (typeof window.markAllNotificationsRead === 'function') {
          window.markAllNotificationsRead();
        }
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    initPatientHeaderSearch();
    initNotificationDropdown();
  });
  // start polling notifications every 30s
  setInterval(fetchNotifications, 30000);
  // initial
  fetchNotifications();

})();