import type { Auth, SharedOrganisation } from '@/types/auth';
import type { FlashToast } from '@/types/ui';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        flashDataType: {
            toast?: FlashToast;
            /** A new gateway token, shown once. */
            gatewayToken?: string;
        };
        sharedPageProps: {
            name: string;
            auth: Auth;
            organisation: SharedOrganisation | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
