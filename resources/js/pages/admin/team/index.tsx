import { Head, router, usePage } from '@inertiajs/react';
import {
    Badge,
    BlockStack,
    Button,
    Card,
    DataTable,
    InlineStack,
    Page,
    Select,
    Text,
    TextField,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type AdminUserRow = {
    id: number;
    name: string;
    email: string;
    role: 'superadmin' | 'support' | 'readonly';
    two_factor_enabled: boolean;
    created_at: string | null;
};

const ROLE_OPTIONS = [
    { label: 'Superadmin', value: 'superadmin' },
    { label: 'Support', value: 'support' },
    { label: 'Readonly', value: 'readonly' },
];

export default function AdminTeamIndex({ admins }: { admins: AdminUserRow[] }) {
    const { props } = usePage<{ auth: { user: { id: number } | null } }>();
    const currentAdminId = props.auth?.user?.id;

    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [role, setRole] = useState<'superadmin' | 'support' | 'readonly'>(
        'support',
    );

    const create = () => {
        router.post(
            '/admin/team',
            { name, email, password, role },
            {
                onSuccess: () => {
                    setName('');
                    setEmail('');
                    setPassword('');
                    setRole('support');
                },
            },
        );
    };

    const changeRole = (admin: AdminUserRow, newRole: string) => {
        router.put(`/admin/team/${admin.id}/role`, { role: newRole });
    };

    const reset2fa = (admin: AdminUserRow) => {
        if (confirm(`Reset 2FA for ${admin.name}?`)) {
            router.post(`/admin/team/${admin.id}/reset-2fa`);
        }
    };

    const destroy = (admin: AdminUserRow) => {
        if (confirm(`Remove admin "${admin.name}"? This cannot be undone.`)) {
            router.delete(`/admin/team/${admin.id}`);
        }
    };

    const rows = admins.map((admin) => {
        const isSelf = admin.id === currentAdminId;

        return [
            admin.name,
            admin.email,
            isSelf ? (
                <Badge key={`role-${admin.id}`}>{admin.role}</Badge>
            ) : (
                <Select
                    key={`role-${admin.id}`}
                    label="Role"
                    labelHidden
                    options={ROLE_OPTIONS}
                    value={admin.role}
                    onChange={(value) => changeRole(admin, value)}
                />
            ),
            admin.two_factor_enabled ? (
                <Badge key={`2fa-${admin.id}`} tone="success">
                    Enabled
                </Badge>
            ) : (
                <Badge key={`2fa-${admin.id}`}>Not set up</Badge>
            ),
            admin.created_at
                ? new Date(admin.created_at).toLocaleDateString()
                : '—',
            isSelf ? (
                <Text key={`actions-${admin.id}`} as="span" tone="subdued">
                    (you)
                </Text>
            ) : (
                <InlineStack key={`actions-${admin.id}`} gap="200">
                    <Button onClick={() => reset2fa(admin)}>Reset 2FA</Button>
                    <Button tone="critical" onClick={() => destroy(admin)}>
                        Remove
                    </Button>
                </InlineStack>
            ),
        ];
    });

    return (
        <>
            <Head title="Admin Team" />
            <Page
                title="Admin Team"
                subtitle="Manage admin users, roles, and 2FA"
                fullWidth
            >
                <BlockStack gap="400">
                    <Card>
                        <BlockStack gap="300">
                            <Text as="h2" variant="headingMd">
                                New admin user
                            </Text>
                            <InlineStack gap="300" wrap>
                                <TextField
                                    label="Name"
                                    value={name}
                                    onChange={setName}
                                    autoComplete="off"
                                />
                                <TextField
                                    label="Email"
                                    type="email"
                                    value={email}
                                    onChange={setEmail}
                                    autoComplete="off"
                                />
                                <TextField
                                    label="Password"
                                    type="password"
                                    value={password}
                                    onChange={setPassword}
                                    autoComplete="off"
                                />
                                <Select
                                    label="Role"
                                    options={ROLE_OPTIONS}
                                    value={role}
                                    onChange={(v) => setRole(v as typeof role)}
                                />
                            </InlineStack>
                            <InlineStack gap="200">
                                <Button
                                    variant="primary"
                                    onClick={create}
                                    disabled={!name || !email || !password}
                                >
                                    Create admin user
                                </Button>
                            </InlineStack>
                        </BlockStack>
                    </Card>

                    <Card>
                        {rows.length > 0 ? (
                            <DataTable
                                columnContentTypes={[
                                    'text',
                                    'text',
                                    'text',
                                    'text',
                                    'text',
                                    'text',
                                ]}
                                headings={[
                                    'Name',
                                    'Email',
                                    'Role',
                                    '2FA',
                                    'Created',
                                    '',
                                ]}
                                rows={rows}
                            />
                        ) : (
                            <Text as="p" tone="subdued">
                                No admin users yet.
                            </Text>
                        )}
                    </Card>
                </BlockStack>
            </Page>
        </>
    );
}

AdminTeamIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
