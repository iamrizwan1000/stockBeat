import { Head, router, usePage } from '@inertiajs/react';
import {
    Badge,
    BlockStack,
    Box,
    Button,
    Card,
    IndexFilters,
    IndexFiltersMode,
    IndexTable,
    InlineStack,
    Page,
    Select,
    Text,
    TextField,
    useSetIndexFiltersMode,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';

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
    const [queryValue, setQueryValue] = useState('');
    const { mode, setMode } = useSetIndexFiltersMode(IndexFiltersMode.Default);

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

    const filteredAdmins = useMemo(() => {
        if (!queryValue) {
            return admins;
        }

        const q = queryValue.toLowerCase();

        return admins.filter(
            (a) =>
                a.name.toLowerCase().includes(q) ||
                a.email.toLowerCase().includes(q),
        );
    }, [admins, queryValue]);

    const rowMarkup = filteredAdmins.map((admin, index) => {
        const isSelf = admin.id === currentAdminId;

        return (
            <IndexTable.Row
                id={String(admin.id)}
                key={admin.id}
                position={index}
            >
                <IndexTable.Cell>
                    <Text as="span" fontWeight="semibold">
                        {admin.name}
                    </Text>
                </IndexTable.Cell>
                <IndexTable.Cell>{admin.email}</IndexTable.Cell>
                <IndexTable.Cell>
                    {isSelf ? (
                        <Badge>{admin.role}</Badge>
                    ) : (
                        <Select
                            label="Role"
                            labelHidden
                            options={ROLE_OPTIONS}
                            value={admin.role}
                            onChange={(value) => changeRole(admin, value)}
                        />
                    )}
                </IndexTable.Cell>
                <IndexTable.Cell>
                    {admin.two_factor_enabled ? (
                        <Badge tone="success">Enabled</Badge>
                    ) : (
                        <Badge>Not set up</Badge>
                    )}
                </IndexTable.Cell>
                <IndexTable.Cell>
                    {admin.created_at
                        ? new Date(admin.created_at).toLocaleDateString()
                        : '—'}
                </IndexTable.Cell>
                <IndexTable.Cell>
                    {isSelf ? (
                        <Text as="span" tone="subdued">
                            (you)
                        </Text>
                    ) : (
                        <InlineStack gap="200">
                            <Button onClick={() => reset2fa(admin)}>
                                Reset 2FA
                            </Button>
                            <Button
                                tone="critical"
                                onClick={() => destroy(admin)}
                            >
                                Remove
                            </Button>
                        </InlineStack>
                    )}
                </IndexTable.Cell>
            </IndexTable.Row>
        );
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

                    <Card padding="0">
                        <IndexFilters
                            queryValue={queryValue}
                            queryPlaceholder="Search by name or email"
                            onQueryChange={setQueryValue}
                            onQueryClear={() => setQueryValue('')}
                            cancelAction={{
                                onAction: () =>
                                    setMode(IndexFiltersMode.Default),
                            }}
                            mode={mode}
                            setMode={setMode}
                            tabs={[]}
                            selected={0}
                            onSelect={() => {}}
                            canCreateNewView={false}
                            filters={[]}
                            appliedFilters={[]}
                            onClearAll={() => setQueryValue('')}
                        />
                        <IndexTable
                            resourceName={{
                                singular: 'admin',
                                plural: 'admins',
                            }}
                            itemCount={filteredAdmins.length}
                            selectable={false}
                            headings={[
                                { title: 'Name' },
                                { title: 'Email' },
                                { title: 'Role' },
                                { title: '2FA' },
                                { title: 'Created' },
                                { title: '' },
                            ]}
                            emptyState={
                                <Box padding="400">
                                    <Text
                                        as="p"
                                        tone="subdued"
                                        alignment="center"
                                    >
                                        No admin users match this search.
                                    </Text>
                                </Box>
                            }
                        >
                            {rowMarkup}
                        </IndexTable>
                    </Card>
                </BlockStack>
            </Page>
        </>
    );
}

AdminTeamIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
