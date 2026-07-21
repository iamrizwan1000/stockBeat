import { Head, router, usePage } from '@inertiajs/react';
import {
    Banner,
    Badge,
    BlockStack,
    Box,
    Button,
    Card,
    Checkbox,
    IndexTable,
    InlineStack,
    Page,
    Select,
    Tabs,
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

type SmsTopupPack = {
    id: number;
    key: string;
    name: string;
    sms_credits: number;
    price_usd: string;
    active: boolean;
    sort_order: number;
};

type ContentBlock = {
    id: number;
    key: string;
    title: string;
    body: string;
    locale: string;
    active: boolean;
};

const BOOLEAN_KEYS = [
    'inbox_enabled',
    'widgets_enabled',
    'advanced_triggers_enabled',
    'ai_enabled',
    'ai_rule_builder_enabled',
    'ai_proactive_insights_enabled',
];
const ENUM_KEYS: Record<string, Array<{ label: string; value: string }>> = {
    analytics_level: [
        { label: 'Today only', value: 'today' },
        { label: 'Today + 7 days', value: '7d' },
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
    advanced_triggers_enabled: 'Advanced rule triggers (order/refund spike)',
    ai_enabled: 'AI Data Copilot',
    ai_questions_monthly: 'AI questions / month',
    ai_rule_builder_enabled: 'AI natural-language rule builder',
    ai_proactive_insights_enabled: 'Proactive AI Insights',
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

function LimitsPanel({ plans }: { plans: Plan[] }) {
    return (
        <BlockStack gap="400">
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
    );
}

function SmsTopupPacksPanel({ packs }: { packs: SmsTopupPack[] }) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [key, setKey] = useState('');
    const [name, setName] = useState('');
    const [smsCredits, setSmsCredits] = useState('100');
    const [priceUsd, setPriceUsd] = useState('2.99');
    const [active, setActive] = useState(true);
    const [sortOrder, setSortOrder] = useState('0');

    const resetForm = () => {
        setEditingId(null);
        setKey('');
        setName('');
        setSmsCredits('100');
        setPriceUsd('2.99');
        setActive(true);
        setSortOrder('0');
    };

    const edit = (pack: SmsTopupPack) => {
        setEditingId(pack.id);
        setKey(pack.key);
        setName(pack.name);
        setSmsCredits(String(pack.sms_credits));
        setPriceUsd(pack.price_usd);
        setActive(pack.active);
        setSortOrder(String(pack.sort_order));
    };

    const save = () => {
        const payload = {
            name,
            sms_credits: smsCredits,
            price_usd: priceUsd,
            active,
            sort_order: sortOrder,
        };

        if (editingId) {
            router.put(`/admin/plans/sms-packs/${editingId}`, payload, {
                preserveScroll: true,
                onSuccess: resetForm,
            });
        } else {
            router.post(
                '/admin/plans/sms-packs',
                { ...payload, key },
                { preserveScroll: true, onSuccess: resetForm },
            );
        }
    };

    const destroy = (pack: SmsTopupPack) => {
        if (confirm(`Delete SMS top-up pack "${pack.name}" (${pack.key})?`)) {
            router.delete(`/admin/plans/sms-packs/${pack.id}`, {
                preserveScroll: true,
            });
        }
    };

    const rowMarkup = packs.map((pack, index) => (
        <IndexTable.Row id={String(pack.id)} key={pack.id} position={index}>
            <IndexTable.Cell>
                <Text as="span" fontWeight="semibold">
                    {pack.key}
                </Text>
            </IndexTable.Cell>
            <IndexTable.Cell>{pack.name}</IndexTable.Cell>
            <IndexTable.Cell>{pack.sms_credits}</IndexTable.Cell>
            <IndexTable.Cell>${pack.price_usd}</IndexTable.Cell>
            <IndexTable.Cell>
                <Badge tone={pack.active ? 'success' : undefined}>
                    {pack.active ? 'Active' : 'Retired'}
                </Badge>
            </IndexTable.Cell>
            <IndexTable.Cell>{pack.sort_order}</IndexTable.Cell>
            <IndexTable.Cell>
                <InlineStack gap="200">
                    <Button onClick={() => edit(pack)}>Edit</Button>
                    <Button tone="critical" onClick={() => destroy(pack)}>
                        Delete
                    </Button>
                </InlineStack>
            </IndexTable.Cell>
        </IndexTable.Row>
    ));

    return (
        <BlockStack gap="400">
            <Banner tone="info">
                SMS credit top-up packs (Plan §5/§6) — consumable in-app
                purchases. The <b>price</b> here is informational/display
                only; the real IAP price and product id live in App Store
                Connect / Play Console. The <b>key</b> must match the
                RevenueCat product id exactly so purchases credit correctly.
            </Banner>

            <Card>
                <BlockStack gap="300">
                    <Text as="h2" variant="headingMd">
                        {editingId ? 'Edit SMS top-up pack' : 'New SMS top-up pack'}
                    </Text>
                    <TextField
                        label="Key"
                        value={key}
                        onChange={setKey}
                        autoComplete="off"
                        disabled={editingId !== null}
                        helpText={
                            editingId
                                ? 'The key cannot be changed after creation.'
                                : 'Must match the RevenueCat product id (e.g. sms_100).'
                        }
                    />
                    <TextField
                        label="Name"
                        value={name}
                        onChange={setName}
                        autoComplete="off"
                        placeholder="e.g. 100 SMS"
                    />
                    <TextField
                        label="SMS credits"
                        type="number"
                        value={smsCredits}
                        onChange={setSmsCredits}
                        autoComplete="off"
                    />
                    <TextField
                        label="Price (USD, informational only)"
                        type="number"
                        step={0.01}
                        prefix="$"
                        value={priceUsd}
                        onChange={setPriceUsd}
                        autoComplete="off"
                    />
                    <TextField
                        label="Sort order"
                        type="number"
                        value={sortOrder}
                        onChange={setSortOrder}
                        autoComplete="off"
                        helpText="Lower numbers appear first in the mobile purchase sheet."
                    />
                    <Checkbox
                        label="Active (offered to the mobile app)"
                        checked={active}
                        onChange={setActive}
                    />
                    <InlineStack gap="200">
                        <Button
                            variant="primary"
                            onClick={save}
                            disabled={!name || (!editingId && !key)}
                        >
                            {editingId ? 'Save changes' : 'Create pack'}
                        </Button>
                        {editingId && <Button onClick={resetForm}>Cancel</Button>}
                    </InlineStack>
                </BlockStack>
            </Card>

            <Card padding="0">
                <IndexTable
                    resourceName={{ singular: 'SMS pack', plural: 'SMS packs' }}
                    itemCount={packs.length}
                    selectable={false}
                    headings={[
                        { title: 'Key' },
                        { title: 'Name' },
                        { title: 'Credits' },
                        { title: 'Price' },
                        { title: 'Status' },
                        { title: 'Sort' },
                        { title: '' },
                    ]}
                    emptyState={
                        <Box padding="400">
                            <Text as="p" tone="subdued" alignment="center">
                                No SMS top-up packs yet.
                            </Text>
                        </Box>
                    }
                >
                    {rowMarkup}
                </IndexTable>
            </Card>
        </BlockStack>
    );
}

function ContentBlocksPanel({ blocks }: { blocks: ContentBlock[] }) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [key, setKey] = useState('');
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [locale, setLocale] = useState('en');
    const [active, setActive] = useState(true);

    const resetForm = () => {
        setEditingId(null);
        setKey('');
        setTitle('');
        setBody('');
        setLocale('en');
        setActive(true);
    };

    const edit = (block: ContentBlock) => {
        setEditingId(block.id);
        setKey(block.key);
        setTitle(block.title);
        setBody(block.body);
        setLocale(block.locale);
        setActive(block.active);
    };

    const save = () => {
        const payload = { title, body, locale, active };

        if (editingId) {
            router.put(`/admin/plans/content-blocks/${editingId}`, payload, {
                preserveScroll: true,
                onSuccess: resetForm,
            });
        } else {
            router.post(
                '/admin/plans/content-blocks',
                { ...payload, key },
                { preserveScroll: true, onSuccess: resetForm },
            );
        }
    };

    const destroy = (block: ContentBlock) => {
        if (confirm(`Delete content block "${block.title}" (${block.key})?`)) {
            router.delete(`/admin/plans/content-blocks/${block.id}`, {
                preserveScroll: true,
            });
        }
    };

    const rowMarkup = blocks.map((block, index) => (
        <IndexTable.Row id={String(block.id)} key={block.id} position={index}>
            <IndexTable.Cell>
                <Text as="span" fontWeight="semibold">
                    {block.key}
                </Text>
            </IndexTable.Cell>
            <IndexTable.Cell>{block.title}</IndexTable.Cell>
            <IndexTable.Cell>{block.locale}</IndexTable.Cell>
            <IndexTable.Cell>
                <Badge tone={block.active ? 'success' : undefined}>
                    {block.active ? 'Active' : 'Retired'}
                </Badge>
            </IndexTable.Cell>
            <IndexTable.Cell>
                <InlineStack gap="200">
                    <Button onClick={() => edit(block)}>Edit</Button>
                    <Button tone="critical" onClick={() => destroy(block)}>
                        Delete
                    </Button>
                </InlineStack>
            </IndexTable.Cell>
        </IndexTable.Row>
    ));

    return (
        <BlockStack gap="400">
            <Banner tone="info">
                Paywall & store-listing copy blocks (Plan §5.1) — editable
                remote content served to the mobile app via <code>/me</code>.
                Body text can include simple <code>{'{placeholders}'}</code>{' '}
                (e.g. <code>{'{price}'}</code>) the client substitutes at
                render time.
            </Banner>

            <Card>
                <BlockStack gap="300">
                    <Text as="h2" variant="headingMd">
                        {editingId ? 'Edit content block' : 'New content block'}
                    </Text>
                    <TextField
                        label="Key"
                        value={key}
                        onChange={setKey}
                        autoComplete="off"
                        disabled={editingId !== null}
                        helpText={
                            editingId
                                ? 'The key cannot be changed after creation.'
                                : 'Lowercase letters, digits, underscores only (e.g. paywall_pro_headline).'
                        }
                    />
                    <TextField
                        label="Title (admin-facing label)"
                        value={title}
                        onChange={setTitle}
                        autoComplete="off"
                    />
                    <TextField
                        label="Body"
                        value={body}
                        onChange={setBody}
                        autoComplete="off"
                        multiline={4}
                    />
                    <TextField
                        label="Locale"
                        value={locale}
                        onChange={setLocale}
                        autoComplete="off"
                        helpText="Only 'en' has real content today."
                    />
                    <Checkbox
                        label="Active (served to the mobile app)"
                        checked={active}
                        onChange={setActive}
                    />
                    <InlineStack gap="200">
                        <Button
                            variant="primary"
                            onClick={save}
                            disabled={!title || !body || (!editingId && !key)}
                        >
                            {editingId ? 'Save changes' : 'Create block'}
                        </Button>
                        {editingId && <Button onClick={resetForm}>Cancel</Button>}
                    </InlineStack>
                </BlockStack>
            </Card>

            <Card padding="0">
                <IndexTable
                    resourceName={{ singular: 'content block', plural: 'content blocks' }}
                    itemCount={blocks.length}
                    selectable={false}
                    headings={[
                        { title: 'Key' },
                        { title: 'Title' },
                        { title: 'Locale' },
                        { title: 'Status' },
                        { title: '' },
                    ]}
                    emptyState={
                        <Box padding="400">
                            <Text as="p" tone="subdued" alignment="center">
                                No content blocks yet.
                            </Text>
                        </Box>
                    }
                >
                    {rowMarkup}
                </IndexTable>
            </Card>
        </BlockStack>
    );
}

export default function PlansIndex({
    plans,
    smsTopupPacks,
    contentBlocks,
}: {
    plans: Plan[];
    smsTopupPacks: SmsTopupPack[];
    contentBlocks: ContentBlock[];
}) {
    const { props } = usePage<{ flash: { status: string | null } }>();
    const [selectedTab, setSelectedTab] = useState(0);

    const tabs = [
        { id: 'limits', content: 'Limits' },
        { id: 'sms-packs', content: 'SMS packs' },
        { id: 'content-blocks', content: 'Content blocks' },
    ];

    return (
        <>
            <Head title="Plans & Limits" />
            <Page
                title="Plans & Limits"
                subtitle="Plan limits, SMS top-up packs, and paywall content — all live-editable, no app release needed"
                fullWidth
            >
                <BlockStack gap="400">
                    {props.flash?.status && <Banner tone="success">{props.flash.status}</Banner>}

                    <Card padding="0">
                        <Tabs tabs={tabs} selected={selectedTab} onSelect={setSelectedTab} />
                        <Box padding="400">
                            {selectedTab === 0 && <LimitsPanel plans={plans} />}
                            {selectedTab === 1 && <SmsTopupPacksPanel packs={smsTopupPacks} />}
                            {selectedTab === 2 && <ContentBlocksPanel blocks={contentBlocks} />}
                        </Box>
                    </Card>
                </BlockStack>
            </Page>
        </>
    );
}

PlansIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
