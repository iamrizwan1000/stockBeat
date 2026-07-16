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

type BadgeTone = 'success' | 'info' | 'attention' | 'critical' | undefined;

type BroadcastSummary = {
    id: number;
    audience_type: string;
    segment_name: string | null;
    recipient_email: string | null;
    channels: string[];
    title: string;
    status: string;
    scheduled_at: string | null;
    sent_at: string | null;
    created_by_name: string | null;
    created_at: string | null;
};

type Filters = {
    q?: string;
    status?: string;
};

const STATUS_TONE: Record<string, BadgeTone> = {
    draft: undefined,
    scheduled: 'info',
    sending: 'attention',
    sent: 'success',
    failed: 'critical',
};

const STATUS_OPTIONS = [
    { label: 'All statuses', value: '' },
    { label: 'Draft', value: 'draft' },
    { label: 'Scheduled', value: 'scheduled' },
    { label: 'Sending', value: 'sending' },
    { label: 'Sent', value: 'sent' },
    { label: 'Failed', value: 'failed' },
];

function audienceLabel(broadcast: BroadcastSummary): string {
    if (broadcast.audience_type === 'all') {
        return 'All users';
    }

    if (broadcast.audience_type === 'segment') {
        return broadcast.segment_name ?? 'Segment';
    }

    return broadcast.recipient_email ?? 'Single user';
}

export default function BroadcastsIndex({
    broadcasts,
    filters,
}: {
    broadcasts: BroadcastSummary[];
    filters: Filters;
}) {
    const [queryValue, setQueryValue] = useState(filters.q ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const { mode, setMode } = useSetIndexFiltersMode(IndexFiltersMode.Default);

    const applyFilters = (next: Partial<Filters> = {}) => {
        router.get(
            '/admin/broadcasts',
            { q: queryValue, status, ...next },
            { preserveState: true, replace: true },
        );
    };

    const clearAll = () => {
        setQueryValue('');
        setStatus('');
        router.get(
            '/admin/broadcasts',
            {},
            { preserveState: true, replace: true },
        );
    };

    const appliedFilters = status
        ? [
              {
                  key: 'status',
                  label: `Status: ${STATUS_OPTIONS.find((o) => o.value === status)?.label ?? status}`,
                  onRemove: () => {
                      setStatus('');
                      applyFilters({ status: '' });
                  },
              },
          ]
        : [];

    const rowMarkup = broadcasts.map((broadcast, index) => (
        <IndexTable.Row
            id={String(broadcast.id)}
            key={broadcast.id}
            position={index}
        >
            <IndexTable.Cell>
                <Link href={`/admin/broadcasts/${broadcast.id}`}>
                    <Text as="span" fontWeight="semibold">
                        {broadcast.title}
                    </Text>
                </Link>
            </IndexTable.Cell>
            <IndexTable.Cell>{audienceLabel(broadcast)}</IndexTable.Cell>
            <IndexTable.Cell>{broadcast.channels.join(', ')}</IndexTable.Cell>
            <IndexTable.Cell>
                <Badge tone={STATUS_TONE[broadcast.status]}>
                    {broadcast.status}
                </Badge>
            </IndexTable.Cell>
            <IndexTable.Cell>
                {broadcast.created_by_name ?? '—'}
            </IndexTable.Cell>
            <IndexTable.Cell>
                {broadcast.created_at
                    ? new Date(broadcast.created_at).toLocaleString()
                    : '—'}
            </IndexTable.Cell>
        </IndexTable.Row>
    ));

    return (
        <>
            <Head title="Broadcasts" />
            <Page
                title="Broadcasts"
                subtitle="Admin → user messaging"
                fullWidth
                primaryAction={{
                    content: 'New broadcast',
                    url: '/admin/broadcasts/create',
                }}
            >
                <Card padding="0">
                    <IndexFilters
                        queryValue={queryValue}
                        queryPlaceholder="Search by title"
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
                        ]}
                        appliedFilters={appliedFilters}
                        onClearAll={clearAll}
                    />
                    <IndexTable
                        resourceName={{
                            singular: 'broadcast',
                            plural: 'broadcasts',
                        }}
                        itemCount={broadcasts.length}
                        selectable={false}
                        headings={[
                            { title: 'Title' },
                            { title: 'Audience' },
                            { title: 'Channels' },
                            { title: 'Status' },
                            { title: 'Created by' },
                            { title: 'Created' },
                        ]}
                        emptyState={
                            <Box padding="400">
                                <Text as="p" tone="subdued" alignment="center">
                                    No broadcasts match these filters.
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

BroadcastsIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
