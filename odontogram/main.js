import * as THREE from "three";
import { OrbitControls } from "three/addons/controls/OrbitControls.js";
import { GLTFLoader } from "three/addons/loaders/GLTFLoader.js";
import { EffectComposer } from "three/addons/postprocessing/EffectComposer.js";
import { RenderPass } from "three/addons/postprocessing/RenderPass.js";
import { ShaderPass } from "three/addons/postprocessing/ShaderPass.js";
import { HorizontalTiltShiftShader } from "three/addons/shaders/HorizontalTiltShiftShader.js";
import { VerticalTiltShiftShader } from "three/addons/shaders/VerticalTiltShiftShader.js";

import {
  ALL_FDI_TEETH,
  CHART_LEGEND,
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
        : "View only — click a tooth in 3D to inspect its charting.";
    } else {
      viewHintEl.textContent = isChart
        ? "Click a crown zone or root on the chart (FDI numbering)."
        : "Drag to orbit the 3D model. Scroll to zoom.";
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

function applyVisualStatus(fdi) {
  applySvgVisualStatus(fdi, toothState[fdi]);
  const mesh = toothMeshes.get(fdi);
  if (!mesh) return;

  const entry = toothState[fdi];
  const allLabels = Object.values(entry.surfaces || {}).flat();
  const color = colorForLabels(allLabels);
  const missing = toothHasMissing(entry);

  forEachToothMesh(fdi, (m) => {
    if (!m.material) return;
    if (!m.material.isMeshStandardMaterial && !m.material.isMeshPhysicalMaterial) {
      m.material = new THREE.MeshStandardMaterial({
        color: 0xf3f5ff,
        roughness: 0.35,
        metalness: 0,
        transparent: true,
        opacity: 1,
      });
    }
    m.material.color.set(color === "#ffffff" ? 0xf3f5ff : new THREE.Color(color).getHex());
    m.material.opacity = missing ? 0.15 : 1;
    m.visible = true;
    m.material.needsUpdate = true;
  });
}

function applyLegendCode(code) {
  if (readonlyMode || !selectedTooth || !selectedSurface) return;
  const labels = ensureSurface(toothState[selectedTooth], selectedSurface);
  const idx = labels.indexOf(code);
  if (idx >= 0) labels.splice(idx, 1);
  else labels.splice(0, labels.length, code);

  applyVisualStatus(selectedTooth);
  updateStatusUI();
  sendStateToParent();
}

function selectSurface(fdi, surface) {
  selectedTooth = String(fdi);
  selectedSurface = surface;
  updateStatusUI();

  const mesh = toothMeshes.get(selectedTooth);
  if (mesh) {
    setHighlight(selectedTooth);
    focusCameraOnTooth(mesh);
  }
}

function clearSelection() {
  selectedTooth = null;
  selectedSurface = null;
  updateStatusUI();
  if (highlightMesh) {
    const prev = highlightMesh.userData.toothFdi;
    if (prev) {
      forEachToothMesh(prev, (m) => {
        if (m.material?.emissive) {
          m.material.emissive.set(0x000000);
          m.material.emissiveIntensity = 0;
        }
      });
    }
    highlightMesh = null;
  }
  resetCameraView();
  focusProgress = 0;
}

clearSurfaceBtn?.addEventListener("click", () => {
  if (readonlyMode || !selectedTooth || !selectedSurface) return;
  delete toothState[selectedTooth].surfaces[selectedSurface];
  applyVisualStatus(selectedTooth);
  updateStatusUI();
  sendStateToParent();
});

clearToothBtn?.addEventListener("click", () => {
  if (readonlyMode || !selectedTooth) return;
  toothState[selectedTooth] = createEmptyToothEntry();
  applyVisualStatus(selectedTooth);
  updateStatusUI();
  sendStateToParent();
});

if (toothCommentEl) {
  toothCommentEl.addEventListener("input", () => {
    if (readonlyMode || !selectedTooth) return;
    toothState[selectedTooth].comment = toothCommentEl.value || "";
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
camera.position.set(0, 7.5, 24);
const controls = new OrbitControls(camera, renderer.domElement);
controls.enableDamping = true;
controls.target.set(0, 2.6, -2);
controls.minDistance = 9;
controls.maxDistance = 42;
controls.minPolarAngle = 0.35;
controls.maxPolarAngle = Math.PI / 2.02;
controls.maxAzimuthAngle = Math.PI * 0.7;
controls.minAzimuthAngle = -Math.PI * 0.7;
controls.enablePan = false;
const defaultCamPos = camera.position.clone();
const defaultTarget = controls.target.clone();

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
  obj.userData.toothFdi = fdi;
  obj.traverse((child) => {
    child.userData.toothFdi = fdi;
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

const composer = new EffectComposer(renderer);
composer.addPass(new RenderPass(scene, camera));
const hTilt = new ShaderPass(HorizontalTiltShiftShader);
const vTilt = new ShaderPass(VerticalTiltShiftShader);
hTilt.uniforms.h.value = 1 / 800;
vTilt.uniforms.v.value = 1 / 800;
hTilt.uniforms.r.value = 0.5;
vTilt.uniforms.r.value = 0.5;
composer.addPass(hTilt);
composer.addPass(vTilt);

let focusProgress = 0;
let highlightMesh = null;

function setHighlight(fdi) {
  if (highlightMesh) {
    const prev = highlightMesh.userData.toothFdi;
    if (prev) {
      forEachToothMesh(prev, (m) => {
        if (m.material?.emissive) {
          m.material.emissive.set(0x000000);
          m.material.emissiveIntensity = 0;
        }
      });
    }
    highlightMesh = null;
  }
  const mesh = toothMeshes.get(fdi);
  if (!mesh) return;
  forEachToothMesh(fdi, (m) => {
    if (!m.material) return;
    if (!m.material.emissive) m.material.emissive = new THREE.Color(0x000000);
    m.material.emissive.set(0x7aa2ff);
    m.material.emissiveIntensity = 0.45;
  });
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
  focusProgress = 0;
}

resetViewBtn?.addEventListener("click", resetCameraView);

const raycaster = new THREE.Raycaster();
const mouse = new THREE.Vector2();

canvas.addEventListener("pointerdown", (e) => {
  if (e.button !== 0) return;
  const rect = canvas.getBoundingClientRect();
  mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
  mouse.y = -(((e.clientY - rect.top) / rect.height) * 2 - 1);
  raycaster.setFromCamera(mouse, camera);
  const hits = raycaster.intersectObjects([...toothMeshes.values()], true);
  if (!hits.length) return;
  const fdi = resolveToothFdi(hits[0].object);
  if (!fdi) return;
  selectSurface(fdi, "O");
});

function resizeToDisplaySize() {
  const w = canvas.clientWidth;
  const h = canvas.clientHeight;
  if (!w || !h) return;
  renderer.setSize(w, h, false);
  camera.aspect = w / h;
  camera.updateProjectionMatrix();
  composer.setSize(w, h);
  hTilt.uniforms.h.value = 1 / Math.max(w, 1);
  vTilt.uniforms.v.value = 1 / Math.max(h, 1);
}

function animate() {
  requestAnimationFrame(animate);
  if (!render3d) return;
  resizeToDisplaySize();
  controls.update();
  controls.target.x = THREE.MathUtils.clamp(controls.target.x, -8, 8);
  controls.target.y = THREE.MathUtils.clamp(controls.target.y, 0.2, 8.5);
  controls.target.z = THREE.MathUtils.clamp(controls.target.z, -16, 8);
  const distance = camera.position.distanceTo(controls.target);
  if (distance < controls.minDistance || distance > controls.maxDistance) {
    const dir = camera.position.clone().sub(controls.target).normalize();
    const clampedDistance = THREE.MathUtils.clamp(distance, controls.minDistance, controls.maxDistance);
    camera.position.copy(controls.target.clone().add(dir.multiplyScalar(clampedDistance)));
  }
  if (camAnim.active) {
    const t = (performance.now() - camAnim.t0) / camAnim.dur;
    const tt = easeInOutCubic(Math.min(Math.max(t, 0), 1));
    camera.position.lerpVectors(camAnim.fromPos, camAnim.toPos, tt);
    controls.target.lerpVectors(camAnim.fromTarget, camAnim.toTarget, tt);
    if (t >= 1) camAnim.active = false;
  }
  if (!selectedTooth) {
    hTilt.uniforms.r.value = 0.2;
    vTilt.uniforms.r.value = 0.2;
  } else {
    focusProgress = Math.min(focusProgress + 0.02, 1);
    const pulse = 1 - Math.pow(1 - focusProgress, 3);
    const r = THREE.MathUtils.lerp(0.75, 0.35, pulse);
    hTilt.uniforms.r.value = r;
    vTilt.uniforms.r.value = r;
  }
  composer.render();
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
