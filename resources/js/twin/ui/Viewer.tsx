import '@fontsource/ibm-plex-sans/400.css';
import '@fontsource/ibm-plex-sans/500.css';
import '@fontsource/ibm-plex-sans/600.css';
import '@fontsource/ibm-plex-mono/400.css';
import '@fontsource/ibm-plex-mono/500.css';
import type { ReactNode } from 'react';
import { useTwin } from '../state/store.ts';
import { App } from './App.tsx';
import './styles.css';

// Must be set before Cesium loads; scripts/copy-cesium.mjs puts its assets here.
(window as unknown as { CESIUM_BASE_URL: string }).CESIUM_BASE_URL =
    '/vendor/cesium/';

// Inspection hook for the end-to-end tests: the viewer's own client state.
(window as unknown as { __twin: typeof useTwin }).__twin = useTwin;

/**
 * The full-screen twin viewer. `header` replaces the sample picker when the
 * control plane hosts it; `children` are extra panels such as the editor.
 */
export function Viewer({
    header,
    children,
}: {
    header?: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className="twin-root">
            <App header={header}>{children}</App>
        </div>
    );
}
