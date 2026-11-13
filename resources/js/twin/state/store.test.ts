import { beforeEach, describe, expect, it } from 'vitest';
import { useTwin } from './store.ts';

const view = { lon: 1, lat: 2, height: 30, heading: 0, pitch: -30, roll: 0 };

describe('pinning an asset', () => {
    beforeEach(() => useTwin.getState().clearPlacedPin());

    it('asks for an asset to be pinned, again each time', () => {
        useTwin.getState().pinAsset('store');
        const first = useTwin.getState().assetPinRequest;
        useTwin.getState().pinAsset('store');

        expect(first).toEqual({ id: 'store', n: 1 });
        expect(useTwin.getState().assetPinRequest).toEqual({
            id: 'store',
            n: 2,
        });
    });

    it('keeps the request until the pin is placed', () => {
        useTwin.getState().pinAsset('store');
        useTwin.getState().placePin([1, 2, 3], view);

        expect(useTwin.getState().placedPin).toEqual({ at: [1, 2, 3], view });
        expect(useTwin.getState().assetPinRequest).toBeNull();
    });

    it('drops the request when the new issue is given up', () => {
        useTwin.getState().pinAsset('store');
        useTwin.getState().clearPlacedPin();

        expect(useTwin.getState().assetPinRequest).toBeNull();
        expect(useTwin.getState().pinning).toBe(false);
    });
});
