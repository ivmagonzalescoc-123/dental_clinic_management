import {

  CHART_LOWER_LEFT,

  CHART_LOWER_RIGHT,

  CHART_UPPER_LEFT,

  CHART_UPPER_RIGHT,

  toothHasMissing,

} from "./chart-constants.js";

import {

  appendToothArtwork,

  injectChartSymbols,

  preloadChartArt,

  toothArtLayout,

} from "./tooth-2d-art.js";

import { appendArchStatusOverlays, updateArchStatusOverlays } from "./tooth-detail-2d.js";



export const toothSvgElements = new Map();



const GRID = {

  startX: 24,

  startY: 44,

  cellW: 46,

  cellH: 78,

  gap: 4,

  midGap: 22,

};



function quadrantStartX(quadrantIndex) {

  const qWidth = 8 * GRID.cellW + 7 * GRID.gap;

  if (quadrantIndex === 0) return GRID.startX;

  return GRID.startX + qWidth + GRID.midGap;

}



function createArchTooth(fdi, cellX, cellY, isUpper, entry) {

  const layout = toothArtLayout(fdi, cellX, cellY, GRID.cellW, GRID.cellH, isUpper);



  const g = document.createElementNS("http://www.w3.org/2000/svg", "g");

  g.setAttribute("class", "svg-tooth");

  g.setAttribute("data-tooth", String(fdi));

  g.setAttribute("data-cell-x", String(cellX));
  g.setAttribute("data-cell-y", String(cellY));
  g.setAttribute("data-is-upper", isUpper ? "1" : "0");



  appendToothArtwork(g, layout);

  appendArchStatusOverlays(g, fdi, layout, entry);



  const pick = document.createElementNS("http://www.w3.org/2000/svg", "rect");

  pick.setAttribute("class", "svg-tooth__pick");

  pick.setAttribute("data-tooth", String(fdi));

  pick.setAttribute("x", String(layout.artX - 3));

  pick.setAttribute("y", String(layout.artY - 3));

  pick.setAttribute("width", String(layout.artW + 6));

  pick.setAttribute("height", String(layout.artH + 6));

  pick.setAttribute("fill", "transparent");

  pick.setAttribute("stroke", "none");

  pick.setAttribute("pointer-events", "all");



  const label = document.createElementNS("http://www.w3.org/2000/svg", "text");

  label.setAttribute("class", "svg-tooth__label");

  label.setAttribute("x", String(layout.cx));

  label.setAttribute("y", String(layout.labelY));

  label.setAttribute("text-anchor", "middle");

  label.setAttribute("pointer-events", "none");

  label.textContent = String(fdi);



  g.appendChild(pick);

  g.appendChild(label);

  g.classList.toggle("is-missing", toothHasMissing(entry));



  return g;

}



function addQuadrantTeeth(svg, teeth, rowY, isUpper, quadrantIndex, toothState) {

  const baseX = quadrantStartX(quadrantIndex);

  teeth.forEach((fdi, idx) => {

    const cellX = baseX + idx * (GRID.cellW + GRID.gap);

    const g = createArchTooth(fdi, cellX, rowY, isUpper, toothState[fdi]);

    svg.appendChild(g);

    toothSvgElements.set(fdi, g);

  });

}



export async function buildSvgOdontogram(container, { toothState = {}, onToothClick, readonly = false } = {}) {

  await preloadChartArt();



  container.innerHTML = "";

  container.classList.add("odontogram--svg", "odontogram--arch");

  toothSvgElements.clear();



  const qWidth = 8 * GRID.cellW + 7 * GRID.gap;

  const totalW = GRID.startX * 2 + qWidth * 2 + GRID.midGap;

  const totalH = GRID.startY + GRID.cellH * 2 + GRID.gap + 40;



  const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");

  svg.setAttribute("viewBox", `0 0 ${totalW} ${totalH}`);

  svg.setAttribute("role", "img");

  svg.setAttribute("aria-label", "Permanent dentition chart, FDI notation");



  injectChartSymbols(svg);



  const title = document.createElementNS("http://www.w3.org/2000/svg", "text");

  title.setAttribute("x", String(totalW / 2));

  title.setAttribute("y", "20");

  title.setAttribute("text-anchor", "middle");

  title.setAttribute("class", "svg-chart-title");

  title.textContent = "Permanent Dentition (FDI)";

  svg.appendChild(title);



  const upperRowY = GRID.startY;

  const lowerRowY = GRID.startY + GRID.cellH + GRID.gap + 10;

  const midX = GRID.startX + qWidth + GRID.midGap / 2;



  addQuadrantTeeth(svg, CHART_UPPER_RIGHT, upperRowY, true, 0, toothState);

  addQuadrantTeeth(svg, CHART_UPPER_LEFT, upperRowY, true, 1, toothState);

  addQuadrantTeeth(svg, CHART_LOWER_RIGHT, lowerRowY, false, 0, toothState);

  addQuadrantTeeth(svg, CHART_LOWER_LEFT, lowerRowY, false, 1, toothState);



  const midline = document.createElementNS("http://www.w3.org/2000/svg", "line");

  midline.setAttribute("x1", String(midX));

  midline.setAttribute("y1", String(upperRowY + GRID.cellH - 2));

  midline.setAttribute("x2", String(midX));

  midline.setAttribute("y2", String(lowerRowY + 2));

  midline.setAttribute("class", "svg-midline");

  svg.appendChild(midline);



  const archLineTop = document.createElementNS("http://www.w3.org/2000/svg", "line");

  archLineTop.setAttribute("x1", String(GRID.startX));

  archLineTop.setAttribute("y1", String(upperRowY + GRID.cellH + 6));

  archLineTop.setAttribute("x2", String(totalW - GRID.startX));

  archLineTop.setAttribute("y2", String(upperRowY + GRID.cellH + 6));

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
    const pick = ev.target.closest(".svg-tooth__pick");
    if (!pick) return;
    onToothClick?.(pick.getAttribute("data-tooth"));
  });



  container.classList.toggle("odontogram--readonly", !!readonly);

  container.appendChild(svg);

}



export function setActiveArchTooth(fdi) {

  document.querySelectorAll(".svg-tooth.is-active").forEach((el) => {

    el.classList.remove("is-active");

  });

  if (!fdi) return;

  toothSvgElements.get(String(fdi))?.classList.add("is-active");

}



export function applySvgVisualStatus(fdi, entry) {
  const g = toothSvgElements.get(String(fdi));
  if (!g) return;
  updateArchStatusOverlays(g, entry);
}


