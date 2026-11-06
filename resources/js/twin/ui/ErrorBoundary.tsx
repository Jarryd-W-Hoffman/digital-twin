import { Component, type ReactNode } from 'react';

/** Contains a render failure to one panel, so the scene and the rest keep running. */
export class ErrorBoundary extends Component<
    { children: ReactNode; label: string },
    { message?: string }
> {
    override state: { message?: string } = {};

    static getDerivedStateFromError(error: unknown) {
        return {
            message: error instanceof Error ? error.message : String(error),
        };
    }

    override render() {
        if (this.state.message === undefined) return this.props.children;
        return (
            <div className="panel-error" role="alert">
                <strong>{this.props.label} stopped working.</strong>
                <p className="muted">{this.state.message}</p>
                <button onClick={() => this.setState({ message: undefined })}>
                    Try again
                </button>
            </div>
        );
    }
}
