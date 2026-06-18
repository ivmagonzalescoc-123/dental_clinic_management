/**
 * DentalCache — lightweight client-side cache with TTL
 * Stores API responses in sessionStorage to avoid redundant requests
 */
window.DentalCache = (function() {
    var PREFIX = 'dental_';
    var DEFAULT_TTL = 30000; // 30 seconds

    function now() { return Date.now(); }

    function set(key, data, ttl) {
        try {
            var entry = { data: data, expires: now() + (ttl || DEFAULT_TTL) };
            sessionStorage.setItem(PREFIX + key, JSON.stringify(entry));
        } catch (e) { /* quota exceeded, clear old entries */ }
    }

    function get(key) {
        try {
            var raw = sessionStorage.getItem(PREFIX + key);
            if (!raw) return null;
            var entry = JSON.parse(raw);
            if (entry.expires < now()) {
                sessionStorage.removeItem(PREFIX + key);
                return null;
            }
            return entry.data;
        } catch (e) { return null; }
    }

    function remove(key) {
        sessionStorage.removeItem(PREFIX + key);
    }

    function clear() {
        var keys = [];
        for (var i = 0; i < sessionStorage.length; i++) {
            var k = sessionStorage.key(i);
            if (k && k.indexOf(PREFIX) === 0) keys.push(k);
        }
        keys.forEach(function(k) { sessionStorage.removeItem(k); });
    }

    function invalidate(pattern) {
        var keys = [];
        for (var i = 0; i < sessionStorage.length; i++) {
            var k = sessionStorage.key(i);
            if (k && k.indexOf(PREFIX) === 0 && k.indexOf(pattern) >= 0) {
                keys.push(k);
            }
        }
        keys.forEach(function(k) { sessionStorage.removeItem(k); });
    }

    /**
     * Cached fetch — returns cached data if available, otherwise fetches and caches
     * @param {string} url
     * @param {object} opts — { ttl, force, transform }
     * @returns {Promise<any>}
     */
    async function cachedFetch(url, opts) {
        opts = opts || {};
        var ttl = opts.ttl || DEFAULT_TTL;
        var cacheKey = 'api_' + url.replace(/[^a-zA-Z0-9]/g, '_').substring(0, 100);

        if (!opts.force) {
            var cached = get(cacheKey);
            if (cached !== null) return cached;
        }

        var resp = await fetch(url);
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        var data = await resp.json();

        if (opts.transform) data = opts.transform(data);
        set(cacheKey, data, ttl);
        return data;
    }

    return {
        set: set,
        get: get,
        remove: remove,
        clear: clear,
        invalidate: invalidate,
        cachedFetch: cachedFetch
    };
})();
