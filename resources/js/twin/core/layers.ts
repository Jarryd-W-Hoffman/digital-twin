import type { Placement, TwinDefinition } from './types.ts';

export const ORIGIN: Placement = {
    east: 0,
    north: 0,
    up: 0,
    headingDeg: 0,
    scale: 1,
};

/** A layer's saved placement; undefined for a tileset on its own georeference. */
export function savedPlacement(
    def: TwinDefinition | undefined,
    id: string,
): Placement | undefined {
    return [...(def?.models ?? []), ...(def?.tilesets ?? [])].find(
        (l) => l.id === id,
    )?.placement;
}

/**
 * The definition with edited placements written in. An undefined draft for a
 * tileset removes its placement so it goes back to its own georeference.
 */
export function withPlacements(
    def: TwinDefinition,
    drafts: Record<string, Placement | undefined>,
): TwinDefinition {
    const apply = <T extends { id: string; placement?: Placement }>(
        layer: T,
    ): T => {
        if (!(layer.id in drafts)) return layer;
        const { placement: _old, ...rest } = layer;
        const placement = drafts[layer.id];
        return (placement ? { ...rest, placement } : rest) as T;
    };
    return {
        ...def,
        ...(def.models && { models: def.models.map(apply) }),
        ...(def.tilesets && { tilesets: def.tilesets.map(apply) }),
    };
}

/** What meridian-imagery writes beside an image's tiles. */
export interface ImageryInfo {
    minZoom: number;
    maxZoom: number;
    /** West, south, east, north in degrees. */
    bounds: [number, number, number, number];
}

/** imagery.json read defensively: null unless its zooms and bounds make sense. */
export function parseImageryInfo(raw: unknown): ImageryInfo | null {
    if (typeof raw !== 'object' || raw === null) return null;
    const { minZoom, maxZoom, bounds } = raw as Record<string, unknown>;
    if (
        !Number.isInteger(minZoom) ||
        !Number.isInteger(maxZoom) ||
        (minZoom as number) < 0 ||
        (maxZoom as number) < (minZoom as number) ||
        !Array.isArray(bounds) ||
        bounds.length !== 4 ||
        !bounds.every((b) => typeof b === 'number' && Number.isFinite(b))
    )
        return null;
    const [west, south, east, north] = bounds as number[];
    if (west! >= east! || south! >= north!) return null;
    return {
        minZoom: minZoom as number,
        maxZoom: maxZoom as number,
        bounds: [west!, south!, east!, north!],
    };
}

/** The tile URL template beside an imagery.json. */
export function imageryTiles(url: string): string {
    return url.replace(/imagery\.json(\?.*)?$/, '{z}/{x}/{y}.png');
}

export const VECTOR_COLOR = '#e9b949';
export const VECTOR_FILL_OPACITY = 0.25;

/** A map layer's colour and fill, with the defaults. */
export function vectorStyle(layer: { color?: string; opacity?: number }): {
    color: string;
    fillOpacity: number;
} {
    return {
        color: layer.color ?? VECTOR_COLOR,
        fillOpacity: Math.min(
            1,
            Math.max(0, layer.opacity ?? VECTOR_FILL_OPACITY),
        ),
    };
}

/** Whether a twin has uploads drawn in the scene that the layer toggle hides. */
export function hasUploadedLayers(def: TwinDefinition | undefined): boolean {
    return (
        (def?.tilesets?.length ?? 0) +
            (def?.vectors?.length ?? 0) +
            (def?.imagery?.length ?? 0) >
        0
    );
}
