import * as THREE from "three";

export function resolveTeethModelsBase(baseUrl) {
  if (baseUrl) return baseUrl;
  if (typeof document !== "undefined" && document.baseURI) {
    return new URL("./models/teeth/", document.baseURI);
  }
  return new URL("./models/teeth/", import.meta.url);
}

const TOOTH_FILES = {
  incisor_upper: "maxillary_left_central_incisor.glb",
  incisor_lower: "mandibular_left_central_incisor.glb",
  canine_upper: "maxillary_canine.glb",
  canine_lower: "mandibular_left_canine.glb",
  premolar_upper: "maxillary_first_premolar.glb",
  premolar_lower: "mandibular_first_premolar.glb",
  molar1_upper: "maxillary_first_molar.glb",
  molar1_lower: "mandibular_first_molar.glb",
  molar2_upper: "maxillary_second_molar.glb",
  molar2_lower: "mandibular_second_molar.glb",
  molar3_upper: "maxillary_third_molar.glb",
  molar3_lower: "mandibular_third_molar.glb",
};

export function toothWidthByFdi(fdi) {
  const pos = parseInt(String(fdi).slice(-1), 10);
  const q = Math.floor(parseInt(String(fdi), 10) / 10);
  const up = q === 1 || q === 2;
  const w = {
    1: up ? 0.6 : 0.38,
    2: up ? 0.46 : 0.42,
    3: up ? 0.54 : 0.5,
    4: up ? 0.36 : 0.36,
    5: up ? 0.34 : 0.36,
    6: up ? 0.38 : 0.4,
    7: up ? 0.34 : 0.36,
    8: up ? 0.3 : 0.3,
  };
  return w[pos] ?? 0.85;
}

export function toothTemplateKey(fdi) {
  const pos = parseInt(String(fdi).slice(-1), 10);
  const q = Math.floor(parseInt(String(fdi), 10) / 10);
  const jaw = q === 1 || q === 2 ? "upper" : "lower";
  if (pos <= 2) return `incisor_${jaw}`;
  if (pos === 3) return `canine_${jaw}`;
  if (pos <= 5) return `premolar_${jaw}`;
  if (pos === 6) return `molar1_${jaw}`;
  if (pos === 7) return `molar2_${jaw}`;
  return `molar3_${jaw}`;
}

export function toothTypeFromFdi(fdi) {
  const pos = parseInt(String(fdi).slice(-1), 10);
  if (pos <= 2) return "incisor";
  if (pos === 3) return "canine";
  if (pos <= 5) return "premolar";
  return "molar";
}

/**
 * Which way each template's buccal/labial surface faces, as a yaw in degrees
 * (0 = +Z, 90 = +X). The GLBs keep the orientation they had inside the source jaw
 * scan (patient's front = +Z, patient's left = +X) and are all LEFT-side teeth, so
 * premolars and molars face +X rather than +Z. prepareToothTemplate() turns each one
 * so buccal = +Z, mesial = -X: the frame tooth-surface-3d.js classifies surfaces in.
 * Right-side teeth (quadrants 1 and 4) are mirrored copies of these.
 */
// Measured from the models: labial crown lean (incisors, canines), palatal root vs the
// buccal root pair and tallest buccal cusp (upper premolar/molars), mesial-distal root
// axis (lower molars).
const TEMPLATE_BUCCAL_YAW_DEG = {
  incisor_upper: 0,
  canine_upper: 15,
  premolar_upper: 75,
  molar1_upper: 70,
  molar2_upper: 80,
  molar3_upper: 77,
  incisor_lower: 0,
  canine_lower: 15,
  premolar_lower: 65,
  molar1_lower: 57,
  molar2_lower: 84,
  molar3_lower: 67,
};

export function templateBuccalYaw(key) {
  return THREE.MathUtils.degToRad(TEMPLATE_BUCCAL_YAW_DEG[key] ?? 0);
}

export const TOOTH_MIRROR_NODE = "__toothMirror";

/** Quadrants 1 and 4 are the patient's right side. */
export function isRightSideTooth(fdi) {
  const q = Math.floor(parseInt(String(fdi), 10) / 10);
  return q === 1 || q === 4;
}

// Tight side-by-side spacing — teeth sit close like a natural arch.
const ARCH_CONTACT = 0.78;
const ARCH_WIDTH = 1.38;

export function buildArchLayout(jaw) {
  const isUp = jaw === "upper";
  const ids = isUp
    ? ["18", "17", "16", "15", "14", "13", "12", "11", "21", "22", "23", "24", "25", "26", "27", "28"]
    : ["48", "47", "46", "45", "44", "43", "42", "41", "31", "32", "33", "34", "35", "36", "37", "38"];

  const halfW = ids.slice(0, 8).reduce((sum, id) => sum + toothWidthByFdi(id) * ARCH_CONTACT, 0);
  const positions = [];
  let cumX = -halfW;

  for (const fdi of ids) {
    const w = toothWidthByFdi(fdi) * ARCH_CONTACT;
    const cx = cumX + w / 2;
    cumX += w;

    const nx = cx / halfW;
    const archWidth = ARCH_WIDTH;
    const archDepth = 3.5;
    const z = -archDepth * Math.pow(Math.abs(nx), 2.8);
    const scaleX = archWidth / halfW;
    const dzdcx = (-archDepth * 2.8 * Math.sign(nx) * Math.pow(Math.abs(nx), 1.8)) / halfW;
    const dzdx = dzdcx / scaleX;
    const rawRotY = Math.atan2(-dzdx, 1.0);
    const rotY = archRotationForTooth(nx, rawRotY);

    positions.push({
      fdi,
      x: cx * scaleX,
      y: isUp ? 0.7 : -0.7,
      z,
      nx,
      rotY,
      // Yaw of the arch's outward normal here (0 = straight forward).
      outwardYaw: rawRotY,
      isUpper: isUp,
    });
  }

  return positions;
}

// Arch curve z = -(A u^2 + B u^4), u = 1 at the second-molar centre. Fitted to typical
// upper-arch landmarks (canine ~17mm out / 9mm back, second molar ~27mm out / 37mm
// back): a broad rounded front (U shape) with near-straight sides, not a V.
const ARCH_A = 0.49;
const ARCH_B = 0.88;
const archDepth = (u) => ARCH_A * u * u + ARCH_B * u * u * u * u;
const archSlope = (u) => 2 * ARCH_A * u + 4 * ARCH_B * u * u * u;

/** Typical mesio-distal crown widths (mm) by tooth position 1-8. */
const CROWN_WIDTH_MM = {
  upper: [8.5, 6.5, 7.5, 7.0, 6.5, 10.0, 9.0, 8.5],
  lower: [5.0, 5.5, 7.0, 7.0, 7.0, 11.0, 10.5, 10.0],
};

export function crownWidthMm(fdi) {
  const q = Math.floor(parseInt(String(fdi), 10) / 10);
  const pos = parseInt(String(fdi).slice(-1), 10);
  return CROWN_WIDTH_MM[q <= 2 ? "upper" : "lower"][pos - 1] ?? 7;
}

/**
 * Places teeth edge to edge ALONG the arch curve (by arc length), using each tooth's
 * mesio-distal crown width, so neighbours touch instead of drifting apart where the
 * arch runs front to back. `widths` are in the same units as the returned x/z.
 */
export function buildAnatomicalArchLayout(jaw, widths) {
  const isUp = jaw === "upper";
  const right = isUp ? ["11", "12", "13", "14", "15", "16", "17", "18"] : ["41", "42", "43", "44", "45", "46", "47", "48"];
  const left = isUp ? ["21", "22", "23", "24", "25", "26", "27", "28"] : ["31", "32", "33", "34", "35", "36", "37", "38"];

  // Arc length from the midline to each tooth's centre on one side.
  const centreS = [];
  let s = 0;
  for (const fdi of right) {
    const w = widths(fdi);
    centreS.push(s + w / 2);
    s += w;
  }
  // Scale the unit arch curve so the second molar centre lands at u = 1.
  const steps = 4000;
  const xMax = 2.2;
  const table = [{ x: 0, s: 0 }];
  for (let i = 1; i <= steps; i++) {
    const x = (xMax * i) / steps;
    const prev = table[i - 1];
    table.push({ x, s: prev.s + Math.hypot(x - prev.x, archDepth(x) - archDepth(prev.x)) });
  }
  const sAtUnit = table[Math.round(steps / xMax)].s;
  const scale = centreS[6] / sAtUnit; // tooth 7 (second molar) centre at x = 1 * scale
  const xForArc = (arc) => {
    const target = arc / scale;
    let lo = 0, hi = table.length - 1;
    while (hi - lo > 1) {
      const mid = (lo + hi) >> 1;
      if (table[mid].s < target) lo = mid; else hi = mid;
    }
    const a = table[lo], b = table[hi];
    return a.x + ((target - a.s) / Math.max(b.s - a.s, 1e-9)) * (b.x - a.x);
  };

  const halfWidth = xForArc(centreS[7]) * scale || 1;
  const place = (fdi, arc, side) => {
    const ux = xForArc(arc);
    const x = ux * scale * side;
    const z = -archDepth(ux) * scale;
    const dzdx = -archSlope(ux) * side; // slope of the arch at this tooth
    const outwardYaw = Math.atan2(-dzdx, 1.0);
    const nx = x / halfWidth;
    return {
      fdi,
      x,
      y: isUp ? 0.7 : -0.7,
      z,
      nx,
      rotY: archRotationForTooth(nx, outwardYaw),
      outwardYaw,
      isUpper: isUp,
    };
  };

  const positions = [];
  right.forEach((fdi, i) => positions.push(place(fdi, centreS[i], -1)));
  left.forEach((fdi, i) => positions.push(place(fdi, centreS[i], 1)));
  return positions;
}

/** Mesio-distal crown width of a template (normalized units), measured once and cached. */
export function templateCrownWidth(tpl, upper) {
  if (tpl.userData.crownWidth) return tpl.userData.crownWidth;
  tpl.updateMatrixWorld(true);
  const pts = [];
  const v = new THREE.Vector3();
  tpl.traverse((obj) => {
    if (!obj.isMesh) return;
    const pos = obj.geometry.attributes.position;
    for (let i = 0; i < pos.count; i++) pts.push(v.fromBufferAttribute(pos, i).applyMatrix4(obj.matrixWorld).clone());
  });
  let minY = Infinity, maxY = -Infinity;
  for (const p of pts) { minY = Math.min(minY, p.y); maxY = Math.max(maxY, p.y); }
  const h = maxY - minY;
  // Crown = occlusal 40% (upper crowns point down, lower crowns up); X is mesio-distal
  // once the template is normalized to buccal = +Z.
  let minX = Infinity, maxX = -Infinity;
  for (const p of pts) {
    const inCrown = upper ? p.y < minY + h * 0.4 : p.y > maxY - h * 0.4;
    if (!inCrown) continue;
    minX = Math.min(minX, p.x);
    maxX = Math.max(maxX, p.x);
  }
  tpl.userData.crownWidth = Number.isFinite(maxX - minX) && maxX > minX ? maxX - minX : 0.5;
  return tpl.userData.crownWidth;
}

function smoothstep(edge0, edge1, x) {
  const t = Math.max(0, Math.min(1, (x - edge0) / (edge1 - edge0)));
  return t * t * (3 - 2 * t);
}

/** Front teeth face forward; arch tangent rotation ramps in toward the sides. */
function archRotationForTooth(nx, rawRotY) {
  const blend = smoothstep(Math.abs(nx), 0.06, 0.38);
  return rawRotY * blend;
}

function archTiltRadians(fdi, nx = 0) {
  const pos = parseInt(String(fdi).slice(-1), 10);
  const sideBlend = smoothstep(Math.abs(nx), 0.12, 0.5);
  const side = Math.sign(nx) || 1;

  if (pos <= 2) return 0;

  if (pos === 3) {
    return ((-10 * Math.PI) / 180) * sideBlend * side;
  }

  const type = toothTypeFromFdi(fdi);
  const deg = type === "molar" || type === "premolar" ? -45 : -20;
  return ((deg * Math.PI) / 180) * sideBlend * side;
}

export function applyNaturalToothOrientation(tooth, pos, { procedural = false } = {}) {
  tooth.rotation.order = "YXZ";

  if (procedural) {
    const extraRot = archTiltRadians(pos.fdi, pos.nx ?? 0);
    tooth.rotation.x = pos.isUpper ? 0.08 : -0.08;
    tooth.rotation.y = -pos.rotY + Math.PI / 2 + extraRot;
    tooth.rotation.z = 0;
    return;
  }

  // GLB templates are normalized to buccal = +Z (see TEMPLATE_BUCCAL_YAW_DEG), so
  // turning the tooth to the arch's outward normal puts buccal outside and
  // lingual/palatal toward the tongue on both sides.
  tooth.rotation.x = 0;
  tooth.rotation.y = pos.outwardYaw ?? pos.rotY;
  tooth.rotation.z = 0;
}

/** Copy geometry buffers so GLB clones never share vertex/color data across teeth. */
export function deepCloneGeometry(geometry) {
  const cloned = new THREE.BufferGeometry();
  for (const name in geometry.attributes) {
    const attr = geometry.attributes[name];
    cloned.setAttribute(
      name,
      new THREE.BufferAttribute(attr.array.slice(), attr.itemSize, attr.normalized)
    );
  }
  if (geometry.index) {
    cloned.setIndex(
      new THREE.BufferAttribute(geometry.index.array.slice(), geometry.index.itemSize)
    );
  }
  return cloned;
}

function cloneMeshMaterial(material) {
  return material?.clone ? material.clone() : material;
}

/** Each placed tooth needs its own geometry + materials (templates are reused by type). */
export function isolateToothAssets(root) {
  root.traverse((child) => {
    if (!child.isMesh) return;
    if (child.geometry) {
      child.geometry = deepCloneGeometry(child.geometry);
    }
    if (!child.material) return;
    if (Array.isArray(child.material)) {
      child.material = child.material.map(cloneMeshMaterial);
      return;
    }
    child.material = cloneMeshMaterial(child.material);
    child.userData.__paintReady = true;
  });
  root.userData.__assetsIsolated = true;
}

/** Safety net before painting — guarantees this mesh is not sharing GLB buffers. */
export function ensureMeshAssetsIsolated(mesh) {
  if (mesh.userData.__paintReady) return;
  if (mesh.geometry) {
    mesh.geometry = deepCloneGeometry(mesh.geometry);
  }
  if (mesh.material) {
    if (Array.isArray(mesh.material)) {
      mesh.material = mesh.material.map(cloneMeshMaterial);
    } else {
      mesh.material = cloneMeshMaterial(mesh.material);
    }
  }
  mesh.userData.__paintReady = true;
}

/** @deprecated Use isolateToothAssets */
export function isolateToothMaterials(root) {
  isolateToothAssets(root);
}

function normalizeMeshMaterials(mesh) {
  if (!mesh.isMesh || !mesh.material) return;

  mesh.castShadow = true;
  mesh.receiveShadow = true;

  const polish = (src) => {
    let mat = src;
    if (mat && typeof mat.clone === "function") {
      mat = mat.clone();
    } else {
      mat = new THREE.MeshStandardMaterial({
        color: 0xf6f1e8,
        roughness: 0.4,
        metalness: 0.02,
      });
    }

    if (mat.isMeshPhysicalMaterial || mat.isMeshStandardMaterial) {
      if (mat.isMeshPhysicalMaterial) {
        mat.clearcoat = 0.75;
        mat.clearcoatRoughness = 0.2;
      }
      mat.roughness = Math.min(mat.roughness ?? 0.45, 0.48);
      mat.metalness = Math.min(mat.metalness ?? 0.02, 0.08);
      if (!mat.emissive) mat.emissive = new THREE.Color(0x000000);
      mat.emissiveIntensity = 0;
    }
    mat.side = THREE.DoubleSide;

    return mat;
  };

  mesh.material = Array.isArray(mesh.material)
    ? mesh.material.map(polish)
    : polish(mesh.material);
}

function prepareToothTemplate(scene, key) {
  scene.updateMatrixWorld(true);
  const box = new THREE.Box3().setFromObject(scene);
  const center = box.getCenter(new THREE.Vector3());
  const size = box.getSize(new THREE.Vector3());
  scene.position.sub(center);
  scene.updateMatrixWorld(true);

  const maxDim = Math.max(size.x, size.y, size.z, 0.001);
  // wrapper (unit scale) > mirror (x = -1 for right-side teeth) > orient (buccal -> +Z) > scene.
  // Both extra nodes sit inside the wrapper, so surface classification still works in
  // the same units as before (it measures relative to the wrapper).
  const orient = new THREE.Group();
  orient.rotation.y = -templateBuccalYaw(key);
  orient.add(scene);
  const mirror = new THREE.Group();
  mirror.name = TOOTH_MIRROR_NODE;
  mirror.add(orient);
  const wrapper = new THREE.Group();
  wrapper.add(mirror);
  wrapper.scale.setScalar(1.0 / maxDim);

  scene.traverse((obj) => {
    try {
      normalizeMeshMaterials(obj);
    } catch (err) {
      console.warn("[odontogram 3d] material normalize failed", err);
    }
  });

  return wrapper;
}

function parseGltfBuffer(loader, buffer, url) {
  return new Promise((resolve, reject) => {
    loader.parse(
      buffer,
      url,
      (gltf) => resolve(gltf),
      (err) => reject(err || new Error("GLTF parse failed"))
    );
  });
}

async function fetchToothGltf(loader, file, bases) {
  let lastError = null;
  for (const base of bases) {
    const url = new URL(file, base).href;
    try {
      const response = await fetch(url);
      if (!response.ok) {
        throw new Error(`HTTP ${response.status} for ${file}`);
      }
      const buffer = await response.arrayBuffer();
      return { gltf: await parseGltfBuffer(loader, buffer, url), url };
    } catch (err) {
      lastError = err;
      if (bases.length > 1) console.warn(`[odontogram 3d] ${url} failed, trying next model folder`, err);
    }
  }
  throw lastError || new Error(`No model folder for ${file}`);
}

/**
 * `modelsBase` may be one folder or a list tried in order per file
 * (optimized models first, full-size originals as a fallback).
 */
export async function loadToothTemplates(loader, modelsBase, { onProgress } = {}) {
  const templates = new Map();
  const bases = (Array.isArray(modelsBase) ? modelsBase : [modelsBase]).map(resolveTeethModelsBase);
  const base = bases[0];
  const entries = Object.entries(TOOTH_FILES);
  const failures = [];
  const total = entries.length;
  let done = 0;

  const report = (label) => {
    if (!onProgress) return;
    const pct = Math.min(85, Math.round((done / total) * 85));
    onProgress(pct, label);
  };

  onProgress?.(0, "Starting 3D model download…");

  await Promise.all(
    entries.map(async ([key, file]) => {
      const url = new URL(file, base).href;
      try {
        const { gltf } = await fetchToothGltf(loader, file, bases);
        templates.set(key, prepareToothTemplate(gltf.scene, key));
      } catch (err) {
        failures.push({ file, url, message: err?.message || String(err) });
        console.warn(`[odontogram 3d] failed to load ${file} from ${url}`, err);
      } finally {
        done += 1;
        report(`Loading models (${done}/${total})…`);
      }
    })
  );

  return { templates, failures, modelsBase: base.href };
}

export function layoutRealisticArch({
  teethGroup,
  toothMeshes,
  templates,
  allFdiTeeth,
  tagToothObject,
  applyVisualStatus,
  createFallbackTooth,
}) {
  teethGroup.clear();
  toothMeshes.clear();

  const layoutScale = 2.65;
  const archRoot = new THREE.Group();
  archRoot.position.set(0, 3.0, -5);
  teethGroup.add(archRoot);

  const upperPivot = new THREE.Group();
  upperPivot.rotation.x = -0.16;
  upperPivot.position.y = 0.55;
  const lowerPivot = new THREE.Group();
  lowerPivot.rotation.x = 0.16;
  lowerPivot.position.y = -0.55;
  archRoot.add(upperPivot);
  archRoot.add(lowerPivot);

  let loadedCount = 0;

  const typeScaleFor = (fdi) => {
    const type = toothTypeFromFdi(fdi);
    return type === "molar" ? 1.08 : type === "premolar" ? 0.96 : type === "canine" ? 0.88 : 0.82;
  };
  const isUpperFdi = (fdi) => Math.floor(parseInt(String(fdi), 10) / 10) <= 2;
  // World units per mm, anchored so the upper first molar keeps its previous size.
  const molarTpl = templates.get("molar1_upper");
  const unitsPerMm = molarTpl
    ? (templateCrownWidth(molarTpl, true) * typeScaleFor("16") * layoutScale * 0.64) / crownWidthMm("16")
    : 0;
  // Size each GLB tooth so its crown is its typical width (laterals smaller than
  // centrals, etc.); schematic fallback teeth keep the old per-type scale.
  const toothScaleFor = (fdi) => {
    const tpl = templates.get(toothTemplateKey(fdi));
    if (!tpl || !unitsPerMm) return typeScaleFor(fdi) * layoutScale * 0.64;
    return (crownWidthMm(fdi) * unitsPerMm) / templateCrownWidth(tpl, isUpperFdi(fdi));
  };
  // Crown width in layout units (positions are multiplied by layoutScale below).
  const archWidthFor = (fdi) => (crownWidthMm(fdi) * unitsPerMm) / layoutScale;
  const useAnatomicalArch = templates.size > 0 && unitsPerMm > 0;

  for (const jaw of ["upper", "lower"]) {
    const parent = jaw === "upper" ? upperPivot : lowerPivot;
    const positions = useAnatomicalArch ? buildAnatomicalArchLayout(jaw, archWidthFor) : buildArchLayout(jaw);

    for (const pos of positions) {
      const key = toothTemplateKey(pos.fdi);
      const tpl = templates.get(key);
      let tooth;

      if (tpl) {
        tooth = tpl.clone(true);
        // Templates are left-side teeth; mirror them for the patient's right.
        const mirror = tooth.getObjectByName(TOOTH_MIRROR_NODE);
        if (mirror) mirror.scale.x = isRightSideTooth(pos.fdi) ? -1 : 1;
        isolateToothAssets(tooth);
        tagToothObject(tooth, pos.fdi);
        loadedCount++;
      } else if (typeof createFallbackTooth === "function") {
        tooth = createFallbackTooth(pos.fdi, pos);
        tagToothObject(tooth, pos.fdi);
      } else {
        continue;
      }

      tooth.position.set(pos.x * layoutScale, pos.y * layoutScale, pos.z * layoutScale);

      const usingGlb = !!tpl;
      applyNaturalToothOrientation(tooth, pos, { procedural: !usingGlb });

      tooth.scale.multiplyScalar(toothScaleFor(pos.fdi));

      parent.add(tooth);
      const fdi = String(pos.fdi);
      toothMeshes.set(fdi, tooth);
      applyVisualStatus(fdi);
    }
  }

  return loadedCount;
}
