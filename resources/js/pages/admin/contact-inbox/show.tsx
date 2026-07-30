import { Head, router, usePage } from '@inertiajs/react';
import { Badge, BlockStack, Button, Card, Page, Text, TextField } from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type Message = {
    id: number;
    direction: string;
    admin_name: string | null;
    body: string;
    created_at: string | null;
};

type Thread = {
    id: number;
    name: string;
    email: string;
    subject: string | null;
    status: string;
};

function MessageBubble({ message }: { message: Message }) {
    const isStaff = message.direction === 'staff';

    return (
        <div style={{ display: 'flex', justifyContent: isStaff ? 'flex-end' : 'flex-start' }}>
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

export default function ContactInboxShow({ thread, messages }: { thread: Thread; messages: Message[] }) {
    const { props } = usePage<{ flash: { status: string | null } }>();
    const [reply, setReply] = useState('');

    const sendReply = () => {
        router.post(`/admin/contact-inbox/${thread.id}/reply`, { body: reply }, { onSuccess: () => setReply('') });
    };

    return (
        <>
            <Head title={`Contact — ${thread.name}`} />
            <Page title={thread.name} subtitle={thread.email} backAction={{ url: '/admin/contact-inbox' }}>
                <BlockStack gap="400">
                    {props.flash?.status && (
                        <Card>
                            <Text as="p" tone="success">
                                {props.flash.status}
                            </Text>
                        </Card>
                    )}

                    <Card>
                        <BlockStack gap="200">
                            <Badge>{thread.status}</Badge>
                            {thread.subject && (
                                <Text as="p">
                                    <b>Subject:</b> {thread.subject}
                                </Text>
                            )}
                        </BlockStack>
                    </Card>

                    <Card>
                        <BlockStack gap="300">
                            {messages.map((message) => (
                                <MessageBubble key={message.id} message={message} />
                            ))}
                        </BlockStack>
                    </Card>

                    <Card>
                        <BlockStack gap="300">
                            <TextField
                                label="Reply (sent to the guest's email)"
                                value={reply}
                                onChange={setReply}
                                multiline={4}
                                autoComplete="off"
                            />
                            <Button onClick={sendReply} disabled={reply.trim() === ''}>
                                Send reply
                            </Button>
                        </BlockStack>
                    </Card>
                </BlockStack>
            </Page>
        </>
    );
}

ContactInboxShow.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
