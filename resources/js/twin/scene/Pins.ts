import {
    Cartesian2,
    Cartesian3,
    Color,
    CustomDataSource,
    type Entity,
    LabelStyle,
    Math as CesiumMath,
    VerticalOrigin,
    type Viewer,
} from 'cesium';
import type { Vec3 } from '../core/measure.ts';
import type { CameraView, Pin } from '../state/store.ts';

const OPEN = Color.fromCssColorString('#ff8a3d');
const CLOSED = Color.fromCssColorString('#7d8a99');
const DRAFT = Color.fromCssColorString('#ffd166');
const PREFIX = 'pin:';

/**
 * Issues pinned in the scene: a dot on the spot with its number, the
 * chosen one larger and titled. Pins are drawn over everything, so one
 * inside a building still shows.
 */
export class Pins {
    private readonly source = new CustomDataSource('pins');
    private pins: Pin[] = [];
    private active: string | null = null;
    private draft: Vec3 | null = null;

    constructor(private readonly viewer: Viewer) {
        void viewer.dataSources.add(this.source);
    }

    /** Shows the pins, the chosen one larger, and a new one being placed. */
    set(pins: Pin[], active: string | null, draft: Vec3 | null = null): void {
        if (
            pins === this.pins &&
            active === this.active &&
            draft === this.draft
        )
            return;
        this.pins = pins;
        this.active = active;
        this.draft = draft;
        // Cleared before suspending: a pin removed and re-added under the same
        // id while suspended cancels out, and Cesium keeps drawing the old one.
        this.source.entities.removeAll();
        this.source.entities.suspendEvents();
        if (draft)
            this.source.entities.add({
                position: Cartesian3.fromDegrees(...draft),
                point: {
                    pixelSize: 16,
                    color: DRAFT,
                    outlineColor: Color.WHITE,
                    outlineWidth: 3,
                    disableDepthTestDistance: Number.POSITIVE_INFINITY,
                },
                label: {
                    text: 'New issue',
                    font: '600 12px "IBM Plex Sans", sans-serif',
                    fillColor: Color.WHITE,
                    outlineColor: Color.fromCssColorString('#0b0f14'),
                    outlineWidth: 3,
                    style: LabelStyle.FILL_AND_OUTLINE,
                    verticalOrigin: VerticalOrigin.BOTTOM,
                    pixelOffset: new Cartesian2(0, -13),
                    disableDepthTestDistance: Number.POSITIVE_INFINITY,
                },
            });
        for (const pin of pins) {
            const chosen = pin.id === active;
            const color = pin.closed ? CLOSED : OPEN;
            this.source.entities.add({
                id: PREFIX + pin.id,
                position: Cartesian3.fromDegrees(...pin.at),
                point: {
                    pixelSize: chosen ? 18 : 13,
                    color,
                    outlineColor: Color.WHITE,
                    outlineWidth: chosen ? 3 : 2,
                    disableDepthTestDistance: Number.POSITIVE_INFINITY,
                },
                label: {
                    text: chosen
                        ? `#${pin.number} ${pin.title}`
                        : `#${pin.number}`,
                    font: '600 12px "IBM Plex Sans", sans-serif',
                    fillColor: Color.WHITE,
                    outlineColor: Color.fromCssColorString('#0b0f14'),
                    outlineWidth: 3,
                    style: LabelStyle.FILL_AND_OUTLINE,
                    verticalOrigin: VerticalOrigin.BOTTOM,
                    pixelOffset: new Cartesian2(0, chosen ? -14 : -11),
                    disableDepthTestDistance: Number.POSITIVE_INFINITY,
                },
            });
        }
        this.source.entities.resumeEvents();
    }

    /** The issue a picked object is the pin of, if it is one. */
    idOf(picked: { id?: Entity | string } | undefined): string | null {
        const id = picked?.id;
        const name = typeof id === 'string' ? id : id?.id;
        return name?.startsWith(PREFIX) ? name.slice(PREFIX.length) : null;
    }

    /** Where the camera is and which way it looks. */
    cameraView(): CameraView {
        const { camera } = this.viewer;
        const at = camera.positionCartographic;
        return {
            lon: CesiumMath.toDegrees(at.longitude),
            lat: CesiumMath.toDegrees(at.latitude),
            height: at.height,
            heading: CesiumMath.toDegrees(camera.heading),
            pitch: CesiumMath.toDegrees(camera.pitch),
            roll: CesiumMath.toDegrees(camera.roll),
        };
    }

    /** Flies the camera back to a saved view. */
    flyTo(view: CameraView, duration = 1.2): void {
        this.viewer.camera.flyTo({
            destination: Cartesian3.fromDegrees(
                view.lon,
                view.lat,
                view.height,
            ),
            orientation: {
                heading: CesiumMath.toRadians(view.heading),
                pitch: CesiumMath.toRadians(view.pitch),
                roll: CesiumMath.toRadians(view.roll),
            },
            duration,
        });
    }

    /** Screen position of a pin, for tests that click the real canvas. */
    project(id: string): { x: number; y: number } | null {
        const pin = this.pins.find((p) => p.id === id);
        if (!pin) return null;
        const p = this.viewer.scene.cartesianToCanvasCoordinates(
            Cartesian3.fromDegrees(...pin.at),
        );
        const rect = this.viewer.scene.canvas.getBoundingClientRect();
        return p ? { x: rect.left + p.x, y: rect.top + p.y } : null;
    }

    destroy(): void {
        void this.viewer.dataSources.remove(this.source, true);
    }
}
