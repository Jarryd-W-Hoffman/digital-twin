import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { Cartesian3, Matrix4, Transforms } from 'cesium';
import { toGlb, type Box } from './glb.ts';

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

const boxes = buildings();
mkdirSync(OUT, { recursive: true });
writeFileSync(resolve(OUT, 'context.glb'), toGlb(boxes, [0.075, 0.09, 0.11, 1], 'meridian-context'));

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
