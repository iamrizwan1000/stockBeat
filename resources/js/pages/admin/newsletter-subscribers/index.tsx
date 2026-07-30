import { router } from '@inertiajs/react';
import { Badge, Box, Button, Card, IndexTable, InlineStack, Page, Select, Text, TextField } from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type Subscriber = {
    id: number;
    email: string;
    subscribed_at: string | null;
    unsubscribed_at: string | null;
};

type Filters = {
    q: string | null;
    status: string | null;
};

type Props = {
    subscribers: {
        data: Subscriber[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: Filters;
    active_count: number;
};

const STATUS_OPTIONS = [
    { label: 'All', value: '' },
    { label: 'Subscribed', value: 'subscribed' },
    { label: 'Unsubscribed', value: 'unsubscribed' },
];

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

export default function NewsletterSubscribersIndex({ subscribers, filters, active_count: activeCount }: Props) {
    const [q, setQ] = useState(filters.q ?? '');
    const [status, setStatus] = useState(filters.status ?? '');

    const applyFilters = (next: Partial<{ q: string; status: string }>) => {
        router.get('/admin/newsletter-subscribers', { q, status, ...next }, { preserveState: true, replace: true });
    };

    const rowMarkup = subscribers.data.map((subscriber, index) => (
        <IndexTable.Row id={String(subscriber.id)} key={subscriber.id} position={index}>
            <IndexTable.Cell>
                <Text as="span" fontWeight="semibold">
                    {subscriber.email}
                </Text>
            </IndexTable.Cell>
            <IndexTable.Cell>
                <Badge tone={subscriber.unsubscribed_at === null ? 'success' : undefined}>
                    {subscriber.unsubscribed_at === null ? 'Subscribed' : 'Unsubscribed'}
                </Badge>
            </IndexTable.Cell>
            <IndexTable.Cell>{formatDate(subscriber.subscribed_at)}</IndexTable.Cell>
            <IndexTable.Cell>{formatDate(subscriber.unsubscribed_at)}</IndexTable.Cell>
        </IndexTable.Row>
    ));

    return (
        <Page
            title="Newsletter Subscribers"
            subtitle={`${activeCount} currently subscribed`}
            fullWidth
            primaryAction={{
                content: 'Export CSV',
                url: `/admin/newsletter-subscribers/export?q=${encodeURIComponent(q)}&status=${status}`,
            }}
        >
            <Card padding="0">
                <Box padding="400">
                    <InlineStack gap="300" blockAlign="end">
                        <div style={{ width: '280px' }}>
                            <TextField
                                label="Search"
                                labelHidden
                                placeholder="Search by email"
                                value={q}
                                onChange={setQ}
                                autoComplete="off"
                                onBlur={() => applyFilters({ q })}
                            />
                        </div>
                        <div style={{ width: '200px' }}>
                            <Select
                                label="Status"
                                labelHidden
                                options={STATUS_OPTIONS}
                                value={status}
                                onChange={(value) => {
                                    setStatus(value);
                                    applyFilters({ status: value });
                                }}
                            />
                        </div>
                        <Button onClick={() => applyFilters({ q })}>Search</Button>
                    </InlineStack>
                </Box>
                <IndexTable
                    resourceName={{ singular: 'subscriber', plural: 'subscribers' }}
                    itemCount={subscribers.data.length}
                    selectable={false}
                    headings={[
                        { title: 'Email' },
                        { title: 'Status' },
                        { title: 'Subscribed at' },
                        { title: 'Unsubscribed at' },
                    ]}
                    emptyState={
                        <Box padding="400">
                            <Text as="p" tone="subdued" alignment="center">
                                No newsletter subscribers match these filters.
                            </Text>
                        </Box>
                    }
                >
                    {rowMarkup}
                </IndexTable>
            </Card>
        </Page>
    );
}

NewsletterSubscribersIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
