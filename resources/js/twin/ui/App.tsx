import { lazy, Suspense, useState, type ReactNode } from 'react';
import { useTwin, type Tab } from '../state/store.ts';
import { AlertsPanel } from './AlertsPanel.tsx';
import { ErrorBoundary } from './ErrorBoundary.tsx';
import { Inspector } from './Inspector.tsx';
import { LeftPanel } from './LeftPanel.tsx';
import { MeasurePanel } from './MeasurePanel.tsx';
import { Timeline } from './Timeline.tsx';
import { TopBar } from './TopBar.tsx';
import { WhatIfPanel } from './WhatIfPanel.tsx';

// Cesium is large, so the shell paints first and the globe loads behind it.
const CesiumView = lazy(() => import('../scene/CesiumView.tsx'));

const TABS: { id: Tab; label: string }[] = [
    { id: 'inspect', label: 'Inspect' },
    { id: 'alerts', label: 'Alerts' },
    { id: 'whatif', label: 'What-if' },
];

const startsOpen = () => window.matchMedia('(min-width: 900px)').matches;

export function App({
    header,
    children,
}: {
    header?: ReactNode;
    children?: ReactNode;
}) {
    const tab = useTwin((s) => s.tab);
    const setTab = useTwin((s) => s.setTab);
    const loadError = useTwin((s) => s.loadError);
    const def = useTwin((s) => s.def);
    const [leftOpen, setLeftOpen] = useState(startsOpen);
    const [rightOpen, setRightOpen] = useState(startsOpen);
    const measuring = useTwin((s) => s.measuring);

    return (
        <div className="app">
            <ErrorBoundary label="The 3D view">
                <Suspense fallback={<div className="globe" />}>
                    <CesiumView />
                </Suspense>
            </ErrorBoundary>
            <TopBar header={header} />
            <div className="panels">
                <div className="drawer">
                    <button
                        className="drawer-toggle"
                        onClick={() => setLeftOpen(!leftOpen)}
                        aria-expanded={leftOpen}
                    >
                        Assets
                    </button>
                    {leftOpen && (
                        <ErrorBoundary label="The asset list">
                            <LeftPanel />
                        </ErrorBoundary>
                    )}
                </div>
                <div className="view-buttons">
                    <button
                        title="Frame the whole site"
                        onClick={() => useTwin.getState().select(null, true)}
                    >
                        Whole site
                    </button>
                    <button
                        title="Pull back to see the whole Earth"
                        onClick={() => useTwin.getState().showGlobe()}
                    >
                        Globe
                    </button>
                    <button
                        title="Measure distances, heights, areas and points"
                        aria-pressed={measuring !== null}
                        className={measuring ? 'on' : ''}
                        onClick={() =>
                            useTwin
                                .getState()
                                .setMeasuring(
                                    measuring
                                        ? null
                                        : { tool: 'distance', lock: 'none' },
                                )
                        }
                    >
                        Measure
                    </button>
                </div>
                <MeasurePanel />
                <div className="drawer drawer-right">
                    <button
                        className="drawer-toggle"
                        onClick={() => setRightOpen(!rightOpen)}
                        aria-expanded={rightOpen}
                    >
                        Details
                    </button>
                    {rightOpen && (
                        <aside
                            className="panel right-panel"
                            aria-label="Details"
                        >
                            <div className="tabs" role="tablist">
                                {TABS.map((t) => (
                                    <button
                                        key={t.id}
                                        role="tab"
                                        aria-selected={tab === t.id}
                                        className={tab === t.id ? 'on' : ''}
                                        onClick={() => setTab(t.id)}
                                    >
                                        {t.label}
                                    </button>
                                ))}
                            </div>
                            <div className="tab-body" role="tabpanel">
                                <ErrorBoundary key={tab} label="This panel">
                                    {tab === 'inspect' && <Inspector />}
                                    {tab === 'alerts' && <AlertsPanel />}
                                    {tab === 'whatif' && <WhatIfPanel />}
                                </ErrorBoundary>
                            </div>
                        </aside>
                    )}
                </div>
            </div>
            <Timeline />
            {children}
            {loadError && (
                <LoadError
                    message={loadError}
                    recoverable={!!def}
                    embedded={header !== undefined}
                />
            )}
        </div>
    );
}

function LoadError({
    message,
    recoverable,
    embedded,
}: {
    message: string;
    recoverable: boolean;
    embedded: boolean;
}) {
    return (
        <div className="modal-scrim">
            <div
                className="modal panel"
                role="alertdialog"
                aria-label="Could not load twin"
            >
                <h2>Could not load that twin</h2>
                <pre>{message}</pre>
                {recoverable ? (
                    <button
                        onClick={() =>
                            useTwin.setState({ loadError: undefined })
                        }
                    >
                        Keep the current twin
                    </button>
                ) : embedded ? (
                    <button onClick={() => window.location.reload()}>
                        Reload
                    </button>
                ) : (
                    <button
                        onClick={() =>
                            void useTwin.getState().loadTwinById('northshore')
                        }
                    >
                        Open the sample twin
                    </button>
                )}
            </div>
        </div>
    );
}
