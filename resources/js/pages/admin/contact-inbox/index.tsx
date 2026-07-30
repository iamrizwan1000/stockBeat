import { Link, router } from '@inertiajs/react';
import { Badge, Box, Card, IndexTable, Page, Select, Text } from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type BadgeTone = 'success' | 'info' | 'attention' | undefined;

type ThreadSummary = {
    id: number;
    name: string;
    email: string;
    subject: string | null;
    status: string;
    last_message_at: string | null;
};

type Filters = {
    status: string | null;
};

const STATUS_OPTIONS = [
    { label: 'All statuses', value: '' },
    { label: 'Open', value: 'open' },
    { label: 'Replied', value: 'replied' },
    { label: 'Closed', value: 'closed' },
];

const STATUS_TONE: Record<string, BadgeTone> = {
    open: 'attention',
    replied: 'info',
    closed: 'success',
};

export default function ContactInboxIndex({ threads, filters }: { threads: ThreadSummary[]; filters: Filters }) {
    const [status, setStatus] = useState(filters.status ?? '');

    const applyFilter = (value: string) => {
        setStatus(value);
        router.get('/admin/contact-inbox', { status: value }, { preserveState: true, replace: true });
    };

    const rowMarkup = threads.map((thread, index) => (
        <IndexTable.Row id={String(thread.id)} key={thread.id} position={index}>
            <IndexTable.Cell>
                <Link href={`/admin/contact-inbox/${thread.id}`}>
                    <Text as="span" fontWeight="semibold">
                        {thread.name}
                    </Text>
                </Link>
            </IndexTable.Cell>
            <IndexTable.Cell>{thread.email}</IndexTable.Cell>
            <IndexTable.Cell>{thread.subject ?? '—'}</IndexTable.Cell>
            <IndexTable.Cell>
                <Badge tone={STATUS_TONE[thread.status]}>{thread.status}</Badge>
            </IndexTable.Cell>
            <IndexTable.Cell>
                {thread.last_message_at ? new Date(thread.last_message_at).toLocaleString() : '—'}
            </IndexTable.Cell>
        </IndexTable.Row>
    ));

    return (
        <Page title="Contact Inbox" fullWidth>
            <Card padding="0">
                <Box padding="400">
                    <Select
                        label="Status"
                        options={STATUS_OPTIONS}
                        value={status}
                        onChange={applyFilter}
                    />
                </Box>
                <IndexTable
                    resourceName={{ singular: 'inquiry', plural: 'inquiries' }}
                    itemCount={threads.length}
                    selectable={false}
                    headings={[
                        { title: 'Name' },
                        { title: 'Email' },
                        { title: 'Subject' },
                        { title: 'Status' },
                        { title: 'Last message' },
                    ]}
                    emptyState={
                        <Box padding="400">
                            <Text as="p" tone="subdued" alignment="center">
                                No contact inquiries yet.
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

ContactInboxIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
