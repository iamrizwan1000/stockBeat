import { Head } from '@inertiajs/react';
import {
    Badge,
    BlockStack,
    Banner,
    Card,
    InlineGrid,
    Page,
    Text,
} from '@shopify/polaris';
import type { ReactNode } from 'react';

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

type ComputedStats = {
    computable: boolean;
    reason: string | null;
    redemptions: number | null;
    targeted_segment_size: number | null;
    conversion: number | null;
    revenue_impact: number | null;
    revenue_impact_currency: string | null;
    revenue_events_included: number | null;
    revenue_events_excluded_no_price: number | null;
    revenue_events_excluded_no_fx_rate: number | null;
};

const TYPE_LABELS: Record<Campaign['type'], string> = {
    offer_code: 'Offer code (Apple/Google)',
    intro_offer: 'Introductory offer',
    server_comp: 'Server-side comp',
};

function formatPercent(value: number | null): string {
    if (value === null) {
        return '—';
    }

    return `${(value * 100).toFixed(1)}%`;
}

function formatMoney(value: number | null, currency: string | null): string {
    if (value === null || currency === null) {
        return '—';
    }

    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    }).format(value);
}

export default function PromotionsShow({
    campaign,
    computed_stats: stats,
}: {
    campaign: Campaign;
    computed_stats: ComputedStats;
}) {
    return (
        <>
            <Head title={campaign.name} />
            <Page
                title={campaign.name}
                subtitle={TYPE_LABELS[campaign.type]}
                backAction={{ url: '/admin/promotions' }}
            >
                <BlockStack gap="400">
                    <Card>
                        <BlockStack gap="200">
                            <Badge tone={campaign.is_active ? 'success' : undefined}>
                                {campaign.is_active ? 'Active' : 'Inactive'}
                            </Badge>
                            <Text as="p" tone="subdued">
                                Created by {campaign.created_by_name ?? 'unknown'}
                                {campaign.created_at &&
                                    ` on ${new Date(campaign.created_at).toLocaleDateString()}`}
                            </Text>
                            {(campaign.starts_at || campaign.ends_at) && (
                                <Text as="p" tone="subdued">
                                    {campaign.starts_at
                                        ? `Starts ${new Date(campaign.starts_at).toLocaleDateString()}`
                                        : 'No start date'}
                                    {' · '}
                                    {campaign.ends_at
                                        ? `Ends ${new Date(campaign.ends_at).toLocaleDateString()}`
                                        : 'No end date'}
                                </Text>
                            )}
                        </BlockStack>
                    </Card>

                    <Card>
                        <BlockStack gap="300">
                            <Text as="h2" variant="headingMd">
                                Performance
                            </Text>

                            {!stats.computable ? (
                                <Banner tone="info">
                                    <p>{stats.reason}</p>
                                </Banner>
                            ) : (
                                <BlockStack gap="300">
                                    <InlineGrid columns={3} gap="400">
                                        <BlockStack gap="100">
                                            <Text as="p" tone="subdued">
                                                Redemptions
                                            </Text>
                                            <Text as="p" variant="headingLg">
                                                {stats.redemptions}
                                            </Text>
                                        </BlockStack>
                                        <BlockStack gap="100">
                                            <Text as="p" tone="subdued">
                                                Conversion
                                            </Text>
                                            <Text as="p" variant="headingLg">
                                                {formatPercent(stats.conversion)}
                                            </Text>
                                            {stats.targeted_segment_size !== null && (
                                                <Text as="p" tone="subdued">
                                                    of {stats.targeted_segment_size}{' '}
                                                    targeted team(s)
                                                </Text>
                                            )}
                                        </BlockStack>
                                        <BlockStack gap="100">
                                            <Text as="p" tone="subdued">
                                                Revenue impact
                                            </Text>
                                            <Text as="p" variant="headingLg">
                                                {formatMoney(
                                                    stats.revenue_impact,
                                                    stats.revenue_impact_currency,
                                                )}
                                            </Text>
                                        </BlockStack>
                                    </InlineGrid>

                                    <Text as="p" tone="subdued">
                                        Revenue impact sums subscription
                                        events on redeeming teams occurring on
                                        or after each team&apos;s redemption
                                        date, converted to{' '}
                                        {stats.revenue_impact_currency}.{' '}
                                        {stats.revenue_events_included ?? 0}{' '}
                                        event(s) included
                                        {stats.revenue_events_excluded_no_price
                                            ? `, ${stats.revenue_events_excluded_no_price} excluded (no price on the event)`
                                            : ''}
                                        {stats.revenue_events_excluded_no_fx_rate
                                            ? `, ${stats.revenue_events_excluded_no_fx_rate} excluded (no FX rate available yet)`
                                            : ''}
                                        .
                                    </Text>
                                </BlockStack>
                            )}
                        </BlockStack>
                    </Card>

                    {campaign.stats?.applications &&
                        campaign.stats.applications.length > 0 && (
                            <Card>
                                <BlockStack gap="300">
                                    <Text as="h2" variant="headingMd">
                                        Application history
                                    </Text>
                                    {campaign.stats.applications.map(
                                        (application, index) => (
                                            <Text
                                                as="p"
                                                tone="subdued"
                                                key={index}
                                            >
                                                {new Date(
                                                    application.applied_at,
                                                ).toLocaleString()}{' '}
                                                — applied to{' '}
                                                {application.segment_id
                                                    ? `segment #${application.segment_id}`
                                                    : 'everyone'}{' '}
                                                ({application.recipients_total}{' '}
                                                recipient(s))
                                            </Text>
                                        ),
                                    )}
                                </BlockStack>
                            </Card>
                        )}
                </BlockStack>
            </Page>
        </>
    );
}

PromotionsShow.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
