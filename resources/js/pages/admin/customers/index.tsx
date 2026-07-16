import { Head, Link, router } from '@inertiajs/react';
import {
    BlockStack,
    Button,
    Card,
    DataTable,
    InlineStack,
    Page,
    Pagination,
    Select,
    Text,
    TextField,
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
    created_at: string | null;
    last_active_at: string | null;
    suspended_at: string | null;
};

type Filters = {
    q?: string;
    plan?: string;
    platform?: string;
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

export default function CustomersIndex({ filters, customers }: Props) {
    const [q, setQ] = useState(filters.q ?? '');
    const [plan, setPlan] = useState(filters.plan ?? '');
    const [platform, setPlatform] = useState(filters.platform ?? '');

    const applyFilters = (next: Partial<Filters>) => {
        router.get(
            '/admin/customers',
            { q, plan, platform, ...next },
            { preserveState: true, replace: true },
        );
    };

    const rows = customers.data.map((customer) => [
        <Link key={customer.id} href={`/admin/customers/${customer.id}`}>
            <Text as="span" fontWeight="semibold">
                {customer.name || customer.email}
            </Text>
        </Link>,
        customer.email,
        customer.suspended_at ? 'Suspended' : customer.plan_status,
        customer.platforms.join(', ') || '—',
        customer.created_at ? new Date(customer.created_at).toLocaleDateString() : '—',
        customer.last_active_at ? new Date(customer.last_active_at).toLocaleDateString() : 'Never',
    ]);

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
                        url: `/admin/customers/export?q=${encodeURIComponent(q)}&plan=${plan}&platform=${platform}`,
                    },
                ]}
            >
                <BlockStack gap="400">
                    <Card>
                        <InlineStack gap="300" wrap={false} blockAlign="end">
                            <div style={{ flexGrow: 1 }}>
                                <TextField
                                    label="Search"
                                    labelHidden
                                    placeholder="Search by name, email, or business"
                                    value={q}
                                    onChange={setQ}
                                    autoComplete="off"
                                    onBlur={() => applyFilters({ q })}
                                />
                            </div>
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
                            <Button onClick={() => applyFilters({ q })}>Search</Button>
                        </InlineStack>
                    </Card>

                    <Card>
                        {rows.length > 0 ? (
                            <DataTable
                                columnContentTypes={['text', 'text', 'text', 'text', 'text', 'text']}
                                headings={['Name', 'Email', 'Plan', 'Platforms', 'Signed up', 'Last active']}
                                rows={rows}
                            />
                        ) : (
                            <Text as="p" tone="subdued">
                                No customers match these filters.
                            </Text>
                        )}
                    </Card>

                    {customers.last_page > 1 && (
                        <InlineStack align="center">
                            <Pagination
                                hasPrevious={customers.current_page > 1}
                                onPrevious={() =>
                                    router.get('/admin/customers', {
                                        q,
                                        plan,
                                        platform,
                                        page: customers.current_page - 1,
                                    })
                                }
                                hasNext={customers.current_page < customers.last_page}
                                onNext={() =>
                                    router.get('/admin/customers', {
                                        q,
                                        plan,
                                        platform,
                                        page: customers.current_page + 1,
                                    })
                                }
                            />
                        </InlineStack>
                    )}
                </BlockStack>
            </Page>
        </>
    );
}

CustomersIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
