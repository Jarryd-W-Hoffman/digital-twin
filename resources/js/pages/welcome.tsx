import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, demo, login, register } from '@/routes';

export default function Welcome({
    canRegister = true,
}: {
    canRegister?: boolean;
}) {
    const { auth } = usePage().props;
    const button =
        'inline-block rounded-md border px-5 py-2 text-sm font-medium transition-colors';

    return (
        <>
            <Head title="Meridian" />
            <div className="flex min-h-screen flex-col bg-background p-6 text-foreground">
                <header className="flex items-center justify-between">
                    <div className="flex items-center gap-2 font-semibold">
                        <AppLogoIcon className="size-6" />
                        Meridian
                    </div>
                    <nav className="flex gap-3">
                        {auth.user ? (
                            <Link
                                href={dashboard()}
                                className={`${button} border-border`}
                            >
                                Dashboard
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href={login()}
                                    className={`${button} border-transparent`}
                                >
                                    Log in
                                </Link>
                                {canRegister && (
                                    <Link
                                        href={register()}
                                        className={`${button} border-border`}
                                    >
                                        Register
                                    </Link>
                                )}
                            </>
                        )}
                    </nav>
                </header>
                <main className="mx-auto flex max-w-2xl flex-1 flex-col items-start justify-center gap-6">
                    <h1 className="text-4xl font-semibold tracking-tight">
                        Digital twins on a real globe
                    </h1>
                    <p className="text-lg text-muted-foreground">
                        Model a site as data, run its physics live, upload
                        models and 3D Tiles, place them in the scene, and keep
                        every change as a version your team can restore.
                    </p>
                    <Link
                        href={demo()}
                        className={`${button} border-primary bg-primary text-primary-foreground`}
                    >
                        Open the sample twins
                    </Link>
                </main>
            </div>
        </>
    );
}
