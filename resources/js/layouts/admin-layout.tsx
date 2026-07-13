import { AppProvider } from '@shopify/polaris';
import en from '@shopify/polaris/locales/en.json';
import type { ReactNode } from 'react';

import '@shopify/polaris/build/esm/styles.css';

export default function AdminLayout({ children }: { children: ReactNode }) {
    return <AppProvider i18n={en}>{children}</AppProvider>;
}
