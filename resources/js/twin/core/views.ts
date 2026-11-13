/** Where a camera was and which way it looked, in degrees and metres. */
export type CameraView = {
    lon: number;
    lat: number;
    height: number;
    heading: number;
    pitch: number;
    roll: number;
};

export const COLOR_BYS = ['status', 'temperature', 'power'] as const;
export const BASEMAPS = ['offline', 'osm', 'custom'] as const;
export const LAYER_KEYS = [
    'labels',
    'flows',
    'zones',
    'context',
    'shadows',
] as const;

/**
 * How the twin looked to someone: the camera, what the scene is coloured by,
 * the basemap and layers, any section cut and the selected asset. Saved as a
 * view, or carried whole in a link.
 */
export type ViewState = {
    camera: CameraView;
    colorBy: (typeof COLOR_BYS)[number];
    basemap: (typeof BASEMAPS)[number];
    layers: Record<(typeof LAYER_KEYS)[number], boolean>;
    section: ViewSection | null;
    selected: string | null;
};

/** A section cut, as the scene's `Section`; a plain type so it can be posted. */
export type ViewSection = {
    above: number | null;
    across: { offset: number; heading: number } | null;
};

/** The limits the control plane checks too. */
export const LIMITS = {
    height: [-12_000, 50_000_000],
    above: [-1_000, 10_000],
    offset: [-100_000, 100_000],
    selected: 200,
} as const;

type Obj = Record<string, unknown>;
const isObj = (v: unknown): v is Obj =>
    typeof v === 'object' && v !== null && !Array.isArray(v);
const within = (v: unknown, min: number, max: number): v is number =>
    typeof v === 'number' && Number.isFinite(v) && v >= min && v <= max;
const oneOf = <T extends string>(v: unknown, list: readonly T[]): v is T =>
    typeof v === 'string' && (list as readonly string[]).includes(v);

function camera(v: unknown): CameraView | null {
    if (!isObj(v)) return null;
    const { lon, lat, height, heading, pitch, roll } = v;
    if (
        !within(lon, -180, 180) ||
        !within(lat, -90, 90) ||
        !within(height, ...LIMITS.height) ||
        !within(heading, -360, 360) ||
        !within(pitch, -90, 90) ||
        !within(roll, -360, 360)
    )
        return null;
    return { lon, lat, height, heading, pitch, roll };
}

function section(v: unknown): ViewSection | null | undefined {
    if (v === null) return null;
    if (!isObj(v)) return undefined;
    const above = v.above === null ? null : v.above;
    if (above !== null && !within(above, ...LIMITS.above)) return undefined;
    let across: ViewSection['across'] = null;
    if (v.across !== null) {
        if (!isObj(v.across)) return undefined;
        const { offset, heading } = v.across;
        if (!within(offset, ...LIMITS.offset) || !within(heading, -360, 360))
            return undefined;
        across = { offset, heading };
    }
    return above === null && across === null ? null : { above, across };
}

/** A view as it came from a link or the control plane, or null if it isn't one. */
export function parseView(v: unknown): ViewState | null {
    if (!isObj(v)) return null;
    const cam = camera(v.camera);
    const cut = section(v.section ?? null);
    if (
        !cam ||
        cut === undefined ||
        !oneOf(v.colorBy, COLOR_BYS) ||
        !oneOf(v.basemap, BASEMAPS) ||
        !isObj(v.layers)
    )
        return null;
    const layers = {} as ViewState['layers'];
    for (const key of LAYER_KEYS) {
        const on = v.layers[key];
        if (typeof on !== 'boolean') return null;
        layers[key] = on;
    }
    const selected = v.selected ?? null;
    if (
        selected !== null &&
        (typeof selected !== 'string' ||
            selected.length === 0 ||
            selected.length > LIMITS.selected)
    )
        return null;
    return {
        camera: cam,
        colorBy: v.colorBy,
        basemap: v.basemap,
        layers,
        section: cut,
        selected,
    };
}

const round = (v: number, places: number) => {
    const f = 10 ** places;
    return Math.round(v * f) / f;
};

/** The view rounded to what a person could tell apart, to keep links short. */
export function tidyView(view: ViewState): ViewState {
    const c = view.camera;
    return {
        ...view,
        camera: {
            lon: round(c.lon, 7),
            lat: round(c.lat, 7),
            height: round(c.height, 2),
            heading: round(c.heading, 2),
            pitch: round(c.pitch, 2),
            roll: round(c.roll, 2),
        },
    };
}

/** A view packed into a URL-safe string, for a link that opens the twin as it looked. */
export function encodeView(view: ViewState): string {
    const bytes = new TextEncoder().encode(JSON.stringify(tidyView(view)));
    let binary = '';
    for (const b of bytes) binary += String.fromCharCode(b);
    return btoa(binary)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}

/** The view a link carries, or null if it was cut short or tampered with. */
export function decodeView(text: string): ViewState | null {
    if (text.length > 4000 || !/^[A-Za-z0-9_-]+$/.test(text)) return null;
    try {
        const binary = atob(text.replace(/-/g, '+').replace(/_/g, '/'));
        const bytes = Uint8Array.from(binary, (ch) => ch.charCodeAt(0));
        return parseView(JSON.parse(new TextDecoder().decode(bytes)));
    } catch {
        return null;
    }
}
