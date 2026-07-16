import { Head, router } from '@inertiajs/react';
import {
    BlockStack,
    Box,
    Button,
    Card,
    IndexFilters,
    IndexFiltersMode,
    IndexTable,
    InlineStack,
    Page,
    Text,
    TextField,
    useSetIndexFiltersMode,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';

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
    const [queryValue, setQueryValue] = useState('');
    const { mode, setMode } = useSetIndexFiltersMode(IndexFiltersMode.Default);

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

    const filteredReplies = useMemo(() => {
        if (!queryValue) {
            return replies;
        }

        const q = queryValue.toLowerCase();

        return replies.filter(
            (reply) =>
                reply.title.toLowerCase().includes(q) ||
                reply.body.toLowerCase().includes(q),
        );
    }, [replies, queryValue]);

    const rowMarkup = filteredReplies.map((reply, index) => (
        <IndexTable.Row id={String(reply.id)} key={reply.id} position={index}>
            <IndexTable.Cell>
                <Text as="span" fontWeight="semibold">
                    {reply.title}
                </Text>
            </IndexTable.Cell>
            <IndexTable.Cell>{reply.body.slice(0, 80)}</IndexTable.Cell>
            <IndexTable.Cell>
                <InlineStack gap="200">
                    <Button onClick={() => edit(reply)}>Edit</Button>
                    <Button tone="critical" onClick={() => destroy(reply)}>
                        Delete
                    </Button>
                </InlineStack>
            </IndexTable.Cell>
        </IndexTable.Row>
    ));

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

                    <Card padding="0">
                        <IndexFilters
                            queryValue={queryValue}
                            queryPlaceholder="Search by title or body"
                            onQueryChange={setQueryValue}
                            onQueryClear={() => setQueryValue('')}
                            cancelAction={{
                                onAction: () =>
                                    setMode(IndexFiltersMode.Default),
                            }}
                            mode={mode}
                            setMode={setMode}
                            tabs={[]}
                            selected={0}
                            onSelect={() => {}}
                            canCreateNewView={false}
                            filters={[]}
                            appliedFilters={[]}
                            onClearAll={() => setQueryValue('')}
                        />
                        <IndexTable
                            resourceName={{
                                singular: 'reply',
                                plural: 'replies',
                            }}
                            itemCount={filteredReplies.length}
                            selectable={false}
                            headings={[
                                { title: 'Title' },
                                { title: 'Body' },
                                { title: '' },
                            ]}
                            emptyState={
                                <Box padding="400">
                                    <Text
                                        as="p"
                                        tone="subdued"
                                        alignment="center"
                                    >
                                        No canned replies match this search.
                                    </Text>
                                </Box>
                            }
                        >
                            {rowMarkup}
                        </IndexTable>
                    </Card>
                </BlockStack>
            </Page>
        </>
    );
}

CannedRepliesIndex.layout = (page: ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
