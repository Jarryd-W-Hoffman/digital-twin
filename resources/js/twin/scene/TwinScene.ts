import {
    ArcType,
    buildModuleUrl,
    CallbackPositionProperty,
    CallbackProperty,
    Cartesian2,
    Cartesian3,
    Cesium3DTileset,
    Cesium3DTileStyle,
    ClippingPlane,
    ClippingPlaneCollection,
    CesiumTerrainProvider,
    Color,
    Credit,
    ColorGeometryInstanceAttribute,
    ColorMaterialProperty,
    ConstantProperty,
    CustomDataSource,
    DirectionalLight,
    DistanceDisplayCondition,
    GeometryInstance,
    GeoJsonDataSource,
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
    PolylineDashMaterialProperty,
    Polyline,
    PolylineCollection,
    PolylineGlowMaterialProperty,
    Primitive,
    Quaternion,
    Rectangle,
    ScreenSpaceEventHandler,
    ScreenSpaceEventType,
    ShadowMode,
    SingleTileImageryProvider,
    sampleTerrainMostDetailed,
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
import { imageryTiles, parseImageryInfo, vectorStyle } from '../core/layers.ts';
import type { Vec3 } from '../core/measure.ts';
import {
    formatMetric,
    metricDefFor,
    primaryMetricFor,
    typeOf,
} from '../core/metrics.ts';
import {
    cutBody,
    facing,
    reach,
    sectionPlanes,
    type Body,
    type Section,
} from '../core/section.ts';
import { compileShape } from '../core/shapes.ts';
import type {
    Alert,
    AssetDef,
    FeatureRef,
    Frame,
    Placement,
    Point,
    TwinDefinition,
} from '../core/types.ts';
import { State } from '../core/types.ts';
import type {
    Basemap,
    CameraView,
    ColorBy,
    Layers,
    SurveyOverlay,
} from '../state/store.ts';
import {
    BODY_COLOR,
    INERT_COLOR,
    powerColor,
    SEVERITY_COLOR,
    temperatureColor,
} from './colors.ts';
import { Gizmo } from './Gizmo.ts';
import { hillshade } from './hillshade.ts';
import { Measure } from './Measure.ts';
import { surfaceAt } from './pick.ts';
import { Pins } from './Pins.ts';
import { Shapes } from './Shapes.ts';
import { Sight } from './Sight.ts';
import { Sketch } from './Sketch.ts';

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

/** A feature's id property as text: tilesets may store ids as numbers. */
const featureKey = (value: unknown): string | null =>
    typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : null;

/** A layer's clipping planes: a cut above and a cut across. */
const CLIP_SLOTS = 2;
/** An unused plane's distance: far enough below to keep everything. */
const KEEP_ALL = 1e7;

/** A selected asset's feature: the selection outline's colour. */
const SELECTED_FEATURE = '#ffd166';

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
    private edges?: Primitive;
    /** Every asset's blocks, uncut: the section is cut from these. */
    private readonly bodyShapes: (Body & { id: string })[] = [];
    /** The blocks drawn, as cut by the section, by instance id. */
    private readonly drawnBodies = new Map<string, Body>();
    private section: Section | null = null;
    private readonly sectionLines = new CustomDataSource('section');
    private readonly visuals = new Map<string, AssetVisual>();
    private readonly vehicles = new Map<string, Vehicle>();
    private readonly flows: Flow[] = [];
    private readonly beacons = new Map<string, Entity[]>();
    private readonly handler: ScreenSpaceEventHandler;
    /** A footprint or path being drawn for the definition editor. */
    readonly sketch: Sketch;
    /** Distances, heights, areas and points measured in the scene. */
    readonly measure: Measure;
    /** Handles for moving a placed layer by dragging. */
    readonly gizmo: Gizmo;
    /** Viewsheds and lines of sight. */
    readonly sight: Sight;
    /** The definition's vectors and fans. */
    readonly shapes: Shapes;
    /** Issues pinned in the scene. */
    readonly pins: Pins;
    /** While set, a click places a new pin instead of selecting. */
    onPinPlaced?: (at: Vec3, view: CameraView) => void;
    /** Clicking a pin. */
    onPinPick?: (id: string) => void;
    private built = false;
    /** A camera asked for before the site was built, flown to instead of the opening view. */
    private openingView: CameraView | null = null;
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
    /** An asset to pin once the site is built, asked for before it was. */
    private assetPin?: {
        id: string;
        done: (at: Vec3, view: CameraView) => void;
    };
    /** Kept so a selection made while terrain loads shows once it's built. */
    private selectedId: string | null = null;
    /** Uploaded and bundled context: tilesets and placed models, by layer id. */
    private readonly layerPrimitives = new Map<
        string,
        Cesium3DTileset | Model
    >();
    private contextShown = true;
    /** Uploaded imagery over the basemap, and map layers draped on the ground. */
    private readonly mapImagery = new Map<string, ImageryLayer>();
    private readonly mapVectors = new Map<string, GeoJsonDataSource>();
    /** The change between two surveys, laid over the ground, and its area's outline. */
    private surveyLayer: ImageryLayer | undefined;
    private surveyOutline: Entity | undefined;
    private surveyShown: SurveyOverlay | null = null;
    /**
     * The terrain's height at the origin. The site is built level at that
     * height, so it sits on the ground; 0 on the plain ellipsoid.
     */
    private ground = 0;
    private terrainLoaded = false;
    private basemapLayer?: ImageryLayer;
    private basemapName?: Basemap;
    private removePreRender?: () => void;
    private lastFrame?: Frame;
    private colorBy: ColorBy = 'status';
    private lastPulseAt = performance.now();
    onPick?: (assetId: string | null) => void;
    /** While set, a click reports the ground point instead of selecting. */
    onGroundPick?: (point: Point) => void;
    /** While set, a click on a layer's feature reports it instead of selecting. */
    onFeaturePick?: (feature: FeatureRef) => void;
    /** Assets that are a layer's features: layer id, then feature id, to asset id. */
    private readonly bound = new Map<string, Map<string, string>>();

    constructor(container: HTMLElement, def: TwinDefinition) {
        this.def = def;
        this.origin = def.origin;
        for (const a of def.assets) {
            if (!a.feature) continue;
            const features = this.bound.get(a.feature.layer) ?? new Map();
            features.set(a.feature.id, a.id);
            this.bound.set(a.feature.layer, features);
        }
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

        for (const source of [
            this.zones,
            this.data,
            this.labels,
            this.sectionLines,
        ]) {
            void this.viewer.dataSources.add(source);
        }
        this.flowLines = scene.primitives.add(
            new PolylineCollection(),
        ) as PolylineCollection;
        this.pulses = scene.primitives.add(
            new PointPrimitiveCollection(),
        ) as PointPrimitiveCollection;

        this.sketch = new Sketch(
            this.viewer,
            (position) => this.groundAt(position),
            (p, height) => this.cart(p, height),
            () => this.terrainLoaded,
        );
        // Without terrain the site is level at the origin's height; with it,
        // the terrain's height where it has loaded.
        this.measure = new Measure(this.viewer, (at) =>
            this.terrainLoaded
                ? (scene.globe.getHeight(at) ?? this.ground)
                : this.ground,
        );
        this.gizmo = new Gizmo(this.viewer, () =>
            Transforms.eastNorthUpToFixedFrame(this.cart([0, 0])),
        );
        this.sight = new Sight(this.viewer, {
            toWorld: ([east, north, up]) => this.cart([east, north], up),
            toLocal: (world) => {
                const c = Cartographic.fromCartesian(world);
                const [east, north] = lonLatToMetres(
                    this.origin,
                    CesiumMath.toDegrees(c.longitude),
                    CesiumMath.toDegrees(c.latitude),
                );
                return [east, north, c.height - this.ground];
            },
            frame: () => Transforms.eastNorthUpToFixedFrame(this.cart([0, 0])),
        });
        this.shapes = new Shapes(
            this.viewer,
            ([east, north, up]) => this.cart([east, north], up),
            (def.shapes ?? []).flatMap((s) => compileShape(def, s) ?? []),
        );
        this.pins = new Pins(this.viewer);
        this.handler = new ScreenSpaceEventHandler(scene.canvas);
        this.handler.setInputAction((e: { position: Cartesian2 }) => {
            if (this.onPinPlaced) {
                const hit = surfaceAt(this.viewer, e.position);
                if (!hit) return;
                const c = Cartographic.fromCartesian(hit);
                this.onPinPlaced(
                    [
                        CesiumMath.toDegrees(c.longitude),
                        CesiumMath.toDegrees(c.latitude),
                        c.height,
                    ],
                    this.pins.cameraView(),
                );
                return;
            }
            if (this.measure.active) {
                this.measure.click(e.position);
                return;
            }
            if (this.sight.active) {
                this.sight.click(e.position);
                return;
            }
            if (this.sketch.active) {
                this.sketch.click(e.position);
                return;
            }
            if (this.onGroundPick) {
                const ground = this.groundAt(e.position);
                if (ground) this.onGroundPick(ground);
                return;
            }
            const picked = scene.pick(e.position) as
                | { id?: Entity | string }
                | undefined;
            const pin = this.pins.idOf(picked);
            if (pin) {
                this.onPinPick?.(pin);
                return;
            }
            // A zone's translucent fill can lie over a feature; look through it.
            const feature =
                this.featureOf(picked) ??
                (this.bound.size > 0 || this.onFeaturePick
                    ? (scene
                          .drillPick(e.position, 4)
                          .map((p) => this.featureOf(p))
                          .find((f) => f !== null) ?? null)
                    : null);
            if (this.onFeaturePick) {
                if (feature) this.onFeaturePick(feature);
                return;
            }
            const boundTo =
                feature && this.bound.get(feature.layer)?.get(feature.id);
            if (boundTo) {
                this.onPick?.(boundTo);
                return;
            }
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
        // Arrive from orbit: start over the whole Earth, then descend to the
        // site once it's built. With terrain, that waits for its height at
        // the origin.
        this.globeView(0);
        if (def.terrain) void this.loadTerrain().then(() => this.build());
        else this.build();
    }

    // Construction --------------------------------------------------------------

    private build(): void {
        if (this.viewer.isDestroyed()) return;
        this.buildZones();
        this.buildRoutes();
        for (const a of this.def.assets) this.buildAsset(a);
        this.buildBodies();
        this.buildFlows();
        this.buildSelection();
        void this.loadLayers();
        if (this.lastFrame) this.updateFrame(this.lastFrame, this.colorBy);
        this.setSelected(this.selectedId);
        this.built = true;
        if (this.assetPin) {
            const { id, done } = this.assetPin;
            this.assetPin = undefined;
            this.pinAtAsset(id, done);
        } else if (this.openingView)
            this.pins.flyTo(this.openingView, OPENING_FLIGHT_S);
        else this.home(OPENING_FLIGHT_S);
    }

    /** Flies to a saved camera; before the site is built, it replaces the opening flight. */
    flyToView(view: CameraView): void {
        if (this.built) this.pins.flyTo(view);
        else this.openingView = view;
    }

    /**
     * Pins at the top of an asset, flying to it first so the pin keeps a
     * camera that shows it; before the site is built, once it is. False if
     * the twin has no such asset with a place, so the caller can ask for a
     * click instead.
     */
    pinAtAsset(
        id: string,
        done: (at: Vec3, view: CameraView) => void,
    ): boolean {
        const a = this.def.assets.find((x) => x.id === id);
        const p = a && (a.footprint ? centroid(a.footprint) : a.position);
        if (!a || !p) return false;
        if (!this.built) {
            this.assetPin = { id, done };
            return true;
        }
        const { lon, lat } = metresToLonLat(this.origin, p);
        const at: Vec3 = [
            lon,
            lat,
            this.ground + (a.baseHeight ?? 0.5) + (a.height ?? 4),
        ];
        this.flyToAsset(id, () => done(at, this.pins.cameraView()));
        return true;
    }

    /** Places pins on the next click while on, with a crosshair to show it. */
    setPinning(onPlaced: ((at: Vec3, view: CameraView) => void) | null): void {
        this.onPinPlaced = onPlaced ?? undefined;
        this.viewer.canvas.style.cursor = onPlaced ? 'crosshair' : '';
    }

    /**
     * Quantized-mesh terrain from the definition's layer.json, and its height
     * at the origin. Terrain that fails to load leaves the plain ellipsoid, so
     * the twin still runs.
     */
    private async loadTerrain(): Promise<void> {
        const url = resolveUrl(this.def.terrain!.url).replace(
            /layer\.json(\?.*)?$/,
            '',
        );
        try {
            const provider = await CesiumTerrainProvider.fromUrl(url, {
                requestVertexNormals: true,
            });
            if (this.viewer.isDestroyed()) return;
            this.viewer.terrainProvider = provider;
            this.viewer.scene.globe.depthTestAgainstTerrain = true;
            this.viewer.scene.globe.material = hillshade();
            const [origin] = await sampleTerrainMostDetailed(provider, [
                Cartographic.fromDegrees(this.origin.lon, this.origin.lat),
            ]);
            this.ground = Number.isFinite(origin?.height) ? origin!.height : 0;
            this.terrainLoaded = true;
        } catch (error) {
            console.warn('Could not load the terrain', error);
        }
    }

    /** Test hook: whether terrain loaded, the ground height at the origin, and whether it's hillshaded. */
    terrain(): { loaded: boolean; ground: number; shaded: boolean } {
        return {
            loaded: this.terrainLoaded,
            ground: this.ground,
            shaded: this.viewer.scene.globe.material !== undefined,
        };
    }

    /** Where a screen position meets the ground, in metres from the origin. */
    private groundAt(position: Cartesian2): Point | null {
        const { scene, camera } = this.viewer;
        const ray = camera.getPickRay(position);
        const ground =
            this.terrainLoaded && ray
                ? scene.globe.pick(ray, scene)
                : camera.pickEllipsoid(position);
        if (!ground) return null;
        const c = Cartographic.fromCartesian(ground);
        return lonLatToMetres(
            this.origin,
            CesiumMath.toDegrees(c.longitude),
            CesiumMath.toDegrees(c.latitude),
        );
    }

    /** Where a point, in metres from the origin and above the ground, is on screen. */
    screenAt(p: Point, height = 0): { x: number; y: number } | null {
        const at = this.viewer.scene.cartesianToCanvasCoordinates(
            this.cart(p, height),
        );
        return at ? { x: at.x, y: at.y } : null;
    }

    private cart(p: Point, height = 0): Cartesian3 {
        const { lon, lat } = metresToLonLat(this.origin, p);
        return Cartesian3.fromDegrees(lon, lat, this.ground + height);
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
        // The twin's own imagery stays over whichever basemap.
        for (const layer of this.mapImagery.values())
            this.viewer.imageryLayers.raiseToTop(layer);
        if (this.surveyLayer)
            this.viewer.imageryLayers.raiseToTop(this.surveyLayer);
    }

    /**
     * Lays the change between two surveys over everything on the ground, with
     * the area it was measured in outlined; null takes it away.
     */
    async setSurveyOverlay(overlay: SurveyOverlay | null): Promise<void> {
        this.surveyShown = overlay;
        if (this.surveyLayer) {
            this.viewer.imageryLayers.remove(this.surveyLayer);
            this.surveyLayer = undefined;
        }
        if (this.surveyOutline) {
            this.viewer.entities.remove(this.surveyOutline);
            this.surveyOutline = undefined;
        }
        if (!overlay) return;
        this.surveyOutline = this.viewer.entities.add({
            polyline: {
                positions: Cartesian3.fromDegreesArray(
                    [...overlay.area, overlay.area[0]].flat(),
                ),
                clampToGround: true,
                width: 3,
                material: Color.WHITE.withAlpha(0.9),
            },
        });
        try {
            const provider = await SingleTileImageryProvider.fromUrl(
                overlay.image,
                { rectangle: Rectangle.fromDegrees(...overlay.bounds) },
            );
            // Another overlay may have been asked for while this one loaded.
            if (this.surveyShown !== overlay || this.viewer.isDestroyed())
                return;
            this.surveyLayer =
                this.viewer.imageryLayers.addImageryProvider(provider);
        } catch (error) {
            console.warn('Could not load the survey change', error);
        }
    }

    /** Test hook: the survey change shown, if it has loaded. */
    surveyOverlay(): { bounds: number[]; loaded: boolean } | null {
        return this.surveyShown
            ? {
                  bounds: this.surveyShown.bounds,
                  loaded: this.surveyLayer !== undefined,
              }
            : null;
    }

    private buildZones(): void {
        for (const z of this.def.zones) {
            this.zones.entities.add({
                polygon: {
                    hierarchy: Cartesian3.fromDegreesArray(
                        this.ring(z.footprint),
                    ),
                    // Draped over terrain; just above the plain ellipsoid.
                    height: this.terrainLoaded ? undefined : 0.2,
                    material:
                        Color.fromCssColorString('#1c2733').withAlpha(0.72),
                    outline: !this.terrainLoaded,
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
                    arcType: this.terrainLoaded
                        ? ArcType.GEODESIC
                        : ArcType.NONE,
                    clampToGround: this.terrainLoaded,
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
            this.bodyShapes.push({ id, footprint, base: from, top: to });
        };

        if (a.feature) {
            // The layer's feature is its body.
        } else if (type && a.position && !a.footprint) {
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
     * A section rebuilds them, cut; their colours are written again once built.
     */
    private buildBodies(): void {
        const { primitives } = this.viewer.scene;
        if (this.bodies) primitives.remove(this.bodies);
        if (this.edges) primitives.remove(this.edges);
        this.bodies = this.edges = undefined;
        this.drawnBodies.clear();
        for (const b of this.bodyShapes) {
            const cut = cutBody(b, this.section);
            if (cut) this.drawnBodies.set(b.id, cut);
        }
        for (const visual of this.visuals.values())
            if (!visual.def.feature) visual.color = undefined;
        if (this.drawnBodies.size === 0) return;
        const drawn = [...this.drawnBodies];
        const hierarchy = (footprint: Point[]) =>
            new PolygonHierarchy(
                Cartesian3.fromDegreesArray(this.ring(footprint)),
            );
        const start = ColorGeometryInstanceAttribute.fromColor(
            Color.fromCssColorString(INERT_COLOR),
        );
        const bodies = primitives.add(
            new Primitive({
                geometryInstances: drawn.map(
                    ([id, b]) =>
                        new GeometryInstance({
                            id,
                            geometry: new PolygonGeometry({
                                polygonHierarchy: hierarchy(b.footprint),
                                height: this.ground + b.base,
                                extrudedHeight: this.ground + b.top,
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
        this.bodies = bodies;
        this.edges = primitives.add(
            new Primitive({
                geometryInstances: drawn.map(
                    ([id, b]) =>
                        new GeometryInstance({
                            id: `${id}#edge`,
                            geometry: new PolygonOutlineGeometry({
                                polygonHierarchy: hierarchy(b.footprint),
                                height: this.ground + b.base,
                                extrudedHeight: this.ground + b.top,
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
        ) as Primitive;
        // Colours are written as frames arrive; a paused twin sends none, so
        // write the last frame's once the new primitive can take them.
        const remove = this.viewer.scene.postRender.addEventListener(() => {
            if (this.bodies !== bodies) return remove();
            if (!bodies.ready) return;
            remove();
            if (this.lastFrame) this.updateFrame(this.lastFrame, this.colorBy);
        });
    }

    /** Cuts the site open: blocks are rebuilt cut, and layers are clipped. */
    setSection(section: Section | null): void {
        if (section === this.section) return;
        this.section = section;
        if (this.visuals.size) this.buildBodies();
        for (const primitive of this.layerPrimitives.values())
            this.clip(primitive);
        this.drawSection();
    }

    /**
     * Clipping planes for a tileset or model, given in the site's
     * east-north-up frame: a tileset's planes are relative to its own origin,
     * a model's to its placement, so each gets the way back to the site.
     * Each layer keeps one collection of two planes for good, changed in
     * place: replacing a tileset's collection while its tiles draw left them
     * drawing with the old one's freed texture.
     */
    private clip(primitive: Cesium3DTileset | Model): void {
        const planes = this.section ? sectionPlanes(this.section) : [];
        let collection = primitive.clippingPlanes as
            | ClippingPlaneCollection
            | undefined;
        if (!collection) {
            if (!planes.length) return;
            collection = new ClippingPlaneCollection({
                // Clipped outside either plane, so the spare one cuts nothing.
                unionClippingRegions: true,
                edgeWidth: 1.5,
                edgeColor: Color.fromCssColorString(SELECTED_FEATURE),
            });
            for (let k = 0; k < CLIP_SLOTS; k++)
                collection.add(new ClippingPlane(Cartesian3.UNIT_Z, KEEP_ALL));
            primitive.clippingPlanes = collection;
        }
        collection.enabled = planes.length > 0;
        for (let k = 0; k < CLIP_SLOTS; k++) {
            const plane = collection.get(k);
            const wanted = planes[k];
            plane.normal = wanted
                ? new Cartesian3(...wanted.normal)
                : Cartesian3.UNIT_Z;
            plane.distance = wanted ? wanted.distance : KEEP_ALL;
        }
        const own =
            primitive instanceof Cesium3DTileset
                ? (
                      primitive as unknown as {
                          clippingPlanesOriginMatrix: Matrix4;
                      }
                  ).clippingPlanesOriginMatrix
                : primitive.modelMatrix;
        collection.modelMatrix = Matrix4.multiply(
            Matrix4.inverse(own, new Matrix4()),
            Transforms.eastNorthUpToFixedFrame(this.cart([0, 0])),
            new Matrix4(),
        );
    }

    /** The vertical cut's line on the ground, so it's clear where it runs. */
    private drawSection(): void {
        const { entities } = this.sectionLines;
        entities.removeAll();
        const across = this.section?.across;
        if (!across) return;
        const [fe, fn] = facing(across.heading);
        const half = 2 * reach(this.bodyShapes.flatMap((b) => b.footprint));
        const centre: Point = [fe * across.offset, fn * across.offset];
        const end = (k: number): Point => [
            centre[0] + k * fn,
            centre[1] - k * fe,
        ];
        const ends = [this.cart(end(-half), 0.3), this.cart(end(half), 0.3)];
        entities.add({
            polyline: {
                // Drawn every frame rather than built in a worker, so it shows
                // at once, as the cut moves.
                positions: new CallbackProperty(() => ends, false),
                width: 2,
                material: new PolylineDashMaterialProperty({
                    color: Color.fromCssColorString(SELECTED_FEATURE),
                    dashLength: 16,
                }),
            },
        });
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
                if (this.bound.has(t.id))
                    tileset.style = this.featureStyle(t.id, t.featureProperty);
                this.viewer.scene.primitives.add(tileset);
                this.layerPrimitives.set(t.id, tileset);
                this.clip(tileset);
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
                if (this.bound.has(m.id))
                    model.style = this.featureStyle(m.id, m.featureProperty);
                this.viewer.scene.primitives.add(model);
                this.layerPrimitives.set(m.id, model);
                this.clip(model);
            } catch (error) {
                console.warn(`Could not load model ${m.id}`, error);
            }
        }
        for (const i of this.def.imagery ?? []) {
            try {
                const url = resolveUrl(i.url);
                const info = parseImageryInfo(
                    await (
                        await fetch(url, { credentials: 'same-origin' })
                    ).json(),
                );
                if (!info) throw new Error('imagery.json is not readable');
                if (this.viewer.isDestroyed()) return;
                const layer = this.viewer.imageryLayers.addImageryProvider(
                    new UrlTemplateImageryProvider({
                        url: imageryTiles(url),
                        rectangle: Rectangle.fromDegrees(...info.bounds),
                        minimumLevel: info.minZoom,
                        maximumLevel: info.maxZoom,
                    }),
                );
                layer.alpha = i.opacity ?? 1;
                layer.show = this.contextShown;
                this.mapImagery.set(i.id, layer);
            } catch (error) {
                console.warn(`Could not load imagery ${i.id}`, error);
            }
        }
        for (const v of this.def.vectors ?? []) {
            try {
                const { color, fillOpacity } = vectorStyle(v);
                const stroke = Color.fromCssColorString(color);
                const source = await GeoJsonDataSource.load(resolveUrl(v.url), {
                    clampToGround: true,
                    stroke,
                    fill: stroke.withAlpha(fillOpacity),
                    strokeWidth: 3,
                    markerColor: stroke,
                    markerSize: 32,
                });
                if (this.viewer.isDestroyed()) return;
                source.show = this.contextShown;
                await this.viewer.dataSources.add(source);
                this.mapVectors.set(v.id, source);
            } catch (error) {
                console.warn(`Could not load map layer ${v.id}`, error);
            }
        }
    }

    /**
     * Colours each feature bound to an asset as the asset's body would be, or
     * as the selection when it's selected; other features keep their own colour.
     */
    private featureStyle(layerId: string, property = 'id'): Cesium3DTileStyle {
        const style = new Cesium3DTileStyle();
        const features = this.bound.get(layerId)!;
        style.color = {
            evaluateColor: (
                feature: { getProperty(name: string): unknown },
                result?: Color,
            ) => {
                const assetId = features.get(
                    String(feature.getProperty(property)),
                );
                const css =
                    assetId === undefined
                        ? undefined
                        : assetId === this.selectedId
                          ? SELECTED_FEATURE
                          : this.visuals.get(assetId)?.color;
                return css
                    ? Color.fromCssColorString(css, result)
                    : Color.clone(Color.WHITE, result);
            },
        } as unknown as Cesium3DTileStyle['color'];
        return style;
    }

    /** Re-evaluates a layer's feature colours after an asset's colour changed. */
    private restyle(layerId: string): void {
        const primitive = this.layerPrimitives.get(layerId);
        if (primitive instanceof Cesium3DTileset) primitive.makeStyleDirty();
        else if (primitive?.style) {
            // A model re-applies its style when one is set.
            const { style } = primitive;
            primitive.style = style;
        }
    }

    /** The layer feature a pick hit, by the layer's feature property. */
    private featureOf(picked: unknown): FeatureRef | null {
        const feature = picked as
            | { primitive?: unknown; getProperty?: (name: string) => unknown }
            | undefined;
        if (typeof feature?.getProperty !== 'function') return null;
        for (const [layer, primitive] of this.layerPrimitives) {
            if (primitive !== feature.primitive) continue;
            const def = [
                ...(this.def.tilesets ?? []),
                ...(this.def.models ?? []),
            ].find((l) => l.id === layer);
            const id = featureKey(
                feature.getProperty(def?.featureProperty ?? 'id'),
            );
            return id === null ? null : { layer, id };
        }
        return null;
    }

    /** East/north/up from the origin, turned clockwise from north, then scaled. */
    placementMatrix(p: Placement): Matrix4 {
        const { lon, lat } = metresToLonLat(this.origin, [p.east, p.north]);
        const frame = Transforms.headingPitchRollToFixedFrame(
            Cartesian3.fromDegrees(lon, lat, this.ground + p.up),
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
        return [
            ...this.layerPrimitives.keys(),
            ...this.mapImagery.keys(),
            ...this.mapVectors.keys(),
        ];
    }

    /** Test hook: how many features a map layer drew, and whether it shows. */
    mapLayer(id: string): { features: number; shown: boolean } | null {
        const source = this.mapVectors.get(id);
        const imagery = this.mapImagery.get(id);
        if (source)
            return {
                features: source.entities.values.length,
                shown: source.show,
            };
        return imagery ? { features: 0, shown: imagery.show } : null;
    }

    // Updates ---------------------------------------------------------------------

    updateFrame(frame: Frame, colorBy: ColorBy): void {
        this.lastFrame = frame;
        this.colorBy = colorBy;
        this.viewer.clock.currentTime = JulianDate.fromDate(new Date(frame.t));
        this.updateLight(frame.t);
        this.shapes.update(frame.values);
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
        if (a.feature) {
            if (color !== visual.color) {
                visual.color = color;
                this.restyle(a.feature.layer);
            }
        } else if (color !== visual.color && this.bodies?.ready) {
            // Until the primitive has built there is nothing to write to; retry next frame.
            visual.color = color;
            const value = Color.fromCssColorString(color);
            for (const id of visual.bodies) {
                // A block the section took away has nothing to colour.
                if (!this.drawnBodies.has(id)) continue;
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
        const before = this.selectedId;
        this.selectedId = id;
        for (const was of [before, id]) {
            const layer = was
                ? this.def.assets.find((x) => x.id === was)?.feature?.layer
                : undefined;
            if (layer) this.restyle(layer);
        }
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

    /** Flies to an asset; `arrived` runs when the flight ends or is cut short. */
    flyToAsset(id: string, arrived?: () => void): void {
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
                complete: arrived,
                cancel: arrived,
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
        for (const layer of this.mapImagery.values())
            layer.show = layers.context;
        for (const source of this.mapVectors.values())
            source.show = layers.context;
        this.shadowsWanted = layers.shadows;
        if (this.lastFrame) this.updateLight(this.lastFrame.t);
    }

    /** The colour actually written to an asset's geometry, for tests. */
    appliedColor(id: string): string | null {
        const visual = this.visuals.get(id);
        const instance = visual?.bodies.find((b) => this.drawnBodies.has(b));
        if (!instance || !this.bodies?.ready) return null;
        const [r, g, b] = this.bodies.getGeometryInstanceAttributes(instance)
            .color as Uint8Array;
        return `#${[r, g, b].map((c) => c!.toString(16).padStart(2, '0')).join('')}`;
    }

    /** How high an asset's first block now reaches, as cut, for tests; null when cut away. */
    bodyTop(id: string): number | null {
        const instance = this.visuals
            .get(id)
            ?.bodies.find((b) => this.drawnBodies.has(b));
        return instance ? this.drawnBodies.get(instance)!.top : null;
    }

    /** How many clipping planes a layer has, for tests. */
    clipPlanes(layer: string): number {
        const planes = this.layerPrimitives.get(layer)?.clippingPlanes;
        if (!planes?.enabled) return 0;
        let cutting = 0;
        for (let k = 0; k < planes.length; k++)
            if (planes.get(k).distance !== KEEP_ALL) cutting++;
        return cutting;
    }

    /** The colour a layer's feature is drawn with, for tests; null when unstyled. */
    featureColor(layer: string, id: string): string | null {
        const color = this.layerPrimitives.get(layer)?.style?.color as
            | {
                  evaluateColor(feature: {
                      getProperty(name: string): unknown;
                  }): Color;
              }
            | undefined;
        return color
            ? color.evaluateColor({ getProperty: () => id }).toCssHexString()
            : null;
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
        this.sketch.destroy();
        this.measure.destroy();
        this.gizmo.destroy();
        this.sight.destroy();
        this.shapes.destroy();
        this.pins.destroy();
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
