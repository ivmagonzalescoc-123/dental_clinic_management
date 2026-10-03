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
import { toothAnatomicalName, toothDisplayLabel } from "./tooth-names.js";

import {
  applySvgVisualStatus,
  buildSvgOdontogram,
  setActiveArchTooth,
} from "./svg-chart.js";

import {
  applyDetailVisualStatus,
  initToothDetailPanel,
  setActiveDetailSurface,
  showToothDetail,
} from "./tooth-detail-2d.js";

import {
  layoutRealisticArch,
  loadToothTemplates,
} from "./teeth-3d.js";

import {
  getSurfaceCameraPose,
  paintMeshSurfaceColors,
} from "./tooth-surface-3d.js";

const ODONTOGRAM_BASE = new URL("./", import.meta.url);
// teeth-lite/ holds decimated copies of teeth/ (same files, ~2.7MB instead of ~45MB;
// see scripts/optimize_odontogram_models.mjs). A file missing there falls back to teeth/.
const TEETH_MODELS_BASE = [
  new URL("models/teeth-lite/", ODONTOGRAM_BASE),
  new URL("models/teeth/", ODONTOGRAM_BASE),
];

const toothState = {};
for (const fdi of ALL_FDI_TEETH) toothState[fdi] = createEmptyToothEntry();

let selectedTooth = null;
let selectedSurface = null;
let readonlyMode = false;
let usingGlbTeeth = false;

const odontogramEl = document.getElementById("odontogram");
const chartToothDetailEl = document.getElementById("chartToothDetail");
const selectedToothEl = document.getElementById("selectedTooth");
const selectedSurfaceEl = document.getElementById("selectedSurface");
const statusSummaryEl = document.getElementById("statusSummary");
const clearSurfaceBtn = document.getElementById("clearSurface");
const clearToothBtn = document.getElementById("clearTooth");
const resetViewBtn = document.getElementById("resetView");
const toothCommentEl = document.getElementById("toothComment");
const modelStatusEl = document.getElementById("modelStatus");
const isolationStatusEl = document.getElementById("isolationStatus");
const loadOverlayEl = document.getElementById("loadOverlay");
const loadLabelEl = document.getElementById("loadLabel");
const loadBarEl = document.getElementById("loadBar");
const loadPctEl = document.getElementById("loadPct");
const loadTrackEl = loadOverlayEl?.querySelector(".load-overlay__track");
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
        ? "View only — select a tooth, then inspect surfaces in the bar above the chart."
        : "View only — double-click a tooth for close-up and pick a surface to inspect it; double-click again, press Esc or tap \"All teeth\" to return.";
    } else {
      viewHintEl.textContent = isChart
        ? "Click a tooth on the arch, pick a surface in the bar above, then apply a legend code."
        : "Double-click a tooth for close-up (Esc or \"All teeth\" to return). Pick a surface to tilt the view; apply codes on the left.";
    }
  }
  if (!isChart) {
    requestRender();
    resizeToDisplaySize();
    if (!isolatedToothFdi) fitCameraToArch();
    updateModelStatusForSelection();
  } else if (!modelStatusEl?.classList.contains("is-error")) {
    setModelStatus("");
  }
}

viewTabChart?.addEventListener("click", () => setView("chart"));
viewTab3d?.addEventListener("click", () => setView("3d"));

const printChartBtn = document.getElementById("printChartBtn");
printChartBtn?.addEventListener("click", () => {
  setView("chart");
  window.print();
});

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

function initChartDetailPanel() {
  if (!chartToothDetailEl || chartToothDetailEl.dataset.ready === "1") return;
  chartToothDetailEl.dataset.ready = "1";
  initToothDetailPanel(chartToothDetailEl, {
    legendByCode: LEGEND_BY_CODE,
    onSurfaceClick: (surface) => {
      if (!selectedTooth) return;
      selectSurface(selectedTooth, surface);
    },
  });
}

async function buildOdontogramUI() {
  initChartDetailPanel();
  await buildSvgOdontogram(odontogramEl, {
    toothState,
    readonly: readonlyMode,
    onToothClick: (fdi) => selectTooth(fdi),
  });
  for (const fdi of ALL_FDI_TEETH) applySvgVisualStatus(fdi, toothState[fdi]);
  refreshChartDetailPanel();
}

function refreshChartDetailPanel() {
  if (!selectedTooth) {
    showToothDetail(null);
    return;
  }
  showToothDetail(selectedTooth, toothState[selectedTooth], selectedSurface);
  setActiveDetailSurface(selectedSurface);
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
    setActiveArchTooth(null);
    showToothDetail(null);
    updateModelStatusForSelection();
    return;
  }

  selectedToothEl.textContent = toothDisplayLabel(selectedTooth);
  selectedToothEl.title = toothAnatomicalName(selectedTooth);
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
  setActiveArchTooth(selectedTooth);
  refreshChartDetailPanel();
  if (isolatedToothFdi) updateIsolationStatusPanel();
  updateModelStatusForSelection();
}

async function normalizeIncomingToothState(ts) {
  const migrated = migrateToothState(ts);
  for (const fdi of ALL_FDI_TEETH) {
    toothState[fdi] = migrated[fdi] || createEmptyToothEntry();
  }
  // Models may already be on the arch (they load faster than the parent's message
  // can arrive), so repaint them from the new state as well as the 2D chart.
  repaintAllTeeth3d();
  await buildOdontogramUI();
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
  fdi = String(fdi);
  const toothRoot = toothMeshes.get(fdi);
  if (!toothRoot) return;

  const entry = toothState[fdi] || createEmptyToothEntry();
  const missing = toothHasMissing(entry);
  const highlightSurface =
    isolatedToothFdi === String(fdi) && selectedTooth === String(fdi) ? selectedSurface : null;

  forEachToothMesh(fdi, (m) => {
    ensureStandardMaterial(m);
    paintMeshSurfaceColors(m, toothRoot, fdi, entry, { highlightSurface, missing });
    // Close-up hides whole teeth via syncToothVisibility(); hiding meshes here too left
    // teeth invisible after leaving close-up (e.g. when another record was loaded).
    m.visible = true;
  });
  requestRender();
}

function applyVisualStatus(fdi) {
  fdi = String(fdi);
  applySvgVisualStatus(fdi, toothState[fdi]);
  if (selectedTooth === fdi) {
    applyDetailVisualStatus(fdi, toothState[fdi], selectedSurface);
  }
  paintTooth3d(fdi);
}

function repaintAllTeeth3d() {
  for (const fdi of ALL_FDI_TEETH) paintTooth3d(fdi);
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

function selectTooth(fdi) {
  selectedTooth = String(fdi);
  if (!selectedSurface) selectedSurface = "O";
  updateStatusUI();
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

// Draw only when something changed (camera, colors, visibility, size) instead of
// every frame, so an idle 3D tab doesn't keep the GPU busy.
let needsRender = true;
function requestRender() {
  needsRender = true;
}
controls.addEventListener("change", requestRender);

const archControlLimits = {
  minDistance: 4.5,
  maxDistance: 24,
  minPolarAngle: 0.05,
  maxPolarAngle: Math.PI - 0.05,
  minAzimuthAngle: -Infinity,
  maxAzimuthAngle: Infinity,
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

function fitCameraToArch({ animate = false } = {}) {
  if (isolatedToothFdi || !teethGroup.children.length) return;

  teethGroup.updateMatrixWorld(true);
  const box = new THREE.Box3().setFromObject(teethGroup);
  if (box.isEmpty()) return;

  const center = box.getCenter(new THREE.Vector3());
  const size = box.getSize(new THREE.Vector3());
  const maxDim = Math.max(size.x, size.y, size.z, 1);
  const aspect = Math.max(camera.aspect || 1, 0.5);
  const fov = THREE.MathUtils.degToRad(camera.fov);
  const distV = (maxDim * 0.52) / Math.tan(fov / 2);
  const distH = (maxDim * 0.52) / (Math.tan(fov / 2) * aspect);
  const dist = Math.max(distV, distH) * 1.2;

  const dir = new THREE.Vector3(0.1, 0.32, 1).normalize();
  const pos = center.clone().add(dir.multiplyScalar(dist));

  if (animate) {
    camAnim.start(camera.position.clone(), pos, controls.target.clone(), center, 650);
  } else {
    camera.position.copy(pos);
    controls.target.copy(center);
  }

  defaultCamPos = pos.clone();
  defaultTarget = center.clone();
}

function refreshDefaultPreview() {
  fitCameraToArch();
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
  requestRender();
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

function setLoadProgress(percent, label) {
  const pct = Math.max(0, Math.min(100, Math.round(percent)));
  if (loadLabelEl && label) loadLabelEl.textContent = label;
  if (loadBarEl) loadBarEl.style.width = `${pct}%`;
  if (loadPctEl) loadPctEl.textContent = `${pct}%`;
  if (loadTrackEl) loadTrackEl.setAttribute("aria-valuenow", String(pct));
}

function showLoadOverlay(show) {
  if (!loadOverlayEl) return;
  loadOverlayEl.classList.toggle("is-hidden", !show);
  loadOverlayEl.setAttribute("aria-busy", show ? "true" : "false");
}

function finishLoadProgress() {
  setLoadProgress(100, "3D preview ready");
  setTimeout(() => showLoadOverlay(false), 350);
}

async function loadPreviewModels() {
  const loader = new GLTFLoader();
  showLoadOverlay(true);
  setLoadProgress(0, "Preparing 3D scene…");
  setModelStatus("");
  usingGlbTeeth = false;
  toothTemplates = new Map();

  try {
    const result = await loadToothTemplates(loader, TEETH_MODELS_BASE, {
      onProgress: (pct, label) => setLoadProgress(pct, label),
    });
    toothTemplates = result.templates;

    setLoadProgress(88, "Placing teeth on the arch…");
    const loaded = layoutTeeth();

    setLoadProgress(94, "Applying chart colors…");
    repaintAllTeeth3d();

    setLoadProgress(98, "Framing camera…");
    resizeToDisplaySize();
    fitCameraToArch();

    if (loaded >= 28) {
      finishLoadProgress();
      console.info("[odontogram 3d] loaded realistic teeth from", result.modelsBase);
    } else if (loaded > 0) {
      finishLoadProgress();
      setModelStatus(`${loaded}/32 realistic teeth loaded — some models missing.`);
    } else {
      setLoadProgress(100, "Using schematic fallback…");
      const sample = result.failures[0];
      const hint = sample ? `${sample.file}: ${sample.message}` : "check odontogram/models/teeth/";
      setTimeout(() => showLoadOverlay(false), 400);
      setModelStatus(`Schematic fallback — models did not load (${hint}). Hard-refresh the page.`, true);
      console.error("[odontogram 3d] all model loads failed", result.failures);
    }
  } catch (err) {
    console.warn("[odontogram 3d] load failed", err);
    setLoadProgress(92, "Building schematic teeth…");
    layoutTeeth();
    repaintAllTeeth3d();
    resizeToDisplaySize();
    fitCameraToArch();
    setLoadProgress(100, "Schematic preview ready");
    setTimeout(() => showLoadOverlay(false), 400);
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
  requestRender();
}

function updateModelStatusForSelection() {
  if (activeView !== "3d" || modelStatusEl?.classList.contains("is-error")) return;
  if (isolatedToothFdi) {
    setModelStatus(
      `${toothAnatomicalName(isolatedToothFdi)} (${isolatedToothFdi}) · double-click or Esc to go back`,
    );
    return;
  }
  if (selectedTooth) {
    setModelStatus(`${toothAnatomicalName(selectedTooth)} (${selectedTooth})`);
    return;
  }
  setModelStatus("");
}

function updateIsolationExitButton() {
  exitIsolationBtn?.classList.toggle("is-hidden", !isolatedToothFdi);
}

function updateIsolationBanner() {
  updateModelStatusForSelection();
  updateIsolationExitButton();
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
  // Picking a surface only changes the view, so it works in read mode too.
  const selectable = true;

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
    <h3 class="isolation-status__title">
      <span class="isolation-status__tooth-name">${toothAnatomicalName(fdi)}</span>
      <span class="isolation-status__tooth-fdi">FDI ${fdi}</span>
    </h3>
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
    if (!isolatedToothFdi) return;
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
  updateModelStatusForSelection();
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
  requestRender();
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
  requestRender();
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
  fitCameraToArch({ animate: true });
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

const exitIsolationBtn = document.getElementById("exitIsolationBtn");
exitIsolationBtn?.addEventListener("click", () => exitToothIsolation());
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape" && isolatedToothFdi) exitToothIsolation();
});

/**
 * Back to the state a freshly opened chart has: 2D tab, nothing selected, no
 * close-up, camera framing the whole arch. Run whenever a record is loaded, since
 * the records pages reuse this iframe for every patient/visit.
 */
function resetViewerToDefault() {
  if (pendingClickTimer) {
    clearTimeout(pendingClickTimer);
    pendingClickTimer = null;
  }
  lastPointerDown = { t: 0, fdi: null };
  lastBackgroundDown = 0;
  camAnim.active = false;
  selectedTooth = null;
  selectedSurface = null;
  clearHighlight();
  isolatedToothFdi = null;
  savedArchCamera = null;
  syncToothVisibility();
  applyControlLimits(archControlLimits);
  updateIsolationStatusPanel();
  updateIsolationExitButton();
  fitCameraToArch();
  setView("chart");
}

const raycaster = new THREE.Raycaster();
const mouse = new THREE.Vector2();
const DBL_CLICK_MS = 320;
let pendingClickTimer = null;
let lastPointerDown = { t: 0, fdi: null };

function isObjectShown(obj) {
  for (let node = obj; node; node = node.parent) {
    if (!node.visible) return false;
  }
  return true;
}

function pickToothFromPointer(e) {
  const rect = canvas.getBoundingClientRect();
  mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
  mouse.y = -(((e.clientY - rect.top) / rect.height) * 2 - 1);
  raycaster.setFromCamera(mouse, camera);
  const hits = raycaster.intersectObjects([...toothMeshes.values()], true);
  const seen = new Set();
  for (const hit of hits) {
    // Raycasting ignores `visible`, so without this a hidden neighbour in front of the
    // close-up tooth would swallow the double-click meant to zoom back out.
    if (!isObjectShown(hit.object)) continue;
    const fdi = String(resolveToothFdi(hit.object) || "");
    if (!fdi || seen.has(fdi)) continue;
    seen.add(fdi);
    return fdi;
  }
  return null;
}

let lastBackgroundDown = 0;

canvas.addEventListener("pointerdown", (e) => {
  if (e.button !== 0) return;
  const fdi = pickToothFromPointer(e);
  const now = performance.now();
  if (!fdi) {
    // Double-click on empty space in close-up returns to the full arch.
    if (isolatedToothFdi && now - lastBackgroundDown <= DBL_CLICK_MS) {
      lastBackgroundDown = 0;
      exitToothIsolation();
      return;
    }
    lastBackgroundDown = now;
    return;
  }
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

let lastCanvasSize = "";
function resizeToDisplaySize() {
  const w = canvas.clientWidth;
  const h = canvas.clientHeight;
  if (!w || !h) return false;
  // setSize() clears the drawing buffer, so skip it unless the size really changed.
  const size = `${w}x${h}x${renderer.getPixelRatio()}`;
  if (size === lastCanvasSize) return false;
  lastCanvasSize = size;
  renderer.setSize(w, h, false);
  camera.aspect = w / h;
  camera.updateProjectionMatrix();
  requestRender();
  return true;
}

function animate() {
  requestAnimationFrame(animate);
  if (!render3d) return;
  resizeToDisplaySize();
  // update() returns true while the camera is still moving (including damping).
  if (controls.update()) needsRender = true;
  if (!camAnim.active) {
    const distance = camera.position.distanceTo(controls.target);
    if (distance < controls.minDistance || distance > controls.maxDistance) {
      const dir = camera.position.clone().sub(controls.target).normalize();
      const clampedDistance = THREE.MathUtils.clamp(distance, controls.minDistance, controls.maxDistance);
      camera.position.copy(controls.target.clone().add(dir.multiplyScalar(clampedDistance)));
      needsRender = true;
    }
  }
  if (camAnim.active) {
    const t = (performance.now() - camAnim.t0) / camAnim.dur;
    const tt = easeInOutCubic(Math.min(Math.max(t, 0), 1));
    camera.position.lerpVectors(camAnim.fromPos, camAnim.toPos, tt);
    controls.target.lerpVectors(camAnim.fromTarget, camAnim.toTarget, tt);
    if (t >= 1) camAnim.active = false;
    needsRender = true;
  }
  if (!needsRender) return;
  needsRender = false;
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
      ? "View only — select a tooth and inspect surfaces in the bar above the chart."
      : "Select a tooth on the chart, pick a surface above it, then apply a legend code.";
  }

  odontogramEl?.classList.toggle("odontogram--readonly", readonlyMode);
  updateStatusUI();
  setView(activeView);
}

buildLegendUI();
bindIsolationPanelEvents();
loadPreviewModels();
updateStatusUI();
setView("chart");
animate();

buildOdontogramUI().catch((err) => {
  console.error("2D chart failed to load", err);
  if (odontogramEl) {
    odontogramEl.innerHTML = "<p class=\"chart-load-error\">Could not load dental chart artwork. Check network and refresh.</p>";
  }
});

window.addEventListener("resize", () => {
  requestRender();
  if (!render3d || isolatedToothFdi) return;
  resizeToDisplaySize();
  fitCameraToArch();
});

window.addEventListener("message", (ev) => {
  try {
    if (ev.data?.type === "odontogram_resize") {
      requestRender();
      resizeToDisplaySize();
      if (!isolatedToothFdi) fitCameraToArch();
      return;
    }
    if (!ev.data || ev.data.type !== "load_odontogram") return;
    // New record (another patient or visit): start from the default view, not
    // whatever close-up/angle/tab the previous record was left in.
    resetViewerToDefault();
    setReadonlyMode(!!ev.data.readonly);
    normalizeIncomingToothState(ev.data.toothState || {});
    clearSelection();
    updateStatusUI();
    sendStateToParent();
  } catch (e) {
    console.warn("odontogram load failed", e);
  }
});
