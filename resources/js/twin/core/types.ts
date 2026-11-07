/**
 * The twin definition is plain JSON: a platform user describes a site in
 * metres relative to an origin, and everything else (physics, 3D scene, asset
 * tree, rules) is derived from it. Nothing here depends on the DOM or Cesium,
 * so it runs in the browser and in the Node gateway.
 */

export type AssetKind =
    | 'custom'
    | 'site'
    | 'weather'
    | 'grid'
    | 'solar'
    | 'battery'
    | 'genset'
    | 'bus'
    | 'chiller'
    | 'coldstore'
    | 'building'
    | 'charger'
    | 'vehicle';

export type Point = [x: number, y: number];

export interface AssetDef {
    id: string;
    name: string;
    kind: AssetKind;
    zone: string;
    /** Polygon in metres east/north of the origin. */
    footprint?: Point[];
    /** Point location for assets without a footprint. */
    position?: Point;
    height?: number;
    baseHeight?: number;
    /** Id of the bus (or supply) this asset is powered from. */
    fedBy?: string;
    /** Cold stores this chiller serves. */
    cools?: string[];
    /** Vehicles: the route they run. */
    route?: string;
    /** Lower number is shed last when the site is short of power. */
    shedPriority?: number;
    params?: Record<string, number>;
    /** Custom assets: the id of a type in the definition's `types`. */
    type?: string;
    /**
     * The element of a model or tileset that is this asset, such as a pump in
     * an IFC model: it takes the asset's colour, and clicking it selects the
     * asset. The asset's own body isn't drawn.
     */
    feature?: FeatureRef;
}

/** A feature of a context layer: the layer's id, and the feature's id property. */
export interface FeatureRef {
    layer: string;
    id: string;
}

/** The status a custom type's state shows as, in colour and in the inspector. */
export type StatusName =
    | 'running'
    | 'standby'
    | 'off'
    | 'fault'
    | 'maintenance';

export interface TypeMetricDef {
    key: string;
    label?: string;
    unit: string;
    decimals?: number;
    default?: number;
    /**
     * Worked out every step from other values, in the order the metrics are
     * listed; it can read its own last value, so it can integrate.
     */
    expr?: string;
    /** An operator may set this metric, between min and max. */
    setpoint?: { min: number; max: number; step?: number };
}

export interface TypeStateDef {
    /** Also a name in expressions: 1 while the asset is in this state. */
    id: string;
    label?: string;
    status: StatusName;
}

export interface TypeTransitionDef {
    /** A state id, or `*` for any other state. */
    from: string;
    to: string;
    /** An expression; the first transition whose `when` holds is taken. */
    when: string;
}

/**
 * Equipment the simulator has no physics for, described as data: its metrics,
 * how some of them follow from others, the states it moves between, and what
 * operators may set. Metrics without an `expr` hold until readings arrive.
 */
export interface AssetTypeDef {
    id: string;
    name: string;
    /** The metric shown in lists and labels. */
    primary: string;
    metrics: TypeMetricDef[];
    /** The first state is where an asset starts. */
    states?: TypeStateDef[];
    transitions?: TypeTransitionDef[];
    visual: { color: string; shape: 'box' | 'cylinder'; height?: number };
}

export interface RouteStop {
    /** Index into the route's path. */
    at: number;
    dwellS: number;
    /** Id of the charger available at this stop. */
    charger?: string;
}

export interface RouteDef {
    id: string;
    name: string;
    path: Point[];
    stops: RouteStop[];
}

export interface ZoneDef {
    id: string;
    name: string;
    footprint: Point[];
}

export type Comparison = 'gt' | 'lt';
export type Severity = 'info' | 'warning' | 'critical';

/**
 * When to raise an alert. A threshold rule compares `metric` (or how much it
 * changed over `change.overS`) with `threshold`; an expression rule raises
 * while `when` holds instead.
 */
export interface RuleDef {
    id: string;
    name: string;
    severity: Severity;
    /** Applies to every asset of this kind, or to one named asset. */
    kind?: AssetKind;
    asset?: string;
    metric?: string;
    op?: Comparison;
    threshold?: number;
    /** Compare the change in `metric` over this many seconds, not its value. */
    change?: { overS: number };
    /** An expression over the asset's metrics; raises while it holds. */
    when?: string;
    /** Clears once this holds; without it, once `when` stops holding. */
    clearWhen?: string;
    /** Must stay breached this long before raising. */
    forS?: number;
    /** Value past which the alert clears; defaults to the threshold (no hysteresis). */
    clearAt?: number;
    message: string;
}

export type FaultType =
    | 'grid-outage'
    | 'trip'
    | 'door-stuck'
    | 'heatwave'
    | 'bearing-wear'
    | 'genset-fail'
    | 'cloud-cover';

export interface FaultSpec {
    type: FaultType;
    target?: string;
    /** Heatwave: extra degrees. Bearing wear: mm/s per hour. Cloud cover: 0..1. */
    magnitude?: number;
    /** Delay from scenario start. */
    offsetS?: number;
    /** Omit to last until cleared. */
    durationS?: number;
}

export interface ScenarioDef {
    id: string;
    name: string;
    description: string;
    faults: FaultSpec[];
}

export interface Tariff {
    offPeakPerKwh: number;
    peakPerKwh: number;
    peakStartHour: number;
    peakEndHour: number;
    dieselPerLitre: number;
    gridKgCo2PerKwh: number;
    dieselKgCo2PerLitre: number;
}

/** Where a model or local-coordinate tileset sits, in metres from the origin. */
export interface Placement {
    east: number;
    north: number;
    up: number;
    /** Clockwise from north. */
    headingDeg: number;
    scale: number;
}

export interface TilesetDef {
    id: string;
    name: string;
    url: string;
    /** Omit for a georeferenced tileset; set to place one built in local metres. */
    placement?: Placement;
    /** The control plane's upload this layer came from. */
    assetId?: string;
    /** The feature property that names each feature, for binding assets; default `id`. */
    featureProperty?: string;
}

export interface ModelDef {
    id: string;
    name: string;
    /** A glTF 2.0 binary (.glb). */
    url: string;
    placement: Placement;
    assetId?: string;
    /** The feature property that names each feature, for binding assets; default `id`. */
    featureProperty?: string;
}

/**
 * Where a metric's readings come from over MQTT. The gateway subscribes to
 * `topic` and turns each message into a reading of `asset`.`metric`:
 * the number at `path` in a JSON payload (or the payload itself, or its
 * `value`), times `scale`, plus `offset`.
 */
export interface TelemetryMapping {
    /** An MQTT topic filter: `+` matches one level, a final `#` the rest. */
    topic: string;
    asset: string;
    metric: string;
    /** Dotted keys into a JSON payload, such as `data.tempC`. */
    path?: string;
    scale?: number;
    offset?: number;
    /** How long each reading holds, in simulated seconds. */
    ttlS?: number;
}

/** A quantized-mesh terrain tileset: the URL of its layer.json. */
export interface TerrainDef {
    name?: string;
    url: string;
    /** The project library upload it came from. */
    assetId?: string;
}

/**
 * A figure worked out from the twin's values: an expression that reads
 * other assets as `{asset.metric}`, with how to show it.
 */
export interface ReadoutDef {
    label: string;
    value: string;
    unit?: string;
    decimals?: number;
}

/** A section of the site summary, with its figures and an optional chart. */
export interface PanelDef {
    title: string;
    items?: ReadoutDef[];
    /** Charted over the history the twin keeps; its label defaults to the title. */
    chart?: Omit<ReadoutDef, 'label'> & { label?: string };
}

export interface TwinDefinition {
    id: string;
    name: string;
    description: string;
    origin: { lon: number; lat: number; height?: number };
    /** Hours east of UTC; drives the daily weather and tariff cycle. */
    utcOffsetHours: number;
    climate: { meanC: number; swingC: number };
    camera: { headingDeg: number; pitchDeg: number; rangeM: number };
    tariff: Tariff;
    zones: ZoneDef[];
    assets: AssetDef[];
    routes: RouteDef[];
    rules: RuleDef[];
    scenarios: ScenarioDef[];
    tilesets?: TilesetDef[];
    models?: ModelDef[];
    /** Quantized-mesh terrain the site sits on; the ellipsoid without it. */
    terrain?: TerrainDef;
    types?: AssetTypeDef[];
    telemetry?: TelemetryMapping[];
    /** Replaces the built-in figures in the top bar. */
    kpis?: ReadoutDef[];
    /** Added to the site summary in the inspector. */
    panels?: PanelDef[];
}

/** Numeric state codes stored in each asset's `state` metric. */
export const State = {
    Off: 0,
    Running: 1,
    Standby: 2,
    Maintenance: 3,
    Fault: 4,
} as const;
export type StateCode = (typeof State)[keyof typeof State];

export const STATE_LABEL: Record<number, string> = {
    0: 'De-energised',
    1: 'Running',
    2: 'Standby',
    3: 'Maintenance',
    4: 'Fault',
};

/** A flat map of `assetId.metric` to value; the unit of exchange everywhere. */
export type Values = Record<string, number>;

export interface Frame {
    t: number;
    values: Values;
}

export interface ActiveFault extends FaultSpec {
    id: string;
    startT: number;
    endT?: number;
}

/** Complete, JSON-serialisable simulator state; forking a future is a clone. */
export interface SimState {
    t: number;
    step: number;
    seed: number;
    values: Values;
    faults: ActiveFault[];
}

export type Command =
    /** A cold store's setpoint, or a custom asset's setpoint `metric`. */
    | { type: 'setpoint'; asset: string; value: number; metric?: string }
    | { type: 'maintenance'; asset: string; on: boolean }
    | { type: 'scenario'; scenario: string }
    | { type: 'clear-faults' };

export interface Alert {
    id: string;
    ruleId: string;
    assetId: string;
    severity: Severity;
    message: string;
    raisedAt: number;
    clearedAt?: number;
    ackedAt?: number;
    /** Value at the moment the alert was raised. */
    value: number;
}
