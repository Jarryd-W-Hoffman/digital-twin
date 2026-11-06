import {
    ArcType,
    buildModuleUrl,
    CallbackPositionProperty,
    CallbackProperty,
    Cartesian2,
    Cartesian3,
    Cesium3DTileset,
    Color,
    Credit,
    ColorGeometryInstanceAttribute,
    ColorMaterialProperty,
    ConstantProperty,
    CustomDataSource,
    DirectionalLight,
    DistanceDisplayCondition,
    GeometryInstance,
    Entity,
    HeadingPitchRange,
    HorizontalOrigin,
    ImageryLayer,
    JulianDate,
    LabelStyle,
    Material,
    Math as CesiumMath,
    NearFarScalar,
    OpenStreetMapImageryProvider,
    PerInstanceColorAppearance,
    PointPrimitiveCollection,
    PolygonGeometry,
    PolygonHierarchy,
    PolygonOutlineGeometry,
    Polyline,
    PolylineCollection,
    PolylineGlowMaterialProperty,
    Primitive,
    Quaternion,
    ScreenSpaceEventHandler,
    ScreenSpaceEventType,
    ShadowMode,
    SunLight,
    TileMapServiceImageryProvider,
    Transforms,
    Cartographic,
    Matrix4,
    Model,
    HeadingPitchRoll,
    UrlTemplateImageryProvider,
    VerticalOrigin,
    Viewer,
    type PointPrimitive,
} from 'cesium';
import { hourOfDay } from '../core/engine.ts';
import { centroid, lonLatToMetres, metresToLonLat } from '../core/geo.ts';
import {
    formatMetric,
    metricDefFor,
    primaryMetricFor,
    typeOf,
} from '../core/metrics.ts';
import type {
    Alert,
    AssetDef,
    Frame,
    Placement,
    Point,
    TwinDefinition,
} from '../core/types.ts';
import { State } from '../core/types.ts';
import type { Basemap, ColorBy, Layers } from '../state/store.ts';
import {
    BODY_COLOR,
    INERT_COLOR,
    powerColor,
    SEVERITY_COLOR,
    temperatureColor,
} from './colors.ts';

const LABELLED = new Set<AssetDef['kind']>([
    'custom',
    'coldstore',
    'chiller',
    'battery',
    'genset',
    'solar',
    'grid',
    'building',
    'bus',
    'charger',
]);

/** Roughly the largest draw for colouring by power, in kW. */
const POWER_SCALE: Partial<Record<AssetDef['kind'], number>> = {
    chiller: 90,
    building: 100,
    charger: 330,
    bus: 700,
    solar: 400,
    battery: 420,
    genset: 450,
    grid: 900,
};

export const CUSTOM_BASEMAP_URL: string | undefined = import.meta.env
    .VITE_BASEMAP_URL;

const PULSES_PER_FLOW = 4;
const GLOBE_ALTITUDE_M = 16_000_000;
export const OPENING_FLIGHT_S = 3.5;
const LERP_MS = 900;

interface Flow {
    key: string;
    type: 'power' | 'cooling';
    /** Metric giving the flow's kW; negative runs backward. */
    metric: string;
    path: Cartesian3[];
    line: Polyline;
    pulses: PointPrimitive[];
    kw: number;
    phase: number;
}

interface Vehicle {
    from: Cartesian3;
    to: Cartesian3;
    heading: number;
    startedAt: number;
    entity: Entity;
}

interface AssetVisual {
    def: AssetDef;
    /** Instance ids in the bodies primitive. */
    bodies: string[];
    color?: string;
    label?: Entity;
    labelText?: string;
    anchor: Cartesian3;
    top: number;
}

/**
 * Everything Cesium. Kept imperative and outside React: Cesium owns its render
 * loop and thousands of updates a second must not become component renders.
 */
export class TwinScene {
    readonly viewer: Viewer;
    private readonly def: TwinDefinition;
    private readonly origin: { lon: number; lat: number };
    private readonly data = new CustomDataSource('twin');
    private readonly zones = new CustomDataSource('zones');
    private readonly labels = new CustomDataSource('labels');
    private readonly flowLines: PolylineCollection;
    private readonly pulses: PointPrimitiveCollection;
    private bodies?: Primitive;
    private readonly pendingBodies: {
        id: string;
        footprint: Point[];
        base: number;
        top: number;
    }[] = [];
    private readonly visuals = new Map<string, AssetVisual>();
    private readonly vehicles = new Map<string, Vehicle>();
    private readonly flows: Flow[] = [];
    private readonly beacons = new Map<string, Entity[]>();
    private readonly handler: ScreenSpaceEventHandler;
    private readonly sunLight = new SunLight({ intensity: 1.35 });
    private readonly moonLight = new DirectionalLight({
        direction: Cartesian3.normalize(
            new Cartesian3(0.3, 0.4, -1),
            new Cartesian3(),
        ),
        color: Color.fromCssColorString('#9fb4d6'),
        intensity: 1.4,
    });
    private selection?: Entity;
    /** Uploaded and bundled context: tilesets and placed models, by layer id. */
    private readonly layerPrimitives = new Map<
        string,
        Cesium3DTileset | Model
    >();
    private contextShown = true;
    private basemapLayer?: ImageryLayer;
    private basemapName?: Basemap;
    private removePreRender?: () => void;
    private lastFrame?: Frame;
    private colorBy: ColorBy = 'status';
    private lastPulseAt = performance.now();
    onPick?: (assetId: string | null) => void;
    /** While set, a click reports the ground point instead of selecting. */
    onGroundPick?: (point: Point) => void;

    constructor(container: HTMLElement, def: TwinDefinition) {
        this.def = def;
        this.origin = def.origin;
        this.viewer = new Viewer(container, {
            animation: false,
            timeline: false,
            geocoder: false,
            homeButton: false,
            sceneModePicker: false,
            baseLayerPicker: false,
            navigationHelpButton: false,
            infoBox: false,
            selectionIndicator: false,
            fullscreenButton: false,
            baseLayer: false,
            msaaSamples: 4,
            targetFrameRate: 30,
        });
        const { scene } = this.viewer;
        scene.globe.baseColor = Color.fromCssColorString('#10151c');
        // The real Earth stays in view: lit by the site's sun, with atmosphere,
        // so pulling back shows the day and night sides at the simulated time.
        scene.globe.enableLighting = true;
        scene.globe.showGroundAtmosphere = true;
        scene.globe.dynamicAtmosphereLighting = true;
        if (scene.skyAtmosphere) scene.skyAtmosphere.show = true;
        scene.globe.depthTestAgainstTerrain = false;
        scene.backgroundColor = Color.fromCssColorString('#0b0f14');
        this.viewer.shadowMap.softShadows = true;
        this.viewer.shadowMap.darkness = 0.45;
        this.viewer.clock.shouldAnimate = false;
        this.viewer.screenSpaceEventHandler.removeInputAction(
            ScreenSpaceEventType.LEFT_DOUBLE_CLICK,
        );

        // Bundled imagery means the twin still renders with no network at all.
        void this.addOfflineImagery();

        for (const source of [this.zones, this.data, this.labels]) {
            void this.viewer.dataSources.add(source);
        }
        this.flowLines = scene.primitives.add(
            new PolylineCollection(),
        ) as PolylineCollection;
        this.pulses = scene.primitives.add(
            new PointPrimitiveCollection(),
        ) as PointPrimitiveCollection;

        this.buildZones();
        this.buildRoutes();
        for (const a of def.assets) this.buildAsset(a);
        this.buildBodies();
        this.buildFlows();
        this.buildSelection();
        void this.loadLayers();

        this.handler = new ScreenSpaceEventHandler(scene.canvas);
        this.handler.setInputAction((e: { position: Cartesian2 }) => {
            if (this.onGroundPick) {
                const ground = this.viewer.camera.pickEllipsoid(e.position);
                if (ground) {
                    const c = Cartographic.fromCartesian(ground);
                    this.onGroundPick(
                        lonLatToMetres(
                            this.origin,
                            CesiumMath.toDegrees(c.longitude),
                            CesiumMath.toDegrees(c.latitude),
                        ),
                    );
                }
                return;
            }
            const picked = scene.pick(e.position) as
                | { id?: Entity | string }
                | undefined;
            const hit = picked?.id;
            const id =
                typeof hit === 'string'
                    ? hit.startsWith('layer:')
                        ? undefined
                        : hit.split('#')[0]
                    : (hit?.properties?.assetId?.getValue() as
                          | string
                          | undefined);
            this.onPick?.(id ?? null);
        }, ScreenSpaceEventType.LEFT_CLICK);

        this.removePreRender = scene.preRender.addEventListener(() =>
            this.animate(),
        );
        // Arrive from orbit: start over the whole Earth, then descend to the site.
        this.globeView(0);
        this.home(OPENING_FLIGHT_S);
    }

    // Construction --------------------------------------------------------------

    private cart(p: Point, height = 0): Cartesian3 {
        const { lon, lat } = metresToLonLat(this.origin, p);
        return Cartesian3.fromDegrees(lon, lat, height);
    }

    private ring(points: Point[]): number[] {
        return points.flatMap((p) => {
            const { lon, lat } = metresToLonLat(this.origin, p);
            return [lon, lat];
        });
    }

    private async addOfflineImagery(): Promise<void> {
        const provider = await TileMapServiceImageryProvider.fromUrl(
            buildModuleUrl('Assets/Textures/NaturalEarthII'),
        );
        if (this.viewer.isDestroyed()) return;
        this.viewer.imageryLayers.add(
            new ImageryLayer(provider, { brightness: 0.75, saturation: 0.8 }),
            0,
        );
    }

    setBasemap(name: Basemap): void {
        if (name === this.basemapName) return;
        this.basemapName = name;
        if (this.basemapLayer)
            this.viewer.imageryLayers.remove(this.basemapLayer);
        this.basemapLayer = undefined;
        // Online layers sit above the bundled one, which shows through if tiles fail.
        if (name === 'osm') {
            this.basemapLayer = this.viewer.imageryLayers.addImageryProvider(
                new OpenStreetMapImageryProvider({
                    url: 'https://tile.openstreetmap.org/',
                    credit: new Credit('© OpenStreetMap contributors', true),
                }),
            );
            this.basemapLayer.brightness = 0.62;
            this.basemapLayer.saturation = 0.45;
        } else if (name === 'custom' && CUSTOM_BASEMAP_URL) {
            this.basemapLayer = this.viewer.imageryLayers.addImageryProvider(
                new UrlTemplateImageryProvider({
                    url: CUSTOM_BASEMAP_URL,
                    // Shown on screen, not behind a link: tile licences ask for visible attribution.
                    credit: new Credit(
                        import.meta.env.VITE_BASEMAP_ATTRIBUTION ?? '',
                        true,
                    ),
                }),
            );
        }
    }

    private buildZones(): void {
        for (const z of this.def.zones) {
            this.zones.entities.add({
                polygon: {
                    hierarchy: Cartesian3.fromDegreesArray(
                        this.ring(z.footprint),
                    ),
                    height: 0.2,
                    material:
                        Color.fromCssColorString('#1c2733').withAlpha(0.72),
                    outline: true,
                    outlineColor: Color.fromCssColorString('#4a5a6c'),
                },
            });
            const [x] = centroid(z.footprint);
            const south = Math.min(...z.footprint.map((p) => p[1]));
            this.zones.entities.add({
                position: this.cart([x, south + 6], 1),
                label: {
                    text: z.name.toUpperCase(),
                    font: '500 11px "IBM Plex Sans", sans-serif',
                    fillColor: Color.fromCssColorString('#8796a8'),
                    style: LabelStyle.FILL,
                    scaleByDistance: new NearFarScalar(200, 1, 1800, 0.7),
                    distanceDisplayCondition: new DistanceDisplayCondition(
                        0,
                        1800,
                    ),
                },
            });
        }
    }

    private buildRoutes(): void {
        for (const r of this.def.routes) {
            const closed = [...r.path, r.path[0]!];
            this.zones.entities.add({
                polyline: {
                    positions: closed.map((p) => this.cart(p, 0.4)),
                    width: 5,
                    material: Color.fromCssColorString('#3a4757'),
                    arcType: ArcType.NONE,
                },
            });
        }
    }

    private buildAsset(a: AssetDef): void {
        if (a.kind === 'vehicle') {
            this.buildVehicle(a);
            return;
        }
        if (a.kind === 'site') return;
        const type = typeOf(this.def, a);
        const base = a.baseHeight ?? 0.5;
        const height = a.height ?? type?.visual.height ?? 4;
        const properties = { assetId: a.id };
        const bodies: string[] = [];
        const addBody = (footprint: Point[], from: number, to: number) => {
            const id = `${a.id}#${bodies.length}`;
            bodies.push(id);
            this.pendingBodies.push({ id, footprint, base: from, top: to });
        };

        if (type && a.position && !a.footprint) {
            addBody(
                type.visual.shape === 'cylinder'
                    ? circleAround(a.position, 1.6)
                    : squareAround(a.position, 1.6),
                base,
                base + height,
            );
        } else if (a.kind === 'solar' && a.footprint) {
            for (const row of solarRows(a.footprint, a.params?.rows ?? 8))
                addBody(row, base, base + 0.6);
        } else if (a.footprint) {
            addBody(a.footprint, base, base + height);
        } else if (a.position) {
            // A mast: a thin pole with a sensor at the top.
            addBody(squareAround(a.position, 1.2), 0.5, height);
        }

        const center = a.footprint
            ? centroid(a.footprint)
            : (a.position ?? [0, 0]);
        const top = base + height;
        const anchor = this.cart(center, top + 2);
        const visual: AssetVisual = { def: a, bodies, anchor, top };
        if (LABELLED.has(a.kind)) {
            visual.label = this.labels.entities.add({
                position: anchor,
                properties,
                label: {
                    text: a.name,
                    font: '500 12px "IBM Plex Sans", sans-serif',
                    fillColor: Color.fromCssColorString('#e8edf3'),
                    showBackground: true,
                    backgroundColor:
                        Color.fromCssColorString('#0b0f14').withAlpha(0.78),
                    backgroundPadding: new Cartesian2(7, 4),
                    horizontalOrigin: HorizontalOrigin.CENTER,
                    verticalOrigin: VerticalOrigin.BOTTOM,
                    // Something mounted on a roof labels above whatever it sits on.
                    pixelOffset: new Cartesian2(0, a.baseHeight ? -40 : -4),
                    scaleByDistance: new NearFarScalar(250, 1, 1600, 0.62),
                    distanceDisplayCondition: new DistanceDisplayCondition(
                        0,
                        1700,
                    ),
                    disableDepthTestDistance: Number.POSITIVE_INFINITY,
                },
            });
        }
        this.visuals.set(a.id, visual);
    }

    /**
     * All bodies are instances in one primitive. A colour change writes straight
     * to a per-instance attribute; doing it through entities would rebuild the
     * geometry in a worker each time, which at this update rate never catches up.
     */
    private buildBodies(): void {
        const hierarchy = (footprint: Point[]) =>
            new PolygonHierarchy(
                Cartesian3.fromDegreesArray(this.ring(footprint)),
            );
        const start = ColorGeometryInstanceAttribute.fromColor(
            Color.fromCssColorString(INERT_COLOR),
        );
        this.bodies = this.viewer.scene.primitives.add(
            new Primitive({
                geometryInstances: this.pendingBodies.map(
                    (b) =>
                        new GeometryInstance({
                            id: b.id,
                            geometry: new PolygonGeometry({
                                polygonHierarchy: hierarchy(b.footprint),
                                height: b.base,
                                extrudedHeight: b.top,
                                vertexFormat:
                                    PerInstanceColorAppearance.VERTEX_FORMAT,
                            }),
                            attributes: { color: start },
                        }),
                ),
                appearance: new PerInstanceColorAppearance({
                    translucent: false,
                    closed: true,
                }),
                shadows: ShadowMode.ENABLED,
                // A few dozen prisms build in milliseconds here; through the worker
                // pipeline they took half a minute to first appear.
                asynchronous: false,
            }),
        ) as Primitive;
        this.viewer.scene.primitives.add(
            new Primitive({
                geometryInstances: this.pendingBodies.map(
                    (b) =>
                        new GeometryInstance({
                            id: `${b.id}#edge`,
                            geometry: new PolygonOutlineGeometry({
                                polygonHierarchy: hierarchy(b.footprint),
                                height: b.base,
                                extrudedHeight: b.top,
                            }),
                            attributes: {
                                color: ColorGeometryInstanceAttribute.fromColor(
                                    Color.fromCssColorString(
                                        '#0b0f14',
                                    ).withAlpha(0.7),
                                ),
                            },
                        }),
                ),
                appearance: new PerInstanceColorAppearance({
                    flat: true,
                    translucent: true,
                }),
                asynchronous: false,
            }),
        );
        this.pendingBodies.length = 0;
    }

    private buildVehicle(a: AssetDef): void {
        const isTruck = a.id.startsWith('truck');
        const dims = isTruck
            ? new Cartesian3(11, 3, 3.6)
            : new Cartesian3(4, 2.4, 1.8);
        const start = this.cart([0, 0], dims.z / 2 + 0.5);
        const vehicle: Vehicle = {
            from: start,
            to: start,
            heading: 0,
            startedAt: 0,
            entity: undefined as never,
        };
        const position = new CallbackPositionProperty(() => {
            const f = Math.min(
                1,
                (performance.now() - vehicle.startedAt) / LERP_MS,
            );
            return Cartesian3.lerp(
                vehicle.from,
                vehicle.to,
                f,
                new Cartesian3(),
            );
        }, false);
        const orientation = new CallbackProperty(() => {
            const f = Math.min(
                1,
                (performance.now() - vehicle.startedAt) / LERP_MS,
            );
            const at = Cartesian3.lerp(
                vehicle.from,
                vehicle.to,
                f,
                new Cartesian3(),
            );
            // Cesium heading 0 faces east; ours is clockwise from north.
            return Transforms.headingPitchRollQuaternion(
                at,
                new HeadingPitchRoll(vehicle.heading - Math.PI / 2, 0, 0),
                undefined,
                undefined,
                new Quaternion(),
            );
        }, false);
        vehicle.entity = this.data.entities.add({
            position,
            orientation: orientation as never,
            properties: { assetId: a.id },
            box: {
                dimensions: dims,
                material: Color.fromCssColorString('#e8edf3'),
                outline: true,
                outlineColor: Color.fromCssColorString('#0b0f14'),
                shadows: ShadowMode.ENABLED,
            },
            point: {
                pixelSize: 5,
                color: Color.fromCssColorString('#e8edf3'),
                outlineColor: Color.fromCssColorString('#0b0f14'),
                outlineWidth: 1,
                distanceDisplayCondition: new DistanceDisplayCondition(
                    900,
                    6000,
                ),
            },
        });
        this.vehicles.set(a.id, vehicle);
    }

    private buildFlows(): void {
        const center = (a: AssetDef): Cartesian3 =>
            this.visuals.get(a.id)?.anchor ?? this.cart([0, 0], 8);
        const add = (
            key: string,
            type: Flow['type'],
            metric: string,
            from: AssetDef,
            to: AssetDef,
        ) => {
            const path = arc(center(from), center(to));
            const line = this.flowLines.add({
                positions: path,
                width: 1.4,
                material: Material.fromType('Color', {
                    color: flowColor(type, 0.12),
                }),
            }) as Polyline;
            const pulses: PointPrimitive[] = [];
            for (let i = 0; i < PULSES_PER_FLOW; i++) {
                pulses.push(
                    this.pulses.add({
                        position: path[0],
                        pixelSize: 5,
                        color: flowColor(type, 1),
                        show: false,
                        disableDepthTestDistance: Number.POSITIVE_INFINITY,
                    }),
                );
            }
            this.flows.push({
                key,
                type,
                metric,
                path,
                line,
                pulses,
                kw: 0,
                phase: this.flows.length * 0.37,
            });
        };
        const main = this.def.assets.find((a) => a.kind === 'bus' && !a.fedBy);
        if (!main) return;
        for (const a of this.def.assets) {
            if (
                a.kind === 'grid' ||
                a.kind === 'solar' ||
                a.kind === 'battery' ||
                a.kind === 'genset'
            ) {
                add(`${a.id}>${main.id}`, 'power', `${a.id}.powerKw`, a, main);
            }
            if (a.fedBy) {
                const parent = this.def.assets.find((p) => p.id === a.fedBy);
                const metric =
                    a.kind === 'bus' ? `${a.id}.loadKw` : `${a.id}.powerKw`;
                if (parent)
                    add(`${parent.id}>${a.id}`, 'power', metric, parent, a);
            }
            for (const storeId of a.cools ?? []) {
                const store = this.def.assets.find((s) => s.id === storeId);
                if (store)
                    add(
                        `${a.id}~${storeId}`,
                        'cooling',
                        `${a.id}.coolingKw`,
                        a,
                        store,
                    );
            }
        }
    }

    private buildSelection(): void {
        this.selection = this.data.entities.add({
            show: false,
            polyline: {
                positions: [],
                width: 3,
                arcType: ArcType.NONE,
                material: Color.fromCssColorString('#ffd166'),
                depthFailMaterial:
                    Color.fromCssColorString('#ffd166').withAlpha(0.45),
            },
        });
    }

    /**
     * Tilesets keep their own georeference unless placed; models are always
     * placed. A layer that fails to load is skipped so the twin still runs.
     */
    private async loadLayers(): Promise<void> {
        for (const t of this.def.tilesets ?? []) {
            try {
                const tileset = await Cesium3DTileset.fromUrl(
                    resolveUrl(t.url),
                );
                if (this.viewer.isDestroyed()) return;
                if (t.placement)
                    tileset.modelMatrix = this.placementMatrix(t.placement);
                tileset.show = this.contextShown;
                this.viewer.scene.primitives.add(tileset);
                this.layerPrimitives.set(t.id, tileset);
            } catch (error) {
                console.warn(`Could not load tileset ${t.id}`, error);
            }
        }
        for (const m of this.def.models ?? []) {
            try {
                const model = await Model.fromGltfAsync({
                    url: resolveUrl(m.url),
                    modelMatrix: this.placementMatrix(m.placement),
                    id: `layer:${m.id}`,
                    shadows: ShadowMode.ENABLED,
                });
                if (this.viewer.isDestroyed()) return;
                this.viewer.scene.primitives.add(model);
                this.layerPrimitives.set(m.id, model);
            } catch (error) {
                console.warn(`Could not load model ${m.id}`, error);
            }
        }
    }

    /** East/north/up from the origin, turned clockwise from north, then scaled. */
    placementMatrix(p: Placement): Matrix4 {
        const { lon, lat } = metresToLonLat(this.origin, [p.east, p.north]);
        const frame = Transforms.headingPitchRollToFixedFrame(
            Cartesian3.fromDegrees(lon, lat, p.up),
            new HeadingPitchRoll(CesiumMath.toRadians(p.headingDeg), 0, 0),
        );
        return Matrix4.multiplyByUniformScale(frame, p.scale, frame);
    }

    /** Moves a loaded layer live, for the placement editor. */
    setPlacement(id: string, p: Placement | undefined): void {
        const primitive = this.layerPrimitives.get(id);
        if (primitive)
            primitive.modelMatrix = p
                ? this.placementMatrix(p)
                : Matrix4.clone(Matrix4.IDENTITY);
    }

    /** Test hook: which layers have loaded. */
    loadedLayers(): string[] {
        return [...this.layerPrimitives.keys()];
    }

    // Updates ---------------------------------------------------------------------

    updateFrame(frame: Frame, colorBy: ColorBy): void {
        this.lastFrame = frame;
        this.colorBy = colorBy;
        this.viewer.clock.currentTime = JulianDate.fromDate(new Date(frame.t));
        this.updateLight(frame.t);
        for (const visual of this.visuals.values())
            this.updateVisual(visual, frame);
        for (const flow of this.flows) {
            const kw = frame.values[flow.metric] ?? 0;
            const wasActive = Math.abs(flow.kw) > 0.5;
            const active = Math.abs(kw) > 0.5;
            flow.kw = kw;
            if (active !== wasActive) {
                flow.line.material.uniforms.color = flowColor(
                    flow.type,
                    active ? 0.45 : 0.12,
                );
            }
        }
        const now = performance.now();
        for (const [id, vehicle] of this.vehicles) {
            const x = frame.values[`${id}.x`];
            const y = frame.values[`${id}.y`];
            if (x === undefined || y === undefined) continue;
            const box = vehicle.entity.box!;
            const f = Math.min(1, (now - vehicle.startedAt) / LERP_MS);
            vehicle.from = Cartesian3.lerp(
                vehicle.from,
                vehicle.to,
                f,
                new Cartesian3(),
            );
            vehicle.to = this.cart(
                [x, y],
                (box.dimensions!.getValue(JulianDate.now()) as Cartesian3).z /
                    2 +
                    0.5,
            );
            vehicle.heading = frame.values[`${id}.heading`] ?? 0;
            vehicle.startedAt = now;
            const state = frame.values[`${id}.state`];
            const color =
                state === State.Fault
                    ? BODY_COLOR[State.Fault]!
                    : colorBy === 'status'
                      ? '#e8edf3'
                      : '#aab4c0';
            const material = box.material as ColorMaterialProperty;
            if (
                material.color?.getValue(JulianDate.now())?.toCssHexString() !==
                color
            ) {
                material.color = new ConstantProperty(
                    Color.fromCssColorString(color),
                );
            }
        }
    }

    private updateLight(t: number): void {
        const hour = hourOfDay(t, this.def.utcOffsetHours);
        const scene = this.viewer.scene;
        const daylight = hour > 5.8 && hour < 18.6;
        const light = daylight ? this.sunLight : this.moonLight;
        if (scene.light !== light) scene.light = light;
        this.viewer.shadows = this.shadowsWanted && daylight;
    }

    private shadowsWanted = false;

    private updateVisual(visual: AssetVisual, frame: Frame): void {
        const { def: a } = visual;
        const color = this.colorFor(a, frame);
        // Until the primitive has built there is nothing to write to; retry next frame.
        if (color !== visual.color && this.bodies?.ready) {
            visual.color = color;
            const value = Color.fromCssColorString(color);
            for (const id of visual.bodies) {
                const attributes =
                    this.bodies.getGeometryInstanceAttributes(id);
                attributes.color = ColorGeometryInstanceAttribute.toValue(
                    value,
                    attributes.color,
                );
            }
        }
        if (visual.label) {
            const key = primaryMetricFor(this.def, a);
            const text = `${a.name}\n${formatMetric(metricDefFor(this.def, a, key), frame.values[`${a.id}.${key}`])}`;
            if (text !== visual.labelText) {
                visual.labelText = text;
                visual.label.label!.text = new ConstantProperty(text);
            }
        }
    }

    private colorFor(a: AssetDef, frame: Frame): string {
        const value = (k: string) => frame.values[`${a.id}.${k}`];
        const state = value('state') ?? State.Off;
        if (
            state === State.Fault &&
            a.kind !== 'coldstore' &&
            this.colorBy !== 'status'
        )
            return BODY_COLOR[State.Fault]!;
        switch (this.colorBy) {
            case 'status':
                if (a.kind === 'custom' && state === State.Running)
                    return typeOf(this.def, a)?.visual.color ?? INERT_COLOR;
                return BODY_COLOR[state] ?? INERT_COLOR;
            case 'temperature': {
                if (a.kind === 'coldstore') {
                    return temperatureColor(
                        value('tempC') ?? 0,
                        value('setpointC') ?? 0,
                        a.params?.limitC ?? 0,
                    );
                }
                if (a.kind === 'chiller')
                    return temperatureColor(value('condenserC') ?? 30, 30, 50);
                return INERT_COLOR;
            }
            case 'power': {
                const scale = POWER_SCALE[a.kind];
                if (!scale) return INERT_COLOR;
                const kw =
                    a.kind === 'bus' ? value('loadKw') : value('powerKw');
                return powerColor(Math.abs(kw ?? 0) / scale);
            }
        }
    }

    setAlerts(alerts: Alert[]): void {
        const wanted = new Map<string, Alert['severity']>();
        for (const alert of alerts) {
            const current = wanted.get(alert.assetId);
            if (!current || alert.severity === 'critical')
                wanted.set(alert.assetId, alert.severity);
        }
        for (const [id, entities] of this.beacons) {
            if (!wanted.has(id)) {
                for (const e of entities) this.data.entities.remove(e);
                this.beacons.delete(id);
            }
        }
        for (const [id, severity] of wanted) {
            const visual = this.visuals.get(id);
            if (!visual || this.beacons.has(id)) continue;
            const color = Color.fromCssColorString(SEVERITY_COLOR[severity]);
            const center = visual.def.footprint
                ? centroid(visual.def.footprint)
                : (visual.def.position ?? [0, 0]);
            const beam = this.data.entities.add({
                properties: { assetId: id },
                polyline: {
                    positions: [
                        this.cart(center, visual.top),
                        this.cart(center, visual.top + 70),
                    ],
                    width: 9,
                    arcType: ArcType.NONE,
                    material: new PolylineGlowMaterialProperty({
                        color,
                        glowPower: 0.3,
                    }),
                },
            });
            const pulse = this.data.entities.add({
                position: this.cart(center, visual.top + 70),
                properties: { assetId: id },
                point: {
                    pixelSize: new CallbackProperty(
                        () =>
                            11 +
                            6 * (0.5 + 0.5 * Math.sin(performance.now() / 260)),
                        false,
                    ),
                    color,
                    outlineColor: Color.BLACK.withAlpha(0.6),
                    outlineWidth: 1,
                    disableDepthTestDistance: Number.POSITIVE_INFINITY,
                },
            });
            this.beacons.set(id, [beam, pulse]);
        }
    }

    setSelected(id: string | null): void {
        if (!this.selection) return;
        const a = id ? this.def.assets.find((x) => x.id === id) : undefined;
        const footprint =
            a?.footprint ??
            (a?.position ? squareAround(a.position, 6) : undefined);
        if (!a || !footprint) {
            this.selection.show = false;
            return;
        }
        const top = (a.baseHeight ?? 0.5) + (a.height ?? 4) + 0.3;
        this.selection.polyline!.positions = new ConstantProperty(
            [...footprint, footprint[0]!].map((p) =>
                this.cart(
                    p,
                    a.kind === 'solar' ? (a.baseHeight ?? 0.5) + 1 : top,
                ),
            ),
        );
        this.selection.show = true;
    }

    flyToAsset(id: string): void {
        const a = this.def.assets.find((x) => x.id === id);
        if (!a) return;
        const vehicle = this.vehicles.get(id);
        const center = vehicle
            ? vehicle.to
            : a.footprint
              ? this.cart(centroid(a.footprint), a.height ?? 0)
              : a.position
                ? this.cart(a.position, a.height ?? 0)
                : null;
        if (!center) {
            this.home(1.2);
            return;
        }
        const size = a.footprint
            ? Math.max(
                  ...a.footprint.map((p) =>
                      Math.hypot(
                          p[0] - centroid(a.footprint!)[0],
                          p[1] - centroid(a.footprint!)[1],
                      ),
                  ),
              )
            : 12;
        this.viewer.camera.flyToBoundingSphere(
            { center, radius: size } as never,
            {
                duration: 1.2,
                offset: new HeadingPitchRange(
                    CesiumMath.toRadians(this.def.camera.headingDeg),
                    CesiumMath.toRadians(-28),
                    Math.max(70, size * 4.2),
                ),
            },
        );
    }

    /** Pulls back until the whole Earth is in view, still centred on the site. */
    globeView(duration = 2.5): void {
        this.viewer.camera.flyTo({
            destination: Cartesian3.fromDegrees(
                this.origin.lon,
                this.origin.lat,
                GLOBE_ALTITUDE_M,
            ),
            duration,
        });
    }

    home(duration = 1.2): void {
        const { camera } = this.def;
        this.viewer.camera.flyToBoundingSphere(
            { center: this.cart([0, -30], 0), radius: 1 } as never,
            {
                duration,
                offset: new HeadingPitchRange(
                    CesiumMath.toRadians(camera.headingDeg),
                    CesiumMath.toRadians(camera.pitchDeg),
                    camera.rangeM,
                ),
            },
        );
    }

    setLayers(layers: Layers): void {
        this.labels.show = layers.labels;
        this.flowLines.show = layers.flows;
        this.pulses.show = layers.flows;
        this.zones.show = layers.zones;
        this.contextShown = layers.context;
        for (const primitive of this.layerPrimitives.values()) {
            if (primitive instanceof Cesium3DTileset)
                primitive.show = layers.context;
        }
        this.shadowsWanted = layers.shadows;
        if (this.lastFrame) this.updateLight(this.lastFrame.t);
    }

    /** The colour actually written to an asset's geometry, for tests. */
    appliedColor(id: string): string | null {
        const visual = this.visuals.get(id);
        const instance = visual?.bodies[0];
        if (!instance || !this.bodies?.ready) return null;
        const [r, g, b] = this.bodies.getGeometryInstanceAttributes(instance)
            .color as Uint8Array;
        return `#${[r, g, b].map((c) => c!.toString(16).padStart(2, '0')).join('')}`;
    }

    /** Screen position of an asset, for tests that click the real canvas. */
    project(id: string): { x: number; y: number } | null {
        const visual = this.visuals.get(id);
        if (!visual) return null;
        const a = visual.def;
        const center = a.footprint
            ? centroid(a.footprint)
            : (a.position ?? [0, 0]);
        const p = this.viewer.scene.cartesianToCanvasCoordinates(
            this.cart(center, visual.top - 0.2),
        );
        const rect = this.viewer.scene.canvas.getBoundingClientRect();
        return p ? { x: rect.left + p.x, y: rect.top + p.y } : null;
    }

    private animate(): void {
        const now = performance.now();
        const dt = (now - this.lastPulseAt) / 1000;
        this.lastPulseAt = now;
        for (const flow of this.flows) {
            const magnitude = Math.abs(flow.kw);
            if (magnitude <= 0.5) {
                for (const pulse of flow.pulses) pulse.show = false;
                continue;
            }
            const speed = 0.12 + Math.min(0.5, magnitude / 900);
            flow.phase = (flow.phase + dt * speed) % 1;
            const visible = Math.min(
                PULSES_PER_FLOW,
                1 + Math.floor(magnitude / 60),
            );
            const size = 3.5 + Math.min(4, magnitude / 110);
            flow.pulses.forEach((pulse, i) => {
                pulse.show = i < visible;
                if (i >= visible) return;
                let f = (flow.phase + i / visible) % 1;
                if (flow.kw < 0) f = 1 - f;
                pulse.position = alongPath(flow.path, f);
                pulse.pixelSize = size;
            });
        }
    }

    destroy(): void {
        this.removePreRender?.();
        this.handler.destroy();
        if (!this.viewer.isDestroyed()) this.viewer.destroy();
    }
}

function flowColor(type: Flow['type'], alpha: number): Color {
    return Color.fromCssColorString(
        type === 'power' ? '#ffc95c' : '#59c3ff',
    ).withAlpha(alpha);
}

function circleAround([x, y]: Point, r: number, sides = 16): Point[] {
    return Array.from({ length: sides }, (_, i) => {
        const a = (i / sides) * Math.PI * 2;
        return [x + r * Math.cos(a), y + r * Math.sin(a)] as Point;
    });
}

/** Absolute paths and URLs load as given; bare paths are relative to the site root. */
function resolveUrl(url: string): string {
    return /^(https?:)?\/\//.test(url) || url.startsWith('/') ? url : `/${url}`;
}

function squareAround([x, y]: Point, s: number): Point[] {
    return [
        [x - s, y - s],
        [x + s, y - s],
        [x + s, y + s],
        [x - s, y + s],
    ];
}

/** Splits a footprint's bounding box into parallel rows of panels. */
function solarRows(footprint: Point[], rows: number): Point[][] {
    const xs = footprint.map((p) => p[0]);
    const ys = footprint.map((p) => p[1]);
    const [x0, x1] = [Math.min(...xs), Math.max(...xs)];
    const [y0, y1] = [Math.min(...ys), Math.max(...ys)];
    const pitch = (y1 - y0) / rows;
    return Array.from({ length: rows }, (_, i) => {
        const a = y0 + i * pitch;
        const b = a + pitch * 0.72;
        return [
            [x0, a],
            [x1, a],
            [x1, b],
            [x0, b],
        ] as Point[];
    });
}

/** A shallow arch between two points, so flows clear the buildings below. */
function arc(from: Cartesian3, to: Cartesian3): Cartesian3[] {
    const distance = Cartesian3.distance(from, to);
    const up = Cartesian3.normalize(
        Cartesian3.midpoint(from, to, new Cartesian3()),
        new Cartesian3(),
    );
    const lift = Math.min(38, 6 + distance * 0.14);
    const points: Cartesian3[] = [];
    const segments = 18;
    for (let i = 0; i <= segments; i++) {
        const f = i / segments;
        const p = Cartesian3.lerp(from, to, f, new Cartesian3());
        Cartesian3.add(
            p,
            Cartesian3.multiplyByScalar(
                up,
                4 * f * (1 - f) * lift,
                new Cartesian3(),
            ),
            p,
        );
        points.push(p);
    }
    return points;
}

function alongPath(path: Cartesian3[], f: number): Cartesian3 {
    const scaled = f * (path.length - 1);
    const i = Math.min(path.length - 2, Math.floor(scaled));
    return Cartesian3.lerp(
        path[i]!,
        path[i + 1]!,
        scaled - i,
        new Cartesian3(),
    );
}
