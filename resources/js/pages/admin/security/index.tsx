import { Head, router } from '@inertiajs/react';
import {
    Banner,
    BlockStack,
    Box,
    Button,
    Card,
    InlineStack,
    List,
    Page,
    Text,
    TextField,
} from '@shopify/polaris';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type SecurityProps = {
    twoFactorEnabled: boolean;
};

function readCookie(name: string): string {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : '';
}

// Every call needs Accept: application/json — Fortify's 2FA endpoints return a
// plain redirect for browser (non-JSON) requests, which a fetch() can't follow
// usefully. With Accept: application/json they return clean JSON/plain 200s
// instead (see vendor/laravel/fortify's TwoFactor*Response classes).
function apiFetch(
    url: string,
    method: string,
    body?: unknown,
): Promise<Response> {
    return fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': readCookie('XSRF-TOKEN'),
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });
}

export default function Security({ twoFactorEnabled }: SecurityProps) {
    const [enabled, setEnabled] = useState(twoFactorEnabled);
    const [qrSvg, setQrSvg] = useState<string | null>(null);
    const [secretKey, setSecretKey] = useState<string | null>(null);
    const [confirming, setConfirming] = useState(false);
    const [code, setCode] = useState('');
    const [codeError, setCodeError] = useState<string | null>(null);
    const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
    const [busy, setBusy] = useState(false);

    // A stale/missing password confirmation (Plan §8.7's "mandatory 2FA" is
    // gated behind a recent password confirm) can't usefully redirect a
    // fetch() call — send the admin to confirm, then they retry the action.
    // Known minor rough edge: they land back on the dashboard, not here.
    const handlePasswordConfirmRequired = () => {
        router.visit('/admin/user/confirm-password');
    };

    const startEnabling = async () => {
        setBusy(true);
        setCodeError(null);

        const enableRes = await apiFetch(
            '/admin/user/two-factor-authentication',
            'POST',
        );

        if (enableRes.status === 423) {
            setBusy(false);

            return handlePasswordConfirmRequired();
        }

        const [qrRes, secretRes] = await Promise.all([
            apiFetch('/admin/user/two-factor-qr-code', 'GET'),
            apiFetch('/admin/user/two-factor-secret-key', 'GET'),
        ]);
        const qrData = await qrRes.json();
        const secretData = await secretRes.json();

        setQrSvg(qrData.svg ?? null);
        setSecretKey(secretData.secretKey ?? null);
        setConfirming(true);
        setBusy(false);
    };

    const confirmTwoFactor = async () => {
        setBusy(true);
        setCodeError(null);

        const res = await apiFetch(
            '/admin/user/confirmed-two-factor-authentication',
            'POST',
            { code },
        );

        if (res.status === 423) {
            setBusy(false);

            return handlePasswordConfirmRequired();
        }

        if (!res.ok) {
            const data = await res.json();
            setCodeError(
                data.errors?.code?.[0] ?? 'That code was not correct.',
            );
            setBusy(false);

            return;
        }

        const codesRes = await apiFetch(
            '/admin/user/two-factor-recovery-codes',
            'GET',
        );
        setRecoveryCodes(await codesRes.json());
        setConfirming(false);
        setEnabled(true);
        setBusy(false);
    };

    const viewRecoveryCodes = async () => {
        setBusy(true);
        const res = await apiFetch(
            '/admin/user/two-factor-recovery-codes',
            'GET',
        );

        if (res.status === 423) {
            setBusy(false);

            return handlePasswordConfirmRequired();
        }

        setRecoveryCodes(await res.json());
        setBusy(false);
    };

    const disable = async () => {
        if (
            !window.confirm(
                'Disable two-factor authentication for your account?',
            )
        ) {
            return;
        }

        setBusy(true);
        const res = await apiFetch(
            '/admin/user/two-factor-authentication',
            'DELETE',
        );

        if (res.status === 423) {
            setBusy(false);

            return handlePasswordConfirmRequired();
        }

        setEnabled(false);
        setQrSvg(null);
        setSecretKey(null);
        setRecoveryCodes(null);
        setBusy(false);
    };

    return (
        <AdminLayout>
            <Head title="Security" />
            <Page
                title="Security"
                subtitle="Two-factor authentication for your admin account"
            >
                <BlockStack gap="400">
                    <Card>
                        <BlockStack gap="400">
                            {enabled ? (
                                <Banner tone="success">
                                    Two-factor authentication is enabled.
                                </Banner>
                            ) : (
                                <Banner tone="warning">
                                    Two-factor authentication is not enabled on
                                    your account.
                                </Banner>
                            )}

                            {!enabled && !confirming && (
                                <Button
                                    variant="primary"
                                    loading={busy}
                                    onClick={startEnabling}
                                >
                                    Enable two-factor authentication
                                </Button>
                            )}

                            {confirming && (
                                <BlockStack gap="300">
                                    <Text as="p">
                                        Scan this QR code with your
                                        authenticator app, or enter the key
                                        manually, then confirm with a generated
                                        code.
                                    </Text>
                                    {qrSvg && (
                                        <Box
                                            background="bg-surface"
                                            padding="400"
                                            borderRadius="200"
                                        >
                                            <div
                                                dangerouslySetInnerHTML={{
                                                    __html: qrSvg,
                                                }}
                                            />
                                        </Box>
                                    )}
                                    {secretKey && (
                                        <Text as="p" tone="subdued">
                                            Setup key:{' '}
                                            <Text as="span" fontWeight="bold">
                                                {secretKey}
                                            </Text>
                                        </Text>
                                    )}
                                    <TextField
                                        label="Authentication code"
                                        autoComplete="one-time-code"
                                        value={code}
                                        onChange={setCode}
                                        error={codeError ?? undefined}
                                        autoFocus
                                    />
                                    <InlineStack gap="200">
                                        <Button
                                            variant="primary"
                                            loading={busy}
                                            onClick={confirmTwoFactor}
                                        >
                                            Confirm
                                        </Button>
                                        <Button
                                            onClick={() => {
                                                setConfirming(false);
                                                setQrSvg(null);
                                                setSecretKey(null);
                                            }}
                                        >
                                            Cancel
                                        </Button>
                                    </InlineStack>
                                </BlockStack>
                            )}

                            {enabled && !confirming && (
                                <InlineStack gap="200">
                                    <Button
                                        loading={busy}
                                        onClick={viewRecoveryCodes}
                                    >
                                        View recovery codes
                                    </Button>
                                    <Button
                                        tone="critical"
                                        loading={busy}
                                        onClick={disable}
                                    >
                                        Disable two-factor authentication
                                    </Button>
                                </InlineStack>
                            )}

                            {recoveryCodes && recoveryCodes.length > 0 && (
                                <Card background="bg-surface-secondary">
                                    <BlockStack gap="200">
                                        <Text as="h3" variant="headingSm">
                                            Recovery codes
                                        </Text>
                                        <Text as="p" tone="subdued">
                                            Store these somewhere safe. Each
                                            code can be used once to sign in if
                                            you lose access to your
                                            authenticator app.
                                        </Text>
                                        <List type="bullet">
                                            {recoveryCodes.map(
                                                (recoveryCode) => (
                                                    <List.Item
                                                        key={recoveryCode}
                                                    >
                                                        <Text
                                                            as="span"
                                                            fontWeight="bold"
                                                        >
                                                            {recoveryCode}
                                                        </Text>
                                                    </List.Item>
                                                ),
                                            )}
                                        </List>
                                    </BlockStack>
                                </Card>
                            )}
                        </BlockStack>
                    </Card>
                </BlockStack>
            </Page>
        </AdminLayout>
    );
}
