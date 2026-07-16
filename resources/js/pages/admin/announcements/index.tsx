import { Head, router } from '@inertiajs/react';
import {
    Badge,
    BlockStack,
    Box,
    Button,
    Card,
    Checkbox,
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

import PolarisDateField from '@/components/PolarisDateField';
import PolarisTimeField from '@/components/PolarisTimeField';
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

function pad(n: number): string {
    return String(n).padStart(2, '0');
}

function splitDateTime(value: string | null): { date: string; time: string } {
    if (!value) {
        return { date: '', time: '' };
    }

    // The backend always sends UTC (app.timezone is UTC, and Carbon's JSON
    // serialization appends "Z"). `new Date(...)` parses that instant
    // correctly, and its getFullYear/getMonth/... getters return it
    // converted into *this browser's* local time — exactly what the admin
    // should see reflected back in the picker.
    const local = new Date(value);

    const date = `${local.getFullYear()}-${pad(local.getMonth() + 1)}-${pad(local.getDate())}`;
    const time = `${pad(local.getHours())}:${pad(local.getMinutes())}`;

    return { date, time };
}

function combineDateTime(date: string, time: string): string | null {
    if (!date) {
        return null;
    }

    if (!time) {
        // Date-only (no time of day picked): send the plain date, same as
        // every other date-only field in this admin (Promotions, Audit Log).
        // Converting a bare date through a timezone would risk shifting it
        // to the wrong calendar day for admins outside UTC.
        return date;
    }

    // With a time picked, the admin means "this clock time, in my own
    // timezone" — the Date constructor's (y, m, d, h, min) form interprets
    // those numbers as browser-local, so toISOString() gives the correct
    // UTC instant to send. Without this conversion, the naive "date+time"
    // string would be silently reinterpreted as UTC by the backend
    // (app.timezone=UTC), shifting it by the admin's UTC offset.
    const [year, month, day] = date.split('-').map(Number);
    const [hours, minutes] = time.split(':').map(Number);
    const local = new Date(year, month - 1, day, hours, minutes);

    return local.toISOString();
}

export default function AnnouncementsIndex({
    announcements,
}: {
    announcements: Announcement[];
}) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [startsAtDate, setStartsAtDate] = useState('');
    const [startsAtTime, setStartsAtTime] = useState('');
    const [endsAtDate, setEndsAtDate] = useState('');
    const [endsAtTime, setEndsAtTime] = useState('');
    const [dismissible, setDismissible] = useState(true);
    const [queryValue, setQueryValue] = useState('');
    const { mode, setMode } = useSetIndexFiltersMode(IndexFiltersMode.Default);

    const resetForm = () => {
        setEditingId(null);
        setTitle('');
        setBody('');
        setStartsAtDate('');
        setStartsAtTime('');
        setEndsAtDate('');
        setEndsAtTime('');
        setDismissible(true);
    };

    const edit = (announcement: Announcement) => {
        setEditingId(announcement.id);
        setTitle(announcement.title);
        setBody(announcement.body);
        const starts = splitDateTime(announcement.starts_at);
        const ends = splitDateTime(announcement.ends_at);
        setStartsAtDate(starts.date);
        setStartsAtTime(starts.time);
        setEndsAtDate(ends.date);
        setEndsAtTime(ends.time);
        setDismissible(announcement.dismissible);
    };

    const save = () => {
        const payload = {
            title,
            body,
            starts_at: combineDateTime(startsAtDate, startsAtTime),
            ends_at: combineDateTime(endsAtDate, endsAtTime),
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

    const filteredAnnouncements = useMemo(() => {
        if (!queryValue) {
            return announcements;
        }

        const q = queryValue.toLowerCase();

        return announcements.filter((a) => a.title.toLowerCase().includes(q));
    }, [announcements, queryValue]);

    const rowMarkup = filteredAnnouncements.map((announcement, index) => (
        <IndexTable.Row
            id={String(announcement.id)}
            key={announcement.id}
            position={index}
        >
            <IndexTable.Cell>
                <Text as="span" fontWeight="semibold">
                    {announcement.title}
                </Text>
            </IndexTable.Cell>
            <IndexTable.Cell>
                <Badge tone={announcement.is_active ? 'success' : undefined}>
                    {announcement.is_active ? 'Active' : 'Inactive'}
                </Badge>
            </IndexTable.Cell>
            <IndexTable.Cell>
                {announcement.starts_at
                    ? new Date(announcement.starts_at).toLocaleString()
                    : '—'}
            </IndexTable.Cell>
            <IndexTable.Cell>
                {announcement.ends_at
                    ? new Date(announcement.ends_at).toLocaleString()
                    : '—'}
            </IndexTable.Cell>
            <IndexTable.Cell>
                <InlineStack gap="200">
                    <Button onClick={() => edit(announcement)}>Edit</Button>
                    <Button
                        tone="critical"
                        onClick={() => destroy(announcement)}
                    >
                        Delete
                    </Button>
                </InlineStack>
            </IndexTable.Cell>
        </IndexTable.Row>
    ));

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
                            <Text as="p" tone="subdued" variant="bodySm">
                                Times below are in your own local timezone —
                                converted automatically for storage and shown
                                back to every admin in theirs.
                            </Text>
                            <InlineStack gap="300" wrap blockAlign="end">
                                <PolarisDateField
                                    label="Starts (date)"
                                    value={startsAtDate}
                                    onChange={setStartsAtDate}
                                />
                                <PolarisTimeField
                                    label="Starts (time, optional)"
                                    value={startsAtTime}
                                    onChange={setStartsAtTime}
                                    disabled={!startsAtDate}
                                />
                                <PolarisDateField
                                    label="Ends (date)"
                                    value={endsAtDate}
                                    onChange={setEndsAtDate}
                                />
                                <PolarisTimeField
                                    label="Ends (time, optional)"
                                    value={endsAtTime}
                                    onChange={setEndsAtTime}
                                    disabled={!endsAtDate}
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

                    <Card padding="0">
                        <IndexFilters
                            queryValue={queryValue}
                            queryPlaceholder="Search by title"
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
                                singular: 'announcement',
                                plural: 'announcements',
                            }}
                            itemCount={filteredAnnouncements.length}
                            selectable={false}
                            headings={[
                                { title: 'Title' },
                                { title: 'Status' },
                                { title: 'Starts' },
                                { title: 'Ends' },
                                { title: '' },
                            ]}
                            emptyState={
                                <Box padding="400">
                                    <Text
                                        as="p"
                                        tone="subdued"
                                        alignment="center"
                                    >
                                        No announcements match this search.
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

AnnouncementsIndex.layout = (page: ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
