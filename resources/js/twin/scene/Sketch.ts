import {
    ArcType,
    Cartesian2,
    Cartesian3,
    Color,
    CustomDataSource,
    type Entity,
    ScreenSpaceEventHandler,
    ScreenSpaceEventType,
    type Viewer,
} from 'cesium';
import { snapPoint as snap } from '../core/geo.ts';
import type { Point } from '../core/types.ts';

const VERTEX = 'sketch:';

/**
 * A footprint or path drawn on the ground, or one position. Each click adds
 * a point (or moves the only one), and a point can be dragged; every change is reported through `onChange`, and
 * the editor that started the sketch keeps the result.
 */
export class Sketch {
    onChange?: (points: Point[]) => void;
    private readonly source = new CustomDataSource('sketch');
    private readonly handler: ScreenSpaceEventHandler;
    private points: Point[] | null = null;
    /** One point only, as for an asset's position: a click moves it. */
    private single = false;
    private dragging: number | null = null;
    /** A drag ends in a click; this keeps that click from adding a point. */
    private justDragged = false;

    constructor(
        private readonly viewer: Viewer,
        private readonly ground: (position: Cartesian2) => Point | null,
        private readonly cart: (p: Point, height?: number) => Cartesian3,
        private readonly draped: () => boolean,
    ) {
        void viewer.dataSources.add(this.source);
        this.handler = new ScreenSpaceEventHandler(viewer.scene.canvas);
        this.handler.setInputAction(
            (e: { position: Cartesian2 }) => this.press(e.position),
            ScreenSpaceEventType.LEFT_DOWN,
        );
        this.handler.setInputAction(
            (e: { endPosition: Cartesian2 }) => this.move(e.endPosition),
            ScreenSpaceEventType.MOUSE_MOVE,
        );
        this.handler.setInputAction(
            () => this.release(),
            ScreenSpaceEventType.LEFT_UP,
        );
    }

    get active(): boolean {
        return this.points !== null;
    }

    /** Shows these points, or ends the sketch given null. */
    set(points: Point[] | null, single = false): void {
        this.points = points;
        this.single = single;
        this.draw();
    }

    /** A click while sketching: adds a point, unless it ended a drag. */
    click(position: Cartesian2): void {
        if (!this.points) return;
        if (this.justDragged) {
            this.justDragged = false;
            return;
        }
        if (this.vertexAt(position) !== null) return;
        const point = this.ground(position);
        if (point)
            this.change(
                this.single ? [snap(point)] : [...this.points, snap(point)],
            );
    }

    destroy(): void {
        this.handler.destroy();
        void this.viewer.dataSources.remove(this.source, true);
    }

    private vertexAt(position: Cartesian2): number | null {
        const picked = this.viewer.scene.pick(position) as
            | { id?: Entity }
            | undefined;
        const id = picked?.id?.id;
        return typeof id === 'string' && id.startsWith(VERTEX)
            ? Number(id.slice(VERTEX.length))
            : null;
    }

    private press(position: Cartesian2): void {
        if (!this.points) return;
        this.justDragged = false;
        this.dragging = this.vertexAt(position);
        if (this.dragging !== null)
            this.viewer.scene.screenSpaceCameraController.enableInputs = false;
    }

    private move(position: Cartesian2): void {
        if (!this.points || this.dragging === null) return;
        const point = this.ground(position);
        if (!point) return;
        const next = [...this.points];
        next[this.dragging] = snap(point);
        this.justDragged = true;
        this.change(next);
    }

    private release(): void {
        if (this.dragging === null) return;
        this.dragging = null;
        this.viewer.scene.screenSpaceCameraController.enableInputs = true;
    }

    private change(points: Point[]): void {
        this.points = points;
        this.draw();
        this.onChange?.(points);
    }

    private draw(): void {
        const { entities } = this.source;
        entities.removeAll();
        const points = this.points;
        if (!points || points.length === 0) return;
        const draped = this.draped();
        const colour = Color.fromCssColorString('#ffc95c');
        if (points.length > 1 && !this.single) {
            entities.add({
                polyline: {
                    positions: [...points, points[0]].map((p) =>
                        this.cart(p, 0.6),
                    ),
                    width: 3,
                    material: colour,
                    arcType: draped ? ArcType.GEODESIC : ArcType.NONE,
                    clampToGround: draped,
                },
            });
        }
        points.forEach((p, i) =>
            entities.add({
                id: `${VERTEX}${i}`,
                position: this.cart(p, 0.6),
                point: {
                    pixelSize: 11,
                    color: colour,
                    outlineColor: Color.fromCssColorString('#1a1405'),
                    outlineWidth: 2,
                    disableDepthTestDistance: Number.POSITIVE_INFINITY,
                },
            }),
        );
    }
}
