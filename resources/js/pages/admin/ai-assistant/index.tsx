import { Head, router } from '@inertiajs/react';
import {
    Badge,
    BlockStack,
    Box,
    Button,
    Card,
    InlineStack,
    Page,
    Text,
    TextField,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type ProviderSetting = {
    provider: string;
    model: string | null;
    active: boolean;
    has_key: boolean;
    updated_at: string | null;
};

const PROVIDER_LABELS: Record<string, string> = {
    openai: 'OpenAI',
    groq: 'Groq',
    claude: 'Claude (Anthropic)',
};

const PROVIDER_MODEL_HINTS: Record<string, string> = {
    openai: 'e.g. gpt-4o',
    groq: 'e.g. llama-3.3-70b-versatile',
    claude: 'e.g. claude-sonnet-5',
};

function ProviderCard({ setting }: { setting: ProviderSetting }) {
    const [apiKey, setApiKey] = useState('');
    const [model, setModel] = useState(setting.model ?? '');

    const save = (activate: boolean) => {
        router.put(
            `/admin/ai-assistant/${setting.provider}`,
            {
                api_key: apiKey || null,
                model: model || null,
                activate,
            },
            {
                preserveScroll: true,
                onSuccess: () => setApiKey(''),
            },
        );
    };

    return (
        <Card>
            <BlockStack gap="300">
                <InlineStack align="space-between" blockAlign="center">
                    <Text as="h2" variant="headingMd">
                        {PROVIDER_LABELS[setting.provider] ?? setting.provider}
                    </Text>
                    {setting.active ? (
                        <Badge tone="success">Active</Badge>
                    ) : (
                        <Badge>Inactive</Badge>
                    )}
                </InlineStack>

                <TextField
                    label="Model"
                    value={model}
                    onChange={setModel}
                    autoComplete="off"
                    placeholder={PROVIDER_MODEL_HINTS[setting.provider]}
                />

                <TextField
                    label="API key"
                    type="password"
                    value={apiKey}
                    onChange={setApiKey}
                    autoComplete="off"
                    placeholder={
                        setting.has_key
                            ? 'Key is set — leave blank to keep it'
                            : 'No key set yet'
                    }
                    helpText="Stored encrypted. Never shown again after saving — leave blank to keep the current key."
                />

                <InlineStack gap="200">
                    <Button onClick={() => save(false)}>Save</Button>
                    <Button
                        variant="primary"
                        disabled={setting.active || (!setting.has_key && !apiKey)}
                        onClick={() => save(true)}
                    >
                        {setting.active
                            ? 'Currently active'
                            : 'Save & make active'}
                    </Button>
                </InlineStack>
            </BlockStack>
        </Card>
    );
}

export default function AiAssistantIndex({
    providers,
}: {
    providers: ProviderSetting[];
}) {
    return (
        <>
            <Head title="AI Assistant" />
            <Page
                title="AI Assistant"
                subtitle="One active provider powers every /assistant/ask call — switching takes effect immediately, no deploy (Plan §8.7.9)"
                fullWidth
            >
                <BlockStack gap="400">
                    <Box padding="200">
                        <Text as="p" tone="subdued">
                            Only one provider can be active at a time.
                            Activating a provider automatically deactivates
                            the others. Question quotas per plan are edited
                            on the{' '}
                            <a href="/admin/plans">Plans &amp; Limits</a>{' '}
                            page (ai_enabled, ai_questions_monthly,
                            ai_rule_builder_enabled,
                            ai_proactive_insights_enabled).
                        </Text>
                    </Box>
                    {providers.map((setting) => (
                        <ProviderCard key={setting.provider} setting={setting} />
                    ))}
                </BlockStack>
            </Page>
        </>
    );
}

AiAssistantIndex.layout = (page: ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
