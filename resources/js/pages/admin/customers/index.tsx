import { Head, Link, router } from '@inertiajs/react';
import {
    Badge,
    Box,
    Card,
    IndexFilters,
    IndexFiltersMode,
    IndexTable,
    Page,
    Pagination,
    Select,
    Text,
    TextField,
    useSetIndexFiltersMode,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type CustomerSummary = {
    id: number;
    name: string;
    email: string;
    business_name: string | null;
    plan_status: string;
    platforms: string[];
    ltv: number | null;
    created_at: string | null;
    last_active_at: string | null;
    suspended_at: string | null;
};

type Filters = {
    q?: string;
    plan?: string;
    platform?: string;
    country?: string;
    ltv_min?: string;
    ltv_max?: string;
};

type Props = {
    filters: Filters;
    customers: {
        data: CustomerSummary[];
        current_page: number;
        last_page: number;
        total: number;
    };
};

const PLAN_OPTIONS = [
    { label: 'All plans', value: '' },
    { label: 'Free', value: 'free' },
    { label: 'Trial', value: 'trial' },
    { label: 'Active', value: 'active' },
    { label: 'Grace period', value: 'grace' },
    { label: 'Expired', value: 'expired' },
];

const PLATFORM_OPTIONS = [
    { label: 'All platforms', value: '' },
    { label: 'Shopify', value: 'shopify' },
    { label: 'WooCommerce', value: 'woo' },
    { label: 'eBay', value: 'ebay' },
    { label: 'Etsy', value: 'etsy' },
    { label: 'Amazon', value: 'amazon' },
];

function planLabel(value: string): string {
    return (
        PLAN_OPTIONS.find((option) => option.value === value)?.label ?? value
    );
}

function planTone(
    status: string,
    suspended: boolean,
): 'success' | 'attention' | 'warning' | 'critical' | undefined {
    if (suspended) {
        return 'critical';
    }

    switch (status) {
        case 'active':
            return 'success';
        case 'trial':
            return 'attention';
        case 'grace':
            return 'warning';
        case 'expired':
            return 'critical';
        default:
            return undefined;
    }
}

export default function CustomersIndex({ filters, customers }: Props) {
    const [queryValue, setQueryValue] = useState(filters.q ?? '');
    const [plan, setPlan] = useState(filters.plan ?? '');
    const [platform, setPlatform] = useState(filters.platform ?? '');
    const [country, setCountry] = useState(filters.country ?? '');
    const [ltvMin, setLtvMin] = useState(filters.ltv_min ?? '');
    const [ltvMax, setLtvMax] = useState(filters.ltv_max ?? '');
    const { mode, setMode } = useSetIndexFiltersMode(IndexFiltersMode.Default);

    const applyFilters = (next: Partial<Filters> = {}) => {
        router.get(
            '/admin/customers',
            {
                q: queryValue,
                plan,
                platform,
                country,
                ltv_min: ltvMin,
                ltv_max: ltvMax,
                ...next,
            },
            { preserveState: true, replace: true },
        );
    };

    const clearAll = () => {
        setQueryValue('');
        setPlan('');
        setPlatform('');
        setCountry('');
        setLtvMin('');
        setLtvMax('');
        router.get(
            '/admin/customers',
            {},
            { preserveState: true, replace: true },
        );
    };

    const appliedFilters = [
        plan
            ? {
                  key: 'plan',
                  label: `Plan: ${planLabel(plan)}`,
                  onRemove: () => {
                      setPlan('');
                      applyFilters({ plan: '' });
                  },
              }
            : null,
        platform
            ? {
                  key: 'platform',
                  label: `Platform: ${PLATFORM_OPTIONS.find((o) => o.value === platform)?.label ?? platform}`,
                  onRemove: () => {
                      setPlatform('');
                      applyFilters({ platform: '' });
                  },
              }
            : null,
        country
            ? {
                  key: 'country',
                  label: `Shipped to: ${country}`,
                  onRemove: () => {
                      setCountry('');
                      applyFilters({ country: '' });
                  },
              }
            : null,
        ltvMin || ltvMax
            ? {
                  key: 'ltv',
                  label: `LTV: ${ltvMin || '0'}–${ltvMax || '∞'}`,
                  onRemove: () => {
                      setLtvMin('');
                      setLtvMax('');
                      applyFilters({ ltv_min: '', ltv_max: '' });
                  },
              }
            : null,
    ].filter((f): f is NonNullable<typeof f> => f !== null);

    const rowMarkup = customers.data.map((customer, index) => (
        <IndexTable.Row
            id={String(customer.id)}
            key={customer.id}
            position={index}
        >
            <IndexTable.Cell>
                <Link href={`/admin/customers/${customer.id}`}>
                    <Text as="span" fontWeight="semibold">
                        {customer.name || customer.email}
                    </Text>
                </Link>
            </IndexTable.Cell>
            <IndexTable.Cell>{customer.email}</IndexTable.Cell>
            <IndexTable.Cell>
                <Badge
                    tone={planTone(
                        customer.plan_status,
                        customer.suspended_at !== null,
                    )}
                >
                    {customer.suspended_at
                        ? 'Suspended'
                        : planLabel(customer.plan_status)}
                </Badge>
            </IndexTable.Cell>
            <IndexTable.Cell>
                {customer.platforms.join(', ') || '—'}
            </IndexTable.Cell>
            <IndexTable.Cell>
                {customer.ltv !== null ? `$${customer.ltv.toFixed(2)}` : '—'}
            </IndexTable.Cell>
            <IndexTable.Cell>
                {customer.created_at
                    ? new Date(customer.created_at).toLocaleDateString()
                    : '—'}
            </IndexTable.Cell>
            <IndexTable.Cell>
                {customer.last_active_at
                    ? new Date(customer.last_active_at).toLocaleDateString()
                    : 'Never'}
            </IndexTable.Cell>
        </IndexTable.Row>
    ));

    return (
        <>
            <Head title="Customers" />
            <Page
                title="Customers"
                subtitle={`${customers.total} total`}
                fullWidth
                secondaryActions={[
                    {
                        content: 'Export CSV',
                        url: `/admin/customers/export?q=${encodeURIComponent(queryValue)}&plan=${plan}&platform=${platform}&country=${encodeURIComponent(country)}&ltv_min=${ltvMin}&ltv_max=${ltvMax}`,
                    },
                ]}
            >
                <Card padding="0">
                    <IndexFilters
                        queryValue={queryValue}
                        queryPlaceholder="Search by name, email, or business"
                        onQueryChange={setQueryValue}
                        onQueryBlur={() => applyFilters({ q: queryValue })}
                        onQueryClear={() => {
                            setQueryValue('');
                            applyFilters({ q: '' });
                        }}
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
                                key: 'plan',
                                label: 'Plan',
                                filter: (
                                    <Select
                                        label="Plan"
                                        labelHidden
                                        options={PLAN_OPTIONS}
                                        value={plan}
                                        onChange={(value) => {
                                            setPlan(value);
                                            applyFilters({ plan: value });
                                        }}
                                    />
                                ),
                            },
                            {
                                key: 'platform',
                                label: 'Platform',
                                filter: (
                                    <Select
                                        label="Platform"
                                        labelHidden
                                        options={PLATFORM_OPTIONS}
                                        value={platform}
                                        onChange={(value) => {
                                            setPlatform(value);
                                            applyFilters({ platform: value });
                                        }}
                                    />
                                ),
                            },
                            {
                                key: 'country',
                                label: 'Shipped to country',
                                filter: (
                                    <TextField
                                        label="Shipped to country"
                                        labelHidden
                                        value={country}
                                        onChange={setCountry}
                                        onBlur={() => applyFilters({ country })}
                                        autoComplete="off"
                                        placeholder="e.g. US, AU — matches at least one shipped order"
                                    />
                                ),
                            },
                            {
                                key: 'ltv',
                                label: 'LTV range',
                                filter: (
                                    <div
                                        style={{
                                            display: 'flex',
                                            gap: '8px',
                                        }}
                                    >
                                        <TextField
                                            label="Min LTV"
                                            type="number"
                                            value={ltvMin}
                                            onChange={setLtvMin}
                                            onBlur={() =>
                                                applyFilters({
                                                    ltv_min: ltvMin,
                                                })
                                            }
                                            autoComplete="off"
                                        />
                                        <TextField
                                            label="Max LTV"
                                            type="number"
                                            value={ltvMax}
                                            onChange={setLtvMax}
                                            onBlur={() =>
                                                applyFilters({
                                                    ltv_max: ltvMax,
                                                })
                                            }
                                            autoComplete="off"
                                        />
                                    </div>
                                ),
                            },
                        ]}
                        appliedFilters={appliedFilters}
                        onClearAll={clearAll}
                    />
                    <IndexTable
                        resourceName={{
                            singular: 'customer',
                            plural: 'customers',
                        }}
                        itemCount={customers.data.length}
                        selectable={false}
                        headings={[
                            { title: 'Name' },
                            { title: 'Email' },
                            { title: 'Plan' },
                            { title: 'Platforms' },
                            { title: 'LTV' },
                            { title: 'Signed up' },
                            { title: 'Last active' },
                        ]}
                        emptyState={
                            <Box padding="400">
                                <Text as="p" tone="subdued" alignment="center">
                                    No customers match these filters.
                                </Text>
                            </Box>
                        }
                    >
                        {rowMarkup}
                    </IndexTable>
                </Card>

                {customers.last_page > 1 && (
                    <Box padding="400">
                        <div
                            style={{
                                display: 'flex',
                                justifyContent: 'center',
                            }}
                        >
                            <Pagination
                                hasPrevious={customers.current_page > 1}
                                onPrevious={() =>
                                    router.get('/admin/customers', {
                                        q: queryValue,
                                        plan,
                                        platform,
                                        country,
                                        ltv_min: ltvMin,
                                        ltv_max: ltvMax,
                                        page: customers.current_page - 1,
                                    })
                                }
                                hasNext={
                                    customers.current_page < customers.last_page
                                }
                                onNext={() =>
                                    router.get('/admin/customers', {
                                        q: queryValue,
                                        plan,
                                        platform,
                                        country,
                                        ltv_min: ltvMin,
                                        ltv_max: ltvMax,
                                        page: customers.current_page + 1,
                                    })
                                }
                            />
                        </div>
                    </Box>
                )}
            </Page>
        </>
    );
}

CustomersIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
