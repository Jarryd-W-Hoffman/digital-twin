import type { AssetDef, AssetKind, TwinDefinition } from './types.ts';

export interface MetricDef {
    key: string;
    label: string;
    unit: string;
    decimals: number;
    /** Internal integrator or position; not listed in the inspector. */
    hidden?: boolean;
}

const m = (
    key: string,
    label: string,
    unit: string,
    decimals = 0,
    hidden = false,
): MetricDef => ({
    key,
    label,
    unit,
    decimals,
    hidden,
});

const STATE = m('state', 'State', '', 0, true);

export const METRICS: Record<AssetKind, MetricDef[]> = {
    // Custom assets take their metrics from their type; see metricsFor.
    custom: [STATE],
    site: [
        m('loadKw', 'Site load', 'kW'),
        m('gridKw', 'Grid import', 'kW'),
        m('solarKw', 'Solar', 'kW'),
        m('batteryKw', 'Battery', 'kW'),
        m('gensetKw', 'Generator', 'kW'),
        m('unservedKw', 'Unserved load', 'kW'),
        m('costPerH', 'Run cost', '$/h', 2),
        m('costTotal', 'Cost accrued', '$'),
        m('co2Total', 'CO₂ accrued', 'kg'),
        m('islanded', 'Islanded', '', 0, true),
    ],
    weather: [
        STATE,
        m('ambientC', 'Ambient', '°C', 1),
        m('irradiance', 'Irradiance', 'W/m²'),
        m('cloud', 'Cloud cover', '', 2),
    ],
    grid: [STATE, m('powerKw', 'Import', 'kW'), m('voltageV', 'Voltage', 'V')],
    solar: [
        STATE,
        m('powerKw', 'Output', 'kW'),
        m('curtailedKw', 'Curtailed', 'kW'),
    ],
    battery: [
        STATE,
        m('socPct', 'State of charge', '%', 1),
        m('powerKw', 'Power (+ discharge)', 'kW'),
    ],
    genset: [
        STATE,
        m('powerKw', 'Output', 'kW'),
        m('fuelPct', 'Fuel', '%', 1),
        m('timerS', 'Start timer', 's', 0, true),
    ],
    bus: [STATE, m('loadKw', 'Load', 'kW')],
    chiller: [
        STATE,
        m('powerKw', 'Electrical', 'kW', 1),
        m('coolingKw', 'Cooling', 'kW', 1),
        m('cop', 'COP', '', 2),
        m('condenserC', 'Condenser', '°C', 1),
        m('vibrationMms', 'Vibration', 'mm/s', 2),
        m('wearMms', 'Bearing wear', 'mm/s', 2, true),
        m('maintenance', 'Maintenance', '', 0, true),
    ],
    coldstore: [
        STATE,
        m('tempC', 'Temperature', '°C', 1),
        m('setpointC', 'Setpoint', '°C', 1),
        m('doorPct', 'Door open', '%'),
        m('heatKw', 'Heat load', 'kW', 1),
        m('coolingKw', 'Cooling', 'kW', 1),
        m('excursionMin', 'Time over limit', 'min'),
        m('stockT', 'Stock', 't'),
        m('atRiskT', 'Stock at risk', 't', 1),
    ],
    building: [
        STATE,
        m('powerKw', 'Power', 'kW', 1),
        m('occupancy', 'Occupancy', 'people'),
    ],
    charger: [
        STATE,
        m('powerKw', 'Charging', 'kW', 1),
        m('sessions', 'Vehicles', ''),
    ],
    vehicle: [
        STATE,
        m('speedKmh', 'Speed', 'km/h', 1),
        m('batteryPct', 'Battery', '%', 1),
        m('x', 'East', 'm', 1, true),
        m('y', 'North', 'm', 1, true),
        m('heading', 'Heading', 'rad', 3, true),
        m('dist', 'Route distance', 'm', 1, true),
        m('dwellS', 'Dwell', 's', 0, true),
    ],
};

/** The headline figure shown on a 3D label and in the asset tree. */
export const PRIMARY_METRIC: Record<AssetKind, string> = {
    custom: 'state',
    site: 'loadKw',
    weather: 'ambientC',
    grid: 'powerKw',
    solar: 'powerKw',
    battery: 'socPct',
    genset: 'powerKw',
    bus: 'loadKw',
    chiller: 'powerKw',
    coldstore: 'tempC',
    building: 'powerKw',
    charger: 'powerKw',
    vehicle: 'batteryPct',
};

export function metricDef(kind: AssetKind, key: string): MetricDef | undefined {
    return METRICS[kind].find((d) => d.key === key);
}

export function typeOf(def: TwinDefinition | undefined, asset: AssetDef) {
    return asset.kind === 'custom'
        ? def?.types?.find((t) => t.id === asset.type)
        : undefined;
}

/** An asset's metrics, including those a custom type declares. */
export function metricsFor(
    def: TwinDefinition | undefined,
    asset: AssetDef,
): MetricDef[] {
    const type = typeOf(def, asset);
    if (!type) return METRICS[asset.kind];
    return [
        ...type.metrics.map((x) =>
            m(x.key, x.label ?? x.key, x.unit, x.decimals ?? 1),
        ),
        STATE,
    ];
}

export function primaryMetricFor(
    def: TwinDefinition | undefined,
    asset: AssetDef,
): string {
    return typeOf(def, asset)?.primary ?? PRIMARY_METRIC[asset.kind];
}

export function metricDefFor(
    def: TwinDefinition | undefined,
    asset: AssetDef,
    key: string,
): MetricDef | undefined {
    return metricsFor(def, asset).find((d) => d.key === key);
}

export function formatMetric(
    def: MetricDef | undefined,
    value: number | undefined,
): string {
    if (value === undefined || !Number.isFinite(value)) return '—';
    const decimals = def?.decimals ?? 0;
    // Rounding must not leave "-0" behind.
    const rounded = Number(value.toFixed(decimals)) || 0;
    const text = rounded.toLocaleString('en-AU', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
    const unit = def?.unit ?? '';
    if (unit.startsWith('$')) return `$${text}${unit.slice(1)}`;
    return unit ? `${text} ${unit}` : text;
}
