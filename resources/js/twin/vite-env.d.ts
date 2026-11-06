/// <reference types="vite/client" />

interface ImportMetaEnv {
    /** Slippy-map tile URL with {z}/{x}/{y}; adds a "Custom tiles" basemap. */
    readonly VITE_BASEMAP_URL?: string;
    /** Attribution your tile provider requires. */
    readonly VITE_BASEMAP_ATTRIBUTION?: string;
}
