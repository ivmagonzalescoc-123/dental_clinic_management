/**
 * Client helpers for safe submit / retry without duplicate writes.
 */
(function (global) {
    function newIdempotencyKey(prefix) {
        const p = prefix ? String(prefix) + '-' : '';
        if (global.crypto && typeof global.crypto.randomUUID === 'function') {
            return p + global.crypto.randomUUID();
        }
        return p + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
    }

    function bindSubmitGuard(button, options) {
        if (!button) return;

        const opts = options || {};
        let inFlight = false;
        let activeKey = null;

        button.addEventListener('click', function () {
            if (inFlight) return;
            inFlight = true;
            activeKey = newIdempotencyKey(opts.keyPrefix || 'req');
            button.dataset.idempotencyKey = activeKey;

            const originalHtml = button.innerHTML;
            if (opts.busyHtml) {
                button.innerHTML = opts.busyHtml;
            }
            button.disabled = true;

            const release = function (restore) {
                inFlight = false;
                button.disabled = false;
                if (restore && opts.busyHtml) {
                    button.innerHTML = originalHtml;
                }
            };

            if (typeof opts.onSubmit === 'function') {
                Promise.resolve()
                    .then(function () { return opts.onSubmit(activeKey, release); })
                    .catch(function (err) {
                        console.error(err);
                        release(true);
                        if (typeof opts.onError === 'function') {
                            opts.onError(err);
                        }
                    });
            }
        });
    }

    global.Idempotency = {
        newKey: newIdempotencyKey,
        bindSubmitGuard: bindSubmitGuard,
    };
})(window);
