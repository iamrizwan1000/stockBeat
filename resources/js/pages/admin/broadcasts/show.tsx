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
    Tooltip,
} from '@shopify/polaris';
import type { ReactNode } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type DeliveryCounts = Record<string, Record<string, number>>;

type OpenStats = {
    sent: number;
    opened: number;
    rate: number | null;
};

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
    approved_at: string | null;
    created_at: string | null;
    template_vars_available: string[];
};

type Props = {
    broadcast: BroadcastDetail;
    delivery_counts: DeliveryCounts;
    open_stats: OpenStats;
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
    open_stats: openStats,
}: Props) {
    const { props } = usePage<{
        flash: { status: string | null };
        auth: { user: { role: string } | null };
    }>();
    const isSuperadmin = props.auth?.user?.role === 'superadmin';
    const canSend =
        broadcast.status === 'draft' || broadcast.status === 'scheduled';
    const needsApproval =
        broadcast.audience_type === 'all' && !broadcast.approved_at;

    const deliveryRows = Object.entries(deliveryCounts).flatMap(
        ([channel, statuses]) =>
            Object.entries(statuses).map(([status, count]) => [
                channel,
                status,
                String(count),
            ]),
    );

    const handleApproveAndSend = () => {
        router.post(
            `/admin/broadcasts/${broadcast.id}/approve`,
            {},
            {
                onSuccess: () => {
                    router.post(`/admin/broadcasts/${broadcast.id}/send`);
                },
            },
        );
    };

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
                                {needsApproval && (
                                    <Badge tone="attention">
                                        Awaiting approval
                                    </Badge>
                                )}
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

                            {needsApproval && (
                                <Text as="p" tone="subdued">
                                    This broadcast targets all users, so a
                                    superadmin must approve it before it can
                                    be sent.
                                    {broadcast.approved_by_name &&
                                        ` Approved by ${broadcast.approved_by_name}.`}
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
                                {canSend && needsApproval && isSuperadmin && (
                                    <Button
                                        variant="primary"
                                        onClick={handleApproveAndSend}
                                    >
                                        Approve &amp; send
                                    </Button>
                                )}
                                {canSend && needsApproval && !isSuperadmin && (
                                    <Tooltip content="This broadcast targets all users and needs superadmin approval before it can be sent.">
                                        <Button variant="primary" disabled>
                                            Send now
                                        </Button>
                                    </Tooltip>
                                )}
                                {canSend && !needsApproval && (
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
                            {openStats.rate !== null && (
                                <Text as="p" fontWeight="semibold">
                                    {openStats.rate}% opened (
                                    {openStats.opened} of {openStats.sent})
                                </Text>
                            )}
                            <Text as="p" tone="subdued">
                                Counts reflect attempted delivery
                                (sent/failed/skipped). Email opens are tracked
                                via a real tracking pixel. Push/banner
                                &quot;opened&quot; is a best-effort proxy —
                                the recipient marking the linked in-app
                                notification as read — not a literal push-tap
                                event.
                            </Text>
                        </BlockStack>
                    </Card>
                </BlockStack>
            </Page>
        </>
    );
}

BroadcastsShow.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
