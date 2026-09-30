/**
 * Header page search shared by every portal (admin, doctor, secretary, patient).
 *
 * Indexes the sidebar and profile-dropdown links (keeping their full href, e.g.
 * "?page=doctor_management&tab=schedule") plus any sub-sections a portal lists
 * in `sections`, so tabs like "Doctor Leaves" or "Follow-ups" are findable too.
 *
 *   initPortalSearch({
 *       inputId: 'adminSearch',
 *       keywords: { billing: ['invoices', 'payments'] },      // extra words per ?page=
 *       sections: [{ title: 'Doctor Leaves', parent: 'Doctor Management',
 *                    href: 'admin.php?page=doctor_management&tab=leaves',
 *                    keywords: ['leave', 'vacation'] }],
 *   });
 */
(function () {
    'use strict';

    var MAX_RESULTS = 8;

    function injectStyles() {
        if (document.getElementById('portalSearchSuggestionStyles')) return;
        var style = document.createElement('style');
        style.id = 'portalSearchSuggestionStyles';
        style.textContent =
            '.search-suggest-menu{position:absolute;top:calc(100% + 6px);left:0;right:0;background:#fff;border:1px solid #dbe4f0;border-radius:10px;box-shadow:0 10px 24px rgba(24,45,74,.14);max-height:320px;overflow:auto;z-index:1200;display:none;}' +
            '.search-suggest-item{display:block;width:100%;text-align:left;border:0;background:transparent;padding:10px 12px;cursor:pointer;}' +
            '.search-suggest-item:hover,.search-suggest-item.active{background:#eef5ff;}' +
            '.search-suggest-title{font-size:14px;color:#153b66;font-weight:600;}' +
            '.search-suggest-parent{font-weight:500;color:#6b7f95;}' +
            '.search-suggest-meta{font-size:12px;color:#6b7f95;}' +
            '.search-suggest-empty{padding:10px 12px;font-size:13px;color:#71839a;}' +
            '.search-suggest-item mark{background:#fff3bf;padding:0 1px;border-radius:2px;}';
        document.head.appendChild(style);
    }

    function escapeHtml(text) {
        return String(text).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function highlight(text, term) {
        var safe = escapeHtml(text);
        var tokens = String(term || '').trim().split(/\s+/).filter(Boolean);
        tokens.forEach(function (t) {
            var escaped = escapeHtml(t).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            safe = safe.replace(new RegExp('(' + escaped + ')(?![^<]*>)', 'ig'), '<mark>$1</mark>');
        });
        return safe;
    }

    function pageOf(href) {
        try {
            return new URL(href, window.location.href).searchParams.get('page') || '';
        } catch (e) {
            return '';
        }
    }

    function normalize(list) {
        var seen = {};
        return list.map(function (k) { return String(k || '').toLowerCase().trim(); })
            .filter(function (k) { return k && !seen[k] && (seen[k] = true); });
    }

    function buildEntries(opts) {
        var keywords = opts.keywords || {};
        var entries = [];
        var seenHref = {};

        function add(entry) {
            var key;
            try { key = new URL(entry.href, window.location.href).search; } catch (e) { key = entry.href; }
            if (!entry.href || seenHref[key]) return;
            seenHref[key] = true;
            entries.push(entry);
        }

        var links = document.querySelectorAll('#sidebar-nav a.nav-link[href*="page="], .dropdown-menu a.dropdown-item[href*="page="]');
        links.forEach(function (a) {
            var href = a.getAttribute('href') || '';
            var page = pageOf(href);
            if (!page) return;
            var title = (a.getAttribute('data-tooltip') || a.textContent || '').replace(/\s+/g, ' ').trim() || page.replace(/_/g, ' ');
            add({
                title: title,
                parent: '',
                href: href,
                keywords: normalize([title, page.replace(/_/g, ' ')].concat(keywords[page] || [])),
            });
        });

        (opts.sections || []).forEach(function (s) {
            var page = pageOf(s.href);
            add({
                title: s.title,
                parent: s.parent || '',
                href: s.href,
                // A section also answers to its parent page's words ("leave" finds Doctor Leaves).
                keywords: normalize([s.title, s.parent].concat(s.keywords || [], keywords[page] || [])),
            });
        });

        return entries;
    }

    function score(entry, tokens) {
        var title = entry.title.toLowerCase();
        var hay = (entry.title + ' ' + entry.parent + ' ' + entry.keywords.join(' ')).toLowerCase();
        var total = 0;
        for (var i = 0; i < tokens.length; i++) {
            var t = tokens[i];
            if (hay.indexOf(t) === -1) return -1;
            if (title.indexOf(t) === 0) total += 4;
            else if (title.indexOf(t) !== -1) total += 3;
            else total += 1;
        }
        // Main pages before their sub-sections when equally relevant.
        return total + (entry.parent ? 0 : 0.5);
    }

    window.initPortalSearch = function (opts) {
        var input = document.getElementById(opts.inputId);
        if (!input) return;
        injectStyles();

        var wrap = input.closest('.header-search-wrap');
        var menu = document.createElement('div');
        menu.className = 'search-suggest-menu';
        menu.setAttribute('role', 'listbox');
        if (wrap) wrap.appendChild(menu);

        var entries = buildEntries(opts);
        var results = [];
        var activeIndex = 0;

        function find(term) {
            var tokens = String(term || '').trim().toLowerCase().split(/\s+/).filter(Boolean);
            if (!tokens.length) {
                return entries.filter(function (e) { return !e.parent; }).slice(0, MAX_RESULTS);
            }
            return entries
                .map(function (e) { return { e: e, s: score(e, tokens) }; })
                .filter(function (x) { return x.s >= 0; })
                .sort(function (a, b) { return b.s - a.s; })
                .slice(0, MAX_RESULTS)
                .map(function (x) { return x.e; });
        }

        function hidden() {
            // On mobile the input only counts once the header search is expanded.
            var header = document.getElementById('header');
            return window.innerWidth <= 991 && header && !header.classList.contains('header-search-open');
        }

        function render(term) {
            if (hidden()) {
                menu.style.display = 'none';
                return;
            }
            results = find(term);
            activeIndex = 0;
            if (!results.length) {
                menu.innerHTML = '<div class="search-suggest-empty">No page matches in this portal.</div>';
                menu.style.display = 'block';
                return;
            }
            menu.innerHTML = results.map(function (r, i) {
                var meta = r.keywords.filter(function (k) {
                    return k !== r.title.toLowerCase() && k !== String(r.parent).toLowerCase();
                }).slice(0, 4).join(' • ');
                return '<button type="button" role="option" class="search-suggest-item' + (i === 0 ? ' active' : '') + '" data-index="' + i + '">' +
                    '<div class="search-suggest-title">' +
                    (r.parent ? '<span class="search-suggest-parent">' + highlight(r.parent, term) + ' › </span>' : '') +
                    highlight(r.title, term) + '</div>' +
                    (meta ? '<div class="search-suggest-meta">' + highlight(meta, term) + '</div>' : '') +
                    '</button>';
            }).join('');
            menu.style.display = 'block';
        }

        function setActive(i) {
            var items = menu.querySelectorAll('.search-suggest-item');
            if (!items.length) return;
            activeIndex = (i + items.length) % items.length;
            items.forEach(function (el, idx) { el.classList.toggle('active', idx === activeIndex); });
            items[activeIndex].scrollIntoView({ block: 'nearest' });
        }

        function go(entry) {
            if (!entry) return;
            menu.style.display = 'none';
            input.value = '';
            input.blur();
            var header = document.getElementById('header');
            if (header) header.classList.remove('header-search-open');
            var url = new URL(entry.href, window.location.href).href;
            // Same in-place navigation the sidebar uses on desktop; full load on mobile.
            if (typeof window.ajaxNavigate === 'function' && window.innerWidth > 991) {
                window.ajaxNavigate(url);
            } else {
                window.location.href = url;
            }
        }

        input.addEventListener('input', function () { render(input.value); });
        input.addEventListener('focus', function () { render(input.value); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (menu.style.display !== 'block') render(input.value);
                setActive(activeIndex + (e.key === 'ArrowDown' ? 1 : -1));
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (menu.style.display !== 'block') render(input.value);
                go(results[activeIndex]);
            } else if (e.key === 'Escape') {
                menu.style.display = 'none';
            }
        });

        menu.addEventListener('mousedown', function (e) {
            // Keep focus in the input so the click lands before any blur handling.
            e.preventDefault();
        });
        menu.addEventListener('click', function (e) {
            var btn = e.target.closest('.search-suggest-item');
            if (btn) go(results[Number(btn.getAttribute('data-index'))]);
        });
        document.addEventListener('click', function (e) {
            if (!wrap || !wrap.contains(e.target)) menu.style.display = 'none';
        });
    };
})();
