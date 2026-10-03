import { SURFACE_LABELS, toothHasMissing } from "./chart-constants.js";
import { toothAnatomicalName } from "./tooth-names.js";
import {
  appendToothArtwork,
  injectChartSymbols,
  templateForFdi,
  toothArtLayout,
} from "./tooth-2d-art.js";
import {
  crownSurfacePolygons,
  detailArtLayout,
  surfaceFillColor,
  surfaceStroke,
} from "./tooth-2d-surfaces.js";

const SVG_NS = "http://www.w3.org/2000/svg";
const DETAIL_PREFIX = "detail-";
const DETAIL_SURFACES = ["O", "M", "D", "B", "L", "root"];

let rootEl = null;
let svgHost = null;
let listEl = null;
let placeholderEl = null;
let bodyEl = null;
let currentFdi = null;
let onSurfaceClick = null;

function addSurfacePolygon(parent, surface, points, labels, interactive) {
  const poly = document.createElementNS(SVG_NS, "polygon");
  poly.setAttribute("class", "svg-surface detail-surface");
  poly.setAttribute("data-surface", surface);
  poly.setAttribute("points", points.map((p) => p.join(",")).join(" "));
  poly.setAttribute("fill", surfaceFillColor(labels));
  poly.setAttribute("stroke", surfaceStroke(labels));
  poly.setAttribute("stroke-width", "1");
  poly.setAttribute("pointer-events", interactive ? "all" : "none");
  if (labels?.length) poly.setAttribute("fill-opacity", "0.88");
  parent.appendChild(poly);

  const code = labels?.length ? labels[labels.length - 1] : "";
  if (code) {
    const cx = points.reduce((s, p) => s + p[0], 0) / points.length;
    const cy = points.reduce((s, p) => s + p[1], 0) / points.length;
    const badge = document.createElementNS(SVG_NS, "text");
    badge.setAttribute("class", "svg-surface-code detail-surface-code");
    badge.setAttribute("x", String(cx));
    badge.setAttribute("y", String(cy + 4));
    badge.setAttribute("text-anchor", "middle");
    badge.textContent = code;
    parent.appendChild(badge);
  }
}

/** Selected-tooth preview (~2.3× former 72px display size). */
const DETAIL_BOX_W = 166;
const DETAIL_BOX_H = 232;

function layoutForDetail(fdi) {
  const layout = detailArtLayout(fdi, DETAIL_BOX_W, DETAIL_BOX_H);
  layout.file = templateForFdi(fdi);
  return { layout, boxW: DETAIL_BOX_W, boxH: DETAIL_BOX_H };
}

function renderDetailSvg(fdi, entry) {
  if (!svgHost) return;
  const { layout, boxW, boxH } = layoutForDetail(fdi);
  const surfaces = entry?.surfaces || {};

  svgHost.innerHTML = "";
  const svg = document.createElementNS(SVG_NS, "svg");
  svg.setAttribute("viewBox", `0 0 ${boxW} ${boxH}`);
  svg.setAttribute("class", "tooth-detail-svg__canvas");
  svg.setAttribute("role", "img");
  svg.setAttribute("aria-label", `Tooth ${fdi} surface chart`);

  injectChartSymbols(svg, DETAIL_PREFIX);

  const g = document.createElementNS(SVG_NS, "g");
  g.setAttribute("class", "tooth-detail-svg__tooth");
  g.classList.toggle("is-missing", toothHasMissing(entry));

  appendToothArtwork(g, layout, DETAIL_PREFIX);

  const hits = document.createElementNS(SVG_NS, "g");
  hits.setAttribute("class", "tooth-detail-svg__hits");

  const root = document.createElementNS(SVG_NS, "ellipse");
  root.setAttribute("class", "svg-surface detail-surface");
  root.setAttribute("data-surface", "root");
  root.setAttribute("cx", String(layout.cx));
  root.setAttribute("cy", String(layout.rootY + layout.rootH / 2));
  root.setAttribute("rx", String(layout.rootW / 2));
  root.setAttribute("ry", String(layout.rootH / 2));
  root.setAttribute("fill", surfaceFillColor(surfaces.root));
  root.setAttribute("stroke", surfaceStroke(surfaces.root));
  root.setAttribute("pointer-events", "all");
  if (surfaces.root?.length) root.setAttribute("fill-opacity", "0.88");

  const polys = crownSurfacePolygons(fdi, layout.cx, layout.crownY, layout.crownW, layout.crownH);
  for (const key of ["M", "D", "B", "L", "O"]) {
    addSurfacePolygon(hits, key, polys[key], surfaces[key], true);
  }
  hits.appendChild(root);

  const rootCode = surfaces.root?.length ? surfaces.root[surfaces.root.length - 1] : "";
  if (rootCode) {
    const text = document.createElementNS(SVG_NS, "text");
    text.setAttribute("class", "svg-surface-code detail-surface-code");
    text.setAttribute("x", root.getAttribute("cx"));
    text.setAttribute("y", String(Number(root.getAttribute("cy")) + 4));
    text.setAttribute("text-anchor", "middle");
    text.textContent = rootCode;
    hits.appendChild(text);
  }

  g.appendChild(hits);
  svg.appendChild(g);
  svgHost.appendChild(svg);

  svg.addEventListener("click", (ev) => {
    const target = ev.target.closest("[data-surface]");
    if (!target) return;
    onSurfaceClick?.(target.getAttribute("data-surface"));
  });
}

function surfaceChipLabel(key) {
  const full = SURFACE_LABELS[key] || key;
  if (key === "root") return "Root";
  return full.split("/")[0].split(" ")[0];
}

function renderSurfaceList(fdi, entry, selectedSurface, legendByCode) {
  if (!listEl) return;
  const surfaces = entry?.surfaces || {};

  listEl.innerHTML = DETAIL_SURFACES.map((key) => {
    const labels = surfaces[key] || [];
    const primary = labels.length ? labels[labels.length - 1] : "";
    const item = primary ? legendByCode[primary] : null;
    const swatch = item?.color || "#e2e8f0";
    const isActive = selectedSurface === key;
    const activeClass = isActive ? " is-active" : "";
    const codeHtml = primary
      ? `<span class="chart-surface-chip__code"><span class="chart-surface-chip__swatch" style="background:${swatch}"></span>${primary}</span>`
      : `<span class="chart-surface-chip__code is-empty">—</span>`;

    const inner = `
      <span class="chart-surface-chip__key">${key === "root" ? "R" : key}</span>
      <span class="chart-surface-chip__name">${surfaceChipLabel(key)}</span>
      ${codeHtml}
    `;

    return `<button type="button" class="chart-surface-chip${activeClass}" data-surface-key="${key}">${inner}</button>`;
  }).join("");
}

export function initToothDetailPanel(container, options = {}) {
  rootEl = container;
  onSurfaceClick = options.onSurfaceClick;
  legendByCode = options.legendByCode || {};

  rootEl.innerHTML = `
    <p class="chart-tooth-detail__placeholder">Select a tooth on the chart below to pick surfaces here.</p>
    <div class="chart-tooth-detail__body is-hidden">
      <div class="chart-tooth-detail__tooth">
        <div class="chart-tooth-detail__badge">
          <span id="detailToothName" class="chart-tooth-detail__name">—</span>
          <span class="chart-tooth-detail__fdi">FDI <strong id="detailToothLabel">—</strong></span>
        </div>
        <div class="tooth-detail-svg" id="toothDetailSvgHost"></div>
      </div>
      <div class="chart-tooth-detail__surfaces" id="toothDetailSurfaces" role="toolbar" aria-label="Tooth surfaces"></div>
    </div>
  `;

  placeholderEl = rootEl.querySelector(".chart-tooth-detail__placeholder");
  bodyEl = rootEl.querySelector(".chart-tooth-detail__body");
  svgHost = rootEl.querySelector("#toothDetailSvgHost");
  listEl = rootEl.querySelector("#toothDetailSurfaces");

  listEl?.addEventListener("click", (e) => {
    const row = e.target.closest("[data-surface-key]");
    if (!row) return;
    onSurfaceClick?.(row.dataset.surfaceKey);
  });
}

let legendByCode = {};

export function showToothDetail(fdi, entry, selectedSurface) {
  if (!rootEl) return;
  currentFdi = fdi ? String(fdi) : null;

  if (!currentFdi) {
    placeholderEl?.classList.remove("is-hidden");
    bodyEl?.classList.add("is-hidden");
    return;
  }

  placeholderEl?.classList.add("is-hidden");
  bodyEl?.classList.remove("is-hidden");

  const nameEl = rootEl.querySelector("#detailToothName");
  const label = rootEl.querySelector("#detailToothLabel");
  if (nameEl) nameEl.textContent = toothAnatomicalName(currentFdi);
  if (label) label.textContent = currentFdi;

  renderDetailSvg(currentFdi, entry || { surfaces: {} });
  renderSurfaceList(currentFdi, entry || { surfaces: {} }, selectedSurface, legendByCode);
}

export function setActiveDetailSurface(surface) {
  if (!listEl) return;
  listEl.querySelectorAll(".chart-surface-chip").forEach((row) => {
    row.classList.toggle("is-active", row.dataset.surfaceKey === surface);
  });

  const svg = svgHost?.querySelector("svg");
  svg?.querySelectorAll(".detail-surface").forEach((el) => {
    el.classList.toggle("is-active-surface", el.getAttribute("data-surface") === surface);
  });
}

export function applyDetailVisualStatus(fdi, entry, selectedSurface) {
  if (currentFdi !== String(fdi)) return;
  renderDetailSvg(fdi, entry);
  renderSurfaceList(fdi, entry, selectedSurface, legendByCode);
  setActiveDetailSurface(selectedSurface);
}

function addArchStatusPolygon(parent, surface, points, labels, layout) {
  const poly = document.createElementNS(SVG_NS, "polygon");
  poly.setAttribute("class", "svg-tooth__status-surface");
  poly.setAttribute("data-surface", surface);
  poly.setAttribute("points", points.map((p) => p.join(",")).join(" "));
  poly.setAttribute("fill", surfaceFillColor(labels));
  if (labels?.length) poly.setAttribute("fill-opacity", "0.85");
  poly.setAttribute("stroke", "none");
  parent.appendChild(poly);

  const code = labels?.length ? labels[labels.length - 1] : "";
  if (!code) return;

  let lx;
  let ly;
  if (surface === "O") {
    lx = layout.cx;
    ly = layout.crownY + layout.crownH / 2;
  } else {
    lx = points.reduce((s, p) => s + p[0], 0) / points.length;
    ly = points.reduce((s, p) => s + p[1], 0) / points.length;
  }

  const badge = document.createElementNS(SVG_NS, "text");
  badge.setAttribute("class", "svg-surface-code arch-surface-code");
  badge.setAttribute("x", String(lx));
  badge.setAttribute("y", String(ly + 3));
  badge.setAttribute("text-anchor", "middle");
  badge.setAttribute("dominant-baseline", "middle");
  badge.textContent = code;
  parent.appendChild(badge);
}

function addArchRootStatus(parent, layout, labels) {
  const rootFill = document.createElementNS(SVG_NS, "ellipse");
  rootFill.setAttribute("class", "svg-tooth__status-surface");
  rootFill.setAttribute("data-surface", "root");
  rootFill.setAttribute("cx", String(layout.cx));
  rootFill.setAttribute("cy", String(layout.rootY + layout.rootH / 2));
  rootFill.setAttribute("rx", String(layout.rootW / 2));
  rootFill.setAttribute("ry", String(layout.rootH / 2));
  rootFill.setAttribute("fill", surfaceFillColor(labels));
  if (labels?.length) rootFill.setAttribute("fill-opacity", "0.85");
  rootFill.setAttribute("stroke", "none");
  parent.appendChild(rootFill);

  const code = labels?.length ? labels[labels.length - 1] : "";
  if (!code) return;

  const badge = document.createElementNS(SVG_NS, "text");
  badge.setAttribute("class", "svg-surface-code arch-surface-code");
  badge.setAttribute("x", String(layout.cx));
  badge.setAttribute("y", String(layout.rootY + layout.rootH / 2 + 3));
  badge.setAttribute("text-anchor", "middle");
  badge.setAttribute("dominant-baseline", "middle");
  badge.textContent = code;
  parent.appendChild(badge);
}

/** Mini arch tooth status overlays (labels only, not clickable). */
export function appendArchStatusOverlays(g, fdi, layout, entry) {
  const surfaces = entry?.surfaces || {};
  const status = document.createElementNS(SVG_NS, "g");
  status.setAttribute("class", "svg-tooth__status");
  status.setAttribute("pointer-events", "none");

  addArchRootStatus(status, layout, surfaces.root || []);

  const polys = crownSurfacePolygons(fdi, layout.cx, layout.crownY, layout.crownW, layout.crownH);
  for (const key of ["M", "D", "B", "L", "O"]) {
    addArchStatusPolygon(status, key, polys[key], surfaces[key] || [], layout);
  }

  g.appendChild(status);
}

export function updateArchStatusOverlays(g, entry) {
  g.querySelectorAll(".svg-tooth__status").forEach((el) => el.remove());
  const fdi = g.getAttribute("data-tooth");
  const cellX = parseFloat(g.getAttribute("data-cell-x") || "0");
  const cellY = parseFloat(g.getAttribute("data-cell-y") || "0");
  const isUpper = g.getAttribute("data-is-upper") === "1";
  const layout = toothArtLayout(fdi, cellX, cellY, 46, 78, isUpper);
  appendArchStatusOverlays(g, fdi, layout, entry);
  g.classList.toggle("is-missing", toothHasMissing(entry));
}
