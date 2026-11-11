import {
    ArcType,
    BoundingSphere,
    CallbackProperty,
    Cartesian2,
    Cartesian3,
    Color,
    ColorGeometryInstanceAttribute,
    ComponentDatatype,
    CustomDataSource,
    Geometry,
    GeometryAttribute,
    GeometryAttributes,
    GeometryInstance,
    LabelStyle,
    Matrix3,
    Matrix4,
    PerInstanceColorAppearance,
    PolylineArrowMaterialProperty,
    Primitive,
    PrimitiveType,
    Transforms,
    VerticalOrigin,
    type Viewer,
} from 'cesium';
import type { Vec3 } from '../core/measure.ts';
import {
    type CompiledShape,
    fanMesh,
    type Pose,
    vectorTip,
} from '../core/shapes.ts';
import type { Values } from '../core/types.ts';

/** How see-through a fan's surface is. */
const FAN_ALPHA = 0.28;

interface Drawn {
    shape: CompiledShape;
    pose: Pose | null;
    /** A fan's mesh in its own frame, and its surface and outline as drawn. */
    fan?: ReturnType<typeof fanMesh>;
    fill?: Primitive;
    edges?: Primitive;
    /** The pose and place the fan was last built for. */
    builtFor?: string;
    /** Where it stands, and that point's east-north-up frame for turning a fan. */
    start: Cartesian3;
    frame: Matrix4;
}

function mesh(
    positions: number[],
    indices: number[],
    type: PrimitiveType,
): Geometry {
    const values = new Float64Array(positions);
    return new Geometry({
        attributes: {
            position: new GeometryAttribute({
                componentDatatype: ComponentDatatype.DOUBLE,
                componentsPerAttribute: 3,
                values,
            }),
        } as unknown as GeometryAttributes,
        indices:
            positions.length / 3 > 65535
                ? new Uint32Array(indices)
                : new Uint16Array(indices),
        primitiveType: type,
        boundingSphere: BoundingSphere.fromVertices(positions),
    });
}

/**
 * The definition's shapes in the scene: vectors as arrows and fans as
 * see-through sweeps with an outline. Each follows its readings as frames
 * arrive; a shape missing a reading isn't drawn.
 */
export class Shapes {
    private readonly source = new CustomDataSource('shapes');
    private readonly drawn = new Map<string, Drawn>();

    constructor(
        private readonly viewer: Viewer,
        /** The site's conversion from metres east, north and up of its origin. */
        private readonly toWorld: (local: Vec3) => Cartesian3,
        shapes: CompiledShape[],
    ) {
        void viewer.dataSources.add(this.source);
        for (const shape of shapes) this.add(shape);
    }

    update(values: Values): void {
        for (const d of this.drawn.values()) {
            // The ground can rise once terrain loads.
            d.start = this.toWorld(d.shape.origin);
            d.frame = Transforms.eastNorthUpToFixedFrame(d.start);
            d.pose = d.shape.pose(values);
            if (d.fan) this.drawFan(d);
        }
        this.viewer.scene.requestRender();
    }

    /** Test hook: a shape's heading, tilt and length now, or null if it isn't drawn. */
    poseOf(id: string): Pose | null {
        return this.drawn.get(id)?.pose ?? null;
    }

    /** Test hook: where a shape's tip (or a fan's middle, out at its radius) is on screen. */
    tipOnScreen(id: string): { x: number; y: number } | null {
        const d = this.drawn.get(id);
        if (!d?.pose) return null;
        const at = this.viewer.scene.cartesianToCanvasCoordinates(
            this.toWorld(vectorTip(d.shape.origin, d.pose)),
        );
        return at ? { x: at.x, y: at.y } : null;
    }

    destroy(): void {
        for (const d of this.drawn.values()) {
            if (d.fill) this.viewer.scene.primitives.remove(d.fill);
            if (d.edges) this.viewer.scene.primitives.remove(d.edges);
        }
        this.drawn.clear();
        void this.viewer.dataSources.remove(this.source, true);
    }

    /**
     * Builds a fan where it stands, turned to its pose. Cesium needs a
     * custom mesh in world coordinates (it's projected for 2D too), so it's
     * rebuilt only when the fan turns or moves.
     */
    private drawFan(d: Drawn): void {
        const key = d.pose
            ? [
                  d.pose.headingDeg,
                  d.pose.tiltDeg,
                  d.start.x,
                  d.start.y,
                  d.start.z,
              ]
                  .map((v) => v.toFixed(1))
                  .join()
            : '';
        if (key === d.builtFor) return;
        d.builtFor = key;
        const { primitives } = this.viewer.scene;
        if (d.fill) primitives.remove(d.fill);
        if (d.edges) primitives.remove(d.edges);
        d.fill = d.edges = undefined;
        if (!d.pose || !d.fan) return;
        const placed = Matrix4.multiply(d.frame, turn(d.pose), new Matrix4());
        const local = d.fan.positions;
        const positions: number[] = [];
        const point = new Cartesian3();
        for (let i = 0; i < local.length; i += 3) {
            Matrix4.multiplyByPoint(
                placed,
                Cartesian3.fromElements(
                    local[i]!,
                    local[i + 1]!,
                    local[i + 2]!,
                    point,
                ),
                point,
            );
            positions.push(point.x, point.y, point.z);
        }
        const colour = Color.fromCssColorString(d.shape.colour);
        const primitive = (
            type: PrimitiveType,
            list: number[],
            alpha: number,
        ) =>
            primitives.add(
                new Primitive({
                    geometryInstances: new GeometryInstance({
                        geometry: mesh(positions, list, type),
                        attributes: {
                            color: ColorGeometryInstanceAttribute.fromColor(
                                colour.withAlpha(alpha),
                            ),
                        },
                    }),
                    // See-through, and not in the way: it writes no depth,
                    // so measuring and picking reach what's behind it.
                    appearance: new PerInstanceColorAppearance({
                        flat: true,
                        translucent: true,
                        closed: false,
                        renderState: {
                            depthTest: { enabled: true },
                            depthMask: false,
                        },
                    }),
                    allowPicking: false,
                    asynchronous: false,
                }),
            ) as Primitive;
        d.fill = primitive(PrimitiveType.TRIANGLES, d.fan.indices, FAN_ALPHA);
        d.edges = primitive(PrimitiveType.LINES, d.fan.edges, 0.95);
    }

    private add(shape: CompiledShape): void {
        const { def, origin } = shape;
        const start = this.toWorld(origin);
        const drawn: Drawn = {
            shape,
            pose: null,
            start,
            frame: Transforms.eastNorthUpToFixedFrame(start),
        };
        this.drawn.set(def.id, drawn);
        const colour = Color.fromCssColorString(shape.colour);
        const label = (position: CallbackProperty | Cartesian3) =>
            def.name
                ? {
                      position,
                      label: {
                          text: def.name,
                          font: '600 12px system-ui, sans-serif',
                          fillColor: colour,
                          outlineColor: Color.BLACK,
                          outlineWidth: 3,
                          style: LabelStyle.FILL_AND_OUTLINE,
                          verticalOrigin: VerticalOrigin.BOTTOM,
                          pixelOffset: new Cartesian2(0, -8),
                          disableDepthTestDistance: Number.POSITIVE_INFINITY,
                          show: new CallbackProperty(
                              () => drawn.pose !== null,
                              false,
                          ),
                      },
                  }
                : null;

        if (def.kind === 'vector') {
            const tip = () =>
                drawn.pose
                    ? this.toWorld(vectorTip(origin, drawn.pose))
                    : drawn.start;
            this.source.entities.add({
                polyline: {
                    positions: new CallbackProperty(
                        () => [drawn.start, tip()],
                        false,
                    ),
                    width: 14,
                    arcType: ArcType.NONE,
                    material: new PolylineArrowMaterialProperty(colour),
                    show: new CallbackProperty(
                        () => (drawn.pose?.length ?? 0) > 0,
                        false,
                    ),
                },
            });
            const named = label(
                new CallbackProperty(tip, false) as unknown as CallbackProperty,
            );
            if (named) this.source.entities.add(named as never);
            return;
        }

        drawn.fan = fanMesh(def.radius ?? 0, def.across ?? 60, def.up ?? 0);
        const named = label(new CallbackProperty(() => drawn.start, false));
        if (named) this.source.entities.add(named as never);
    }
}

/** Turns a shape drawn pointing north and level to its heading and tilt. */
function turn(pose: Pose): Matrix4 {
    const heading = Matrix3.fromRotationZ((-pose.headingDeg * Math.PI) / 180);
    const tilt = Matrix3.fromRotationX((pose.tiltDeg * Math.PI) / 180);
    return Matrix4.fromRotation(Matrix3.multiply(heading, tilt, new Matrix3()));
}
