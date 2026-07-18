import { Head, Link, router } from '@inertiajs/react';
import {
    Badge,
    BlockStack,
    Box,
    Button,
    Card,
    IndexFilters,
    IndexFiltersMode,
    IndexTable,
    InlineGrid,
    InlineStack,
    Page,
    Select,
    Tabs,
    Text,
    useSetIndexFiltersMode,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import PolarisDateField from '@/components/PolarisDateField';
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

type TimingStat = {
    avg_minutes: number | null;
    median_minutes: number | null;
    sample_size: number;
};

type AgentStat = {
    admin_id: number;
    admin_name: string;
    assigned_total: number;
    resolved_in_period: number;
    avg_resolution_minutes: number | null;
};

type SlaMetrics = {
    period: { from: string; to: string };
    first_response: TimingStat;
    resolution: TimingStat;
    agents: AgentStat[];
    csat: {
        positive: number;
        negative: number;
        total: number;
        positive_pct: number | null;
    };
};

type SlaFilters = {
    from: string | null;
    to: string | null;
};

function formatMinutes(minutes: number | null): string {
    if (minutes === null) {
        return '—';
    }

    if (minutes < 60) {
        return `${Math.round(minutes)} min`;
    }

    const hours = minutes / 60;

    if (hours < 24) {
        return `${hours.toFixed(1)} hr`;
    }

    return `${(hours / 24).toFixed(1)} days`;
}

function SlaMetricsPanel({
    sla,
    slaFilters,
}: {
    sla: SlaMetrics;
    slaFilters: SlaFilters;
}) {
    const [from, setFrom] = useState(slaFilters.from ?? '');
    const [to, setTo] = useState(slaFilters.to ?? '');

    const applyRange = () => {
        router.get(
            '/admin/support',
            { sla_from: from || undefined, sla_to: to || undefined },
            { preserveState: true, replace: true, preserveScroll: true },
        );
    };

    const agentRows = sla.agents.map((agent, index) => (
        <IndexTable.Row
            id={String(agent.admin_id)}
            key={agent.admin_id}
            position={index}
        >
            <IndexTable.Cell>
                <Text as="span" fontWeight="semibold">
                    {agent.admin_name}
                </Text>
            </IndexTable.Cell>
            <IndexTable.Cell>{agent.assigned_total}</IndexTable.Cell>
            <IndexTable.Cell>{agent.resolved_in_period}</IndexTable.Cell>
            <IndexTable.Cell>
                {formatMinutes(agent.avg_resolution_minutes)}
            </IndexTable.Cell>
        </IndexTable.Row>
    ));

    return (
        <BlockStack gap="400">
            <Card>
                <BlockStack gap="300">
                    <Text as="h2" variant="headingMd">
                        Reporting period
                    </Text>
                    <InlineStack gap="300" blockAlign="end" wrap>
                        <PolarisDateField
                            label="From"
                            value={from}
                            onChange={setFrom}
                        />
                        <PolarisDateField
                            label="To"
                            value={to}
                            onChange={setTo}
                        />
                        <Button onClick={applyRange}>Apply</Button>
                    </InlineStack>
                    <Text as="p" tone="subdued">
                        Showing {new Date(sla.period.from).toLocaleDateString()}{' '}
                        – {new Date(sla.period.to).toLocaleDateString()}{' '}
                        (defaults to the last 30 days).
                    </Text>
                </BlockStack>
            </Card>

            <InlineGrid columns={{ xs: 1, md: 3 }} gap="400">
                <Card>
                    <BlockStack gap="200">
                        <Text as="h3" variant="headingSm">
                            First response time
                        </Text>
                        <Text as="p" variant="heading2xl">
                            {formatMinutes(sla.first_response.avg_minutes)}
                        </Text>
                        <Text as="p" tone="subdued">
                            Median{' '}
                            {formatMinutes(sla.first_response.median_minutes)} ·
                            based on {sla.first_response.sample_size} threads
                            opened
                        </Text>
                    </BlockStack>
                </Card>
                <Card>
                    <BlockStack gap="200">
                        <Text as="h3" variant="headingSm">
                            Resolution time
                        </Text>
                        <Text as="p" variant="heading2xl">
                            {formatMinutes(sla.resolution.avg_minutes)}
                        </Text>
                        <Text as="p" tone="subdued">
                            Median{' '}
                            {formatMinutes(sla.resolution.median_minutes)} ·
                            based on {sla.resolution.sample_size} threads
                            resolved
                        </Text>
                    </BlockStack>
                </Card>
                <Card>
                    <BlockStack gap="200">
                        <Text as="h3" variant="headingSm">
                            CSAT
                        </Text>
                        <Text as="p" variant="heading2xl">
                            {sla.csat.positive_pct === null
                                ? '—'
                                : `${sla.csat.positive_pct}%`}
                        </Text>
                        <Text as="p" tone="subdued">
                            {sla.csat.positive} 👍 · {sla.csat.negative} 👎 ·{' '}
                            {sla.csat.total} rated
                        </Text>
                    </BlockStack>
                </Card>
            </InlineGrid>

            <Card padding="0">
                <Box padding="400">
                    <Text as="h2" variant="headingMd">
                        Threads per agent
                    </Text>
                </Box>
                <IndexTable
                    resourceName={{ singular: 'agent', plural: 'agents' }}
                    itemCount={sla.agents.length}
                    selectable={false}
                    headings={[
                        { title: 'Agent' },
                        { title: 'Assigned (total)' },
                        { title: 'Resolved in period' },
                        { title: 'Avg resolution time' },
                    ]}
                    emptyState={
                        <Box padding="400">
                            <Text as="p" tone="subdued" alignment="center">
                                No threads have been assigned to an agent yet.
                            </Text>
                        </Box>
                    }
                >
                    {agentRows}
                </IndexTable>
            </Card>
        </BlockStack>
    );
}

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

function ThreadsPanel({
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
    );
}

export default function SupportIndex({
    threads,
    filters,
    sla,
    sla_filters: slaFilters,
}: {
    threads: ThreadSummary[];
    filters: Filters;
    sla: SlaMetrics;
    sla_filters: SlaFilters;
}) {
    const [selectedTab, setSelectedTab] = useState(0);

    const tabs = [
        { id: 'threads', content: 'Threads' },
        { id: 'sla-metrics', content: 'SLA & metrics' },
    ];

    return (
        <>
            <Head title="Support Inbox" />
            <Page title="Support Inbox" fullWidth>
                <BlockStack gap="400">
                    <Card padding="0">
                        <Tabs
                            tabs={tabs}
                            selected={selectedTab}
                            onSelect={setSelectedTab}
                        />
                        <Box padding={selectedTab === 0 ? '0' : '400'}>
                            {selectedTab === 0 && (
                                <ThreadsPanel
                                    threads={threads}
                                    filters={filters}
                                />
                            )}
                            {selectedTab === 1 && (
                                <SlaMetricsPanel
                                    sla={sla}
                                    slaFilters={slaFilters}
                                />
                            )}
                        </Box>
                    </Card>
                </BlockStack>
            </Page>
        </>
    );
}

SupportIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
