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

    // Show skeleton loading in a container
    function showSkeleton(container, rows, cols) {
        if (!container) return;
        rows = rows || 5;
        cols = cols || 4;
        var html = '';
        for (var r = 0; r < rows; r++) {
            html += '<tr>';
            for (var c = 0; c < cols; c++) {
                html += '<td><div class="placeholder-glow"><span class="placeholder col-' + (6 + Math.floor(Math.random() * 5)) + '"></span></div></td>';
            }
            html += '</tr>';
        }
        container.innerHTML = html;
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
