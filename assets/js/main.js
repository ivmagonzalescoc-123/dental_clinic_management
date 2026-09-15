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

  /**
   * Header clock — updates the digital display (no seconds) and, when the
   * analog popup is open, its clock hands, once per second.
   */
  function updateHeaderClock() {
    var display = select('#headerClockDisplay')
    var icon = select('.header-clock__icon')
    var now = new Date()
    var h = now.getHours()
    var m = now.getMinutes()
    var s = now.getSeconds()
    var ampm = h >= 12 ? 'PM' : 'AM'
    var h12 = h % 12 || 12
    if (display) {
      display.textContent = h12 + ':' + (m < 10 ? '0' : '') + m + ' ' + ampm
    }
    if (icon) {
      var isNight = h < 6 || h >= 18
      icon.className = 'header-clock__icon bi ' + (isNight ? 'bi-moon-stars' : 'bi-sun')
    }

    var hourHand = select('#analogClockHour')
    var minuteHand = select('#analogClockMinute')
    var secondHand = select('#analogClockSecond')
    if (hourHand && minuteHand && secondHand) {
      var hourDeg = ((h % 12) + m / 60) * 30
      var minuteDeg = (m + s / 60) * 6
      var secondDeg = s * 6
      hourHand.style.transform = 'rotate(' + hourDeg + 'deg)'
      minuteHand.style.transform = 'rotate(' + minuteDeg + 'deg)'
      secondHand.style.transform = 'rotate(' + secondDeg + 'deg)'
    }
  }
  updateHeaderClock()
  setInterval(updateHeaderClock, 1000)

  /**
   * Analog clock popup — opens on clicking the header clock, closes on the
   * X button or on clicking outside the clock face.
   */
  function initAnalogClockPopup() {
    var trigger = select('#headerClockTrigger')
    var overlay = select('#analogClockOverlay')
    var closeBtn = select('#analogClockClose')
    if (!trigger || !overlay || !closeBtn) return

    // #header uses backdrop-filter, which makes it a containing block for
    // fixed-position descendants — without this, "inset:0" would center the
    // popup inside the 60px header bar instead of the actual viewport.
    // Re-parent to <body> so position:fixed is relative to the viewport.
    document.body.appendChild(overlay)

    // Second/minute tick marks around the rim — 60 dashes, every 5th one
    // (the hour positions) drawn longer/darker.
    var face = select('.analog-clock-face')
    if (face && !face.querySelector('.analog-clock-tick')) {
      for (var i = 0; i < 60; i++) {
        var tick = document.createElement('div')
        tick.className = 'analog-clock-tick' + (i % 5 === 0 ? ' analog-clock-tick--major' : '')
        tick.style.transform = 'rotate(' + (i * 6) + 'deg)'
        face.appendChild(tick)
      }
    }

    function openPopup() {
      overlay.classList.add('show')
      overlay.setAttribute('aria-hidden', 'false')
      trigger.setAttribute('aria-expanded', 'true')
      updateHeaderClock()
    }

    function closePopup() {
      overlay.classList.remove('show')
      overlay.setAttribute('aria-hidden', 'true')
      trigger.setAttribute('aria-expanded', 'false')
    }

    trigger.addEventListener('click', function () {
      if (overlay.classList.contains('show')) {
        closePopup()
      } else {
        openPopup()
      }
    })

    closeBtn.addEventListener('click', closePopup)

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closePopup()
    })

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && overlay.classList.contains('show')) closePopup()
    })
  }
  initAnalogClockPopup()

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
      if (message && typeof window.showAppToast === 'function') window.showAppToast(message, type)
      alertEl.remove()
    })
  }

  /**
   * Sidebar toggle
   */
  var body = select('body')
  if (body && window.innerWidth > 991) {
    var saved = localStorage.getItem('sidebar-collapsed')
    if (saved === 'true') {
      body.classList.add('toggle-sidebar')
    }
  }

  if (select('.toggle-sidebar-btn')) {
    on('click', '.toggle-sidebar-btn', function(e) {
      var body = select('body')
      if (body && body.classList.contains('patient-portal') && window.innerWidth <= 991) {
        e.preventDefault()
        return
      }
      body.classList.toggle('toggle-sidebar')
      if (window.innerWidth > 991) {
        localStorage.setItem('sidebar-collapsed', body.classList.contains('toggle-sidebar'))
      }
    })
  }

  if (select('.mobile-sidebar-toggle')) {
    on('click', '.mobile-sidebar-toggle', function(e) {
      var body = select('body')
      if (!body || body.classList.contains('patient-portal')) return
      body.classList.toggle('toggle-sidebar')
    })
  }

  document.addEventListener('click', function (e) {
    if (window.innerWidth > 991) return
    var body = select('body')
    if (!body || body.classList.contains('patient-portal')) return
    if (!body.classList.contains('toggle-sidebar')) return
    if (e.target.closest('#sidebar') || e.target.closest('.toggle-sidebar-btn') || e.target.closest('.mobile-sidebar-toggle')) return
    body.classList.remove('toggle-sidebar')
  })

  /**
   * AJAX sidebar navigation (desktop only)
   */
  ;(function() {
    var isNavigating = false
    var currentAbort = null
    var navToken = 0

    /**
     * Persistent body guard: removes any element appended to document.body
     * that isn't part of the known page structure. This prevents orphaned
     * elements (Flatpickr calendars, FullCalendar widgets, echarts tooltips,
     * etc.) from accumulating below the footer after AJAX page switches.
     */
    function isRecognizedBodyChild(el) {
      if (!el || el.nodeType !== 1) return true
      var tag = el.tagName
      if (tag === 'HEADER' || tag === 'MAIN' || tag === 'ASIDE' ||
          tag === 'SCRIPT' || tag === 'STYLE' || tag === 'LINK' ||
          tag === 'META' || tag === 'NOSCRIPT') return true
      var id = el.id || ''
      if (id === 'header' || id === 'main' || id === 'sidebar' ||
          id === 'appToastHost' || id === 'appConfirmModal' ||
          id === 'mediaLightboxModal' || id === 'idleToastContainer') return true
      var cl = el.classList
      if (cl.contains('footer') || cl.contains('back-to-top') ||
          cl.contains('app-page-loading') || cl.contains('modal-backdrop') ||
          cl.contains('modal') || cl.contains('app-toast-host') ||
          cl.contains('toast-container')) return true
      return false
    }

    var bodyGuard = new MutationObserver(function(mutations) {
      for (var i = 0; i < mutations.length; i++) {
        var nodes = mutations[i].addedNodes
        for (var j = 0; j < nodes.length; j++) {
          var node = nodes[j]
          if (node.nodeType === 1 && !isRecognizedBodyChild(node)) {
            node.remove()
          }
        }
      }
    })
    bodyGuard.observe(document.body, { childList: true })

    function getPortalFile() {
      var path = window.location.pathname
      var m = path.match(/\/(admin|doctor|secretary|patient)\.php/)
      return m ? m[1] + '.php' : null
    }

    function updateActiveNav(page) {
      var navLinks = document.querySelectorAll('.sidebar-nav .nav-link')
      navLinks.forEach(function(link) {
        link.classList.remove('active')
        var href = link.getAttribute('href') || ''
        if (href.includes('page=' + page)) {
          link.classList.add('active')
        }
      })
    }

    window.updateActiveNav = updateActiveNav

    function reExecuteScripts(container) {
      var extScripts = Array.from(container.querySelectorAll('script[src]'))
      var inlineScripts = Array.from(container.querySelectorAll('script:not([src])'))

      function runInline() {
        if (window.FullCalendar && !FullCalendar.__fcPatched) {
          window.__fcInstances = window.__fcInstances || []
          var _OrigCalendar = FullCalendar.Calendar
          FullCalendar.Calendar = function(el, opts) {
            var inst = new _OrigCalendar(el, opts)
            window.__fcInstances.push(inst)
            return inst
          }
          FullCalendar.Calendar.prototype = _OrigCalendar.prototype
          FullCalendar.__fcPatched = true
        }

        var origAddEL = EventTarget.prototype.addEventListener
        var dcQueue = []
        EventTarget.prototype.addEventListener = function(type, listener, opts) {
          if (this === document && type === 'DOMContentLoaded') {
            dcQueue.push(listener)
            return
          }
          return origAddEL.call(this, type, listener, opts)
        }

        inlineScripts.forEach(function(old) {
          try { ;(0, eval)(old.textContent) } catch(e) { console.error('[ajax script]', e) }
        })

        EventTarget.prototype.addEventListener = origAddEL

        dcQueue.forEach(function(cb) {
          Promise.resolve().then(function() {
            try { cb() } catch (e) { console.error('[ajax dcl]', e) }
          })
        })
      }

      function resolveScriptUrl(raw) {
        try { return new URL(raw, window.location.href).href } catch(e) { return raw }
      }

      var pending = extScripts.filter(function(el) {
        var src = el.getAttribute('src')
        if (!src) return false
        var resolved = resolveScriptUrl(src)
        return !Array.from(document.querySelectorAll('script[src]')).some(function(s) {
          if (container.contains(s)) return false
          var existingSrc = s.getAttribute('src') || s.src
          return resolveScriptUrl(existingSrc) === resolved
        })
      })

      if (!pending.length) {
        runInline()
        return
      }

      pending.reduce(function(chain, el) {
        return chain.then(function() {
          return new Promise(function(resolve) {
            var s = document.createElement('script')
            s.src = el.getAttribute('src')
            s.onload = resolve
            s.onerror = resolve
            document.head.appendChild(s)
          })
        })
      }, Promise.resolve()).then(runInline)
    }

    var portalFile = getPortalFile()
    if (!portalFile) return

    function isSamePortal(href) {
      if (!href || href === '#' || href.indexOf('javascript:') === 0) return false
      if (href.indexOf('http://') === 0 || href.indexOf('https://') === 0) return false
      if (href.indexOf(portalFile) !== -1) return true
      if (href.indexOf('?') === 0 || href.indexOf('./') === 0 || href.indexOf('../') === 0) return true
      return false
    }

    var pageLoadingSafetyTimer = null
    function setPageLoading(active) {
      var overlay = document.getElementById('globalLoadingOverlay')
      if (!overlay) {
        // Create overlay if it doesn't exist
        overlay = document.createElement('div')
        overlay.id = 'globalLoadingOverlay'
        overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;pointer-events:none;'
        overlay.innerHTML = '<div style="width:36px;height:36px;border:3px solid #e2e8f0;border-top-color:#1d6fd8;border-radius:50%;animation:spin 0.8s linear infinite;"></div>'
        document.body.appendChild(overlay)
      }
      overlay.style.display = active ? 'flex' : 'none'

      // Safety net: if the fetch this was shown for stalls (dropped
      // connection, unresponsive server) and never reaches its .then/.catch,
      // don't leave the spinner on screen forever.
      if (pageLoadingSafetyTimer) clearTimeout(pageLoadingSafetyTimer)
      if (active) {
        pageLoadingSafetyTimer = setTimeout(function () { overlay.style.display = 'none' }, 8000)
      }
    }

    function cleanupModalState() {
      if (window.__fcInstances && window.__fcInstances.length) {
        window.__fcInstances.forEach(function(cal) {
          try { cal.destroy() } catch(e) {}
        })
        window.__fcInstances = []
      }
      destroyAllFlatpickrs()

      if (window.bootstrap) {
        document.querySelectorAll('.modal.show').forEach(function(el) {
          var inst = bootstrap.Modal.getInstance(el)
          if (inst) inst.hide()
        })
      }
      document.querySelectorAll('.modal-backdrop').forEach(function(el) { el.remove() })
      document.body.classList.remove('modal-open')
      document.body.style.removeProperty('overflow')
      document.body.style.removeProperty('padding-right')
    }

    function destroyAllFullCalendars() {
      // Destroy tracked instances
      if (window.__fcInstances && window.__fcInstances.length) {
        window.__fcInstances.forEach(function(cal) {
          try { cal.destroy() } catch(e) {}
        })
        window.__fcInstances = []
      }
      // Aggressively find and remove any leftover FullCalendar DOM
      document.querySelectorAll('#main .fc, #main .fc-root, #main [data-fullcalendar]').forEach(function(el) {
        try { el.remove() } catch(e) {}
      })
    }

    function destroyAllFlatpickrs() {
      // flatpickr appends its calendar popup to document.body by default, not inside
      // #main, so swapping #main's innerHTML alone leaves orphaned, unstyled calendar
      // popups behind (the module's own <link>/<script> for flatpickr gets destroyed
      // along with the old #main content, but the popup element on body survives).
      // Destroy every tracked instance still bound to an input inside #main first...
      document.querySelectorAll('#main input, #main select').forEach(function(el) {
        if (el._flatpickr) {
          try { el._flatpickr.destroy() } catch(e) {}
        }
      })
      // ...then sweep for any stray popup nodes left on body regardless of source.
      document.querySelectorAll('body > .flatpickr-calendar').forEach(function(el) {
        try { el.remove() } catch(e) {}
      })
    }

    function ajaxNavigate(url) {
      if (currentAbort) {
        currentAbort.abort()
      }
      var myToken = ++navToken
      currentAbort = new AbortController()
      isNavigating = true
      cleanupModalState()
      setPageLoading(true)

      fetch(url, { credentials: 'same-origin', signal: currentAbort.signal })
        .then(function(r) { return r.text() })
        .then(function(html) {
          // A newer navigation started after this fetch began (and may have already
          // resolved by the time abort() was called) — discard this stale response
          // instead of letting it overwrite what the newer click just loaded.
          if (myToken !== navToken) return

          setPageLoading(false)
          currentAbort = null

          // Destroy all FullCalendar/flatpickr instances BEFORE swapping DOM
          destroyAllFullCalendars()
          destroyAllFlatpickrs()

          var parser = new DOMParser()
          var doc = parser.parseFromString(html, 'text/html')
          var newMain = doc.querySelector('#main')
          var currentMain = document.querySelector('#main')

          if (newMain && currentMain) {
            currentMain.innerHTML = newMain.innerHTML
            reExecuteScripts(currentMain)
          }

          var params = new URLSearchParams(url.split('?')[1] || '')
          var page = params.get('page') || 'dashboard'
          updateActiveNav(page)

          history.pushState({ page: page }, '', url)
          document.documentElement.scrollTop = 0
          document.body.scrollTop = 0
          isNavigating = false
          window.dispatchEvent(new CustomEvent('page-loaded', { detail: { page: page } }))
        })
        .catch(function(err) {
          if (err.name === 'AbortError') return
          if (myToken !== navToken) return
          setPageLoading(false)
          destroyAllFullCalendars()
          destroyAllFlatpickrs()
          cleanupModalState()
          window.location.href = url
          isNavigating = false
        })
    }

    window.ajaxNavigate = ajaxNavigate

    document.addEventListener('click', function(e) {
      if (window.innerWidth <= 991) return

      var link = e.target.closest('a')
      if (!link) return
      if (link.target === '_blank') return
      if (link.hasAttribute('data-no-ajax')) return
      if (link.hasAttribute('download')) return

      var href = link.getAttribute('href')
      if (!isSamePortal(href)) return

      e.preventDefault()

      var fullUrl
      if (href.indexOf('?') === 0 || href.indexOf('./') === 0 || href.indexOf('../') === 0) {
        fullUrl = new URL(href, window.location.href).href
      } else {
        fullUrl = href
      }

      ajaxNavigate(fullUrl)
    })

    document.addEventListener('submit', function(e) {
      if (window.innerWidth <= 991) return
      var form = e.target
      if (form.method && form.method.toUpperCase() === 'POST') return
      if (form.hasAttribute('data-no-ajax')) return

      var action = form.getAttribute('action') || ''
      if (!isSamePortal(action) && action !== '') return

      e.preventDefault()

      var formData = new FormData(form)
      var params = new URLSearchParams(formData).toString()
      var baseUrl = action || window.location.pathname
      var url = baseUrl + (params ? '?' + params : '')

      ajaxNavigate(url)
    })

    window.addEventListener('popstate', function() {
      if (window.innerWidth <= 991) return
      location.reload()
    })
  })()

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

  const datatables = select('.datatable:not([data-skip-datatable]):not([data-skip-table-search])', true)
  datatables.forEach(datatable => {
    if (datatable.dataset.skipDatatable === '1' || datatable.dataset.skipTableSearch === '1') return
    try {
      // Column-specific options only make sense if the table actually has
      // that many columns — a table with fewer columns (e.g. a trimmed-down
      // admin table) would otherwise make simpleDatatables throw on an
      // out-of-range column index. Since this runs at top-level script
      // execution, an uncaught throw here previously killed the rest of
      // main.js for the whole page (including unrelated things like the
      // loading-overlay cleanup) — so this is now both defensive about the
      // column count AND wrapped in try/catch as a second line of defense.
      const headerCellCount = datatable.querySelectorAll('thead th').length
      const columns = []
      if (headerCellCount > 2) columns.push({ select: 2, sortSequence: ["desc", "asc"] })
      if (headerCellCount > 3) columns.push({ select: 3, sortSequence: ["desc"] })
      if (headerCellCount > 4) columns.push({ select: 4, cellClass: "green", headerClass: "red" })

      new simpleDatatables.DataTable(datatable, {
        perPageSelect: [5, 10, 15, ["All", -1]],
        columns: columns
      })
    } catch (e) {
      console.error('DataTable init failed for', datatable, e)
    }
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

    let filterRowsTimer = null
    searchInput.addEventListener('input', () => {
      clearTimeout(filterRowsTimer)
      filterRowsTimer = setTimeout(filterRows, 300)
    })
    clearBtn.addEventListener('click', () => {
      clearTimeout(filterRowsTimer)
      searchInput.value = ''
      filterRows()
      searchInput.focus()
    })
    searchInput.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        clearTimeout(filterRowsTimer)
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

  // Delays a native 'input' event without needing any access to whatever
  // library/listener is reacting to it: real keystrokes are intercepted in
  // the capture phase and held back; once the user pauses, a synthetic
  // 'input' event is re-dispatched so the original (e.g. simpleDatatables')
  // listener runs exactly as it always did, just later. isTrusted lets the
  // handler tell a real keystroke apart from its own synthetic re-dispatch
  // so it doesn't re-intercept and loop forever.
  function debounceNativeInputEvent(inputEl, delay) {
    if (!inputEl || inputEl.dataset.debounceBound === '1') return
    inputEl.dataset.debounceBound = '1'
    let timer = null
    inputEl.addEventListener('input', (e) => {
      if (!e.isTrusted) return
      e.stopImmediatePropagation()
      clearTimeout(timer)
      timer = setTimeout(() => {
        inputEl.dispatchEvent(new Event('input', { bubbles: true }))
      }, delay || 300)
    }, true)
  }

  function styleDatatableSearchInputs() {
    document.querySelectorAll('.datatable-wrapper .datatable-input').forEach((input) => {
      debounceNativeInputEvent(input, 300)
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

  function resolveNotificationHref(rawLink) {
    const link = String(rawLink || '').trim();
    if (link === '' || link === '#' || /^javascript:/i.test(link)) {
      return null;
    }
    if (/^https?:\/\//i.test(link)) {
      return link;
    }
    if (link.startsWith('/')) {
      const root = String(appRoot || '').replace(/\/$/, '');
      if (root && link.startsWith(root + '/')) {
        return link;
      }
      return root ? (root + link) : link;
    }
    try {
      return new URL(link, window.location.href).href;
    } catch (err) {
      return link;
    }
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
  if (typeof MutationObserver !== 'undefined') {
    const observeTargets = [mainRoot, document.body].filter(Boolean);
    const observer = new MutationObserver(() => {
      queueBadgeNormalization();
      observeTargets.forEach((target) => convertInlineAlertsToToasts(target));
      if (mainRoot) initUnifiedTableSearch(mainRoot);
    });
    observeTargets.forEach((target) => {
      observer.observe(target, { childList: true, subtree: true });
    });
  }
  
  window.updateNotificationBadge = function (unread) {
    const nb = document.getElementById('navNotificationBadge');
    const count = parseInt(unread, 10) || 0;
    if (!nb) return;
    if (count <= 0) {
      nb.hidden = true;
      nb.setAttribute('aria-hidden', 'true');
      nb.textContent = '0';
      nb.classList.remove('has-count');
      return;
    }
    nb.hidden = false;
    nb.setAttribute('aria-hidden', 'false');
    nb.textContent = count > 9 ? '9+' : String(count);
    nb.classList.add('has-count');
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

  function relativeTime(dateStr) {
    if (!dateStr) return '';
    const now = Date.now();
    const then = new Date(dateStr).getTime();
    if (isNaN(then)) return '';
    const diff = Math.floor((now - then) / 1000);
    if (diff < 0) return 'just now';
    if (diff < 5) return 'just now';
    if (diff < 60) return diff + 's ago';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
    return new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  }

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

      function getNotifIcon(title, body) {
        const text = ((title || '') + ' ' + (body || '')).toLowerCase();
        if (text.includes('new patient')) return '<i class="bi bi-person-plus"></i>';
        if (text.includes('appointment') || text.includes('scheduled') || text.includes('confirmed')) return '<i class="bi bi-calendar-check"></i>';
        if (text.includes('arrived') || text.includes('checked in') || text.includes('patient arrived')) return '<i class="bi bi-person-check"></i>';
        if (text.includes('reschedule')) return '<i class="bi bi-calendar2-week"></i>';
        if (text.includes('decline') || text.includes('cancel')) return '<i class="bi bi-x-circle"></i>';
        if (text.includes('record') || text.includes('clinical')) return '<i class="bi bi-clipboard2-pulse"></i>';
        if (text.includes('bill') || text.includes('payment') || text.includes('charge')) return '<i class="bi bi-receipt"></i>';
        if (text.includes('message') || text.includes('contact')) return '<i class="bi bi-chat-dots"></i>';
        if (text.includes('leave') || text.includes('schedule')) return '<i class="bi bi-calendar-x"></i>';
        return '<i class="bi bi-bell"></i>';
      }

      function simplifyMessage(text) {
        if (!text) return '';
        let t = text.trim();
        t = t.replace(/\s*on\s+\d{4}-\d{2}-\d{2}.*/i, '');
        t = t.replace(/\s*—\s*\d{4}-\d{2}-\d{2}.*/i, '');
        t = t.replace(/\s*\(.*?\)\s*$/i, '');
        if (t.length > 120) t = t.slice(0, 117) + '...';
        return t;
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
            const resolvedHref = resolveNotificationHref(n.link);
            const isNavigable = resolvedHref !== null;

            const isUnread = String(n.is_read) === '0' || n.is_read === 0 || n.is_read === false;
            const item = document.createElement(isNavigable ? 'a' : 'button');
            item.type = isNavigable ? undefined : 'button';
            if (isNavigable) item.href = resolvedHref;
            item.className = 'notification-dropdown-item' + (isUnread ? ' is-unread' : '');
            item.dataset.notificationId = n.notification_id || '';

            const icon = document.createElement('div');
            icon.className = 'notification-dropdown-item__icon';
            icon.innerHTML = getNotifIcon(n.title, n.body);

            const body = document.createElement('div');
            body.className = 'notification-dropdown-item__body';
            const message = escapeHtml(simplifyMessage(n.body || n.title || 'Notification'));
            const timeStr = relativeTime(n.created_at);
            body.innerHTML = '<div class="notification-dropdown-item__message">' + message + '</div>' +
                (timeStr ? '<div class="notification-dropdown-item__time" data-created-at="' + escapeHtml(n.created_at) + '">' + timeStr + '</div>' : '');

            item.appendChild(icon);
            item.appendChild(body);

            item.addEventListener('click', async function (e) {
              e.preventDefault();
              e.stopPropagation();
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
              if (typeof window.closePortalHeaderDropdowns === 'function') {
                window.closePortalHeaderDropdowns();
              }
              if (isNavigable && resolvedHref) {
                window.location.href = resolvedHref;
                return;
              }
              await fetchNotifications();
            });

            list.appendChild(item);
          });
        }
      }
    } catch (e) {
      // silent
    }
  }

  function isPortalBody() {
    return document.body.classList.contains('patient-portal')
      || document.body.classList.contains('doctor-portal')
      || document.body.classList.contains('secretary-portal')
      || document.body.classList.contains('admin-portal');
  }

  function initPortalHeaderSearch() {
    if (!isPortalBody()) return;

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
        header.style.removeProperty('--portal-search-left');
        header.style.removeProperty('--portal-search-right');
        return;
      }

      const logoZone = header.querySelector(':scope > .d-flex.align-items-center');
      const navEl = header.querySelector('.header-nav');
      if (!logoZone || !navEl) return;

      const headerRect = header.getBoundingClientRect();
      const logoRect = logoZone.getBoundingClientRect();
      const navRect = navEl.getBoundingClientRect();

      const leftInset = Math.max(0, Math.round(logoRect.right - headerRect.left + 8));
      const rightInset = Math.max(0, Math.round(headerRect.right - navRect.left + 4));

      header.style.setProperty('--portal-search-left', leftInset + 'px');
      header.style.setProperty('--portal-search-right', rightInset + 'px');
    };

    const closeSearch = function () {
      header.classList.remove('header-search-open');
      toggle.setAttribute('aria-expanded', 'false');
      if (input) input.blur();
      const menu = wrap.querySelector('.search-suggest-menu');
      if (menu) menu.style.display = 'none';
    };

    window.closePortalHeaderSearch = closeSearch;

    const openSearch = function () {
      if (window.innerWidth > 991) return;
      if (typeof window.closePortalHeaderDropdowns === 'function') {
        window.closePortalHeaderDropdowns();
      }
      syncLayout();
      header.classList.add('header-search-open');
      toggle.setAttribute('aria-expanded', 'true');
      if (input) {
        requestAnimationFrame(function () {
          input.focus();
        });
      }
    };

    const openSearchFromToggle = function (e) {
      if (e) {
        e.preventDefault();
        e.stopPropagation();
      }
      if (window.innerWidth > 991) return;
      if (header.classList.contains('header-search-open')) return;
      openSearch();
    };

    toggle.addEventListener('click', openSearchFromToggle);
    toggle.addEventListener('pointerup', function (e) {
      if (e.pointerType !== 'touch') return;
      openSearchFromToggle(e);
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
          if (typeof window.closePortalHeaderDropdowns === 'function') {
            window.closePortalHeaderDropdowns();
          }
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
      toggle.addEventListener('portaldropdown:open', function () {
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

  function removeGlobalLoadingOverlay() {
    var overlay = document.getElementById('globalLoadingOverlay');
    if (!overlay) return;
    var loadTime = Date.now() - (window.__pageLoadStart || Date.now());
    var minDisplay = 300; // minimum 300ms display
    var remaining = Math.max(0, minDisplay - loadTime);
    setTimeout(function() {
      if (overlay && overlay.parentNode) overlay.remove();
    }, remaining);
  }

  document.addEventListener('DOMContentLoaded', function () {
    // Removed first and unconditionally — if initPortalHeaderSearch() or
    // initNotificationDropdown() below throws, this must still have already
    // run, otherwise the loading spinner is stuck on screen forever.
    removeGlobalLoadingOverlay();
    try { initPortalHeaderSearch(); } catch (e) { console.error(e); }
    try { initNotificationDropdown(); } catch (e) { console.error(e); }
  });

  // Safety net: something upstream of DOMContentLoaded firing cleanly (a
  // parse error earlier in this file, a script that never finishes, etc.)
  // must never leave the overlay on screen indefinitely.
  window.addEventListener('load', removeGlobalLoadingOverlay);
  setTimeout(removeGlobalLoadingOverlay, 4000);
  // Poll notifications every 30s, but only while this tab is visible — a
  // backgrounded/idle tab shouldn't keep hitting the server indefinitely.
  var notifPollTimer = null;
  function startNotifPolling() {
    if (notifPollTimer) return;
    notifPollTimer = setInterval(fetchNotifications, 30000);
  }
  function stopNotifPolling() {
    if (!notifPollTimer) return;
    clearInterval(notifPollTimer);
    notifPollTimer = null;
  }
  document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
      stopNotifPolling();
    } else {
      fetchNotifications();
      startNotifPolling();
    }
  });
  if (!document.hidden) startNotifPolling();
  // update displayed notification times every 5s (cheap, DOM-only — no need to pause)
  setInterval(function() {
    document.querySelectorAll('.notification-dropdown-item__time').forEach(function(el) {
      var created = el.dataset.createdAt;
      if (created) el.textContent = relativeTime(created);
    });
  }, 5000);
  // initial
  fetchNotifications();

})();