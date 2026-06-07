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

export function buildArchLayout(jaw) {
  const isUp = jaw === "upper";
  const ids = isUp
    ? ["18", "17", "16", "15", "14", "13", "12", "11", "21", "22", "23", "24", "25", "26", "27", "28"]
    : ["48", "47", "46", "45", "44", "43", "42", "41", "31", "32", "33", "34", "35", "36", "37", "38"];

  const halfW = ids.slice(0, 8).reduce((sum, id) => sum + toothWidthByFdi(id), 0);
  const positions = [];
  let cumX = -halfW;

  for (const fdi of ids) {
    const w = toothWidthByFdi(fdi);
    const cx = cumX + w / 2;
    cumX += w;

    const nx = cx / halfW;
    const archWidth = 2.5;
    const archDepth = 3.5;
    const z = -archDepth * Math.pow(Math.abs(nx), 2.8);
    const scaleX = archWidth / halfW;
    const dzdcx = (-archDepth * 2.8 * Math.sign(nx) * Math.pow(Math.abs(nx), 1.8)) / halfW;
    const dzdx = dzdcx / scaleX;
    const rotY = Math.atan2(-dzdx, 1.0);

    positions.push({
      fdi,
      x: cx * scaleX,
      y: isUp ? 0.7 : -0.7,
      z,
      rotY,
      isUpper: isUp,
    });
  }

  return positions;
}

function archTiltRadians(fdi) {
  const type = toothTypeFromFdi(fdi);
  const deg = type === "molar" || type === "premolar" ? -45 : -20;
  return (deg * Math.PI) / 180;
}

/** Match DentProSave GLB placement: models are already jaw-oriented; only arch Y rotation. */
export function applyNaturalToothOrientation(tooth, pos, { procedural = false } = {}) {
  const extraRot = archTiltRadians(pos.fdi);
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

export async function loadToothTemplates(loader, modelsBase) {
  const templates = new Map();
  const base = resolveTeethModelsBase(modelsBase);
  const entries = Object.entries(TOOTH_FILES);
  const failures = [];

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

  const layoutScale = 3.15;
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
      tooth.scale.multiplyScalar(typeScale * layoutScale * 0.52);

      parent.add(tooth);
      toothMeshes.set(pos.fdi, tooth);
      applyVisualStatus(pos.fdi);
    }
  }

  return loadedCount;
}
