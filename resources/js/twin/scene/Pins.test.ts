import type { Entity, Viewer } from 'cesium';
import { describe, expect, it } from 'vitest';
import type { Pin } from '../state/store.ts';
import { Pins } from './Pins.ts';

type Source = {
    entities: {
        collectionChanged: {
            addEventListener(
                cb: (c: unknown, added: Entity[], removed: Entity[]) => void,
            ): void;
        };
    };
};

function pinsWithSource(): { pins: Pins; source: Source } {
    let source: Source | undefined;
    const viewer = {
        dataSources: {
            add: (s: Source) => {
                source = s;
                return Promise.resolve(s);
            },
        },
    } as unknown as Viewer;
    const pins = new Pins(viewer);
    return { pins, source: source! };
}

const pin: Pin = {
    id: 'a1',
    number: 1,
    title: 'Pump skid guard is loose',
    closed: false,
    at: [174.76, -36.84, 3],
};

describe('Pins', () => {
    it('tells the renderer about a pin redrawn when it is chosen', () => {
        const { pins, source } = pinsWithSource();
        const list = [pin];
        pins.set(list, null);
        const added: Entity[] = [];
        source.entities.collectionChanged.addEventListener((_, a) =>
            added.push(...a),
        );

        pins.set(list, 'a1');

        expect(added).toHaveLength(1);
        expect(added[0].id).toBe('pin:a1');
        expect(added[0].label?.text?.getValue()).toBe(
            '#1 Pump skid guard is loose',
        );
    });

    it('names the issue a picked pin belongs to', () => {
        const { pins } = pinsWithSource();

        expect(pins.idOf({ id: 'pin:a1' })).toBe('a1');
        expect(pins.idOf({ id: 'asset:chiller' })).toBeNull();
        expect(pins.idOf(undefined)).toBeNull();
    });
});
