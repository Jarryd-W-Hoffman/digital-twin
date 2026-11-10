import { Cartesian2, Cartesian3, Matrix4, type Viewer } from 'cesium';
import {
    AXES,
    centreOf,
    dragged,
    grip,
    type Grip,
    type Handle,
    type Ray,
} from '../core/gizmo.ts';
import type { Vec3 } from '../core/measure.ts';
import type { Placement } from '../core/types.ts';

const SVG = 'http://www.w3.org/2000/svg';
const COLOURS: Record<Handle, string> = {
    east: '#ef5350',
    north: '#3fb68b',
    up: '#59a5ff',
    slide: '#ffffff',
    turn: '#ffd166',
};
const TITLES: Record<Handle, string> = {
    east: 'Drag to move east or west',
    north: 'Drag to move north or south',
    up: 'Drag to raise or lower',
    slide: 'Drag to slide across the level',
    turn: 'Drag to turn',
};
/** The handles' length as a share of the camera's distance: the same size on screen. */
const SCREEN_SHARE = 0.12;
const RING_SEGMENTS = 72;
/** The ring's radius, as a share of the arrows' length. */
const RING = 0.8;
/** How far along an arrow a test grabs it. */
const ALONG = 0.7;

type Pixel = { x: number; y: number };

/**
 * Handles for moving a placed layer in the scene: an arrow each to move it
 * east, north and up, a point to slide it across the level, and a ring to
 * turn it. They're drawn over the scene, projected each frame, so a
 * building can't hide them and a drag can't move the camera or select what
 * is behind them. Every change is reported through `onChange`; the
 * placement editor keeps it, as it does typed-in values.
 */
export class Gizmo {
    onChange?: (placement: Placement) => void;
    private readonly svg: SVGSVGElement;
    private readonly shapes = new Map<Handle, SVGElement[]>();
    private placement: Placement | null = null;
    private drag: { handle: Handle; start: Placement; from: Grip } | null =
        null;
    private readonly removePostRender: () => void;

    constructor(
        private readonly viewer: Viewer,
        /** The site's east-north-up frame, at its ground. */
        private readonly frame: () => Matrix4,
    ) {
        this.svg = document.createElementNS(SVG, 'svg');
        this.svg.setAttribute('class', 'gizmo');
        this.svg.setAttribute('aria-hidden', 'true');
        this.svg.style.cssText =
            'position:absolute;inset:0;width:100%;height:100%;pointer-events:none;overflow:visible;display:none';
        this.build();
        viewer.container.appendChild(this.svg);
        this.removePostRender = viewer.scene.postRender.addEventListener(() =>
            this.draw(),
        );
    }

    get active(): boolean {
        return this.placement !== null;
    }

    /** Shows the handles on this placement, or hides them given null. */
    set(placement: Placement | null): void {
        this.placement = placement;
        this.svg.style.display = placement ? '' : 'none';
        if (!placement) this.drag = null;
        this.viewer.scene.requestRender();
    }

    /** Test hook: where on screen to grab a handle; the ring at `bearing` degrees. */
    handleAt(handle: Handle, bearing = 225): Pixel | null {
        if (!this.placement) return null;
        const size = this.size();
        const [e, n, u] = centreOf(this.placement);
        const r = (bearing * Math.PI) / 180;
        const local: Vec3 =
            handle === 'slide'
                ? [e, n, u]
                : handle === 'turn'
                  ? [
                        e + RING * size * Math.sin(r),
                        n + RING * size * Math.cos(r),
                        u,
                    ]
                  : [
                        e + AXES[handle][0] * ALONG * size,
                        n + AXES[handle][1] * ALONG * size,
                        u + AXES[handle][2] * ALONG * size,
                    ];
        return this.screen(local);
    }

    destroy(): void {
        this.removePostRender();
        this.svg.remove();
    }

    private element<K extends keyof SVGElementTagNameMap>(
        name: K,
        attributes: Record<string, string>,
    ): SVGElementTagNameMap[K] {
        const element = document.createElementNS(SVG, name);
        for (const [key, value] of Object.entries(attributes))
            element.setAttribute(key, value);
        return element;
    }

    /** One wide invisible stroke to grab, and the visible shape, per handle. */
    private build(): void {
        const defs = this.element('defs', {});
        for (const axis of ['east', 'north', 'up'] as const) {
            const marker = this.element('marker', {
                id: `gizmo-head-${axis}`,
                viewBox: '0 0 10 10',
                refX: '5',
                refY: '5',
                markerWidth: '4',
                markerHeight: '4',
                orient: 'auto-start-reverse',
            });
            marker.appendChild(
                this.element('path', {
                    d: 'M0,0 L10,5 L0,10 z',
                    fill: COLOURS[axis],
                }),
            );
            defs.appendChild(marker);
        }
        this.svg.appendChild(defs);
        for (const handle of [
            'turn',
            'east',
            'north',
            'up',
            'slide',
        ] as const) {
            const group = this.element('g', {
                'data-handle': handle,
                style: 'pointer-events:auto;cursor:grab',
            });
            const title = this.element('title', {});
            title.textContent = TITLES[handle];
            group.appendChild(title);
            let shapes: SVGElement[];
            if (handle === 'slide') {
                shapes = [
                    this.element('circle', {
                        r: '9',
                        fill: COLOURS.slide,
                        stroke: '#0b0f14',
                        'stroke-width': '2',
                    }),
                ];
            } else {
                const tag = handle === 'turn' ? 'polyline' : 'line';
                const grab = this.element(tag, {
                    stroke: 'transparent',
                    'stroke-width': '16',
                    fill: 'none',
                });
                const shown = this.element(tag, {
                    stroke: COLOURS[handle],
                    'stroke-width': handle === 'turn' ? '3' : '4',
                    'stroke-linecap': 'round',
                    fill: 'none',
                    ...(handle === 'turn'
                        ? {}
                        : { 'marker-end': `url(#gizmo-head-${handle})` }),
                });
                shapes = [grab, shown];
            }
            for (const shape of shapes) group.appendChild(shape);
            group.addEventListener('pointerdown', (e) =>
                this.press(handle, e, group),
            );
            group.addEventListener('pointermove', (e) => this.move(e));
            group.addEventListener('pointerup', (e) => this.release(e, group));
            group.addEventListener('pointercancel', (e) =>
                this.release(e, group),
            );
            this.svg.appendChild(group);
            this.shapes.set(handle, shapes);
        }
    }

    private world(local: Vec3): Cartesian3 {
        return Matrix4.multiplyByPoint(
            this.frame(),
            new Cartesian3(...local),
            new Cartesian3(),
        );
    }

    private screen(local: Vec3): Pixel | null {
        const at = this.viewer.scene.cartesianToCanvasCoordinates(
            this.world(local),
        );
        return at ? { x: at.x, y: at.y } : null;
    }

    /** How long the arrows are now, in metres. */
    private size(): number {
        if (!this.placement) return 1;
        const distance = Cartesian3.distance(
            this.viewer.camera.positionWC,
            this.world(centreOf(this.placement)),
        );
        return Math.max(1, distance * SCREEN_SHARE);
    }

    private draw(): void {
        if (!this.placement) return;
        const size = this.size();
        const [e, n, u] = centreOf(this.placement);
        const centre = this.screen([e, n, u]);
        this.svg.style.visibility = centre ? '' : 'hidden';
        if (!centre) return;
        for (const axis of ['east', 'north', 'up'] as const) {
            const [ax, ay, az] = AXES[axis];
            const tip = this.screen([
                e + ax * size,
                n + ay * size,
                u + az * size,
            ]);
            for (const line of this.shapes.get(axis)!) {
                line.setAttribute('x1', String(centre.x));
                line.setAttribute('y1', String(centre.y));
                line.setAttribute('x2', String(tip?.x ?? centre.x));
                line.setAttribute('y2', String(tip?.y ?? centre.y));
            }
        }
        const ring: string[] = [];
        for (let k = 0; k <= RING_SEGMENTS; k++) {
            const a = (2 * Math.PI * k) / RING_SEGMENTS;
            const p = this.screen([
                e + RING * size * Math.sin(a),
                n + RING * size * Math.cos(a),
                u,
            ]);
            if (p) ring.push(`${p.x},${p.y}`);
        }
        for (const line of this.shapes.get('turn')!)
            line.setAttribute('points', ring.join(' '));
        const [dot] = this.shapes.get('slide')!;
        dot!.setAttribute('cx', String(centre.x));
        dot!.setAttribute('cy', String(centre.y));
    }

    private ray(e: PointerEvent): Ray | null {
        const rect = this.viewer.scene.canvas.getBoundingClientRect();
        const ray = this.viewer.camera.getPickRay(
            new Cartesian2(e.clientX - rect.left, e.clientY - rect.top),
        );
        if (!ray) return null;
        const inverse = Matrix4.inverse(this.frame(), new Matrix4());
        const origin = Matrix4.multiplyByPoint(
            inverse,
            ray.origin,
            new Cartesian3(),
        );
        const direction = Cartesian3.normalize(
            Matrix4.multiplyByPointAsVector(
                inverse,
                ray.direction,
                new Cartesian3(),
            ),
            new Cartesian3(),
        );
        return {
            origin: [origin.x, origin.y, origin.z],
            direction: [direction.x, direction.y, direction.z],
        };
    }

    private press(handle: Handle, e: PointerEvent, target: Element): void {
        if (!this.placement || e.button !== 0) return;
        e.preventDefault();
        e.stopPropagation();
        const ray = this.ray(e);
        const from = ray && grip(handle, this.placement, ray);
        if (from === null || from === undefined) return;
        this.drag = { handle, start: this.placement, from };
        target.setPointerCapture(e.pointerId);
        (target as SVGElement).style.cursor = 'grabbing';
    }

    private move(e: PointerEvent): void {
        if (!this.drag) return;
        const { handle, start, from } = this.drag;
        const ray = this.ray(e);
        const to = ray && grip(handle, start, ray);
        if (to === null || to === undefined) return;
        const next = dragged(start, handle, from, to);
        this.placement = next;
        this.onChange?.(next);
    }

    private release(e: PointerEvent, target: Element): void {
        if (!this.drag) return;
        this.drag = null;
        if (target.hasPointerCapture(e.pointerId))
            target.releasePointerCapture(e.pointerId);
        (target as SVGElement).style.cursor = 'grab';
    }
}
