/**
 * Odontogram fullscreen (shared across doctor/secretary EHR + records
 * pages — they all mark their target element with .odontogram-viewport).
 *
 * iOS Safari has no element Fullscreen API at all (Element.requestFullscreen
 * is undefined there), so a plain requestFullscreen() call silently did
 * nothing on iPhone — the "Full Screen" button just looked broken. Fall
 * back to a fixed-position "pseudo-fullscreen" (.is-pseudo-fullscreen) on
 * any browser that lacks the real API, or whose request Promise rejects.
 *
 * This is its own small file, loaded early (right after <body> opens, before
 * the EHR/records module content further down the page) rather than bundled
 * into main.js — main.js loads near the footer, but the odontogram module's
 * own inline <script> calls window.OdontogramFullscreen.isActive(...)
 * synchronously as soon as the parser reaches it, well before a
 * footer-loaded script would have run.
 */
(function () {
  'use strict'

  function getNativeFullscreenElement() {
    return document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement || null
  }

  function isOdontogramFullscreenActive(el) {
    if (!el) return false
    return getNativeFullscreenElement() === el || el.classList.contains('is-pseudo-fullscreen')
  }

  function enterPseudoFullscreen(el) {
    el.classList.add('is-pseudo-fullscreen')
    document.body.classList.add('odontogram-pseudo-fullscreen-lock')
    el.dispatchEvent(new CustomEvent('odontogram:pseudofullscreenchange', { bubbles: true }))
  }

  function exitPseudoFullscreen(el) {
    if (!el.classList.contains('is-pseudo-fullscreen')) return
    el.classList.remove('is-pseudo-fullscreen')
    document.body.classList.remove('odontogram-pseudo-fullscreen-lock')
    el.dispatchEvent(new CustomEvent('odontogram:pseudofullscreenchange', { bubbles: true }))
  }

  function requestOdontogramFullscreen(el) {
    if (!el) return
    var requestFn = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen
    if (!requestFn) {
      enterPseudoFullscreen(el)
      return
    }
    var result = requestFn.call(el)
    if (result && typeof result.then === 'function') {
      result.then(lockLandscape, function () { enterPseudoFullscreen(el) })
    } else {
      lockLandscape()
    }
  }

  // Charting works best in landscape. Browsers only allow an orientation lock
  // while in native fullscreen (Android Chrome); elsewhere (iOS, desktop) it
  // rejects and the rotate prompt covers it instead.
  function lockLandscape() {
    try {
      if (screen.orientation && typeof screen.orientation.lock === 'function') {
        screen.orientation.lock('landscape').catch(function () {})
      }
    } catch (e) {}
  }

  function unlockOrientation() {
    try {
      if (screen.orientation && typeof screen.orientation.unlock === 'function') {
        screen.orientation.unlock()
      }
    } catch (e) {}
  }

  function exitOdontogramFullscreen(el) {
    var exitFn = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen
    if (getNativeFullscreenElement() && exitFn) {
      unlockOrientation()
      exitFn.call(document)
    }
    if (el) exitPseudoFullscreen(el)
  }

  window.OdontogramFullscreen = {
    isActive: isOdontogramFullscreenActive,
    request: requestOdontogramFullscreen,
    exit: exitOdontogramFullscreen
  }

  function resetRotatePrompt(el) {
    if (!el) return
    var prompt = el.querySelector('.odontogram-rotate-prompt')
    if (prompt) prompt.classList.remove('is-dismissed')
  }

  document.addEventListener('click', function (e) {
    var dismissBtn = e.target.closest && e.target.closest('[data-odontogram-rotate-dismiss]')
    if (!dismissBtn) return
    var prompt = dismissBtn.closest('.odontogram-rotate-prompt')
    if (prompt) prompt.classList.add('is-dismissed')
  })

  function handleNativeFullscreenChange() {
    var el = getNativeFullscreenElement()
    if (el && el.classList && el.classList.contains('odontogram-viewport')) resetRotatePrompt(el)
  }
  document.addEventListener('fullscreenchange', handleNativeFullscreenChange)
  document.addEventListener('webkitfullscreenchange', handleNativeFullscreenChange)
  document.addEventListener('odontogram:pseudofullscreenchange', function (e) {
    if (e.target.classList.contains('is-pseudo-fullscreen')) resetRotatePrompt(e.target)
  })
})();
