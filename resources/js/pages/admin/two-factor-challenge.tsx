import { Head, useForm } from '@inertiajs/react';
import {
    BlockStack,
    Box,
    Button,
    Card,
    Form,
    FormLayout,
    Link,
    Text,
    TextField,
} from '@shopify/polaris';
import type { ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

export default function TwoFactorChallenge() {
    const [useRecoveryCode, setUseRecoveryCode] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
        code: '',
        recovery_code: '',
    });

    const submit = () => post('/admin/two-factor-challenge');

    return (
        <>
            <Head title="Two-factor challenge" />
            <div
                style={{
                    minHeight: '100vh',
                    display: 'grid',
                    placeItems: 'center',
                    background: 'var(--p-color-bg)',
                }}
            >
                <Box width="400px" padding="400">
                    <BlockStack gap="400">
                        <BlockStack gap="100">
                            <Text
                                as="h1"
                                variant="headingLg"
                                alignment="center"
                            >
                                Two-factor authentication
                            </Text>
                            <Text as="p" tone="subdued" alignment="center">
                                {useRecoveryCode
                                    ? 'Enter one of your recovery codes.'
                                    : 'Enter the 6-digit code from your authenticator app.'}
                            </Text>
                        </BlockStack>

                        <Card>
                            <Form onSubmit={submit}>
                                <FormLayout>
                                    {useRecoveryCode ? (
                                        <TextField
                                            label="Recovery code"
                                            name="recovery_code"
                                            autoComplete="off"
                                            value={data.recovery_code}
                                            onChange={(value) =>
                                                setData('recovery_code', value)
                                            }
                                            error={errors.recovery_code}
                                            autoFocus
                                        />
                                    ) : (
                                        <TextField
                                            label="Code"
                                            name="code"
                                            autoComplete="one-time-code"
                                            value={data.code}
                                            onChange={(value) =>
                                                setData('code', value)
                                            }
                                            error={errors.code}
                                            autoFocus
                                        />
                                    )}
                                    <Button
                                        submit
                                        variant="primary"
                                        fullWidth
                                        loading={processing}
                                    >
                                        Verify
                                    </Button>
                                    <Link
                                        onClick={() =>
                                            setUseRecoveryCode((v) => !v)
                                        }
                                        monochrome
                                    >
                                        {useRecoveryCode
                                            ? 'Use an authentication code instead'
                                            : 'Use a recovery code instead'}
                                    </Link>
                                </FormLayout>
                            </Form>
                        </Card>
                    </BlockStack>
                </Box>
            </div>
        </>
    );
}

TwoFactorChallenge.layout = (page: ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
