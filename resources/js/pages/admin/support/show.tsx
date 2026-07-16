import { Head, router, usePage } from '@inertiajs/react';
import {
    Badge,
    BlockStack,
    Button,
    Card,
    InlineGrid,
    InlineStack,
    Page,
    Select,
    Text,
    TextField,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type Message = {
    id: number;
    direction: string;
    admin_name: string | null;
    body: string;
    delivered_via: Record<string, unknown> | null;
    created_at: string | null;
};

type CannedReply = { id: number; title: string; body: string };

type CustomerDetail = {
    user: { name: string; email: string; business_name: string | null };
    entitlements: { plan: string } | null;
    subscription: { status: string } | null;
    store_connections: Array<{
        platform: string;
        name: string;
        status: string;
    }>;
    devices: Array<{ platform: string; last_seen_at: string | null }>;
};

type Thread = {
    id: number;
    status: string;
    priority: string;
    assigned_admin_id: number | null;
    assigned_admin_name: string | null;
};

type Props = {
    thread: Thread;
    messages: Message[];
    customer: CustomerDetail;
    canned_replies: CannedReply[];
};

function MessageBubble({ message }: { message: Message }) {
    const isStaff = message.direction === 'staff';
    const isNote = message.direction === 'note';

    if (isNote) {
        return (
            <div
                className="support-note"
                style={{
                    background: '#fff4e5',
                    borderRadius: 8,
                    padding: '8px 12px',
                }}
            >
                <Text as="p" variant="bodySm" tone="subdued">
                    Internal note
                    {message.admin_name ? ` · ${message.admin_name}` : ''}
                </Text>
                <Text as="p" variant="bodySm">
                    {message.body}
                </Text>
            </div>
        );
    }

    return (
        <div
            style={{
                display: 'flex',
                justifyContent: isStaff ? 'flex-end' : 'flex-start',
            }}
        >
            <div
                style={{
                    maxWidth: '70%',
                    background: isStaff ? '#e3f1df' : '#f1f1f1',
                    borderRadius: 12,
                    padding: '8px 12px',
                }}
            >
                {isStaff && message.admin_name && (
                    <Text as="p" variant="bodySm" tone="subdued">
                        {message.admin_name}
                    </Text>
                )}
                <Text as="p">{message.body}</Text>
            </div>
        </div>
    );
}

export default function SupportShow({
    thread,
    messages,
    customer,
    canned_replies: cannedReplies,
}: Props) {
    const { props } = usePage<{
        flash: { status: string | null };
        auth: { user: { id: number } | null };
    }>();
    const currentAdminId = props.auth.user?.id;
    const [reply, setReply] = useState('');
    const [note, setNote] = useState('');
    const [cannedId, setCannedId] = useState('');

    const applyCanned = (value: string) => {
        setCannedId(value);
        const canned = cannedReplies.find((c) => String(c.id) === value);

        if (canned) {
            setReply(canned.body);
        }
    };

    const sendReply = () => {
        router.post(
            `/admin/support/${thread.id}/reply`,
            { body: reply },
            { onSuccess: () => setReply('') },
        );
    };

    const sendNote = () => {
        router.post(
            `/admin/support/${thread.id}/notes`,
            { body: note },
            { onSuccess: () => setNote('') },
        );
    };

    return (
        <>
            <Head
                title={`Support — ${customer.user.name || customer.user.email}`}
            />
            <Page
                title={customer.user.name || customer.user.email}
                backAction={{ url: '/admin/support' }}
            >
                <InlineGrid columns={{ xs: 1, md: 3 }} gap="400">
                    <div style={{ gridColumn: 'span 2' }}>
                        <BlockStack gap="400">
                            {props.flash?.status && (
                                <Card>
                                    <Text as="p">{props.flash.status}</Text>
                                </Card>
                            )}

                            <Card>
                                <InlineStack gap="200" blockAlign="center">
                                    <Badge>
                                        {thread.status.replace('_', ' ')}
                                    </Badge>
                                    {thread.priority === 'high' && (
                                        <Badge tone="critical">
                                            High priority
                                        </Badge>
                                    )}
                                    <Text as="span" tone="subdued">
                                        {thread.assigned_admin_name
                                            ? `Assigned to ${thread.assigned_admin_name}`
                                            : 'Unassigned'}
                                    </Text>
                                    {thread.assigned_admin_id !==
                                        currentAdminId && (
                                        <Button
                                            onClick={() =>
                                                router.post(
                                                    `/admin/support/${thread.id}/assign`,
                                                    {
                                                        assigned_admin_id:
                                                            currentAdminId,
                                                    },
                                                )
                                            }
                                        >
                                            Assign to me
                                        </Button>
                                    )}
                                    {thread.assigned_admin_id !== null && (
                                        <Button
                                            onClick={() =>
                                                router.post(
                                                    `/admin/support/${thread.id}/assign`,
                                                    { assigned_admin_id: null },
                                                )
                                            }
                                        >
                                            Unassign
                                        </Button>
                                    )}
                                    <Button
                                        onClick={() =>
                                            router.post(
                                                `/admin/support/${thread.id}/resolve`,
                                            )
                                        }
                                    >
                                        Mark resolved
                                    </Button>
                                </InlineStack>
                            </Card>

                            <Card>
                                <BlockStack gap="300">
                                    {messages.map((message) => (
                                        <MessageBubble
                                            key={message.id}
                                            message={message}
                                        />
                                    ))}
                                    {messages.length === 0 && (
                                        <Text as="p" tone="subdued">
                                            No messages yet.
                                        </Text>
                                    )}
                                </BlockStack>
                            </Card>

                            <Card>
                                <BlockStack gap="300">
                                    <Text as="h2" variant="headingSm">
                                        Reply
                                    </Text>
                                    {cannedReplies.length > 0 && (
                                        <Select
                                            label="Insert canned reply"
                                            labelHidden
                                            options={[
                                                {
                                                    label: 'Insert a canned reply…',
                                                    value: '',
                                                },
                                                ...cannedReplies.map((c) => ({
                                                    label: c.title,
                                                    value: String(c.id),
                                                })),
                                            ]}
                                            value={cannedId}
                                            onChange={applyCanned}
                                        />
                                    )}
                                    <TextField
                                        label="Reply"
                                        labelHidden
                                        value={reply}
                                        onChange={setReply}
                                        multiline={3}
                                        autoComplete="off"
                                    />
                                    <InlineStack>
                                        <Button
                                            variant="primary"
                                            onClick={sendReply}
                                            disabled={!reply}
                                        >
                                            Send reply
                                        </Button>
                                    </InlineStack>
                                </BlockStack>
                            </Card>

                            <Card>
                                <BlockStack gap="300">
                                    <Text as="h2" variant="headingSm">
                                        Internal note (not visible to user)
                                    </Text>
                                    <TextField
                                        label="Note"
                                        labelHidden
                                        value={note}
                                        onChange={setNote}
                                        multiline={2}
                                        autoComplete="off"
                                    />
                                    <InlineStack>
                                        <Button
                                            onClick={sendNote}
                                            disabled={!note}
                                        >
                                            Add note
                                        </Button>
                                    </InlineStack>
                                </BlockStack>
                            </Card>
                        </BlockStack>
                    </div>

                    <BlockStack gap="400">
                        <Card>
                            <BlockStack gap="200">
                                <Text as="h2" variant="headingSm">
                                    Customer
                                </Text>
                                <Text as="p">{customer.user.email}</Text>
                                {customer.user.business_name && (
                                    <Text as="p" tone="subdued">
                                        {customer.user.business_name}
                                    </Text>
                                )}
                                <Text as="p" tone="subdued">
                                    Plan:{' '}
                                    {customer.entitlements?.plan ?? 'free'}
                                    {customer.subscription
                                        ? ` (${customer.subscription.status})`
                                        : ''}
                                </Text>
                            </BlockStack>
                        </Card>

                        <Card>
                            <BlockStack gap="200">
                                <Text as="h2" variant="headingSm">
                                    Connected stores
                                </Text>
                                {customer.store_connections.length > 0 ? (
                                    customer.store_connections.map(
                                        (connection, i) => (
                                            <Text as="p" key={i} tone="subdued">
                                                {connection.name} ·{' '}
                                                {connection.platform} ·{' '}
                                                {connection.status}
                                            </Text>
                                        ),
                                    )
                                ) : (
                                    <Text as="p" tone="subdued">
                                        None connected.
                                    </Text>
                                )}
                            </BlockStack>
                        </Card>

                        <Card>
                            <BlockStack gap="200">
                                <Text as="h2" variant="headingSm">
                                    Devices
                                </Text>
                                {customer.devices.length > 0 ? (
                                    customer.devices.map((device, i) => (
                                        <Text as="p" key={i} tone="subdued">
                                            {device.platform} · last seen{' '}
                                            {device.last_seen_at
                                                ? new Date(
                                                      device.last_seen_at,
                                                  ).toLocaleDateString()
                                                : 'never'}
                                        </Text>
                                    ))
                                ) : (
                                    <Text as="p" tone="subdued">
                                        No devices registered.
                                    </Text>
                                )}
                            </BlockStack>
                        </Card>
                    </BlockStack>
                </InlineGrid>
            </Page>
        </>
    );
}

SupportShow.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
