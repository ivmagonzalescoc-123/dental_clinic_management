// Builds odontogram/models/teeth-lite/ from the full-size odontogram/models/teeth/ GLBs.
// Not part of the site runtime; run it only when the source models change.
//
//   mkdir tmp-models && cd tmp-models && npm init -y
//   npm i @gltf-transform/core@4 @gltf-transform/extensions@4 @gltf-transform/functions@4 meshoptimizer
//   cp ../scripts/optimize_odontogram_models.mjs . && node optimize_odontogram_models.mjs \
//       ../odontogram/models/teeth ../odontogram/models/teeth-lite 0.13 512
//
// Arguments: source dir, output dir, simplify ratio (0.13 keeps ~7k of ~55k triangles),
// texture size. What it keeps identical to the originals, because the chart code
// depends on it: file names, node names/transforms, one primitive per mesh, NORMAL and
// TEXCOORD_0 attributes, material setup, and a SEPARATE (non-interleaved) vertex layout
// (teeth-3d.js deepCloneGeometry() copies attribute arrays directly).
// COLOR_0 is dropped: tooth-surface-3d.js creates the vertex colours it paints.
import { createRequire } from 'node:module';
// Use the sharp copy gltf-transform already depends on; loading a second copy in the
// same process fails on Windows.
const requireHere = createRequire(import.meta.url);
const sharp = createRequire(requireHere.resolve('ndarray-pixels'))('sharp');
import { NodeIO, VertexLayout } from '@gltf-transform/core';
import { ALL_EXTENSIONS } from '@gltf-transform/extensions';
import { weld, simplify, dedup, prune } from '@gltf-transform/functions';
import { MeshoptSimplifier } from 'meshoptimizer';


import fs from 'node:fs';
import path from 'node:path';

const [srcDir, outDir, ratioArg, texArg] = process.argv.slice(2);
const RATIO = Number(ratioArg || 0.13);
const TEX = Number(texArg || 512);
// SEPARATE (not interleaved) like the originals: teeth-3d.js deepCloneGeometry() copies attr.array directly.
const io = new NodeIO().registerExtensions(ALL_EXTENSIONS).setVertexLayout(VertexLayout.SEPARATE);
await MeshoptSimplifier.ready;
fs.mkdirSync(outDir, { recursive: true });

// Fill the black area around the UV islands with neighbouring colour so
// mipmapping doesn't bleed dark seams onto the tooth.
async function shrinkAndDilate(image) {
  const { data, info } = await sharp(Buffer.from(image.slice())).resize(TEX, TEX).toColourspace('srgb').removeAlpha().raw().toBuffer({ resolveWithObject: true });
  const w = info.width, h = info.height, px = new Float32Array(w * h * 3), filled = new Uint8Array(w * h);
  for (let i = 0; i < w * h; i++) {
    const r = data[i * 3], g = data[i * 3 + 1], b = data[i * 3 + 2];
    px[i * 3] = r; px[i * 3 + 1] = g; px[i * 3 + 2] = b;
    filled[i] = r + g + b > 60 ? 1 : 0;
  }
  for (let pass = 0; pass < 64; pass++) {
    const next = filled.slice(); let changed = 0;
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
      const i = y * w + x; if (filled[i]) continue;
      let r = 0, g = 0, b = 0, n = 0;
      for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) {
        const xx = x + dx, yy = y + dy; if (xx < 0 || yy < 0 || xx >= w || yy >= h) continue;
        const j = yy * w + xx; if (!filled[j]) continue;
        r += px[j * 3]; g += px[j * 3 + 1]; b += px[j * 3 + 2]; n++;
      }
      if (n) { px[i * 3] = r / n; px[i * 3 + 1] = g / n; px[i * 3 + 2] = b / n; next[i] = 1; changed++; }
    }
    filled.set(next); if (!changed) break;
  }
  const out = Buffer.alloc(w * h * 3); for (let i = 0; i < out.length; i++) out[i] = Math.round(px[i]);
  return sharp(out, { raw: { width: w, height: h, channels: 3 } }).jpeg({ quality: 86, mozjpeg: true }).toBuffer();
}

let totalIn = 0, totalOut = 0;
for (const file of fs.readdirSync(srcDir).filter((f) => f.endsWith('.glb')).sort()) {
  const src = path.join(srcDir, file);
  const doc = await io.read(src);
  let trisIn = 0;
  for (const mesh of doc.getRoot().listMeshes()) for (const prim of mesh.listPrimitives()) {
    trisIn += prim.getIndices().getCount() / 3;
    // The chart repaints vertex colours itself (tooth-surface-3d.js creates them when absent).
    prim.setAttribute('COLOR_0', null);
  }
  await doc.transform(weld(), simplify({ simplifier: MeshoptSimplifier, ratio: RATIO, error: 0.001 }), dedup(), prune());
  for (const tex of doc.getRoot().listTextures()) {
    tex.setImage(new Uint8Array(await shrinkAndDilate(tex.getImage()))).setMimeType('image/jpeg').setURI('');
  }
  const out = path.join(outDir, file);
  await io.write(out, doc);
  let trisOut = 0; for (const mesh of doc.getRoot().listMeshes()) for (const prim of mesh.listPrimitives()) trisOut += prim.getIndices().getCount() / 3;
  const a = fs.statSync(src).size, b = fs.statSync(out).size; totalIn += a; totalOut += b;
  console.log(file.padEnd(40), (a / 1e6).toFixed(2) + 'MB ->', (b / 1e3).toFixed(0) + 'KB', 'tris', trisIn, '->', trisOut);
}
console.log('TOTAL', (totalIn / 1e6).toFixed(1) + 'MB ->', (totalOut / 1e6).toFixed(2) + 'MB');
