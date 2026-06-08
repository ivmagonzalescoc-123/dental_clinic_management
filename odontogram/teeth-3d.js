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
      isUpper: isUp,
    });
  }

  return positions;
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

  if (pos <= 2) return 0;

  if (pos === 3) {
    return ((-10 * Math.PI) / 180) * sideBlend;
  }

  const type = toothTypeFromFdi(fdi);
  const deg = type === "molar" || type === "premolar" ? -45 : -20;
  return ((deg * Math.PI) / 180) * sideBlend;
}

/** Match DentProSave GLB placement: models are already jaw-oriented; only arch Y rotation. */
export function applyNaturalToothOrientation(tooth, pos, { procedural = false } = {}) {
  const extraRot = archTiltRadians(pos.fdi, pos.nx ?? 0);
  tooth.rotation.order = "YXZ";

  if (procedural) {
    tooth.rotation.x = pos.isUpper ? 0.08 : -0.08;
    tooth.rotation.y = -pos.rotY + Math.PI / 2 + extraRot;
    tooth.rotation.z = 0;
    return;
  }

  // GLB maxillary/mandibular files are exported with correct crown direction per jaw.
  tooth.rotation.x = 0;
  tooth.rotation.y = pos.rotY + extraRot;
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

function prepareToothTemplate(scene) {
  scene.updateMatrixWorld(true);
  const box = new THREE.Box3().setFromObject(scene);
  const center = box.getCenter(new THREE.Vector3());
  const size = box.getSize(new THREE.Vector3());
  scene.position.sub(center);
  scene.updateMatrixWorld(true);

  const maxDim = Math.max(size.x, size.y, size.z, 0.001);
  const wrapper = new THREE.Group();
  wrapper.add(scene);
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

export async function loadToothTemplates(loader, modelsBase, { onProgress } = {}) {
  const templates = new Map();
  const base = resolveTeethModelsBase(modelsBase);
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
        const response = await fetch(url);
        if (!response.ok) {
          throw new Error(`HTTP ${response.status} for ${file}`);
        }
        const buffer = await response.arrayBuffer();
        const gltf = await parseGltfBuffer(loader, buffer, url);
        templates.set(key, prepareToothTemplate(gltf.scene));
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

  for (const jaw of ["upper", "lower"]) {
    const parent = jaw === "upper" ? upperPivot : lowerPivot;
    const positions = buildArchLayout(jaw);

    for (const pos of positions) {
      const key = toothTemplateKey(pos.fdi);
      const tpl = templates.get(key);
      let tooth;

      if (tpl) {
        tooth = tpl.clone(true);
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

      const type = toothTypeFromFdi(pos.fdi);
      const usingGlb = !!tpl;
      applyNaturalToothOrientation(tooth, pos, { procedural: !usingGlb });

      const typeScale =
        type === "molar" ? 1.08 : type === "premolar" ? 0.96 : type === "canine" ? 0.88 : 0.82;
      tooth.scale.multiplyScalar(typeScale * layoutScale * 0.64);

      parent.add(tooth);
      const fdi = String(pos.fdi);
      toothMeshes.set(fdi, tooth);
      applyVisualStatus(fdi);
    }
  }

  return loadedCount;
}
