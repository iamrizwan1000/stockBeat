import { Head, useForm } from '@inertiajs/react';
import {
    BlockStack,
    Box,
    Button,
    Card,
    Form,
    FormLayout,
    Text,
    TextField,
} from '@shopify/polaris';
import type { ReactNode } from 'react';

import AdminLayout from '@/layouts/admin-layout';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors } = useForm({
        password: '',
    });

    const submit = () => post('/admin/user/confirm-password');

    return (
        <>
            <Head title="Confirm password" />
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
                        <Text as="h1" variant="headingLg" alignment="center">
                            Confirm your password
                        </Text>
                        <Card>
                            <Form onSubmit={submit}>
                                <FormLayout>
                                    <Text as="p" tone="subdued">
                                        This is a secure area. Please confirm your
                                        password before continuing.
                                    </Text>
                                    <TextField
                                        label="Password"
                                        type="password"
                                        name="password"
                                        autoComplete="current-password"
                                        value={data.password}
                                        onChange={(value) => setData('password', value)}
                                        error={errors.password}
                                        autoFocus
                                    />
                                    <Button
                                        submit
                                        variant="primary"
                                        fullWidth
                                        loading={processing}
                                    >
                                        Confirm
                                    </Button>
                                </FormLayout>
                            </Form>
                        </Card>
                    </BlockStack>
                </Box>
            </div>
        </>
    );
}

ConfirmPassword.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
