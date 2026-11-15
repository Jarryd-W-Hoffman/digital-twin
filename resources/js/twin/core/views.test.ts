import { describe, expect, it } from 'vitest';
import { decodeView, encodeView, parseView, type ViewState } from './views.ts';

const view: ViewState = {
    camera: {
        lon: 174.76331234567,
        lat: -36.84851234567,
        height: 120.456,
        heading: 30.1234,
        pitch: -35.5678,
        roll: 0,
    },
    colorBy: 'temperature',
    basemap: 'osm',
    layers: {
        labels: true,
        flows: false,
        zones: true,
        context: true,
        shadows: true,
    },
    section: { above: 6, across: null },
    selected: 'chiller',
    siteDate: '2026-03-10',
};

describe('parseView', () => {
    it('takes a whole view', () => {
        expect(parseView(view)).toEqual(view);
    });

    it('treats a missing section, selection and date as none, as in views saved before dates', () => {
        const { section: _s, selected: _sel, siteDate: _date, ...rest } = view;

        expect(parseView(rest)).toMatchObject({
            section: null,
            selected: null,
            siteDate: null,
        });
    });

    it('treats a section that cuts nothing as none', () => {
        expect(
            parseView({ ...view, section: { above: null, across: null } })
                ?.section,
        ).toBeNull();
    });

    it('keeps a cut across', () => {
        const cut = { above: null, across: { offset: -12, heading: 90 } };

        expect(parseView({ ...view, section: cut })?.section).toEqual(cut);
    });

    it.each([
        ['not an object', 'view'],
        ['an array', [view]],
        [
            'a camera off the globe',
            { ...view, camera: { ...view.camera, lat: 91 } },
        ],
        ['a camera missing a field', { ...view, camera: { lon: 1, lat: 2 } }],
        ['a date that is not one', { ...view, siteDate: '10/03/2026' }],
        ['a date that is not a string', { ...view, siteDate: 20260310 }],
        [
            'a camera that is not a number',
            { ...view, camera: { ...view.camera, height: '60' } },
        ],
        ['an unknown colouring', { ...view, colorBy: 'mood' }],
        ['an unknown basemap', { ...view, basemap: 'satellite' }],
        ['layers that are not an object', { ...view, layers: true }],
        [
            'a layer that is not on or off',
            { ...view, layers: { ...view.layers, zones: 1 } },
        ],
        ['a layer missing', { ...view, layers: { labels: true } }],
        ['a section that is not an object', { ...view, section: 6 }],
        [
            'a cut above out of range',
            { ...view, section: { above: 1e9, across: null } },
        ],
        [
            'a cut across that is not an object',
            { ...view, section: { above: null, across: 4 } },
        ],
        [
            'a cut across out of range',
            {
                ...view,
                section: { above: null, across: { offset: 0, heading: 720 } },
            },
        ],
        ['an empty selection', { ...view, selected: '' }],
        [
            'a selection that is too long',
            { ...view, selected: 'x'.repeat(201) },
        ],
        ['a selection that is not text', { ...view, selected: 3 }],
    ])('refuses %s', (_, value) => {
        expect(parseView(value)).toBeNull();
    });
});

describe('links', () => {
    it('round-trips a view through a URL-safe string, rounded', () => {
        const text = encodeView(view);

        expect(text).toMatch(/^[A-Za-z0-9_-]+$/);
        expect(decodeView(text)).toEqual({
            ...view,
            camera: {
                lon: 174.7633123,
                lat: -36.8485123,
                height: 120.46,
                heading: 30.12,
                pitch: -35.57,
                roll: 0,
            },
        });
    });

    it('carries names outside ASCII', () => {
        expect(
            decodeView(encodeView({ ...view, selected: 'pompe-à-chaleur' }))
                ?.selected,
        ).toBe('pompe-à-chaleur');
    });

    it.each([
        ['characters a link would not carry', 'abc+/='],
        ['something too long to be a view', 'a'.repeat(4001)],
        ['text that is not JSON', 'bm90IGpzb24'],
        ['JSON that is not a view', 'eyJhIjoxfQ'],
    ])('refuses %s', (_, text) => {
        expect(decodeView(text)).toBeNull();
    });
});
