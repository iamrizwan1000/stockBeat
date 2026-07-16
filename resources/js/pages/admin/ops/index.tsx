import { Head, router, usePage } from '@inertiajs/react';
import {
    Badge,
    BlockStack,
    Button,
    Card,
    Checkbox,
    DataTable,
    InlineGrid,
    InlineStack,
    Page,
    Text,
    TextField,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type Health = {
    connections: {
        total: number;
        needs_reauth: number;
        disconnected: number;
        stale: number;
        never_synced: number;
    };
    failed_jobs: {
        total: number;
        recent: Array<{
            uuid: string;
            queue: string;
            failed_at: string;
            exception_summary: string;
        }>;
    };
    sms: {
        consumed_this_month: number;
        top_consumers: Array<{
            team_id: number;
            team_name: string;
            consumed: number;
        }>;
    };
    notification_volume_24h: Record<string, number>;
    abuse: {
        runaway_rule_teams: Array<{
            team_id: number;
            team_name: string;
            executions_last_hour: number;
        }>;
        threshold_per_hour: number;
        shared_fingerprint_teams: Array<{
            fingerprint: string;
            platform: string;
            teams: Array<{ team_id: number; team_name: string | null }>;
        }>;
        shared_signup_ip_teams: Array<{
            signup_ip: string;
            teams: Array<{
                team_id: number | null;
                team_name: string | null;
                user_email: string;
            }>;
        }>;
    };
};

type Config = {
    min_version: string | null;
    maintenance_mode: boolean;
    maintenance_banner: string | null;
};

function StatTile({
    label,
    value,
    tone,
}: {
    label: string;
    value: string | number;
    tone?: 'critical' | 'caution';
}) {
    return (
        <Card>
            <BlockStack gap="100">
                <Text as="p" tone="subdued" variant="bodySm">
                    {label}
                </Text>
                <Text as="p" variant="headingLg" tone={tone}>
                    {value}
                </Text>
            </BlockStack>
        </Card>
    );
}

export default function OpsIndex({
    health,
    config,
}: {
    health: Health;
    config: Config;
}) {
    const { props } = usePage<{ flash: { status: string | null } }>();
    const [minVersion, setMinVersion] = useState(config.min_version ?? '');
    const [maintenanceMode, setMaintenanceMode] = useState(
        config.maintenance_mode,
    );
    const [maintenanceBanner, setMaintenanceBanner] = useState(
        config.maintenance_banner ?? '',
    );

    const saveConfig = (key: string, value: string | boolean) => {
        router.put(
            '/admin/ops/config',
            { key, value },
            { preserveScroll: true },
        );
    };

    const notificationRows = Object.entries(health.notification_volume_24h).map(
        ([type, count]) => [type, String(count)],
    );
    const failedJobRows = health.failed_jobs.recent.map((job) => [
        job.queue,
        job.exception_summary,
        new Date(job.failed_at).toLocaleString(),
    ]);
    const smsConsumerRows = health.sms.top_consumers.map((row) => [
        row.team_name,
        String(row.consumed),
    ]);
    const runawayRows = health.abuse.runaway_rule_teams.map((row) => [
        row.team_name,
        String(row.executions_last_hour),
    ]);
    const sharedFingerprintRows = health.abuse.shared_fingerprint_teams.map(
        (row) => [
            row.platform,
            row.teams.map((t) => t.team_name ?? `#${t.team_id}`).join(', '),
        ],
    );
    const sharedSignupIpRows = health.abuse.shared_signup_ip_teams.map(
        (row) => [
            row.signup_ip,
            row.teams.map((t) => t.team_name ?? t.user_email).join(', '),
        ],
    );

    return (
        <>
            <Head title="Operations & Health" />
            <Page title="Operations & Health" fullWidth>
                <BlockStack gap="500">
                    {props.flash?.status && (
                        <Card>
                            <Text as="p">{props.flash.status}</Text>
                        </Card>
                    )}

                    <Text as="h2" variant="headingMd">
                        Platform health
                    </Text>
                    <InlineGrid columns={{ xs: 1, sm: 2, md: 5 }} gap="300">
                        <StatTile
                            label="Connections"
                            value={health.connections.total}
                        />
                        <StatTile
                            label="Needs reauth"
                            value={health.connections.needs_reauth}
                            tone={
                                health.connections.needs_reauth > 0
                                    ? 'critical'
                                    : undefined
                            }
                        />
                        <StatTile
                            label="Disconnected"
                            value={health.connections.disconnected}
                        />
                        <StatTile
                            label="Stale (2h+)"
                            value={health.connections.stale}
                            tone={
                                health.connections.stale > 0
                                    ? 'caution'
                                    : undefined
                            }
                        />
                        <StatTile
                            label="Never synced"
                            value={health.connections.never_synced}
                        />
                    </InlineGrid>

                    <Text as="h2" variant="headingMd">
                        Failed jobs ({health.failed_jobs.total} total)
                    </Text>
                    <Card>
                        {failedJobRows.length > 0 ? (
                            <DataTable
                                columnContentTypes={['text', 'text', 'text']}
                                headings={['Queue', 'Exception', 'Failed at']}
                                rows={failedJobRows}
                            />
                        ) : (
                            <Text as="p" tone="subdued">
                                No failed jobs.
                            </Text>
                        )}
                    </Card>

                    <Text as="h2" variant="headingMd">
                        Notification volume (last 24h)
                    </Text>
                    <Card>
                        {notificationRows.length > 0 ? (
                            <DataTable
                                columnContentTypes={['text', 'numeric']}
                                headings={['Type', 'Count']}
                                rows={notificationRows}
                            />
                        ) : (
                            <Text as="p" tone="subdued">
                                No notifications sent in the last 24 hours.
                            </Text>
                        )}
                    </Card>

                    <InlineGrid columns={{ xs: 1, md: 2 }} gap="400">
                        <BlockStack gap="300">
                            <Text as="h2" variant="headingMd">
                                SMS spend ({health.sms.consumed_this_month}{' '}
                                credits this month)
                            </Text>
                            <Card>
                                {smsConsumerRows.length > 0 ? (
                                    <DataTable
                                        columnContentTypes={['text', 'numeric']}
                                        headings={['Team', 'Credits used']}
                                        rows={smsConsumerRows}
                                    />
                                ) : (
                                    <Text as="p" tone="subdued">
                                        No SMS sent this month.
                                    </Text>
                                )}
                            </Card>
                        </BlockStack>

                        <BlockStack gap="300">
                            <Text as="h2" variant="headingMd">
                                Abuse guard — runaway rules (
                                {health.abuse.threshold_per_hour}+/hr)
                            </Text>
                            <Card>
                                {runawayRows.length > 0 ? (
                                    <DataTable
                                        columnContentTypes={['text', 'numeric']}
                                        headings={['Team', 'Executions/hr']}
                                        rows={runawayRows}
                                    />
                                ) : (
                                    <Text as="p" tone="subdued">
                                        No teams over the threshold right now.
                                    </Text>
                                )}
                            </Card>
                        </BlockStack>
                    </InlineGrid>

                    <Text as="h2" variant="headingMd">
                        Trial-abuse guard
                    </Text>
                    <InlineGrid columns={{ xs: 1, md: 2 }} gap="400">
                        <BlockStack gap="300">
                            <Text as="h3" variant="headingSm">
                                Same store, multiple teams
                            </Text>
                            <Card>
                                {sharedFingerprintRows.length > 0 ? (
                                    <DataTable
                                        columnContentTypes={['text', 'text']}
                                        headings={[
                                            'Platform',
                                            'Teams sharing this store',
                                        ]}
                                        rows={sharedFingerprintRows}
                                    />
                                ) : (
                                    <Text as="p" tone="subdued">
                                        No stores connected under more than one
                                        team.
                                    </Text>
                                )}
                            </Card>
                        </BlockStack>

                        <BlockStack gap="300">
                            <Text as="h3" variant="headingSm">
                                Same signup IP, multiple trials
                            </Text>
                            <Card>
                                {sharedSignupIpRows.length > 0 ? (
                                    <DataTable
                                        columnContentTypes={['text', 'text']}
                                        headings={['Signup IP', 'Teams']}
                                        rows={sharedSignupIpRows}
                                    />
                                ) : (
                                    <Text as="p" tone="subdued">
                                        No shared signup IPs across
                                        trial-consuming teams.
                                    </Text>
                                )}
                            </Card>
                        </BlockStack>
                    </InlineGrid>

                    <Text as="h2" variant="headingMd">
                        App config
                    </Text>
                    <Card>
                        <BlockStack gap="400">
                            <InlineStack
                                gap="300"
                                blockAlign="end"
                                wrap={false}
                            >
                                <div style={{ flexGrow: 1 }}>
                                    <TextField
                                        label="Minimum supported app version"
                                        value={minVersion}
                                        onChange={setMinVersion}
                                        autoComplete="off"
                                        placeholder="1.0.0"
                                    />
                                </div>
                                <Button
                                    onClick={() =>
                                        saveConfig('min_version', minVersion)
                                    }
                                >
                                    Save
                                </Button>
                            </InlineStack>

                            <InlineStack gap="300" blockAlign="center">
                                <Checkbox
                                    label="Maintenance mode"
                                    checked={maintenanceMode}
                                    onChange={(value) => {
                                        setMaintenanceMode(value);
                                        saveConfig('maintenance_mode', value);
                                    }}
                                />
                                {maintenanceMode && (
                                    <Badge tone="warning">Active</Badge>
                                )}
                            </InlineStack>

                            <InlineStack
                                gap="300"
                                blockAlign="end"
                                wrap={false}
                            >
                                <div style={{ flexGrow: 1 }}>
                                    <TextField
                                        label="Maintenance banner message"
                                        value={maintenanceBanner}
                                        onChange={setMaintenanceBanner}
                                        autoComplete="off"
                                        placeholder="We're performing scheduled maintenance — back shortly."
                                    />
                                </div>
                                <Button
                                    onClick={() =>
                                        saveConfig(
                                            'maintenance_banner',
                                            maintenanceBanner,
                                        )
                                    }
                                >
                                    Save
                                </Button>
                            </InlineStack>
                        </BlockStack>
                    </Card>
                </BlockStack>
            </Page>
        </>
    );
}

OpsIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
