// Builds assets/models/hero-tooth.bin (the landing-page 3D tooth) from an
// odontogram GLB. Not part of the site runtime; run it only to regenerate the model.
//
//   mkdir tmp-tooth && cd tmp-tooth && npm init -y
//   npm i @gltf-transform/core@4 @gltf-transform/functions@4 meshoptimizer
//   cp ../scripts/build_hero_tooth.mjs . && node build_hero_tooth.mjs \
//       ../odontogram/models/teeth/mandibular_first_molar.glb ../assets/models/hero-tooth.bin 0.1
//
// The third argument is the simplify ratio (0.1 keeps ~5.5k of the 55k triangles).
import { NodeIO } from '@gltf-transform/core';
import { weld, simplify, dedup, prune } from '@gltf-transform/functions';
import { MeshoptSimplifier } from 'meshoptimizer';
import fs from 'node:fs';

const [src, out, ratioArg] = process.argv.slice(2);
const ratio = Number(ratioArg || 0.15);
const io = new NodeIO();
const doc = await io.read(src);
// Drop everything but geometry before welding so UV/color seams don't block merges.
for (const mesh of doc.getRoot().listMeshes()) {
  for (const prim of mesh.listPrimitives()) {
    for (const sem of prim.listSemantics()) if (sem !== 'POSITION') prim.setAttribute(sem, null);
  }
}
await MeshoptSimplifier.ready;
await doc.transform(weld({ tolerance: 0.0001 }), simplify({ simplifier: MeshoptSimplifier, ratio, error: 0.002 }), dedup(), prune());

const prim = doc.getRoot().listMeshes()[0].listPrimitives()[0];
const pos = prim.getAttribute('POSITION').getArray();
const idx = prim.getIndices().getArray();
const vCount = pos.length / 3;
const min = [Infinity, Infinity, Infinity], max = [-Infinity, -Infinity, -Infinity];
for (let i = 0; i < pos.length; i++) { const a = i % 3; min[a] = Math.min(min[a], pos[i]); max[a] = Math.max(max[a], pos[i]); }
const center = min.map((m, a) => (m + max[a]) / 2);
const half = Math.max(...max.map((m, a) => m - min[a])) / 2;
console.log('verts', vCount, 'tris', idx.length / 3, 'size', max.map((m, a) => ((m - min[a]) / half / 2).toFixed(3)));

const posBytes = vCount * 6;
const pad = (4 - (posBytes % 4)) % 4;
const buf = Buffer.alloc(8 + posBytes + pad + idx.length * 2);
buf.writeUInt32LE(vCount, 0);
buf.writeUInt32LE(idx.length, 4);
for (let i = 0; i < pos.length; i++) buf.writeInt16LE(Math.round(((pos[i] - center[i % 3]) / half) * 32767), 8 + i * 2);
if (vCount > 65535) throw new Error('too many vertices for u16 indices');
for (let i = 0; i < idx.length; i++) buf.writeUInt16LE(idx[i], 8 + posBytes + pad + i * 2);
fs.writeFileSync(out, buf);
console.log('wrote', out, buf.length, 'bytes');
