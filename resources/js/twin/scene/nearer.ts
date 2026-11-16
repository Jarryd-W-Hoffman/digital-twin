import { CustomShader, UniformType } from 'cesium';

/**
 * How far each vertex is drawn towards the camera, as a share of its distance
 * from it. Cesium lets terrain stray from the true ground by about two pixels'
 * worth at any distance (its screen-space error), about 0.2% of the distance
 * at a usual field of view, so this stays just ahead of that.
 */
export const NEARER_SHARE = 0.003;

/**
 * Draws a tileset a little nearer the camera than it is, along each vertex's
 * own line of sight, so it lands on exactly the same pixels but in front of
 * terrain at the same height. Without it, a photogrammetry mesh's ground and
 * the terrain beneath it (or imagery draped on the terrain) flicker through
 * each other wherever the terrain's coarser triangles rise above the mesh. A
 * point picked on the tileset is that share nearer than the true surface:
 * about 15 cm at 50 m.
 */
export function nearerThanTheGround(share = NEARER_SHARE): CustomShader {
    return new CustomShader({
        uniforms: {
            u_nearerShare: { type: UniformType.FLOAT, value: share },
        },
        vertexShaderText: /* glsl */ `
            void vertexMain(VertexInput vsInput, inout czm_modelVertexOutput vsOutput) {
                vec3 eye = (czm_inverseModelView * vec4(0.0, 0.0, 0.0, 1.0)).xyz;
                vec3 towardsEye = eye - vsOutput.positionMC;
                vsOutput.positionMC += towardsEye * u_nearerShare;
            }
        `,
    });
}
