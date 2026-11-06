import { Head } from '@inertiajs/react';
import { useEffect } from 'react';
import { boot, stopTwin } from '@/twin/state/store';
import { Viewer } from '@/twin/ui/Viewer';

/** The bundled sample twins, run in the browser with no account. */
export default function Demo() {
    useEffect(() => {
        boot();
        return stopTwin;
    }, []);

    return (
        <>
            <Head title="Sample twins" />
            <Viewer />
        </>
    );
}
