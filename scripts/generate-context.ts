import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { Cartesian3, Matrix4, Transforms } from 'cesium';

/**
 * Generates a real OGC 3D Tiles 1.1 tileset of fictional neighbouring buildings,
 * so the Cesium tiles pipeline runs end to end with no external service. A
 * photogrammetry or BIM export would plug into `tilesets` the same way.
 */

const ORIGIN = { lon: 153.0812, lat: -27.4405 };
const OUT = resolve(import.meta.dirname, '../public/tilesets/context');
// The campus sits inside this rectangle, and is kept clear of generated buildings.
const CLEAR = { x0: -290, x1: 270, y0: -225, y1: 150 };
const EXTENT = 900;

interface Box {
  x: number;
  y: number;
  w: number;
  d: number;
  h: number;
}

function rng(seed: number) {
  let s = seed;
  return () => {
    s = (Math.imul(s, 1664525) + 1013904223) >>> 0;
    return s / 0x100000000;
  };
}

function buildings(): Box[] {
  const random = rng(42);
  const boxes: Box[] = [];
  const block = 78;
  for (let bx = -EXTENT; bx < EXTENT; bx += block) {
    for (let by = -EXTENT; by < EXTENT; by += block) {
      if (random() < 0.24) continue;
      const w = 22 + random() * 38;
      const d = 22 + random() * 38;
      const x = bx + random() * 10;
      const y = by + random() * 10;
      const overlapsCampus = x + w > CLEAR.x0 && x < CLEAR.x1 && y + d > CLEAR.y0 && y < CLEAR.y1;
      if (overlapsCampus) continue;
      boxes.push({ x, y, w, d, h: 6 + random() * random() * 34 });
    }
  }
  return boxes;
}

/** One merged mesh; glTF is Y-up, so east = x, up = y, north = -z. */
function toGlb(boxes: Box[]): Buffer {
  const positions: number[] = [];
  const normals: number[] = [];
  const indices: number[] = [];
  const quad = (a: number[], b: number[], c: number[], d: number[], n: number[]) => {
    const start = positions.length / 3;
    for (const p of [a, b, c, d]) {
      positions.push(p[0]!, p[1]!, p[2]!);
      normals.push(n[0]!, n[1]!, n[2]!);
    }
    indices.push(start, start + 1, start + 2, start, start + 2, start + 3);
  };
  for (const { x, y, w, d, h } of boxes) {
    const [x0, x1, z0, z1] = [x, x + w, -y, -(y + d)] as const;
    quad([x0, 0, z1], [x0, h, z1], [x1, h, z1], [x1, 0, z1], [0, 0, -1]);
    quad([x1, 0, z0], [x1, h, z0], [x0, h, z0], [x0, 0, z0], [0, 0, 1]);
    quad([x1, 0, z1], [x1, h, z1], [x1, h, z0], [x1, 0, z0], [1, 0, 0]);
    quad([x0, 0, z0], [x0, h, z0], [x0, h, z1], [x0, 0, z1], [-1, 0, 0]);
    quad([x0, h, z0], [x1, h, z0], [x1, h, z1], [x0, h, z1], [0, 1, 0]);
  }
  const pos = Buffer.from(new Float32Array(positions).buffer);
  const nor = Buffer.from(new Float32Array(normals).buffer);
  const idx = Buffer.from(new Uint32Array(indices).buffer);
  const bin = Buffer.concat([pos, nor, idx]);
  const min = [0, 1, 2].map((a) => Math.min(...positions.filter((_, i) => i % 3 === a)));
  const max = [0, 1, 2].map((a) => Math.max(...positions.filter((_, i) => i % 3 === a)));

  const gltf = {
    asset: { version: '2.0', generator: 'meridian-context' },
    scene: 0,
    scenes: [{ nodes: [0] }],
    nodes: [{ mesh: 0 }],
    meshes: [{ primitives: [{ attributes: { POSITION: 0, NORMAL: 1 }, indices: 2, material: 0 }] }],
    materials: [{
      pbrMetallicRoughness: { baseColorFactor: [0.075, 0.09, 0.11, 1], metallicFactor: 0, roughnessFactor: 0.9 },
    }],
    accessors: [
      { bufferView: 0, componentType: 5126, count: positions.length / 3, type: 'VEC3', min, max },
      { bufferView: 1, componentType: 5126, count: normals.length / 3, type: 'VEC3' },
      { bufferView: 2, componentType: 5125, count: indices.length, type: 'SCALAR' },
    ],
    bufferViews: [
      { buffer: 0, byteOffset: 0, byteLength: pos.length, target: 34962 },
      { buffer: 0, byteOffset: pos.length, byteLength: nor.length, target: 34962 },
      { buffer: 0, byteOffset: pos.length + nor.length, byteLength: idx.length, target: 34963 },
    ],
    buffers: [{ byteLength: bin.length }],
  };
  const pad = (b: Buffer, fill: number) => Buffer.concat([b, Buffer.alloc((4 - (b.length % 4)) % 4, fill)]);
  const json = pad(Buffer.from(JSON.stringify(gltf)), 0x20);
  const binary = pad(bin, 0);
  const total = 12 + 8 + json.length + 8 + binary.length;
  const header = Buffer.alloc(12);
  header.writeUInt32LE(0x46546c67, 0);
  header.writeUInt32LE(2, 4);
  header.writeUInt32LE(total, 8);
  const chunk = (data: Buffer, type: number) => {
    const head = Buffer.alloc(8);
    head.writeUInt32LE(data.length, 0);
    head.writeUInt32LE(type, 4);
    return Buffer.concat([head, data]);
  };
  return Buffer.concat([header, chunk(json, 0x4e4f534a), chunk(binary, 0x004e4942)]);
}

const boxes = buildings();
mkdirSync(OUT, { recursive: true });
writeFileSync(resolve(OUT, 'context.glb'), toGlb(boxes));

const center = Cartesian3.fromDegrees(ORIGIN.lon, ORIGIN.lat, 0);
const transform = Matrix4.toArray(Transforms.eastNorthUpToFixedFrame(center));
const tileset = {
  asset: { version: '1.1' },
  geometricError: 500,
  root: {
    transform,
    boundingVolume: { box: [0, 0, 25, EXTENT + 60, 0, 0, 0, EXTENT + 60, 0, 0, 0, 30] },
    geometricError: 0,
    refine: 'ADD',
    content: { uri: 'context.glb' },
  },
};
writeFileSync(resolve(OUT, 'tileset.json'), `${JSON.stringify(tileset, null, 2)}\n`);
console.log(`${boxes.length} buildings written to ${OUT}`);
