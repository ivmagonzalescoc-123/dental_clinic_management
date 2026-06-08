import * as THREE from "three";
import { colorForLabels, isMesialRight, toothHasMissing } from "./chart-constants.js";
import { ensureMeshAssetsIsolated } from "./teeth-3d.js";

const ENAMEL = new THREE.Color(0xf3f5ff);
const HIGHLIGHT = new THREE.Color(0x7aa2ff);
const MISSING_COLOR = new THREE.Color(0x8b95a8);
export const MISSING_TOOTH_3D_OPACITY = 0.35;

function hexToColor(hex) {
  return new THREE.Color(hex === "#ffffff" ? 0xf3f5ff : hex);
}

function isUpperTooth(fdi) {
  const q = Math.floor(parseInt(String(fdi), 10) / 10);
  return q === 1 || q === 2;
}

function surfaceChartColor(entry, surfaceKey) {
  const labels = entry?.surfaces?.[surfaceKey];
  if (labels?.length) return hexToColor(colorForLabels(labels));
  if (entry?.surfaces?.whole?.length) return hexToColor(colorForLabels(entry.surfaces.whole));
  return ENAMEL.clone();
}

export function classifySurfaceFromNormal(normal, position, fdi) {
  const mesialSign = isMesialRight(fdi) ? 1 : -1;
  const upper = isUpperTooth(fdi);
  const ny = normal.y;
  const nz = normal.z;
  const nx = normal.x;
  const absNy = Math.abs(ny);
  const absNz = Math.abs(nz);
  const absNx = Math.abs(nx);

  // Upper roots sit above the crown; lower roots sit below.
  if (upper && position.y > 0.12) return "root";
  if (!upper && position.y < -0.12) return "root";

  // Upper occlusal faces down; lower occlusal faces up.
  if (upper && ny < -0.42 && absNy >= absNz * 0.9 && absNy >= absNx * 0.9) return "O";
  if (!upper && ny > 0.42 && absNy >= absNz * 0.9 && absNy >= absNx * 0.9) return "O";

  if (absNz >= absNx && absNz >= absNy * 0.75) return nz >= 0 ? "B" : "L";

  if (nx * mesialSign > 0.38) return "M";
  if (nx * mesialSign < -0.38) return "D";

  if (absNy >= absNz && absNy >= absNx) return "O";
  if (absNz >= absNx) return nz >= 0 ? "B" : "L";
  return nx * mesialSign >= 0 ? "M" : "D";
}

function meshIsRoot(mesh) {
  const n = (mesh.name || "").toLowerCase();
  return mesh.name === "root" || n.includes("root") || n.includes("apex");
}

function ensureVertexColors(geometry) {
  if (!geometry.attributes.normal) geometry.computeVertexNormals();
  if (!geometry.attributes.color) {
    const count = geometry.attributes.position.count;
    geometry.setAttribute("color", new THREE.BufferAttribute(new Float32Array(count * 3), 3));
  }
}

function enableVertexColorMaterial(material, { missing = false } = {}) {
  if (!material) return;
  material.vertexColors = true;
  material.color.set(0xffffff);
  material.transparent = true;
  material.opacity = missing ? MISSING_TOOTH_3D_OPACITY : 1;
  material.depthWrite = !missing;
  if (material.emissive) {
    material.emissive.set(0x000000);
    material.emissiveIntensity = 0;
  }
  material.needsUpdate = true;
}

export function paintMeshSurfaceColors(mesh, toothRoot, fdi, entry, { highlightSurface = null, missing = false } = {}) {
  if (!mesh.geometry) return;
  ensureMeshAssetsIsolated(mesh);

  const materials = Array.isArray(mesh.material) ? mesh.material : [mesh.material];
  for (const material of materials) enableVertexColorMaterial(material, { missing });

  if (missing) {
    const geometry = mesh.geometry;
    ensureVertexColors(geometry);
    const colors = geometry.attributes.color;
    for (let i = 0; i < colors.count; i++) {
      colors.setXYZ(i, MISSING_COLOR.r, MISSING_COLOR.g, MISSING_COLOR.b);
    }
    colors.needsUpdate = true;
    return;
  }

  const geometry = mesh.geometry;
  ensureVertexColors(geometry);

  toothRoot.updateMatrixWorld(true);
  mesh.updateMatrixWorld(true);

  const toothInv = toothRoot.matrixWorld.clone().invert();
  const localMatrix = new THREE.Matrix4().multiplyMatrices(toothInv, mesh.matrixWorld);
  const normalMatrix = new THREE.Matrix3().getNormalMatrix(localMatrix);

  const posAttr = geometry.attributes.position;
  const normAttr = geometry.attributes.normal;
  const colors = geometry.attributes.color;
  const isRootMesh = meshIsRoot(mesh);

  const v = new THREE.Vector3();
  const n = new THREE.Vector3();
  const color = new THREE.Color();

  for (let i = 0; i < posAttr.count; i++) {
    v.fromBufferAttribute(posAttr, i).applyMatrix4(localMatrix);
    let surfaceKey = "O";

    if (isRootMesh) {
      surfaceKey = "root";
    } else {
      n.fromBufferAttribute(normAttr, i).applyMatrix3(normalMatrix).normalize();
      surfaceKey = classifySurfaceFromNormal(n, v, fdi);
    }

    color.copy(surfaceChartColor(entry, surfaceKey));
    if (highlightSurface && surfaceKey === highlightSurface) {
      color.lerp(HIGHLIGHT, 0.42);
    }

    colors.setXYZ(i, color.r, color.g, color.b);
  }

  colors.needsUpdate = true;
}

export function getSurfaceViewDirection(surface, fdi) {
  const mesialRight = isMesialRight(fdi);
  const mesialX = mesialRight ? 1 : -1;
  const distalX = -mesialX;
  const upper = isUpperTooth(fdi);

  switch (surface) {
    case "B":
      return new THREE.Vector3(0, 0.06, 1);
    case "L":
      return new THREE.Vector3(0, 0.06, -1);
    case "M":
      return new THREE.Vector3(mesialX, 0.04, 0.42);
    case "D":
      return new THREE.Vector3(distalX, 0.04, 0.42);
    case "O":
      // View toward occlusal: upper from below, lower from above.
      return upper ? new THREE.Vector3(0.04, -1, 0.38) : new THREE.Vector3(0.04, 1, 0.38);
    case "root":
      // View toward root apex: upper from above, lower from below.
      return upper ? new THREE.Vector3(0, 1, 0.38) : new THREE.Vector3(0, -1, 0.38);
    case "whole":
    default:
      return upper ? new THREE.Vector3(0.22, 0.55, 1) : new THREE.Vector3(0.22, -0.55, 1);
  }
}

export function getSurfaceCameraPose(toothRoot, fdi, surface) {
  const box = new THREE.Box3().setFromObject(toothRoot);
  const center = box.getCenter(new THREE.Vector3());
  const size = box.getSize(new THREE.Vector3());
  const radius = Math.max(size.x, size.y, size.z, 0.5) * 0.55;

  const dir = getSurfaceViewDirection(surface, fdi).normalize();
  const worldDir = dir.clone().applyQuaternion(toothRoot.getWorldQuaternion(new THREE.Quaternion())).normalize();
  const camPos = center.clone().add(worldDir.multiplyScalar(radius * 2.15));

  return { pos: camPos, target: center };
}
