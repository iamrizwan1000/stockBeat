import { Head, router, usePage } from '@inertiajs/react';
import { BlockStack, Card, Page, Text } from '@shopify/polaris';
import type { ReactNode } from 'react';

import AdminLayout from '@/layouts/admin-layout';

export default function Dashboard() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Dashboard" />
            <Page
                title="Dashboard"
                secondaryActions={[
                    {
                        content: 'Log out',
                        onAction: () => router.post('/admin/logout'),
                    },
                ]}
            >
                <Card>
                    <BlockStack gap="200">
                        <Text as="h2" variant="headingMd">
                            Welcome, {auth.user.name}
                        </Text>
                        <Text as="p" tone="subdued">
                            You are signed in as {auth.user.email} ({auth.user.role}).
                            The admin modules — customers, plans, promotions, messaging,
                            support inbox, operations — will be built here next.
                        </Text>
                    </BlockStack>
                </Card>
            </Page>
        </>
    );
}

Dashboard.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
