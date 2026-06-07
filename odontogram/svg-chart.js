import {
  ALL_FDI_TEETH,
  CHART_LOWER_LEFT,
  CHART_LOWER_RIGHT,
  CHART_UPPER_LEFT,
  CHART_UPPER_RIGHT,
  colorForLabels,
  isMesialRight,
  toothHasMissing,
} from "./chart-constants.js";

export const toothSvgElements = new Map();

const GRID = {
  startX: 28,
  startY: 48,
  cellW: 42,
  cellH: 72,
  gap: 3,
  midGap: 18,
};

function quadrantStartX(quadrantIndex) {
  const qWidth = 8 * GRID.cellW + 7 * GRID.gap;
  if (quadrantIndex === 0) return GRID.startX;
  return GRID.startX + qWidth + GRID.midGap;
}

function toothWidthScale(fdi) {
  const idx = ALL_FDI_TEETH.indexOf(String(fdi)) % 8;
  if (idx <= 1 || idx >= 6) return 0.92;
  if (idx === 2 || idx === 5) return 0.82;
  return 0.72;
}

function addSurfacePolygon(parent, fdi, surface, points, labels) {
  const poly = document.createElementNS("http://www.w3.org/2000/svg", "polygon");
  poly.setAttribute("class", "svg-surface");
  poly.setAttribute("data-tooth", String(fdi));
  poly.setAttribute("data-surface", surface);
  poly.setAttribute("points", points.map((p) => p.join(",")).join(" "));
  poly.setAttribute("fill", colorForLabels(labels));
  poly.setAttribute("stroke", "#334155");
  poly.setAttribute("stroke-width", "0.8");
  parent.appendChild(poly);

  const code = labels?.length ? labels[labels.length - 1] : "";
  if (code) {
    const cx = points.reduce((s, p) => s + p[0], 0) / points.length;
    const cy = points.reduce((s, p) => s + p[1], 0) / points.length;
    const badge = document.createElementNS("http://www.w3.org/2000/svg", "text");
    badge.setAttribute("class", "svg-surface-code");
    badge.setAttribute("x", String(cx));
    badge.setAttribute("y", String(cy + 3));
    badge.setAttribute("text-anchor", "middle");
    badge.textContent = code;
    parent.appendChild(badge);
  }
}

function crownSurfacePolygons(fdi, cx, crownY, crownW, crownH) {
  const x1 = cx - crownW / 2;
  const x2 = cx + crownW / 2;
  const y1 = crownY;
  const y2 = crownY + crownH;
  const mx = cx;
  const my = crownY + crownH / 2;
  const inset = crownW * 0.22;
  const insY = crownH * 0.24;

  const mesialRight = isMesialRight(fdi);
  const mX1 = mesialRight ? mx : x1;
  const mX2 = mesialRight ? x2 : mx;
  const dX1 = mesialRight ? x1 : mx;
  const dX2 = mesialRight ? mx : x2;

  return {
    O: [
      [mx - inset * 0.55, my - insY * 0.45],
      [mx + inset * 0.55, my - insY * 0.45],
      [mx + inset * 0.55, my + insY * 0.45],
      [mx - inset * 0.55, my + insY * 0.45],
    ],
    B: [
      [x1, y1],
      [x2, y1],
      [mx + inset * 0.55, my - insY * 0.45],
      [mx - inset * 0.55, my - insY * 0.45],
    ],
    L: [
      [mx - inset * 0.55, my + insY * 0.45],
      [mx + inset * 0.55, my + insY * 0.45],
      [x2, y2],
      [x1, y2],
    ],
    M: [
      [mX1, y1],
      [mX2, y1],
      [mX2, y2],
      [mX1, y2],
    ],
    D: [
      [dX1, y1],
      [dX2, y1],
      [dX2, y2],
      [dX1, y2],
    ],
  };
}

function createClinicalTooth(fdi, cellX, cellY, isUpper, entry) {
  const scale = toothWidthScale(fdi);
  const crownW = GRID.cellW * 0.62 * scale;
  const crownH = GRID.cellH * 0.34;
  const rootW = crownW * 0.48;
  const rootH = GRID.cellH * 0.22;
  const cx = cellX + GRID.cellW / 2;
  const surfaces = entry?.surfaces || {};

  const g = document.createElementNS("http://www.w3.org/2000/svg", "g");
  g.setAttribute("class", "svg-tooth");
  g.setAttribute("data-tooth", String(fdi));

  let crownY;
  let rootY;
  let labelY;

  if (isUpper) {
    rootY = cellY + 4;
    crownY = rootY + rootH + 2;
    labelY = crownY + crownH + 11;
  } else {
    labelY = cellY + 10;
    crownY = labelY + 4;
    rootY = crownY + crownH + 2;
  }

  const root = document.createElementNS("http://www.w3.org/2000/svg", "ellipse");
  root.setAttribute("class", "svg-tooth__root svg-surface");
  root.setAttribute("data-tooth", String(fdi));
  root.setAttribute("data-surface", "root");
  root.setAttribute("cx", String(cx));
  root.setAttribute("cy", String(rootY + rootH / 2));
  root.setAttribute("rx", String(rootW / 2));
  root.setAttribute("ry", String(rootH / 2));
  root.setAttribute("fill", colorForLabels(surfaces.root));
  root.setAttribute("stroke", "#334155");
  root.setAttribute("stroke-width", "1");

  const crownGroup = document.createElementNS("http://www.w3.org/2000/svg", "g");
  crownGroup.setAttribute("class", "svg-tooth__crown-group");

  const polys = crownSurfacePolygons(fdi, cx, crownY, crownW, crownH);
  for (const key of ["M", "D", "B", "L", "O"]) {
    addSurfacePolygon(crownGroup, fdi, key, polys[key], surfaces[key]);
  }

  const outline = document.createElementNS("http://www.w3.org/2000/svg", "rect");
  outline.setAttribute("class", "svg-tooth__outline");
  outline.setAttribute("x", String(cx - crownW / 2));
  outline.setAttribute("y", String(crownY));
  outline.setAttribute("width", String(crownW));
  outline.setAttribute("height", String(crownH));
  outline.setAttribute("fill", "none");
  outline.setAttribute("stroke", "#334155");
  outline.setAttribute("stroke-width", "1.2");
  outline.setAttribute("pointer-events", "none");
  crownGroup.appendChild(outline);

  const label = document.createElementNS("http://www.w3.org/2000/svg", "text");
  label.setAttribute("class", "svg-tooth__label");
  label.setAttribute("x", String(cx));
  label.setAttribute("y", String(labelY));
  label.setAttribute("text-anchor", "middle");
  label.textContent = String(fdi);

  g.appendChild(root);
  g.appendChild(crownGroup);
  g.appendChild(label);
  g.classList.toggle("is-missing", toothHasMissing(entry));

  return g;
}

function addQuadrantTeeth(svg, teeth, rowY, isUpper, quadrantIndex, toothState) {
  const baseX = quadrantStartX(quadrantIndex);
  teeth.forEach((fdi, idx) => {
    const cellX = baseX + idx * (GRID.cellW + GRID.gap);
    const g = createClinicalTooth(fdi, cellX, rowY, isUpper, toothState[fdi]);
    svg.appendChild(g);
    toothSvgElements.set(fdi, g);
  });
}

export function buildSvgOdontogram(container, { toothState = {}, onSurfaceClick, readonly = false } = {}) {
  container.innerHTML = "";
  container.classList.add("odontogram--svg");
  toothSvgElements.clear();

  const qWidth = 8 * GRID.cellW + 7 * GRID.gap;
  const totalW = GRID.startX * 2 + qWidth * 2 + GRID.midGap;
  const totalH = GRID.startY + GRID.cellH * 2 + GRID.gap + 36;

  const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
  svg.setAttribute("viewBox", `0 0 ${totalW} ${totalH}`);
  svg.setAttribute("role", "img");
  svg.setAttribute("aria-label", "Permanent dentition chart, FDI notation");

  const title = document.createElementNS("http://www.w3.org/2000/svg", "text");
  title.setAttribute("x", String(totalW / 2));
  title.setAttribute("y", "22");
  title.setAttribute("text-anchor", "middle");
  title.setAttribute("class", "svg-chart-title");
  title.textContent = "Permanent Dentition (FDI)";
  svg.appendChild(title);

  const upperRowY = GRID.startY;
  const lowerRowY = GRID.startY + GRID.cellH + GRID.gap + 8;
  const midX = GRID.startX + qWidth + GRID.midGap / 2;

  addQuadrantTeeth(svg, CHART_UPPER_RIGHT, upperRowY, true, 0, toothState);
  addQuadrantTeeth(svg, CHART_UPPER_LEFT, upperRowY, true, 1, toothState);
  addQuadrantTeeth(svg, CHART_LOWER_RIGHT, lowerRowY, false, 0, toothState);
  addQuadrantTeeth(svg, CHART_LOWER_LEFT, lowerRowY, false, 1, toothState);

  const midline = document.createElementNS("http://www.w3.org/2000/svg", "line");
  midline.setAttribute("x1", String(midX));
  midline.setAttribute("y1", String(upperRowY + GRID.cellH - 4));
  midline.setAttribute("x2", String(midX));
  midline.setAttribute("y2", String(lowerRowY + 4));
  midline.setAttribute("class", "svg-midline");
  svg.appendChild(midline);

  const archLineTop = document.createElementNS("http://www.w3.org/2000/svg", "line");
  archLineTop.setAttribute("x1", String(GRID.startX));
  archLineTop.setAttribute("y1", String(upperRowY + GRID.cellH + 4));
  archLineTop.setAttribute("x2", String(totalW - GRID.startX));
  archLineTop.setAttribute("y2", String(upperRowY + GRID.cellH + 4));
  archLineTop.setAttribute("class", "svg-arch-divider");
  svg.appendChild(archLineTop);

  const archLineBottom = document.createElementNS("http://www.w3.org/2000/svg", "line");
  archLineBottom.setAttribute("x1", String(GRID.startX));
  archLineBottom.setAttribute("y1", String(lowerRowY - 2));
  archLineBottom.setAttribute("x2", String(totalW - GRID.startX));
  archLineBottom.setAttribute("y2", String(lowerRowY - 2));
  archLineBottom.setAttribute("class", "svg-arch-divider");
  svg.appendChild(archLineBottom);

  svg.addEventListener("click", (ev) => {
    const target = ev.target.closest("[data-tooth][data-surface]");
    if (!target) return;
    onSurfaceClick?.(target.getAttribute("data-tooth"), target.getAttribute("data-surface"));
  });

  container.classList.toggle("odontogram--readonly", !!readonly);
  container.appendChild(svg);
}

export function setActiveSvgSelection(fdi, surface) {
  document.querySelectorAll(".svg-surface.is-active-surface").forEach((el) => {
    el.classList.remove("is-active-surface");
  });
  document.querySelectorAll(".svg-tooth.is-active").forEach((el) => {
    el.classList.remove("is-active");
  });
  if (!fdi) return;

  const tooth = toothSvgElements.get(String(fdi));
  tooth?.classList.add("is-active");
  if (surface) {
    tooth?.querySelector(`[data-surface="${surface}"]`)?.classList.add("is-active-surface");
  }
}

export function applySvgVisualStatus(fdi, entry) {
  const g = toothSvgElements.get(String(fdi));
  if (!g) return;

  g.querySelectorAll(".svg-surface").forEach((el) => {
    const surface = el.getAttribute("data-surface");
    const labels = entry?.surfaces?.[surface] || [];
    el.setAttribute("fill", colorForLabels(labels));

    const next = el.nextElementSibling;
    if (next?.classList?.contains("svg-surface-code")) next.remove();

    const code = labels.length ? labels[labels.length - 1] : "";
    if (code && el.tagName === "polygon") {
      const points = el.getAttribute("points").split(" ").map((pair) => pair.split(",").map(Number));
      const cx = points.reduce((s, p) => s + p[0], 0) / points.length;
      const cy = points.reduce((s, p) => s + p[1], 0) / points.length;
      const text = document.createElementNS("http://www.w3.org/2000/svg", "text");
      text.setAttribute("class", "svg-surface-code");
      text.setAttribute("x", String(cx));
      text.setAttribute("y", String(cy + 3));
      text.setAttribute("text-anchor", "middle");
      text.textContent = code;
      el.parentNode.insertBefore(text, el.nextSibling);
    } else if (code && el.tagName === "ellipse") {
      const text = document.createElementNS("http://www.w3.org/2000/svg", "text");
      text.setAttribute("class", "svg-surface-code");
      text.setAttribute("x", el.getAttribute("cx"));
      text.setAttribute("y", String(Number(el.getAttribute("cy")) + 3));
      text.setAttribute("text-anchor", "middle");
      text.textContent = code;
      el.parentNode.insertBefore(text, el.nextSibling);
    }
  });

  g.classList.toggle("is-missing", toothHasMissing(entry));
}
