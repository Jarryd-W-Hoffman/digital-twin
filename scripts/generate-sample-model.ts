import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { toGlb, type Box } from './glb.ts';

/**
 * A small pump skid to try the upload and placement flow with: a base plate,
 * two tanks and a motor, centred on its own origin.
 */
const OUT = resolve(import.meta.dirname, '../public/samples/models');

const skid: Box[] = [
    { x: -3, y: -1.5, w: 6, d: 3, h: 0.3 },
    { x: -2.6, y: -1, z: 0.3, w: 1.8, d: 2, h: 2.6 },
    { x: -0.4, y: -1, z: 0.3, w: 1.8, d: 2, h: 2.6 },
    { x: 1.8, y: -0.6, z: 0.3, w: 1, d: 1.2, h: 1 },
];

mkdirSync(OUT, { recursive: true });
writeFileSync(
    resolve(OUT, 'pump-skid.glb'),
    toGlb(skid, [0.85, 0.45, 0.12, 1], 'meridian-sample'),
);
console.log(`pump skid written to ${OUT}`);
