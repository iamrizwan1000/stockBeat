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
    CashDollarIcon,
    ChartFunnelIcon,
    ChartVerticalIcon,
    ClockIcon,
    GlobeIcon,
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

export default function Dashboard({ kpis }: { kpis: Kpis }) {
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
                        icon={ChartFunnelIcon}
                        title="Activation funnel"
                        caption={
                            '"Paywall seen" isn\'t tracked yet (no paywall-impression analytics), so the funnel skips straight from rule creation to paid.'
                        }
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
