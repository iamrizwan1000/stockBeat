import { Head, router } from '@inertiajs/react';
import {
    Badge,
    Box,
    Card,
    IndexFilters,
    IndexFiltersMode,
    IndexTable,
    Page,
    Pagination,
    Select,
    Text,
    useSetIndexFiltersMode,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import PolarisDateField from '@/components/PolarisDateField';
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
    q?: string;
    admin_id?: string;
    action?: string;
    target_type?: string;
    from?: string;
    to?: string;
};

type Props = {
    filters: Filters;
    admins: AdminOption[];
    filter_options: { actions: string[]; target_types: string[] };
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

export default function AuditLogIndex({
    filters,
    admins,
    filter_options: filterOptions,
    entries,
}: Props) {
    const [queryValue, setQueryValue] = useState(filters.q ?? '');
    const [adminId, setAdminId] = useState(filters.admin_id ?? '');
    const [action, setAction] = useState(filters.action ?? '');
    const [targetType, setTargetType] = useState(filters.target_type ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const { mode, setMode } = useSetIndexFiltersMode(IndexFiltersMode.Default);

    const adminOptions = [
        { label: 'All admins', value: '' },
        ...admins.map((a) => ({ label: a.name, value: String(a.id) })),
    ];
    const actionOptions = [
        { label: 'All actions', value: '' },
        ...filterOptions.actions.map((a) => ({ label: a, value: a })),
    ];
    const targetTypeOptions = [
        { label: 'All target types', value: '' },
        ...filterOptions.target_types.map((t) => ({
            label: shortTargetType(t),
            value: t,
        })),
    ];

    const applyFilters = (next: Partial<Filters> = {}) => {
        router.get(
            '/admin/audit-log',
            {
                q: queryValue,
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

    const clearAll = () => {
        setQueryValue('');
        setAdminId('');
        setAction('');
        setTargetType('');
        setFrom('');
        setTo('');
        router.get(
            '/admin/audit-log',
            {},
            { preserveState: true, replace: true },
        );
    };

    const appliedFilters = [
        adminId
            ? {
                  key: 'admin_id',
                  label: `Admin: ${admins.find((a) => String(a.id) === adminId)?.name ?? adminId}`,
                  onRemove: () => {
                      setAdminId('');
                      applyFilters({ admin_id: '' });
                  },
              }
            : null,
        action
            ? {
                  key: 'action',
                  label: `Action: ${action}`,
                  onRemove: () => {
                      setAction('');
                      applyFilters({ action: '' });
                  },
              }
            : null,
        targetType
            ? {
                  key: 'target_type',
                  label: `Target: ${shortTargetType(targetType)}`,
                  onRemove: () => {
                      setTargetType('');
                      applyFilters({ target_type: '' });
                  },
              }
            : null,
        from
            ? {
                  key: 'from',
                  label: `From: ${from}`,
                  onRemove: () => {
                      setFrom('');
                      applyFilters({ from: '' });
                  },
              }
            : null,
        to
            ? {
                  key: 'to',
                  label: `To: ${to}`,
                  onRemove: () => {
                      setTo('');
                      applyFilters({ to: '' });
                  },
              }
            : null,
    ].filter((f): f is NonNullable<typeof f> => f !== null);

    const rowMarkup = entries.data.map((entry, index) => (
        <IndexTable.Row id={String(entry.id)} key={entry.id} position={index}>
            <IndexTable.Cell>
                <Text as="span" tone="subdued">
                    {new Date(entry.at).toLocaleString()}
                </Text>
            </IndexTable.Cell>
            <IndexTable.Cell>{entry.admin_name ?? 'Unknown'}</IndexTable.Cell>
            <IndexTable.Cell>
                <Badge>{entry.action}</Badge>
            </IndexTable.Cell>
            <IndexTable.Cell>
                {shortTargetType(entry.target_type)}
                {entry.target_id ? ` #${entry.target_id}` : ''}
            </IndexTable.Cell>
            <IndexTable.Cell>
                <Text as="span" tone="subdued">
                    {summarizeChange(entry)}
                </Text>
            </IndexTable.Cell>
        </IndexTable.Row>
    ));

    return (
        <>
            <Head title="Audit Log" />
            <Page
                title="Audit Log"
                subtitle={`${entries.total} total entries`}
                fullWidth
            >
                <Card padding="0">
                    <IndexFilters
                        queryValue={queryValue}
                        queryPlaceholder="Search by action or admin name"
                        onQueryChange={setQueryValue}
                        onQueryBlur={() => applyFilters({ q: queryValue })}
                        onQueryClear={() => {
                            setQueryValue('');
                            applyFilters({ q: '' });
                        }}
                        cancelAction={{
                            onAction: () => setMode(IndexFiltersMode.Default),
                        }}
                        mode={mode}
                        setMode={setMode}
                        tabs={[]}
                        selected={0}
                        onSelect={() => {}}
                        canCreateNewView={false}
                        filters={[
                            {
                                key: 'admin_id',
                                label: 'Admin',
                                filter: (
                                    <Select
                                        label="Admin"
                                        labelHidden
                                        options={adminOptions}
                                        value={adminId}
                                        onChange={(value) => {
                                            setAdminId(value);
                                            applyFilters({ admin_id: value });
                                        }}
                                    />
                                ),
                            },
                            {
                                key: 'action',
                                label: 'Action',
                                filter: (
                                    <Select
                                        label="Action"
                                        labelHidden
                                        options={actionOptions}
                                        value={action}
                                        onChange={(value) => {
                                            setAction(value);
                                            applyFilters({ action: value });
                                        }}
                                    />
                                ),
                            },
                            {
                                key: 'target_type',
                                label: 'Target type',
                                filter: (
                                    <Select
                                        label="Target type"
                                        labelHidden
                                        options={targetTypeOptions}
                                        value={targetType}
                                        onChange={(value) => {
                                            setTargetType(value);
                                            applyFilters({
                                                target_type: value,
                                            });
                                        }}
                                    />
                                ),
                            },
                            {
                                key: 'from',
                                label: 'From',
                                filter: (
                                    <PolarisDateField
                                        label="From"
                                        labelHidden
                                        value={from}
                                        onChange={(value) => {
                                            setFrom(value);
                                            applyFilters({ from: value });
                                        }}
                                    />
                                ),
                            },
                            {
                                key: 'to',
                                label: 'To',
                                filter: (
                                    <PolarisDateField
                                        label="To"
                                        labelHidden
                                        value={to}
                                        onChange={(value) => {
                                            setTo(value);
                                            applyFilters({ to: value });
                                        }}
                                    />
                                ),
                            },
                        ]}
                        appliedFilters={appliedFilters}
                        onClearAll={clearAll}
                    />
                    <IndexTable
                        resourceName={{ singular: 'entry', plural: 'entries' }}
                        itemCount={entries.data.length}
                        selectable={false}
                        headings={[
                            { title: 'When' },
                            { title: 'Admin' },
                            { title: 'Action' },
                            { title: 'Target' },
                            { title: 'Change' },
                        ]}
                        emptyState={
                            <Box padding="400">
                                <Text as="p" tone="subdued" alignment="center">
                                    No audit log entries match these filters.
                                </Text>
                            </Box>
                        }
                    >
                        {rowMarkup}
                    </IndexTable>
                </Card>

                {entries.last_page > 1 && (
                    <Box padding="400">
                        <div
                            style={{
                                display: 'flex',
                                justifyContent: 'center',
                            }}
                        >
                            <Pagination
                                hasPrevious={entries.current_page > 1}
                                onPrevious={() =>
                                    router.get('/admin/audit-log', {
                                        q: queryValue,
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
                                        q: queryValue,
                                        admin_id: adminId,
                                        action,
                                        target_type: targetType,
                                        from,
                                        to,
                                        page: entries.current_page + 1,
                                    })
                                }
                            />
                        </div>
                    </Box>
                )}
            </Page>
        </>
    );
}

AuditLogIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
