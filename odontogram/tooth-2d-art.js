/** Tooth artwork from React-Odontogram-Modul (MIT) — https://github.com/ZoliQua/React-Odontogram-Modul */

const SVG_NS = "http://www.w3.org/2000/svg";
const CHART_ART_BASE = new URL("./assets/chart-teeth/", import.meta.url);

const FDI_TEMPLATE = Object.fromEntries(
  [
    ["11", "11.svg"], ["12", "11.svg"], ["21", "11.svg"], ["22", "11.svg"],
    ["31", "11.svg"], ["32", "11.svg"], ["41", "11.svg"], ["42", "11.svg"],
    ["13", "13.svg"], ["23", "13.svg"], ["33", "13.svg"], ["43", "13.svg"],
    ["14", "14.svg"], ["15", "14.svg"], ["24", "14.svg"], ["25", "14.svg"],
    ["34", "14.svg"], ["35", "14.svg"], ["44", "14.svg"], ["45", "14.svg"],
    ["16", "16.svg"], ["17", "16.svg"], ["18", "16.svg"], ["26", "16.svg"],
    ["27", "16.svg"], ["28", "16.svg"], ["36", "16.svg"], ["37", "16.svg"],
    ["38", "16.svg"], ["46", "16.svg"], ["47", "16.svg"], ["48", "16.svg"],
  ],
);

const SYMBOL_ID = {
  "11.svg": "chart-tooth-incisor",
  "13.svg": "chart-tooth-canine",
  "14.svg": "chart-tooth-premolar",
  "16.svg": "chart-tooth-molar",
};

const VIEWBOX = {
  "11.svg": { w: 36, h: 64 },
  "13.svg": { w: 36, h: 64 },
  "14.svg": { w: 32.3, h: 64 },
  "16.svg": { w: 40.6, h: 64 },
};

const svgTextCache = new Map();
let symbolsReady = false;

export function templateForFdi(fdi) {
  return FDI_TEMPLATE[String(fdi)] || "11.svg";
}

export function toothWidthScale(fdi) {
  const pos = parseInt(String(fdi).slice(-1), 10);
  if (pos <= 2) return 1;
  if (pos === 3) return 0.9;
  if (pos <= 5) return 0.82;
  return 0.76;
}

export function toothArtLayout(fdi, cellX, cellY, cellW, cellH, isUpper) {
  const file = templateForFdi(fdi);
  const vb = VIEWBOX[file] || VIEWBOX["11.svg"];
  const scale = toothWidthScale(fdi);
  const artH = cellH * 0.9;
  const artW = artH * (vb.w / vb.h) * scale;
  const cx = cellX + cellW / 2;
  const artX = cx - artW / 2;
  const artY = isUpper ? cellY + 2 : cellY + 12;
  const rootH = artH * 0.32;
  const crownH = artH - rootH;
  let crownY;
  let rootY;
  let labelY;

  if (isUpper) {
    rootY = artY;
    crownY = artY + rootH + 1;
    labelY = artY + artH + 12;
  } else {
    crownY = artY;
    rootY = artY + crownH + 1;
    labelY = artY - 4;
  }

  return {
    file,
    artX,
    artY,
    artW,
    artH,
    cx,
    crownY,
    crownH,
    crownW: artW * 0.9,
    rootY,
    rootH,
    rootW: artW * 0.42,
    labelY,
    isUpper,
  };
}

async function loadSvgText(file) {
  if (svgTextCache.has(file)) return svgTextCache.get(file);
  const res = await fetch(new URL(file, CHART_ART_BASE).href);
  if (!res.ok) throw new Error(`Failed to load chart art: ${file}`);
  const text = await res.text();
  svgTextCache.set(file, text);
  return text;
}

function pruneChartLayers(node) {
  const hideRoots = new Set(["mods", "surfaces", "restorations", "contact-point"]);
  if (node.id && hideRoots.has(node.id)) {
    node.setAttribute("display", "none");
    return;
  }
  if (node.id === "tooth-variants") {
    for (const child of node.children) {
      const keep = ["tooth-base", "tooth-base-beauty", "tooth-healthy-pulp"].includes(child.id);
      if (!keep) child.setAttribute("display", "none");
    }
    return;
  }
  for (const child of node.children) pruneChartLayers(child);
}

function svgTextToSymbol(svgText, symbolId, file) {
  const doc = new DOMParser().parseFromString(svgText, "image/svg+xml");
  const svg = doc.documentElement;
  const symbol = document.createElementNS(SVG_NS, "symbol");
  symbol.setAttribute("id", symbolId);
  symbol.setAttribute("viewBox", svg.getAttribute("viewBox") || "0 0 36 64");

  for (const child of svg.childNodes) {
    if (child.nodeType !== 1) continue;
    const tag = child.tagName.toLowerCase();
    if (tag === "style") continue;
    const imported = document.importNode(child, true);
    if (tag !== "defs") pruneChartLayers(imported);
    symbol.appendChild(imported);
  }
  return symbol;
}

export async function preloadChartArt() {
  if (symbolsReady) return;
  const files = [...new Set(Object.values(FDI_TEMPLATE))];
  await Promise.all(files.map((file) => loadSvgText(file)));
  symbolsReady = true;
}

export function injectChartSymbols(svgRoot, prefix = "") {
  let defs = svgRoot.querySelector("defs.chart-tooth-defs");
  if (!defs) {
    defs = document.createElementNS(SVG_NS, "defs");
    defs.setAttribute("class", "chart-tooth-defs");
    svgRoot.insertBefore(defs, svgRoot.firstChild);
  }
  defs.innerHTML = "";

  for (const file of new Set(Object.values(FDI_TEMPLATE))) {
    const text = svgTextCache.get(file);
    if (!text) continue;
    defs.appendChild(svgTextToSymbol(text, `${prefix}${SYMBOL_ID[file]}`, file));
  }
}

export function appendToothArtwork(g, layout, prefix = "") {
  const symbolId = `${prefix}${SYMBOL_ID[layout.file]}`;
  if (!SYMBOL_ID[layout.file]) return;

  const artWrap = document.createElementNS(SVG_NS, "g");
  artWrap.setAttribute("class", "svg-tooth__art");
  artWrap.setAttribute("pointer-events", "none");

  const use = document.createElementNS(SVG_NS, "use");
  use.setAttribute("href", `#${symbolId}`);
  use.setAttributeNS("http://www.w3.org/1999/xlink", "href", `#${symbolId}`);
  use.setAttribute("x", String(layout.artX));
  use.setAttribute("y", String(layout.artY));
  use.setAttribute("width", String(layout.artW));
  use.setAttribute("height", String(layout.artH));

  if (!layout.isUpper) {
    const midY = layout.artY + layout.artH / 2;
    use.setAttribute(
      "transform",
      `translate(${layout.artX + layout.artW / 2} ${midY}) scale(1 -1) translate(${-(layout.artX + layout.artW / 2)} ${-midY})`,
    );
  }

  artWrap.appendChild(use);
  g.appendChild(artWrap);
}
