import type { Cartesian2, Cartesian3, Viewer } from 'cesium';

/**
 * Whatever is under a screen position: a model or tileset where the depth
 * buffer has one, else the terrain, else the ellipsoid.
 */
export function surfaceAt(
    viewer: Viewer,
    position: Cartesian2,
): Cartesian3 | null {
    const { scene, camera } = viewer;
    if (scene.pickPositionSupported) {
        const hit = scene.pickPosition(position);
        if (hit && Number.isFinite(hit.x)) return hit;
    }
    const ray = camera.getPickRay(position);
    return (
        (ray && scene.globe.pick(ray, scene)) ??
        camera.pickEllipsoid(position) ??
        null
    );
}
