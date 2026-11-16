export type User = {
    id: string;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};

export type OrganisationRole = 'owner' | 'admin' | 'operator' | 'viewer';

export type SharedOrganisation = {
    current: {
        id: string;
        name: string;
        role: OrganisationRole;
        /** The optional features on for it, such as `photo-processing`. */
        features: string[];
    } | null;
    all: { id: string; name: string }[];
};
