/**
 * DentalUI — loading states, optimistic updates, debouncing
 */
window.DentalUI = (function() {

    // Debounce: prevents rapid-fire API calls
    function debounce(fn, delay) {
        var timer = null;
        return function() {
            var ctx = this, args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function() { fn.apply(ctx, args); }, delay || 300);
        };
    }

    // Throttle: max once per interval
    function throttle(fn, interval) {
        var last = 0;
        return function() {
            var now = Date.now();
            if (now - last >= (interval || 1000)) {
                last = now;
                fn.apply(this, arguments);
            }
        };
    }

    // Show loading state on a button
    function btnLoading(btn, loadingText) {
        if (!btn) return;
        btn.dataset.originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>' + (loadingText || 'Loading...');
    }

    // Restore button state
    function btnRestore(btn) {
        if (!btn) return;
        btn.disabled = false;
        btn.innerHTML = btn.dataset.originalText || btn.innerHTML;
    }

    // Show shimmering skeleton placeholder rows in a <tbody> while real data loads
    // (Facebook-comments-style: shows the table's shape immediately, not just a spinner).
    // cols is auto-detected from the table's <thead> when omitted.
    function showSkeleton(tbody, rows, cols) {
        if (!tbody) return;
        rows = rows || 5;
        if (!cols) {
            var table = tbody.closest ? tbody.closest('table') : null;
            var headRow = table ? table.querySelector('thead tr') : null;
            cols = headRow ? headRow.children.length : 4;
        }
        var html = '';
        for (var r = 0; r < rows; r++) {
            html += '<tr class="skeleton-row">';
            for (var c = 0; c < cols; c++) {
                var w = 45 + Math.floor(Math.random() * 45);
                html += '<td><span class="skeleton-bar" style="width:' + w + '%"></span></td>';
            }
            html += '</tr>';
        }
        tbody.innerHTML = html;
        tbody.setAttribute('aria-busy', 'true');
    }

    // Inline spinner overlay
    function showOverlay(el) {
        if (!el) return;
        var overlay = document.createElement('div');
        overlay.className = 'dental-overlay';
        overlay.style.cssText = 'position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.7);display:flex;align-items:center;justify-content:center;z-index:10;border-radius:inherit;';
        overlay.innerHTML = '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>';
        el.style.position = 'relative';
        el.appendChild(overlay);
        return overlay;
    }

    function hideOverlay(el) {
        if (!el) return;
        var overlay = el.querySelector('.dental-overlay');
        if (overlay) overlay.remove();
    }

    // Toast notification (uses existing showToast if available)
    function toast(msg, type) {
        if (typeof window.showToast === 'function') {
            window.showToast(msg, type);
        } else {
            console.log('[' + (type || 'info') + '] ' + msg);
        }
    }

    return {
        debounce: debounce,
        throttle: throttle,
        btnLoading: btnLoading,
        btnRestore: btnRestore,
        showSkeleton: showSkeleton,
        showOverlay: showOverlay,
        hideOverlay: hideOverlay,
        toast: toast
    };
})();
