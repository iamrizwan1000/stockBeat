import { Head, router, usePage } from '@inertiajs/react';
import {
    Banner,
    BlockStack,
    Button,
    Card,
    InlineStack,
    Page,
    Select,
    Text,
    TextField,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type PlanLimitValue = number | boolean | string | null;

type PlanLimit = {
    id: number;
    key: string;
    value: PlanLimitValue;
};

type Plan = {
    id: number;
    key: string;
    name: string;
    active: boolean;
    limits: PlanLimit[];
};

const BOOLEAN_KEYS = ['inbox_enabled', 'widgets_enabled'];
const ENUM_KEYS: Record<string, Array<{ label: string; value: string }>> = {
    analytics_level: [
        { label: 'Today only', value: 'today' },
        { label: 'Full', value: 'full' },
    ],
};

const LABELS: Record<string, string> = {
    max_stores: 'Max stores',
    max_rules: 'Max custom rules',
    sms_monthly: 'SMS / month',
    email_monthly: 'Email alerts / month',
    history_days: 'Order history (days)',
    team_seats: 'Team seats',
    trial_days: 'Trial length (days)',
    inbox_enabled: 'Unified inbox',
    analytics_level: 'Analytics level',
    widgets_enabled: 'Home-screen widgets',
};

function LimitRow({ limit }: { limit: PlanLimit }) {
    const isUnlimitedCapable = limit.key === 'max_stores' || limit.key === 'max_rules';
    const [value, setValue] = useState(
        limit.value === null ? '' : String(limit.value),
    );

    const save = () => {
        router.put(
            `/admin/plans/limits/${limit.id}`,
            { value },
            { preserveScroll: true },
        );
    };

    return (
        <InlineStack gap="300" blockAlign="end" wrap={false}>
            <div style={{ width: '220px' }}>
                <Text as="span" variant="bodyMd">
                    {LABELS[limit.key] ?? limit.key}
                </Text>
            </div>
            <div style={{ width: '200px' }}>
                {BOOLEAN_KEYS.includes(limit.key) ? (
                    <Select
                        label={LABELS[limit.key] ?? limit.key}
                        labelHidden
                        options={[
                            { label: 'Enabled', value: 'true' },
                            { label: 'Disabled', value: 'false' },
                        ]}
                        value={value === 'true' ? 'true' : 'false'}
                        onChange={setValue}
                    />
                ) : ENUM_KEYS[limit.key] ? (
                    <Select
                        label={LABELS[limit.key] ?? limit.key}
                        labelHidden
                        options={ENUM_KEYS[limit.key]}
                        value={value}
                        onChange={setValue}
                    />
                ) : (
                    <TextField
                        label={LABELS[limit.key] ?? limit.key}
                        labelHidden
                        type="number"
                        value={value}
                        onChange={setValue}
                        autoComplete="off"
                        placeholder={isUnlimitedCapable ? 'blank = unlimited' : undefined}
                    />
                )}
            </div>
            <Button onClick={save}>Save</Button>
        </InlineStack>
    );
}

export default function PlansIndex({ plans }: { plans: Plan[] }) {
    const { props } = usePage<{ flash: { status: string | null } }>();

    return (
        <>
            <Head title="Plans & Limits" />
            <Page title="Plans & Limits">
                <BlockStack gap="500">
                    {props.flash?.status && <Banner tone="success">{props.flash.status}</Banner>}

                    <Banner tone="info">
                        These limits take effect on the next entitlement refresh — no
                        app release needed. IAP <b>prices</b> themselves are controlled
                        in App Store Connect / Play Console, not here.
                    </Banner>

                    {plans.map((plan) => (
                        <Card key={plan.id}>
                            <BlockStack gap="300">
                                <Text as="h2" variant="headingMd">
                                    {plan.name}
                                </Text>
                                <BlockStack gap="200">
                                    {plan.limits.map((limit) => (
                                        <LimitRow key={limit.id} limit={limit} />
                                    ))}
                                </BlockStack>
                            </BlockStack>
                        </Card>
                    ))}
                </BlockStack>
            </Page>
        </>
    );
}

PlansIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
