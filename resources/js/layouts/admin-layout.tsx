import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import MovexaLayout from '@/layouts/movexa-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export default function AdminLayout({ title, children }: { title: string; children: ReactNode }) {
    const { t } = useT();
    const url = usePage().url;
    const tabs = [
        ['/admin', t.adminUi.dashboard], ['/admin/quarantine', t.adminUi.quarantine], ['/admin/matches', t.adminUi.matches], ['/admin/live', t.adminUi.liveSources], ['/blocks', t.blocks.title],
    ];
    return (
        <MovexaLayout title={title}>
            <div className="px-4 py-6 md:px-10">
                <nav className="mb-6 flex gap-1 overflow-x-auto border-b scrollbar-none">
                    {tabs.map(([href, label]) => (
                        <Link key={href} href={href} className={cn('border-b-2 border-transparent px-3 py-2 text-sm whitespace-nowrap', (href === '/admin' ? url === '/admin' : url.startsWith(href)) && 'border-primary font-semibold')}>{label}</Link>
                    ))}
                </nav>
                {children}
            </div>
        </MovexaLayout>
    );
}

export function Section({ title, children, actions }: { title: string; children: ReactNode; actions?: ReactNode }) {
    return (
        <section className="mb-8 rounded-lg border bg-card p-4">
            <div className="mb-3 flex items-center gap-2"><h2 className="text-lg font-bold">{title}</h2><div className="ms-auto flex gap-2">{actions}</div></div>
            {children}
        </section>
    );
}

export const bytes = (n: number | null | undefined) => {
    if (!n) return '0 B';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.min(u.length - 1, Math.floor(Math.log(n) / Math.log(1024)));
    return `${(n / 1024 ** i).toFixed(i > 2 ? 1 : 0)} ${u[i]}`;
};
