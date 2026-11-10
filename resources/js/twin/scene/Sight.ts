import {
    ArcType,
    Camera,
    Cartesian2,
    Cartesian3,
    Cartesian4,
    Color,
    CustomDataSource,
    LabelStyle,
    Matrix3,
    Matrix4,
    PerspectiveFrustum,
    PolylineDashMaterialProperty,
    PostProcessStage,
    Ray,
    ShadowMap,
    ShadowMode,
    VerticalOrigin,
    type Viewer,
} from 'cesium';
import { formatLength, type Vec3 } from '../core/measure.ts';
import {
    frustumOf,
    judgeSight,
    lookDirection,
    type Sensor,
    type Sight as SightResult,
} from '../core/sight.ts';

const SEEN = Color.fromCssColorString('#3fb68b');
const HIDDEN = Color.fromCssColorString('#ef5350');
const OUTLINE = Color.fromCssColorString('#ffd166');
/** How much of the scene's own colour shows through the tint. */
const TINT = 0.45;
const SHADOW_MAP_SIZE = 2048;
/** Closer than this to the sensor counts as the sensor itself. */
const NEAR = 0.5;

/** A sensor placed in the site: on a surface `base` metres above the ground. */
export type PlacedSensor = Sensor & { base: number };

const FRAGMENT = /* glsl */ `
uniform sampler2D colorTexture;
uniform sampler2D depthTexture;
uniform sampler2D shadowMap_texture;
uniform samplerCube shadowMap_textureCube;
uniform mat4 shadowMap_matrix;
uniform vec4 shadowMap_lightPositionEC;
uniform vec3 sight_lookEC;
uniform vec3 sight_rightEC;
uniform vec3 sight_upEC;
// x: 0 cone, 1 rectangle, 2 dome; y: cone's cos(half angle) or tan(half width); z: tan(half height); w: range
uniform vec4 sight_shape;
uniform vec4 sight_seen;
uniform vec4 sight_hidden;
uniform float sight_bias;
// The angle one texel of the sensor's depth map spans, in radians.
uniform float sight_texel;
uniform float sight_depthTexture;
in vec2 v_textureCoordinates;

void main() {
    vec4 color = texture(colorTexture, v_textureCoordinates);
    out_FragColor = color;
    // The raw depth: Cesium's function takes it as stored, logarithmic or not.
    float depth = texture(depthTexture, v_textureCoordinates).r;
    if (depth >= 1.0) return;
    vec4 positionEC = czm_windowToEyeCoordinates(gl_FragCoord.xy, depth);
    positionEC /= positionEC.w;
    vec3 toPoint = positionEC.xyz - shadowMap_lightPositionEC.xyz;
    float distance = length(toPoint);
    float range = sight_shape.w;
    if (distance > range || distance < ${NEAR.toFixed(1)}) return;
    vec3 direction = toPoint / distance;
    // The surface's normal, from neighbouring pixels' positions, turned to face the camera.
    vec3 normalEC = normalize(cross(dFdx(positionEC.xyz), dFdy(positionEC.xyz)));
    if (dot(normalEC, positionEC.xyz) > 0.0) normalEC = -normalEC;
    float facing = dot(normalEC, -direction);
    if (sight_shape.x < 1.5) {
        float along = dot(direction, sight_lookEC);
        if (along <= 0.0) return;
        if (sight_shape.x < 0.5) {
            if (along < sight_shape.y) return;
        } else {
            if (abs(dot(direction, sight_rightEC) / along) > sight_shape.y) return;
            if (abs(dot(direction, sight_upEC) / along) > sight_shape.z) return;
        }
    }
    float visible = 0.0;
    // A surface turned away from the sensor can't be seen from it.
    if (facing > 0.0) {
        // Lifted off its surface by a few of the depth map's texels there, more
        // where the surface is seen edge-on, so it doesn't hide itself.
        float texel = distance * sight_texel;
        vec3 lifted = positionEC.xyz + normalEC * texel * (1.5 + 3.0 * (1.0 - facing));
        if (sight_shape.x > 1.5) {
            vec3 toLifted = lifted - shadowMap_lightPositionEC.xyz;
            vec3 directionWC = czm_inverseViewRotation * normalize(toLifted);
            float stored = czm_unpackDepth(czm_textureCube(shadowMap_textureCube, directionWC));
            visible = step(length(toLifted) / range - sight_bias, stored);
        } else {
            vec4 shadowPosition = shadowMap_matrix * vec4(lifted, 1.0);
            shadowPosition /= shadowPosition.w;
            if (any(lessThan(shadowPosition.xyz, vec3(0.0))) || any(greaterThan(shadowPosition.xyz, vec3(1.0)))) return;
            vec4 sampled = texture(shadowMap_texture, shadowPosition.xy);
            float stored = sight_depthTexture > 0.5 ? sampled.r : czm_unpackDepth(sampled);
            visible = step(shadowPosition.z - sight_bias, stored);
        }
    }
    vec4 tint = mix(sight_hidden, sight_seen, visible);
    out_FragColor = vec4(mix(tint.rgb, color.rgb, ${TINT.toFixed(2)}), color.a);
}
`;

/** Cesium's shadow map fields the pass reads; they aren't in its typings. */
interface ShadowMapInternals {
    _shadowMapTexture?: unknown;
    _shadowMapMatrix: Matrix4;
    _lightPositionEC: Cartesian4;
    _usesDepthTexture: boolean;
    _isPointLight: boolean;
    destroy(): void;
}

/**
 * What a sensor can see, and lines of sight. The viewshed is drawn by
 * rendering the scene's depth from the sensor, as a shadow map, then
 * tinting each pixel in its shape and range green where nothing nearer to
 * the sensor is in the way, and red where something is. A dome uses a cube
 * of six such maps. Lines of sight are cast against the scene and the
 * ground.
 */
export class Sight {
    /** A click on a surface while picking, in metres east, north and up of the origin. */
    onPick?: (point: Vec3) => void;
    private picking = false;
    private readonly source = new CustomDataSource('sight');
    private readonly lightCamera: Camera;
    private shadowMap: (ShadowMap & ShadowMapInternals) | null = null;
    private shadowKey = '';
    private stage: PostProcessStage | null = null;
    private sensor: PlacedSensor | null = null;
    private globeShadows: ShadowMode | null = null;
    private depthTest: boolean | null = null;
    private readonly basis = {
        look: new Cartesian3(),
        right: new Cartesian3(),
        up: new Cartesian3(),
    };
    /** Pushes the shadow map into each frame: Cesium gathers them from its primitives' updates. */
    private readonly hook = {
        update: (frameState: { shadowMaps: unknown[] }) => {
            if (this.shadowMap) frameState.shadowMaps.push(this.shadowMap);
        },
        isDestroyed: () => false,
        destroy: () => undefined,
    };

    constructor(
        private readonly viewer: Viewer,
        /**
         * The site's own conversions between metres east, north and up of
         * its origin and the globe, as everything else in it is placed, and
         * its east-north-up frame for directions.
         */
        private readonly site: {
            toWorld(local: Vec3): Cartesian3;
            toLocal(world: Cartesian3): Vec3;
            frame(): Matrix4;
        },
    ) {
        void viewer.dataSources.add(this.source);
        this.lightCamera = new Camera(viewer.scene);
        viewer.scene.primitives.add(this.hook);
    }

    get active(): boolean {
        return this.picking;
    }

    /** While on, a click reports the surface point through `onPick`. */
    setPicking(on: boolean): void {
        this.picking = on;
    }

    click(position: Cartesian2): void {
        if (!this.picking) return;
        const hit = this.surfaceAt(position);
        if (hit) this.onPick?.(this.local(hit));
    }

    /** Test hook: clicks the surface under a point given in the site's frame. */
    clickLocal(point: Vec3): void {
        if (this.picking) this.onPick?.(point);
    }

    /** Shows what this sensor sees, or nothing given null. */
    showViewshed(sensor: PlacedSensor | null): void {
        this.sensor = sensor;
        const { scene } = this.viewer;
        if (!sensor) {
            this.removeShadowMap();
            if (this.stage) {
                scene.postProcessStages.remove(this.stage);
                this.stage = null;
            }
            if (this.globeShadows !== null) {
                scene.globe.shadows = this.globeShadows;
                this.globeShadows = null;
            }
            if (this.depthTest !== null) {
                scene.globe.depthTestAgainstTerrain = this.depthTest;
                this.depthTest = null;
            }
            this.drawSensor(null);
            return;
        }
        // The ground hides what's behind a hill.
        if (this.globeShadows === null) {
            this.globeShadows = scene.globe.shadows;
            scene.globe.shadows = ShadowMode.ENABLED;
        }
        // Without it Cesium clears the depth after drawing the ground, and the
        // ground's depth can't be turned back into positions.
        if (this.depthTest === null) {
            this.depthTest = scene.globe.depthTestAgainstTerrain;
            scene.globe.depthTestAgainstTerrain = true;
        }
        this.aimCamera(sensor);
        const key = `${sensor.shape === 'dome'}:${sensor.shape === 'dome' ? sensor.range : ''}`;
        if (!this.shadowMap || key !== this.shadowKey) {
            this.removeShadowMap();
            const ShadowMapClass = ShadowMap as unknown as new (
                options: Record<string, unknown>,
            ) => ShadowMap & ShadowMapInternals;
            this.shadowMap = new ShadowMapClass({
                context: (scene as unknown as { context: unknown }).context,
                lightCamera: this.lightCamera,
                isPointLight: sensor.shape === 'dome',
                pointLightRadius: sensor.range,
                cascadesEnabled: false,
                fromLightSource: false,
                softShadows: false,
                normalOffset: false,
                size: SHADOW_MAP_SIZE,
            });
            this.shadowKey = key;
        }
        if (!this.stage) {
            this.stage = scene.postProcessStages.add(
                new PostProcessStage({
                    fragmentShader: FRAGMENT,
                    uniforms: this.uniforms(),
                }),
            ) as PostProcessStage;
        }
        this.drawSensor(sensor);
        scene.requestRender();
    }

    /**
     * Whether `to` can be seen from `from`, both in the site's frame: the
     * first thing a ray between them meets, scene or ground, decides it.
     */
    lineOfSight(from: Vec3, to: Vec3): SightResult {
        const a = this.world(from);
        const b = this.world(to);
        const distance = Cartesian3.distance(a, b);
        const direction = Cartesian3.normalize(
            Cartesian3.subtract(b, a, new Cartesian3()),
            new Cartesian3(),
        );
        const ray = new Ray(a, direction);
        const { scene } = this.viewer;
        let first: number | null = null;
        const consider = (hit: Cartesian3 | undefined) => {
            if (!hit) return;
            const d = Cartesian3.distance(a, hit);
            // Behind the target doesn't count.
            if (d <= distance + 0.01 && (first === null || d < first))
                first = d;
        };
        const exclude = [...this.source.entities.values];
        // Public in Cesium, though left out of its typings.
        const pickFromRay = (from: Ray) =>
            (
                (
                    scene as unknown as {
                        pickFromRay(ray: Ray, exclude: unknown[]): unknown;
                    }
                ).pickFromRay(from, exclude) as
                    | { position?: Cartesian3 }
                    | undefined
            )?.position;
        let hit = pickFromRay(ray);
        // The hit is read back from a depth buffer as long as the scene;
        // a second, shorter look from just before it is far more exact.
        if (hit) {
            const before = Math.max(0, Cartesian3.distance(a, hit) - 2);
            const closer = new Ray(
                Ray.getPoint(ray, before, new Cartesian3()),
                direction,
            );
            hit = pickFromRay(closer) ?? hit;
        }
        consider(hit);
        consider(scene.globe.pick(ray, scene));
        return judgeSight(distance, first);
    }

    /** Draws a line of sight, green where it's seen and red past what blocks it. */
    showLine(
        from: Vec3 | null,
        to: Vec3 | null,
        sight: SightResult | null,
    ): void {
        this.drawSensor(this.sensor);
        const { entities } = this.source;
        if (from) this.marker(this.world(from), 'observer');
        if (!from || !to) return;
        const a = this.world(from);
        const b = this.world(to);
        this.marker(b, 'target');
        const line = (positions: Cartesian3[], color: Color) =>
            entities.add({
                polyline: {
                    positions,
                    width: 4,
                    arcType: ArcType.NONE,
                    material: color,
                    depthFailMaterial: new PolylineDashMaterialProperty({
                        color,
                        dashLength: 10,
                    }),
                },
            });
        if (!sight || sight.clear) {
            line([a, b], SEEN);
            this.label(
                Cartesian3.midpoint(a, b, new Cartesian3()),
                `Seen · ${formatLength(sight?.distance ?? Cartesian3.distance(a, b))}`,
                SEEN,
            );
            return;
        }
        const t = sight.blockedAt / sight.distance;
        const block = Cartesian3.lerp(a, b, t, new Cartesian3());
        line([a, block], SEEN);
        line([block, b], HIDDEN);
        this.marker(block, 'block');
        this.label(
            block,
            `Blocked at ${formatLength(sight.blockedAt)}`,
            HIDDEN,
        );
    }

    /** Test hook: the colour drawn at a canvas pixel, read after the next frame. */
    pixelAt(x: number, y: number): Promise<[number, number, number]> {
        const { scene } = this.viewer;
        return new Promise((resolve) => {
            const remove = scene.postRender.addEventListener(() => {
                remove();
                const gl = (
                    scene as unknown as {
                        context: { _gl: WebGL2RenderingContext };
                    }
                ).context._gl;
                const ratio = scene.canvas.height / scene.canvas.clientHeight;
                const out = new Uint8Array(4);
                gl.readPixels(
                    Math.round(x * ratio),
                    Math.round(scene.canvas.height - y * ratio),
                    1,
                    1,
                    gl.RGBA,
                    gl.UNSIGNED_BYTE,
                    out,
                );
                resolve([out[0]!, out[1]!, out[2]!]);
            });
            scene.requestRender();
        });
    }

    /** Test hook: where a point in the site's frame is on the canvas. */
    screenAt(point: Vec3): { x: number; y: number } | null {
        const at = this.viewer.scene.cartesianToCanvasCoordinates(
            this.world(point),
        );
        return at ? { x: at.x, y: at.y } : null;
    }

    destroy(): void {
        this.showViewshed(null);
        this.viewer.scene.primitives.remove(this.hook);
        void this.viewer.dataSources.remove(this.source, true);
    }

    private removeShadowMap(): void {
        this.shadowMap?.destroy();
        this.shadowMap = null;
        this.shadowKey = '';
    }

    private uniforms(): Record<string, () => unknown> {
        const viewMatrix = () => this.viewer.camera.viewMatrix;
        const toEC = (v: Cartesian3) =>
            Cartesian3.normalize(
                Matrix4.multiplyByPointAsVector(
                    viewMatrix(),
                    v,
                    new Cartesian3(),
                ),
                new Cartesian3(),
            );
        return {
            shadowMap_texture: () => this.texture(false),
            shadowMap_textureCube: () => this.texture(true),
            shadowMap_matrix: () =>
                this.shadowMap?._shadowMapMatrix ?? Matrix4.IDENTITY,
            shadowMap_lightPositionEC: () =>
                this.shadowMap?._lightPositionEC ?? Cartesian4.ZERO,
            sight_lookEC: () => toEC(this.basis.look),
            sight_rightEC: () => toEC(this.basis.right),
            sight_upEC: () => toEC(this.basis.up),
            sight_shape: () => {
                const s = this.sensor;
                if (!s) return new Cartesian4(0, 2, 0, 0);
                const half = (d: number) => (d * Math.PI) / 360;
                return s.shape === 'dome'
                    ? new Cartesian4(2, 0, 0, s.range)
                    : s.shape === 'cone'
                      ? new Cartesian4(0, Math.cos(half(s.coneDeg)), 0, s.range)
                      : new Cartesian4(
                            1,
                            Math.tan(half(s.widthDeg)),
                            Math.tan(half(s.heightDeg)),
                            s.range,
                        );
            },
            sight_seen: () => SEEN,
            sight_hidden: () => HIDDEN,
            sight_bias: () =>
                this.sensor?.shape === 'dome' ? 0.0005 : 0.00002,
            sight_texel: () => {
                const s = this.sensor;
                if (!s) return 0;
                const across =
                    s.shape === 'dome' ? Math.PI / 2 : frustumOf(s).fov;
                return across / SHADOW_MAP_SIZE;
            },
            sight_depthTexture: () =>
                this.shadowMap?._usesDepthTexture ? 1 : 0,
        };
    }

    /** The shadow map's texture, or a stand-in until it has one. */
    private texture(cube: boolean): unknown {
        const map = this.shadowMap;
        const context = (
            this.viewer.scene as unknown as {
                context: { defaultTexture: unknown; defaultCubeMap: unknown };
            }
        ).context;
        if (!map || map._isPointLight !== cube || !map._shadowMapTexture)
            return cube ? context.defaultCubeMap : context.defaultTexture;
        return map._shadowMapTexture;
    }

    private aimCamera(sensor: PlacedSensor): void {
        const frame = this.site.frame();
        const rotation = Matrix4.getMatrix3(frame, new Matrix3());
        const toWorld = (v: Vec3) =>
            Cartesian3.normalize(
                Matrix3.multiplyByVector(
                    rotation,
                    new Cartesian3(...v),
                    new Cartesian3(),
                ),
                new Cartesian3(),
            );
        const { look, right, up } = this.basis;
        Cartesian3.clone(
            toWorld(lookDirection(sensor.headingDeg, sensor.tiltDeg)),
            look,
        );
        Cartesian3.clone(
            toWorld(lookDirection(sensor.headingDeg + 90, 0)),
            right,
        );
        Cartesian3.normalize(Cartesian3.cross(right, look, up), up);
        const { fov, aspectRatio } = frustumOf(sensor);
        this.lightCamera.frustum = new PerspectiveFrustum({
            fov: sensor.shape === 'dome' ? Math.PI / 2 : fov,
            aspectRatio,
            near: NEAR,
            far: sensor.range,
        });
        this.lightCamera.setView({
            destination: this.world(this.eye(sensor)),
            orientation: { direction: look, up },
        });
    }

    private eye(sensor: PlacedSensor): Vec3 {
        return [sensor.at[0], sensor.at[1], sensor.base + sensor.height];
    }

    private world(local: Vec3): Cartesian3 {
        return this.site.toWorld(local);
    }

    private local(world: Cartesian3): Vec3 {
        return this.site.toLocal(world);
    }

    private surfaceAt(position: Cartesian2): Cartesian3 | null {
        const { scene, camera } = this.viewer;
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

    private marker(
        position: Cartesian3,
        kind: 'observer' | 'target' | 'block',
    ): void {
        this.source.entities.add({
            position,
            point: {
                pixelSize: kind === 'block' ? 8 : 11,
                color:
                    kind === 'block'
                        ? HIDDEN
                        : kind === 'observer'
                          ? OUTLINE
                          : Color.WHITE,
                outlineColor: Color.BLACK,
                outlineWidth: 2,
                disableDepthTestDistance: Number.POSITIVE_INFINITY,
            },
        });
    }

    private label(position: Cartesian3, text: string, colour: Color): void {
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
                verticalOrigin: VerticalOrigin.BOTTOM,
                pixelOffset: new Cartesian2(0, -10),
                disableDepthTestDistance: Number.POSITIVE_INFINITY,
            },
        });
    }

    /** The sensor's mast and the edges of its shape, at its range. */
    private drawSensor(sensor: PlacedSensor | null): void {
        const { entities } = this.source;
        entities.removeAll();
        if (!sensor) return;
        const eye = this.eye(sensor);
        const foot: Vec3 = [eye[0], eye[1], sensor.base];
        const edge = (positions: Cartesian3[], width = 2) =>
            entities.add({
                polyline: {
                    positions,
                    width,
                    arcType: ArcType.NONE,
                    material: OUTLINE.withAlpha(0.9),
                },
            });
        edge([this.world(foot), this.world(eye)], 3);
        this.marker(this.world(eye), 'observer');
        const along = (d: Vec3, r: number): Vec3 => [
            eye[0] + d[0] * r,
            eye[1] + d[1] * r,
            eye[2] + d[2] * r,
        ];
        const ring = (directions: Vec3[]) => {
            const ends = directions.map((d) =>
                this.world(along(d, sensor.range)),
            );
            edge([...ends, ends[0]!]);
            return ends;
        };
        if (sensor.shape === 'dome') {
            ring(
                Array.from({ length: 73 }, (_, k): Vec3 =>
                    lookDirection(k * 5, 0),
                ),
            );
            return;
        }
        const look = lookDirection(sensor.headingDeg, sensor.tiltDeg);
        const right = lookDirection(sensor.headingDeg + 90, 0);
        const up: Vec3 = [
            right[1] * look[2] - right[2] * look[1],
            right[2] * look[0] - right[0] * look[2],
            right[0] * look[1] - right[1] * look[0],
        ];
        const towards = (x: number, y: number): Vec3 => {
            const v: Vec3 = [
                look[0] + x * right[0] + y * up[0],
                look[1] + x * right[1] + y * up[1],
                look[2] + x * right[2] + y * up[2],
            ];
            const n = Math.hypot(...v);
            return [v[0] / n, v[1] / n, v[2] / n];
        };
        let corners: Vec3[];
        if (sensor.shape === 'cone') {
            const t = Math.tan((sensor.coneDeg * Math.PI) / 360);
            const circle = Array.from({ length: 49 }, (_, k) => {
                const a = (k / 48) * 2 * Math.PI;
                return towards(t * Math.cos(a), t * Math.sin(a));
            });
            ring(circle);
            corners = [0, 12, 24, 36].map((k) => circle[k]!);
        } else {
            const x = Math.tan((sensor.widthDeg * Math.PI) / 360);
            const y = Math.tan((sensor.heightDeg * Math.PI) / 360);
            corners = [
                towards(-x, -y),
                towards(x, -y),
                towards(x, y),
                towards(-x, y),
            ];
            ring(corners);
        }
        for (const c of corners)
            edge([this.world(eye), this.world(along(c, sensor.range))], 1);
    }
}
