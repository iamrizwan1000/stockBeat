import { Head, useForm } from '@inertiajs/react';
import {
    Banner,
    BlockStack,
    Box,
    Button,
    Card,
    Checkbox,
    Form,
    FormLayout,
    Text,
    TextField,
} from '@shopify/polaris';
import type { ReactNode } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type LoginProps = {
    status?: string;
};

export default function Login({ status }: LoginProps) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = () => post('/admin/login');

    return (
        <>
            <Head title="Log in" />
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
                            <Text as="h1" variant="headingLg" alignment="center">
                                StockBeat Admin
                            </Text>
                            <Text as="p" tone="subdued" alignment="center">
                                Sign in to your admin account
                            </Text>
                        </BlockStack>

                        {status && <Banner tone="success">{status}</Banner>}

                        <Card>
                            <Form onSubmit={submit}>
                                <FormLayout>
                                    <TextField
                                        label="Email"
                                        type="email"
                                        name="email"
                                        autoComplete="email"
                                        value={data.email}
                                        onChange={(value) => setData('email', value)}
                                        error={errors.email}
                                        autoFocus
                                    />
                                    <TextField
                                        label="Password"
                                        type="password"
                                        name="password"
                                        autoComplete="current-password"
                                        value={data.password}
                                        onChange={(value) => setData('password', value)}
                                        error={errors.password}
                                    />
                                    <Checkbox
                                        label="Remember me"
                                        checked={data.remember}
                                        onChange={(value) => setData('remember', value)}
                                    />
                                    <Button
                                        submit
                                        variant="primary"
                                        fullWidth
                                        loading={processing}
                                    >
                                        Log in
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

Login.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
