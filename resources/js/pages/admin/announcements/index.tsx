import { Head, router } from '@inertiajs/react';
import {
    Badge,
    BlockStack,
    Button,
    Card,
    Checkbox,
    DataTable,
    InlineStack,
    Page,
    Text,
    TextField,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type Announcement = {
    id: number;
    title: string;
    body: string;
    starts_at: string | null;
    ends_at: string | null;
    dismissible: boolean;
    is_active: boolean;
};

function toLocalInput(value: string | null): string {
    if (!value) {
        return '';
    }

    return value.slice(0, 16);
}

export default function AnnouncementsIndex({
    announcements,
}: {
    announcements: Announcement[];
}) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [startsAt, setStartsAt] = useState('');
    const [endsAt, setEndsAt] = useState('');
    const [dismissible, setDismissible] = useState(true);

    const resetForm = () => {
        setEditingId(null);
        setTitle('');
        setBody('');
        setStartsAt('');
        setEndsAt('');
        setDismissible(true);
    };

    const edit = (announcement: Announcement) => {
        setEditingId(announcement.id);
        setTitle(announcement.title);
        setBody(announcement.body);
        setStartsAt(toLocalInput(announcement.starts_at));
        setEndsAt(toLocalInput(announcement.ends_at));
        setDismissible(announcement.dismissible);
    };

    const save = () => {
        const payload = {
            title,
            body,
            starts_at: startsAt || null,
            ends_at: endsAt || null,
            dismissible,
        };

        if (editingId) {
            router.put(`/admin/announcements/${editingId}`, payload, {
                onSuccess: resetForm,
            });
        } else {
            router.post('/admin/announcements', payload, {
                onSuccess: resetForm,
            });
        }
    };

    const destroy = (announcement: Announcement) => {
        if (confirm(`Delete announcement "${announcement.title}"?`)) {
            router.delete(`/admin/announcements/${announcement.id}`);
        }
    };

    const rows = announcements.map((announcement) => [
        announcement.title,
        <Badge
            key={`${announcement.id}-status`}
            tone={announcement.is_active ? 'success' : undefined}
        >
            {announcement.is_active ? 'Active' : 'Inactive'}
        </Badge>,
        announcement.starts_at
            ? new Date(announcement.starts_at).toLocaleString()
            : '—',
        announcement.ends_at
            ? new Date(announcement.ends_at).toLocaleString()
            : '—',
        <InlineStack key={announcement.id} gap="200">
            <Button onClick={() => edit(announcement)}>Edit</Button>
            <Button tone="critical" onClick={() => destroy(announcement)}>
                Delete
            </Button>
        </InlineStack>,
    ]);

    return (
        <>
            <Head title="Announcements" />
            <Page
                title="Announcements"
                subtitle="In-app banners shown to the mobile app"
                fullWidth
            >
                <BlockStack gap="400">
                    <Card>
                        <BlockStack gap="300">
                            <Text as="h2" variant="headingMd">
                                {editingId
                                    ? 'Edit announcement'
                                    : 'New announcement'}
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
                            <InlineStack gap="300" wrap>
                                <TextField
                                    label="Starts at (optional)"
                                    type="datetime-local"
                                    value={startsAt}
                                    onChange={setStartsAt}
                                    autoComplete="off"
                                />
                                <TextField
                                    label="Ends at (optional)"
                                    type="datetime-local"
                                    value={endsAt}
                                    onChange={setEndsAt}
                                    autoComplete="off"
                                />
                            </InlineStack>
                            <Checkbox
                                label="Dismissible"
                                checked={dismissible}
                                onChange={setDismissible}
                            />
                            <InlineStack gap="200">
                                <Button
                                    variant="primary"
                                    onClick={save}
                                    disabled={!title || !body}
                                >
                                    {editingId
                                        ? 'Save changes'
                                        : 'Create announcement'}
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
                                columnContentTypes={[
                                    'text',
                                    'text',
                                    'text',
                                    'text',
                                    'text',
                                ]}
                                headings={[
                                    'Title',
                                    'Status',
                                    'Starts',
                                    'Ends',
                                    '',
                                ]}
                                rows={rows}
                            />
                        ) : (
                            <Text as="p" tone="subdued">
                                No announcements yet.
                            </Text>
                        )}
                    </Card>
                </BlockStack>
            </Page>
        </>
    );
}

AnnouncementsIndex.layout = (page: ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
