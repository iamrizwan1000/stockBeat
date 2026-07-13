export type AdminUser = {
    id: number;
    name: string;
    email: string;
    role: 'superadmin' | 'support' | 'readonly';
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: AdminUser;
};
