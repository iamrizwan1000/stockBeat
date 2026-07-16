import { Head, router } from '@inertiajs/react';
import {
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

type SegmentFilters = {
    plan?: string;
    platform?: string;
    inactive_days_gte?: number;
    trial_ending_within_days?: number;
    marketing_opt_in?: boolean;
};

type Segment = {
    id: number;
    name: string;
    filters: SegmentFilters | null;
    broadcasts_count: number;
    created_at: string | null;
};

const PLAN_OPTIONS = [
    { label: 'Any plan', value: '' },
    { label: 'Free', value: 'free' },
    { label: 'Trial', value: 'trial' },
    { label: 'Active', value: 'active' },
    { label: 'Grace period', value: 'grace' },
    { label: 'Expired', value: 'expired' },
];

const PLATFORM_OPTIONS = [
    { label: 'Any platform', value: '' },
    { label: 'Shopify', value: 'shopify' },
    { label: 'WooCommerce', value: 'woo' },
    { label: 'eBay', value: 'ebay' },
    { label: 'Etsy', value: 'etsy' },
    { label: 'Amazon', value: 'amazon' },
];

const MARKETING_OPT_IN_OPTIONS = [
    { label: 'Anyone', value: '' },
    { label: 'Opted in only', value: 'true' },
    { label: 'Opted out only', value: 'false' },
];

function readCookie(name: string): string {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : '';
}

function describeFilters(filters: SegmentFilters | null): string {
    if (!filters) {
        return 'Everyone';
    }

    const parts: string[] = [];

    if (filters.plan) {
        parts.push(`plan=${filters.plan}`);
    }

    if (filters.platform) {
        parts.push(`platform=${filters.platform}`);
    }

    if (filters.inactive_days_gte) {
        parts.push(`inactive ${filters.inactive_days_gte}+ days`);
    }

    if (filters.trial_ending_within_days) {
        parts.push(`trial ending within ${filters.trial_ending_within_days}d`);
    }

    if (
        filters.marketing_opt_in !== undefined &&
        filters.marketing_opt_in !== null
    ) {
        parts.push(filters.marketing_opt_in ? 'opted in' : 'opted out');
    }

    return parts.length > 0 ? parts.join(', ') : 'Everyone';
}

export default function SegmentsIndex({ segments }: { segments: Segment[] }) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [name, setName] = useState('');
    const [plan, setPlan] = useState('');
    const [platform, setPlatform] = useState('');
    const [inactiveDays, setInactiveDays] = useState('');
    const [trialEndingDays, setTrialEndingDays] = useState('');
    const [marketingOptIn, setMarketingOptIn] = useState('');
    const [previewCount, setPreviewCount] = useState<number | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);

    const buildFilters = (): SegmentFilters | null => {
        const filters: SegmentFilters = {};

        if (plan) {
            filters.plan = plan;
        }

        if (platform) {
            filters.platform = platform;
        }

        if (inactiveDays) {
            filters.inactive_days_gte = Number(inactiveDays);
        }

        if (trialEndingDays) {
            filters.trial_ending_within_days = Number(trialEndingDays);
        }

        if (marketingOptIn) {
            filters.marketing_opt_in = marketingOptIn === 'true';
        }

        return Object.keys(filters).length > 0 ? filters : null;
    };

    const resetForm = () => {
        setEditingId(null);
        setName('');
        setPlan('');
        setPlatform('');
        setInactiveDays('');
        setTrialEndingDays('');
        setMarketingOptIn('');
        setPreviewCount(null);
    };

    const edit = (segment: Segment) => {
        setEditingId(segment.id);
        setName(segment.name);
        setPlan(segment.filters?.plan ?? '');
        setPlatform(segment.filters?.platform ?? '');
        setInactiveDays(
            segment.filters?.inactive_days_gte
                ? String(segment.filters.inactive_days_gte)
                : '',
        );
        setTrialEndingDays(
            segment.filters?.trial_ending_within_days
                ? String(segment.filters.trial_ending_within_days)
                : '',
        );
        setMarketingOptIn(
            segment.filters?.marketing_opt_in === undefined ||
                segment.filters?.marketing_opt_in === null
                ? ''
                : String(segment.filters.marketing_opt_in),
        );
        setPreviewCount(null);
    };

    const preview = () => {
        setPreviewLoading(true);
        fetch('/admin/segments/preview-count', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': readCookie('XSRF-TOKEN'),
            },
            body: JSON.stringify({ filters: buildFilters() }),
        })
            .then((res) => res.json())
            .then((data: { count: number }) => setPreviewCount(data.count))
            .finally(() => setPreviewLoading(false));
    };

    const save = () => {
        const payload = { name, filters: buildFilters() };

        if (editingId) {
            router.put(`/admin/segments/${editingId}`, payload, {
                onSuccess: resetForm,
            });
        } else {
            router.post('/admin/segments', payload, { onSuccess: resetForm });
        }
    };

    const destroy = (segment: Segment) => {
        if (confirm(`Delete segment "${segment.name}"?`)) {
            router.delete(`/admin/segments/${segment.id}`);
        }
    };

    const [queryValue, setQueryValue] = useState('');
    const { mode, setMode } = useSetIndexFiltersMode(IndexFiltersMode.Default);

    const filteredSegments = useMemo(() => {
        if (!queryValue) {
            return segments;
        }

        const q = queryValue.toLowerCase();

        return segments.filter((segment) =>
            segment.name.toLowerCase().includes(q),
        );
    }, [segments, queryValue]);

    const rowMarkup = filteredSegments.map((segment, index) => (
        <IndexTable.Row
            id={String(segment.id)}
            key={segment.id}
            position={index}
        >
            <IndexTable.Cell>
                <Text as="span" fontWeight="semibold">
                    {segment.name}
                </Text>
            </IndexTable.Cell>
            <IndexTable.Cell>
                {describeFilters(segment.filters)}
            </IndexTable.Cell>
            <IndexTable.Cell>{segment.broadcasts_count}</IndexTable.Cell>
            <IndexTable.Cell>
                <InlineStack gap="200">
                    <Button onClick={() => edit(segment)}>Edit</Button>
                    <Button tone="critical" onClick={() => destroy(segment)}>
                        Delete
                    </Button>
                </InlineStack>
            </IndexTable.Cell>
        </IndexTable.Row>
    ));

    return (
        <>
            <Head title="Segments" />
            <Page
                title="Segments"
                subtitle="Reusable audiences for broadcasts"
                fullWidth
            >
                <BlockStack gap="400">
                    <Card>
                        <BlockStack gap="300">
                            <Text as="h2" variant="headingMd">
                                {editingId ? 'Edit segment' : 'New segment'}
                            </Text>
                            <TextField
                                label="Name"
                                value={name}
                                onChange={setName}
                                autoComplete="off"
                            />
                            <InlineStack gap="300" wrap>
                                <Select
                                    label="Plan"
                                    options={PLAN_OPTIONS}
                                    value={plan}
                                    onChange={setPlan}
                                />
                                <Select
                                    label="Platform"
                                    options={PLATFORM_OPTIONS}
                                    value={platform}
                                    onChange={setPlatform}
                                />
                                <TextField
                                    label="Inactive for at least (days)"
                                    type="number"
                                    value={inactiveDays}
                                    onChange={setInactiveDays}
                                    autoComplete="off"
                                />
                                <TextField
                                    label="Trial ending within (days)"
                                    type="number"
                                    value={trialEndingDays}
                                    onChange={setTrialEndingDays}
                                    autoComplete="off"
                                />
                                <Select
                                    label="Marketing consent"
                                    options={MARKETING_OPT_IN_OPTIONS}
                                    value={marketingOptIn}
                                    onChange={setMarketingOptIn}
                                />
                            </InlineStack>
                            <InlineStack gap="300" blockAlign="center">
                                <Button
                                    onClick={preview}
                                    loading={previewLoading}
                                >
                                    Preview audience size
                                </Button>
                                {previewCount !== null && (
                                    <Text as="span" tone="subdued">
                                        {previewCount} matching user
                                        {previewCount === 1 ? '' : 's'}
                                    </Text>
                                )}
                            </InlineStack>
                            <InlineStack gap="200">
                                <Button
                                    variant="primary"
                                    onClick={save}
                                    disabled={!name}
                                >
                                    {editingId
                                        ? 'Save changes'
                                        : 'Create segment'}
                                </Button>
                                {editingId && (
                                    <Button onClick={resetForm}>Cancel</Button>
                                )}
                            </InlineStack>
                        </BlockStack>
                    </Card>

                    <Card padding="0">
                        <IndexFilters
                            queryValue={queryValue}
                            queryPlaceholder="Search by name"
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
                                singular: 'segment',
                                plural: 'segments',
                            }}
                            itemCount={filteredSegments.length}
                            selectable={false}
                            headings={[
                                { title: 'Name' },
                                { title: 'Filters' },
                                { title: 'Used in broadcasts' },
                                { title: '' },
                            ]}
                            emptyState={
                                <Box padding="400">
                                    <Text
                                        as="p"
                                        tone="subdued"
                                        alignment="center"
                                    >
                                        No segments match this search.
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

SegmentsIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
