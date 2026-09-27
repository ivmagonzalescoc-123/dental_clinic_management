/**
 * Treatment plan widget (doctor EHR + secretary EHR queue).
 *
 *   TreatmentPlan.mount(containerEl, {
 *     patientId, appointmentId, role: 'doctor' | 'secretary', csrfToken,
 *     services: [{ service_id, name, price }],   // doctor add form only
 *     onCompleted(item), onUncompleted(item),     // doctor: sync "Services Performed"
 *     notify(message, type)
 *   })
 *
 * The plan is an estimate; it's never billed directly. Marking an item done on the
 * current visit hands its service to onCompleted, which puts it in that visit's
 * Services Performed — the existing path billing is built from.
 */
(function () {
  'use strict'

  var API = '../assets/api/treatment_plan/'
  var PHASES = { 1: 'Phase 1 — Urgent', 2: 'Phase 2 — Restorative', 3: 'Phase 3 — Prosthetic / Maintenance' }
  var STATUS = {
    proposed: { label: 'Proposed', cls: 'tp-badge--proposed' },
    accepted: { label: 'Accepted', cls: 'tp-badge--accepted' },
    completed: { label: 'Done', cls: 'tp-badge--completed' },
    declined: { label: 'Declined', cls: 'tp-badge--declined' }
  }

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    })
  }

  function peso(v) {
    return '₱' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  }

  function Widget(el, opts) {
    this.el = el
    this.opts = opts
    this.items = []
    this.totals = null
    this.busy = false
    el.classList.add('tp-widget')
    el.addEventListener('click', this.onClick.bind(this))
    el.addEventListener('change', this.onChange.bind(this))
    el.addEventListener('submit', this.onSubmit.bind(this))
    this.render()
    this.load()
  }

  Widget.prototype.notify = function (msg, type) {
    if (typeof this.opts.notify === 'function') this.opts.notify(msg, type)
    else if (typeof window.showAppToast === 'function') window.showAppToast(msg, type)
  }

  Widget.prototype.isDoctor = function () { return this.opts.role === 'doctor' }

  Widget.prototype.load = function () {
    var self = this
    fetch(API + 'list.php?patient_id=' + encodeURIComponent(this.opts.patientId), { credentials: 'same-origin' })
      .then(function (r) { return r.json() })
      .then(function (j) {
        if (!j.success) throw new Error(j.message || 'Failed to load')
        self.apply(j)
      })
      .catch(function () {
        self.loadError = true
        self.render()
      })
  }

  Widget.prototype.apply = function (j) {
    this.items = j.items || []
    this.totals = j.totals || null
    this.loadError = false
    this.render()
    // Re-assert services for items already done on this visit (e.g. after a reload),
    // so Services Performed never silently drops them. onCompleted must be idempotent.
    var appt = Number(this.opts.appointmentId || 0)
    if (this.isDoctor() && appt && typeof this.opts.onCompleted === 'function') {
      var cb = this.opts.onCompleted
      this.items.forEach(function (i) {
        if (i.status === 'completed' && Number(i.completed_appointment_id) === appt) cb(i)
      })
    }
  }

  Widget.prototype.post = function (data) {
    if (this.busy) return Promise.resolve(null)
    this.busy = true
    this.el.classList.add('is-busy')
    var fd = new FormData()
    fd.append('csrf_token', this.opts.csrfToken || '')
    fd.append('patient_id', this.opts.patientId)
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]) })
    var self = this
    return fetch(API + 'save.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json() })
      .then(function (j) {
        if (!j.success) { self.notify(j.message || 'Could not update the treatment plan.', 'warning'); return null }
        self.apply(j)
        return j
      })
      .catch(function () { self.notify('Could not reach the server. Please try again.', 'danger'); return null })
      .finally(function () { self.busy = false; self.el.classList.remove('is-busy') })
  }

  Widget.prototype.findItem = function (id) {
    id = Number(id)
    for (var i = 0; i < this.items.length; i++) if (this.items[i].item_id === id) return this.items[i]
    return null
  }

  Widget.prototype.onClick = function (e) {
    var btn = e.target.closest('[data-tp-action]')
    if (!btn || !this.el.contains(btn)) return
    var action = btn.getAttribute('data-tp-action')
    var id = btn.getAttribute('data-item-id')
    var item = this.findItem(id)
    var self = this
    var appt = Number(this.opts.appointmentId || 0)

    if (action === 'accept' || action === 'decline' || action === 'reopen') {
      var status = action === 'accept' ? 'accepted' : action === 'decline' ? 'declined' : 'proposed'
      this.post({ action: 'status', item_id: id, status: status })
    } else if (action === 'delete') {
      this.post({ action: 'delete', item_id: id })
    } else if (action === 'complete') {
      if (!appt) { this.notify('Select the patient\'s current appointment first.', 'warning'); return }
      this.post({ action: 'complete', item_id: id, appointment_id: appt }).then(function (j) {
        if (j) self.notify((item && item.service_name ? item.service_name : 'Service') + ' added to Services Performed.', 'success')
      })
    } else if (action === 'uncomplete') {
      this.post({ action: 'uncomplete', item_id: id, appointment_id: appt }).then(function (j) {
        if (j && item && typeof self.opts.onUncompleted === 'function') self.opts.onUncompleted(item, self.items)
      })
    }
  }

  Widget.prototype.onChange = function (e) {
    if (e.target.name !== 'service_id') return
    var form = e.target.form
    var svc = (this.opts.services || []).filter(function (s) { return String(s.service_id) === e.target.value })[0]
    if (svc && form.estimated_cost) form.estimated_cost.value = Number(svc.price || 0).toFixed(2)
  }

  Widget.prototype.onSubmit = function (e) {
    var form = e.target.closest('.tp-add-form')
    if (!form) return
    e.preventDefault()
    if (!form.service_id.value) { this.notify('Select a procedure to add.', 'warning'); return }
    var self = this
    this.post({
      action: 'add',
      service_id: form.service_id.value,
      tooth_number: form.tooth_number.value.trim(),
      phase: form.phase.value,
      estimated_cost: form.estimated_cost.value,
      notes: form.notes.value.trim(),
      appointment_id: this.opts.appointmentId || 0
    }).then(function (j) {
      // render() rebuilt the form (now empty), so focus the new one for quick entry.
      var next = j && self.el.querySelector('.tp-add-service')
      if (next) next.focus()
    })
  }

  Widget.prototype.renderAddForm = function () {
    var options = (this.opts.services || []).map(function (s) {
      return '<option value="' + esc(s.service_id) + '">' + esc(s.name) + ' — ' + esc(peso(s.price)) + '</option>'
    }).join('')
    return '' +
      '<form class="tp-add-form" method="post" data-no-ajax autocomplete="off">' +
        '<select name="service_id" class="form-select form-select-sm tp-add-service" aria-label="Procedure"><option value="">Add planned procedure…</option>' + options + '</select>' +
        '<input name="tooth_number" class="form-control form-control-sm tp-add-tooth" inputmode="numeric" maxlength="2" placeholder="Tooth" aria-label="Tooth (FDI), blank for whole mouth" title="FDI tooth number, e.g. 36. Leave blank for whole mouth.">' +
        '<select name="phase" class="form-select form-select-sm tp-add-phase" aria-label="Phase">' +
          '<option value="1">Phase 1 — Urgent</option><option value="2">Phase 2 — Restorative</option><option value="3">Phase 3 — Prosthetic</option>' +
        '</select>' +
        '<input name="estimated_cost" type="number" step="0.01" min="0" class="form-control form-control-sm tp-add-cost" placeholder="Estimate" aria-label="Estimated cost">' +
        '<input name="notes" class="form-control form-control-sm tp-add-notes" maxlength="255" placeholder="Notes (optional)" aria-label="Notes">' +
        '<button type="submit" class="btn btn-sm btn-primary tp-add-btn"><i class="bi bi-plus-lg"></i> Add</button>' +
      '</form>'
  }

  Widget.prototype.renderItem = function (i) {
    var doctor = this.isDoctor()
    var appt = Number(this.opts.appointmentId || 0)
    var st = STATUS[i.status] || { label: i.status, cls: '' }
    var actions = []

    if (i.status === 'proposed') {
      if (doctor && appt) actions.push('<button type="button" class="btn btn-sm btn-success" data-tp-action="complete" data-item-id="' + i.item_id + '"><i class="bi bi-check2"></i> Done this visit</button>')
      actions.push('<button type="button" class="btn btn-sm btn-outline-primary" data-tp-action="accept" data-item-id="' + i.item_id + '">Accept</button>')
      actions.push('<button type="button" class="btn btn-sm btn-outline-secondary" data-tp-action="decline" data-item-id="' + i.item_id + '">Decline</button>')
      if (doctor) actions.push('<button type="button" class="btn btn-sm btn-link text-danger" data-tp-action="delete" data-item-id="' + i.item_id + '" aria-label="Remove"><i class="bi bi-trash"></i></button>')
    } else if (i.status === 'accepted') {
      if (doctor && appt) actions.push('<button type="button" class="btn btn-sm btn-success" data-tp-action="complete" data-item-id="' + i.item_id + '"><i class="bi bi-check2"></i> Done this visit</button>')
      actions.push('<button type="button" class="btn btn-sm btn-outline-secondary" data-tp-action="decline" data-item-id="' + i.item_id + '">Decline</button>')
    } else if (i.status === 'declined') {
      actions.push('<button type="button" class="btn btn-sm btn-outline-secondary" data-tp-action="reopen" data-item-id="' + i.item_id + '">Reconsider</button>')
      if (doctor) actions.push('<button type="button" class="btn btn-sm btn-link text-danger" data-tp-action="delete" data-item-id="' + i.item_id + '" aria-label="Remove"><i class="bi bi-trash"></i></button>')
    } else if (i.status === 'completed') {
      var when = i.completed_appointment_date ? new Date(i.completed_appointment_date + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : ''
      var thisVisit = appt && Number(i.completed_appointment_id) === appt
      actions.push('<span class="tp-done-note">' + (thisVisit ? 'This visit' : esc(when)) + '</span>')
      if (doctor && thisVisit) actions.push('<button type="button" class="btn btn-sm btn-link" data-tp-action="uncomplete" data-item-id="' + i.item_id + '">Undo</button>')
    }

    return '' +
      '<li class="tp-item tp-item--' + esc(i.status) + '">' +
        '<span class="tp-tooth" title="' + (i.tooth_number ? 'Tooth ' + esc(i.tooth_number) : 'Whole mouth') + '">' + (i.tooth_number ? esc(i.tooth_number) : '<i class="bi bi-circle"></i>') + '</span>' +
        '<div class="tp-main">' +
          '<div class="tp-name">' + esc(i.service_name || ('Service #' + i.service_id)) + '</div>' +
          (i.notes ? '<div class="tp-notes">' + esc(i.notes) + '</div>' : '') +
        '</div>' +
        '<span class="tp-badge ' + st.cls + '">' + esc(st.label) + '</span>' +
        '<span class="tp-cost">' + esc(peso(i.estimated_cost)) + '</span>' +
        '<div class="tp-actions">' + actions.join('') + '</div>' +
      '</li>'
  }

  Widget.prototype.render = function () {
    var doctor = this.isDoctor()
    var t = this.totals
    var head = '' +
      '<div class="tp-head">' +
        '<div class="tp-title"><i class="bi bi-list-check"></i> Treatment Plan</div>' +
        (t && this.items.length ? '<div class="tp-totals">' +
          '<span>Plan <strong>' + esc(peso(t.plan)) + '</strong></span>' +
          '<span>Done <strong>' + esc(peso(t.completed)) + '</strong></span>' +
          '<span>Remaining <strong>' + esc(peso(t.remaining)) + '</strong></span>' +
        '</div>' : '') +
        '<a class="btn btn-sm btn-outline-secondary tp-print" target="_blank" rel="noopener" href="' + API + 'print_estimate.php?patient_id=' + encodeURIComponent(this.opts.patientId) + '"><i class="bi bi-printer"></i> Estimate</a>' +
      '</div>'

    var body
    if (this.loadError) {
      body = '<div class="tp-empty text-danger">Couldn\'t load the treatment plan.</div>'
    } else if (!this.items.length) {
      body = '<div class="tp-empty">No treatment plan yet.' + (doctor ? ' Add the procedures this patient needs, then give them the estimate.' : '') + '</div>'
    } else {
      var byPhase = {}
      this.items.forEach(function (i) { (byPhase[i.phase] = byPhase[i.phase] || []).push(i) })
      var self = this
      body = Object.keys(byPhase).sort().map(function (p) {
        return '<div class="tp-phase"><div class="tp-phase-title">' + esc(PHASES[p] || ('Phase ' + p)) + '</div>' +
          '<ul class="tp-list">' + byPhase[p].map(function (i) { return self.renderItem(i) }).join('') + '</ul></div>'
      }).join('')
    }

    this.el.innerHTML = head + (doctor ? this.renderAddForm() : '') + body
  }

  window.TreatmentPlan = {
    mount: function (el, opts) {
      if (!el) return null
      if (el._tpWidget) el.replaceWith((el = el.cloneNode(false)))
      el._tpWidget = new Widget(el, opts || {})
      return el._tpWidget
    }
  }
})();
