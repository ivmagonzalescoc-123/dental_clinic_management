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
      select('body').classList.toggle('toggle-sidebar')
    })
  }

  /**
   * Search bar toggle
   */
  if (select('.search-bar-toggle')) {
    on('click', '.search-bar-toggle', function(e) {
      select('.search-bar').classList.toggle('search-bar-show')
    })
  }

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
  const datatables = select('.datatable', true)
  datatables.forEach(datatable => {
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
    });
  })

  function ensureUnifiedTableSearch(tableEl) {
    if (!(tableEl instanceof HTMLTableElement)) return
    if (tableEl.dataset.unifiedSearchInit === '1') return
    if (tableEl.closest('.fc')) return
    if (tableEl.getAttribute('role') === 'presentation') return

    const tbody = tableEl.tBodies && tableEl.tBodies[0] ? tableEl.tBodies[0] : null
    if (!tbody) return

    const host = tableEl.closest('.table-responsive') || tableEl.parentElement
    if (!host || !host.parentElement) return

    const searchWrap = document.createElement('div')
    searchWrap.className = 'unified-table-search-wrap'

    const searchInput = document.createElement('input')
    searchInput.type = 'search'
    searchInput.className = 'form-control unified-table-search-input'
    searchInput.placeholder = 'Search table...'
    searchInput.setAttribute('aria-label', 'Search table')

    const clearBtn = document.createElement('button')
    clearBtn.type = 'button'
    clearBtn.className = 'unified-table-search-clear'
    clearBtn.setAttribute('aria-label', 'Clear search')
    clearBtn.innerHTML = '&times;'
    clearBtn.style.display = 'none'

    searchWrap.appendChild(searchInput)
    searchWrap.appendChild(clearBtn)

    host.parentElement.insertBefore(searchWrap, host)

    const filterRows = () => {
      const term = String(searchInput.value || '').toLowerCase().trim()
      const rows = Array.from(tbody.querySelectorAll(':scope > tr')).filter((row) => !row.classList.contains('unified-table-no-results'))
      let visibleCount = 0

      rows.forEach((row) => {
        const text = String(row.textContent || '').toLowerCase()
        const match = !term || text.includes(term)
        row.style.display = match ? '' : 'none'
        if (match) visibleCount++
      })

      clearBtn.style.display = term ? '' : 'none'

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

    tableEl.dataset.unifiedSearchInit = '1'
    filterRows()
  }

  function initUnifiedTableSearch(root) {
    const scope = root instanceof HTMLElement ? root : document
    const tables = scope.querySelectorAll('#main table, #main .table-responsive table')
    tables.forEach((table) => ensureUnifiedTableSearch(table))
  }

  window.addEventListener('load', () => initUnifiedTableSearch(document))
  document.addEventListener('DOMContentLoaded', () => initUnifiedTableSearch(document))

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
    const p = window.location.pathname || '';
    const parts = p.split('/').filter(Boolean);
    const file = parts.length ? parts[parts.length - 1].toLowerCase() : '';
    if (['admin.php', 'doctor.php', 'secretary.php', 'patient.php'].includes(file)) {
      return file + '?page=notifications';
    }
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
  
  async function fetchNotifications() {
    try {
      const resp = await fetch(appRoot + '/assets/api/notifications/get_notifications.php', { cache: 'no-store' });
      if (!resp.ok) return;
      const j = await resp.json();
      if (!j.success) return;
      // update badge if present
      const nb = document.getElementById('navNotificationBadge');
      if (nb) {
        const unread = parseInt(j.unread || 0, 10) || 0;
        nb.textContent = unread;
        nb.style.display = unread > 0 ? '' : 'none';
      }
      // optionally populate dropdown list
      const list = document.getElementById('navNotificationList');
      if (list) {
        list.innerHTML = '';
        (j.notifications || []).slice(0,10).forEach(n => {
          const rawLink = String(n.link || '').trim();
          const hasValidRawLink = rawLink !== '' && rawLink !== '#' && !/^javascript:/i.test(rawLink);
          const fallbackLink = currentPortalNotificationsUrl();
          const isNavigable = hasValidRawLink || fallbackLink !== '#';

          let resolvedHref = '#';
          if (hasValidRawLink) {
            if (/^https?:\/\//i.test(rawLink)) {
              resolvedHref = rawLink;
            } else if (rawLink.startsWith('/')) {
              resolvedHref = rawLink.startsWith(appRoot + '/') ? rawLink : (appRoot + rawLink);
            } else {
              resolvedHref = rawLink;
            }
          } else if (fallbackLink !== '#') {
            resolvedHref = fallbackLink;
          }

          const a = document.createElement(isNavigable ? 'a' : 'div');
          if (isNavigable) a.href = resolvedHref;
          a.className = 'dropdown-item d-flex justify-content-between align-items-start';
          a.dataset.notificationId = n.notification_id || '';
          const left = document.createElement('div');
          left.innerHTML = '<div class="fw-bold">' + (n.title || '') + '</div><div class="small text-muted">' + (n.body || '') + '</div>';
          const right = document.createElement('small');
          right.className = 'text-muted';
          right.textContent = new Date(n.created_at).toLocaleString();
          a.appendChild(left);
          a.appendChild(right);
          if (isNavigable) {
            a.addEventListener('click', async function(e){
              // mark read then navigate
              e.preventDefault();
              const nid = this.dataset.notificationId;
              if (nid) {
                try { await fetch(appRoot + '/assets/api/notifications/mark_read.php', { method: 'POST', body: new URLSearchParams({ notification_id: nid }) }); } catch(e){}
              }
              const href = this.getAttribute('href') || '#';
              if (href && href !== '#') window.location.href = href;
            });
          }
          list.appendChild(a);
        });
      }
    } catch (e) {
      // silent
    }
  }
  // start polling notifications every 30s
  setInterval(fetchNotifications, 30000);
  // initial
  fetchNotifications();

})();