import { Link, usePage } from '@inertiajs/react';
import { AppProvider, Box, InlineStack, Text } from '@shopify/polaris';
import en from '@shopify/polaris/locales/en.json';
import type { ReactNode } from 'react';

import '@shopify/polaris/build/esm/styles.css';

const NAV_ITEMS = [
    { label: 'Dashboard', href: '/admin' },
    { label: 'Customers', href: '/admin/customers' },
    { label: 'Plans & Limits', href: '/admin/plans' },
];

function AdminNav() {
    const { url, props } = usePage<{ auth: { user: unknown } }>();

    if (!props.auth?.user) {
        return null;
    }

    return (
        <Box
            background="bg-surface"
            borderBlockEndWidth="025"
            borderColor="border"
            padding="300"
        >
            <InlineStack gap="400" blockAlign="center">
                <Text as="span" variant="headingSm">
                    StockBeat Admin
                </Text>
                <InlineStack gap="300">
                    {NAV_ITEMS.map((item) => {
                        const isActive =
                            item.href === '/admin'
                                ? url === '/admin'
                                : url.startsWith(item.href);

                        return (
                            <Link key={item.href} href={item.href}>
                                <Text
                                    as="span"
                                    variant="bodyMd"
                                    fontWeight={isActive ? 'semibold' : 'regular'}
                                    tone={isActive ? undefined : 'subdued'}
                                >
                                    {item.label}
                                </Text>
                            </Link>
                        );
                    })}
                </InlineStack>
            </InlineStack>
        </Box>
    );
}

export default function AdminLayout({ children }: { children: ReactNode }) {
    return (
        <AppProvider i18n={en}>
            <AdminNav />
            {children}
        </AppProvider>
    );
}
