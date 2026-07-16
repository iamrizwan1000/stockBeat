import { Head, Link, router } from '@inertiajs/react';
import {
    Badge,
    BlockStack,
    Button,
    Card,
    DataTable,
    InlineStack,
    Page,
    Select,
    Text,
} from '@shopify/polaris';
import type { ReactNode } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type BadgeTone = 'success' | 'info' | 'attention' | undefined;

type ThreadSummary = {
    id: number;
    user_name: string;
    user_email: string;
    status: string;
    priority: string;
    assigned_admin_name: string | null;
    last_message_at: string | null;
};

type Filters = {
    status: string | null;
    unassigned: boolean;
};

const STATUS_OPTIONS = [
    { label: 'All statuses', value: '' },
    { label: 'Open', value: 'open' },
    { label: 'Awaiting user', value: 'awaiting_user' },
    { label: 'Resolved', value: 'resolved' },
];

const STATUS_TONE: Record<string, BadgeTone> = {
    open: 'attention',
    awaiting_user: 'info',
    resolved: 'success',
};

export default function SupportIndex({
    threads,
    filters,
}: {
    threads: ThreadSummary[];
    filters: Filters;
}) {
    const applyFilters = (next: Partial<Filters>) => {
        router.get(
            '/admin/support',
            { ...filters, ...next },
            { preserveState: true, replace: true },
        );
    };

    const rows = threads.map((thread) => [
        <Link key={thread.id} href={`/admin/support/${thread.id}`}>
            <Text as="span" fontWeight="semibold">
                {thread.user_name || thread.user_email}
            </Text>
        </Link>,
        <Badge key={`${thread.id}-status`} tone={STATUS_TONE[thread.status]}>
            {thread.status.replace('_', ' ')}
        </Badge>,
        thread.priority === 'high' ? (
            <Badge key={`${thread.id}-priority`} tone="critical">
                High
            </Badge>
        ) : (
            '—'
        ),
        thread.assigned_admin_name ?? 'Unassigned',
        thread.last_message_at
            ? new Date(thread.last_message_at).toLocaleString()
            : '—',
    ]);

    return (
        <>
            <Head title="Support Inbox" />
            <Page title="Support Inbox" fullWidth>
                <BlockStack gap="400">
                    <Card>
                        <InlineStack gap="300" blockAlign="center">
                            <Select
                                label="Status"
                                labelHidden
                                options={STATUS_OPTIONS}
                                value={filters.status ?? ''}
                                onChange={(value) =>
                                    applyFilters({ status: value || null })
                                }
                            />
                            <Button
                                pressed={filters.unassigned}
                                onClick={() =>
                                    applyFilters({
                                        unassigned: !filters.unassigned,
                                    })
                                }
                            >
                                Unassigned only
                            </Button>
                        </InlineStack>
                    </Card>

                    <Card>
                        {rows.length > 0 ? (
                            <DataTable
                                columnContentTypes={[
                                    'text',
                                    'text',
                                    'text',
                                    'text',
                                    'text',
                                ]}
                                headings={[
                                    'User',
                                    'Status',
                                    'Priority',
                                    'Assigned to',
                                    'Last message',
                                ]}
                                rows={rows}
                            />
                        ) : (
                            <Text as="p" tone="subdued">
                                No threads match these filters.
                            </Text>
                        )}
                    </Card>
                </BlockStack>
            </Page>
        </>
    );
}

SupportIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
