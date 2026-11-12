import { router, useForm } from '@inertiajs/react';
import TwinController from '@/actions/App/Http/Controllers/TwinController';

/**
 * A twin's own settings, for editors: its name and description, and
 * deleting it with its history. Its uploads stay in the project's library.
 */
export function TwinSettings({
    twin,
    onClose,
}: {
    twin: { id: string; name: string; description: string | null };
    onClose: () => void;
}) {
    const form = useForm({
        name: twin.name,
        description: twin.description ?? '',
    });
    const changed =
        form.data.name !== twin.name ||
        form.data.description !== (twin.description ?? '');

    return (
        <aside className="panel editor" aria-label="Settings">
            <div className="editor-head">
                <h2>Settings</h2>
                <button
                    className="ghost"
                    onClick={onClose}
                    aria-label="Close settings"
                >
                    ✕
                </button>
            </div>

            <section>
                <h3>Twin</h3>
                <form
                    className="editor-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.submit(TwinController.update(twin.id), {
                            preserveScroll: true,
                        });
                    }}
                >
                    <label className="editor-field">
                        <span>Name</span>
                        <input
                            value={form.data.name}
                            maxLength={120}
                            required
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                    </label>
                    {form.errors.name && (
                        <p className="editor-error" role="alert">
                            {form.errors.name}
                        </p>
                    )}
                    <label className="editor-field">
                        <span>Description</span>
                        <textarea
                            value={form.data.description}
                            maxLength={2000}
                            rows={3}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                        />
                    </label>
                    {form.errors.description && (
                        <p className="editor-error" role="alert">
                            {form.errors.description}
                        </p>
                    )}
                    <div className="editor-actions">
                        <button
                            type="submit"
                            className="primary"
                            disabled={!changed || form.processing}
                        >
                            Save
                        </button>
                        {form.recentlySuccessful && (
                            <span className="muted" role="status">
                                Saved
                            </span>
                        )}
                    </div>
                </form>
            </section>

            <section>
                <h3>Delete</h3>
                <p className="muted hint">
                    Deletes the twin and all of its versions. Its uploads stay
                    in the project's library for its other twins. This can't be
                    undone.
                </p>
                <div className="editor-actions">
                    <button
                        className="danger"
                        onClick={() =>
                            confirm(
                                `Delete ${twin.name} and all of its versions? This can't be undone.`,
                            ) &&
                            router.delete(TwinController.destroy.url(twin.id))
                        }
                    >
                        Delete twin
                    </button>
                </div>
            </section>
        </aside>
    );
}
