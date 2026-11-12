import { Material } from 'cesium';

/**
 * How much a slope darkens or lightens, per unit of difference between its
 * light and flat ground's, and the most it can.
 */
const STRENGTH = 1.6;
const MOST = 0.55;

/**
 * A cartographic hillshade for terrain. Cesium leaves the globe unshaded up
 * close, so at site scale relief barely shows. This lights each point of the
 * ground from the north-west, 45° up, in its own east-north-up frame (so it
 * reads the same at any time of day), and compares it with flat ground there: a
 * slope facing the light is brightened, one facing away darkened, and flat
 * ground left as it is. It needs the terrain's vertex normals.
 */
export function hillshade(): Material {
    return new Material({
        fabric: {
            type: 'MeridianHillshade',
            uniforms: { strength: STRENGTH, most: MOST },
            source: /* glsl */ `
                const vec3 ONE_OVER_RADII_SQUARED = vec3(
                    1.0 / (6378137.0 * 6378137.0),
                    1.0 / (6378137.0 * 6378137.0),
                    1.0 / (6356752.314245 * 6356752.314245)
                );

                czm_material czm_getMaterial(czm_materialInput materialInput)
                {
                    czm_material material = czm_getDefaultMaterial(materialInput);
                    // Flat ground's up and compass at this point. (The input's
                    // tangent frame is built on the slope's own normal.)
                    vec3 world = (czm_inverseView * vec4(-materialInput.positionToEyeEC, 1.0)).xyz;
                    vec3 upWC = czm_geodeticSurfaceNormal(world, vec3(0.0), ONE_OVER_RADII_SQUARED);
                    vec3 eastWC = normalize(cross(vec3(0.0, 0.0, 1.0), upWC));
                    vec3 northWC = cross(upWC, eastWC);
                    vec3 up = normalize(czm_viewRotation * upWC);
                    vec3 east = czm_viewRotation * eastWC;
                    vec3 north = czm_viewRotation * northWC;
                    vec3 light = normalize(normalize(north - east) + up);
                    float lit = dot(normalize(materialInput.normalEC), light) - dot(up, light);
                    material.diffuse = lit > 0.0 ? vec3(1.0) : vec3(0.0);
                    material.alpha = min(abs(lit) * strength, most);
                    return material;
                }
            `,
        },
        translucent: false,
    });
}
