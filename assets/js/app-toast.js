(function () {
  'use strict';

  var TOAST_MAX_LENGTH = 48;

  var TOAST_SHORTCUTS = {
    'thank you! your message has been sent. our secretary will get back to you soon.': 'Message sent.',
    'thank you! your message has been sent.': 'Message sent.',
    'unable to send your message right now.': 'Could not send message.',
    'an error occurred while sending your message. please try again.': 'Send failed.',
    'account created! taking you to your dashboard...': 'Account created.',
    'account created successfully. redirecting to your dashboard...': 'Account created.',
    'password updated successfully. you can now log in.': 'Password updated.',
    'profile updated successfully. reloading...': 'Profile updated.',
    'your session has expired. please log in again.': 'Session expired.',
    'payment recorded successfully': 'Payment saved.',
    'payment already recorded.': 'Payment saved.',
    'clinical record created successfully!': 'Record saved.',
    'clinical record was already submitted.': 'Record already saved.',
    'appointment created successfully': 'Appointment booked.',
    'profiling saved successfully!': 'Profiling saved.',
    'patient account created successfully.': 'Patient added.',
    'services confirmed successfully.': 'Services saved.',
    'schedule updated successfully.': 'Schedule updated.',
    'otp sent. check your email.': 'OTP sent.',
    'please complete all fields before sending your message.': 'Complete all fields.',
    'security token mismatch. please refresh and try again.': 'Refresh and retry.',
    'an error occurred. please try again.': 'Something went wrong.',
    'unable to update profile right now. please try again.': 'Profile update failed.',
    'password copied to clipboard.': 'Password copied.',
    'message marked as read.': 'Marked as read.',
    'message archived.': 'Message archived.'
  };

  function ensureAppToastHost() {
    var host = document.getElementById('appToastHost');
    if (host) return host;

    host = document.createElement('div');
    host.id = 'appToastHost';
    host.className = 'app-toast-host';
    if (!document.getElementById('header')) {
      host.classList.add('app-toast-host--auth');
    }
    host.setAttribute('aria-live', 'polite');
    host.setAttribute('aria-atomic', 'true');
    document.body.appendChild(host);
    return host;
  }

  function normalizeToastType(type) {
    var value = String(type || 'info').toLowerCase();
    if (value === 'error') return 'danger';
    if (value === 'success' || value === 'danger' || value === 'warning' || value === 'info') {
      return value;
    }
    return 'info';
  }

  function getToastIcon(type) {
    switch (normalizeToastType(type)) {
      case 'success':
        return '\u2713';
      case 'danger':
        return '\u2715';
      case 'warning':
        return '!';
      default:
        return 'i';
    }
  }

  function truncateToastText(text, maxLen) {
    if (text.length <= maxLen) return text;
    var cut = text.slice(0, maxLen - 1).replace(/\s+\S*$/, '').trim();
    return (cut || text.slice(0, maxLen - 1)) + '\u2026';
  }

  function compressToastMessage(message, options) {
    var text = String(message || '').trim().replace(/\s+/g, ' ');
    if (!text) return '';
    if (options && options.fullMessage) return text;

    var mapped = TOAST_SHORTCUTS[text.toLowerCase()];
    if (mapped) return mapped;

    if (/secretary will get back/i.test(text)) return 'Message sent.';
    if (/already (recorded|submitted|processed)/i.test(text)) {
      text = text.replace(/successfully!?/gi, '').replace(/\s+/g, ' ').trim();
    }

    text = text
      .replace(/^thank you!?\s*/i, '')
      .replace(/\s*our secretary will get back to you soon\.?/i, '')
      .replace(/\s*you can now log in\.?/i, '')
      .replace(/\s*taking you to your dashboard\.{0,3}/i, '')
      .replace(/\s*redirecting\.{0,3}/i, '')
      .replace(/\s*reloading\.{0,3}/i, '')
      .replace(/\s*please try again\.?/i, '')
      .replace(/\s*please refresh and try again\.?/i, '')
      .replace(/^error:\s*/i, '')
      .replace(/\s+successfully!?$/i, '')
      .replace(/\s+/g, ' ')
      .trim();

    if (!text) return 'Done.';
    if (text.slice(-1) !== '.' && text.slice(-1) !== '!' && text.slice(-1) !== '?') {
      text += '.';
    }

    return truncateToastText(text, TOAST_MAX_LENGTH);
  }

  function showAppToast(message, type, options) {
    var opts = options || {};
    var text = compressToastMessage(message, opts);
    if (!text) return;

    var host = ensureAppToastHost();
    var toastType = normalizeToastType(type);
    var toast = document.createElement('div');
    toast.className = 'app-toast app-toast-' + toastType;
    toast.setAttribute('role', 'status');
    if (String(message || '').trim() && text !== String(message || '').trim()) {
      toast.setAttribute('title', String(message || '').trim());
    }

    var icon = document.createElement('span');
    icon.className = 'app-toast-icon app-toast-icon--' + toastType;
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = getToastIcon(toastType);

    var body = document.createElement('div');
    body.className = 'app-toast-body';
    body.textContent = text;

    toast.appendChild(icon);
    toast.appendChild(body);
    host.appendChild(toast);

    requestAnimationFrame(function () {
      toast.classList.add('is-visible');
    });

    var removed = false;
    var removeToast = function () {
      if (removed) return;
      removed = true;
      toast.classList.remove('is-visible');
      setTimeout(function () {
        try { toast.remove(); } catch (e) {}
      }, 180);
    };

    setTimeout(removeToast, Number(opts.delay || 3200));
  }

  function portalNotify(message, type, options) {
    if (typeof showAppToast === 'function') {
      showAppToast(message, type, options);
      return true;
    }
    if (typeof window.alert === 'function') {
      window.alert(String(message || ''));
    }
    return false;
  }

  window.compressToastMessage = compressToastMessage;
  window.showAppToast = showAppToast;
  window.showToast = showAppToast;
  window.portalNotify = portalNotify;
})();
