import { Link, router, usePage } from '@inertiajs/react';
import { AppProvider, Box, Frame, Navigation, Text, TopBar } from '@shopify/polaris';
import { CreditCardIcon, ExitIcon, HomeIcon, PersonIcon } from '@shopify/polaris-icons';
import en from '@shopify/polaris/locales/en.json';
import { forwardRef, useState, type ReactNode } from 'react';

import '@shopify/polaris/build/esm/styles.css';

const NAV_ITEMS = [
    { label: 'Dashboard', url: '/admin', icon: HomeIcon, exactMatch: true },
    { label: 'Customers', url: '/admin/customers', icon: PersonIcon, exactMatch: false },
    { label: 'Plans & Limits', url: '/admin/plans', icon: CreditCardIcon, exactMatch: false },
];

/**
 * Lets Polaris's Navigation/TopBar render real Inertia `<Link>`s (client-side
 * routing) instead of full-page `<a>` reloads, via AppProvider's
 * `linkComponent` escape hatch.
 */
const InertiaLink = forwardRef<HTMLAnchorElement, { href?: string; children?: ReactNode; className?: string }>(
    function InertiaLink({ href, children, className }, ref) {
        return (
            <Link ref={ref} href={href ?? '#'} className={className}>
                {children}
            </Link>
        );
    },
);

type AdminUser = { name: string; email: string };

function initialsFor(name: string): string {
    const initials = name
        .split(' ')
        .filter(Boolean)
        .map((part) => part[0]?.toUpperCase())
        .join('');

    return initials.slice(0, 2) || 'A';
}

function AdminFrame({ children }: { children: ReactNode }) {
    const { url, props } = usePage<{ auth: { user: AdminUser | null } }>();
    const [mobileNavigationActive, setMobileNavigationActive] = useState(false);
    const [userMenuActive, setUserMenuActive] = useState(false);

    const user = props.auth?.user;

    if (!user) {
        return <>{children}</>;
    }

    const navigation = (
        <Navigation location={url}>
            <Box padding="400" paddingBlockEnd="200">
                <Text as="span" variant="headingMd">
                    StockBeat
                </Text>
            </Box>
            <Navigation.Section
                items={NAV_ITEMS.map((item) => ({
                    label: item.label,
                    url: item.url,
                    icon: item.icon,
                    selected: item.exactMatch ? url === item.url : url.startsWith(item.url),
                }))}
            />
        </Navigation>
    );

    const topBar = (
        <TopBar
            showNavigationToggle
            onNavigationToggle={() => setMobileNavigationActive((active) => !active)}
            userMenu={
                <TopBar.UserMenu
                    name={user.name}
                    detail={user.email}
                    initials={initialsFor(user.name)}
                    open={userMenuActive}
                    onToggle={() => setUserMenuActive((active) => !active)}
                    actions={[
                        {
                            items: [
                                {
                                    content: 'Sign out',
                                    icon: ExitIcon,
                                    onAction: () => router.post('/admin/logout'),
                                },
                            ],
                        },
                    ]}
                />
            }
        />
    );

    return (
        <Frame
            topBar={topBar}
            navigation={navigation}
            showMobileNavigation={mobileNavigationActive}
            onNavigationDismiss={() => setMobileNavigationActive(false)}
        >
            {children}
        </Frame>
    );
}

export default function AdminLayout({ children }: { children: ReactNode }) {
    return (
        <AppProvider i18n={en} linkComponent={InertiaLink}>
            <AdminFrame>{children}</AdminFrame>
        </AppProvider>
    );
}
