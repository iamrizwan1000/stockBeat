import { Head, router, usePage } from '@inertiajs/react';
import {
    Badge,
    Banner,
    BlockStack,
    Button,
    Card,
    DataTable,
    InlineGrid,
    InlineStack,
    Page,
    Text,
    TextField,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type CustomerDetail = {
    user: {
        id: number;
        name: string;
        email: string;
        business_name: string | null;
        base_currency: string;
        timezone: string | null;
        sells_on: string[] | null;
        suspended_at: string | null;
        created_at: string | null;
        last_active_at: string | null;
    };
    team: { id: number; name: string } | null;
    entitlements: { plan: string; limits: Record<string, unknown> } | null;
    subscription: {
        status: string;
        product_id: string | null;
        provider: string | null;
        trial_ends_at: string | null;
        expires_at: string | null;
        renewed_at: string | null;
    } | null;
    devices: Array<{ id: number; platform: string; last_seen_at: string | null }>;
    store_connections: Array<{
        id: number;
        platform: string;
        name: string;
        status: string;
        last_sync_at: string | null;
        webhook_status: string | null;
    }>;
    rules: Array<{ id: number; name: string; trigger: string; enabled: boolean }>;
    sms_ledger: Array<{
        id: number;
        delta: number;
        reason: string;
        balance_after: number;
        created_at: string | null;
    }>;
    ai_usage: {
        questions_used_this_month: number;
        monthly_limit: number | null;
        bonus_granted_this_month: number;
        ledger: Array<{
            id: number;
            delta: number;
            reason: string;
            balance_after: number;
            created_at: string | null;
        }>;
    } | null;
    notification_volume: { push: number; email: number; sms: number };
    funnel_position: string;
    subscription_timeline: Array<{
        id: number;
        event_type: string;
        price: number | null;
        currency: string | null;
        occurred_at: string | null;
    }>;
    ltv: {
        total: number;
        currency: string;
        events_included: number;
        events_excluded_no_price: number;
        events_excluded_no_fx_rate: number;
    } | null;
    abuse_flags: { trial_abuse_suspected: boolean; high_sms_cost: boolean };
};

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

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

export default function CustomerShow({ customer }: { customer: CustomerDetail }) {
    const { props } = usePage<{ flash: { status: string | null } }>();
    const [trialDays, setTrialDays] = useState('7');
    const [proDays, setProDays] = useState('30');
    const [smsCredits, setSmsCredits] = useState('100');
    const [aiCredits, setAiCredits] = useState('20');

    const post = (url: string, data: Record<string, string> = {}) => router.post(url, data);

    const confirmAndPost = (message: string, url: string) => {
        if (window.confirm(message)) {
            post(url);
        }
    };

    return (
        <>
            <Head title={customer.user.name || customer.user.email} />
            <Page
                title={customer.user.name || customer.user.email}
                subtitle={customer.user.email}
                backAction={{ url: '/admin/customers' }}
                fullWidth
            >
                <BlockStack gap="500">
                    {props.flash?.status && <Banner tone="success">{props.flash.status}</Banner>}

                    {(customer.abuse_flags.trial_abuse_suspected || customer.abuse_flags.high_sms_cost) && (
                        <InlineStack gap="200">
                            {customer.abuse_flags.trial_abuse_suspected && (
                                <Badge tone="warning">⚠️ Trial abuse suspected</Badge>
                            )}
                            {customer.abuse_flags.high_sms_cost && (
                                <Badge tone="warning">⚠️ High SMS cost</Badge>
                            )}
                        </InlineStack>
                    )}

                    <Section title="Actions">
                        <Card>
                            <BlockStack gap="400">
                                <InlineStack gap="300" blockAlign="end">
                                    <div style={{ width: '120px' }}>
                                        <TextField
                                            label="Days"
                                            type="number"
                                            value={trialDays}
                                            onChange={setTrialDays}
                                            autoComplete="off"
                                        />
                                    </div>
                                    <Button
                                        onClick={() =>
                                            post(`/admin/customers/${customer.user.id}/extend-trial`, {
                                                days: trialDays,
                                            })
                                        }
                                    >
                                        Extend trial
                                    </Button>
                                </InlineStack>

                                <InlineStack gap="300" blockAlign="end">
                                    <div style={{ width: '120px' }}>
                                        <TextField
                                            label="Days"
                                            type="number"
                                            value={proDays}
                                            onChange={setProDays}
                                            autoComplete="off"
                                        />
                                    </div>
                                    <Button
                                        onClick={() =>
                                            post(`/admin/customers/${customer.user.id}/grant-pro`, {
                                                days: proDays,
                                            })
                                        }
                                    >
                                        Grant complimentary Pro
                                    </Button>
                                </InlineStack>

                                <InlineStack gap="300" blockAlign="end">
                                    <div style={{ width: '120px' }}>
                                        <TextField
                                            label="Credits"
                                            type="number"
                                            value={smsCredits}
                                            onChange={setSmsCredits}
                                            autoComplete="off"
                                        />
                                    </div>
                                    <Button
                                        onClick={() =>
                                            post(`/admin/customers/${customer.user.id}/grant-sms-credits`, {
                                                credits: smsCredits,
                                            })
                                        }
                                    >
                                        Grant bonus SMS credits
                                    </Button>
                                </InlineStack>

                                <InlineStack gap="300" blockAlign="end">
                                    <div style={{ width: '120px' }}>
                                        <TextField
                                            label="Credits"
                                            type="number"
                                            value={aiCredits}
                                            onChange={setAiCredits}
                                            autoComplete="off"
                                        />
                                    </div>
                                    <Button
                                        onClick={() =>
                                            post(`/admin/customers/${customer.user.id}/grant-ai-credits`, {
                                                credits: aiCredits,
                                            })
                                        }
                                    >
                                        Grant bonus AI question credits
                                    </Button>
                                </InlineStack>

                                <InlineStack gap="300">
                                    <Button
                                        onClick={() =>
                                            confirmAndPost(
                                                'Log this user out of all devices?',
                                                `/admin/customers/${customer.user.id}/force-logout`,
                                            )
                                        }
                                    >
                                        Force logout
                                    </Button>
                                    {customer.user.suspended_at ? (
                                        <Button
                                            onClick={() =>
                                                post(`/admin/customers/${customer.user.id}/unsuspend`)
                                            }
                                        >
                                            Unsuspend account
                                        </Button>
                                    ) : (
                                        <Button
                                            tone="critical"
                                            onClick={() =>
                                                confirmAndPost(
                                                    'Suspend this account? They will be logged out and unable to use the app.',
                                                    `/admin/customers/${customer.user.id}/suspend`,
                                                )
                                            }
                                        >
                                            Suspend account
                                        </Button>
                                    )}
                                </InlineStack>
                            </BlockStack>
                        </Card>
                    </Section>

                    <Section title="Profile">
                        <Card>
                            <InlineGrid columns={3} gap="300">
                                <Text as="p">
                                    <b>Business:</b> {customer.user.business_name ?? '—'}
                                </Text>
                                <Text as="p">
                                    <b>Currency:</b> {customer.user.base_currency}
                                </Text>
                                <Text as="p">
                                    <b>Timezone:</b> {customer.user.timezone ?? '—'}
                                </Text>
                                <Text as="p">
                                    <b>Signed up:</b> {formatDate(customer.user.created_at)}
                                </Text>
                                <Text as="p">
                                    <b>Last active:</b> {formatDate(customer.user.last_active_at)}
                                </Text>
                                <Text as="p">
                                    <b>Status:</b>{' '}
                                    {customer.user.suspended_at
                                        ? `Suspended (${formatDate(customer.user.suspended_at)})`
                                        : 'Active'}
                                </Text>
                            </InlineGrid>
                        </Card>
                    </Section>

                    <Section title="Plan & subscription">
                        <Card>
                            <InlineGrid columns={3} gap="300">
                                <Text as="p">
                                    <b>Effective plan:</b> {customer.entitlements?.plan ?? 'free'}
                                </Text>
                                <Text as="p">
                                    <b>Funnel position:</b> {customer.funnel_position.replaceAll('_', ' ')}
                                </Text>
                                <Text as="p">
                                    <b>Subscription status:</b> {customer.subscription?.status ?? 'none'}
                                </Text>
                                {customer.subscription?.trial_ends_at && (
                                    <Text as="p">
                                        <b>Trial ends:</b> {formatDate(customer.subscription.trial_ends_at)}
                                    </Text>
                                )}
                                {customer.subscription?.product_id && (
                                    <Text as="p">
                                        <b>Product:</b> {customer.subscription.product_id}
                                    </Text>
                                )}
                                <Text as="p">
                                    <b>LTV:</b>{' '}
                                    {customer.ltv
                                        ? `${customer.ltv.total.toFixed(2)} ${customer.ltv.currency}`
                                        : '—'}
                                </Text>
                            </InlineGrid>
                            {customer.ltv &&
                                (customer.ltv.events_excluded_no_price > 0 ||
                                    customer.ltv.events_excluded_no_fx_rate > 0) && (
                                    <Text as="p" tone="subdued">
                                        LTV may be incomplete: {customer.ltv.events_excluded_no_price} event(s)
                                        carried no price, {customer.ltv.events_excluded_no_fx_rate} event(s)
                                        had no FX rate available for conversion.
                                    </Text>
                                )}
                        </Card>
                    </Section>

                    <Section title="Devices">
                        <Card>
                            {customer.devices.length > 0 ? (
                                <DataTable
                                    columnContentTypes={['text', 'text']}
                                    headings={['Platform', 'Last seen']}
                                    rows={customer.devices.map((d) => [d.platform, formatDate(d.last_seen_at)])}
                                />
                            ) : (
                                <Text as="p" tone="subdued">
                                    No devices registered.
                                </Text>
                            )}
                        </Card>
                    </Section>

                    <Section title="Connected stores">
                        <Card>
                            {customer.store_connections.length > 0 ? (
                                <DataTable
                                    columnContentTypes={['text', 'text', 'text', 'text']}
                                    headings={['Platform', 'Name', 'Status', 'Last sync']}
                                    rows={customer.store_connections.map((c) => [
                                        c.platform,
                                        c.name,
                                        c.status,
                                        formatDate(c.last_sync_at),
                                    ])}
                                />
                            ) : (
                                <Text as="p" tone="subdued">
                                    No stores connected.
                                </Text>
                            )}
                        </Card>
                    </Section>

                    <Section title="Rules & notification volume">
                        <Card>
                            <BlockStack gap="300">
                                <InlineGrid columns={3} gap="300">
                                    <Text as="p">
                                        <b>Push sent:</b> {customer.notification_volume.push}
                                    </Text>
                                    <Text as="p">
                                        <b>Email sent:</b> {customer.notification_volume.email}
                                    </Text>
                                    <Text as="p">
                                        <b>SMS sent:</b> {customer.notification_volume.sms}
                                    </Text>
                                </InlineGrid>
                                {customer.rules.length > 0 ? (
                                    <DataTable
                                        columnContentTypes={['text', 'text', 'text']}
                                        headings={['Name', 'Trigger', 'Enabled']}
                                        rows={customer.rules.map((r) => [
                                            r.name,
                                            r.trigger,
                                            r.enabled ? 'Yes' : 'No',
                                        ])}
                                    />
                                ) : (
                                    <Text as="p" tone="subdued">
                                        No rules created.
                                    </Text>
                                )}
                            </BlockStack>
                        </Card>
                    </Section>

                    <Section title="SMS ledger">
                        <Card>
                            {customer.sms_ledger.length > 0 ? (
                                <DataTable
                                    columnContentTypes={['text', 'numeric', 'numeric', 'text']}
                                    headings={['Reason', 'Delta', 'Balance after', 'Date']}
                                    rows={customer.sms_ledger.map((entry) => [
                                        entry.reason,
                                        String(entry.delta),
                                        String(entry.balance_after),
                                        formatDate(entry.created_at),
                                    ])}
                                />
                            ) : (
                                <Text as="p" tone="subdued">
                                    No SMS ledger activity.
                                </Text>
                            )}
                        </Card>
                    </Section>

                    <Section title="AI Assistant usage">
                        <Card>
                            {customer.ai_usage ? (
                                <BlockStack gap="200">
                                    <Text as="p">
                                        {customer.ai_usage.questions_used_this_month} of{' '}
                                        {customer.ai_usage.monthly_limit ?? '∞'} questions
                                        used this month
                                        {customer.ai_usage.bonus_granted_this_month > 0
                                            ? ` (includes ${customer.ai_usage.bonus_granted_this_month} bonus credits granted this month)`
                                            : ''}
                                    </Text>
                                    {customer.ai_usage.ledger.length > 0 ? (
                                        <DataTable
                                            columnContentTypes={['text', 'numeric', 'numeric', 'text']}
                                            headings={['Reason', 'Delta', 'Balance after', 'Date']}
                                            rows={customer.ai_usage.ledger.map((entry) => [
                                                entry.reason,
                                                String(entry.delta),
                                                String(entry.balance_after),
                                                formatDate(entry.created_at),
                                            ])}
                                        />
                                    ) : (
                                        <Text as="p" tone="subdued">
                                            No AI Assistant questions asked yet.
                                        </Text>
                                    )}
                                </BlockStack>
                            ) : (
                                <Text as="p" tone="subdued">
                                    No team.
                                </Text>
                            )}
                        </Card>
                    </Section>

                    <Section title="Subscription timeline">
                        <Card>
                            {customer.subscription_timeline.length > 0 ? (
                                <BlockStack gap="200">
                                    {customer.subscription_timeline.map((event) => (
                                        <InlineStack key={event.id} align="space-between" blockAlign="center">
                                            <Text as="p">
                                                <b>{event.event_type}</b>
                                                {event.price !== null && event.currency
                                                    ? ` — ${event.price.toFixed(2)} ${event.currency}`
                                                    : ''}
                                            </Text>
                                            <Text as="p" tone="subdued">
                                                {formatDate(event.occurred_at)}
                                            </Text>
                                        </InlineStack>
                                    ))}
                                </BlockStack>
                            ) : (
                                <Text as="p" tone="subdued">
                                    No RevenueCat events recorded yet.
                                </Text>
                            )}
                        </Card>
                    </Section>
                </BlockStack>
            </Page>
        </>
    );
}

CustomerShow.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
