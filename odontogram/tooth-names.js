/** FDI tooth anatomical names (quadrant + type). */

const QUADRANT_NAMES = {
  1: "Upper Right",
  2: "Upper Left",
  3: "Lower Left",
  4: "Lower Right",
};

const TOOTH_TYPE_BY_POS = {
  1: "Central Incisor",
  2: "Lateral Incisor",
  3: "Canine",
  4: "First Premolar",
  5: "Second Premolar",
  6: "First Molar",
  7: "Second Molar",
  8: "Third Molar",
};

/** Full anatomical name, e.g. "Upper Right Central Incisor". */
export function toothAnatomicalName(fdi) {
  const n = parseInt(String(fdi), 10);
  if (!Number.isFinite(n)) return String(fdi);
  const q = Math.floor(n / 10);
  const pos = n % 10;
  const quadrant = QUADRANT_NAMES[q] || "";
  const type = TOOTH_TYPE_BY_POS[pos] || "Tooth";
  return quadrant ? `${quadrant} ${type}` : type;
}

/** Compact label for UI: "11 — Upper Right Central Incisor". */
export function toothDisplayLabel(fdi) {
  fdi = String(fdi);
  return `${fdi} — ${toothAnatomicalName(fdi)}`;
}
