import {
    CallbackProperty,
    Cartesian2,
    Cartesian3,
    Cartographic,
    Color,
    ColorMaterialProperty,
    CustomDataSource,
    Ellipsoid,
    HorizontalOrigin,
    IntersectionTests,
    LabelStyle,
    Math as CesiumMath,
    Plane,
    PolygonHierarchy,
    PolylineDashMaterialProperty,
    ScreenSpaceEventHandler,
    ScreenSpaceEventType,
    VerticalOrigin,
    type Viewer,
} from 'cesium';
import {
    closestAlongLine,
    distanceParts,
    formatArea,
    formatLength,
    type MeasuredPoint,
    type Measurement,
    type MeasureLock,
    type MeasureTool,
    perimeter,
    planArea,
    slopeOf,
    type Vec3,
} from '../core/measure.ts';

const COLOUR = Color.fromCssColorString('#5ce1ff');
const LEG = Color.fromCssColorString('#ffc95c');
/** How far either side of a point the ground is sampled for its slope. */
const SLOPE_SPACING_M = 2;

const vec = (c: Cartesian3): Vec3 => [c.x, c.y, c.z];
/** Within the depth buffer's precision of the ground counts as on it. */
const aboveGround = (height: number, ground: number) =>
    Math.abs(height - ground) < 0.2 ? 0 : height - ground;

/**
 * Measuring in the scene: distances (optionally held level or vertical),
 * heights above the ground, areas and single points. Points are picked on
 * whatever is under the pointer, terrain, models or tilesets, and the
 * result follows the pointer until a click fixes it.
 */
export class Measure {
    onChange?: (measurement: Measurement | null) => void;
    private readonly source = new CustomDataSource('measure');
    private readonly handler: ScreenSpaceEventHandler;
    private tool: MeasureTool | null = null;
    private lock: MeasureLock = 'none';
    private fixed: Cartesian3[] = [];
    private hover: Cartesian3 | null = null;
    private pending: Cartesian2 | null = null;
    private frame?: number;

    constructor(
        private readonly viewer: Viewer,
        /** The ground's height (above the ellipsoid) at a place. */
        private readonly groundHeight: (at: Cartographic) => number,
    ) {
        void viewer.dataSources.add(this.source);
        this.handler = new ScreenSpaceEventHandler(viewer.scene.canvas);
        this.handler.setInputAction(
            (e: { endPosition: Cartesian2 }) => this.move(e.endPosition),
            ScreenSpaceEventType.MOUSE_MOVE,
        );
    }

    get active(): boolean {
        return this.tool !== null;
    }

    /** Starts a tool, or stops measuring given null. Either clears what was measured. */
    set(tool: MeasureTool | null, lock: MeasureLock = 'none'): void {
        const changed = tool !== this.tool;
        this.tool = tool;
        this.lock = lock;
        if (changed || !tool) {
            this.fixed = [];
            this.hover = null;
        }
        this.update();
    }

    /** Forgets what was measured, keeping the tool. */
    clear(): void {
        this.fixed = [];
        this.hover = null;
        this.update();
    }

    /** A click while measuring: fixes a point, or starts the next measurement. */
    click(position: Cartesian2): void {
        if (!this.tool) return;
        const point = this.pick(position);
        if (!point) return;
        const complete =
            (this.tool === 'distance' && this.fixed.length >= 2) ||
            ((this.tool === 'height' || this.tool === 'point') &&
                this.fixed.length >= 1);
        if (complete) this.fixed = [];
        this.fixed = [...this.fixed, point];
        this.hover = null;
        this.update();
    }

    /** Test hook: measures at these screen positions, as clicks would. */
    clickAt(x: number, y: number): void {
        this.click(new Cartesian2(x, y));
    }

    destroy(): void {
        if (this.frame !== undefined) cancelAnimationFrame(this.frame);
        this.handler.destroy();
        void this.viewer.dataSources.remove(this.source, true);
    }

    private move(position: Cartesian2): void {
        if (!this.tool) return;
        // Picking reads the depth buffer: once a frame is plenty.
        this.pending = Cartesian2.clone(position);
        this.frame ??= requestAnimationFrame(() => {
            this.frame = undefined;
            const at = this.pending;
            if (!at || !this.tool) return;
            const waiting =
                this.tool === 'area' ||
                (this.tool === 'distance' && this.fixed.length === 1);
            if (!waiting) return;
            this.hover = this.pick(at);
            this.update();
        });
    }

    /** What is under the pointer, held level or vertical for a locked distance. */
    private pick(position: Cartesian2): Cartesian3 | null {
        const { scene, camera } = this.viewer;
        const first = this.fixed[0];
        if (
            this.tool === 'distance' &&
            this.fixed.length === 1 &&
            first &&
            this.lock !== 'none'
        ) {
            const ray = camera.getPickRay(position);
            if (!ray) return null;
            const up = Ellipsoid.WGS84.geodeticSurfaceNormal(
                first,
                new Cartesian3(),
            );
            if (this.lock === 'horizontal')
                return (
                    IntersectionTests.rayPlane(
                        ray,
                        Plane.fromPointNormal(first, up),
                    ) ?? null
                );
            const t = closestAlongLine(
                vec(first),
                vec(up),
                vec(ray.origin),
                vec(ray.direction),
            );
            return t === null
                ? null
                : Cartesian3.add(
                      first,
                      Cartesian3.multiplyByScalar(up, t, new Cartesian3()),
                      new Cartesian3(),
                  );
        }
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

    private measured(c: Cartesian3): MeasuredPoint {
        const carto = Cartographic.fromCartesian(c);
        return {
            position: vec(c),
            lon: CesiumMath.toDegrees(carto.longitude),
            lat: CesiumMath.toDegrees(carto.latitude),
            height: carto.height,
            ground: this.groundHeight(carto),
        };
    }

    /** The point straight below `c` at height `height`. */
    private atHeight(c: Cartesian3, height: number): Cartesian3 {
        const carto = Cartographic.fromCartesian(c);
        return Cartesian3.fromRadians(carto.longitude, carto.latitude, height);
    }

    private result(points: Cartesian3[]): Measurement | null {
        const measured = points.map((c) => this.measured(c));
        const [a, b] = measured;
        switch (this.tool) {
            case 'distance':
                return a && b
                    ? {
                          tool: 'distance',
                          points: measured,
                          ...distanceParts(a, b),
                      }
                    : null;
            case 'height':
                return a
                    ? {
                          tool: 'height',
                          points: measured,
                          aboveGround: aboveGround(
                              a.height,
                              a.ground ?? a.height,
                          ),
                      }
                    : null;
            case 'area': {
                if (measured.length < 3) return null;
                const origin = Cartesian3.fromRadians(
                    CesiumMath.toRadians(measured[0]!.lon),
                    CesiumMath.toRadians(measured[0]!.lat),
                    0,
                );
                const up = Ellipsoid.WGS84.geodeticSurfaceNormal(
                    origin,
                    new Cartesian3(),
                );
                const east = Cartesian3.normalize(
                    Cartesian3.cross(Cartesian3.UNIT_Z, up, new Cartesian3()),
                    new Cartesian3(),
                );
                const north = Cartesian3.cross(up, east, new Cartesian3());
                const plan = points.map((c): [number, number] => {
                    const d = Cartesian3.subtract(c, origin, new Cartesian3());
                    return [Cartesian3.dot(d, east), Cartesian3.dot(d, north)];
                });
                // Around the plan, as the area is.
                const flat = plan.map(([x, y]): Vec3 => [x, y, 0]);
                return {
                    tool: 'area',
                    points: measured,
                    area: planArea(plan),
                    perimeter: perimeter(flat),
                };
            }
            case 'point': {
                if (!a) return null;
                const heightAt = (dEast: number, dNorth: number) => {
                    const R = Ellipsoid.WGS84.maximumRadius;
                    const lat = CesiumMath.toRadians(a.lat);
                    return this.groundHeight(
                        Cartographic.fromRadians(
                            CesiumMath.toRadians(a.lon) +
                                dEast / (R * Math.cos(lat)),
                            lat + dNorth / R,
                        ),
                    );
                };
                const s = SLOPE_SPACING_M;
                const { slope, aspect } = slopeOf(
                    {
                        east: heightAt(s, 0),
                        west: heightAt(-s, 0),
                        north: heightAt(0, s),
                        south: heightAt(0, -s),
                    },
                    s,
                );
                return {
                    tool: 'point',
                    points: measured,
                    aboveGround:
                        a.ground === undefined
                            ? null
                            : aboveGround(a.height, a.ground),
                    slope,
                    aspect,
                };
            }
            default:
                return null;
        }
    }

    private update(): void {
        const points = this.hover ? [...this.fixed, this.hover] : this.fixed;
        const measurement = this.tool ? this.result(points) : null;
        this.draw(points, measurement);
        this.onChange?.(measurement);
    }

    private label(position: Cartesian3, text: string, colour = Color.WHITE) {
        this.source.entities.add({
            position,
            label: {
                text,
                font: '600 13px system-ui, sans-serif',
                fillColor: colour,
                outlineColor: Color.BLACK,
                outlineWidth: 3,
                style: LabelStyle.FILL_AND_OUTLINE,
                showBackground: true,
                backgroundColor:
                    Color.fromCssColorString('#0b0f14').withAlpha(0.75),
                horizontalOrigin: HorizontalOrigin.CENTER,
                verticalOrigin: VerticalOrigin.BOTTOM,
                pixelOffset: new Cartesian2(0, -8),
                disableDepthTestDistance: Number.POSITIVE_INFINITY,
            },
        });
    }

    private line(
        positions: Cartesian3[],
        colour: Color,
        dashed = false,
        width = 3,
    ) {
        this.source.entities.add({
            polyline: {
                // A callback keeps the line dynamic: drawn every frame rather
                // than built in a worker, which a line following the pointer
                // would never let finish.
                positions: new CallbackProperty(() => positions, false),
                width,
                material: dashed
                    ? new PolylineDashMaterialProperty({
                          color: colour,
                          dashLength: 12,
                      })
                    : colour,
                depthFailMaterial: dashed
                    ? new PolylineDashMaterialProperty({
                          color: colour.withAlpha(0.5),
                          dashLength: 12,
                      })
                    : new ColorMaterialProperty(colour.withAlpha(0.5)),
            },
        });
    }

    private draw(points: Cartesian3[], measurement: Measurement | null): void {
        const { entities } = this.source;
        entities.removeAll();
        for (const p of points)
            entities.add({
                position: p,
                point: {
                    pixelSize: 9,
                    color: COLOUR,
                    outlineColor: Color.BLACK,
                    outlineWidth: 2,
                    disableDepthTestDistance: Number.POSITIVE_INFINITY,
                },
            });
        const mid = (a: Cartesian3, b: Cartesian3) =>
            Cartesian3.midpoint(a, b, new Cartesian3());
        if (
            this.tool === 'distance' &&
            points.length === 2 &&
            measurement?.tool === 'distance'
        ) {
            const [a, b] = points as [Cartesian3, Cartesian3];
            this.line([a, b], COLOUR);
            this.label(mid(a, b), formatLength(measurement.slant));
            if (
                Math.abs(measurement.vertical) > 0.05 &&
                measurement.horizontal > 0.05
            ) {
                // The right angle: along the level at the lower point's height, then straight up.
                const [low, high] = measurement.vertical > 0 ? [a, b] : [b, a];
                const corner = this.atHeight(
                    high,
                    Cartographic.fromCartesian(low).height,
                );
                this.line([low, corner], LEG, true, 2);
                this.line([corner, high], LEG, true, 2);
                this.label(
                    mid(low, corner),
                    `↔ ${formatLength(measurement.horizontal)}`,
                    LEG,
                );
                this.label(
                    mid(corner, high),
                    `↕ ${formatLength(Math.abs(measurement.vertical))}`,
                    LEG,
                );
            }
        } else if (
            this.tool === 'height' &&
            measurement?.tool === 'height' &&
            points[0]
        ) {
            const top = points[0];
            const foot = this.atHeight(
                top,
                measurement.points[0]!.ground ?? measurement.points[0]!.height,
            );
            this.line([foot, top], COLOUR);
            this.label(top, `↕ ${formatLength(measurement.aboveGround)}`);
        } else if (this.tool === 'area' && points.length >= 2) {
            this.line([...points, points[0]!], COLOUR);
            if (measurement?.tool === 'area') {
                entities.add({
                    polygon: {
                        hierarchy: new CallbackProperty(
                            () => new PolygonHierarchy(points),
                            false,
                        ),
                        perPositionHeight: true,
                        material: COLOUR.withAlpha(0.25),
                    },
                });
                const centre = Cartesian3.multiplyByScalar(
                    points.reduce(
                        (sum, p) => Cartesian3.add(sum, p, new Cartesian3()),
                        new Cartesian3(),
                    ),
                    1 / points.length,
                    new Cartesian3(),
                );
                this.label(centre, formatArea(measurement.area));
            }
        }
    }
}
