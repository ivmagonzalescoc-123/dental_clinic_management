import * as THREE from "three";
import { OrbitControls } from "three/addons/controls/OrbitControls.js";
import { GLTFLoader } from "three/addons/loaders/GLTFLoader.js";
import {
  ALL_FDI_TEETH,
  CHART_LEGEND,
  LEGEND_BY_CODE,
  SURFACE_KEYS,
  SURFACE_LABELS,
  colorForLabels,
  createEmptyToothEntry,
  ensureSurface,
  fdiToArchIndex,
  migrateToothState,
  primaryLabelForSurface,
  serializeToothState,
  toothHasMissing,
} from "./chart-constants.js";

import {
  applySvgVisualStatus,
  buildSvgOdontogram,
  setActiveSvgSelection,
} from "./svg-chart.js";

import {
  layoutRealisticArch,
  loadToothTemplates,
} from "./teeth-3d.js";

import {
  getSurfaceCameraPose,
  paintMeshSurfaceColors,
} from "./tooth-surface-3d.js";

const ODONTOGRAM_BASE = new URL("./", import.meta.url);
const TEETH_MODELS_BASE = new URL("models/teeth/", ODONTOGRAM_BASE);

const toothState = {};
for (const fdi of ALL_FDI_TEETH) toothState[fdi] = createEmptyToothEntry();

let selectedTooth = null;
let selectedSurface = null;
let readonlyMode = false;
let usingGlbTeeth = false;

const odontogramEl = document.getElementById("odontogram");
const selectedToothEl = document.getElementById("selectedTooth");
const selectedSurfaceEl = document.getElementById("selectedSurface");
const statusSummaryEl = document.getElementById("statusSummary");
const clearSurfaceBtn = document.getElementById("clearSurface");
const clearToothBtn = document.getElementById("clearTooth");
const resetViewBtn = document.getElementById("resetView");
const toothCommentEl = document.getElementById("toothComment");
const modelStatusEl = document.getElementById("modelStatus");
const isolationStatusEl = document.getElementById("isolationStatus");
const legendGridEl = document.getElementById("legendGrid");
const chartPane = document.getElementById("chartPane");
const viewport3d = document.getElementById("viewport3d");
const viewTabChart = document.getElementById("viewTabChart");
const viewTab3d = document.getElementById("viewTab3d");
const viewHintEl = document.getElementById("viewHint");
const panelSubtitleEl = document.querySelector(".panel__header p");

let activeView = "chart";
let render3d = false;
const legendButtons = new Map();

function setView(view) {
  activeView = view;
  const isChart = view === "chart";
  render3d = !isChart;
  chartPane?.classList.toggle("is-hidden", !isChart);
  viewport3d?.classList.toggle("is-hidden", isChart);
  viewTabChart?.classList.toggle("is-active", isChart);
  viewTab3d?.classList.toggle("is-active", !isChart);
  viewTabChart?.setAttribute("aria-selected", isChart ? "true" : "false");
  viewTab3d?.setAttribute("aria-selected", !isChart ? "true" : "false");
  if (resetViewBtn) resetViewBtn.style.display = isChart ? "none" : "block";
  if (viewHintEl) {
    if (readonlyMode) {
      viewHintEl.textContent = isChart
        ? "View only — click a surface to inspect charting codes."
        : "View only — double-click a tooth for close-up, double-click again to return.";
    } else {
      viewHintEl.textContent = isChart
        ? "Click a crown zone or root on the chart (FDI numbering)."
        : "Double-click a tooth for close-up. Pick a surface to tilt the view; apply codes on the left.";
    }
  }
  if (!isChart) resizeToDisplaySize();
}

viewTabChart?.addEventListener("click", () => setView("chart"));
viewTab3d?.addEventListener("click", () => setView("3d"));

function buildLegendUI() {
  if (!legendGridEl) return;
  legendGridEl.innerHTML = "";
  legendButtons.clear();
  for (const item of CHART_LEGEND) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "legend-btn";
    btn.dataset.code = item.code;
    btn.title = item.label;
    btn.innerHTML = `<span class="legend-btn__code">${item.code}</span><span class="legend-btn__label">${item.label}</span>`;
    btn.style.setProperty("--legend-color", item.color);
    btn.addEventListener("click", () => applyLegendCode(item.code));
    legendGridEl.appendChild(btn);
    legendButtons.set(item.code, btn);
  }
}

function buildOdontogramUI() {
  buildSvgOdontogram(odontogramEl, {
    toothState,
    readonly: readonlyMode,
    onSurfaceClick: (fdi, surface) => selectSurface(fdi, surface),
  });
  for (const fdi of ALL_FDI_TEETH) applySvgVisualStatus(fdi, toothState[fdi]);
}

function updateLegendButtons() {
  const labels = selectedTooth && selectedSurface
    ? toothState[selectedTooth]?.surfaces?.[selectedSurface] || []
    : [];
  const active = primaryLabelForSurface(labels);
  for (const [code, btn] of legendButtons.entries()) {
    btn.classList.toggle("is-on", code === active);
    btn.disabled = readonlyMode || !selectedTooth || !selectedSurface;
  }
}

function updateStatusUI() {
  if (!selectedTooth || !selectedSurface) {
    selectedToothEl.textContent = "—";
    selectedSurfaceEl.textContent = "—";
    statusSummaryEl.textContent = "—";
    if (toothCommentEl) {
      toothCommentEl.value = "";
      toothCommentEl.disabled = true;
    }
    updateLegendButtons();
    setActiveSvgSelection(null, null);
    return;
  }

  selectedToothEl.textContent = String(selectedTooth);
  selectedSurfaceEl.textContent = SURFACE_LABELS[selectedSurface] || selectedSurface;

  const labels = toothState[selectedTooth]?.surfaces?.[selectedSurface] || [];
  statusSummaryEl.textContent = labels.length ? labels.join(", ") : "—";

  if (toothCommentEl) {
    toothCommentEl.value = toothState[selectedTooth]?.comment || "";
    if (readonlyMode) {
      toothCommentEl.readOnly = true;
      toothCommentEl.disabled = false;
    } else {
      toothCommentEl.readOnly = false;
      toothCommentEl.disabled = false;
    }
  }

  updateLegendButtons();
  setActiveSvgSelection(selectedTooth, selectedSurface);
  if (isolatedToothFdi) updateIsolationStatusPanel();
}

function normalizeIncomingToothState(ts) {
  const migrated = migrateToothState(ts);
  for (const fdi of ALL_FDI_TEETH) {
    toothState[fdi] = migrated[fdi] || createEmptyToothEntry();
  }
  buildOdontogramUI();
}

window.getOdontogramData = function () {
  return serializeToothState(toothState);
};

function sendStateToParent() {
  try {
    if (window.parent && window.parent !== window) {
      window.parent.postMessage({ type: "odontogram_update", toothState: serializeToothState(toothState) }, "*");
    }
  } catch (e) {
    console.warn("postMessage odontogram failed", e);
  }
}

function ensureStandardMaterial(mesh) {
  const make = () =>
    new THREE.MeshStandardMaterial({
      color: 0xf3f5ff,
      roughness: 0.35,
      metalness: 0,
      transparent: true,
      opacity: 1,
    });

  if (Array.isArray(mesh.material)) {
    mesh.material = mesh.material.map((mat) => {
      if (mat?.isMeshStandardMaterial || mat?.isMeshPhysicalMaterial) return mat;
      return make();
    });
    return;
  }

  if (!mesh.material?.isMeshStandardMaterial && !mesh.material?.isMeshPhysicalMaterial) {
    mesh.material = make();
  }
}

function paintTooth3d(fdi) {
  const toothRoot = toothMeshes.get(fdi);
  if (!toothRoot) return;

  const entry = toothState[fdi] || createEmptyToothEntry();
  const missing = toothHasMissing(entry);
  const highlightSurface =
    isolatedToothFdi === String(fdi) && selectedTooth === String(fdi) ? selectedSurface : null;

  forEachToothMesh(fdi, (m) => {
    ensureStandardMaterial(m);
    paintMeshSurfaceColors(m, toothRoot, fdi, entry, { highlightSurface, missing });
    m.visible = !isolatedToothFdi || String(fdi) === isolatedToothFdi;
  });
}

function applyVisualStatus(fdi) {
  applySvgVisualStatus(fdi, toothState[fdi]);
  paintTooth3d(fdi);
}

function applyLegendCode(code) {
  if (readonlyMode || !selectedTooth || !selectedSurface) return;
  const labels = ensureSurface(toothState[selectedTooth], selectedSurface);
  const idx = labels.indexOf(code);
  if (idx >= 0) labels.splice(idx, 1);
  else labels.splice(0, labels.length, code);

  applyVisualStatus(selectedTooth);
  updateStatusUI();
  updateIsolationStatusPanel();
  sendStateToParent();
}

function selectSurface(fdi, surface) {
  selectedTooth = String(fdi);
  selectedSurface = surface;
  updateStatusUI();

  const mesh = toothMeshes.get(selectedTooth);
  if (!mesh) return;

  if (isolatedToothFdi === selectedTooth) {
    paintTooth3d(selectedTooth);
    focusCameraOnSurface(mesh, selectedTooth, selectedSurface);
    return;
  }

  setHighlight(selectedTooth);
  focusCameraOnTooth(mesh);
}

function clearSelection() {
  selectedTooth = null;
  selectedSurface = null;
  updateStatusUI();
  clearHighlight();
  resetCameraView();
}

clearSurfaceBtn?.addEventListener("click", () => {
  if (readonlyMode || !selectedTooth || !selectedSurface) return;
  delete toothState[selectedTooth].surfaces[selectedSurface];
  applyVisualStatus(selectedTooth);
  updateStatusUI();
  updateIsolationStatusPanel();
  sendStateToParent();
});

clearToothBtn?.addEventListener("click", () => {
  if (readonlyMode || !selectedTooth) return;
  toothState[selectedTooth] = createEmptyToothEntry();
  applyVisualStatus(selectedTooth);
  updateStatusUI();
  updateIsolationStatusPanel();
  sendStateToParent();
});

if (toothCommentEl) {
  toothCommentEl.addEventListener("input", () => {
    if (readonlyMode || !selectedTooth) return;
    toothState[selectedTooth].comment = toothCommentEl.value || "";
    updateIsolationStatusPanel();
    sendStateToParent();
  });
}

const canvas = document.getElementById("c");
const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: false });
renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
renderer.toneMapping = THREE.ACESFilmicToneMapping;
renderer.toneMappingExposure = 1.08;

const scene = new THREE.Scene();
scene.background = new THREE.Color(0x101820);
const camera = new THREE.PerspectiveCamera(45, 1, 0.1, 200);
camera.position.set(0, 3.8, 8.5);
const controls = new OrbitControls(camera, renderer.domElement);
controls.enableDamping = true;
controls.target.set(0, 2.5, -2.4);
controls.enablePan = false;

const archControlLimits = {
  minDistance: 4.5,
  maxDistance: 24,
  minPolarAngle: 0.35,
  maxPolarAngle: Math.PI / 2.02,
  minAzimuthAngle: -Math.PI * 0.7,
  maxAzimuthAngle: Math.PI * 0.7,
  zoomSpeed: 1.65,
};

const toothControlLimits = {
  minDistance: 0.35,
  maxDistance: 5.5,
  minPolarAngle: 0.05,
  maxPolarAngle: Math.PI - 0.05,
  minAzimuthAngle: -Infinity,
  maxAzimuthAngle: Infinity,
  zoomSpeed: 2.2,
};

function applyControlLimits(limits) {
  controls.minDistance = limits.minDistance;
  controls.maxDistance = limits.maxDistance;
  controls.minPolarAngle = limits.minPolarAngle;
  controls.maxPolarAngle = limits.maxPolarAngle;
  controls.minAzimuthAngle = limits.minAzimuthAngle;
  controls.maxAzimuthAngle = limits.maxAzimuthAngle;
  controls.zoomSpeed = limits.zoomSpeed ?? 1;
}

applyControlLimits(archControlLimits);
let defaultCamPos = camera.position.clone();
let defaultTarget = controls.target.clone();

function refreshDefaultPreview() {
  camera.position.set(0, 3.8, 8.5);
  controls.target.set(0, 2.5, -2.4);
  defaultCamPos = camera.position.clone();
  defaultTarget = controls.target.clone();
}

scene.add(new THREE.AmbientLight(0xffffff, 0.52));
scene.add(new THREE.HemisphereLight(0xf0f4ff, 0x1a2438, 0.42));
const key = new THREE.DirectionalLight(0xffffff, 1.05);
key.position.set(8, 16, 12);
scene.add(key);
const fill = new THREE.DirectionalLight(0xdce8ff, 0.45);
fill.position.set(-10, 8, 8);
scene.add(fill);
const rim = new THREE.DirectionalLight(0x9bbcff, 0.28);
rim.position.set(-12, 12, -14);
scene.add(rim);
const floor = new THREE.Mesh(
  new THREE.PlaneGeometry(200, 200),
  new THREE.MeshStandardMaterial({ color: 0x0a1020, roughness: 1, metalness: 0 })
);
floor.rotation.x = -Math.PI / 2;
scene.add(floor);

function getToothProfile(fdi) {
  const digit = parseInt(String(fdi).slice(-1), 10);
  if (digit <= 2) {
    return { type: "incisor", crownW: 0.42, crownH: 0.52, crownD: 0.28, rootH: 0.95, rootW: 0.2 };
  }
  if (digit === 3) {
    return { type: "canine", crownW: 0.48, crownH: 0.72, crownD: 0.34, rootH: 1.05, rootW: 0.24 };
  }
  if (digit <= 5) {
    return { type: "premolar", crownW: 0.62, crownH: 0.46, crownD: 0.5, rootH: 0.82, rootW: 0.22 };
  }
  return { type: "molar", crownW: 0.82, crownH: 0.44, crownD: 0.68, rootH: 0.78, rootW: 0.26 };
}

function createEnamelMaterial() {
  return new THREE.MeshStandardMaterial({
    color: 0xf6f1e8,
    roughness: 0.38,
    metalness: 0.03,
    transparent: true,
    opacity: 1,
  });
}

function createRootMaterial() {
  return new THREE.MeshStandardMaterial({
    color: 0xddd0bc,
    roughness: 0.58,
    metalness: 0,
    transparent: true,
    opacity: 1,
  });
}

function makeAnatomicalToothMesh(fdi, layout) {
  const profile = getToothProfile(fdi);
  const group = new THREE.Group();
  group.userData.toothFdi = fdi;

  const crownMat = createEnamelMaterial();
  const rootMat = createRootMaterial();

  let crownGeo;
  if (profile.type === "incisor") {
    crownGeo = new THREE.BoxGeometry(profile.crownW, profile.crownH, profile.crownD);
  } else if (profile.type === "canine") {
    crownGeo = new THREE.ConeGeometry(profile.crownW * 0.55, profile.crownH, 6);
  } else if (profile.type === "premolar") {
    crownGeo = new THREE.CylinderGeometry(profile.crownW * 0.52, profile.crownW * 0.48, profile.crownH, 6);
  } else {
    crownGeo = new THREE.BoxGeometry(profile.crownW, profile.crownH * 0.72, profile.crownD);
  }

  const crown = new THREE.Mesh(crownGeo, crownMat);
  crown.name = "crown";

  const root = new THREE.Mesh(
    new THREE.ConeGeometry(profile.rootW, profile.rootH, 8),
    rootMat
  );
  root.name = "root";
  root.rotation.x = Math.PI;

  const gap = 0.04;
  if (layout.isUpper) {
    crown.position.y = profile.crownH * 0.5;
    root.position.y = -(profile.rootH * 0.5 + gap);
  } else {
    root.position.y = profile.rootH * 0.5;
    root.rotation.x = 0;
    crown.position.y = profile.crownH * 0.5 + profile.rootH + gap;
  }

  group.add(crown);
  group.add(root);
  return group;
}

const teethGroup = new THREE.Group();
scene.add(teethGroup);
const toothMeshes = new Map();
let toothTemplates = new Map();

function getToothLayoutForFallback(fdi) {
  const archIndex = fdiToArchIndex(fdi);
  return { isUpper: archIndex <= 15 };
}

function createFallbackTooth(fdi) {
  return makeAnatomicalToothMesh(fdi, getToothLayoutForFallback(fdi));
}

function tagToothObject(obj, fdi) {
  const id = String(fdi);
  obj.userData.toothFdi = id;
  obj.traverse((child) => {
    child.userData.toothFdi = id;
  });
}

function resolveToothFdi(obj) {
  let node = obj;
  while (node) {
    if (node.userData?.toothFdi) return node.userData.toothFdi;
    node = node.parent;
  }
  return "";
}

function layoutTeeth() {
  const loaded = layoutRealisticArch({
    teethGroup,
    toothMeshes,
    templates: toothTemplates,
    allFdiTeeth: ALL_FDI_TEETH,
    tagToothObject,
    applyVisualStatus,
    createFallbackTooth,
  });
  usingGlbTeeth = loaded > 0;
  refreshDefaultPreview();
  return loaded;
}

function forEachToothMesh(fdi, fn) {
  const root = toothMeshes.get(fdi);
  if (!root) return;
  if (root.isMesh) {
    fn(root);
    return;
  }
  root.traverse((child) => {
    if (child.isMesh) fn(child);
  });
}

function forEachToothMaterial(mesh, fn) {
  if (!mesh?.material) return;
  if (Array.isArray(mesh.material)) {
    mesh.material.forEach((mat) => mat && fn(mat));
    return;
  }
  fn(mesh.material);
}

function forEachToothMeshMaterial(fdi, fn) {
  forEachToothMesh(fdi, (mesh) => forEachToothMaterial(mesh, fn));
}

function setModelStatus(text, isError = false) {
  if (!modelStatusEl) return;
  modelStatusEl.textContent = text;
  modelStatusEl.classList.toggle("is-error", isError);
  modelStatusEl.classList.toggle("is-hidden", !text);
}

async function loadPreviewModels() {
  const loader = new GLTFLoader();
  setModelStatus("Loading realistic 3D teeth…");
  usingGlbTeeth = false;
  toothTemplates = new Map();

  try {
    const result = await loadToothTemplates(loader, TEETH_MODELS_BASE);
    toothTemplates = result.templates;
    const loaded = layoutTeeth();

    if (loaded >= 28) {
      setModelStatus("");
      console.info("[odontogram 3d] loaded realistic teeth from", result.modelsBase);
    } else if (loaded > 0) {
      setModelStatus(`${loaded}/32 realistic teeth loaded — some models missing.`);
    } else {
      const sample = result.failures[0];
      const hint = sample ? `${sample.file}: ${sample.message}` : "check odontogram/models/teeth/";
      setModelStatus(`Schematic fallback — models did not load (${hint}). Hard-refresh the page.`, true);
      console.error("[odontogram 3d] all model loads failed", result.failures);
    }
  } catch (err) {
    console.warn("[odontogram 3d] load failed", err);
    layoutTeeth();
    setModelStatus("Schematic fallback — tooth models could not be loaded. Hard-refresh the page.", true);
  }
}

let highlightMesh = null;
let isolatedToothFdi = null;
let savedArchCamera = null;

function syncToothVisibility() {
  for (const [fdi, mesh] of toothMeshes.entries()) {
    mesh.visible = !isolatedToothFdi || String(fdi) === isolatedToothFdi;
  }
}

function updateIsolationBanner() {
  if (isolatedToothFdi) {
    setModelStatus(`Tooth ${isolatedToothFdi} — double-click again to show all teeth`);
    return;
  }
  if (!modelStatusEl?.classList.contains("is-error")) {
    setModelStatus("");
  }
}

function formatSurfaceCodes(labels) {
  if (!labels?.length) return null;
  return labels.map((code) => {
    const item = LEGEND_BY_CODE[code];
    return item ? { code, label: item.label, color: item.color } : { code, label: code, color: "#e2e8f0" };
  });
}

function buildIsolationSurfaceValue(codes) {
  if (!codes?.length) {
    return `<span class="isolation-status__value is-empty">No charting</span>`;
  }
  const value = codes.map((item) =>
    `<span class="isolation-status__code">
      <span class="isolation-status__swatch" style="background:${item.color}"></span>
      <strong>${item.code}</strong> ${item.label}
    </span>`
  ).join("");
  return `<span class="isolation-status__value">${value}</span>`;
}

function updateIsolationStatusPanel() {
  if (!isolationStatusEl) return;
  if (!isolatedToothFdi) {
    isolationStatusEl.classList.add("is-hidden");
    isolationStatusEl.classList.remove("isolation-status--selectable");
    isolationStatusEl.innerHTML = "";
    return;
  }

  const fdi = isolatedToothFdi;
  const entry = toothState[fdi] || createEmptyToothEntry();
  const surfaces = entry.surfaces || {};
  const selectable = !readonlyMode;

  const rows = SURFACE_KEYS.map((key) => {
    const codes = formatSurfaceCodes(surfaces[key]);
    const surfaceName = SURFACE_LABELS[key] || key;
    const isActive = selectedTooth === fdi && selectedSurface === key;
    const valueHtml = buildIsolationSurfaceValue(codes);
    const activeClass = isActive ? " is-active" : "";
    if (!selectable) {
      return `<div class="isolation-status__row${activeClass}">
        <span class="isolation-status__surface">${surfaceName}</span>
        ${valueHtml}
      </div>`;
    }
    return `<button type="button" class="isolation-status__row${activeClass}" data-surface-key="${key}">
      <span class="isolation-status__surface">${surfaceName}</span>
      ${valueHtml}
    </button>`;
  }).join("");

  isolationStatusEl.innerHTML = `
    <h3 class="isolation-status__title">Tooth ${fdi}</h3>
    <p class="isolation-status__hint">${selectable ? "Select a surface to tilt the view and highlight that part only." : "Charted surfaces for this tooth"}</p>
    <div class="isolation-status__list">${rows}</div>
  `;
  isolationStatusEl.classList.toggle("isolation-status--selectable", selectable);
  isolationStatusEl.classList.remove("is-hidden");
}

function bindIsolationPanelEvents() {
  if (!isolationStatusEl || isolationStatusEl.dataset.bound === "1") return;
  isolationStatusEl.dataset.bound = "1";

  isolationStatusEl.addEventListener("click", (e) => {
    if (readonlyMode || !isolatedToothFdi) return;
    const row = e.target.closest("[data-surface-key]");
    if (!row) return;
    selectSurface(isolatedToothFdi, row.dataset.surfaceKey);
  });
}

function focusCameraOnIsolatedTooth(mesh) {
  const box = new THREE.Box3().setFromObject(mesh);
  const focusTarget = new THREE.Vector3();
  box.getCenter(focusTarget);
  const size = box.getSize(new THREE.Vector3());
  const radius = Math.max(size.x, size.y, size.z, 0.5) * 0.55;
  const desiredCamPos = focusTarget.clone().add(new THREE.Vector3(radius * 1.1, radius * 0.85, radius * 2.4));
  camAnim.start(camera.position.clone(), desiredCamPos, controls.target.clone(), focusTarget, 700);
}

function enterToothIsolation(fdi) {
  fdi = String(fdi);
  savedArchCamera = {
    pos: camera.position.clone(),
    target: controls.target.clone(),
  };
  isolatedToothFdi = fdi;
  syncToothVisibility();
  applyControlLimits(toothControlLimits);
  const mesh = toothMeshes.get(fdi);
  if (mesh) focusCameraOnIsolatedTooth(mesh);
  clearHighlight();
  selectSurface(fdi, selectedTooth === fdi && selectedSurface ? selectedSurface : "O");
  updateIsolationBanner();
  updateIsolationStatusPanel();
}

function exitToothIsolation() {
  if (!isolatedToothFdi) return;
  const prevSelected = selectedTooth;
  const prevIsolated = isolatedToothFdi;
  isolatedToothFdi = null;
  syncToothVisibility();
  paintTooth3d(prevIsolated);
  applyControlLimits(archControlLimits);
  if (savedArchCamera) {
    camAnim.start(
      camera.position.clone(),
      savedArchCamera.pos.clone(),
      controls.target.clone(),
      savedArchCamera.target.clone(),
      700
    );
  }
  savedArchCamera = null;
  updateIsolationBanner();
  updateIsolationStatusPanel();
  if (prevSelected) setHighlight(prevSelected);
}

function toggleToothIsolation(fdi) {
  fdi = String(fdi);
  if (isolatedToothFdi === fdi) {
    exitToothIsolation();
    return;
  }
  if (isolatedToothFdi) {
    const prevFdi = isolatedToothFdi;
    isolatedToothFdi = fdi;
    syncToothVisibility();
    paintTooth3d(prevFdi);
    clearHighlight();
    selectSurface(fdi, selectedTooth === fdi && selectedSurface ? selectedSurface : "O");
    updateIsolationBanner();
    updateIsolationStatusPanel();
    return;
  }
  enterToothIsolation(fdi);
}

function clearHighlight() {
  if (!highlightMesh) return;
  const prev = String(highlightMesh.userData.toothFdi || "");
  if (prev) {
    forEachToothMeshMaterial(prev, (material) => {
      if (material.emissive) {
        material.emissive.set(0x000000);
        material.emissiveIntensity = 0;
      }
    });
  }
  highlightMesh = null;
}

function setHighlight(fdi) {
  fdi = String(fdi);
  if (isolatedToothFdi) {
    clearHighlight();
    return;
  }
  if (highlightMesh) {
    const prev = String(highlightMesh.userData.toothFdi || "");
    if (prev && prev !== fdi) {
      forEachToothMeshMaterial(prev, (material) => {
        if (material.emissive) {
          material.emissive.set(0x000000);
          material.emissiveIntensity = 0;
        }
      });
    }
    highlightMesh = null;
  }
  const mesh = toothMeshes.get(fdi);
  if (!mesh) return;
  forEachToothMeshMaterial(fdi, (material) => {
    if (!material.emissive) material.emissive = new THREE.Color(0x000000);
    material.emissive.set(0x7aa2ff);
    material.emissiveIntensity = 0.45;
  });
  mesh.userData.toothFdi = fdi;
  highlightMesh = mesh;
}

const camAnim = {
  active: false,
  t0: 0,
  dur: 700,
  fromPos: new THREE.Vector3(),
  toPos: new THREE.Vector3(),
  fromTarget: new THREE.Vector3(),
  toTarget: new THREE.Vector3(),
  start(fromPos, toPos, fromTarget, toTarget, durMs) {
    this.active = true;
    this.t0 = performance.now();
    this.dur = durMs;
    this.fromPos.copy(fromPos);
    this.toPos.copy(toPos);
    this.fromTarget.copy(fromTarget);
    this.toTarget.copy(toTarget);
  },
};

function easeInOutCubic(t) {
  return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
}

function resetCameraView() {
  if (isolatedToothFdi) {
    isolatedToothFdi = null;
    savedArchCamera = null;
    syncToothVisibility();
    applyControlLimits(archControlLimits);
    updateIsolationBanner();
    updateIsolationStatusPanel();
  }
  camAnim.start(camera.position.clone(), defaultCamPos.clone(), controls.target.clone(), defaultTarget.clone(), 650);
}

function focusCameraOnTooth(mesh) {
  const focusTarget = new THREE.Vector3();
  mesh.getWorldPosition(focusTarget);
  focusTarget.y += 0.35;
  const dir = new THREE.Vector3().subVectors(camera.position, controls.target).normalize();
  const desiredCamPos = focusTarget.clone().add(dir.multiplyScalar(10));
  desiredCamPos.y += 1.2;
  camAnim.start(camera.position.clone(), desiredCamPos, controls.target.clone(), focusTarget.clone(), 650);
}

function focusCameraOnSurface(mesh, fdi, surface) {
  const pose = getSurfaceCameraPose(mesh, fdi, surface);
  camAnim.start(camera.position.clone(), pose.pos, controls.target.clone(), pose.target, 650);
}

resetViewBtn?.addEventListener("click", resetCameraView);

const raycaster = new THREE.Raycaster();
const mouse = new THREE.Vector2();
const DBL_CLICK_MS = 320;
let pendingClickTimer = null;
let lastPointerDown = { t: 0, fdi: null };

function pickToothFromPointer(e) {
  const rect = canvas.getBoundingClientRect();
  mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
  mouse.y = -(((e.clientY - rect.top) / rect.height) * 2 - 1);
  raycaster.setFromCamera(mouse, camera);
  const hits = raycaster.intersectObjects([...toothMeshes.values()], true);
  const seen = new Set();
  for (const hit of hits) {
    const fdi = String(resolveToothFdi(hit.object) || "");
    if (!fdi || seen.has(fdi)) continue;
    seen.add(fdi);
    return fdi;
  }
  return null;
}

canvas.addEventListener("pointerdown", (e) => {
  if (e.button !== 0) return;
  const fdi = pickToothFromPointer(e);
  if (!fdi) return;
  const now = performance.now();
  if (lastPointerDown.fdi === fdi && now - lastPointerDown.t <= DBL_CLICK_MS) {
    if (pendingClickTimer) {
      clearTimeout(pendingClickTimer);
      pendingClickTimer = null;
    }
    lastPointerDown.t = 0;
    lastPointerDown.fdi = null;
    toggleToothIsolation(fdi);
    selectedTooth = fdi;
    selectedSurface = "O";
    updateStatusUI();
    return;
  }
  lastPointerDown.t = now;
  lastPointerDown.fdi = fdi;
  if (pendingClickTimer) clearTimeout(pendingClickTimer);
  pendingClickTimer = setTimeout(() => {
    pendingClickTimer = null;
    selectSurface(fdi, "O");
  }, DBL_CLICK_MS);
});

function resizeToDisplaySize() {
  const w = canvas.clientWidth;
  const h = canvas.clientHeight;
  if (!w || !h) return;
  renderer.setSize(w, h, false);
  camera.aspect = w / h;
  camera.updateProjectionMatrix();
}

function animate() {
  requestAnimationFrame(animate);
  if (!render3d) return;
  resizeToDisplaySize();
  controls.update();
  if (!isolatedToothFdi) {
    controls.target.x = THREE.MathUtils.clamp(controls.target.x, -8, 8);
    controls.target.y = THREE.MathUtils.clamp(controls.target.y, 0.2, 8.5);
    controls.target.z = THREE.MathUtils.clamp(controls.target.z, -16, 8);
  }
  if (!camAnim.active) {
    const distance = camera.position.distanceTo(controls.target);
    if (distance < controls.minDistance || distance > controls.maxDistance) {
      const dir = camera.position.clone().sub(controls.target).normalize();
      const clampedDistance = THREE.MathUtils.clamp(distance, controls.minDistance, controls.maxDistance);
      camera.position.copy(controls.target.clone().add(dir.multiplyScalar(clampedDistance)));
    }
  }
  if (camAnim.active) {
    const t = (performance.now() - camAnim.t0) / camAnim.dur;
    const tt = easeInOutCubic(Math.min(Math.max(t, 0), 1));
    camera.position.lerpVectors(camAnim.fromPos, camAnim.toPos, tt);
    controls.target.lerpVectors(camAnim.fromTarget, camAnim.toTarget, tt);
    if (t >= 1) camAnim.active = false;
  }
  renderer.render(scene, camera);
}

function setReadonlyMode(on) {
  readonlyMode = !!on;
  document.body?.classList.toggle("is-readonly", readonlyMode);

  if (clearSurfaceBtn) {
    clearSurfaceBtn.disabled = readonlyMode;
    clearSurfaceBtn.hidden = readonlyMode;
  }
  if (clearToothBtn) {
    clearToothBtn.disabled = readonlyMode;
    clearToothBtn.hidden = readonlyMode;
  }
  if (panelSubtitleEl) {
    panelSubtitleEl.textContent = readonlyMode
      ? "View only — select a tooth surface to inspect charting."
      : "Click a tooth surface on the chart, then apply a legend code.";
  }

  odontogramEl?.classList.toggle("odontogram--readonly", readonlyMode);
  updateStatusUI();
  setView(activeView);
}

buildLegendUI();
buildOdontogramUI();
bindIsolationPanelEvents();
loadPreviewModels();
updateStatusUI();
setView("chart");
animate();

window.addEventListener("message", (ev) => {
  try {
    if (!ev.data || ev.data.type !== "load_odontogram") return;
    setReadonlyMode(!!ev.data.readonly);
    normalizeIncomingToothState(ev.data.toothState || {});
    clearSelection();
    updateStatusUI();
    sendStateToParent();
  } catch (e) {
    console.warn("odontogram load failed", e);
  }
});
