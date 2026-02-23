import * as THREE from "three";
import { OrbitControls } from "three/addons/controls/OrbitControls.js";

import { EffectComposer } from "three/addons/postprocessing/EffectComposer.js";
import { RenderPass } from "three/addons/postprocessing/RenderPass.js";
import { ShaderPass } from "three/addons/postprocessing/ShaderPass.js";
import { HorizontalTiltShiftShader } from "three/addons/shaders/HorizontalTiltShiftShader.js";
import { VerticalTiltShiftShader } from "three/addons/shaders/VerticalTiltShiftShader.js";

// Debug (safe)
console.log("main.js loaded");

// --------------------
// Data model
// --------------------
const STATUSES = [
  "missing",
  "rootMissing",
  "caries",
  "filled",
  "crown",
  "implant",
];

// Store status per tooth number (1..32)
const toothState = {};
for (let i = 1; i <= 32; i++) toothState[i] = { statuses: new Set() };

let selectedTooth = null;

// --------------------
// UI
// --------------------
const odontogramEl = document.getElementById("odontogram");
const selectedToothEl = document.getElementById("selectedTooth");
const statusSummaryEl = document.getElementById("statusSummary");
const clearStatusBtn = document.getElementById("clearStatus");

const statusButtons = [...document.querySelectorAll(".btn[data-status]")];
const toothButtons = new Map();

function buildOdontogramUI() {
  for (let i = 1; i <= 32; i++) {
    const btn = document.createElement("button");
    btn.className = "toothBtn";
    btn.type = "button";
    btn.innerHTML = `${i}<span class="sub">tooth</span>`;
    btn.addEventListener("click", () => {
      // toggle: clicking the same tooth again clears selection
      if (selectedTooth === i) clearSelection();
      else selectTooth(i);
    });

    odontogramEl.appendChild(btn);
    toothButtons.set(i, btn);
  }
}

function setActiveToothButton(toothNum) {
  for (const [num, btn] of toothButtons.entries()) {
    btn.classList.toggle("is-active", num === toothNum);
  }
}

function updateStatusUI() {
  if (!selectedTooth) {
    selectedToothEl.textContent = "—";
    statusSummaryEl.textContent = "—";
    for (const b of statusButtons) b.classList.remove("is-on");
    return;
  }

  selectedToothEl.textContent = String(selectedTooth);

  const st = [...toothState[selectedTooth].statuses];
  statusSummaryEl.textContent = st.length ? st.join(", ") : "healthy";

  for (const b of statusButtons) {
    const key = b.dataset.status;
    b.classList.toggle("is-on", toothState[selectedTooth].statuses.has(key));
  }
}

function serializeToothState() {
  const out = {};
  for (let i = 1; i <= 32; i++) {
    out[i] = [...toothState[i].statuses];
  }
  return out;
}

function sendStateToParent() {
  try {
    if (window.parent && window.parent !== window) {
      window.parent.postMessage({ type: 'odontogram_update', toothState: serializeToothState() }, '*');
    }
  } catch (e) {
    console.warn('postMessage odontogram failed', e);
  }
}

statusButtons.forEach((btn) => {
  btn.addEventListener("click", () => {
    if (!selectedTooth) return;
    const key = btn.dataset.status;
    const set = toothState[selectedTooth].statuses;
    if (set.has(key)) set.delete(key);
    else set.add(key);

    applyVisualStatus(selectedTooth);
    updateStatusUI();
    sendStateToParent();
  });
});

clearStatusBtn.addEventListener("click", () => {
  if (!selectedTooth) return;
  toothState[selectedTooth].statuses.clear();
  applyVisualStatus(selectedTooth);
  updateStatusUI();
  sendStateToParent();
});

// --------------------
// Three.js Scene
// --------------------
const canvas = document.getElementById("c");
const renderer = new THREE.WebGLRenderer({
  canvas,
  antialias: true,
  alpha: false,
});
renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));

const scene = new THREE.Scene();
scene.background = new THREE.Color(0x0b0f19);

const camera = new THREE.PerspectiveCamera(45, 1, 0.1, 200);
camera.position.set(0, 10, 26);

const controls = new OrbitControls(camera, renderer.domElement);
controls.enableDamping = true;
controls.target.set(0, 2.5, 0);
// Default view (used when no tooth is selected)
const defaultCamPos = camera.position.clone();
const defaultTarget = controls.target.clone();

// Lights
scene.add(new THREE.AmbientLight(0xffffff, 0.55));
const key = new THREE.DirectionalLight(0xffffff, 0.9);
key.position.set(10, 18, 10);
scene.add(key);

const rim = new THREE.DirectionalLight(0x9bbcff, 0.35);
rim.position.set(-12, 12, -14);
scene.add(rim);

// Floor
const floor = new THREE.Mesh(
  new THREE.PlaneGeometry(200, 200),
  new THREE.MeshStandardMaterial({
    color: 0x0a1020,
    roughness: 1,
    metalness: 0,
  })
);
floor.rotation.x = -Math.PI / 2;
floor.position.y = 0;
scene.add(floor);

// --------------------
// Teeth creation
// --------------------
function makeToothMesh() {
  const geo = new THREE.CapsuleGeometry(0.6, 1.5, 6, 10);
  geo.scale(1, 1.15, 0.95);

  const mat = new THREE.MeshStandardMaterial({
    color: 0xf3f5ff,
    roughness: 0.35,
    metalness: 0,
    transparent: true,
    opacity: 1,
  });

  const mesh = new THREE.Mesh(geo, mat);
  mesh.userData.toothNumber = 0;
  return mesh;
}

const teethGroup = new THREE.Group();
scene.add(teethGroup);

const toothMeshes = new Map();

function layoutTeeth() {
  const upperRadius = 7.5;
  const lowerRadius = 7.0;

  const upperStart = Math.PI * 0.15;
  const upperEnd = Math.PI * 0.85;

  const lowerStart = Math.PI * 0.15;
  const lowerEnd = Math.PI * 0.85;

  for (let i = 1; i <= 32; i++) {
    const mesh = makeToothMesh();
    mesh.userData.toothNumber = i;

    const isUpper = i <= 16;
    const idx = isUpper ? i - 1 : i - 17;

    const t = idx / 15;
    const ang =
      (isUpper ? upperStart : lowerStart) +
      t *
        ((isUpper ? upperEnd : lowerEnd) - (isUpper ? upperStart : lowerStart));

    const radius = isUpper ? upperRadius : lowerRadius;
    const x = Math.cos(ang) * radius - 0.2;
    const z = Math.sin(ang) * radius;

    const y = isUpper ? 4.5 : 2.0;

    mesh.position.set(x, y, z - 5);
    mesh.lookAt(0, y, -5);
    mesh.rotation.x += isUpper ? 0.1 : -0.1;

    const scale = idx < 4 || idx > 11 ? 1.05 : 0.92;
    mesh.scale.multiplyScalar(scale);

    teethGroup.add(mesh);
    toothMeshes.set(i, mesh);

    applyVisualStatus(i);
  }
}

function applyVisualStatus(toothNum) {
  const mesh = toothMeshes.get(toothNum);
  if (!mesh) return;

  const statuses = toothState[toothNum].statuses;

  mesh.material.color.set(0xf3f5ff);
  mesh.material.opacity = 1;
  mesh.visible = true;

  if (statuses.has("missing")) mesh.material.opacity = 0.15;

  if (statuses.has("caries")) mesh.material.color.set(0xfda4af);
  if (statuses.has("filled")) mesh.material.color.set(0xa5b4fc);
  if (statuses.has("crown")) mesh.material.color.set(0xfde68a);
  if (statuses.has("implant")) mesh.material.color.set(0x93c5fd);

  if (statuses.has("rootMissing")) {
    mesh.scale.y = Math.max(mesh.scale.y * 0.8, 0.6);
  } else {
    const isUpper = toothNum <= 16;
    const idx = isUpper ? toothNum - 1 : toothNum - 17;
    const baseScale = idx < 4 || idx > 11 ? 1.05 : 0.92;
    mesh.scale.set(baseScale, baseScale * 1.15, baseScale * 0.95);
  }

  mesh.material.needsUpdate = true;
}

// --------------------
// Postprocessing (tilt-shift)
// --------------------
const composer = new EffectComposer(renderer);
composer.addPass(new RenderPass(scene, camera));

const hTilt = new ShaderPass(HorizontalTiltShiftShader);
const vTilt = new ShaderPass(VerticalTiltShiftShader);

// only valid uniforms for these shaders:
hTilt.uniforms.h.value = 1 / 800;
vTilt.uniforms.v.value = 1 / 800;
hTilt.uniforms.r.value = 0.5;
vTilt.uniforms.r.value = 0.5;

composer.addPass(hTilt);
composer.addPass(vTilt);

// Now it’s safe to log uniforms (AFTER init)
console.log("hTilt uniforms keys:", Object.keys(hTilt.uniforms));
console.log("vTilt uniforms keys:", Object.keys(vTilt.uniforms));

// --------------------
// Selection + camera animation
// --------------------
let focusProgress = 0;
let highlightMesh = null;

function setHighlight(toothNum) {
  if (highlightMesh) {
    highlightMesh.material.emissive?.set?.(0x000000);
    highlightMesh.material.emissiveIntensity = 0;
  }
  const mesh = toothMeshes.get(toothNum);
  if (!mesh) return;

  mesh.material.emissive = new THREE.Color(0x7aa2ff);
  mesh.material.emissiveIntensity = 0.45;
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

function clearSelection() {
  selectedTooth = null;
  setActiveToothButton(-1);
  updateStatusUI();

  // remove highlight
  if (highlightMesh) {
    highlightMesh.material.emissive?.set?.(0x000000);
    highlightMesh.material.emissiveIntensity = 0;
    highlightMesh = null;
  }

  // animate camera back to default view
  camAnim.start(
    camera.position.clone(),
    defaultCamPos.clone(),
    controls.target.clone(),
    defaultTarget.clone(),
    650
  );

  // reset blur pulse
  focusProgress = 0;
}

function selectTooth(toothNum) {
  selectedTooth = toothNum;
  setActiveToothButton(toothNum);
  updateStatusUI();

  const mesh = toothMeshes.get(toothNum);
  if (!mesh) return;

  setHighlight(toothNum);

  const focusTarget = mesh.getWorldPosition(new THREE.Vector3()).clone();
  focusTarget.y += 0.35;

  const dir = new THREE.Vector3()
    .subVectors(camera.position, controls.target)
    .normalize();

  const desiredDistance = 10;
  const desiredCamPos = focusTarget
    .clone()
    .add(dir.multiplyScalar(desiredDistance));
  desiredCamPos.y += 1.2;

  camAnim.start(
    camera.position.clone(),
    desiredCamPos,
    controls.target.clone(),
    focusTarget.clone(),
    650
  );

  focusProgress = 0;
}

// --------------------
// Raycast selection
// --------------------
const raycaster = new THREE.Raycaster();
const mouse = new THREE.Vector2();

canvas.addEventListener("pointerdown", (e) => {
  if (e.button !== 0) return;
  const rect = canvas.getBoundingClientRect();
  mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
  mouse.y = -(((e.clientY - rect.top) / rect.height) * 2 - 1);

  raycaster.setFromCamera(mouse, camera);
  const hits = raycaster.intersectObjects([...toothMeshes.values()], false);
  if (hits.length) {
    const toothNum = hits[0].object.userData.toothNumber;
    if (!toothNum) return;
    if (selectedTooth === toothNum) clearSelection();
    else selectTooth(toothNum);
  }
});

// --------------------
// Resize
// --------------------
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

// --------------------
// Render loop
// --------------------
function animate() {
  requestAnimationFrame(animate);
  resizeToDisplaySize();

  controls.update();

  if (camAnim.active) {
    const t = (performance.now() - camAnim.t0) / camAnim.dur;
    const tt = easeInOutCubic(Math.min(Math.max(t, 0), 1));
    camera.position.lerpVectors(camAnim.fromPos, camAnim.toPos, tt);
    controls.target.lerpVectors(camAnim.fromTarget, camAnim.toTarget, tt);
    if (t >= 1) camAnim.active = false;
  }

  // If nothing selected, keep blur low (almost off)
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

// --------------------
// Init
// --------------------
buildOdontogramUI();
layoutTeeth();
updateStatusUI();
animate();

// Listen for parent messages to load initial state
window.addEventListener('message', (ev)=>{
  try {
    if (!ev.data) return;
    if (ev.data.type === 'load_odontogram' && ev.data.toothState) {
      const ts = ev.data.toothState;
      for (let i = 1; i <= 32; i++) {
        toothState[i].statuses = new Set(Array.isArray(ts[i]) ? ts[i] : []);
        applyVisualStatus(i);
      }
      updateStatusUI();
      sendStateToParent();
    }
  } catch(e){ console.warn('odontogram load failed', e); }
});

