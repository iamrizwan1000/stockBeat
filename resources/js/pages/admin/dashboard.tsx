import { Head } from '@inertiajs/react';
import {
    BlockStack,
    Card,
    DataTable,
    InlineGrid,
    Page,
    Text,
} from '@shopify/polaris';
import type { ReactNode } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type Kpis = {
    signups: { today: number; last_7_days: number; last_30_days: number };
    engagement: { dau: number; mau: number };
    trials: { active: number; expiring_this_week: number; conversion_pct: number };
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

function StatTile({ label, value }: { label: string; value: string | number }) {
    return (
        <Card>
            <BlockStack gap="100">
                <Text as="p" tone="subdued" variant="bodySm">
                    {label}
                </Text>
                <Text as="p" variant="headingLg">
                    {value}
                </Text>
            </BlockStack>
        </Card>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <BlockStack gap="200">
            <Text as="h2" variant="headingMd">
                {title}
            </Text>
            {children}
        </BlockStack>
    );
}

export default function Dashboard({ kpis }: { kpis: Kpis }) {
    const funnelRows: string[][] = [
        ['Signed up', String(kpis.funnel.signups), '100%'],
        [
            'Connected a store',
            String(kpis.funnel.store_connected),
            percentOf(kpis.funnel.store_connected, kpis.funnel.signups),
        ],
        [
            'Enabled push',
            String(kpis.funnel.push_enabled),
            percentOf(kpis.funnel.push_enabled, kpis.funnel.signups),
        ],
        [
            'Created a rule',
            String(kpis.funnel.rule_created),
            percentOf(kpis.funnel.rule_created, kpis.funnel.signups),
        ],
        ['Paid', String(kpis.funnel.paid), percentOf(kpis.funnel.paid, kpis.funnel.signups)],
    ];

    const platformRows: string[][] = kpis.platforms.map((row) => [
        row.platform,
        String(row.count),
    ]);

    return (
        <>
            <Head title="Dashboard" />
            <Page title="Dashboard" fullWidth>
                <BlockStack gap="500">
                    <Section title="Signups">
                        <InlineGrid columns={3} gap="300">
                            <StatTile label="Today" value={kpis.signups.today} />
                            <StatTile label="Last 7 days" value={kpis.signups.last_7_days} />
                            <StatTile label="Last 30 days" value={kpis.signups.last_30_days} />
                        </InlineGrid>
                    </Section>

                    <Section title="Engagement">
                        <InlineGrid columns={2} gap="300">
                            <StatTile label="DAU" value={kpis.engagement.dau} />
                            <StatTile label="MAU" value={kpis.engagement.mau} />
                        </InlineGrid>
                    </Section>

                    <Section title="Trials">
                        <InlineGrid columns={3} gap="300">
                            <StatTile label="Active trials" value={kpis.trials.active} />
                            <StatTile
                                label="Expiring this week"
                                value={kpis.trials.expiring_this_week}
                            />
                            <StatTile
                                label="Trial → paid conversion"
                                value={`${kpis.trials.conversion_pct}%`}
                            />
                        </InlineGrid>
                    </Section>

                    <Section title="Subscriptions & revenue">
                        <InlineGrid columns={4} gap="300">
                            <StatTile label="Paying (monthly)" value={kpis.subscriptions.paying_monthly} />
                            <StatTile label="Paying (yearly)" value={kpis.subscriptions.paying_yearly} />
                            <StatTile label="MRR" value={`$${kpis.subscriptions.mrr}`} />
                            <StatTile label="ARR" value={`$${kpis.subscriptions.arr}`} />
                            <StatTile label="ARPU" value={`$${kpis.subscriptions.arpu}`} />
                            <StatTile label="Churn" value={`${kpis.subscriptions.churn_pct}%`} />
                            <StatTile
                                label="Cancellations this month"
                                value={kpis.subscriptions.cancellations_this_month}
                            />
                        </InlineGrid>
                        <Text as="p" tone="subdued" variant="bodySm">
                            MRR/ARR use the current Starter/Pro/Premium list prices — update
                            this estimate by hand if store pricing changes until a real
                            prices table exists.
                        </Text>
                    </Section>

                    <Section title="Notification volume">
                        <InlineGrid columns={3} gap="300">
                            <StatTile label="Push sent" value={kpis.notifications.push} />
                            <StatTile label="Email sent" value={kpis.notifications.email} />
                            <StatTile label="SMS sent" value={kpis.notifications.sms} />
                        </InlineGrid>
                    </Section>

                    <Section title="SMS cost vs. revenue">
                        <InlineGrid columns={2} gap="300">
                            <StatTile label="Messages sent" value={kpis.sms.sent_count} />
                            <StatTile
                                label="Top-up credits purchased"
                                value={kpis.sms.topup_credits_purchased}
                            />
                        </InlineGrid>
                        <Text as="p" tone="subdued" variant="bodySm">
                            SMS sending isn't live yet (pending Twilio account setup), so
                            these will read zero until then.
                        </Text>
                    </Section>

                    <Section title="Top platforms connected">
                        <Card>
                            {platformRows.length > 0 ? (
                                <DataTable
                                    columnContentTypes={['text', 'numeric']}
                                    headings={['Platform', 'Connections']}
                                    rows={platformRows}
                                />
                            ) : (
                                <Text as="p" tone="subdued">
                                    No stores connected yet.
                                </Text>
                            )}
                        </Card>
                    </Section>

                    <Section title="Activation funnel">
                        <Card>
                            <DataTable
                                columnContentTypes={['text', 'numeric', 'text']}
                                headings={['Step', 'Users', '% of signups']}
                                rows={funnelRows}
                            />
                        </Card>
                        <Text as="p" tone="subdued" variant="bodySm">
                            "Paywall seen" isn't tracked yet (no paywall-impression
                            analytics), so the funnel skips straight from rule creation to
                            paid.
                        </Text>
                    </Section>
                </BlockStack>
            </Page>
        </>
    );
}

function percentOf(part: number, whole: number): string {
    if (whole === 0) {
        return '0%';
    }

    return `${Math.round((part / whole) * 100)}%`;
}

Dashboard.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
