// Cesium loads its workers, shaders and widget assets at runtime from
// CESIUM_BASE_URL. Copying them under public/ lets Laravel serve them in dev
// and production alike, without Cesium ion or a CDN.
import { cpSync, existsSync, rmSync } from 'node:fs';

const from = 'node_modules/cesium/Build/Cesium';
const to = 'public/vendor/cesium';

if (!existsSync(from)) {
    console.warn(`copy-cesium: ${from} not found, skipping`);
    process.exit(0);
}
rmSync(to, { recursive: true, force: true });
for (const dir of ['Workers', 'ThirdParty', 'Assets', 'Widgets']) {
    cpSync(`${from}/${dir}`, `${to}/${dir}`, { recursive: true });
}
console.log(`copy-cesium: copied to ${to}`);
