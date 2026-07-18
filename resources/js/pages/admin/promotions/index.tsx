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
    InlineStack,
    Page,
    Select,
    Text,
    TextField,
    useSetIndexFiltersMode,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';

import PolarisDateField from '@/components/PolarisDateField';
import AdminLayout from '@/layouts/admin-layout';

type CampaignConfig = {
    code_prefix?: string;
    discount_pct?: number;
    duration_months?: number;
    intro_price?: number;
    intro_duration?: string;
    comp_type?: 'pro_days' | 'sms_credits';
    amount?: number;
    segment_id?: number | null;
};

type CampaignStats = {
    applications?: {
        segment_id: number | null;
        recipients_total: number;
        applied_at: string;
    }[];
    recipients_total_all_time?: number;
};

type Campaign = {
    id: number;
    name: string;
    type: 'offer_code' | 'intro_offer' | 'server_comp';
    store_ref: string | null;
    config: CampaignConfig | null;
    starts_at: string | null;
    ends_at: string | null;
    is_active: boolean;
    stats: CampaignStats | null;
    created_by_name: string | null;
    created_at: string | null;
};

type Segment = { id: number; name: string };

const TYPE_OPTIONS = [
    { label: 'Offer code (Apple/Google)', value: 'offer_code' },
    { label: 'Introductory offer', value: 'intro_offer' },
    { label: 'Server-side comp', value: 'server_comp' },
];

const STORE_OPTIONS = [
    { label: 'N/A', value: '' },
    { label: 'Apple', value: 'apple' },
    { label: 'Google', value: 'google' },
];

const COMP_TYPE_OPTIONS = [
    { label: 'Complimentary Pro days', value: 'pro_days' },
    { label: 'Bonus SMS credits', value: 'sms_credits' },
];

function describeConfig(campaign: Campaign): string {
    const c = campaign.config ?? {};

    if (campaign.type === 'offer_code') {
        const bits = [
            c.code_prefix,
            c.discount_pct ? `${c.discount_pct}% off` : null,
            c.duration_months ? `${c.duration_months}mo` : null,
        ].filter(Boolean);

        return bits.length > 0 ? bits.join(', ') : 'No details set';
    }

    if (campaign.type === 'intro_offer') {
        const bits = [
            c.intro_price ? `$${c.intro_price}` : null,
            c.intro_duration,
        ].filter(Boolean);

        return bits.length > 0 ? bits.join(' for ') : 'No details set';
    }

    if (c.comp_type && c.amount) {
        const label =
            c.comp_type === 'pro_days'
                ? `${c.amount} Pro days`
                : `${c.amount} SMS credits`;

        return label;
    }

    return 'No comp configured';
}

export default function PromotionsIndex({
    campaigns,
    segments,
}: {
    campaigns: Campaign[];
    segments: Segment[];
}) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [name, setName] = useState('');
    const [type, setType] = useState<Campaign['type']>('offer_code');
    const [storeRef, setStoreRef] = useState('');
    const [codePrefix, setCodePrefix] = useState('');
    const [discountPct, setDiscountPct] = useState('');
    const [durationMonths, setDurationMonths] = useState('');
    const [introPrice, setIntroPrice] = useState('');
    const [introDuration, setIntroDuration] = useState('');
    const [compType, setCompType] = useState<'pro_days' | 'sms_credits'>(
        'pro_days',
    );
    const [amount, setAmount] = useState('');
    const [startsAt, setStartsAt] = useState('');
    const [endsAt, setEndsAt] = useState('');
    const [applySegmentByCampaign, setApplySegmentByCampaign] = useState<
        Record<number, string>
    >({});

    const resetForm = () => {
        setEditingId(null);
        setName('');
        setType('offer_code');
        setStoreRef('');
        setCodePrefix('');
        setDiscountPct('');
        setDurationMonths('');
        setIntroPrice('');
        setIntroDuration('');
        setCompType('pro_days');
        setAmount('');
        setStartsAt('');
        setEndsAt('');
    };

    const edit = (campaign: Campaign) => {
        setEditingId(campaign.id);
        setName(campaign.name);
        setType(campaign.type);
        setStoreRef(campaign.store_ref ?? '');
        setCodePrefix(campaign.config?.code_prefix ?? '');
        setDiscountPct(
            campaign.config?.discount_pct
                ? String(campaign.config.discount_pct)
                : '',
        );
        setDurationMonths(
            campaign.config?.duration_months
                ? String(campaign.config.duration_months)
                : '',
        );
        setIntroPrice(
            campaign.config?.intro_price
                ? String(campaign.config.intro_price)
                : '',
        );
        setIntroDuration(campaign.config?.intro_duration ?? '');
        setCompType(campaign.config?.comp_type ?? 'pro_days');
        setAmount(
            campaign.config?.amount ? String(campaign.config.amount) : '',
        );
        setStartsAt(campaign.starts_at ? campaign.starts_at.slice(0, 10) : '');
        setEndsAt(campaign.ends_at ? campaign.ends_at.slice(0, 10) : '');
    };

    const buildConfig = (): CampaignConfig => {
        if (type === 'offer_code') {
            return {
                code_prefix: codePrefix || undefined,
                discount_pct: discountPct ? Number(discountPct) : undefined,
                duration_months: durationMonths
                    ? Number(durationMonths)
                    : undefined,
            };
        }

        if (type === 'intro_offer') {
            return {
                intro_price: introPrice ? Number(introPrice) : undefined,
                intro_duration: introDuration || undefined,
            };
        }

        return {
            comp_type: compType,
            amount: amount ? Number(amount) : undefined,
        };
    };

    const save = () => {
        const payload = {
            name,
            type,
            store_ref: storeRef || null,
            config: buildConfig(),
            starts_at: startsAt || null,
            ends_at: endsAt || null,
        };

        if (editingId) {
            router.put(`/admin/promotions/${editingId}`, payload, {
                onSuccess: resetForm,
            });
        } else {
            router.post('/admin/promotions', payload, { onSuccess: resetForm });
        }
    };

    const destroy = (campaign: Campaign) => {
        if (confirm(`Delete campaign "${campaign.name}"?`)) {
            router.delete(`/admin/promotions/${campaign.id}`);
        }
    };

    const applyComp = (campaign: Campaign) => {
        const segmentId = applySegmentByCampaign[campaign.id];
        const label = segmentId
            ? segments.find((s) => String(s.id) === segmentId)?.name
            : 'everyone';

        if (!confirm(`Apply this comp to ${label}?`)) {
            return;
        }

        router.post(`/admin/promotions/${campaign.id}/apply`, {
            segment_id: segmentId || null,
        });
    };

    const segmentOptions = [
        { label: 'Everyone (superadmin only)', value: '' },
        ...segments.map((s) => ({ label: s.name, value: String(s.id) })),
    ];

    const [queryValue, setQueryValue] = useState('');
    const [typeFilter, setTypeFilter] = useState('');
    const { mode, setMode } = useSetIndexFiltersMode(IndexFiltersMode.Default);

    const filteredCampaigns = useMemo(() => {
        return campaigns.filter((campaign) => {
            if (typeFilter && campaign.type !== typeFilter) {
                return false;
            }

            if (
                queryValue &&
                !campaign.name.toLowerCase().includes(queryValue.toLowerCase())
            ) {
                return false;
            }

            return true;
        });
    }, [campaigns, queryValue, typeFilter]);

    const appliedFilters = typeFilter
        ? [
              {
                  key: 'type',
                  label: `Type: ${TYPE_OPTIONS.find((o) => o.value === typeFilter)?.label ?? typeFilter}`,
                  onRemove: () => setTypeFilter(''),
              },
          ]
        : [];

    const rowMarkup = filteredCampaigns.map((campaign, index) => (
        <IndexTable.Row
            id={String(campaign.id)}
            key={campaign.id}
            position={index}
        >
            <IndexTable.Cell>
                <Link href={`/admin/promotions/${campaign.id}`}>
                    <Text as="span" fontWeight="semibold">
                        {campaign.name}
                    </Text>
                </Link>
            </IndexTable.Cell>
            <IndexTable.Cell>
                {TYPE_OPTIONS.find((o) => o.value === campaign.type)?.label ??
                    campaign.type}
            </IndexTable.Cell>
            <IndexTable.Cell>{describeConfig(campaign)}</IndexTable.Cell>
            <IndexTable.Cell>
                <Badge tone={campaign.is_active ? 'success' : undefined}>
                    {campaign.is_active ? 'Active' : 'Inactive'}
                </Badge>
            </IndexTable.Cell>
            <IndexTable.Cell>
                {campaign.type === 'server_comp'
                    ? String(campaign.stats?.recipients_total_all_time ?? 0)
                    : '—'}
            </IndexTable.Cell>
            <IndexTable.Cell>
                <BlockStack gap="200">
                    {campaign.type === 'server_comp' && (
                        <InlineStack gap="200" blockAlign="center">
                            <Select
                                label="Apply to"
                                labelHidden
                                options={segmentOptions}
                                value={
                                    applySegmentByCampaign[campaign.id] ?? ''
                                }
                                onChange={(value) =>
                                    setApplySegmentByCampaign((prev) => ({
                                        ...prev,
                                        [campaign.id]: value,
                                    }))
                                }
                            />
                            <Button onClick={() => applyComp(campaign)}>
                                Apply
                            </Button>
                        </InlineStack>
                    )}
                    <InlineStack gap="200">
                        <Button onClick={() => edit(campaign)}>Edit</Button>
                        <Button
                            tone="critical"
                            onClick={() => destroy(campaign)}
                        >
                            Delete
                        </Button>
                    </InlineStack>
                </BlockStack>
            </IndexTable.Cell>
        </IndexTable.Row>
    ));

    return (
        <>
            <Head title="Promotions & Discounts" />
            <Page
                title="Promotions & Discounts"
                subtitle="Offer codes, intro offers, and server-side comps"
                fullWidth
            >
                <BlockStack gap="400">
                    <Card>
                        <BlockStack gap="300">
                            <Text as="h2" variant="headingMd">
                                {editingId ? 'Edit campaign' : 'New campaign'}
                            </Text>
                            <InlineStack gap="300" wrap>
                                <TextField
                                    label="Name"
                                    value={name}
                                    onChange={setName}
                                    autoComplete="off"
                                />
                                <Select
                                    label="Type"
                                    options={TYPE_OPTIONS}
                                    value={type}
                                    onChange={(v) =>
                                        setType(v as Campaign['type'])
                                    }
                                />
                                {type !== 'server_comp' && (
                                    <Select
                                        label="Store"
                                        options={STORE_OPTIONS}
                                        value={storeRef}
                                        onChange={setStoreRef}
                                    />
                                )}
                            </InlineStack>

                            {type === 'offer_code' && (
                                <InlineStack gap="300" wrap>
                                    <TextField
                                        label="Code prefix"
                                        value={codePrefix}
                                        onChange={setCodePrefix}
                                        autoComplete="off"
                                    />
                                    <TextField
                                        label="Discount %"
                                        type="number"
                                        value={discountPct}
                                        onChange={setDiscountPct}
                                        autoComplete="off"
                                    />
                                    <TextField
                                        label="Duration (months)"
                                        type="number"
                                        value={durationMonths}
                                        onChange={setDurationMonths}
                                        autoComplete="off"
                                    />
                                </InlineStack>
                            )}

                            {type === 'intro_offer' && (
                                <InlineStack gap="300" wrap>
                                    <TextField
                                        label="Intro price"
                                        type="number"
                                        value={introPrice}
                                        onChange={setIntroPrice}
                                        autoComplete="off"
                                    />
                                    <TextField
                                        label="Intro duration"
                                        value={introDuration}
                                        onChange={setIntroDuration}
                                        autoComplete="off"
                                        placeholder="e.g. 1 month"
                                    />
                                </InlineStack>
                            )}

                            {type === 'server_comp' && (
                                <InlineStack gap="300" wrap>
                                    <Select
                                        label="Comp type"
                                        options={COMP_TYPE_OPTIONS}
                                        value={compType}
                                        onChange={(v) =>
                                            setCompType(
                                                v as 'pro_days' | 'sms_credits',
                                            )
                                        }
                                    />
                                    <TextField
                                        label="Amount"
                                        type="number"
                                        value={amount}
                                        onChange={setAmount}
                                        autoComplete="off"
                                    />
                                </InlineStack>
                            )}

                            <InlineStack gap="300" wrap>
                                <PolarisDateField
                                    label="Starts"
                                    value={startsAt}
                                    onChange={setStartsAt}
                                />
                                <PolarisDateField
                                    label="Ends"
                                    value={endsAt}
                                    onChange={setEndsAt}
                                />
                            </InlineStack>

                            <InlineStack gap="200">
                                <Button
                                    variant="primary"
                                    onClick={save}
                                    disabled={!name}
                                >
                                    {editingId
                                        ? 'Save changes'
                                        : 'Create campaign'}
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
                            filters={[
                                {
                                    key: 'type',
                                    label: 'Type',
                                    filter: (
                                        <Select
                                            label="Type"
                                            labelHidden
                                            options={[
                                                {
                                                    label: 'All types',
                                                    value: '',
                                                },
                                                ...TYPE_OPTIONS,
                                            ]}
                                            value={typeFilter}
                                            onChange={setTypeFilter}
                                        />
                                    ),
                                },
                            ]}
                            appliedFilters={appliedFilters}
                            onClearAll={() => {
                                setQueryValue('');
                                setTypeFilter('');
                            }}
                        />
                        <IndexTable
                            resourceName={{
                                singular: 'campaign',
                                plural: 'campaigns',
                            }}
                            itemCount={filteredCampaigns.length}
                            selectable={false}
                            headings={[
                                { title: 'Name' },
                                { title: 'Type' },
                                { title: 'Details' },
                                { title: 'Status' },
                                { title: 'Recipients (all-time)' },
                                { title: '' },
                            ]}
                            emptyState={
                                <Box padding="400">
                                    <Text
                                        as="p"
                                        tone="subdued"
                                        alignment="center"
                                    >
                                        No campaigns match these filters.
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

PromotionsIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
