import { Link, router, usePage } from '@inertiajs/react';
import {
    AppProvider,
    Box,
    Frame,
    Navigation,
    Text,
    TopBar,
} from '@shopify/polaris';
import en from '@shopify/polaris/locales/en.json';
import {
    ChatIcon,
    ClockIcon,
    CreditCardIcon,
    DiscountIcon,
    ExitIcon,
    FlagIcon,
    HeartIcon,
    HomeIcon,
    LockIcon,
    MagicIcon,
    MegaphoneIcon,
    NoteIcon,
    NotificationIcon,
    PersonIcon,
    TargetIcon,
    TeamIcon,
} from '@shopify/polaris-icons';
import { forwardRef, useState } from 'react';
import type { ReactNode } from 'react';

import '@shopify/polaris/build/esm/styles.css';

const NAV_ITEMS = [
    { label: 'Dashboard', url: '/admin', icon: HomeIcon, exactMatch: true },
    {
        label: 'Customers',
        url: '/admin/customers',
        icon: PersonIcon,
        exactMatch: false,
    },
    {
        label: 'Plans & Limits',
        url: '/admin/plans',
        icon: CreditCardIcon,
        exactMatch: false,
    },
    {
        label: 'Feature Flags',
        url: '/admin/feature-flags',
        icon: FlagIcon,
        exactMatch: false,
    },
    {
        label: 'AI Assistant',
        url: '/admin/ai-assistant',
        icon: MagicIcon,
        exactMatch: false,
    },
    {
        label: 'Promotions',
        url: '/admin/promotions',
        icon: DiscountIcon,
        exactMatch: false,
    },
    {
        label: 'Segments',
        url: '/admin/segments',
        icon: TargetIcon,
        exactMatch: false,
    },
    {
        label: 'Broadcasts',
        url: '/admin/broadcasts',
        icon: MegaphoneIcon,
        exactMatch: false,
    },
    {
        label: 'Announcements',
        url: '/admin/announcements',
        icon: NotificationIcon,
        exactMatch: false,
    },
    {
        label: 'Support Inbox',
        url: '/admin/support',
        icon: ChatIcon,
        exactMatch: false,
    },
    {
        label: 'Canned Replies',
        url: '/admin/canned-replies',
        icon: NoteIcon,
        exactMatch: false,
    },
    {
        label: 'Operations & Health',
        url: '/admin/ops',
        icon: HeartIcon,
        exactMatch: false,
    },
    {
        label: 'Admin Team',
        url: '/admin/team',
        icon: TeamIcon,
        exactMatch: false,
    },
    {
        label: 'Audit Log',
        url: '/admin/audit-log',
        icon: ClockIcon,
        exactMatch: false,
    },
];

/**
 * Lets Polaris's Navigation/TopBar render real Inertia `<Link>`s (client-side
 * routing) instead of full-page `<a>` reloads, via AppProvider's
 * `linkComponent` escape hatch. Polaris's `LinkLikeComponent` contract passes
 * the destination as a `url` prop, not `href` — passing `href` here (as this
 * previously did) left every nav item with an empty `href=""`, so every
 * sidebar link rendered but silently went nowhere on click.
 */
const InertiaLink = forwardRef<
    HTMLAnchorElement,
    { url?: string; children?: ReactNode; className?: string }
>(function InertiaLink({ url, children, className }, ref) {
    return (
        <Link ref={ref} href={url ?? '#'} className={className}>
            {children}
        </Link>
    );
});

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
                    selected: item.exactMatch
                        ? url === item.url
                        : url.startsWith(item.url),
                }))}
            />
        </Navigation>
    );

    const topBar = (
        <TopBar
            showNavigationToggle
            onNavigationToggle={() =>
                setMobileNavigationActive((active) => !active)
            }
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
                                    content: 'Security (2FA)',
                                    icon: LockIcon,
                                    url: '/admin/security',
                                },
                                {
                                    content: 'Sign out',
                                    icon: ExitIcon,
                                    onAction: () =>
                                        router.post('/admin/logout'),
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
