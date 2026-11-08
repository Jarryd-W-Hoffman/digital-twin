import { create } from 'zustand';
import { compile, type Model } from '../core/engine.ts';
import { runForecast, type Forecast } from '../core/forecast.ts';
import { History } from '../core/history.ts';
import { activeAt, createRuleEngine, type RuleEngine } from '../core/rules.ts';
import type {
    Alert,
    Command,
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
    /** Placements being edited, shown live before they are saved. */
    drafts: Record<string, Placement | undefined>;
    /** The layer a ground click moves, while placing. */
    placingLayer: string | null;
    setDraft: (id: string, placement: Placement | undefined) => void;
    setPlacingLayer: (id: string | null) => void;
    /** A footprint or path being drawn on the ground, by the editor field that owns it. */
    sketch: Sketching | null;
    setSketch: (sketch: Sketching | null) => void;
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
    drafts: {},
    placingLayer: null,
    setDraft: (id, placement) =>
        set((s) => ({ drafts: { ...s.drafts, [id]: placement } })),
    setPlacingLayer: (id) => set({ placingLayer: id }),
    sketch: null,
    setSketch: (sketch) => set({ sketch }),
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
        sketch: null,
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
        drafts: {},
        placingLayer: null,
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
