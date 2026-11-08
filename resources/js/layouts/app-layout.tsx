import type { ReactNode } from 'react';
import MovexaLayout from '@/layouts/movexa-layout';
import type { BreadcrumbItem } from '@/types';

/** Starter-kit settings pages render inside the Movexa shell. */
export default function AppLayout({ children, breadcrumbs }: { children: ReactNode; breadcrumbs?: BreadcrumbItem[] }) {
    return <MovexaLayout title={breadcrumbs?.[breadcrumbs.length - 1]?.title}>{children}</MovexaLayout>;
}
