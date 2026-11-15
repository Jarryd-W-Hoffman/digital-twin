import '@fontsource/ibm-plex-sans/400.css';
import '@fontsource/ibm-plex-sans/500.css';
import '@fontsource/ibm-plex-sans/600.css';
import '@fontsource/ibm-plex-mono/400.css';
import '../../css/welcome.css';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    Boxes,
    ChevronsLeftRight,
    Code,
    FlaskConical,
    Globe,
    KeyRound,
    Layers,
    Map,
    MessageSquareWarning,
    Mountain,
    Radio,
    Scan,
    ScrollText,
    Server,
    Share2,
    ShieldCheck,
    Users,
    WifiOff,
} from 'lucide-react';
import type {
    CSSProperties,
    KeyboardEvent,
    PointerEvent,
    ReactNode,
    RefObject,
} from 'react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, demo, login, register } from '@/routes';

const IMAGES = '/images/landing';

const LESS_MOTION = '(prefers-reduced-motion: reduce)';

/** True when the visitor asked for less motion. */
function useReducedMotion(): boolean {
    return useSyncExternalStore(
        (change) => {
            const query = window.matchMedia(LESS_MOTION);
            query.addEventListener('change', change);
            return () => query.removeEventListener('change', change);
        },
        () => window.matchMedia(LESS_MOTION).matches,
        () => false,
    );
}

/** Fades sections in as they scroll into view. */
function useReveal(root: RefObject<HTMLElement | null>): void {
    useEffect(() => {
        const elements =
            root.current?.querySelectorAll<HTMLElement>('.l-reveal');
        if (!elements || !('IntersectionObserver' in window)) {
            elements?.forEach((el) => (el.dataset.shown = 'true'));
            return;
        }
        const observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        (entry.target as HTMLElement).dataset.shown = 'true';
                        observer.unobserve(entry.target);
                    }
                }
            },
            { rootMargin: '0px 0px -10% 0px', threshold: 0.08 },
        );
        elements.forEach((el) => observer.observe(el));
        return () => observer.disconnect();
    }, [root]);
}

/** A globe of meridians that turns, behind the hero. */
function TurningGlobe({ still }: { still: boolean }) {
    const meridians = Array.from({ length: 6 }, (_, i) => i);
    return (
        <svg className="l-globe" viewBox="-110 -110 220 220" aria-hidden>
            <defs>
                <radialGradient id="l-globe-fill" cx="35%" cy="30%">
                    <stop
                        offset="0%"
                        stopColor="currentColor"
                        stopOpacity="0.2"
                    />
                    <stop
                        offset="100%"
                        stopColor="currentColor"
                        stopOpacity="0"
                    />
                </radialGradient>
            </defs>
            <circle r="100" fill="url(#l-globe-fill)" />
            <g fill="none" stroke="currentColor" strokeWidth="0.5">
                <circle r="100" strokeWidth="0.8" />
                {[-60, -30, 0, 30, 60].map((lat) => {
                    const y = 100 * Math.sin((lat * Math.PI) / 180);
                    const rx = 100 * Math.cos((lat * Math.PI) / 180);
                    return <ellipse key={lat} cy={y} rx={rx} ry={rx * 0.12} />;
                })}
                {meridians.map((i) => (
                    <ellipse
                        key={i}
                        rx={100 * Math.abs(Math.cos((i * Math.PI) / 6))}
                        ry="100"
                    >
                        {!still && (
                            <animate
                                attributeName="rx"
                                values="100;0;100"
                                keyTimes="0;0.5;1"
                                dur="24s"
                                begin={`${-i * 4}s`}
                                repeatCount="indefinite"
                                calcMode="spline"
                                keySplines="0.4 0 0.6 1;0.4 0 0.6 1"
                            />
                        )}
                    </ellipse>
                ))}
            </g>
            <g fill="#ffc94a">
                {[
                    [-38, -22],
                    [26, 18],
                    [52, -46],
                ].map(([x, y], i) => (
                    <circle key={i} cx={x} cy={y} r="2.4">
                        {!still && (
                            <animate
                                attributeName="r"
                                values="2.4;4;2.4"
                                dur="3s"
                                begin={`${i}s`}
                                repeatCount="indefinite"
                            />
                        )}
                    </circle>
                ))}
            </g>
        </svg>
    );
}

/** A reading that drifts, as one from the sample twin does. */
function useDrifting(start: number, step: number, still: boolean): number[] {
    const [series, setSeries] = useState(() =>
        Array.from({ length: 24 }, (_, i) => start + Math.sin(i / 2.5) * step),
    );
    useEffect(() => {
        if (still) return;
        const timer = window.setInterval(() => {
            setSeries((s) => {
                const last = s[s.length - 1];
                const pull = (start - last) * 0.3;
                const next = last + pull + (Math.random() - 0.5) * step * 1.4;
                return [...s.slice(1), next];
            });
        }, 1600);
        return () => window.clearInterval(timer);
    }, [start, step, still]);
    return series;
}

function Sparkline({ series }: { series: number[] }) {
    const min = Math.min(...series);
    const max = Math.max(...series);
    const span = max - min || 1;
    const points = series
        .map(
            (v, i) =>
                `${(i / (series.length - 1)) * 74},${24 - ((v - min) / span) * 22}`,
        )
        .join(' ');
    return (
        <svg className="l-spark" viewBox="0 0 74 26" aria-hidden>
            <polyline
                points={points}
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
            />
        </svg>
    );
}

function Hero({ still, primary }: { still: boolean; primary: ReactNode }) {
    const shot = useRef<HTMLDivElement>(null);
    const freezer = useDrifting(-19.9, 0.15, still);
    const solar = useDrifting(445, 12, still);

    const tilt = (event: PointerEvent<HTMLDivElement>) => {
        if (still || event.pointerType !== 'mouse' || !shot.current) return;
        const box = event.currentTarget.getBoundingClientRect();
        const x = (event.clientX - box.left) / box.width - 0.5;
        const y = (event.clientY - box.top) / box.height - 0.5;
        shot.current.style.setProperty('--tilt-y', String(x * 5));
        shot.current.style.setProperty('--tilt-x', String(-y * 4));
    };
    const settle = () => {
        shot.current?.style.setProperty('--tilt-x', '0');
        shot.current?.style.setProperty('--tilt-y', '0');
    };

    return (
        <section className="l-hero">
            <div className="l-hero-bg" />
            <div className="l-grid-bg" />
            <div className="l-wrap" style={{ position: 'relative' }}>
                <TurningGlobe still={still} />
                <span className="l-eyebrow">
                    <b>Digital twins</b> for factories, mines, depots and
                    surveyed ground
                </span>
                <h1>
                    Your whole site, <em>live</em> on a real globe.
                </h1>
                <p className="l-lede">
                    Bring in scans, drone surveys, building models and drawings.
                    Connect the sensors and PLCs already on site. Meridian
                    builds a twin your team can watch, question and act on, and
                    keeps every change as a version.
                </p>
                <div className="l-cta-row">
                    <Link
                        href={demo()}
                        className="l-btn l-btn-primary l-btn-lg"
                    >
                        Explore the sample twins
                        <ArrowRight className="l-arrow" />
                    </Link>
                    {primary}
                </div>
                <p className="l-hero-note">
                    The samples run in your browser, no account needed.
                </p>

                <div
                    className="l-stage"
                    onPointerMove={tilt}
                    onPointerLeave={settle}
                >
                    <div className="l-stage-glow" />
                    <div className="l-shot" ref={shot}>
                        <img
                            src={`${IMAGES}/overview.webp`}
                            width={1600}
                            height={950}
                            alt="A twin of a cold chain campus: the buildings in 3D with live readings on each, energy flowing between them, and charts of site load and the cold stores beside the scene."
                            fetchPriority="high"
                        />
                    </div>
                    <div className="l-chip l-chip-a" aria-hidden>
                        <span className="l-dot" />
                        <span>
                            Freezer hall
                            <strong className="l-mono">
                                {freezer[freezer.length - 1].toFixed(1)} °C
                            </strong>
                        </span>
                    </div>
                    <div className="l-chip l-chip-b" aria-hidden>
                        <span>
                            Solar
                            <strong className="l-mono">
                                {Math.round(solar[solar.length - 1])} kW
                            </strong>
                        </span>
                        <Sparkline series={solar} />
                    </div>
                    <div className="l-chip l-chip-c" aria-hidden>
                        <span className="l-dot" />
                        <span>
                            Gateway
                            <strong>Online · no alerts</strong>
                        </span>
                    </div>
                </div>
            </div>
        </section>
    );
}

const FORMATS: [string, string][] = [
    ['LAS · LAZ · E57 point clouds', '#74b8a0'],
    ['OBJ photogrammetry meshes', '#74b8a0'],
    ['IFC building models', '#74b8a0'],
    ['GLB models', '#74b8a0'],
    ['3D Tiles', '#74b8a0'],
    ['GeoTIFF terrain and orthophotos', '#5aa2ff'],
    ['GeoJSON · KML · DXF', '#5aa2ff'],
    ['Shapefiles · GeoPackage', '#5aa2ff'],
    ['MQTT', '#ffc94a'],
    ['OPC UA', '#ffc94a'],
    ['Modbus TCP', '#ffc94a'],
    ['CSV readings', '#ffc94a'],
    ['OpenID Connect', '#c79bff'],
    ['SCIM', '#c79bff'],
    ['REST API · OpenAPI', '#c79bff'],
];

function FormatStrip() {
    const track = (hidden: boolean) => (
        <div className="l-marquee-track" aria-hidden={hidden || undefined}>
            {FORMATS.map(([name, color]) => (
                <span
                    className="l-tag"
                    key={name}
                    style={{ '--c': color } as CSSProperties}
                >
                    <i />
                    {name}
                </span>
            ))}
        </div>
    );
    return (
        <section className="l-strip" aria-label="Formats and protocols">
            <p>Works with the data your site already has</p>
            <div className="l-marquee">
                {track(false)}
                {track(true)}
            </div>
        </section>
    );
}

const VIEWS = [
    {
        id: 'live',
        icon: Activity,
        title: 'Run it live',
        body: 'Every asset with its reading in the scene, energy and flows between them, and the day in charts. From the built-in simulator, or live from your gateway.',
        image: 'overview',
        alt: 'The cold chain campus live, with readings on every building and charts beside it.',
    },
    {
        id: 'what-if',
        icon: FlaskConical,
        title: 'Ask what if',
        body: 'Trip a board or lose a chiller and see what would happen over the next twelve hours, alert by alert, before it does.',
        image: 'what-if',
        alt: 'What-if: the freezer hall would pass its limit in 3 h 47 min, with the alerts that would follow.',
    },
    {
        id: 'volumes',
        icon: Mountain,
        title: 'Measure the ground',
        body: 'Cut and fill between surveys inside any area you draw, survey after survey, for stockpiles, pits and earthworks.',
        image: 'volumes',
        alt: 'A comparison of two ground surveys: 1,800 m³ cut, 1,413 m³ filled, shown in red and blue.',
    },
    {
        id: 'map',
        icon: Map,
        title: 'Lay down the map',
        body: 'Site plans, boundaries and drawings draped on the ground, and drone orthophotos laid over the terrain, where they are.',
        image: 'map-layers',
        alt: 'A depot with an orthophoto under it and site plan lines draped on the ground.',
    },
    {
        id: 'section',
        icon: Layers,
        title: 'Cut it open',
        body: 'Section the site above any height or across any line to see inside models and scans, and measure what you find.',
        image: 'section',
        alt: 'The campus cut open 8 m up, showing inside the buildings.',
    },
    {
        id: 'issues',
        icon: MessageSquareWarning,
        title: 'Pin issues where they are',
        body: 'Raise an issue on the spot with photos and comments, assign it and give it a due date. Anyone can fly back to it later.',
        image: 'issues',
        alt: 'An issue pinned on a pump skid, assigned, with a photo and comments.',
    },
] as const;

function Showcase({ still }: { still: boolean }) {
    const [active, setActive] = useState(0);
    const [auto, setAuto] = useState(true);
    const [held, setHeld] = useState(false);
    const tabs = useRef<(HTMLButtonElement | null)[]>([]);
    const running = auto && !still;

    const choose = (i: number, focus = false) => {
        setAuto(false);
        setActive(i);
        if (focus) tabs.current[i]?.focus();
    };
    const keys = (event: KeyboardEvent) => {
        const last = VIEWS.length - 1;
        const next =
            event.key === 'ArrowDown' || event.key === 'ArrowRight'
                ? active === last
                    ? 0
                    : active + 1
                : event.key === 'ArrowUp' || event.key === 'ArrowLeft'
                  ? active === 0
                      ? last
                      : active - 1
                  : event.key === 'Home'
                    ? 0
                    : event.key === 'End'
                      ? last
                      : null;
        if (next === null) return;
        event.preventDefault();
        choose(next, true);
    };

    return (
        <div
            className="l-showcase"
            onPointerEnter={() => setHeld(true)}
            onPointerLeave={() => setHeld(false)}
        >
            <div
                className="l-tabs"
                role="tablist"
                aria-label="What a twin does"
                aria-orientation="vertical"
                onKeyDown={keys}
            >
                {VIEWS.map((view, i) => (
                    <button
                        key={view.id}
                        ref={(el) => {
                            tabs.current[i] = el;
                        }}
                        role="tab"
                        id={`l-tab-${view.id}`}
                        aria-controls="l-showcase-panel"
                        aria-selected={i === active}
                        tabIndex={i === active ? 0 : -1}
                        className="l-tab"
                        onClick={() => choose(i)}
                    >
                        <span className="l-tab-title">
                            <view.icon aria-hidden />
                            {view.title}
                        </span>
                        <span className="l-tab-body">
                            <span>{view.body}</span>
                        </span>
                        {running && i === active && (
                            <span
                                className="l-tab-progress"
                                data-running="true"
                                style={{
                                    animationPlayState: held
                                        ? 'paused'
                                        : 'running',
                                }}
                                onAnimationEnd={() =>
                                    setActive((a) => (a + 1) % VIEWS.length)
                                }
                                aria-hidden
                            />
                        )}
                    </button>
                ))}
            </div>
            <div
                className="l-frame"
                role="tabpanel"
                id="l-showcase-panel"
                aria-labelledby={`l-tab-${VIEWS[active].id}`}
            >
                <div className="l-frame-stack">
                    {VIEWS.map((view, i) => (
                        <img
                            key={view.id}
                            src={`${IMAGES}/${view.image}.webp`}
                            width={1600}
                            height={950}
                            alt={i === active ? view.alt : ''}
                            aria-hidden={i !== active}
                            data-active={i === active}
                            loading="lazy"
                        />
                    ))}
                </div>
                <p className="l-caption">{VIEWS[active].body}</p>
            </div>
        </div>
    );
}

const STEPS = [
    {
        title: 'Bring it in',
        body: 'Upload scans, meshes, models, DEMs and drawings to a project library. Each is converted on a queue to 3D Tiles, terrain or map tiles, and placed where it was surveyed.',
        tags: ['LAS', 'E57', 'OBJ', 'IFC', 'GLB', 'GeoTIFF', 'DXF'],
    },
    {
        title: 'Build the twin',
        body: 'Start from a template, add uploads from the library, draw zones and place assets. Custom types give equipment its own metrics and behaviour.',
        tags: ['Templates', 'Types', 'Versions'],
    },
    {
        title: 'Connect it',
        body: 'A gateway on site reads your sensors and PLCs and streams to the twin. No gateway? Import CSV files, or post readings to the API.',
        tags: ['MQTT', 'OPC UA', 'Modbus', 'CSV', 'API'],
    },
    {
        title: 'Act on it',
        body: 'Rules and learned anomalies raise alerts to email and webhooks, with escalation. Issues get assigned, reports go out, and setpoints can be written back.',
        tags: ['Alerts', 'Anomalies', 'Issues', 'Reports'],
    },
];

function Steps() {
    return (
        <div className="l-steps">
            {STEPS.map((step, i) => (
                <article
                    className="l-step l-reveal"
                    key={step.title}
                    style={{ '--d': `${i * 0.08}s` } as CSSProperties}
                >
                    <span className="l-step-icon">
                        {String(i + 1).padStart(2, '0')}
                    </span>
                    <h3>{step.title}</h3>
                    <p>{step.body}</p>
                    <ul aria-label={`${step.title}: formats and features`}>
                        {step.tags.map((tag) => (
                            <li key={tag}>{tag}</li>
                        ))}
                    </ul>
                </article>
            ))}
        </div>
    );
}

const SURVEYS = [
    { pos: 100, label: '10 Mar 2026', note: 'March survey · 6 m stockpile' },
    { pos: 50, label: 'Compare', note: 'Drag to see what changed' },
    { pos: 0, label: '18 Jun 2026', note: 'June survey · 11 m stockpile' },
];

function SiteOverTime() {
    const [pos, setPos] = useState(50);
    return (
        <div className="l-compare-grid">
            <div className="l-reveal">
                <span className="l-kicker">// the site over time</span>
                <h2>Step back to any survey.</h2>
                <p className="l-lede" style={{ fontSize: 17 }}>
                    Date each scan, mesh and orthophoto by when it was captured.
                    A twin then shows the site as it was on any of those days:
                    the surveys, the imagery, even the ground it stands on.
                    Saved views and links keep the date.
                </p>
                <div
                    className="l-dates"
                    role="group"
                    aria-label="Show a survey"
                >
                    {SURVEYS.map((survey) => (
                        <button
                            key={survey.label}
                            type="button"
                            className="l-date"
                            aria-pressed={pos === survey.pos}
                            onClick={() => setPos(survey.pos)}
                        >
                            <strong>{survey.label}</strong>
                            {survey.note}
                        </button>
                    ))}
                </div>
            </div>
            <div
                className="l-frame l-reveal"
                style={{ '--d': '0.1s' } as CSSProperties}
            >
                <div
                    className="l-compare"
                    style={{ '--pos': `${pos}%` } as CSSProperties}
                >
                    <img
                        src={`${IMAGES}/site-march.webp`}
                        width={1600}
                        height={950}
                        alt="The survey pad on 10 March 2026, with a 6 m stockpile."
                        loading="lazy"
                    />
                    <img
                        className="l-compare-after"
                        src={`${IMAGES}/site-june.webp`}
                        width={1600}
                        height={950}
                        alt="The same pad in June, with an 11 m stockpile."
                        loading="lazy"
                    />
                    <span className="l-compare-label left">
                        March
                        <small>6 m stockpile</small>
                    </span>
                    <span className="l-compare-label right">
                        June
                        <small>11 m stockpile</small>
                    </span>
                    <div className="l-compare-handle" aria-hidden>
                        <span>
                            <ChevronsLeftRight />
                        </span>
                    </div>
                    <input
                        type="range"
                        min={0}
                        max={100}
                        value={pos}
                        onChange={(event) => setPos(Number(event.target.value))}
                        aria-label="March to June"
                        aria-valuetext={`${pos}% March, ${100 - pos}% June`}
                    />
                </div>
            </div>
        </div>
    );
}

type Part = 'where' | 'ground' | 'behave' | 'assets' | 'live';

const DEFINITION: [string, Part | null][] = [
    ['{', null],
    ['  "format": 2,', null],
    ['  "name": "Ridge pit",', null],
    ['  "origin": { "lon": 151.21, "lat": -32.56 },', 'where'],
    [
        '  "terrains": [{ "name": "Pit survey, May", "url": "…/layer.json" }],',
        'ground',
    ],
    ['  "types": [{', 'behave'],
    ['    "id": "crusher", "primary": "feedTph",', 'behave'],
    ['    "metrics": [{ "key": "crushedT", "unit": "t",', 'behave'],
    ['      "expr": "crushedT + feedTph * dt / 3600" }]', 'behave'],
    ['  }],', 'behave'],
    ['  "assets": [', 'assets'],
    [
        '    { "id": "crusher", "name": "Primary crusher", "type": "crusher" },',
        'assets',
    ],
    [
        '    { "id": "stockpile", "name": "ROM stockpile", "zone": "stockpiles" }',
        'assets',
    ],
    ['  ],', 'assets'],
    [
        '  "telemetry": [{ "topic": "pit/crusher/feed", "metric": "feedTph" }],',
        'live',
    ],
    [
        '  "rules": [{ "metric": "bearingC", "op": "gt", "threshold": 85 }]',
        'behave',
    ],
    ['}', null],
];

/** Colours a line of JSON: keys, strings and numbers. */
function highlight(line: string): ReactNode[] {
    const parts: ReactNode[] = [];
    const token = /("(?:[^"\\]|\\.)*")(\s*:)?|(-?\d+(?:\.\d+)?)/g;
    let last = 0;
    for (const match of line.matchAll(token)) {
        const at = match.index ?? 0;
        if (at > last) parts.push(line.slice(last, at));
        if (match[1] !== undefined) {
            parts.push(
                <span key={at} className={match[2] ? 'k' : 's'}>
                    {match[1]}
                </span>,
            );
            if (match[2]) parts.push(match[2]);
        } else {
            parts.push(
                <span key={at} className="n">
                    {match[3]}
                </span>,
            );
        }
        last = at + match[0].length;
    }
    if (last < line.length) parts.push(line.slice(last));
    return parts;
}

const PARTS: { id: Part; icon: typeof Globe; title: string; body: string }[] = [
    {
        id: 'where',
        icon: Globe,
        title: 'Where it is',
        body: 'One point on the globe. Everything else is metres east and north of it.',
    },
    {
        id: 'assets',
        icon: Boxes,
        title: "What's there",
        body: 'Assets, zones and routes, and what feeds and cools what.',
    },
    {
        id: 'behave',
        icon: Activity,
        title: 'How it behaves',
        body: 'Custom types with metrics and expressions, and rules that raise alerts.',
    },
    {
        id: 'live',
        icon: Radio,
        title: 'Where readings come from',
        body: 'MQTT topics, OPC UA nodes and Modbus registers mapped onto metrics.',
    },
    {
        id: 'ground',
        icon: Mountain,
        title: 'What it stands on',
        body: 'Terrain from your surveys, chosen by date.',
    },
];

function DefinitionAsData() {
    const [focus, setFocus] = useState<Part | null>(null);
    return (
        <div className="l-def-grid">
            <div className="l-reveal">
                <span className="l-kicker">// a twin is data</span>
                <h2>Change the site, not the code.</h2>
                <p className="l-lede" style={{ fontSize: 17 }}>
                    A twin is one JSON definition, edited in a form or drawn in
                    the scene. Every save is a version you can download, compare
                    and restore, and the format is versioned so twins saved
                    today keep opening tomorrow.
                </p>
                <ul
                    className="l-def-points"
                    style={{ marginTop: 24 }}
                    onPointerLeave={() => setFocus(null)}
                >
                    {PARTS.map((part) => (
                        <li key={part.id}>
                            <button
                                type="button"
                                className="l-def-point"
                                data-active={focus === part.id}
                                aria-pressed={focus === part.id}
                                onPointerEnter={() => setFocus(part.id)}
                                onFocus={() => setFocus(part.id)}
                                onBlur={() => setFocus(null)}
                                onClick={() => setFocus(part.id)}
                            >
                                <part.icon aria-hidden />
                                <span>
                                    <strong>{part.title}</strong>
                                    {part.body}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            </div>
            <pre
                className="l-code l-reveal"
                data-focus={focus ?? undefined}
                style={{ '--d': '0.1s' } as CSSProperties}
                aria-label="An excerpt of a twin definition"
            >
                <div className="l-code-bar" aria-hidden>
                    <i />
                    <i />
                    <i />
                    <span>ridge-pit.json</span>
                </div>
                <code>
                    {DEFINITION.map(([line, part], i) => (
                        <span
                            key={i}
                            className="l-code-line"
                            data-on={part !== null && part === focus}
                        >
                            {highlight(line)}
                            {'\n'}
                        </span>
                    ))}
                </code>
            </pre>
        </div>
    );
}

const SITES = [
    {
        image: 'overview',
        title: 'Depots and campuses',
        body: 'Cold stores, chillers, solar, batteries and the grid, with the physics to forecast them.',
    },
    {
        image: 'template-mine',
        title: 'Mines and quarries',
        body: 'Crushers, conveyors and stockpiles, pit water, and surveys of the ground month by month.',
    },
    {
        image: 'volumes',
        title: 'Survey and monitoring',
        body: 'Scans and drone surveys over time, cut and fill, and readings from loggers and lab results.',
    },
];

const FEATURES = [
    {
        icon: ShieldCheck,
        title: 'Single sign-on',
        body: 'OpenID Connect with Entra ID, Okta, Google or Keycloak, and require it for everyone.',
    },
    {
        icon: Users,
        title: 'Provisioning',
        body: 'SCIM adds and removes members as your identity provider does.',
    },
    {
        icon: KeyRound,
        title: 'Roles',
        body: 'Owners, admins, operators and viewers. Writing to PLCs stays off until an owner or admin turns it on.',
    },
    {
        icon: ScrollText,
        title: 'Audit log',
        body: 'Who did what and from where, kept for two years and readable by your SIEM.',
    },
    {
        icon: Code,
        title: 'API and OpenAPI',
        body: 'Tokens that read or write, for maintenance systems, BI tools and scripts.',
    },
    {
        icon: Share2,
        title: 'Share links',
        body: 'Show a twin read-only to someone without an account, until the link expires.',
    },
    {
        icon: Server,
        title: 'Your servers',
        body: 'One Docker stack with its workers, broker and converter, on your own infrastructure.',
    },
    {
        icon: WifiOff,
        title: 'Works offline',
        body: 'A bundled basemap means the viewer makes no outside requests unless you choose to.',
    },
];

function Features() {
    const light = (event: PointerEvent<HTMLElement>) => {
        const box = event.currentTarget.getBoundingClientRect();
        event.currentTarget.style.setProperty(
            '--mx',
            `${event.clientX - box.left}px`,
        );
        event.currentTarget.style.setProperty(
            '--my',
            `${event.clientY - box.top}px`,
        );
    };
    return (
        <div className="l-features l-reveal">
            {FEATURES.map((feature) => (
                <article
                    className="l-feature"
                    key={feature.title}
                    onPointerMove={light}
                >
                    <feature.icon aria-hidden />
                    <h3>{feature.title}</h3>
                    <p>{feature.body}</p>
                </article>
            ))}
        </div>
    );
}

export default function Welcome({
    canRegister = true,
}: {
    canRegister?: boolean;
}) {
    const { auth } = usePage().props;
    const still = useReducedMotion();
    const page = useRef<HTMLDivElement>(null);
    const [scrolled, setScrolled] = useState(false);
    useReveal(page);

    useEffect(() => {
        const scroll = () => setScrolled(window.scrollY > 8);
        scroll();
        window.addEventListener('scroll', scroll, { passive: true });
        return () => window.removeEventListener('scroll', scroll);
    }, []);

    const account = auth.user ? (
        <Link href={dashboard()} className="l-btn l-btn-lg">
            Open your dashboard
        </Link>
    ) : canRegister ? (
        <Link href={register()} className="l-btn l-btn-lg">
            Create an account
        </Link>
    ) : (
        <Link href={login()} className="l-btn l-btn-lg">
            Log in
        </Link>
    );

    return (
        <>
            <Head title="Meridian: digital twins on a real globe" />
            <div className="landing" ref={page}>
                <header className="l-header" data-scrolled={scrolled}>
                    <div className="l-wrap l-header-inner">
                        <Link
                            href="/"
                            className="l-brand"
                            aria-label="Meridian home"
                        >
                            <AppLogoIcon />
                            Meridian
                        </Link>
                        <nav className="l-nav" aria-label="Main">
                            <div className="l-nav-links">
                                <a href="#features">Features</a>
                                <a href="#over-time">Over time</a>
                                <a href="#data">Definitions</a>
                                <a href="#teams">For teams</a>
                            </div>
                            {auth.user ? (
                                <Link
                                    href={dashboard()}
                                    className="l-btn l-btn-primary"
                                >
                                    Dashboard
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href={login()}
                                        className="l-btn l-btn-ghost"
                                    >
                                        Log in
                                    </Link>
                                    {canRegister && (
                                        <Link
                                            href={register()}
                                            className="l-btn l-btn-primary"
                                        >
                                            Get started
                                        </Link>
                                    )}
                                </>
                            )}
                        </nav>
                    </div>
                </header>

                <main>
                    <Hero still={still} primary={account} />
                    <FormatStrip />

                    <section className="l-section" id="features">
                        <div className="l-wrap">
                            <div className="l-section-head l-reveal">
                                <span className="l-kicker">
                                    // one place for the site
                                </span>
                                <h2>See it, run it, question it.</h2>
                                <p>
                                    The scans, the models and the live readings,
                                    together in one scene everyone shares, from
                                    the control room to the field.
                                </p>
                            </div>
                            <div className="l-reveal">
                                <Showcase still={still} />
                            </div>
                        </div>
                    </section>

                    <section className="l-section" style={{ paddingTop: 24 }}>
                        <div className="l-wrap">
                            <div className="l-section-head l-reveal">
                                <span className="l-kicker">
                                    // from survey to live
                                </span>
                                <h2>From a drone flight to a running twin.</h2>
                            </div>
                            <Steps />
                        </div>
                    </section>

                    <section className="l-section" id="over-time">
                        <div className="l-wrap">
                            <SiteOverTime />
                        </div>
                    </section>

                    <section className="l-section">
                        <div className="l-wrap">
                            <div className="l-section-head l-reveal">
                                <span className="l-kicker">
                                    // any kind of site
                                </span>
                                <h2>Not just buildings. Not just energy.</h2>
                                <p>
                                    Templates start a twin as a factory line, a
                                    mine site, a survey or a site with power.
                                    Custom types model whatever else you run.
                                </p>
                            </div>
                            <div className="l-sites">
                                {SITES.map((site, i) => (
                                    <article
                                        className="l-site l-reveal"
                                        key={site.title}
                                        style={
                                            {
                                                '--d': `${i * 0.08}s`,
                                            } as CSSProperties
                                        }
                                    >
                                        <img
                                            src={`${IMAGES}/${site.image}.webp`}
                                            width={1600}
                                            height={950}
                                            alt=""
                                            loading="lazy"
                                        />
                                        <div>
                                            <h3>{site.title}</h3>
                                            <p>{site.body}</p>
                                        </div>
                                    </article>
                                ))}
                            </div>
                        </div>
                    </section>

                    <section className="l-section" id="data">
                        <div className="l-wrap">
                            <DefinitionAsData />
                        </div>
                    </section>

                    <section className="l-section" id="teams">
                        <div className="l-wrap">
                            <div className="l-section-head l-reveal">
                                <span className="l-kicker">
                                    // built for teams
                                </span>
                                <h2>Ready for the whole organisation.</h2>
                                <p>
                                    Organisations, projects and roles, with the
                                    controls security and IT will ask about.
                                </p>
                            </div>
                            <Features />
                        </div>
                    </section>

                    <section className="l-section" style={{ paddingTop: 24 }}>
                        <div className="l-wrap">
                            <div className="l-final l-reveal">
                                <Scan
                                    aria-hidden
                                    style={{
                                        width: 28,
                                        height: 28,
                                        margin: '0 auto 18px',
                                        color: 'var(--l-amber)',
                                    }}
                                />
                                <h2>Walk around your site from your desk.</h2>
                                <p>
                                    Open a sample twin in your browser, or sign
                                    in and bring in your first survey.
                                </p>
                                <div className="l-cta-row">
                                    <Link
                                        href={demo()}
                                        className="l-btn l-btn-primary l-btn-lg"
                                    >
                                        Explore the sample twins
                                        <ArrowRight className="l-arrow" />
                                    </Link>
                                    {account}
                                </div>
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="l-footer">
                    <div className="l-wrap l-footer-inner">
                        <span className="l-brand" style={{ fontSize: 14 }}>
                            <AppLogoIcon style={{ width: 20, height: 20 }} />
                            Meridian
                        </span>
                        <span>Digital twins on a real globe</span>
                        <Link href={demo()}>Sample twins</Link>
                    </div>
                </footer>
            </div>
        </>
    );
}
