import { Head, Link, router, usePage } from '@inertiajs/react';
import { Ban, Sparkles, Download, Gamepad2, MonitorSmartphone, Film, Globe, Home, House, List, LogOut, Menu, Radio, Search, Settings, Shield, Tv } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
import { MovexaIntro } from '@/components/movexa/intro';
import { PickDialog } from '@/components/movexa/pick-dialog';
import { SearchCommand } from '@/components/movexa/search-command';
import { Button } from '@/components/ui/button';
import { useTvNavigation } from '@/hooks/use-tv-navigation';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';

export function ProfileAvatar({ name, color, className }: { name: string; color: string; className?: string }) {
    return (
        <span className={cn('inline-flex size-8 items-center justify-center rounded-md text-sm font-bold text-white', className)} style={{ background: color }} aria-hidden>
            {name.slice(0, 1).toUpperCase()}
        </span>
    );
}

export function switchLanguage(current: 'ar' | 'en') {
    window.location.href = `/locale/${current === 'ar' ? 'en' : 'ar'}?back=${encodeURIComponent(location.pathname + location.search)}`;
}

export default function MovexaLayout({ title, children, transparent = false }: { title?: string; children: ReactNode; transparent?: boolean }) {
    const { auth, profile, homecore, locale, viewAs } = usePage<SharedData>().props;
    const { t } = useT();
    const [scrolled, setScrolled] = useState(false);
    const [searchOpen, setSearchOpen] = useState(false);
    const url = usePage().url;
    useTvNavigation();

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 10);
        const onKey = (e: KeyboardEvent) => {
            if ((e.key === 'k' && (e.metaKey || e.ctrlKey)) || (e.key === '/' && !(e.target as HTMLElement)?.closest('input,textarea'))) {
                e.preventDefault();
                setSearchOpen(true);
            }
        };
        window.addEventListener('scroll', onScroll);
        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('scroll', onScroll);
            window.removeEventListener('keydown', onKey);
        };
    }, []);

    const nav = [
        { href: '/home', label: t.home, icon: Home },
        { href: '/search', label: t.search, icon: Search },
        ...(auth.user?.can.live ? [{ href: '/live', label: t.live, icon: Radio }] : []),
        { href: '/history', label: t.history, icon: List },
        ...(auth.user?.manage && !viewAs ? [{ href: '/admin', label: t.admin, icon: Shield }] : []),
    ];

    return (
        <div className="mx-tinted min-h-dvh">
            {profile && !viewAs && <MovexaIntro enabled={profile.settings?.intro !== false} profileId={profile.id} />}
            <Head title={title} />
            <header className={cn('fixed inset-x-0 top-0 z-40 transition-colors', scrolled || !transparent ? 'bg-background/95 shadow-sm backdrop-blur' : 'bg-linear-to-b from-black/80 to-transparent')}>
                <div className="flex h-16 items-center gap-4 px-4 md:px-10">
                    <Sheet>
                        <SheetTrigger asChild>
                            <Button variant="ghost" size="icon" className="md:hidden" aria-label="menu"><Menu /></Button>
                        </SheetTrigger>
                        <SheetContent side={locale === 'ar' ? 'right' : 'left'} className="w-72 p-4">
                            <SheetTitle className="mb-4 text-xl font-bold text-primary">Movexa</SheetTitle>
                            <nav className="flex flex-col gap-1">
                                {nav.map((n) => (
                                    <Link key={n.href} href={n.href} className={cn('flex items-center gap-3 rounded-md px-3 py-3 text-base hover:bg-accent', url.startsWith(n.href) && 'bg-accent font-semibold')}>
                                        <n.icon className="size-5" /> {n.label}
                                    </Link>
                                ))}
                                {homecore.url && (
                                    <a href={homecore.url} className="mt-4 flex items-center gap-3 rounded-md px-3 py-3 text-muted-foreground hover:bg-accent"><House className="size-5" /> {t.backToHomecore}</a>
                                )}
                            </nav>
                        </SheetContent>
                    </Sheet>
                    <Link href="/home" className="flex items-center gap-2 text-2xl font-black tracking-tight text-primary"><Film className="size-6" /> Movexa</Link>
                    <nav className="hidden items-center gap-1 md:flex">
                        {nav.map((n) => (
                            <Link key={n.href} href={n.href} className={cn('rounded-md px-3 py-2 text-sm text-foreground/80 hover:text-foreground', url.startsWith(n.href) && 'font-semibold text-foreground')}>{n.label}</Link>
                        ))}
                    </nav>
                    <div className="ms-auto flex items-center gap-1">
                        {homecore.url && (
                            <Button asChild variant="ghost" size="sm" className="hidden lg:inline-flex"><a href={homecore.url}><House className="size-4" /> {t.backToHomecore}</a></Button>
                        )}
                        {profile && !viewAs && <PickDialog />}
                        <Button variant="ghost" size="icon" onClick={() => setSearchOpen(true)} aria-label={t.search}><Search /></Button>
                        <Button variant="ghost" size="icon" onClick={() => switchLanguage(locale)} aria-label={t.language} title={t.language}><Globe /></Button>
                        {profile && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <button className="rounded-md" aria-label={auth.user?.name ?? profile.name}><ProfileAvatar name={auth.user?.name ?? profile.name} color={profile.avatar_color} /></button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-56">
                                    <div className="px-2 py-1.5 text-sm font-semibold" dir="auto">{auth.user?.name}{auth.user?.username && <span className="block text-xs font-normal text-muted-foreground" dir="ltr">@{auth.user.username}</span>}</div>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem asChild><Link href="/settings/profile"><Settings /> {t.settings}</Link></DropdownMenuItem>
                                    <DropdownMenuItem asChild><Link href="/settings/playback"><Sparkles /> {t.visuals.title}</Link></DropdownMenuItem>
                                    {auth.user?.can.blocks && !viewAs && <DropdownMenuItem asChild><Link href="/blocks"><Ban /> {t.blocks.title}</Link></DropdownMenuItem>}
                                    {auth.user?.can.live && <DropdownMenuItem asChild><Link href="/live"><Tv /> {t.live}</Link></DropdownMenuItem>}
                                    {auth.user?.can.download && <DropdownMenuItem asChild><Link href="/downloads"><Download /> {t.devices.downloads}</Link></DropdownMenuItem>}
                                    <DropdownMenuItem asChild><Link href="/remote"><Gamepad2 /> {t.devices.remote}</Link></DropdownMenuItem>
                                    <DropdownMenuItem asChild><Link href="/devices"><MonitorSmartphone /> {t.devices.myDevices}</Link></DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem onClick={() => router.post('/logout')}><LogOut /> {t.logout}</DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>
                </div>
            </header>
            {viewAs && (
                <div className="fixed inset-x-0 bottom-0 z-50 flex items-center justify-center gap-3 bg-amber-500 p-2 text-sm text-black">
                    <span dir="auto">{t.blocks.viewingAs} ({profile?.name})</span>
                    <Button size="sm" variant="secondary" onClick={() => router.post('/view-as/exit')}>{t.blocks.exitViewAs}</Button>
                </div>
            )}
            <main className={cn(!transparent && 'pt-16')}>{children}</main>
            <SearchCommand open={searchOpen} onOpenChange={setSearchOpen} />
        </div>
    );
}
