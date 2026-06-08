import { colorForLabels, isMesialRight } from "./chart-constants.js";

export function isUpperFdi(fdi) {
  const q = Math.floor(parseInt(String(fdi), 10) / 10);
  return q === 1 || q === 2;
}

export function surfaceFillColor(labels) {
  if (!labels?.length) return "transparent";
  return colorForLabels(labels);
}

export function surfaceStroke(labels) {
  return labels?.length ? "#334155" : "transparent";
}

export function crownSurfacePolygons(fdi, cx, crownY, crownW, crownH) {
  const x1 = cx - crownW / 2;
  const x2 = cx + crownW / 2;
  const y1 = crownY;
  const y2 = crownY + crownH;
  const mx = cx;
  const my = crownY + crownH / 2;
  const inset = crownW * 0.2;
  const insY = crownH * 0.22;

  const oTop = my - insY * 0.45;
  const oBot = my + insY * 0.45;
  const oL = mx - inset * 0.55;
  const oR = mx + inset * 0.55;
  const mesialRight = isMesialRight(fdi);

  // Five regions tile the crown: B top, L bottom, O center, M/D side wedges (not full-height strips).
  const mesial = mesialRight
    ? [
        [oR, oTop],
        [x2, y1],
        [x2, y2],
        [oR, oBot],
      ]
    : [
        [x1, y1],
        [oL, oTop],
        [oL, oBot],
        [x1, y2],
      ];

  const distal = mesialRight
    ? [
        [x1, y1],
        [oL, oTop],
        [oL, oBot],
        [x1, y2],
      ]
    : [
        [oR, oTop],
        [x2, y1],
        [x2, y2],
        [oR, oBot],
      ];

  return {
    O: [
      [oL, oTop],
      [oR, oTop],
      [oR, oBot],
      [oL, oBot],
    ],
    B: [
      [x1, y1],
      [x2, y1],
      [oR, oTop],
      [oL, oTop],
    ],
    L: [
      [oL, oBot],
      [oR, oBot],
      [x2, y2],
      [x1, y2],
    ],
    M: mesial,
    D: distal,
  };
}

export function detailArtLayout(fdi, boxW, boxH) {
  const isUpper = isUpperFdi(fdi);
  const artH = boxH * 0.88;
  const artW = artH * 0.52;
  const cx = boxW / 2;
  const artX = cx - artW / 2;
  const artY = (boxH - artH) / 2;
  const rootH = artH * 0.32;
  const crownH = artH - rootH;
  let crownY;
  let rootY;

  if (isUpper) {
    rootY = artY;
    crownY = artY + rootH + 2;
  } else {
    crownY = artY;
    rootY = artY + crownH + 2;
  }

  return {
    file: null,
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
    isUpper,
  };
}
