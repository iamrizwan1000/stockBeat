import { Head } from '@inertiajs/react';
import {
    BlockStack,
    Box,
    Card,
    Icon,
    InlineGrid,
    InlineStack,
    Page,
    ProgressBar,
    Text,
} from '@shopify/polaris';
import {
    AppsIcon,
    CashDollarIcon,
    ChartFunnelIcon,
    ChartVerticalIcon,
    ClockIcon,
    GlobeIcon,
    LockIcon,
    MegaphoneIcon,
    MobileIcon,
    PersonAddIcon,
} from '@shopify/polaris-icons';
import type { ReactNode } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type Kpis = {
    signups: { today: number; last_7_days: number; last_30_days: number };
    engagement: { dau: number; mau: number };
    trials: {
        active: number;
        expiring_this_week: number;
        conversion_pct: number;
    };
    subscriptions: {
        paying_monthly: number;
        paying_yearly: number;
        mrr: number;
        arr: number;
        arpu: number;
        churn_pct: number;
        cancellations_this_month: number;
    };
    platforms: Array<{ platform: string; count: number }>;
    notifications: { push: number; email: number; sms: number };
    sms: { sent_count: number; topup_credits_purchased: number };
    funnel: {
        signups: number;
        store_connected: number;
        push_enabled: number;
        rule_created: number;
        paid: number;
    };
};

type FeatureAdoption = {
    saved_filters: { count: number; teams: number };
    payouts: { count: number; teams: number };
    reviews: { total: number; replied: number; reply_rate_pct: number };
    rules: {
        positive_review: number;
        stale_inventory: number;
        monthly_digest: number;
        priority_critical: number;
        priority_high: number;
    };
    quick_actions: {
        fulfilled: number;
        refunded: number;
        cancelled: number;
        tags_updated: number;
        snoozed: number;
        note_added: number;
    };
    usage_events: Record<string, { count: number; teams: number }>;
};

type PaywallHits = {
    total: number;
    last_30_days: number;
    by_limit_key: Array<{ limit_key: string; count: number; teams: number }>;
    top_teams: Array<{
        team_id: number;
        team_name: string;
        plan_key: string | null;
        hits: number;
        last_hit_at: string;
    }>;
};

type StatTone = 'success' | 'critical' | 'caution' | undefined;

function Stat({
    label,
    value,
    tone,
}: {
    label: string;
    value: string | number;
    tone?: StatTone;
}) {
    return (
        <BlockStack gap="100">
            <Text as="p" tone="subdued" variant="bodySm">
                {label}
            </Text>
            <Text as="p" variant="headingXl" tone={tone}>
                {value}
            </Text>
        </BlockStack>
    );
}

function SectionCard({
    icon,
    title,
    caption,
    children,
}: {
    icon: typeof PersonAddIcon;
    title: string;
    caption?: string;
    children: ReactNode;
}) {
    return (
        <Card>
            <BlockStack gap="400">
                <InlineStack gap="200" blockAlign="center">
                    <Box
                        background="bg-surface-secondary"
                        borderRadius="200"
                        padding="150"
                    >
                        <Icon source={icon} tone="base" />
                    </Box>
                    <Text as="h2" variant="headingMd">
                        {title}
                    </Text>
                </InlineStack>
                {children}
                {caption && (
                    <Text as="p" tone="subdued" variant="bodySm">
                        {caption}
                    </Text>
                )}
            </BlockStack>
        </Card>
    );
}

function churnTone(churnPct: number): StatTone {
    if (churnPct >= 10) {
        return 'critical';
    }

    if (churnPct > 0) {
        return 'caution';
    }

    return 'success';
}

function conversionTone(pct: number): StatTone {
    return pct >= 20 ? 'success' : undefined;
}

const PLATFORM_LABELS: Record<string, string> = {
    shopify: 'Shopify',
    woo: 'WooCommerce',
    ebay: 'eBay',
    etsy: 'Etsy',
    amazon: 'Amazon',
};

const FUNNEL_STEPS = [
    { key: 'signups', label: 'Signed up' },
    { key: 'store_connected', label: 'Connected a store' },
    { key: 'push_enabled', label: 'Enabled push' },
    { key: 'rule_created', label: 'Created a rule' },
    { key: 'paid', label: 'Paid' },
] as const;

const USAGE_EVENT_LABELS: Record<string, string> = {
    invoice_generated: 'Invoices generated',
    packing_slip_generated: 'Packing slips (single)',
    bulk_packing_slips_generated: 'Packing slips (bulk)',
    bulk_cancel_used: 'Bulk cancel',
    bulk_tag_used: 'Bulk tag',
};

const LIMIT_KEY_LABELS: Record<string, string> = {
    bulk_actions_enabled: 'Bulk order actions',
    analytics_level: 'Analytics history',
    inbox_enabled: 'Unified inbox / reviews',
    max_stores: 'Store limit',
    max_rules: 'Rule limit',
    advanced_triggers_enabled: 'Advanced rule triggers',
    ai_proactive_insights_enabled: 'Proactive AI insights',
    ai_rule_builder_enabled: 'AI rule builder',
    team_seats: 'Team seat limit',
    ai_enabled: 'AI Data Copilot',
    ai_questions_monthly: 'AI question quota',
};

const PLAN_LABELS: Record<string, string> = {
    free: 'Free',
    starter: 'Starter',
    pro: 'Pro',
    premium: 'Premium',
};

const QUICK_ACTION_LABELS: Record<
    keyof FeatureAdoption['quick_actions'],
    string
> = {
    fulfilled: 'Fulfilled',
    refunded: 'Refunded',
    cancelled: 'Cancelled',
    tags_updated: 'Tags updated',
    snoozed: 'Snoozed',
    note_added: 'Note added',
};

export default function Dashboard({
    kpis,
    featureAdoption,
    paywallHits,
}: {
    kpis: Kpis;
    featureAdoption: FeatureAdoption;
    paywallHits: PaywallHits;
}) {
    const totalPlatformConnections = kpis.platforms.reduce(
        (sum, row) => sum + row.count,
        0,
    );

    return (
        <>
            <Head title="Dashboard" />
            <Page
                title="Dashboard"
                subtitle="Live snapshot — every figure below is computed from real data"
                fullWidth
            >
                <BlockStack gap="400">
                    <InlineGrid columns={{ xs: 1, md: 2 }} gap="400">
                        <SectionCard icon={PersonAddIcon} title="Signups">
                            <InlineGrid columns={3} gap="400">
                                <Stat
                                    label="Today"
                                    value={kpis.signups.today}
                                />
                                <Stat
                                    label="Last 7 days"
                                    value={kpis.signups.last_7_days}
                                />
                                <Stat
                                    label="Last 30 days"
                                    value={kpis.signups.last_30_days}
                                />
                            </InlineGrid>
                        </SectionCard>

                        <SectionCard
                            icon={ChartVerticalIcon}
                            title="Engagement"
                        >
                            <InlineGrid columns={2} gap="400">
                                <Stat
                                    label="Daily active users"
                                    value={kpis.engagement.dau}
                                />
                                <Stat
                                    label="Monthly active users"
                                    value={kpis.engagement.mau}
                                />
                            </InlineGrid>
                        </SectionCard>
                    </InlineGrid>

                    <SectionCard icon={ClockIcon} title="Trials">
                        <InlineGrid columns={3} gap="400">
                            <Stat
                                label="Active trials"
                                value={kpis.trials.active}
                            />
                            <Stat
                                label="Expiring this week"
                                value={kpis.trials.expiring_this_week}
                                tone={
                                    kpis.trials.expiring_this_week > 0
                                        ? 'caution'
                                        : undefined
                                }
                            />
                            <Stat
                                label="Trial → paid conversion"
                                value={`${kpis.trials.conversion_pct}%`}
                                tone={conversionTone(
                                    kpis.trials.conversion_pct,
                                )}
                            />
                        </InlineGrid>
                    </SectionCard>

                    <SectionCard
                        icon={CashDollarIcon}
                        title="Subscriptions & revenue"
                        caption="MRR/ARR use the current Starter/Pro/Premium list prices — update this estimate by hand if store pricing changes until a real prices table exists."
                    >
                        <InlineGrid columns={{ xs: 2, sm: 4 }} gap="400">
                            <Stat
                                label="Paying (monthly)"
                                value={kpis.subscriptions.paying_monthly}
                            />
                            <Stat
                                label="Paying (yearly)"
                                value={kpis.subscriptions.paying_yearly}
                            />
                            <Stat
                                label="MRR"
                                value={`$${kpis.subscriptions.mrr}`}
                            />
                            <Stat
                                label="ARR"
                                value={`$${kpis.subscriptions.arr}`}
                            />
                            <Stat
                                label="ARPU"
                                value={`$${kpis.subscriptions.arpu}`}
                            />
                            <Stat
                                label="Churn"
                                value={`${kpis.subscriptions.churn_pct}%`}
                                tone={churnTone(kpis.subscriptions.churn_pct)}
                            />
                            <Stat
                                label="Cancellations this month"
                                value={
                                    kpis.subscriptions.cancellations_this_month
                                }
                                tone={
                                    kpis.subscriptions
                                        .cancellations_this_month > 0
                                        ? 'caution'
                                        : undefined
                                }
                            />
                        </InlineGrid>
                    </SectionCard>

                    <InlineGrid columns={{ xs: 1, md: 2 }} gap="400">
                        <SectionCard
                            icon={MegaphoneIcon}
                            title="Notification volume (24h)"
                        >
                            <InlineGrid columns={3} gap="400">
                                <Stat
                                    label="Push"
                                    value={kpis.notifications.push}
                                />
                                <Stat
                                    label="Email"
                                    value={kpis.notifications.email}
                                />
                                <Stat
                                    label="SMS"
                                    value={kpis.notifications.sms}
                                />
                            </InlineGrid>
                        </SectionCard>

                        <SectionCard
                            icon={MobileIcon}
                            title="SMS cost vs. revenue"
                            caption="SMS sending isn't live yet (pending Twilio account setup), so these will read zero until then."
                        >
                            <InlineGrid columns={2} gap="400">
                                <Stat
                                    label="Messages sent"
                                    value={kpis.sms.sent_count}
                                />
                                <Stat
                                    label="Top-up credits purchased"
                                    value={kpis.sms.topup_credits_purchased}
                                />
                            </InlineGrid>
                        </SectionCard>
                    </InlineGrid>

                    <SectionCard
                        icon={GlobeIcon}
                        title="Top platforms connected"
                    >
                        {kpis.platforms.length > 0 ? (
                            <BlockStack gap="300">
                                {kpis.platforms.map((row) => {
                                    const pct =
                                        totalPlatformConnections > 0
                                            ? Math.round(
                                                  (row.count /
                                                      totalPlatformConnections) *
                                                      100,
                                              )
                                            : 0;

                                    return (
                                        <BlockStack
                                            gap="100"
                                            key={row.platform}
                                        >
                                            <InlineStack align="space-between">
                                                <Text
                                                    as="span"
                                                    fontWeight="medium"
                                                >
                                                    {PLATFORM_LABELS[
                                                        row.platform
                                                    ] ?? row.platform}
                                                </Text>
                                                <Text as="span" tone="subdued">
                                                    {row.count} connection
                                                    {row.count === 1 ? '' : 's'}
                                                </Text>
                                            </InlineStack>
                                            <ProgressBar
                                                progress={pct}
                                                size="small"
                                                tone="primary"
                                            />
                                        </BlockStack>
                                    );
                                })}
                            </BlockStack>
                        ) : (
                            <Text as="p" tone="subdued">
                                No stores connected yet.
                            </Text>
                        )}
                    </SectionCard>

                    <SectionCard
                        icon={AppsIcon}
                        title="Feature adoption"
                        caption="Real usage counts for every module shipped since §4.13 — invoice/packing-slip generation and bulk actions come from feature_usage_events; quick actions come from the order timeline (order_events)."
                    >
                        <BlockStack gap="400">
                            <InlineGrid columns={{ xs: 2, sm: 4 }} gap="400">
                                <Stat
                                    label="Saved filters"
                                    value={`${featureAdoption.saved_filters.count} (${featureAdoption.saved_filters.teams} teams)`}
                                />
                                <Stat
                                    label="Payouts recorded"
                                    value={`${featureAdoption.payouts.count} (${featureAdoption.payouts.teams} teams)`}
                                />
                                <Stat
                                    label="Review reply rate"
                                    value={`${featureAdoption.reviews.reply_rate_pct}%`}
                                />
                                <Stat
                                    label="Monthly digest rules"
                                    value={featureAdoption.rules.monthly_digest}
                                />
                            </InlineGrid>

                            <BlockStack gap="200">
                                <Text as="h3" variant="headingSm">
                                    Usage events
                                </Text>
                                <InlineGrid
                                    columns={{ xs: 2, sm: 5 }}
                                    gap="400"
                                >
                                    {Object.entries(
                                        featureAdoption.usage_events,
                                    ).map(([key, row]) => (
                                        <Stat
                                            key={key}
                                            label={
                                                USAGE_EVENT_LABELS[key] ?? key
                                            }
                                            value={`${row.count} (${row.teams} teams)`}
                                        />
                                    ))}
                                </InlineGrid>
                            </BlockStack>

                            <BlockStack gap="200">
                                <Text as="h3" variant="headingSm">
                                    Quick actions taken (order timeline)
                                </Text>
                                <InlineGrid
                                    columns={{ xs: 2, sm: 6 }}
                                    gap="400"
                                >
                                    {(
                                        Object.keys(
                                            QUICK_ACTION_LABELS,
                                        ) as Array<
                                            keyof FeatureAdoption['quick_actions']
                                        >
                                    ).map((key) => (
                                        <Stat
                                            key={key}
                                            label={QUICK_ACTION_LABELS[key]}
                                            value={
                                                featureAdoption.quick_actions[
                                                    key
                                                ]
                                            }
                                        />
                                    ))}
                                </InlineGrid>
                            </BlockStack>
                        </BlockStack>
                    </SectionCard>

                    <SectionCard
                        icon={LockIcon}
                        title="Paywall hits"
                        caption="Every row is a real server-side rejection (a team actually tried to use a gated feature and was blocked) — not a client-side upsell-modal impression, which still isn't tracked. Top teams are the clearest upgrade-outreach list this dashboard can produce: a repeat hit means the feature is wanted, not just glanced at."
                    >
                        <BlockStack gap="400">
                            <InlineGrid columns={2} gap="400">
                                <Stat
                                    label="Total hits (all time)"
                                    value={paywallHits.total}
                                />
                                <Stat
                                    label="Last 30 days"
                                    value={paywallHits.last_30_days}
                                    tone={
                                        paywallHits.last_30_days > 0
                                            ? 'caution'
                                            : undefined
                                    }
                                />
                            </InlineGrid>

                            {paywallHits.by_limit_key.length > 0 && (
                                <BlockStack gap="200">
                                    <Text as="h3" variant="headingSm">
                                        By gate
                                    </Text>
                                    <BlockStack gap="300">
                                        {paywallHits.by_limit_key.map((row) => {
                                            const pct =
                                                paywallHits.total > 0
                                                    ? Math.round(
                                                          (row.count /
                                                              paywallHits.total) *
                                                              100,
                                                      )
                                                    : 0;

                                            return (
                                                <BlockStack
                                                    gap="100"
                                                    key={row.limit_key}
                                                >
                                                    <InlineStack align="space-between">
                                                        <Text
                                                            as="span"
                                                            fontWeight="medium"
                                                        >
                                                            {LIMIT_KEY_LABELS[
                                                                row.limit_key
                                                            ] ?? row.limit_key}
                                                        </Text>
                                                        <Text
                                                            as="span"
                                                            tone="subdued"
                                                        >
                                                            {row.count} hit
                                                            {row.count === 1
                                                                ? ''
                                                                : 's'}{' '}
                                                            · {row.teams} team
                                                            {row.teams === 1
                                                                ? ''
                                                                : 's'}
                                                        </Text>
                                                    </InlineStack>
                                                    <ProgressBar
                                                        progress={pct}
                                                        size="small"
                                                        tone="primary"
                                                    />
                                                </BlockStack>
                                            );
                                        })}
                                    </BlockStack>
                                </BlockStack>
                            )}

                            {paywallHits.top_teams.length > 0 && (
                                <BlockStack gap="200">
                                    <Text as="h3" variant="headingSm">
                                        Top teams to reach out to (last 30 days)
                                    </Text>
                                    <BlockStack gap="200">
                                        {paywallHits.top_teams.map((team) => (
                                            <InlineStack
                                                align="space-between"
                                                key={team.team_id}
                                            >
                                                <Text as="span">
                                                    {team.team_name}
                                                    {team.plan_key && (
                                                        <Text
                                                            as="span"
                                                            tone="subdued"
                                                        >
                                                            {' '}
                                                            (
                                                            {PLAN_LABELS[
                                                                team.plan_key
                                                            ] ?? team.plan_key}
                                                            )
                                                        </Text>
                                                    )}
                                                </Text>
                                                <Text as="span" tone="subdued">
                                                    {team.hits} hit
                                                    {team.hits === 1 ? '' : 's'}
                                                </Text>
                                            </InlineStack>
                                        ))}
                                    </BlockStack>
                                </BlockStack>
                            )}
                        </BlockStack>
                    </SectionCard>

                    <SectionCard
                        icon={ChartFunnelIcon}
                        title="Activation funnel"
                        caption="Funnel steps skip straight from rule creation to paid — there's no reliable single step to insert for 'paywall seen' since teams hit different gates at different points. See the Paywall hits section above for which gates are actually being hit and by whom."
                    >
                        <BlockStack gap="300">
                            {FUNNEL_STEPS.map((step) => {
                                const value = kpis.funnel[step.key];
                                const pct =
                                    kpis.funnel.signups > 0
                                        ? Math.round(
                                              (value / kpis.funnel.signups) *
                                                  100,
                                          )
                                        : 0;

                                return (
                                    <BlockStack gap="100" key={step.key}>
                                        <InlineStack align="space-between">
                                            <Text as="span" fontWeight="medium">
                                                {step.label}
                                            </Text>
                                            <Text as="span" tone="subdued">
                                                {value} · {pct}%
                                            </Text>
                                        </InlineStack>
                                        <ProgressBar
                                            progress={pct}
                                            size="small"
                                            tone={
                                                step.key === 'paid'
                                                    ? 'success'
                                                    : 'highlight'
                                            }
                                        />
                                    </BlockStack>
                                );
                            })}
                        </BlockStack>
                    </SectionCard>
                </BlockStack>
            </Page>
        </>
    );
}

Dashboard.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
