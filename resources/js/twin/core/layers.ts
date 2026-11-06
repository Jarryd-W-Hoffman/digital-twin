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
