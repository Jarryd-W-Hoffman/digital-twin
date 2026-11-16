import { UniformType } from 'cesium';
import { describe, expect, it } from 'vitest';
import { NEARER_SHARE, nearerThanTheGround } from './nearer.ts';

describe('nearerThanTheGround', () => {
    it('moves each vertex a share of the way towards the camera', () => {
        const shader = nearerThanTheGround();

        expect(shader.uniforms.u_nearerShare).toEqual({
            type: UniformType.FLOAT,
            value: NEARER_SHARE,
        });
        expect(shader.vertexShaderText).toContain(
            'vsOutput.positionMC += towardsEye * u_nearerShare;',
        );
    });

    it('stays ahead of terrain within two pixels of error, and barely moves a picked point', () => {
        // Two pixels of a 1000 px view 60° high, as a share of the distance.
        const terrainError = 2 * (Math.PI / 3 / 1000);

        expect(NEARER_SHARE).toBeGreaterThan(terrainError);
        expect(NEARER_SHARE * 50).toBeLessThan(0.2);
    });

    it('takes another share', () => {
        expect(nearerThanTheGround(0.01).uniforms.u_nearerShare.value).toBe(
            0.01,
        );
    });
});
