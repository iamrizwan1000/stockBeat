import { Head, router, usePage } from '@inertiajs/react';
import {
    Badge,
    BlockStack,
    Button,
    Card,
    DataTable,
    InlineStack,
    Page,
    Text,
} from '@shopify/polaris';
import type { ReactNode } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type DeliveryCounts = Record<string, Record<string, number>>;

type BroadcastDetail = {
    id: number;
    audience_type: string;
    segment_name: string | null;
    recipient_email: string | null;
    channels: string[];
    title: string;
    body: string;
    status: string;
    scheduled_at: string | null;
    sent_at: string | null;
    stats: { recipients_total?: number } | null;
    created_by_name: string | null;
    approved_by_name: string | null;
    created_at: string | null;
    template_vars_available: string[];
};

type Props = {
    broadcast: BroadcastDetail;
    delivery_counts: DeliveryCounts;
};

function audienceLabel(broadcast: BroadcastDetail): string {
    if (broadcast.audience_type === 'all') {
        return 'All users';
    }

    if (broadcast.audience_type === 'segment') {
        return broadcast.segment_name ?? 'Segment';
    }

    return broadcast.recipient_email ?? 'Single user';
}

export default function BroadcastsShow({
    broadcast,
    delivery_counts: deliveryCounts,
}: Props) {
    const { props } = usePage<{ flash: { status: string | null } }>();
    const canSend =
        broadcast.status === 'draft' || broadcast.status === 'scheduled';

    const deliveryRows = Object.entries(deliveryCounts).flatMap(
        ([channel, statuses]) =>
            Object.entries(statuses).map(([status, count]) => [
                channel,
                status,
                String(count),
            ]),
    );

    return (
        <>
            <Head title={broadcast.title} />
            <Page
                title={broadcast.title}
                backAction={{ url: '/admin/broadcasts' }}
            >
                <BlockStack gap="400">
                    {props.flash?.status && (
                        <Card>
                            <Text as="p">{props.flash.status}</Text>
                        </Card>
                    )}

                    <Card>
                        <BlockStack gap="300">
                            <InlineStack gap="200" blockAlign="center">
                                <Badge>{broadcast.status}</Badge>
                                <Text as="span" tone="subdued">
                                    Sending to: {audienceLabel(broadcast)}
                                </Text>
                            </InlineStack>

                            <Text as="p">{broadcast.body}</Text>

                            <Text as="p" tone="subdued">
                                Channels: {broadcast.channels.join(', ')} ·
                                Variables available:{' '}
                                {broadcast.template_vars_available.join(', ')}
                            </Text>

                            {broadcast.stats?.recipients_total !==
                                undefined && (
                                <Text as="p" tone="subdued">
                                    {broadcast.stats.recipients_total}{' '}
                                    recipient(s) at send time
                                </Text>
                            )}

                            {broadcast.scheduled_at && (
                                <Text as="p" tone="subdued">
                                    Scheduled for{' '}
                                    {new Date(
                                        broadcast.scheduled_at,
                                    ).toLocaleString()}
                                </Text>
                            )}

                            {broadcast.sent_at && (
                                <Text as="p" tone="subdued">
                                    Sent{' '}
                                    {new Date(
                                        broadcast.sent_at,
                                    ).toLocaleString()}
                                    {broadcast.approved_by_name &&
                                        ` · approved by ${broadcast.approved_by_name}`}
                                </Text>
                            )}

                            <InlineStack gap="200">
                                <Button
                                    onClick={() =>
                                        router.post(
                                            `/admin/broadcasts/${broadcast.id}/send-test`,
                                        )
                                    }
                                >
                                    Send test to my email
                                </Button>
                                {canSend && (
                                    <Button
                                        variant="primary"
                                        onClick={() =>
                                            router.post(
                                                `/admin/broadcasts/${broadcast.id}/send`,
                                            )
                                        }
                                    >
                                        Send now
                                    </Button>
                                )}
                            </InlineStack>
                        </BlockStack>
                    </Card>

                    <Card>
                        <BlockStack gap="300">
                            <Text as="h2" variant="headingMd">
                                Delivery report
                            </Text>
                            {deliveryRows.length > 0 ? (
                                <DataTable
                                    columnContentTypes={[
                                        'text',
                                        'text',
                                        'numeric',
                                    ]}
                                    headings={['Channel', 'Outcome', 'Count']}
                                    rows={deliveryRows}
                                />
                            ) : (
                                <Text as="p" tone="subdued">
                                    Nothing sent yet.
                                </Text>
                            )}
                            <Text as="p" tone="subdued">
                                Counts reflect attempted delivery
                                (sent/failed/skipped). Read receipts and email
                                opens aren&apos;t tracked — no delivery-receipt
                                or open-pixel infrastructure exists yet.
                            </Text>
                        </BlockStack>
                    </Card>
                </BlockStack>
            </Page>
        </>
    );
}

BroadcastsShow.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
