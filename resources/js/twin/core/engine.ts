import {
    compileTypes,
    initialCustom,
    POWERED,
    MODE,
    statusOf,
    stepCustom,
    type CompiledType,
} from './behaviour.ts';
import { distanceToVertex, pathSegments, pointAlong } from './geo.ts';
import type {
    ActiveFault,
    AssetDef,
    Command,
    FaultSpec,
    RouteDef,
    SimState,
    TwinDefinition,
    Values,
} from './types.ts';
import { State } from './types.ts';

/**
 * The physics model behind the twin: a microgrid power balance feeding a
 * refrigeration plant, integrated at a fixed step.
 *
 * Everything the model remembers lives in `SimState.values` and noise is a pure
 * function of (seed, step, channel). So a state can be cloned, sent over a
 * socket, or forked into the future, and a fork with no extra faults reproduces
 * the original run exactly.
 */

export const STEP_S = 5;
const HOUR_S = 3600;

export interface Model {
    def: TwinDefinition;
    byId: Map<string, AssetDef>;
    byKind: (kind: AssetDef['kind']) => AssetDef[];
    routes: Map<string, RouteDef>;
    /** Buses and consumers in feed order, so a parent is always visited first. */
    feedOrder: AssetDef[];
    /** Consumers that may be dropped under power shortage, shed first at the front. */
    sheddable: AssetDef[];
    mainBusId: string;
    /** Custom types' behaviour, by type id. */
    types: Map<string, CompiledType>;
}

export function compile(def: TwinDefinition): Model {
    const byId = new Map(def.assets.map((a) => [a.id, a]));
    const kinds = new Map<string, AssetDef[]>();
    const children = new Map<string, AssetDef[]>();
    for (const a of def.assets) {
        kinds.set(a.kind, [...(kinds.get(a.kind) ?? []), a]);
        if (a.fedBy)
            children.set(a.fedBy, [...(children.get(a.fedBy) ?? []), a]);
    }
    const main = (kinds.get('bus') ?? []).find((b) => !b.fedBy);
    if (!main) throw new Error('Twin needs a main bus: one bus with no fedBy');

    const feedOrder: AssetDef[] = [];
    const walk = (a: AssetDef) => {
        feedOrder.push(a);
        for (const c of children.get(a.id) ?? []) walk(c);
    };
    walk(main);

    return {
        def,
        byId,
        byKind: (kind) => kinds.get(kind) ?? [],
        routes: new Map(def.routes.map((r) => [r.id, r])),
        feedOrder,
        sheddable: def.assets
            .filter((a) => a.shedPriority !== undefined)
            .sort((a, b) => (b.shedPriority ?? 0) - (a.shedPriority ?? 0)),
        mainBusId: main.id,
        types: compileTypes(def),
    };
}

/** Deterministic noise in [-1, 1) for a channel at a step. */
export function noise(seed: number, step: number, channel: number): number {
    let h = Math.imul(seed ^ 0x9e3779b9, 0x85ebca6b);
    h = Math.imul(h ^ step, 0xc2b2ae35);
    h = Math.imul(h ^ channel, 0x27d4eb2f);
    h ^= h >>> 15;
    h = Math.imul(h, 0x165667b1);
    h ^= h >>> 13;
    return (h >>> 0) / 0x80000000 - 1;
}

/** Slowly varying noise: smoothly interpolates between samples `period` steps apart. */
function smoothNoise(
    seed: number,
    step: number,
    channel: number,
    period: number,
): number {
    const k = Math.floor(step / period);
    const f = (step % period) / period;
    const u = f * f * (3 - 2 * f);
    return noise(seed, k, channel) * (1 - u) + noise(seed, k + 1, channel) * u;
}

const clamp = (v: number, lo: number, hi: number) =>
    Math.min(hi, Math.max(lo, v));
const sum = (xs: number[]) => xs.reduce((a, b) => a + b, 0);
const p = (a: AssetDef, key: string, fallback: number) =>
    a.params?.[key] ?? fallback;

export function hashId(id: string): number {
    let h = 17;
    for (let i = 0; i < id.length; i++) h = Math.imul(h, 31) + id.charCodeAt(i);
    return h >>> 0;
}

export function hourOfDay(t: number, utcOffsetHours: number): number {
    const h = t / 3_600_000 + utcOffsetHours;
    return ((h % 24) + 24) % 24;
}

function faultActive(
    state: SimState,
    type: ActiveFault['type'],
    target?: string,
) {
    return state.faults.find(
        (f) =>
            f.type === type &&
            state.t >= f.startT &&
            (f.endT === undefined || state.t < f.endT) &&
            (target === undefined || f.target === target),
    );
}

export function initialState(model: Model, startT: number, seed = 7): SimState {
    const values: Values = {};
    for (const a of model.def.assets) {
        const set = (key: string, value: number) => {
            values[`${a.id}.${key}`] = value;
        };
        switch (a.kind) {
            case 'custom':
                // Behaviour comes from the type; other metrics hold until
                // readings arrive.
                initialCustom(a, model.types.get(a.type ?? ''), values);
                break;
            case 'coldstore':
                set('tempC', p(a, 'setpointC', 0));
                set('setpointC', p(a, 'setpointC', 0));
                set('excursionMin', 0);
                set('stockT', p(a, 'stockT', 0));
                break;
            case 'battery':
                set('socPct', p(a, 'initialSocPct', 70));
                set('lockout', 0);
                break;
            case 'genset':
                set('fuelPct', p(a, 'initialFuelPct', 90));
                set('timerS', 0);
                set('litres', 0);
                break;
            case 'chiller':
                set('wearMms', 0);
                set('maintenance', 0);
                break;
            case 'vehicle': {
                const route = model.routes.get(a.route ?? '');
                if (route)
                    set(
                        'dist',
                        p(a, 'startFrac', 0) * sum(pathSegments(route.path)),
                    );
                set('batteryPct', p(a, 'initialBatteryPct', 80));
                set('dwellS', 0);
                break;
            }
            case 'site':
                set('costTotal', 0);
                set('co2Total', 0);
                set('unservedKwh', 0);
                break;
            default:
                break;
        }
    }
    const state: SimState = { t: startT, step: 0, seed, values, faults: [] };
    // One step so that every metric exists before the first frame is read.
    return advance(model, state, STEP_S);
}

/** Applies whole fixed steps covering `seconds`; mutates and returns `state`. */
export function advance(
    model: Model,
    state: SimState,
    seconds: number,
): SimState {
    const steps = Math.max(1, Math.round(seconds / STEP_S));
    for (let i = 0; i < steps; i++) step(model, state);
    return state;
}

export function cloneState(state: SimState): SimState {
    return {
        ...state,
        values: { ...state.values },
        faults: state.faults.map((f) => ({ ...f })),
    };
}

export function applyCommand(
    model: Model,
    state: SimState,
    command: Command,
): void {
    switch (command.type) {
        case 'setpoint': {
            const a = model.byId.get(command.asset);
            if (a?.kind === 'custom') {
                const setpoint = model.types
                    .get(a.type ?? '')
                    ?.setpoints.get(command.metric ?? '');
                if (!setpoint || !Number.isFinite(command.value))
                    throw new Error(
                        `${command.asset} has no setpoint ${command.metric ?? ''}`,
                    );
                state.values[`${a.id}.${command.metric}`] = clamp(
                    command.value,
                    setpoint.min,
                    setpoint.max,
                );
                break;
            }
            if (a?.kind !== 'coldstore')
                throw new Error(`${command.asset} is not a cold store`);
            state.values[`${a.id}.setpointC`] = command.value;
            break;
        }
        case 'maintenance': {
            const a = model.byId.get(command.asset);
            if (a?.kind !== 'chiller')
                throw new Error(`${command.asset} is not a chiller`);
            state.values[`${a.id}.maintenance`] = command.on ? 1 : 0;
            break;
        }
        case 'scenario': {
            const scenario = model.def.scenarios.find(
                (s) => s.id === command.scenario,
            );
            if (!scenario)
                throw new Error(`Unknown scenario ${command.scenario}`);
            injectFaults(state, scenario.id, scenario.faults);
            break;
        }
        case 'clear-faults':
            state.faults = [];
            for (const c of model.byKind('chiller'))
                state.values[`${c.id}.wearMms`] = 0;
            break;
    }
}

function injectFaults(
    state: SimState,
    scenarioId: string,
    faults: readonly FaultSpec[],
): void {
    faults.forEach((spec, i) => {
        const startT = state.t + (spec.offsetS ?? 0) * 1000;
        state.faults.push({
            ...spec,
            id: `${scenarioId}#${i}@${state.t}`,
            startT,
            endT:
                spec.durationS === undefined
                    ? undefined
                    : startT + spec.durationS * 1000,
        });
    });
}

function step(model: Model, state: SimState): void {
    const { def } = model;
    const v = state.values;
    const dt = STEP_S;
    state.t += dt * 1000;
    state.step += 1;
    const hour = hourOfDay(state.t, def.utcOffsetHours);
    const stepsPerHour = HOUR_S / dt;

    // Weather -----------------------------------------------------------------
    const weather = model.byKind('weather')[0];
    const heatwave = faultActive(state, 'heatwave');
    const overcast = faultActive(state, 'cloud-cover');
    const ambient =
        def.climate.meanC +
        def.climate.swingC * Math.sin(((hour - 9) / 24) * 2 * Math.PI) +
        smoothNoise(state.seed, state.step, 1, stepsPerHour * 3) * 1.5 +
        (heatwave?.magnitude ?? 0);
    const baseCloud = clamp(
        0.25 + 0.3 * smoothNoise(state.seed, state.step, 2, stepsPerHour),
        0,
        1,
    );
    const cloud = overcast
        ? Math.max(baseCloud, overcast.magnitude ?? 0.9)
        : baseCloud;
    const sun = Math.max(0, Math.sin(((hour - 6) / 12.5) * Math.PI));
    const irradiance = 1000 * sun * (1 - 0.75 * cloud);
    // A breeze that picks up in the afternoon, mostly from the south-west.
    const wind = clamp(
        4 +
            2 * Math.sin(((hour - 9) / 24) * 2 * Math.PI) +
            2.5 * smoothNoise(state.seed, state.step, 3, stepsPerHour),
        0.3,
        20,
    );
    const windFrom =
        (((225 +
            45 * smoothNoise(state.seed, state.step, 4, stepsPerHour * 2)) %
            360) +
            360) %
        360;
    if (weather) {
        v[`${weather.id}.state`] = State.Running;
        v[`${weather.id}.ambientC`] = ambient;
        v[`${weather.id}.irradiance`] = irradiance;
        v[`${weather.id}.cloud`] = cloud;
        v[`${weather.id}.windMs`] = wind;
        v[`${weather.id}.windDeg`] = windFrom;
    }

    // Custom assets: transitions, then derived metrics, from last step's values
    for (const a of model.byKind('custom')) {
        const compiled = model.types.get(a.type ?? '');
        if (compiled)
            stepCustom(a, compiled, v, { dt, hour, ambientC: ambient });
    }

    // Refrigeration demand, from the previous step's store temperatures --------
    const chillers = model.byKind('chiller');
    const stores = model.byKind('coldstore');
    const chillerDemand = new Map<string, number>();
    for (const store of stores) {
        const temp = v[`${store.id}.tempC`] ?? 0;
        const setpoint = v[`${store.id}.setpointC`] ?? 0;
        const fraction = clamp(
            (temp - setpoint) / p(store, 'controlBandC', 1.5) + 0.45,
            0,
            1,
        );
        for (const c of chillers) {
            if (c.cools?.includes(store.id)) chillerDemand.set(c.id, fraction);
        }
    }

    // What every consumer would draw if energised ------------------------------
    const desired = new Map<string, number>();
    for (const a of model.byKind('building')) {
        const base = p(a, 'baseKw', 20);
        const open =
            hour >= 6 && hour < 19 ? Math.sin(((hour - 6) / 13) * Math.PI) : 0;
        const jitter =
            noise(state.seed, state.step, hashId(a.id)) * base * 0.03;
        desired.set(a.id, base + (p(a, 'peakKw', 50) - base) * open + jitter);
        v[`${a.id}.occupancy`] = Math.round(p(a, 'people', 0) * open);
    }
    for (const c of chillers) {
        const cop = chillerCop(c, ambient, v[`${c.id}.wearMms`] ?? 0);
        const available =
            !faultActive(state, 'trip', c.id) &&
            (v[`${c.id}.maintenance`] ?? 0) === 0;
        const demand = available ? (chillerDemand.get(c.id) ?? 0) : 0;
        desired.set(c.id, (demand * chillerCapacity(c, ambient)) / cop);
        v[`${c.id}.cop`] = cop;
    }

    // Vehicles move, and ask chargers for power --------------------------------
    const chargerRequest = new Map<string, number>();
    const chargerSessions = new Map<string, number>();
    for (const vehicle of model.byKind('vehicle')) {
        stepVehicle(model, state, vehicle, dt, chargerRequest, chargerSessions);
    }
    for (const c of model.byKind('charger')) {
        desired.set(c.id, chargerRequest.get(c.id) ?? 0);
        v[`${c.id}.sessions`] = chargerSessions.get(c.id) ?? 0;
    }
    for (const a of model.byKind('custom')) {
        if (a.fedBy && model.types.get(a.type ?? '')?.drawsPower)
            desired.set(a.id, Math.max(0, v[`${a.id}.powerKw`] ?? 0));
    }

    // Supply limits -------------------------------------------------------------
    const grid = model.byKind('grid')[0];
    const gridUp = !!grid && !faultActive(state, 'grid-outage');
    const mainBus = model.byId.get(model.mainBusId)!;
    const mainUp = !faultActive(state, 'trip', mainBus.id);

    const solarAssets = model.byKind('solar');
    const solarAvailable = new Map<string, number>();
    for (const s of solarAssets) {
        const usable = mainUp && !faultActive(state, 'trip', s.id);
        solarAvailable.set(
            s.id,
            usable ? (irradiance / 1000) * p(s, 'peakKw', 100) * 0.92 : 0,
        );
    }
    const solarTotal = sum([...solarAvailable.values()]);

    const genset = model.byKind('genset')[0];
    let gensetCapacity = 0;
    if (genset) {
        const timer = gridUp ? 0 : (v[`${genset.id}.timerS`] ?? 0) + dt;
        v[`${genset.id}.timerS`] = timer;
        const ready =
            (v[`${genset.id}.fuelPct`] ?? 0) > 0 &&
            !faultActive(state, 'genset-fail');
        if (!gridUp && ready && timer >= p(genset, 'startDelayS', 45)) {
            gensetCapacity = p(genset, 'maxKw', 300);
        }
    }

    const battery = model.byKind('battery')[0];
    const soc = battery ? (v[`${battery.id}.socPct`] ?? 0) : 0;
    const batteryUp = !!battery && !faultActive(state, 'trip', battery.id);
    let maxDischarge = 0;
    let maxCharge = 0;
    if (battery && batteryUp) {
        // Lockout hysteresis: an empty battery stays off until usefully recharged,
        // otherwise a trickle of solar flickers the whole site on and off.
        const minSoc = p(battery, 'minSocPct', 8);
        let lockout = (v[`${battery.id}.lockout`] ?? 0) > 0;
        if (soc <= minSoc) lockout = true;
        else if (soc >= minSoc + 5) lockout = false;
        v[`${battery.id}.lockout`] = lockout ? 1 : 0;
        maxDischarge = lockout ? 0 : p(battery, 'maxKw', 200);
        maxCharge = soc < 98 ? p(battery, 'maxKw', 200) : 0;
    }

    const importLimit = gridUp && grid ? p(grid, 'importLimitKw', 1e6) : 0;
    const supplyLimit = mainUp
        ? solarTotal + importLimit + maxDischarge + gensetCapacity
        : 0;

    // Energisation: walk down from the main bus, shedding by priority if short --
    const tripped = new Set(
        model.feedOrder
            .filter((a) => faultActive(state, 'trip', a.id))
            .map((a) => a.id),
    );
    const shed = new Set<string>();
    const energised = new Set<string>();
    const markEnergised = () => {
        energised.clear();
        if (!mainUp || supplyLimit <= 0) return;
        for (const a of model.feedOrder) {
            if (a.id === mainBus.id) energised.add(a.id);
            else if (
                !tripped.has(a.id) &&
                !shed.has(a.id) &&
                a.fedBy &&
                energised.has(a.fedBy)
            )
                energised.add(a.id);
        }
    };
    const servedLoad = () =>
        sum([...energised].map((id) => desired.get(id) ?? 0));
    markEnergised();
    for (const candidate of model.sheddable) {
        if (servedLoad() <= supplyLimit) break;
        shed.add(candidate.id);
        markEnergised();
    }
    // Shedding everything sheddable was not enough, so the site blacks out.
    if (servedLoad() > supplyLimit) energised.clear();

    const load = servedLoad();
    const totalDesired = sum(
        model.feedOrder.map((a) => desired.get(a.id) ?? 0),
    );
    const unserved = Math.max(0, totalDesired - load);

    // Dispatch: solar first, then battery peak shaving, grid, generator ---------
    const residual = load - solarTotal;
    let batteryKw = 0;
    let gridKw = 0;
    let gensetKw = 0;
    if (residual > 0) {
        if (gridUp) {
            const shaveAbove = battery
                ? p(battery, 'shaveAboveKw', Infinity)
                : Infinity;
            const peak =
                hour >= def.tariff.peakStartHour &&
                hour < def.tariff.peakEndHour;
            const floor = peak
                ? Math.min(shaveAbove, residual) * 0.4
                : shaveAbove;
            if (residual > floor)
                batteryKw = Math.min(maxDischarge, residual - floor);
            gridKw = Math.min(importLimit, residual - batteryKw);
            const remaining = residual - batteryKw - gridKw;
            if (remaining > 0)
                batteryKw = Math.min(maxDischarge, batteryKw + remaining);
        } else {
            gensetKw = Math.min(gensetCapacity, residual);
            batteryKw = Math.min(maxDischarge, residual - gensetKw);
        }
    } else if (residual < 0) {
        const surplus = -residual;
        const charge = Math.min(maxCharge, surplus);
        batteryKw = -charge;
        if (gridUp) gridKw = -(surplus - charge); // Export whatever the battery cannot take.
    }
    // Overnight top-up on cheap power.
    if (
        gridUp &&
        battery &&
        batteryUp &&
        batteryKw === 0 &&
        (hour < 5 || hour >= 22)
    ) {
        if (soc < p(battery, 'nightTargetSocPct', 80)) {
            const charge = Math.min(maxCharge, p(battery, 'nightChargeKw', 80));
            batteryKw = -charge;
            gridKw += charge;
        }
    }
    // The generator recharges the battery while islanded if it has headroom.
    if (
        !gridUp &&
        gensetCapacity > 0 &&
        batteryUp &&
        batteryKw <= 0 &&
        gensetKw < gensetCapacity
    ) {
        const extra = Math.max(
            0,
            Math.min(gensetCapacity - gensetKw, maxCharge + batteryKw),
        );
        gensetKw += extra;
        batteryKw -= extra;
    }

    // Write back power state ----------------------------------------------------
    // Anything solar could make but nobody can use (off-grid with a full battery).
    const solarUsed = load - gridKw - batteryKw - gensetKw;
    const curtailed = Math.max(0, solarTotal - solarUsed);
    let solarDelivered = 0;
    for (const s of solarAssets) {
        const available = solarAvailable.get(s.id) ?? 0;
        const lost = solarTotal > 0 ? curtailed * (available / solarTotal) : 0;
        const out = available - lost;
        solarDelivered += out;
        v[`${s.id}.powerKw`] = out;
        v[`${s.id}.curtailedKw`] = lost;
        v[`${s.id}.state`] = faultActive(state, 'trip', s.id)
            ? State.Fault
            : !mainUp
              ? State.Off
              : out > 0.5
                ? State.Running
                : State.Standby;
    }
    if (grid) {
        v[`${grid.id}.powerKw`] = gridKw;
        v[`${grid.id}.voltageV`] = gridUp
            ? 415 + noise(state.seed, state.step, 11) * 3
            : 0;
        v[`${grid.id}.state`] = gridUp ? State.Running : State.Fault;
    }
    if (battery) {
        const efficiency = batteryKw < 0 ? 0.94 : 1 / 0.94;
        const delta =
            ((batteryKw * efficiency * dt) /
                HOUR_S /
                p(battery, 'capacityKwh', 500)) *
            100;
        v[`${battery.id}.socPct`] = clamp(soc - delta, 0, 100);
        v[`${battery.id}.powerKw`] = batteryKw;
        v[`${battery.id}.state`] = !batteryUp
            ? State.Fault
            : Math.abs(batteryKw) > 0.5
              ? State.Running
              : State.Standby;
    }
    if (genset) {
        const litres =
            (gensetKw * p(genset, 'litresPerKwh', 0.27) * dt) / HOUR_S;
        v[`${genset.id}.fuelPct`] = clamp(
            (v[`${genset.id}.fuelPct`] ?? 0) -
                (litres / p(genset, 'tankLitres', 2000)) * 100,
            0,
            100,
        );
        v[`${genset.id}.powerKw`] = gensetKw;
        v[`${genset.id}.litres`] = (v[`${genset.id}.litres`] ?? 0) + litres;
        v[`${genset.id}.state`] = faultActive(state, 'genset-fail')
            ? State.Fault
            : gensetKw > 0.5
              ? State.Running
              : State.Standby;
    }

    // Bus loads, bottom-up over the feed tree.
    const busLoad = new Map<string, number>();
    for (const a of [...model.feedOrder].reverse()) {
        const own = energised.has(a.id) ? (desired.get(a.id) ?? 0) : 0;
        const total = own + (busLoad.get(a.id) ?? 0);
        if (a.fedBy) busLoad.set(a.fedBy, (busLoad.get(a.fedBy) ?? 0) + total);
        if (a.kind === 'bus') {
            v[`${a.id}.loadKw`] = total;
            v[`${a.id}.state`] = tripped.has(a.id)
                ? State.Fault
                : energised.has(a.id)
                  ? State.Running
                  : State.Off;
        }
    }
    for (const a of model.byKind('building')) {
        const on = energised.has(a.id);
        v[`${a.id}.powerKw`] = on ? (desired.get(a.id) ?? 0) : 0;
        v[`${a.id}.state`] = tripped.has(a.id)
            ? State.Fault
            : on
              ? State.Running
              : State.Off;
    }
    for (const a of model.byKind('custom')) {
        const compiled = model.types.get(a.type ?? '');
        const on = !a.fedBy || energised.has(a.id);
        v[`${a.id}.${POWERED}`] = on ? 1 : 0;
        if (!on && compiled?.drawsPower) v[`${a.id}.powerKw`] = 0;
        v[`${a.id}.state`] = tripped.has(a.id)
            ? State.Fault
            : on
              ? statusOf(compiled, v[`${a.id}.${MODE}`] ?? 0)
              : State.Off;
    }
    for (const a of model.byKind('charger')) {
        const on = energised.has(a.id);
        const power = on ? (desired.get(a.id) ?? 0) : 0;
        v[`${a.id}.powerKw`] = power;
        v[`${a.id}.state`] = tripped.has(a.id)
            ? State.Fault
            : !on
              ? State.Off
              : power > 0.5
                ? State.Running
                : State.Standby;
    }

    // Refrigeration plant --------------------------------------------------------
    const coolingByStore = new Map<string, number>();
    for (const c of chillers) {
        const on = energised.has(c.id);
        const trip = !!faultActive(state, 'trip', c.id);
        const maintenance = (v[`${c.id}.maintenance`] ?? 0) > 0;
        const demand =
            on && !trip && !maintenance ? (chillerDemand.get(c.id) ?? 0) : 0;
        const cooling = demand * chillerCapacity(c, ambient);
        v[`${c.id}.coolingKw`] = cooling;
        v[`${c.id}.powerKw`] = on ? (desired.get(c.id) ?? 0) : 0;
        v[`${c.id}.condenserC`] = ambient + (demand > 0 ? 6 + 10 * demand : 0);

        const wearFault = faultActive(state, 'bearing-wear', c.id);
        if (wearFault) {
            v[`${c.id}.wearMms`] =
                (v[`${c.id}.wearMms`] ?? 0) +
                ((wearFault.magnitude ?? 0.5) * dt) / HOUR_S;
        }
        const wear = v[`${c.id}.wearMms`] ?? 0;
        const jitter = noise(state.seed, state.step, hashId(c.id) + 3) * 0.08;
        v[`${c.id}.vibrationMms`] =
            demand > 0 ? 1.2 + wear + demand * 0.6 + jitter : 0;
        v[`${c.id}.state`] = trip
            ? State.Fault
            : maintenance
              ? State.Maintenance
              : !on
                ? State.Off
                : demand > 0.02
                  ? State.Running
                  : State.Standby;
        for (const storeId of c.cools ?? []) {
            coolingByStore.set(
                storeId,
                (coolingByStore.get(storeId) ?? 0) + cooling,
            );
        }
    }
    for (const store of stores) {
        stepStore(
            state,
            store,
            ambient,
            hour,
            coolingByStore.get(store.id) ?? 0,
            dt,
        );
    }

    // Site roll-up ----------------------------------------------------------------
    const site = model.byKind('site')[0];
    if (site) {
        const tariff = def.tariff;
        const peak = hour >= tariff.peakStartHour && hour < tariff.peakEndHour;
        const gridCost =
            Math.max(0, gridKw) *
            (peak ? tariff.peakPerKwh : tariff.offPeakPerKwh);
        const litresPerH = genset
            ? gensetKw * p(genset, 'litresPerKwh', 0.27)
            : 0;
        const costPerH = gridCost + litresPerH * tariff.dieselPerLitre;
        const co2PerH =
            Math.max(0, gridKw) * tariff.gridKgCo2PerKwh +
            litresPerH * tariff.dieselKgCo2PerLitre;
        v[`${site.id}.loadKw`] = load;
        v[`${site.id}.gridKw`] = gridKw;
        v[`${site.id}.solarKw`] = solarDelivered;
        v[`${site.id}.batteryKw`] = batteryKw;
        v[`${site.id}.gensetKw`] = gensetKw;
        v[`${site.id}.unservedKw`] = unserved;
        v[`${site.id}.islanded`] = gridUp ? 0 : 1;
        v[`${site.id}.costPerH`] = costPerH;
        v[`${site.id}.costTotal`] =
            (v[`${site.id}.costTotal`] ?? 0) + (costPerH * dt) / HOUR_S;
        v[`${site.id}.co2Total`] =
            (v[`${site.id}.co2Total`] ?? 0) + (co2PerH * dt) / HOUR_S;
        v[`${site.id}.unservedKwh`] =
            (v[`${site.id}.unservedKwh`] ?? 0) + (unserved * dt) / HOUR_S;
    }
}

function chillerCapacity(c: AssetDef, ambient: number): number {
    // Air-cooled plant loses capacity as the condenser air warms.
    return (
        p(c, 'capacityKw', 100) * clamp(1 - 0.012 * (ambient - 25), 0.5, 1.1)
    );
}

function chillerCop(c: AssetDef, ambient: number, wear: number): number {
    const base =
        p(c, 'copNominal', 2.5) * clamp(1 - 0.018 * (ambient - 25), 0.5, 1.2);
    // Bearing wear shows up first as vibration, then as lost efficiency.
    return base * clamp(1 - 0.05 * wear, 0.4, 1);
}

function stepStore(
    state: SimState,
    store: AssetDef,
    ambient: number,
    hour: number,
    cooling: number,
    dt: number,
): void {
    const v = state.values;
    const stuck = !!faultActive(state, 'door-stuck', store.id);
    const shift = hour >= 5 && hour < 22;
    const traffic = shift
        ? 0.5 + 0.5 * smoothNoise(state.seed, state.step, hashId(store.id), 24)
        : 0.05;
    const doorPct = stuck
        ? 100
        : clamp(traffic * p(store, 'doorDutyPct', 18), 0, 100);
    const temp = v[`${store.id}.tempC`] ?? 0;
    const envelope = p(store, 'uaKwPerK', 2) * (ambient - temp);
    const door = (doorPct / 100) * p(store, 'doorKwPerK', 3) * (ambient - temp);
    const heat = envelope + door + p(store, 'internalKw', 10);
    // Thermal mass is in kWh/K, so a 1 kW imbalance moves it 1/mass K per hour.
    const next =
        temp + ((heat - cooling) * dt) / HOUR_S / p(store, 'massKwhPerK', 100);
    const previous = v[`${store.id}.excursionMin`] ?? 0;
    const over = next > p(store, 'limitC', 0);
    const excursion = over
        ? previous + dt / 60
        : Math.max(0, previous - dt / 240);
    v[`${store.id}.tempC`] = next;
    v[`${store.id}.doorPct`] = doorPct;
    v[`${store.id}.heatKw`] = heat;
    v[`${store.id}.coolingKw`] = cooling;
    v[`${store.id}.excursionMin`] = excursion;
    // Stock is written down progressively once an excursion outlasts its grace period.
    const risk = clamp(
        (excursion - p(store, 'graceMin', 60)) / p(store, 'totalLossMin', 240),
        0,
        1,
    );
    v[`${store.id}.atRiskT`] = risk * (v[`${store.id}.stockT`] ?? 0);
    v[`${store.id}.state`] = over
        ? State.Fault
        : cooling > 0
          ? State.Running
          : State.Off;
}

function stepVehicle(
    model: Model,
    state: SimState,
    vehicle: AssetDef,
    dt: number,
    chargerRequest: Map<string, number>,
    chargerSessions: Map<string, number>,
): void {
    const v = state.values;
    const route = model.routes.get(vehicle.route ?? '');
    if (!route) return;
    const id = vehicle.id;
    const speed = p(vehicle, 'speedMps', 3);
    const length = sum(pathSegments(route.path));
    let dist = v[`${id}.dist`] ?? 0;
    let dwell = v[`${id}.dwellS`] ?? 0;
    let battery = v[`${id}.batteryPct`] ?? 0;
    let moving = false;

    if (dwell > 0) {
        dwell = Math.max(0, dwell - dt);
        const stop = route.stops.find(
            (s) => Math.abs(distanceToVertex(route.path, s.at) - dist) < 0.01,
        );
        if (stop?.charger && battery < 100) {
            const charger = model.byId.get(stop.charger);
            const rate = charger ? p(charger, 'perVehicleKw', 50) : 0;
            chargerRequest.set(
                stop.charger,
                (chargerRequest.get(stop.charger) ?? 0) + rate,
            );
            chargerSessions.set(
                stop.charger,
                (chargerSessions.get(stop.charger) ?? 0) + 1,
            );
            // Charger state is from the previous step; a dead charger adds nothing.
            const chargerState = v[`${stop.charger}.state`];
            if (
                chargerState === State.Running ||
                chargerState === State.Standby
            ) {
                battery = Math.min(
                    100,
                    battery +
                        ((rate * dt) / HOUR_S / p(vehicle, 'batteryKwh', 200)) *
                            100,
                );
            }
        }
    } else if (battery > 2) {
        const travelled = speed * dt;
        const before = dist;
        dist = (dist + travelled) % length;
        battery = Math.max(
            0,
            battery - (travelled / 1000) * p(vehicle, 'drainPctPerKm', 0.5),
        );
        moving = true;
        const stop = stopCrossed(route, before, travelled, length);
        if (stop) {
            dist = distanceToVertex(route.path, stop.at);
            dwell = stop.dwellS;
            moving = false;
        }
    }

    const pos = pointAlong(route.path, dist);
    v[`${id}.dist`] = dist;
    v[`${id}.dwellS`] = dwell;
    v[`${id}.batteryPct`] = battery;
    v[`${id}.x`] = pos.x;
    v[`${id}.y`] = pos.y;
    v[`${id}.heading`] = pos.heading;
    v[`${id}.speedKmh`] = moving ? speed * 3.6 : 0;
    v[`${id}.state`] =
        battery <= 2 ? State.Fault : moving ? State.Running : State.Standby;
}

function stopCrossed(
    route: RouteDef,
    before: number,
    travelled: number,
    length: number,
) {
    return route.stops.find((s) => {
        const ahead =
            (((distanceToVertex(route.path, s.at) - before) % length) +
                length) %
            length;
        return ahead > 0 && ahead <= travelled;
    });
}
