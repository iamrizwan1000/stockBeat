import { Head, router } from '@inertiajs/react';
import {
    BlockStack,
    Button,
    Card,
    Checkbox,
    InlineStack,
    Page,
    Select,
    Text,
    TextField,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type Segment = { id: number; name: string };

const AUDIENCE_OPTIONS = [
    { label: 'A segment', value: 'segment' },
    { label: 'A single user', value: 'user' },
    { label: 'All users', value: 'all' },
];

export default function BroadcastsCreate({
    segments,
}: {
    segments: Segment[];
}) {
    const [audienceType, setAudienceType] = useState('segment');
    const [segmentId, setSegmentId] = useState(
        segments[0] ? String(segments[0].id) : '',
    );
    const [userId, setUserId] = useState('');
    const [push, setPush] = useState(true);
    const [email, setEmail] = useState(true);
    const [banner, setBanner] = useState(false);
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [scheduledAt, setScheduledAt] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    const submit = () => {
        const channels = [
            push && 'push',
            email && 'email',
            banner && 'banner',
        ].filter(Boolean);

        router.post(
            '/admin/broadcasts',
            {
                audience_type: audienceType,
                segment_id: audienceType === 'segment' ? segmentId : null,
                user_id: audienceType === 'user' ? userId : null,
                channels,
                title,
                body,
                scheduled_at: scheduledAt || null,
            },
            { onError: setErrors },
        );
    };

    return (
        <>
            <Head title="New broadcast" />
            <Page
                title="New broadcast"
                backAction={{ url: '/admin/broadcasts' }}
            >
                <Card>
                    <BlockStack gap="400">
                        <Select
                            label="Send to"
                            options={AUDIENCE_OPTIONS}
                            value={audienceType}
                            onChange={setAudienceType}
                            error={errors.audience_type}
                        />

                        {audienceType === 'segment' && (
                            <Select
                                label="Segment"
                                options={segments.map((s) => ({
                                    label: s.name,
                                    value: String(s.id),
                                }))}
                                value={segmentId}
                                onChange={setSegmentId}
                                error={errors.segment_id}
                                helpText={
                                    segments.length === 0
                                        ? 'No segments saved yet — create one under Segments first.'
                                        : undefined
                                }
                            />
                        )}

                        {audienceType === 'user' && (
                            <TextField
                                label="User ID"
                                type="number"
                                value={userId}
                                onChange={setUserId}
                                autoComplete="off"
                                helpText="Find the ID on the customer's detail page."
                                error={errors.user_id}
                            />
                        )}

                        {audienceType === 'all' && (
                            <Text as="p" tone="subdued">
                                Sends to every user. Only a superadmin can send
                                or schedule this.
                            </Text>
                        )}

                        <BlockStack gap="200">
                            <Text as="h3" variant="headingSm">
                                Channels
                            </Text>
                            <InlineStack gap="400">
                                <Checkbox
                                    label="Push"
                                    checked={push}
                                    onChange={setPush}
                                />
                                <Checkbox
                                    label="Email"
                                    checked={email}
                                    onChange={setEmail}
                                />
                                <Checkbox
                                    label="In-app banner"
                                    checked={banner}
                                    onChange={setBanner}
                                />
                            </InlineStack>
                            {errors.channels && (
                                <Text as="p" tone="critical">
                                    {errors.channels}
                                </Text>
                            )}
                        </BlockStack>

                        <TextField
                            label="Title"
                            value={title}
                            onChange={setTitle}
                            autoComplete="off"
                            error={errors.title}
                        />
                        <TextField
                            label="Body"
                            value={body}
                            onChange={setBody}
                            autoComplete="off"
                            multiline={4}
                            helpText="Variables: {first_name}, {plan}, {trial_days_left}"
                            error={errors.body}
                        />

                        <TextField
                            label="Schedule for later (optional)"
                            type="datetime-local"
                            value={scheduledAt}
                            onChange={setScheduledAt}
                            autoComplete="off"
                            helpText="Leave blank to save as a draft you send manually."
                            error={errors.scheduled_at}
                        />

                        <InlineStack gap="200">
                            <Button
                                variant="primary"
                                onClick={submit}
                                disabled={!title || !body}
                            >
                                Save
                            </Button>
                        </InlineStack>
                    </BlockStack>
                </Card>
            </Page>
        </>
    );
}

BroadcastsCreate.layout = (page: ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
