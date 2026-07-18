import { Head, router } from '@inertiajs/react';
import {
    BlockStack,
    Box,
    Button,
    Card,
    Checkbox,
    IndexTable,
    InlineStack,
    Page,
    RangeSlider,
    Text,
    TextField,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type FeatureFlag = {
    id: number;
    key: string;
    name: string;
    description: string | null;
    enabled: boolean;
    rollout_percentage: number;
    enabled_for_team_ids: number[];
};

function parseTeamIds(value: string): number[] {
    return value
        .split(',')
        .map((part) => part.trim())
        .filter((part) => part !== '')
        .map((part) => Number(part))
        .filter((n) => Number.isInteger(n));
}

export default function FeatureFlagsIndex({ flags }: { flags: FeatureFlag[] }) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [key, setKey] = useState('');
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [enabled, setEnabled] = useState(false);
    const [rolloutPercentage, setRolloutPercentage] = useState(0);
    const [teamIdsInput, setTeamIdsInput] = useState('');

    const resetForm = () => {
        setEditingId(null);
        setKey('');
        setName('');
        setDescription('');
        setEnabled(false);
        setRolloutPercentage(0);
        setTeamIdsInput('');
    };

    const edit = (flag: FeatureFlag) => {
        setEditingId(flag.id);
        setKey(flag.key);
        setName(flag.name);
        setDescription(flag.description ?? '');
        setEnabled(flag.enabled);
        setRolloutPercentage(flag.rollout_percentage);
        setTeamIdsInput(flag.enabled_for_team_ids.join(', '));
    };

    const save = () => {
        const payload = {
            name,
            description: description || null,
            enabled,
            rollout_percentage: rolloutPercentage,
            enabled_for_team_ids: parseTeamIds(teamIdsInput),
        };

        if (editingId) {
            router.put(`/admin/feature-flags/${editingId}`, payload, {
                preserveScroll: true,
                onSuccess: resetForm,
            });
        } else {
            router.post(
                '/admin/feature-flags',
                { ...payload, key },
                { preserveScroll: true, onSuccess: resetForm },
            );
        }
    };

    const destroy = (flag: FeatureFlag) => {
        if (confirm(`Delete feature flag "${flag.name}" (${flag.key})?`)) {
            router.delete(`/admin/feature-flags/${flag.id}`, {
                preserveScroll: true,
            });
        }
    };

    const toggleEnabled = (flag: FeatureFlag) => {
        router.put(
            `/admin/feature-flags/${flag.id}`,
            {
                name: flag.name,
                description: flag.description,
                enabled: !flag.enabled,
                rollout_percentage: flag.rollout_percentage,
                enabled_for_team_ids: flag.enabled_for_team_ids,
            },
            { preserveScroll: true },
        );
    };

    const rowMarkup = flags.map((flag, index) => (
        <IndexTable.Row id={String(flag.id)} key={flag.id} position={index}>
            <IndexTable.Cell>
                <Text as="span" fontWeight="semibold">
                    {flag.key}
                </Text>
            </IndexTable.Cell>
            <IndexTable.Cell>{flag.name}</IndexTable.Cell>
            <IndexTable.Cell>
                <Button
                    size="slim"
                    tone={flag.enabled ? 'success' : undefined}
                    onClick={() => toggleEnabled(flag)}
                    accessibilityLabel={`Toggle ${flag.key}`}
                >
                    {flag.enabled ? 'Enabled' : 'Disabled'}
                </Button>
            </IndexTable.Cell>
            <IndexTable.Cell>{flag.rollout_percentage}%</IndexTable.Cell>
            <IndexTable.Cell>
                {flag.enabled_for_team_ids.length > 0
                    ? `${flag.enabled_for_team_ids.length} team(s)`
                    : '—'}
            </IndexTable.Cell>
            <IndexTable.Cell>
                <InlineStack gap="200">
                    <Button onClick={() => edit(flag)}>Edit</Button>
                    <Button tone="critical" onClick={() => destroy(flag)}>
                        Delete
                    </Button>
                </InlineStack>
            </IndexTable.Cell>
        </IndexTable.Row>
    ));

    return (
        <>
            <Head title="Feature Flags" />
            <Page
                title="Feature Flags"
                subtitle="Percentage-based rollout, evaluated live for every team's next /me call"
                fullWidth
            >
                <BlockStack gap="400">
                    <Card>
                        <BlockStack gap="300">
                            <Text as="h2" variant="headingMd">
                                {editingId
                                    ? 'Edit feature flag'
                                    : 'New feature flag'}
                            </Text>
                            <TextField
                                label="Key"
                                value={key}
                                onChange={setKey}
                                autoComplete="off"
                                disabled={editingId !== null}
                                helpText={
                                    editingId
                                        ? 'The key cannot be changed after creation.'
                                        : 'Lowercase letters, digits, underscores only (e.g. new_rules_ui).'
                                }
                            />
                            <TextField
                                label="Name"
                                value={name}
                                onChange={setName}
                                autoComplete="off"
                            />
                            <TextField
                                label="Description"
                                value={description}
                                onChange={setDescription}
                                autoComplete="off"
                                multiline={2}
                            />
                            <Checkbox
                                label="Enabled (master on/off)"
                                checked={enabled}
                                onChange={setEnabled}
                                helpText="When off, the flag always evaluates to false regardless of rollout percentage or allow-list."
                            />
                            <RangeSlider
                                label={`Rollout percentage: ${rolloutPercentage}%`}
                                value={rolloutPercentage}
                                onChange={(value) =>
                                    setRolloutPercentage(
                                        Array.isArray(value) ? value[0] : value,
                                    )
                                }
                                min={0}
                                max={100}
                                output
                            />
                            <TextField
                                label="Always-enabled team IDs (comma-separated)"
                                value={teamIdsInput}
                                onChange={setTeamIdsInput}
                                autoComplete="off"
                                helpText="Explicit allow-list that bypasses the rollout percentage — useful for internal testing/dogfooding."
                            />
                            <InlineStack gap="200">
                                <Button
                                    variant="primary"
                                    onClick={save}
                                    disabled={!name || (!editingId && !key)}
                                >
                                    {editingId
                                        ? 'Save changes'
                                        : 'Create feature flag'}
                                </Button>
                                {editingId && (
                                    <Button onClick={resetForm}>Cancel</Button>
                                )}
                            </InlineStack>
                        </BlockStack>
                    </Card>

                    <Card padding="0">
                        <IndexTable
                            resourceName={{
                                singular: 'feature flag',
                                plural: 'feature flags',
                            }}
                            itemCount={flags.length}
                            selectable={false}
                            headings={[
                                { title: 'Key' },
                                { title: 'Name' },
                                { title: 'Status' },
                                { title: 'Rollout' },
                                { title: 'Allow-list' },
                                { title: '' },
                            ]}
                            emptyState={
                                <Box padding="400">
                                    <Text
                                        as="p"
                                        tone="subdued"
                                        alignment="center"
                                    >
                                        No feature flags yet.
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

FeatureFlagsIndex.layout = (page: ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
