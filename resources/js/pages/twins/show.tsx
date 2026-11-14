import { Head, Link, router, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { DefinitionEditor } from '@/components/definition-editor';
import type { GatewayStatus } from '@/components/gateway-panel';
import type { OpcUaWrites } from '@/components/opcua-panel';
import type { ReadingImportSummary } from '@/components/reading-import-panel';
import { TwinAnomalies, type Anomalies } from '@/components/twin-anomalies';
import {
    TwinEditor,
    type LibraryTypeSummary,
    type TwinAssetSummary,
    type TwinVersionSummary,
} from '@/components/twin-editor';
import {
    openIssue,
    TwinIssues,
    type IssueDetail,
    type IssueSummary,
    type RaiseFrom,
} from '@/components/twin-issues';
import { TwinSettings } from '@/components/twin-settings';
import { TwinShare, type ShareLinkSummary } from '@/components/twin-share';
import { TwinViews, type SavedViewSummary } from '@/components/twin-views';
import {
    TwinVolumes,
    type SurveyComparisonSummary,
} from '@/components/twin-volumes';
import { show as showProject } from '@/routes/projects';
import { index as alertLog } from '@/routes/twins/alerts';
import { store as acknowledgeAlert } from '@/routes/twins/alerts/acknowledgement';
import { index as twinReadings } from '@/routes/twins/readings';
import type { TwinDefinition } from '@/twin/core/types';
import { decodeView, parseView, type ViewState } from '@/twin/core/views';
import {
    alertKey,
    applyView,
    runDefinition,
    runGateway,
    stopTwin,
    useTwin,
    type Recorded,
} from '@/twin/state/store';
import { Viewer } from '@/twin/ui/Viewer';

type ServerAlert = {
    id: string;
    rule: string;
    asset: string;
    acknowledgedBy: string | null;
    acknowledgedAt: string | null;
};

type Props = {
    twin: { id: string; name: string; description: string | null };
    project: {
        id: string;
        name: string;
        topicPrefix: string;
        opcuaServers: string[];
        modbusDevices: string[];
    };
    definition: TwinDefinition;
    version: number;
    versions: TwinVersionSummary[];
    assets: TwinAssetSummary[];
    types: LibraryTypeSummary[];
    gateway: GatewayStatus;
    opcuaWrites: OpcUaWrites;
    /** CSV files of readings imported into its history; editors only. */
    readingImports: ReadingImportSummary[];
    /** The alerts the gateway says are open; null without a gateway. */
    alerts: ServerAlert[] | null;
    /** The issues pinned in the scene, and the one open in the panel (`?issue=`). */
    issues: IssueSummary[];
    issue: IssueDetail | null;
    /** An alert to raise an issue from (`?raise=`), pinned at its asset. */
    raise: RaiseFrom | null;
    /** The views this user can see: theirs and the shared ones. */
    views: SavedViewSummary[];
    /** The view to open at: the one linked (`?view=`), or the twin's opening view. */
    startView: { id: string; state: unknown } | null;
    /** The metrics watched for anomalies, and the periods left out. */
    anomalies: Anomalies;
    /** Areas where surveys of the ground are compared. */
    comparisons: SurveyComparisonSummary[];
    /** The links showing it outside the organisation; empty unless they can share. */
    shareLinks: ShareLinkSummary[];
    can: {
        update: boolean;
        share: boolean;
        operate: boolean;
        manageTypes: boolean;
    };
};

export default function ShowTwin({
    twin,
    project,
    definition,
    version,
    versions,
    assets,
    types,
    gateway,
    opcuaWrites,
    readingImports,
    alerts,
    issues,
    issue,
    raise,
    views,
    startView,
    anomalies,
    comparisons,
    shareLinks,
    can,
}: Props) {
    const [editing, setEditing] = useState<
        | 'layout'
        | 'definition'
        | 'settings'
        | 'issues'
        | 'views'
        | 'anomalies'
        | 'volumes'
        | 'share'
        | null
    >(issue || raise ? 'issues' : null);
    const openAnomalies = anomalies.monitors.reduce(
        (n, m) => n + m.open.length,
        0,
    );
    // Follow the gateway when one is connected, unless the user picks the simulator.
    const [preferSimulator, setPreferSimulator] = useState(false);
    const live =
        Boolean(gateway?.online && gateway.url && gateway.ticket) &&
        !preferSimulator;
    const gatewayUrl = gateway?.online ? gateway.url : null;

    // The gateway's status comes and goes; tickets are refreshed with it.
    usePoll(15_000, {
        only: ['gateway', 'alerts', 'issues', 'issue', 'views', 'anomalies'],
    });
    const ticket = useRef(gateway?.ticket ?? null);
    useEffect(() => {
        if (gateway?.ticket) ticket.current = gateway.ticket;
    }, [gateway?.ticket]);

    // A save comes back as a new version; restart the simulator on it. In live
    // mode the gateway picks the version up itself.
    useEffect(() => {
        if (live && gatewayUrl && ticket.current) {
            const url = new URL(gatewayUrl);
            url.searchParams.set('ticket', ticket.current);
            runGateway(url.toString(), { canOperate: can.operate });
        } else {
            runDefinition(definition, { canOperate: can.operate });
        }
    }, [version, definition, can.operate, live, gatewayUrl]);

    // When the library's uploads were captured, for the Site on slider.
    useEffect(() => {
        useTwin.setState({
            captures: Object.fromEntries(
                assets.flatMap((a) =>
                    a.capturedOn
                        ? [[a.id, { on: a.capturedOn, kind: a.kind }]]
                        : [],
                ),
            ),
        });
    }, [assets]);

    // Recorded readings come from the control plane, whichever source runs.
    useEffect(() => {
        useTwin.setState({
            recorded: async (asset, metric, from, to) => {
                const res = await fetch(
                    twinReadings.url(twin.id, {
                        query: { asset, metric, from, to },
                    }),
                    { headers: { accept: 'application/json' } },
                );
                if (!res.ok) throw new Error('Could not load the history');
                return (await res.json()) as Recorded;
            },
        });
    }, [twin.id]);

    // While following the gateway, the Alerts tab shares acknowledgements
    // through the control plane's alert log.
    useEffect(() => {
        if (alerts === null) {
            useTwin.setState({ serverAlerts: null });
            return;
        }
        const byKey = new Map(
            alerts.map((a) => [alertKey(a.rule, a.asset), a]),
        );
        useTwin.setState({
            serverAlerts: {
                open: live
                    ? Object.fromEntries(
                          [...byKey].map(([key, a]) => [
                              key,
                              { acknowledgedBy: a.acknowledgedBy },
                          ]),
                      )
                    : {},
                acknowledge: live
                    ? (ruleId, assetId) => {
                          const alert = byKey.get(alertKey(ruleId, assetId));
                          if (alert)
                              router.post(
                                  acknowledgeAlert.url([twin.id, alert.id]),
                                  {},
                                  {
                                      preserveScroll: true,
                                      preserveState: true,
                                      only: ['alerts'],
                                  },
                              );
                      }
                    : undefined,
                logUrl: alertLog.url(twin.id),
            },
        });
    }, [alerts, live, twin.id]);

    // Issues are pins in the scene; a link to one opens at its camera.
    useEffect(() => {
        useTwin.setState({
            pins: issues.map((i) => ({
                id: i.id,
                number: i.number,
                title: i.title,
                closed: i.closed,
                at: i.at,
            })),
        });
    }, [issues]);
    useEffect(() => {
        useTwin.setState({ activePin: issue?.id ?? null });
    }, [issue?.id]);
    // Opening at: the asset of an alert an issue is raised from, else a
    // linked issue's camera, else a view from the link (a whole look, or a
    // saved view), else the twin's opening view.
    const opening = useRef<{
        asset?: string;
        issue?: IssueDetail['view'];
        view?: ViewState;
    }>(
        raise
            ? { asset: raise.asset }
            : issue
              ? { issue: issue.view }
              : {
                    view:
                        linkedLook() ??
                        parseView(startView?.state) ??
                        undefined,
                },
    );
    useEffect(() => {
        const { asset, issue: at, view } = opening.current;
        if (asset) useTwin.getState().pinAsset(asset);
        else if (at) useTwin.getState().flyTo(at);
        else if (view) applyView(view);
    }, []);
    // Clicking a pin opens its issue.
    useEffect(
        () =>
            useTwin.subscribe((s, prev) => {
                if (!s.pickedPin || s.pickedPin === prev.pickedPin) return;
                setEditing('issues');
                openIssue(twin.id, s.pickedPin.id);
            }),
        [twin.id],
    );
    // Closing the panel stops placing a pin.
    useEffect(() => {
        if (editing !== 'issues') useTwin.getState().clearPlacedPin();
    }, [editing]);
    const openIssues = issues.filter((i) => !i.closed).length;

    useEffect(() => stopTwin, []);

    return (
        <>
            <Head title={twin.name} />
            <Viewer
                header={
                    <>
                        <Link
                            href={showProject(project.id)}
                            className="back-link"
                            aria-label={`Back to ${project.name}`}
                            title={project.name}
                        >
                            ←
                        </Link>
                        <span className="twin-name">{twin.name}</span>
                        <span className="version-chip" title="Saved version">
                            v{version}
                        </span>
                        {gateway?.online && (
                            <div
                                className="segmented"
                                role="radiogroup"
                                aria-label="Source"
                            >
                                {(['Simulator', 'Live'] as const).map(
                                    (label) => {
                                        const on = (label === 'Live') === live;
                                        return (
                                            <button
                                                key={label}
                                                role="radio"
                                                aria-checked={on}
                                                className={on ? 'on' : ''}
                                                onClick={() =>
                                                    setPreferSimulator(
                                                        label === 'Simulator',
                                                    )
                                                }
                                            >
                                                {label}
                                            </button>
                                        );
                                    },
                                )}
                            </div>
                        )}
                        <button
                            className={
                                editing === 'issues' ? 'primary' : 'ghost'
                            }
                            aria-expanded={editing === 'issues'}
                            onClick={() =>
                                setEditing(
                                    editing === 'issues' ? null : 'issues',
                                )
                            }
                        >
                            Issues{openIssues > 0 ? ` (${openIssues})` : ''}
                        </button>
                        <button
                            className={
                                editing === 'views' ? 'primary' : 'ghost'
                            }
                            aria-expanded={editing === 'views'}
                            onClick={() =>
                                setEditing(editing === 'views' ? null : 'views')
                            }
                        >
                            Views
                        </button>
                        <button
                            className={
                                editing === 'anomalies' ? 'primary' : 'ghost'
                            }
                            aria-expanded={editing === 'anomalies'}
                            onClick={() =>
                                setEditing(
                                    editing === 'anomalies'
                                        ? null
                                        : 'anomalies',
                                )
                            }
                        >
                            Anomalies
                            {openAnomalies > 0 ? ` (${openAnomalies})` : ''}
                        </button>
                        <button
                            className={
                                editing === 'volumes' ? 'primary' : 'ghost'
                            }
                            aria-expanded={editing === 'volumes'}
                            onClick={() =>
                                setEditing(
                                    editing === 'volumes' ? null : 'volumes',
                                )
                            }
                        >
                            Volumes
                        </button>
                        {can.share && (
                            <button
                                className={
                                    editing === 'share' ? 'primary' : 'ghost'
                                }
                                aria-expanded={editing === 'share'}
                                onClick={() =>
                                    setEditing(
                                        editing === 'share' ? null : 'share',
                                    )
                                }
                            >
                                Share
                            </button>
                        )}
                        {can.update &&
                            (
                                [
                                    ['layout', 'Layout'],
                                    ['definition', 'Definition'],
                                    ['settings', 'Settings'],
                                ] as const
                            ).map(([panel, label]) => (
                                <button
                                    key={panel}
                                    className={
                                        editing === panel ? 'primary' : 'ghost'
                                    }
                                    aria-expanded={editing === panel}
                                    onClick={() =>
                                        setEditing(
                                            editing === panel ? null : panel,
                                        )
                                    }
                                >
                                    {label}
                                </button>
                            ))}
                    </>
                }
            >
                {editing === 'layout' && (
                    <TwinEditor
                        twinId={twin.id}
                        definition={definition}
                        versions={versions}
                        assets={assets}
                        types={types}
                        canManageTypes={can.manageTypes}
                        gateway={gateway}
                        topicPrefix={project.topicPrefix}
                        opcuaServers={project.opcuaServers}
                        modbusDevices={project.modbusDevices}
                        opcuaWrites={opcuaWrites}
                        readingImports={readingImports}
                        onClose={() => setEditing(null)}
                    />
                )}
                {editing === 'issues' && (
                    <TwinIssues
                        twinId={twin.id}
                        issues={issues}
                        issue={issue}
                        raise={raise}
                        onClose={() => {
                            setEditing(null);
                            if (issue) openIssue(twin.id, null);
                        }}
                    />
                )}
                {editing === 'views' && (
                    <TwinViews
                        twinId={twin.id}
                        views={views}
                        canEdit={can.update}
                        onClose={() => setEditing(null)}
                    />
                )}
                {editing === 'anomalies' && (
                    <TwinAnomalies
                        twinId={twin.id}
                        definition={definition}
                        anomalies={anomalies}
                        canEdit={can.update}
                        onClose={() => setEditing(null)}
                    />
                )}
                {editing === 'volumes' && (
                    <TwinVolumes
                        twinId={twin.id}
                        definition={definition}
                        assets={assets}
                        comparisons={comparisons}
                        canEdit={can.update}
                        onClose={() => setEditing(null)}
                    />
                )}
                {editing === 'share' && (
                    <TwinShare
                        twinId={twin.id}
                        links={shareLinks}
                        onClose={() => setEditing(null)}
                    />
                )}
                {editing === 'settings' && (
                    <TwinSettings
                        twin={twin}
                        onClose={() => setEditing(null)}
                    />
                )}
                {editing === 'definition' && (
                    <DefinitionEditor
                        key={version}
                        twinId={twin.id}
                        definition={definition}
                        onClose={() => setEditing(null)}
                    />
                )}
            </Viewer>
        </>
    );
}

/** A whole look carried in the link (`?look=`), if it holds a valid one. */
function linkedLook(): ViewState | null {
    const look = new URLSearchParams(window.location.search).get('look');
    return look ? decodeView(look) : null;
}
