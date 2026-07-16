import { Head, router } from '@inertiajs/react';
import {
    BlockStack,
    Button,
    Card,
    DataTable,
    InlineStack,
    Page,
    Pagination,
    Select,
    Text,
    TextField,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type AuditEntry = {
    id: number;
    admin_name: string | null;
    action: string;
    target_type: string | null;
    target_id: number | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    at: string;
};

type AdminOption = { id: number; name: string };

type Filters = {
    admin_id?: string;
    action?: string;
    target_type?: string;
    from?: string;
    to?: string;
};

type Props = {
    filters: Filters;
    admins: AdminOption[];
    entries: {
        data: AuditEntry[];
        current_page: number;
        last_page: number;
        total: number;
    };
};

function shortTargetType(targetType: string | null): string {
    if (!targetType) {
        return '—';
    }

    const parts = targetType.split('\\');

    return parts[parts.length - 1];
}

function summarizeChange(entry: AuditEntry): string {
    if (!entry.before && !entry.after) {
        return '—';
    }

    // Pure create (no before) or pure delete (no after): show the one side
    // that exists as plain field: value pairs, not a misleading "undefined → ...".
    if (!entry.before || !entry.after) {
        const values = entry.before ?? entry.after ?? {};

        return Object.entries(values)
            .slice(0, 3)
            .map(([key, value]) => `${key}: ${JSON.stringify(value)}`)
            .join(', ');
    }

    const keys = new Set([
        ...Object.keys(entry.before),
        ...Object.keys(entry.after),
    ]);

    return Array.from(keys)
        .slice(0, 3)
        .map(
            (key) =>
                `${key}: ${JSON.stringify(entry.before?.[key])} → ${JSON.stringify(entry.after?.[key])}`,
        )
        .join(', ');
}

export default function AuditLogIndex({ filters, admins, entries }: Props) {
    const [adminId, setAdminId] = useState(filters.admin_id ?? '');
    const [action, setAction] = useState(filters.action ?? '');
    const [targetType, setTargetType] = useState(filters.target_type ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    const adminOptions = [
        { label: 'All admins', value: '' },
        ...admins.map((a) => ({ label: a.name, value: String(a.id) })),
    ];

    const applyFilters = (next: Partial<Filters> = {}) => {
        router.get(
            '/admin/audit-log',
            {
                admin_id: adminId,
                action,
                target_type: targetType,
                from,
                to,
                ...next,
            },
            { preserveState: true, replace: true },
        );
    };

    const rows = entries.data.map((entry) => [
        new Date(entry.at).toLocaleString(),
        entry.admin_name ?? 'Unknown',
        entry.action,
        `${shortTargetType(entry.target_type)}${entry.target_id ? ` #${entry.target_id}` : ''}`,
        summarizeChange(entry),
    ]);

    return (
        <>
            <Head title="Audit Log" />
            <Page
                title="Audit Log"
                subtitle={`${entries.total} total entries`}
                fullWidth
            >
                <BlockStack gap="400">
                    <Card>
                        <InlineStack gap="300" wrap blockAlign="end">
                            <Select
                                label="Admin"
                                options={adminOptions}
                                value={adminId}
                                onChange={(value) => {
                                    setAdminId(value);
                                    applyFilters({ admin_id: value });
                                }}
                            />
                            <TextField
                                label="Action contains"
                                value={action}
                                onChange={setAction}
                                autoComplete="off"
                                onBlur={() => applyFilters({ action })}
                            />
                            <TextField
                                label="Target type"
                                placeholder="e.g. App\Models\PromoCampaign"
                                value={targetType}
                                onChange={setTargetType}
                                autoComplete="off"
                                onBlur={() =>
                                    applyFilters({ target_type: targetType })
                                }
                            />
                            <TextField
                                label="From"
                                type="date"
                                value={from}
                                onChange={setFrom}
                                autoComplete="off"
                                onBlur={() => applyFilters({ from })}
                            />
                            <TextField
                                label="To"
                                type="date"
                                value={to}
                                onChange={setTo}
                                autoComplete="off"
                                onBlur={() => applyFilters({ to })}
                            />
                            <Button onClick={() => applyFilters()}>
                                Search
                            </Button>
                        </InlineStack>
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
                                ]}
                                headings={[
                                    'When',
                                    'Admin',
                                    'Action',
                                    'Target',
                                    'Change',
                                ]}
                                rows={rows}
                            />
                        ) : (
                            <Text as="p" tone="subdued">
                                No audit log entries match these filters.
                            </Text>
                        )}
                    </Card>

                    {entries.last_page > 1 && (
                        <InlineStack align="center">
                            <Pagination
                                hasPrevious={entries.current_page > 1}
                                onPrevious={() =>
                                    router.get('/admin/audit-log', {
                                        admin_id: adminId,
                                        action,
                                        target_type: targetType,
                                        from,
                                        to,
                                        page: entries.current_page - 1,
                                    })
                                }
                                hasNext={
                                    entries.current_page < entries.last_page
                                }
                                onNext={() =>
                                    router.get('/admin/audit-log', {
                                        admin_id: adminId,
                                        action,
                                        target_type: targetType,
                                        from,
                                        to,
                                        page: entries.current_page + 1,
                                    })
                                }
                            />
                        </InlineStack>
                    )}
                </BlockStack>
            </Page>
        </>
    );
}

AuditLogIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
