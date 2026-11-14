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
            /** A new device password, shown once. */
            deviceCredentials?: {
                deviceId: string;
                username: string;
                password: string;
            };
            /** A new API token, shown once. */
            apiToken?: {
                tokenId: string;
                token: string;
            };
            /** A new provisioning (SCIM) token, shown once. */
            scimToken?: string;
            /** A new webhook channel's signing secret. */
            channelSecret?: {
                channelId: string;
                secret: string;
            };
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
