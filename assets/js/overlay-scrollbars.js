/**
 * Overlay scrollbars for every scrollable area (page, sidebars, tables, modals).
 *
 * Native scrollbars are hidden and replaced with thin bars that float over the
 * content, so they never take up width/height or push layout. A bar appears
 * while its area scrolls, while the pointer hovers a small scroll area (e.g. a
 * table), or when the pointer is near the right/bottom edge of a large one
 * (e.g. the page), then fades out. Thumbs can be dragged.
 *
 * Desktop (fine pointer) only - touch devices already use overlay scrollbars.
 * Opt out per element with the data-native-scrollbar attribute.
 */
(function () {
    'use strict';

    if (window.__overlayScrollbars) return;
    window.__overlayScrollbars = true;
    if (!window.matchMedia || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

    const root = document.documentElement;
    root.classList.add('ovs-enabled');

    const HIDE_DELAY = 1200;
    const EDGE = 24;        // px from a large area's right/bottom edge that reveals its bar
    const SIZE = 10;        // bar thickness
    const INSET = 2;        // gap between the bar and the area's edge
    const MIN_THUMB = 32;
    const LARGE_AREA = 0.5; // areas covering more of the viewport than this are edge-reveal only

    const states = new Map();
    const pointer = { x: -1, y: -1, target: null };
    let hovered = null;
    let drag = null;
    let loopId = 0;
    let moveId = 0;

    function pageEl() {
        return document.scrollingElement || root;
    }

    function isPage(el) {
        return el === root || el === document.body || el === document.scrollingElement;
    }

    function canon(el) {
        return isPage(el) ? pageEl() : el;
    }

    /**
     * False once el is hidden or being hidden - e.g. a modal that just closed. Modals
     * flip aria-hidden="true" immediately but fade out over a few hundred ms while
     * keeping their size, so without this their bar lingered until the mouse moved.
     */
    function isShown(el) {
        if (el.closest('[aria-hidden="true"]')) return false;
        if (typeof el.checkVisibility === 'function') {
            return el.checkVisibility({ visibilityProperty: true, opacityProperty: true });
        }
        return true;
    }

    /** Which axes of el currently overflow and are user-scrollable, or null. */
    function scrollAxes(el) {
        if (!(el instanceof Element) || el.tagName === 'SELECT' || el.hasAttribute('data-native-scrollbar')) return null;
        let y, x;
        if (isPage(el)) {
            const se = pageEl();
            const hs = getComputedStyle(root);
            const bs = getComputedStyle(document.body);
            // Modals lock the page with overflow: hidden.
            y = hs.overflowY !== 'hidden' && bs.overflowY !== 'hidden' && se.scrollHeight > se.clientHeight + 1;
            x = hs.overflowX !== 'hidden' && bs.overflowX !== 'hidden' && se.scrollWidth > se.clientWidth + 1;
        } else {
            if (!isShown(el)) return null;
            const cs = getComputedStyle(el);
            y = (cs.overflowY === 'auto' || cs.overflowY === 'scroll') && el.scrollHeight > el.clientHeight + 1;
            x = (cs.overflowX === 'auto' || cs.overflowX === 'scroll') && el.scrollWidth > el.clientWidth + 1;
        }
        return y || x ? { y, x } : null;
    }

    /** On-screen box of el's scrollport, clipped by overflow ancestors and the viewport. */
    function visibleRect(el) {
        const vw = root.clientWidth;
        const vh = root.clientHeight;
        if (isPage(el)) return { left: 0, top: 0, right: vw, bottom: vh };
        if (!el.isConnected) return null;

        const r = el.getBoundingClientRect();
        if (r.width === 0 && r.height === 0) return null;
        let left = r.left + el.clientLeft;
        let top = r.top + el.clientTop;
        let right = left + el.clientWidth;
        let bottom = top + el.clientHeight;

        for (let p = el.parentElement; p && p !== document.body && p !== root; p = p.parentElement) {
            const cs = getComputedStyle(p);
            if (cs.overflowX !== 'visible' || cs.overflowY !== 'visible') {
                const pr = p.getBoundingClientRect();
                left = Math.max(left, pr.left);
                top = Math.max(top, pr.top);
                right = Math.min(right, pr.right);
                bottom = Math.min(bottom, pr.bottom);
            }
            if (cs.position === 'fixed') break;
        }
        left = Math.max(left, 0);
        top = Math.max(top, 0);
        right = Math.min(right, vw);
        bottom = Math.min(bottom, vh);
        return right - left >= 24 && bottom - top >= 24 ? { left, top, right, bottom } : null;
    }

    function isLarge(el, rect) {
        if (isPage(el)) return true;
        const area = (rect.right - rect.left) * (rect.bottom - rect.top);
        return area > LARGE_AREA * root.clientWidth * root.clientHeight;
    }

    function nearEdge(rect, axes) {
        if (!rect || !axes) return false;
        const { x, y } = pointer;
        const inX = x >= rect.left && x <= rect.right;
        const inY = y >= rect.top && y <= rect.bottom;
        return (axes.y && inY && x >= rect.right - EDGE && x <= rect.right)
            || (axes.x && inX && y >= rect.bottom - EDGE && y <= rect.bottom);
    }

    function makeBar(s, axis) {
        const bar = document.createElement('div');
        bar.className = 'ovs-bar ovs-bar--' + axis;
        bar.hidden = true;
        bar.setAttribute('aria-hidden', 'true');
        const thumb = document.createElement('div');
        thumb.className = 'ovs-thumb';
        bar.appendChild(thumb);
        document.body.appendChild(bar);

        const b = { bar, thumb, axis, track: 0, len: 0, max: 0 };

        thumb.addEventListener('mousedown', function (e) {
            if (e.button !== 0) return;
            e.preventDefault();
            e.stopPropagation();
            const se = s.el;
            drag = {
                s, b,
                start: axis === 'y' ? e.clientY : e.clientX,
                startScroll: axis === 'y' ? se.scrollTop : se.scrollLeft,
                prevBehavior: se.style.scrollBehavior,
            };
            se.style.scrollBehavior = 'auto';
            bar.classList.add('is-dragging');
            root.classList.add('ovs-dragging');
        });

        // Wheel over a thumb scrolls its area instead of whatever is underneath.
        thumb.addEventListener('wheel', function (e) {
            e.preventDefault();
            s.el.scrollBy(e.deltaX, e.deltaY);
        }, { passive: false });

        return b;
    }

    function getState(el) {
        let s = states.get(el);
        if (!s) {
            sweep();
            s = { el, visible: false, timer: 0, rect: null, axes: null };
            s.v = makeBar(s, 'y');
            s.h = makeBar(s, 'x');
            states.set(el, s);
        }
        return s;
    }

    function sweep() {
        states.forEach(function (s, el) {
            if (!el.isConnected) {
                clearTimeout(s.timer);
                s.v.bar.remove();
                s.h.bar.remove();
                states.delete(el);
            }
        });
    }

    function placeBar(b, rect, scrollPos, scrollSize, clientSize, both) {
        const vertical = b.axis === 'y';
        const track = (vertical ? rect.bottom - rect.top : rect.right - rect.left) - INSET * 2 - (both ? SIZE : 0);
        const len = Math.min(track, Math.max(MIN_THUMB, track * (clientSize / scrollSize)));
        const max = scrollSize - clientSize;
        const pos = max > 0 ? Math.min(1, Math.max(0, scrollPos / max)) * (track - len) : 0;
        const st = b.bar.style;

        if (vertical) {
            st.left = (rect.right - SIZE - INSET) + 'px';
            st.top = (rect.top + INSET) + 'px';
            st.width = SIZE + 'px';
            st.height = track + 'px';
            b.thumb.style.height = len + 'px';
            b.thumb.style.transform = 'translateY(' + pos + 'px)';
        } else {
            st.left = (rect.left + INSET) + 'px';
            st.top = (rect.bottom - SIZE - INSET) + 'px';
            st.height = SIZE + 'px';
            st.width = track + 'px';
            b.thumb.style.width = len + 'px';
            b.thumb.style.transform = 'translateX(' + pos + 'px)';
        }
        b.track = track;
        b.len = len;
        b.max = max;
        b.bar.hidden = false;
    }

    /** Position both bars of s; returns false when the area can't show a bar. */
    function layout(s) {
        const el = s.el;
        const axes = el.isConnected ? scrollAxes(el) : null;
        const rect = axes ? visibleRect(el) : null;
        s.axes = axes;
        s.rect = rect;
        if (!rect) return false;

        // Bars must live inside a fullscreen element to be painted over it.
        const fs = document.fullscreenElement;
        const host = fs && fs.tagName !== 'IFRAME' ? fs : document.body;
        if (s.v.bar.parentNode !== host) host.append(s.v.bar, s.h.bar);

        const both = axes.y && axes.x;
        if (axes.y) placeBar(s.v, rect, el.scrollTop, el.scrollHeight, el.clientHeight, both);
        else s.v.bar.hidden = true;
        if (axes.x) placeBar(s.h, rect, el.scrollLeft, el.scrollWidth, el.clientWidth, both);
        else s.h.bar.hidden = true;
        return true;
    }

    function setVisible(s, on) {
        s.visible = on;
        s.v.bar.classList.toggle('is-visible', on);
        s.h.bar.classList.toggle('is-visible', on);
    }

    function shouldStay(s) {
        if (drag && drag.s === s) return true;
        if (s.v.thumb.matches(':hover') || s.h.thumb.matches(':hover')) return true;
        if (s.el === hovered && s.rect && !isLarge(s.el, s.rect)) return true;
        return nearEdge(s.rect, s.axes);
    }

    function scheduleHide(s, delay) {
        clearTimeout(s.timer);
        s.timer = setTimeout(function () {
            if (shouldStay(s)) scheduleHide(s, 400);
            else setVisible(s, false);
        }, delay);
    }

    function reveal(el) {
        const s = getState(canon(el));
        if (!layout(s)) {
            setVisible(s, false);
            return;
        }
        setVisible(s, true);
        scheduleHide(s, HIDE_DELAY);
        if (!loopId) loopId = requestAnimationFrame(loop);
    }

    // Keep visible bars glued to their areas while content moves or resizes.
    function loop() {
        loopId = 0;
        let any = false;
        states.forEach(function (s) {
            if (!s.visible) return;
            if (layout(s)) any = true;
            else setVisible(s, false);
        });
        if (any) loopId = requestAnimationFrame(loop);
    }

    function onPointerFrame() {
        moveId = 0;
        const t = pointer.target;
        if (!(t instanceof Element) || t.closest('.ovs-bar')) return;

        const list = [];
        for (let el = t; el && el !== document.body && el !== root; el = el.parentElement) {
            const axes = scrollAxes(el);
            if (axes) list.push({ el, axes });
        }
        const pageAxes = scrollAxes(pageEl());
        if (pageAxes) list.push({ el: pageEl(), axes: pageAxes });

        hovered = list.length ? list[0].el : null;
        list.forEach(function (item, i) {
            const rect = visibleRect(item.el);
            if (!rect) return;
            if ((i === 0 && !isLarge(item.el, rect)) || nearEdge(rect, item.axes)) reveal(item.el);
        });
    }

    document.addEventListener('scroll', function (e) {
        const t = e.target === document ? pageEl() : e.target;
        if (t instanceof Element) reveal(t);
    }, { capture: true, passive: true });

    document.addEventListener('mousemove', function (e) {
        pointer.x = e.clientX;
        pointer.y = e.clientY;
        pointer.target = e.target;

        if (drag) {
            const b = drag.b;
            const delta = (b.axis === 'y' ? e.clientY : e.clientX) - drag.start;
            const ratio = b.max / Math.max(1, b.track - b.len);
            const value = drag.startScroll + delta * ratio;
            if (b.axis === 'y') drag.s.el.scrollTop = value;
            else drag.s.el.scrollLeft = value;
            return;
        }
        if (!moveId) moveId = requestAnimationFrame(onPointerFrame);
    }, { passive: true });

    document.addEventListener('mouseup', function () {
        if (!drag) return;
        const s = drag.s;
        s.el.style.scrollBehavior = drag.prevBehavior;
        drag.b.bar.classList.remove('is-dragging');
        root.classList.remove('ovs-dragging');
        drag = null;
        scheduleHide(s, HIDE_DELAY);
    });

    document.addEventListener('mouseleave', function () {
        hovered = null;
        pointer.x = pointer.y = -1;
        pointer.target = null;
    });
})();
