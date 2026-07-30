import { Head, router } from '@inertiajs/react';
import {
    BlockStack,
    Button,
    Card,
    InlineStack,
    Page,
    Select,
    Text,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type LogPayload = {
    content: string;
    path: string;
    size_bytes: number;
    truncated: boolean;
    exists: boolean;
};

type Props = {
    log: LogPayload;
    selected_bytes: number;
    byte_options: number[];
};

function formatBytes(bytes: number): string {
    if (bytes < 1000) {
        return `${bytes} B`;
    }

    if (bytes < 1_000_000) {
        return `${(bytes / 1000).toFixed(1)} KB`;
    }

    return `${(bytes / 1_000_000).toFixed(1)} MB`;
}

export default function LogsIndex({
    log,
    selected_bytes,
    byte_options,
}: Props) {
    const [copied, setCopied] = useState(false);

    const byteOptions = byte_options.map((bytes) => ({
        label: `Last ${formatBytes(bytes)}`,
        value: String(bytes),
    }));

    const copyToClipboard = () => {
        navigator.clipboard.writeText(log.content).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    };

    const reload = (bytes?: string) => {
        router.get(
            '/admin/logs',
            bytes ? { bytes } : { bytes: selected_bytes },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Logs" />
            <Page
                title="Logs"
                subtitle={log.exists ? log.path : undefined}
                primaryAction={{
                    content: 'Refresh',
                    onAction: () => reload(),
                }}
            >
                <BlockStack gap="400">
                    {!log.exists ? (
                        <Card>
                            <Text as="p" tone="subdued">
                                No log file found at the expected path yet.
                            </Text>
                        </Card>
                    ) : (
                        <Card>
                            <BlockStack gap="300">
                                <InlineStack
                                    align="space-between"
                                    blockAlign="center"
                                >
                                    <InlineStack gap="300" blockAlign="center">
                                        <div style={{ minWidth: 180 }}>
                                            <Select
                                                label="Show"
                                                labelHidden
                                                options={byteOptions}
                                                value={String(selected_bytes)}
                                                onChange={(value) =>
                                                    reload(value)
                                                }
                                            />
                                        </div>
                                        <Text as="p" tone="subdued">
                                            File is{' '}
                                            {formatBytes(log.size_bytes)}
                                            {log.truncated
                                                ? ' — showing the tail only'
                                                : ''}
                                        </Text>
                                    </InlineStack>
                                    <Button
                                        onClick={copyToClipboard}
                                        disabled={log.content === ''}
                                    >
                                        {copied ? 'Copied!' : 'Copy all'}
                                    </Button>
                                </InlineStack>

                                {log.content === '' ? (
                                    <Text as="p" tone="subdued">
                                        Log file is empty.
                                    </Text>
                                ) : (
                                    <pre
                                        style={{
                                            margin: 0,
                                            padding: '16px',
                                            background: '#1a1a1a',
                                            color: '#d4d4d4',
                                            borderRadius: '8px',
                                            maxHeight: '70vh',
                                            overflow: 'auto',
                                            fontSize: '12px',
                                            lineHeight: 1.5,
                                            whiteSpace: 'pre-wrap',
                                            wordBreak: 'break-word',
                                        }}
                                    >
                                        {log.content}
                                    </pre>
                                )}
                            </BlockStack>
                        </Card>
                    )}
                </BlockStack>
            </Page>
        </>
    );
}

LogsIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
