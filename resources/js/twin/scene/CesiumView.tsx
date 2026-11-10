import 'cesium/Build/Cesium/Widgets/widgets.css';
import { useEffect, useRef } from 'react';
import { ORIGIN, savedPlacement } from '../core/layers.ts';
import { alertsAt, useTwin } from '../state/store.ts';
import { TwinScene } from './TwinScene.ts';

/** Mounts the Cesium scene, and pushes store changes into it without re-rendering. */
export default function CesiumView() {
    const container = useRef<HTMLDivElement>(null);
    const def = useTwin((s) => s.def);

    useEffect(() => {
        if (!def || !container.current) return;
        const scene = new TwinScene(container.current, def);
        scene.onPick = (id) => useTwin.getState().select(id);
        scene.measure.onChange = (measurement) =>
            useTwin.getState().setMeasurement(measurement);
        scene.sketch.onChange = (points) => {
            const { sketch, setSketch } = useTwin.getState();
            if (sketch) setSketch({ ...sketch, points });
        };

        const placeOnClick = (layerId: string | null) => {
            scene.onGroundPick = layerId
                ? ([east, north]) => {
                      const s = useTwin.getState();
                      const current =
                          s.drafts[layerId] ??
                          savedPlacement(s.def, layerId) ??
                          ORIGIN;
                      s.setDraft(layerId, {
                          ...current,
                          east: Math.round(east * 10) / 10,
                          north: Math.round(north * 10) / 10,
                      });
                      s.setPlacingLayer(null);
                  }
                : undefined;
        };

        const pickFeatures = (owner: string | null) => {
            scene.onFeaturePick = owner
                ? (picked) =>
                      useTwin.getState().setFeaturePick({ owner, picked })
                : undefined;
        };
        const waitingFor = (s: ReturnType<typeof useTwin.getState>) =>
            s.featurePick && !s.featurePick.picked ? s.featurePick.owner : null;

        const sync = () => {
            const s = useTwin.getState();
            scene.setBasemap(s.basemap);
            scene.setLayers(s.layers);
            scene.setSelected(s.selectedId);
            scene.sketch.set(s.sketch?.points ?? null, s.sketch?.single);
            scene.measure.set(s.measuring?.tool ?? null, s.measuring?.lock);
            scene.setSection(s.section);
            pickFeatures(waitingFor(s));
            if (s.view) {
                scene.updateFrame(s.view, s.colorBy);
                scene.setAlerts(alertsAt(s.alerts, s.view.t));
            }
        };
        sync();
        const unsubscribe = useTwin.subscribe((s, prev) => {
            if (s.basemap !== prev.basemap) scene.setBasemap(s.basemap);
            if (s.layers !== prev.layers) scene.setLayers(s.layers);
            if (s.selectedId !== prev.selectedId)
                scene.setSelected(s.selectedId);
            if (s.view && (s.view !== prev.view || s.colorBy !== prev.colorBy))
                scene.updateFrame(s.view, s.colorBy);
            if (s.view && (s.alerts !== prev.alerts || s.view !== prev.view))
                scene.setAlerts(alertsAt(s.alerts, s.view.t));
            if (s.globeRequest !== prev.globeRequest) scene.globeView();
            if (s.sketch !== prev.sketch)
                scene.sketch.set(s.sketch?.points ?? null, s.sketch?.single);
            if (s.measuring !== prev.measuring)
                scene.measure.set(s.measuring?.tool ?? null, s.measuring?.lock);
            if (s.measureClear !== prev.measureClear) scene.measure.clear();
            if (s.section !== prev.section) scene.setSection(s.section);
            if (s.placingLayer !== prev.placingLayer)
                placeOnClick(s.placingLayer);
            if (waitingFor(s) !== waitingFor(prev)) pickFeatures(waitingFor(s));
            if (s.drafts !== prev.drafts) {
                for (const id of new Set([
                    ...Object.keys(s.drafts),
                    ...Object.keys(prev.drafts),
                ])) {
                    if (s.drafts[id] !== prev.drafts[id])
                        scene.setPlacement(
                            id,
                            s.drafts[id] ?? savedPlacement(s.def, id),
                        );
                }
            }
            if (s.flyRequest !== prev.flyRequest) {
                if (s.selectedId) scene.flyToAsset(s.selectedId);
                else scene.home();
            }
        });
        // Inspection hook for the end-to-end tests; it only exposes what the page already shows.
        (window as unknown as { __scene?: TwinScene }).__scene = scene;
        return () => {
            unsubscribe();
            scene.destroy();
        };
    }, [def]);

    return <div ref={container} className="globe" data-testid="globe" />;
}
