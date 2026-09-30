/**
 * Page-shaped loading skeletons for portal AJAX navigation (used by main.js).
 *
 * capture() runs when leaving a page: it records where that page's visible
 * panels, text lines and controls sit inside #main and stores them per page.
 * show() then draws those boxes as a shimmering "ghost" on the next visit, so
 * every page loads with its own layout instead of one generic dashboard shape.
 * Pages without a snapshot yet (first visit, or a very different window width)
 * fall back to a template for their page type (table, calendar, form, ...).
 */
(function () {
    'use strict';

    var STORE_KEY = 'dentalPageSkeleton:v1';
    var MAX_ITEMS = 400;
    var MAX_PAGES = 80;
    var WIDTH_TOLERANCE = 0.15;
    // Query params that change a page's layout; everything else (ids, dates, search) is ignored.
    var LAYOUT_PARAMS = ['page', 'tab', 'section', 'view', 'type'];

    var T_PANEL = 0, T_BLOCK = 1, T_TEXT = 2, T_TINT = 3, T_FILL = 4;
    // Cells, links and inline elements never become panels - their text/controls carry the shape.
    var NO_PANEL_TAGS = { TD: 1, TH: 1, A: 1, LI: 1, SPAN: 1, LABEL: 1, SMALL: 1, STRONG: 1 };

    var SKIP_TAGS = { SCRIPT: 1, STYLE: 1, TEMPLATE: 1, NOSCRIPT: 1, LINK: 1, META: 1, BR: 1, OPTION: 1 };
    var BLOCK_TAGS = { BUTTON: 1, INPUT: 1, SELECT: 1, TEXTAREA: 1, IMG: 1, CANVAS: 1, svg: 1, SVG: 1, VIDEO: 1, IFRAME: 1 };
    var BLOCK_CLASSES = ['btn', 'badge', 'form-control', 'form-select', 'avatar', 'rounded-circle'];

    function portalOf(url) {
        var m = String(url || '').match(/\/?(admin|doctor|secretary|patient)\.php/);
        return m ? m[1] : '';
    }

    function pageKeys(url) {
        var u;
        try { u = new URL(url, window.location.href); } catch (e) { return null; }
        var portal = portalOf(u.pathname);
        var page = u.searchParams.get('page') || 'dashboard';
        var parts = [portal];
        LAYOUT_PARAMS.forEach(function (p) {
            var v = p === 'page' ? page : u.searchParams.get(p);
            if (v) parts.push(p + '=' + v);
        });
        return { exact: parts.join('|'), page: portal + '|page=' + page, portal: portal, name: page };
    }

    function readStore() {
        try { return JSON.parse(localStorage.getItem(STORE_KEY) || '{}') || {}; } catch (e) { return {}; }
    }

    function writeStore(store) {
        var keys = Object.keys(store);
        if (keys.length > MAX_PAGES) {
            keys.sort(function (a, b) { return store[a].t - store[b].t; });
            keys.slice(0, keys.length - MAX_PAGES).forEach(function (k) { delete store[k]; });
        }
        try { localStorage.setItem(STORE_KEY, JSON.stringify(store)); } catch (e) {}
    }

    function contentBox(main) {
        var r = main.getBoundingClientRect();
        var cs = getComputedStyle(main);
        var left = r.left + main.clientLeft + parseFloat(cs.paddingLeft);
        var top = r.top + main.clientTop + parseFloat(cs.paddingTop);
        var width = main.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        return { left: left, top: top, width: width };
    }

    function alpha(color) {
        if (!color || color === 'transparent') return 0;
        var m = color.match(/rgba?\(([^)]+)\)/);
        if (!m) return 1;
        var p = m[1].split(/[ ,\/]+/).filter(Boolean);
        return p.length > 3 ? parseFloat(p[3]) : 1;
    }

    function isLight(color) {
        var m = color.match(/rgba?\(([^)]+)\)/);
        if (!m) return true;
        var p = m[1].split(/[ ,\/]+/).map(parseFloat);
        return p[0] > 235 && p[1] > 235 && p[2] > 235;
    }

    function radius(cs, h) {
        return Math.min(Math.round(parseFloat(cs.borderTopLeftRadius) || 0), Math.round(h / 2));
    }

    function isBlock(el, r) {
        if (BLOCK_TAGS[el.tagName]) return el.tagName !== 'INPUT' || el.type !== 'hidden';
        if (el.tagName === 'I' && r.width <= 32 && r.height <= 32) return true;
        for (var i = 0; i < BLOCK_CLASSES.length; i++) {
            if (el.classList.contains(BLOCK_CLASSES[i])) return true;
        }
        return false;
    }

    /** Record the visible layout of #main for the page at url. */
    function capture(main, url) {
        if (!main) return;
        var keys = pageKeys(url || window.location.href);
        if (!keys || !keys.portal) return;
        // Never snapshot a skeleton (e.g. navigating again before a load finished).
        if (main.querySelector('.dsk-ghost, .dsk-tpl, .dental-skeleton-block')) return;

        var box = contentBox(main);
        if (box.width < 240) return;
        // Positions are relative to #main's top, so they hold even when scrolled down.
        // Only the first screenful matters: navigation shows the skeleton at the top.
        var limit = window.innerHeight - Math.max(0, box.top + window.scrollY) + 40;
        if (limit < 200) limit = window.innerHeight;
        var items = [];
        var maxBottom = 0;
        var range = document.createRange();

        function push(type, left, top, width, height, rad) {
            var x = left - box.left;
            var y = top - box.top;
            if (x >= box.width || y >= limit || x + width <= 0 || y + height <= 0) return;
            if (x < 0) { width += x; x = 0; }
            if (x + width > box.width) width = box.width - x;
            if (y + height > limit) height = limit - y;
            if (width < 3 || height < 3) return;
            maxBottom = Math.max(maxBottom, y + height);
            items.push([
                type,
                Math.round(x / box.width * 1000),
                Math.round(y),
                Math.max(1, Math.round(width / box.width * 1000)),
                Math.round(height),
                rad || 0,
            ]);
        }

        function textLines(node) {
            if (!/\S/.test(node.nodeValue)) return;
            range.selectNodeContents(node);
            var rects = range.getClientRects();
            for (var i = 0; i < rects.length && i < 8; i++) {
                var rc = rects[i];
                if (rc.width < 3 || rc.height < 4) continue;
                var bh = Math.max(6, Math.round(rc.height * 0.6));
                var top = rc.top + (rc.height - bh) / 2;
                var prev = items[items.length - 1];
                var px = Math.round((rc.left - box.left) / box.width * 1000);
                // Join the pieces of one line (e.g. <strong> inside a sentence) into one bar.
                if (prev && prev[0] === T_TEXT && Math.abs(prev[2] - Math.round(top - box.top)) <= 3
                    && px - (prev[1] + prev[3]) < 12 && px >= prev[1]) {
                    prev[3] = Math.max(prev[3], Math.round((rc.right - box.left) / box.width * 1000) - prev[1]);
                    prev[4] = Math.max(prev[4], bh);
                    continue;
                }
                push(T_TEXT, rc.left, top, rc.width, bh, Math.round(bh / 2));
            }
        }

        function visit(el) {
            for (var n = el.firstChild; n && items.length < MAX_ITEMS; n = n.nextSibling) {
                if (n.nodeType === 3) { textLines(n); continue; }
                if (n.nodeType !== 1 || SKIP_TAGS[n.tagName]) continue;
                var cl = n.classList;
                if (cl.contains('modal') || cl.contains('offcanvas') || cl.contains('ovs-bar')
                    || (cl.contains('dropdown-menu') && !cl.contains('show'))) continue;

                var r = n.getBoundingClientRect();
                if (r.width < 2 || r.height < 2) continue;
                if (r.top - box.top >= limit || r.bottom <= box.top) continue;
                var cs = getComputedStyle(n);
                if (cs.visibility === 'hidden' || cs.opacity === '0' || cs.position === 'fixed') continue;

                if (isBlock(n, r)) {
                    push(T_BLOCK, r.left, r.top, r.width, r.height, radius(cs, r.height));
                    continue;
                }

                if (r.width * r.height >= 2500 && !NO_PANEL_TAGS[n.tagName]) {
                    var bgA = alpha(cs.backgroundColor);
                    var bordered = parseFloat(cs.borderTopWidth) > 0 && parseFloat(cs.borderLeftWidth) > 0
                        && alpha(cs.borderTopColor) > 0;
                    var shadow = cs.boxShadow && cs.boxShadow !== 'none';
                    if (bgA > 0.2 && !isLight(cs.backgroundColor)) {
                        push(T_TINT, r.left, r.top, r.width, r.height, radius(cs, r.height));
                    } else if (bordered || shadow) {
                        push(T_PANEL, r.left, r.top, r.width, r.height, radius(cs, r.height));
                    } else if (bgA > 0.2 || cs.backgroundImage !== 'none') {
                        push(T_FILL, r.left, r.top, r.width, r.height, radius(cs, r.height));
                    }
                }
                visit(n);
            }
        }

        try {
            visit(main);
        } catch (e) {
            return;
        }
        if (items.length < 3) return;

        var store = readStore();
        var snap = { w: Math.round(box.width), h: Math.round(maxBottom), t: Date.now(), i: items };
        store[keys.exact] = snap;
        store[keys.page] = snap;
        writeStore(store);
    }

    function ghostHtml(snap) {
        var html = '<div class="dsk-ghost" aria-hidden="true" style="height:' + snap.h + 'px">';
        snap.i.forEach(function (it) {
            var cls = it[0] === T_PANEL ? 'dsk-panel'
                : it[0] === T_FILL ? 'dsk-fill'
                : it[0] === T_TINT ? 'dsk-tint dental-skeleton-block'
                : 'dental-skeleton-block' + (it[0] === T_TEXT ? ' dsk-text' : '');
            html += '<div class="dsk-i ' + cls + '" style="left:' + (it[1] / 10) + '%;top:' + it[2]
                + 'px;width:' + (it[3] / 10) + '%;height:' + it[4] + 'px;border-radius:' + it[5] + 'px"></div>';
        });
        return html + '</div>';
    }

    /* ---- Fallback templates (first visit to a page) ---- */

    function bar(w, h, extra) {
        return '<span class="dental-skeleton-block dsk-bar" style="width:' + w + ';height:' + (h || 12) + 'px' + (extra ? ';' + extra : '') + '"></span>';
    }

    function repeat(n, fn) {
        var s = '';
        for (var i = 0; i < n; i++) s += fn(i);
        return s;
    }

    var HEADER = '<div class="dsk-row dsk-head">' + bar('220px', 22) + '<span class="dsk-grow"></span>' + bar('120px', 36, 'border-radius:8px') + '</div>';
    var TABS = '<div class="dsk-row dsk-tabs">' + repeat(4, function (i) { return bar([96, 120, 84, 104][i] + 'px', 30, 'border-radius:8px'); }) + '</div>';
    var TOOLBAR = '<div class="dsk-row dsk-toolbar">' + bar('min(320px, 60%)', 38, 'border-radius:10px') + '<span class="dsk-grow"></span>' + bar('96px', 38, 'border-radius:8px') + '</div>';

    function tableCard(rows) {
        var widths = [['30%', '22%', '18%', '12%'], ['24%', '28%', '14%', '12%'], ['34%', '18%', '20%', '12%']];
        return '<div class="dsk-card dsk-table">'
            + '<div class="dsk-trow dsk-thead">' + repeat(4, function (c) { return '<div>' + bar(['40%', '50%', '45%', '35%'][c], 10) + '</div>'; }) + '</div>'
            + repeat(rows, function (r) {
                return '<div class="dsk-trow">' + repeat(4, function (c) {
                    return '<div>' + (c === 3 ? bar('64px', 28, 'border-radius:8px') : bar(widths[r % 3][c].replace('%', '') * 2.6 + '%', 12)) + '</div>';
                }) + '</div>';
            })
            + '</div>';
    }

    var TEMPLATES = {
        dashboard: function () {
            return '<div class="dental-skeleton-block dental-skeleton-hero"></div>'
                + '<div class="dental-skeleton-kpi-row">' + repeat(4, function () { return '<div class="dental-skeleton-block dental-skeleton-kpi"></div>'; }) + '</div>'
                + '<div class="dental-skeleton-panels">' + repeat(2, function () { return '<div class="dental-skeleton-block dental-skeleton-panel"></div>'; }) + '</div>';
        },
        table: function () {
            return HEADER + TABS + TOOLBAR + tableCard(8);
        },
        calendar: function () {
            return HEADER
                + '<div class="dsk-card">'
                + '<div class="dsk-row">' + bar('180px', 22) + '<span class="dsk-grow"></span>' + repeat(3, function () { return bar('40px', 32, 'border-radius:8px'); }) + '</div>'
                + '<div class="dsk-cal">' + repeat(7, function () { return '<div class="dsk-cal-dow">' + bar('50%', 10) + '</div>'; })
                + repeat(35, function () { return '<div class="dsk-cal-cell">' + bar('22px', 12) + '</div>'; }) + '</div>'
                + '</div>';
        },
        form: function () {
            return '<div class="dsk-card">'
                + '<div class="dsk-row dsk-card-head">' + bar('160px', 20) + '</div>'
                + '<div class="dsk-row">' + bar('88px', 88, 'border-radius:50%') + '<div class="dsk-stack">' + bar('200px', 16) + bar('140px', 12) + '</div></div>'
                + '<div class="dsk-form">' + repeat(6, function () { return '<div class="dsk-stack">' + bar('90px', 10) + bar('100%', 38, 'border-radius:8px') + '</div>'; }) + '</div>'
                + '<div class="dsk-row dsk-end">' + bar('130px', 38, 'border-radius:8px') + '</div>'
                + '</div>';
        },
        list: function () {
            return '<div class="dsk-row dsk-head">' + bar('160px', 20) + '<span class="dsk-grow"></span>' + bar('130px', 30, 'border-radius:8px') + '</div>'
                + '<div class="dsk-card dsk-list">' + repeat(7, function (i) {
                    return '<div class="dsk-row">' + bar('36px', 36, 'border-radius:50%') + '<div class="dsk-stack dsk-grow">' + bar(['70%', '55%', '80%'][i % 3], 12) + bar('30%', 10) + '</div></div>';
                }) + '</div>';
        },
        cards: function () {
            return HEADER + TOOLBAR + '<div class="dsk-cards">' + repeat(6, function () {
                return '<div class="dsk-card">' + bar('100%', 120, 'border-radius:10px') + bar('70%', 16) + bar('90%', 10) + bar('40%', 10) + '</div>';
            }) + '</div>';
        },
        reports: function () {
            return HEADER + TABS + TEMPLATES.dashboard().replace('<div class="dental-skeleton-block dental-skeleton-hero"></div>', '') + '<div style="height:1rem"></div>' + tableCard(4);
        },
    };

    function pageType(keys) {
        var p = keys.name;
        if (p === 'dashboard') return 'dashboard';
        if (/profile|settings/.test(p)) return 'form';
        if (/notification/.test(p)) return 'list';
        if (p === 'reports') return 'reports';
        if (/schedul|leave|doctor_management/.test(p)) return 'calendar';
        if (keys.portal === 'patient' && p === 'services') return 'cards';
        return 'table';
    }

    /** Fill #main with a skeleton shaped like the page at url. */
    function show(main, url) {
        if (!main) return;
        var keys = pageKeys(url);
        if (!keys) {
            main.innerHTML = '<div class="dsk-tpl">' + TEMPLATES.table() + '</div>';
            return;
        }
        var store = readStore();
        var snap = store[keys.exact] || store[keys.page];
        var width = contentBox(main).width;
        if (snap && snap.i && Math.abs(snap.w - width) / snap.w <= WIDTH_TOLERANCE) {
            main.innerHTML = ghostHtml(snap);
            return;
        }
        main.innerHTML = '<div class="dsk-tpl" aria-hidden="true">' + TEMPLATES[pageType(keys)]() + '</div>';
    }

    window.DentalPageSkeleton = { capture: capture, show: show };

    // Full reloads (back/forward) also leave a page - snapshot it too. Skipped on
    // mobile widths, which don't use AJAX nav and would overwrite desktop snapshots.
    window.addEventListener('pagehide', function () {
        if (window.innerWidth > 991) capture(document.querySelector('#main'), window.location.href);
    });
})();
