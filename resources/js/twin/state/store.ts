import { create } from 'zustand';
import { compile, type Model } from '../core/engine.ts';
import { runForecast, type Forecast } from '../core/forecast.ts';
import { History } from '../core/history.ts';
import type { Measurement, MeasureLock, MeasureTool } from '../core/measure.ts';
import type { Vec3 } from '../core/measure.ts';
import { activeAt, createRuleEngine, type RuleEngine } from '../core/rules.ts';
import type { Section } from '../core/section.ts';
import {
    aimAt,
    SENSOR_DEFAULTS,
    type Sensor,
    type Sight,
} from '../core/sight.ts';
import type {
    Alert,
    Command,
    FeatureRef,
    Frame,
    Placement,
    Point,
    SimState,
    TwinDefinition,
} from '../core/types.ts';
import { parseDefinition } from '../core/validate.ts';
import { GatewaySource } from '../sources/gateway.ts';
import { LocalSource } from '../sources/local.ts';
import type { SourceStatus, SourceTick, TwinSource } from '../sources/types.ts';

export type Tab = 'inspect' | 'alerts' | 'whatif';
export type ColorBy = 'status' | 'temperature' | 'power';
export type Basemap = 'offline' | 'osm' | 'custom';

export interface Layers {
    labels: boolean;
    flows: boolean;
    zones: boolean;
    context: boolean;
    shadows: boolean;
}

export interface TwinStore {
    loadError?: string;
    status: SourceStatus;
    statusDetail?: string;
    sourceLabel: string;
    sourceKind: 'simulator' | 'gateway';
    def?: TwinDefinition;
    model?: Model;
    /** Newest frame from the source. */
    live?: Frame;
    /** Frame on screen: the live frame, or a past one while time travelling. */
    view?: Frame;
    replayT: number | null;
    simState?: SimState;
    measured: string[];
    speed: number;
    alerts: Alert[];
    historyVersion: number;
    selectedId: string | null;
    tab: Tab;
    colorBy: ColorBy;
    basemap: Basemap;
    layers: Layers;
    forecast?: { result: Forecast; scenarioId: string };
    /** Bumped when the camera should frame the selection. */
    flyRequest: number;
    /** Bumped when the camera should pull back to the whole Earth. */
    globeRequest: number;
    showGlobe: () => void;

    select: (id: string | null, fly?: boolean) => void;
    setTab: (tab: Tab) => void;
    setColorBy: (c: ColorBy) => void;
    setBasemap: (b: Basemap) => void;
    toggleLayer: (k: keyof Layers) => void;
    setSpeed: (speed: number) => void;
    scrubTo: (t: number) => void;
    goLive: () => void;
    send: (command: Command) => void;
    acknowledge: (id: string) => void;
    predict: (scenarioId: string, horizonS: number) => void;
    clearForecast: () => void;
    loadTwinFile: (file: File) => Promise<void>;
    loadTwinById: (id: string) => Promise<void>;

    /** False for viewers: commands are refused and controls disabled. */
    canOperate: boolean;
    /** Loads recorded readings from the control plane; null when there is none. */
    recorded: RecordedLoader | null;
    /** The control plane's alert log, for a twin with a gateway; null otherwise. */
    serverAlerts: ServerAlerts | null;
    /** Placements being edited, shown live before they are saved. */
    drafts: Record<string, Placement | undefined>;
    /** The layer a ground click moves, while placing. */
    placingLayer: string | null;
    setDraft: (id: string, placement: Placement | undefined) => void;
    setPlacingLayer: (id: string | null) => void;
    /** A footprint or path being drawn on the ground, by the editor field that owns it. */
    sketch: Sketching | null;
    setSketch: (sketch: Sketching | null) => void;
    /** The editor field waiting for a click on a layer's feature, and what was clicked. */
    featurePick: { owner: string; picked?: FeatureRef } | null;
    setFeaturePick: (
        pick: { owner: string; picked?: FeatureRef } | null,
    ) => void;
    /** The measuring tool in use, if any; editing in the scene stops it. */
    measuring: { tool: MeasureTool; lock: MeasureLock } | null;
    setMeasuring: (
        measuring: { tool: MeasureTool; lock: MeasureLock } | null,
    ) => void;
    /** What the scene measured, as the pointer moves and clicks. */
    measurement: Measurement | null;
    setMeasurement: (measurement: Measurement | null) => void;
    /** The sight tools, when open. */
    sighting: Sighting | null;
    setSighting: (sighting: Sighting | null) => void;
    /** A click on a surface while a sight tool waits for one. */
    sightPicked: (point: Vec3) => void;
    /** The layer whose handles are shown in the scene, to drag it into place. */
    movingLayer: string | null;
    setMovingLayer: (id: string | null) => void;
    /** The site cut open to see inside; null when it's whole. */
    section: Section | null;
    setSection: (section: Section | null) => void;
    /** Bumped to clear the measurement and start again with the same tool. */
    measureClear: number;
    clearMeasurement: () => void;
}

/** A sensor's settings, kept while it's moved or before it's placed. */
export type SensorSettings = Omit<Sensor, 'at'>;

/**
 * The sight tools. A viewshed's sensor stands on the surface it was put on;
 * a line of sight runs between two surface points, raised by the eye's and
 * the target's heights.
 */
export interface Sighting {
    tool: 'viewshed' | 'line';
    settings: SensorSettings;
    placed: { at: Point; base: number } | null;
    /** What the next click in the scene does, for a viewshed. */
    next: 'place' | 'aim' | null;
    line: {
        from: Vec3 | null;
        to: Vec3 | null;
        eye: number;
        target: number;
        result: Sight | null;
    };
}

export const NEW_SIGHTING: Sighting = {
    tool: 'viewshed',
    settings: { ...SENSOR_DEFAULTS, height: 6 },
    placed: null,
    next: 'place',
    line: { from: null, to: null, eye: 1.7, target: 1.5, result: null },
};

/** Where a line of sight's ends are, raised to the eye and the target. */
export function sightEnds(line: Sighting['line']): [Vec3, Vec3] | null {
    if (!line.from || !line.to) return null;
    return [
        [line.from[0], line.from[1], line.from[2] + line.eye],
        [line.to[0], line.to[1], line.to[2] + line.target],
    ];
}

export interface Sketching {
    owner: string;
    points: Point[];
    /** One point only, as for a position: a click moves it. */
    single?: boolean;
}

/** A metric's recorded readings, averaged into time buckets (wall-clock ms). */
export interface Recorded {
    bucketMs: number;
    points: {
        t: number;
        mean: number;
        min: number;
        max: number;
        count: number;
    }[];
}

/**
 * A twin's alerts as its gateway reported them to the control plane, so
 * acknowledging one is shared and recorded with who did it.
 */
export interface ServerAlerts {
    /** Open alerts by `alertKey`, with who acknowledged each (null for nobody yet). */
    open: Record<string, { acknowledgedBy: string | null }>;
    /** Acknowledges the open alert for a rule and asset; absent while watching the simulator. */
    acknowledge?: (ruleId: string, assetId: string) => void;
    /** The twin's alert log page. */
    logUrl: string;
}

/** How an alert is named across the browser, the gateway and the control plane. */
export const alertKey = (ruleId: string, assetId: string) =>
    `${ruleId}\n${assetId}`;

export type RecordedLoader = (
    asset: string,
    metric: string,
    fromMs: number,
    toMs: number,
) => Promise<Recorded>;

const HISTORY_FRAMES = 1440;

/** Mutable, non-reactive runtime objects; React sees versions of them. */
let history = new History(HISTORY_FRAMES);
let rules: RuleEngine | null = null;
let source: TwinSource | null = null;

export const getHistory = () => history;

export const useTwin = create<TwinStore>((set, get) => ({
    status: 'connecting',
    sourceLabel: 'Simulator',
    sourceKind: 'simulator',
    replayT: null,
    measured: [],
    speed: 60,
    alerts: [],
    historyVersion: 0,
    selectedId: null,
    tab: 'inspect',
    colorBy: 'status',
    basemap: 'offline',
    layers: {
        labels: true,
        flows: true,
        zones: true,
        context: true,
        shadows: false,
    },
    flyRequest: 0,
    globeRequest: 0,
    canOperate: true,
    recorded: null,
    serverAlerts: null,
    drafts: {},
    placingLayer: null,
    setDraft: (id, placement) =>
        set((s) => ({ drafts: { ...s.drafts, [id]: placement } })),
    setPlacingLayer: (id) =>
        set((s) => ({
            placingLayer: id,
            measuring: id ? null : s.measuring,
            movingLayer: id ? null : s.movingLayer,
            sighting: id ? null : s.sighting,
        })),
    movingLayer: null,
    setMovingLayer: (id) =>
        set((s) => ({
            movingLayer: id,
            placingLayer: id ? null : s.placingLayer,
            measuring: id ? null : s.measuring,
            sighting: id ? null : s.sighting,
        })),
    sighting: null,
    setSighting: (sighting) =>
        set((s) => ({
            sighting,
            measuring: sighting ? null : s.measuring,
            placingLayer: sighting ? null : s.placingLayer,
            movingLayer: sighting ? null : s.movingLayer,
        })),
    sightPicked: (point) =>
        set((s) => {
            const sighting = s.sighting;
            if (!sighting) return {};
            if (sighting.tool === 'line') {
                const { line } = sighting;
                const starting = !line.from || line.to;
                return {
                    sighting: {
                        ...sighting,
                        line: {
                            ...line,
                            from: starting ? point : line.from,
                            to: starting ? null : point,
                            result: null,
                        },
                    },
                };
            }
            if (sighting.next === 'place')
                return {
                    sighting: {
                        ...sighting,
                        placed: { at: [point[0], point[1]], base: point[2] },
                        next: null,
                    },
                };
            if (sighting.next === 'aim' && sighting.placed) {
                const { at, base } = sighting.placed;
                const aim = aimAt(
                    [at[0], at[1], base + sighting.settings.height],
                    point,
                );
                return {
                    sighting: {
                        ...sighting,
                        settings: aim
                            ? { ...sighting.settings, ...aim }
                            : sighting.settings,
                        next: null,
                    },
                };
            }
            return {};
        }),
    sketch: null,
    setSketch: (sketch) =>
        set((s) => ({
            sketch,
            measuring: sketch ? null : s.measuring,
            movingLayer: sketch ? null : s.movingLayer,
            sighting: sketch ? null : s.sighting,
        })),
    featurePick: null,
    setFeaturePick: (featurePick) =>
        set((s) => ({
            featurePick,
            measuring: featurePick ? null : s.measuring,
        })),
    measuring: null,
    setMeasuring: (measuring) =>
        set((s) => ({
            measuring,
            measurement: null,
            movingLayer: measuring ? null : s.movingLayer,
            sighting: measuring ? null : s.sighting,
        })),
    measurement: null,
    setMeasurement: (measurement) => set({ measurement }),
    section: null,
    setSection: (section) => set({ section }),
    measureClear: 0,
    clearMeasurement: () =>
        set((s) => ({ measureClear: s.measureClear + 1, measurement: null })),
    showGlobe: () => set((s) => ({ globeRequest: s.globeRequest + 1 })),

    select: (id, fly = false) =>
        set((s) => ({
            selectedId: id,
            tab: id ? 'inspect' : s.tab,
            flyRequest: fly ? s.flyRequest + 1 : s.flyRequest,
        })),
    setTab: (tab) => set({ tab }),
    setColorBy: (colorBy) => set({ colorBy }),
    setBasemap: (basemap) => set({ basemap }),
    toggleLayer: (k) =>
        set((s) => ({ layers: { ...s.layers, [k]: !s.layers[k] } })),
    setSpeed: (speed) => {
        source?.setSpeed(speed);
        set({ speed });
    },
    scrubTo: (t) => {
        const frame = history.nearest(t);
        if (frame) set({ replayT: frame.t, view: frame });
    },
    goLive: () => set((s) => ({ replayT: null, view: s.live })),
    send: (command) => {
        if (!get().canOperate) return;
        source?.send(command);
        // A forecast describes the state it was forked from, and that just changed.
        set({ forecast: undefined });
    },
    acknowledge: (id) => {
        if (!rules) return;
        rules.acknowledge(id, get().live?.t ?? Date.now());
        set({ alerts: [...rules.alerts()] });
        const alert = rules.alerts().find((a) => a.id === id);
        if (alert && alert.clearedAt === undefined)
            get().serverAlerts?.acknowledge?.(alert.ruleId, alert.assetId);
    },
    predict: (scenarioId, horizonS) => {
        const { model, simState } = get();
        if (!model || !simState) return;
        const result = runForecast(
            model,
            simState,
            [{ type: 'scenario', scenario: scenarioId }],
            horizonS,
        );
        set({ forecast: { result, scenarioId } });
    },
    clearForecast: () => set({ forecast: undefined }),
    loadTwinFile: async (file) => {
        try {
            const def = parseDefinition(JSON.parse(await file.text()));
            startSource(new LocalSource(def));
        } catch (error) {
            set({
                loadError:
                    error instanceof Error ? error.message : String(error),
            });
        }
    },
    loadTwinById: async (id) => {
        const url = new URL(window.location.href);
        url.searchParams.set('twin', id);
        url.searchParams.delete('source');
        window.history.replaceState(null, '', url);
        await bootTwin(id);
    },
}));

function onTick(tick: SourceTick): void {
    let pushed = false;
    const evaluate = (frame: Frame) => {
        if (!rules) return false;
        const { raised, cleared } = rules.evaluate(frame);
        return raised.length > 0 || cleared.length > 0;
    };
    let alertsChanged = false;
    for (const frame of tick.history) {
        if (history.push(frame)) {
            pushed = true;
            if (evaluate(frame)) alertsChanged = true;
        }
    }
    if (evaluate(tick.live)) alertsChanged = true;
    useTwin.setState((s) => ({
        live: tick.live,
        view: s.replayT === null ? tick.live : s.view,
        simState: tick.state,
        measured: tick.measured,
        speed: tick.speed,
        historyVersion: pushed ? s.historyVersion + 1 : s.historyVersion,
        alerts: alertsChanged && rules ? [...rules.alerts()] : s.alerts,
    }));
}

function startSource(next: TwinSource): void {
    source?.stop();
    source = next;
    history = new History(HISTORY_FRAMES);
    rules = null;
    useTwin.setState({
        loadError: undefined,
        sourceLabel: next.label,
        sourceKind: next.kind,
        live: undefined,
        view: undefined,
        replayT: null,
        alerts: [],
        forecast: undefined,
        selectedId: null,
        def: undefined,
        model: undefined,
        historyVersion: 0,
    });
    next.start({
        onDefinition: (def) => {
            // A gateway re-sends the definition and its history on reconnect,
            // and sends a new one when the control plane saves a version.
            const current = useTwin.getState().def;
            if (current && JSON.stringify(current) === JSON.stringify(def))
                return;
            if (current) history = new History(HISTORY_FRAMES);
            rules = createRuleEngine(def);
            useTwin.setState((s) => ({
                def,
                model: compile(def),
                alerts: [],
                forecast: undefined,
                historyVersion: s.historyVersion + 1,
            }));
        },
        onTick,
        onStatus: (status, statusDetail) =>
            useTwin.setState({ status, statusDetail }),
    });
}

async function bootTwin(id: string): Promise<void> {
    try {
        const res = await fetch(`/samples/${encodeURIComponent(id)}.json`);
        // A dev server or SPA host answers unknown paths with index.html and a 200.
        if (!res.ok || !res.headers.get('content-type')?.includes('json')) {
            throw new Error(`Twin "${id}" not found`);
        }
        startSource(new LocalSource(parseDefinition(await res.json())));
    } catch (error) {
        useTwin.setState({
            loadError: error instanceof Error ? error.message : String(error),
            status: 'offline',
        });
    }
}

/**
 * Runs a definition handed over by the control plane. Called again with a new
 * version (after a save), it restarts the simulator on that version.
 */
export function runDefinition(
    def: unknown,
    options: { canOperate: boolean },
): void {
    useTwin.setState({
        canOperate: options.canOperate,
        drafts: {},
        placingLayer: null,
        movingLayer: null,
        sighting: null,
        measuring: null,
        measurement: null,
        section: null,
        sketch: null,
        featurePick: null,
    });
    try {
        startSource(new LocalSource(parseDefinition(def)));
    } catch (error) {
        useTwin.setState({
            loadError: error instanceof Error ? error.message : String(error),
            status: 'offline',
        });
    }
}

/**
 * Follows the twin's gateway instead of simulating in the browser. The URL
 * carries the ticket the control plane signed; the gateway enforces the same
 * operate permission as `canOperate`.
 */
export function runGateway(
    url: string,
    options: { canOperate: boolean },
): void {
    useTwin.setState({
        canOperate: options.canOperate,
        drafts: {},
        placingLayer: null,
        movingLayer: null,
        sighting: null,
        measuring: null,
        measurement: null,
        section: null,
    });
    startSource(new GatewaySource(url));
}

/** Stops the running source when the viewer page is left. */
export function stopTwin(): void {
    source?.stop();
    source = null;
    useTwin.setState({
        def: undefined,
        model: undefined,
        live: undefined,
        view: undefined,
        canOperate: true,
        recorded: null,
        serverAlerts: null,
        drafts: {},
        placingLayer: null,
        movingLayer: null,
        sighting: null,
        measuring: null,
        measurement: null,
        section: null,
    });
}

/** Reads `?twin=` and `?gateway=` to choose what to run. */
export function boot(): void {
    const params = new URLSearchParams(window.location.search);
    const gateway = params.get('gateway');
    if (gateway) {
        try {
            startSource(new GatewaySource(gateway));
        } catch {
            useTwin.setState({
                loadError: `Bad gateway URL: ${gateway}`,
                status: 'offline',
            });
        }
        return;
    }
    void bootTwin(params.get('twin') ?? 'northshore');
}

export function alertsAt(alerts: Alert[], t: number | undefined): Alert[] {
    if (t === undefined) return [];
    return alerts.filter((a) => activeAt(a, t));
}
