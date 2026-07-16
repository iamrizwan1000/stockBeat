import { Head, Link } from '@inertiajs/react';
import {
    Badge,
    BlockStack,
    Card,
    DataTable,
    Page,
    Text,
} from '@shopify/polaris';
import type { ReactNode } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type BadgeTone = 'success' | 'info' | 'attention' | 'critical' | undefined;

type BroadcastSummary = {
    id: number;
    audience_type: string;
    segment_name: string | null;
    recipient_email: string | null;
    channels: string[];
    title: string;
    status: string;
    scheduled_at: string | null;
    sent_at: string | null;
    created_by_name: string | null;
    created_at: string | null;
};

const STATUS_TONE: Record<string, BadgeTone> = {
    draft: undefined,
    scheduled: 'info',
    sending: 'attention',
    sent: 'success',
    failed: 'critical',
};

function audienceLabel(broadcast: BroadcastSummary): string {
    if (broadcast.audience_type === 'all') {
        return 'All users';
    }

    if (broadcast.audience_type === 'segment') {
        return broadcast.segment_name ?? 'Segment';
    }

    return broadcast.recipient_email ?? 'Single user';
}

export default function BroadcastsIndex({
    broadcasts,
}: {
    broadcasts: BroadcastSummary[];
}) {
    const rows = broadcasts.map((broadcast) => [
        <Link key={broadcast.id} href={`/admin/broadcasts/${broadcast.id}`}>
            <Text as="span" fontWeight="semibold">
                {broadcast.title}
            </Text>
        </Link>,
        audienceLabel(broadcast),
        broadcast.channels.join(', '),
        <Badge
            key={`${broadcast.id}-status`}
            tone={STATUS_TONE[broadcast.status]}
        >
            {broadcast.status}
        </Badge>,
        broadcast.created_by_name ?? '—',
        broadcast.created_at
            ? new Date(broadcast.created_at).toLocaleString()
            : '—',
    ]);

    return (
        <>
            <Head title="Broadcasts" />
            <Page
                title="Broadcasts"
                subtitle="Admin → user messaging"
                fullWidth
                primaryAction={{
                    content: 'New broadcast',
                    url: '/admin/broadcasts/create',
                }}
            >
                <BlockStack gap="400">
                    <Card>
                        {rows.length > 0 ? (
                            <DataTable
                                columnContentTypes={[
                                    'text',
                                    'text',
                                    'text',
                                    'text',
                                    'text',
                                    'text',
                                ]}
                                headings={[
                                    'Title',
                                    'Audience',
                                    'Channels',
                                    'Status',
                                    'Created by',
                                    'Created',
                                ]}
                                rows={rows}
                            />
                        ) : (
                            <Text as="p" tone="subdued">
                                No broadcasts yet.
                            </Text>
                        )}
                    </Card>
                </BlockStack>
            </Page>
        </>
    );
}

BroadcastsIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
