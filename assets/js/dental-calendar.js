/**
 * Shared month calendar (styles: assets/css/dental-calendar.css).
 *
 *   var cal = DentalCalendar.create(el, {
 *     month: 'YYYY-MM',                 // initial month (default: current)
 *     minMonth, maxMonth,               // optional nav bounds ('YYYY-MM')
 *     getDay: function (iso, date) {    // per-day content
 *       return { state: 'available'|'unavailable'|'closed'|'leave'|'pending'|'past'|'muted',
 *                tags: [{ text, tone: 'ok'|'info'|'warn'|'danger'|'muted' }],
 *                selectable: true|false, title: 'tooltip / aria text',
 *                mark: tone for the phone-width status dot (default: first tag's tone) }
 *     },
 *     onSelect: function (iso) {},       // selectable day clicked
 *     onBlocked: function (iso) {},      // non-selectable day clicked (optional)
 *     onMonthChange: function (ym) {},   // after navigating (return false to cancel)
 *     selected: 'YYYY-MM-DD',
 *     selectLabel: 'Select',             // shows a "Select" pill on selectable days
 *     overlay: 'message' | null,         // e.g. "Choose a doctor first"
 *     legend: [{ tone, label }]
 *   })
 *   cal.refresh(); cal.setSelected(iso); cal.setOverlay(msg); cal.setMonth(ym); cal.setLoading(bool)
 */
(function () {
  'use strict'

  var WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

  function pad(n) { return (n < 10 ? '0' : '') + n }
  function toIso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) }
  function ymOf(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) }
  function parseYm(ym) {
    var m = /^(\d{4})-(\d{2})$/.exec(String(ym || ''))
    return m ? new Date(+m[1], +m[2] - 1, 1) : null
  }
  function addMonths(ym, delta) {
    var d = parseYm(ym)
    d.setMonth(d.getMonth() + delta)
    return ymOf(d)
  }
  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    })
  }
  var TONES = { ok: 1, info: 1, warn: 1, danger: 1, muted: 1 }
  var STATES = { available: 1, unavailable: 1, closed: 1, leave: 1, pending: 1, past: 1, muted: 1 }

  function Calendar(el, opts) {
    this.el = el
    this.opts = opts || {}
    this.month = parseYm(this.opts.month) ? this.opts.month : ymOf(new Date())
    this.selected = this.opts.selected || null
    this.overlay = this.opts.overlay || null
    this.loading = false
    el.classList.add('dcal')
    // Re-creating a calendar on the same element (e.g. each time a modal opens)
    // replaces the previous one instead of stacking click handlers.
    if (el._dcal) el.removeEventListener('click', el._dcal._onClick)
    this._onClick = this.onClick.bind(this)
    el.addEventListener('click', this._onClick)
    el._dcal = this
    this.render()
  }

  Calendar.prototype.onClick = function (e) {
    var nav = e.target.closest('[data-dcal-nav]')
    if (nav && this.el.contains(nav)) {
      var v = nav.getAttribute('data-dcal-nav')
      this.setMonth(v === 'today' ? ymOf(new Date()) : addMonths(this.month, +v))
      return
    }
    var day = e.target.closest('button.dcal-day')
    if (!day || !this.el.contains(day)) return
    var iso = day.getAttribute('data-date')
    if (day.getAttribute('aria-disabled') === 'true') {
      if (typeof this.opts.onBlocked === 'function') this.opts.onBlocked(iso)
      return
    }
    this.setSelected(iso)
    // render() replaced the clicked button; keep keyboard focus on the chosen day.
    var again = this.el.querySelector('button.dcal-day[data-date="' + iso + '"]')
    if (again) again.focus()
    if (typeof this.opts.onSelect === 'function') this.opts.onSelect(iso)
  }

  Calendar.prototype.inBounds = function (ym) {
    if (this.opts.minMonth && ym < this.opts.minMonth) return false
    if (this.opts.maxMonth && ym > this.opts.maxMonth) return false
    return true
  }

  Calendar.prototype.setMonth = function (ym) {
    if (!parseYm(ym) || !this.inBounds(ym) || ym === this.month) return
    if (typeof this.opts.onMonthChange === 'function' && this.opts.onMonthChange(ym) === false) return
    this.month = ym
    this.render()
  }

  Calendar.prototype.setSelected = function (iso) { this.selected = iso || null; this.render() }
  Calendar.prototype.setOverlay = function (msg) { this.overlay = msg || null; this.render() }
  Calendar.prototype.setLoading = function (on) { this.loading = !!on; this.el.classList.toggle('is-loading', this.loading) }
  Calendar.prototype.refresh = function () { this.render() }

  Calendar.prototype.renderDay = function (date) {
    var iso = toIso(date)
    var info = (typeof this.opts.getDay === 'function' ? this.opts.getDay(iso, date) : null) || {}
    var state = STATES[info.state] ? info.state : 'available'
    var tags = Array.isArray(info.tags) ? info.tags : []
    var selectable = !!info.selectable
    var isToday = iso === toIso(new Date())
    var isSelected = iso === this.selected
    var interactive = selectable || typeof this.opts.onBlocked === 'function'

    var cls = 'dcal-day is-' + state + (isToday ? ' is-today' : '') + (isSelected ? ' is-selected' : '')
    var markTone = TONES[info.mark] ? info.mark : (tags.length && TONES[tags[0].tone] ? tags[0].tone : 'muted')
    var label = date.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' }) +
      (info.title ? ' — ' + info.title : (tags.length ? ' — ' + tags.map(function (t) { return t.text }).join(', ') : ''))

    var inner = '<span class="dcal-num">' + date.getDate() + (isToday ? '<span class="dcal-today-dot" aria-hidden="true"></span>' : '') + '</span>' +
      '<span class="dcal-mark dcal-mark--' + markTone + '" aria-hidden="true"></span>' +
      (tags.length ? '<span class="dcal-tags">' + tags.map(function (t) {
        return '<span class="dcal-tag dcal-tag--' + (TONES[t.tone] ? t.tone : 'muted') + '">' + esc(t.text) + '</span>'
      }).join('') + '</span>' : '') +
      (selectable && this.opts.selectLabel ? '<span class="dcal-select" aria-hidden="true">' + (isSelected ? '<i class="bi bi-check-lg"></i>' : esc(this.opts.selectLabel)) + '</span>' : '')

    if (!interactive) {
      return '<div class="' + cls + '" data-date="' + iso + '" title="' + esc(label) + '">' + inner + '</div>'
    }
    return '<button type="button" class="' + cls + '" data-date="' + iso + '"' +
      (selectable ? ' aria-pressed="' + isSelected + '"' : ' aria-disabled="true"') +
      ' aria-label="' + esc(label) + '" title="' + esc(label) + '">' + inner + '</button>'
  }

  Calendar.prototype.render = function () {
    var first = parseYm(this.month)
    var title = first.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
    var daysInMonth = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate()
    var cells = []
    for (var i = 0; i < first.getDay(); i++) cells.push('<div class="dcal-day is-empty" aria-hidden="true"></div>')
    for (var d = 1; d <= daysInMonth; d++) cells.push(this.renderDay(new Date(first.getFullYear(), first.getMonth(), d)))
    while (cells.length % 7) cells.push('<div class="dcal-day is-empty" aria-hidden="true"></div>')

    var prev = addMonths(this.month, -1)
    var next = addMonths(this.month, 1)
    var thisYm = ymOf(new Date())
    var legend = Array.isArray(this.opts.legend) && this.opts.legend.length
      ? '<div class="dcal-legend">' + this.opts.legend.map(function (l) {
          return '<span><span class="dcal-swatch dcal-swatch--' + esc(l.tone) + '"></span>' + esc(l.label) + '</span>'
        }).join('') + '</div>'
      : ''

    this.el.innerHTML =
      '<div class="dcal-head">' +
        '<button type="button" class="dcal-nav" data-dcal-nav="-1" aria-label="Previous month"' + (this.inBounds(prev) ? '' : ' disabled') + '>&lsaquo;</button>' +
        '<div class="dcal-title" aria-live="polite">' + esc(title) + '</div>' +
        '<button type="button" class="dcal-nav" data-dcal-nav="1" aria-label="Next month"' + (this.inBounds(next) ? '' : ' disabled') + '>&rsaquo;</button>' +
        '<button type="button" class="dcal-today-btn" data-dcal-nav="today"' + (this.month === thisYm || !this.inBounds(thisYm) ? ' disabled' : '') + '>Today</button>' +
      '</div>' +
      '<div class="dcal-body">' +
        '<div class="dcal-weekdays" aria-hidden="true">' + WEEKDAYS.map(function (w) { return '<span>' + w + '</span>' }).join('') + '</div>' +
        '<div class="dcal-grid">' + cells.join('') + '</div>' +
        (this.overlay ? '<div class="dcal-overlay"><div class="dcal-overlay__box"><i class="bi bi-info-circle" aria-hidden="true"></i><span>' + esc(this.overlay) + '</span></div></div>' : '') +
      '</div>' + legend
  }

  // For server-rendered calendars: month arrows load the page for that month.
  // Uses the portal's AJAX navigation on desktop (main.js), a normal load otherwise.
  function goTo(url) {
    if (typeof window.ajaxNavigate === 'function' && window.innerWidth > 991) window.ajaxNavigate(url)
    else window.location.href = url
    return false
  }

  window.DentalCalendar = {
    create: function (el, opts) { return el ? new Calendar(el, opts) : null },
    goTo: goTo,
    toIso: toIso,
    ymOf: ymOf
  }
})();
