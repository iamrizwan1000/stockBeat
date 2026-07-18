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

type QuotaUsage = {
    calls_today: number;
    daily_limit: number | null;
    pct_used: number | null;
    note: string;
};

type SmsAnomaly = {
    team_id: number;
    team_name: string;
    current: number;
    baseline: number;
    multiple: number;
};

type TrendPoint = {
    date: string;
    active_teams: number;
    mrr: number;
    churned_teams: number;
    total_orders_synced: number;
    failed_jobs_total: number;
    sms_cost_total: number;
};

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
        high_sms_cost_teams: Array<{
            team_id: number;
            team_name: string;
            consumed: number;
        }>;
        high_sms_cost_threshold: number;
    };
    api_quota_usage: {
        etsy: QuotaUsage;
        ebay: QuotaUsage;
        amazon: QuotaUsage;
        tiktok: QuotaUsage;
    };
    sms_anomalies: Array<SmsAnomaly>;
    trending: Array<TrendPoint>;
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

function QuotaTile({ platform, usage }: { platform: string; usage: QuotaUsage }) {
    const tone: 'critical' | 'caution' | undefined =
        usage.pct_used === null
            ? undefined
            : usage.pct_used >= 90
              ? 'critical'
              : usage.pct_used >= 70
                ? 'caution'
                : undefined;

    return (
        <Card>
            <BlockStack gap="150">
                <Text as="p" tone="subdued" variant="bodySm">
                    {platform}
                </Text>
                <Text as="p" variant="headingLg" tone={tone}>
                    {usage.calls_today.toLocaleString()}
                    {usage.daily_limit !== null && (
                        <Text as="span" tone="subdued" variant="bodySm">
                            {' '}
                            / {usage.daily_limit.toLocaleString()} (
                            {usage.pct_used}%)
                        </Text>
                    )}
                </Text>
                <Text as="p" tone="subdued" variant="bodyXs">
                    {usage.note}
                </Text>
            </BlockStack>
        </Card>
    );
}

/**
 * Small single-series trend line, no charting library (none exists in this
 * app's `package.json` — checked before adding one). One accent hue per the
 * app's default data-viz palette (`#2a78d6` light / `#3987e5` dark), a thin
 * 2px line with a rounded data-end, recessive gridlines, and a direct label
 * on the latest value so the headline number reads without hovering. Each
 * point still carries an `<title>` for a plain hover tooltip.
 */
function TrendSparkline({
    label,
    points,
    format,
}: {
    label: string;
    points: Array<{ date: string; value: number }>;
    format?: (value: number) => string;
}) {
    const formatValue = format ?? ((value: number) => value.toLocaleString());

    if (points.length < 2) {
        return (
            <Card>
                <BlockStack gap="150">
                    <Text as="p" tone="subdued" variant="bodySm">
                        {label}
                    </Text>
                    <Text as="p" tone="subdued">
                        Not enough history yet.
                    </Text>
                </BlockStack>
            </Card>
        );
    }

    const width = 280;
    const height = 72;
    const padding = 6;
    const values = points.map((p) => p.value);
    const min = Math.min(...values);
    const max = Math.max(...values);
    const range = max - min || 1;

    const coords = points.map((point, index) => {
        const x =
            padding +
            (index / (points.length - 1)) * (width - padding * 2);
        const y =
            height -
            padding -
            ((point.value - min) / range) * (height - padding * 2);

        return { x, y, point };
    });

    const path = coords
        .map((c, i) => `${i === 0 ? 'M' : 'L'}${c.x.toFixed(1)},${c.y.toFixed(1)}`)
        .join(' ');

    const last = coords[coords.length - 1];
    const latest = points[points.length - 1];

    return (
        <Card>
            <BlockStack gap="150">
                <InlineStack align="space-between" blockAlign="center">
                    <Text as="p" tone="subdued" variant="bodySm">
                        {label}
                    </Text>
                    <Text as="p" variant="headingSm">
                        {formatValue(latest.value)}
                    </Text>
                </InlineStack>
                <svg
                    viewBox={`0 0 ${width} ${height}`}
                    width="100%"
                    height={height}
                    role="img"
                    aria-label={`${label} trend over the last ${points.length} days, latest ${formatValue(latest.value)}`}
                    style={{ display: 'block' }}
                >
                    <line
                        x1={padding}
                        y1={height - padding}
                        x2={width - padding}
                        y2={height - padding}
                        stroke="var(--ops-trend-axis, #c3c2b7)"
                        strokeWidth={1}
                    />
                    <path
                        d={path}
                        fill="none"
                        stroke="var(--ops-trend-line, #2a78d6)"
                        strokeWidth={2}
                    />
                    <circle
                        cx={last.x}
                        cy={last.y}
                        r={4}
                        fill="var(--ops-trend-line, #2a78d6)"
                    >
                        <title>
                            {latest.date}: {formatValue(latest.value)}
                        </title>
                    </circle>
                    {coords.slice(0, -1).map((c, i) => (
                        <circle key={i} cx={c.x} cy={c.y} r={2.5} fill="transparent">
                            <title>
                                {c.point.date}: {formatValue(c.point.value)}
                            </title>
                        </circle>
                    ))}
                </svg>
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
    const highSmsCostRows = health.abuse.high_sms_cost_teams.map((row) => [
        row.team_name,
        String(row.consumed),
    ]);
    const smsAnomalyRows = health.sms_anomalies.map((row) => [
        row.team_name,
        String(row.current),
        String(row.baseline),
        `${row.multiple}x`,
    ]);
    const trendDates = health.trending.map((point) => point.date);
    const trendSeries = (key: keyof Omit<TrendPoint, 'date'>) =>
        health.trending.map((point) => ({
            date: point.date,
            value: point[key],
        }));

    return (
        <>
            <Head title="Operations & Health" />
            <style>{`
                :root {
                    --ops-trend-line: #2a78d6;
                    --ops-trend-axis: #c3c2b7;
                }
                @media (prefers-color-scheme: dark) {
                    :root {
                        --ops-trend-line: #3987e5;
                        --ops-trend-axis: #383835;
                    }
                }
            `}</style>
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
                        API quota usage (today)
                    </Text>
                    <InlineGrid columns={{ xs: 1, sm: 2, md: 4 }} gap="300">
                        <QuotaTile
                            platform="Etsy"
                            usage={health.api_quota_usage.etsy}
                        />
                        <QuotaTile
                            platform="eBay"
                            usage={health.api_quota_usage.ebay}
                        />
                        <QuotaTile
                            platform="Amazon"
                            usage={health.api_quota_usage.amazon}
                        />
                        <QuotaTile
                            platform="TikTok Shop"
                            usage={health.api_quota_usage.tiktok}
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
                        Abuse guard — high SMS cost this month (
                        {health.abuse.high_sms_cost_threshold}+ credits) —
                        same flag shown on each customer's own detail page
                    </Text>
                    <Card>
                        {highSmsCostRows.length > 0 ? (
                            <DataTable
                                columnContentTypes={['text', 'numeric']}
                                headings={['Team', 'Credits used']}
                                rows={highSmsCostRows}
                            />
                        ) : (
                            <Text as="p" tone="subdued">
                                No teams over the SMS-cost threshold this
                                month.
                            </Text>
                        )}
                    </Card>

                    <Text as="h2" variant="headingMd">
                        Abuse guard — SMS anomalies (5x+ a team's own 28-day
                        baseline, min {'20'} credits)
                    </Text>
                    <Card>
                        {smsAnomalyRows.length > 0 ? (
                            <DataTable
                                columnContentTypes={[
                                    'text',
                                    'numeric',
                                    'numeric',
                                    'numeric',
                                ]}
                                headings={[
                                    'Team',
                                    'Last 24h',
                                    'Own daily baseline',
                                    'Multiple',
                                ]}
                                rows={smsAnomalyRows}
                            />
                        ) : (
                            <Text as="p" tone="subdued">
                                No team's SMS volume is spiking relative to
                                its own history right now.
                            </Text>
                        )}
                    </Card>

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
                        30-day trend
                        {trendDates.length > 0 && (
                            <Text as="span" tone="subdued">
                                {' '}
                                (since {trendDates[0]})
                            </Text>
                        )}
                    </Text>
                    {health.trending.length > 0 ? (
                        <InlineGrid columns={{ xs: 1, sm: 2, md: 3 }} gap="300">
                            <TrendSparkline
                                label="Active teams"
                                points={trendSeries('active_teams')}
                            />
                            <TrendSparkline
                                label="MRR"
                                points={trendSeries('mrr')}
                                format={(v) => `$${v.toFixed(2)}`}
                            />
                            <TrendSparkline
                                label="Churned teams (this month)"
                                points={trendSeries('churned_teams')}
                            />
                            <TrendSparkline
                                label="Total orders synced"
                                points={trendSeries('total_orders_synced')}
                            />
                            <TrendSparkline
                                label="Failed jobs (total)"
                                points={trendSeries('failed_jobs_total')}
                            />
                            <TrendSparkline
                                label="SMS cost (this month)"
                                points={trendSeries('sms_cost_total')}
                            />
                        </InlineGrid>
                    ) : (
                        <Card>
                            <Text as="p" tone="subdued">
                                No history recorded yet — the daily
                                `ops:record-daily-snapshot` job writes the
                                first row at the next scheduled run.
                            </Text>
                        </Card>
                    )}

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
