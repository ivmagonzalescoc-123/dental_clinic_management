/** Azucen clinic / standard Philippine dental chart — FDI notation, surface-level labels. */

export const CHART_UPPER_RIGHT = ["18", "17", "16", "15", "14", "13", "12", "11"];
export const CHART_UPPER_LEFT = ["21", "22", "23", "24", "25", "26", "27", "28"];
export const CHART_LOWER_RIGHT = ["48", "47", "46", "45", "44", "43", "42", "41"];
export const CHART_LOWER_LEFT = ["31", "32", "33", "34", "35", "36", "37", "38"];

export const ALL_FDI_TEETH = [
  ...CHART_UPPER_RIGHT,
  ...CHART_UPPER_LEFT,
  ...CHART_LOWER_LEFT,
  ...CHART_LOWER_RIGHT,
];

export const SURFACE_KEYS = ["O", "M", "D", "B", "L", "root", "whole"];

export const SURFACE_LABELS = {
  O: "Occlusal / Incisal",
  M: "Mesial",
  D: "Distal",
  B: "Buccal",
  L: "Lingual / Palatal",
  root: "Root",
  whole: "Whole tooth",
};

/** Clinic chart legend (Patient's Dental Chart). */
export const CHART_LEGEND = [
  { code: "C", label: "Dental Caries", category: "decay", color: "#fecaca" },
  { code: "C1", label: "Caries — Vital Pulp Expose", category: "decay", color: "#fca5a5" },
  { code: "C2", label: "Caries — Non-vital Pulp Expose", category: "decay", color: "#f87171" },
  { code: "X", label: "Indicated for Extraction", category: "other", color: "#fdba74" },
  { code: "RF", label: "Retained Root Fragment", category: "missing", color: "#d6d3d1" },
  { code: "Am", label: "Amalgam Filling", category: "restoration", color: "#c4b5fd" },
  { code: "S", label: "Silicate Filling", category: "restoration", color: "#a5b4fc" },
  { code: "GC", label: "Gold Crown", category: "restoration", color: "#fde68a" },
  { code: "Ab1", label: "Bridge Abutment", category: "restoration", color: "#fcd34d" },
  { code: "P", label: "Pontic", category: "restoration", color: "#fbbf24" },
  { code: "GCI", label: "Gold Clasp", category: "restoration", color: "#f59e0b" },
  { code: "GI", label: "Gold Inlays", category: "restoration", color: "#eab308" },
  { code: "M", label: "Missing (Extraction)", category: "missing", color: "#94a3b8" },
  { code: "Un", label: "Unerupted", category: "other", color: "#cbd5e1" },
];

export const LEGEND_BY_CODE = Object.fromEntries(CHART_LEGEND.map((e) => [e.code, e]));

/** Legacy Universal (1–32) → FDI */
export const UNIVERSAL_TO_FDI = {
  1: "18", 2: "17", 3: "16", 4: "15", 5: "14", 6: "13", 7: "12", 8: "11",
  9: "21", 10: "22", 11: "23", 12: "24", 13: "25", 14: "26", 15: "27", 16: "28",
  17: "31", 18: "32", 19: "33", 20: "34", 21: "35", 22: "36", 23: "37", 24: "38",
  25: "41", 26: "42", 27: "43", 28: "44", 29: "45", 30: "46", 31: "47", 32: "48",
};

export function fdiToArchIndex(fdi) {
  return ALL_FDI_TEETH.indexOf(String(fdi));
}

export function isMesialRight(fdi) {
  const q = Math.floor(parseInt(String(fdi), 10) / 10);
  return q === 1 || q === 4;
}

export { toothAnatomicalName, toothDisplayLabel } from "./tooth-names.js";

export function createEmptyToothEntry() {
  return { comment: "", surfaces: {} };
}

export function ensureSurface(entry, surface) {
  if (!entry.surfaces[surface]) entry.surfaces[surface] = [];
  return entry.surfaces[surface];
}

const LEGACY_STATUS_MAP = {
  missing: "M",
  rootMissing: "RF",
  caries: "C",
  filled: "Am",
  crown: "GC",
  implant: "GI",
};

export function migrateLegacyEntry(entry) {
  const out = createEmptyToothEntry();
  if (!entry || typeof entry !== "object") return out;

  out.comment = String(entry.comment || "");

  if (entry.surfaces && typeof entry.surfaces === "object") {
    for (const [surface, labels] of Object.entries(entry.surfaces)) {
      if (!SURFACE_KEYS.includes(surface)) continue;
      const list = Array.isArray(labels) ? labels : [labels];
      out.surfaces[surface] = [...new Set(list.map(String).filter(Boolean))];
    }
    return out;
  }

  const statuses = Array.isArray(entry.statuses)
    ? entry.statuses
    : entry.statuses instanceof Set
      ? [...entry.statuses]
      : [];

  for (const st of statuses) {
    const code = LEGACY_STATUS_MAP[st];
    if (!code) continue;
    const surface = st === "rootMissing" ? "root" : st === "missing" ? "whole" : "O";
    ensureSurface(out, surface).push(code);
  }

  return out;
}

export function migrateToothState(raw) {
  if (!raw || typeof raw !== "object") return {};

  if (raw.teeth && typeof raw.teeth === "object") {
    return migrateToothState(raw.teeth);
  }

  const out = {};
  for (const [key, entry] of Object.entries(raw)) {
    let fdi = String(key);
    if (!ALL_FDI_TEETH.includes(fdi)) {
      if (/^\d+$/.test(fdi) && parseInt(fdi, 10) <= 32 && UNIVERSAL_TO_FDI[parseInt(fdi, 10)]) {
        fdi = UNIVERSAL_TO_FDI[parseInt(fdi, 10)];
      }
    }
    if (!ALL_FDI_TEETH.includes(fdi)) continue;
    out[fdi] = migrateLegacyEntry(entry);
  }
  return out;
}

export function serializeToothState(toothState) {
  const out = {};
  for (const fdi of ALL_FDI_TEETH) {
    const entry = toothState[fdi] || createEmptyToothEntry();
    const surfaces = {};
    for (const [surface, labels] of Object.entries(entry.surfaces || {})) {
      const clean = [...new Set((labels || []).map(String).filter(Boolean))];
      if (clean.length) surfaces[surface] = clean;
    }
    const comment = String(entry.comment || "").trim();
    if (comment || Object.keys(surfaces).length) {
      out[fdi] = { comment, surfaces };
    }
  }
  return out;
}

export function primaryLabelForSurface(labels) {
  if (!labels?.length) return null;
  return labels[labels.length - 1];
}

export function colorForLabels(labels) {
  if (!labels?.length) return "#ffffff";
  const code = primaryLabelForSurface(labels);
  return LEGEND_BY_CODE[code]?.color || "#e2e8f0";
}

export function toothHasMissing(entry) {
  return Object.values(entry?.surfaces || {}).some((labels) =>
    (labels || []).some((c) => c === "M" || c === "X")
  );
}
