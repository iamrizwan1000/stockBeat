import { Head, Link, router } from '@inertiajs/react';
import {
    Badge,
    Box,
    Card,
    IndexFilters,
    IndexFiltersMode,
    IndexTable,
    Page,
    Select,
    Text,
    useSetIndexFiltersMode,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type BadgeTone = 'success' | 'info' | 'attention' | undefined;

type ThreadSummary = {
    id: number;
    user_name: string;
    user_email: string;
    status: string;
    priority: string;
    assigned_admin_name: string | null;
    last_message_at: string | null;
};

type Filters = {
    status: string | null;
    unassigned: boolean;
};

const STATUS_OPTIONS = [
    { label: 'All statuses', value: '' },
    { label: 'Open', value: 'open' },
    { label: 'Awaiting user', value: 'awaiting_user' },
    { label: 'Resolved', value: 'resolved' },
];

const UNASSIGNED_OPTIONS = [
    { label: 'Anyone', value: '' },
    { label: 'Unassigned only', value: 'true' },
];

const STATUS_TONE: Record<string, BadgeTone> = {
    open: 'attention',
    awaiting_user: 'info',
    resolved: 'success',
};

export default function SupportIndex({
    threads,
    filters,
}: {
    threads: ThreadSummary[];
    filters: Filters;
}) {
    const [status, setStatus] = useState(filters.status ?? '');
    const [unassigned, setUnassigned] = useState(
        filters.unassigned ? 'true' : '',
    );
    const { mode, setMode } = useSetIndexFiltersMode(IndexFiltersMode.Default);

    const applyFilters = (
        next: Partial<{ status: string; unassigned: string }>,
    ) => {
        router.get(
            '/admin/support',
            { status, unassigned, ...next },
            { preserveState: true, replace: true },
        );
    };

    const clearAll = () => {
        setStatus('');
        setUnassigned('');
        router.get(
            '/admin/support',
            {},
            { preserveState: true, replace: true },
        );
    };

    const appliedFilters = [
        status
            ? {
                  key: 'status',
                  label: `Status: ${STATUS_OPTIONS.find((o) => o.value === status)?.label ?? status}`,
                  onRemove: () => {
                      setStatus('');
                      applyFilters({ status: '' });
                  },
              }
            : null,
        unassigned
            ? {
                  key: 'unassigned',
                  label: 'Unassigned only',
                  onRemove: () => {
                      setUnassigned('');
                      applyFilters({ unassigned: '' });
                  },
              }
            : null,
    ].filter((f): f is NonNullable<typeof f> => f !== null);

    const rowMarkup = threads.map((thread, index) => (
        <IndexTable.Row id={String(thread.id)} key={thread.id} position={index}>
            <IndexTable.Cell>
                <Link href={`/admin/support/${thread.id}`}>
                    <Text as="span" fontWeight="semibold">
                        {thread.user_name || thread.user_email}
                    </Text>
                </Link>
            </IndexTable.Cell>
            <IndexTable.Cell>
                <Badge tone={STATUS_TONE[thread.status]}>
                    {thread.status.replace('_', ' ')}
                </Badge>
            </IndexTable.Cell>
            <IndexTable.Cell>
                {thread.priority === 'high' ? (
                    <Badge tone="critical">High</Badge>
                ) : (
                    '—'
                )}
            </IndexTable.Cell>
            <IndexTable.Cell>
                {thread.assigned_admin_name ?? 'Unassigned'}
            </IndexTable.Cell>
            <IndexTable.Cell>
                {thread.last_message_at
                    ? new Date(thread.last_message_at).toLocaleString()
                    : '—'}
            </IndexTable.Cell>
        </IndexTable.Row>
    ));

    return (
        <>
            <Head title="Support Inbox" />
            <Page title="Support Inbox" fullWidth>
                <Card padding="0">
                    <IndexFilters
                        queryValue=""
                        onQueryChange={() => {}}
                        onQueryClear={() => {}}
                        hideQueryField
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
                                key: 'status',
                                label: 'Status',
                                filter: (
                                    <Select
                                        label="Status"
                                        labelHidden
                                        options={STATUS_OPTIONS}
                                        value={status}
                                        onChange={(value) => {
                                            setStatus(value);
                                            applyFilters({ status: value });
                                        }}
                                    />
                                ),
                            },
                            {
                                key: 'unassigned',
                                label: 'Assignment',
                                filter: (
                                    <Select
                                        label="Assignment"
                                        labelHidden
                                        options={UNASSIGNED_OPTIONS}
                                        value={unassigned}
                                        onChange={(value) => {
                                            setUnassigned(value);
                                            applyFilters({ unassigned: value });
                                        }}
                                    />
                                ),
                            },
                        ]}
                        appliedFilters={appliedFilters}
                        onClearAll={clearAll}
                    />
                    <IndexTable
                        resourceName={{ singular: 'thread', plural: 'threads' }}
                        itemCount={threads.length}
                        selectable={false}
                        headings={[
                            { title: 'User' },
                            { title: 'Status' },
                            { title: 'Priority' },
                            { title: 'Assigned to' },
                            { title: 'Last message' },
                        ]}
                        emptyState={
                            <Box padding="400">
                                <Text as="p" tone="subdued" alignment="center">
                                    No threads match these filters.
                                </Text>
                            </Box>
                        }
                    >
                        {rowMarkup}
                    </IndexTable>
                </Card>
            </Page>
        </>
    );
}

SupportIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
