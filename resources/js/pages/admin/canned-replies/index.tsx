import { Head, router } from '@inertiajs/react';
import {
    BlockStack,
    Button,
    Card,
    DataTable,
    InlineStack,
    Page,
    Text,
    TextField,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type CannedReply = { id: number; title: string; body: string };

export default function CannedRepliesIndex({
    replies,
}: {
    replies: CannedReply[];
}) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');

    const resetForm = () => {
        setEditingId(null);
        setTitle('');
        setBody('');
    };

    const edit = (reply: CannedReply) => {
        setEditingId(reply.id);
        setTitle(reply.title);
        setBody(reply.body);
    };

    const save = () => {
        if (editingId) {
            router.put(
                `/admin/canned-replies/${editingId}`,
                { title, body },
                { onSuccess: resetForm },
            );
        } else {
            router.post(
                '/admin/canned-replies',
                { title, body },
                { onSuccess: resetForm },
            );
        }
    };

    const destroy = (reply: CannedReply) => {
        if (confirm(`Delete canned reply "${reply.title}"?`)) {
            router.delete(`/admin/canned-replies/${reply.id}`);
        }
    };

    const rows = replies.map((reply) => [
        reply.title,
        reply.body.slice(0, 80),
        <InlineStack key={reply.id} gap="200">
            <Button onClick={() => edit(reply)}>Edit</Button>
            <Button tone="critical" onClick={() => destroy(reply)}>
                Delete
            </Button>
        </InlineStack>,
    ]);

    return (
        <>
            <Head title="Canned Replies" />
            <Page
                title="Canned Replies"
                subtitle="Reusable answers for the support inbox"
                fullWidth
            >
                <BlockStack gap="400">
                    <Card>
                        <BlockStack gap="300">
                            <Text as="h2" variant="headingMd">
                                {editingId ? 'Edit reply' : 'New reply'}
                            </Text>
                            <TextField
                                label="Title"
                                value={title}
                                onChange={setTitle}
                                autoComplete="off"
                            />
                            <TextField
                                label="Body"
                                value={body}
                                onChange={setBody}
                                autoComplete="off"
                                multiline={3}
                            />
                            <InlineStack gap="200">
                                <Button
                                    variant="primary"
                                    onClick={save}
                                    disabled={!title || !body}
                                >
                                    {editingId
                                        ? 'Save changes'
                                        : 'Create reply'}
                                </Button>
                                {editingId && (
                                    <Button onClick={resetForm}>Cancel</Button>
                                )}
                            </InlineStack>
                        </BlockStack>
                    </Card>

                    <Card>
                        {rows.length > 0 ? (
                            <DataTable
                                columnContentTypes={['text', 'text', 'text']}
                                headings={['Title', 'Body', '']}
                                rows={rows}
                            />
                        ) : (
                            <Text as="p" tone="subdued">
                                No canned replies yet.
                            </Text>
                        )}
                    </Card>
                </BlockStack>
            </Page>
        </>
    );
}

CannedRepliesIndex.layout = (page: ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
