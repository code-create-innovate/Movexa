import { Head, Link, router, usePage } from '@inertiajs/react';
import { Ban, ChevronDown, Download, Film, Gamepad2, Globe, History, Home, House, LogOut, Menu, MonitorSmartphone, Radio, Search, Settings, Shield, Sparkles, Tv } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
import { MovexaIntro } from '@/components/movexa/intro';
import { PickDialog } from '@/components/movexa/pick-dialog';
import { SearchCommand } from '@/components/movexa/search-command';
import { Button } from '@/components/ui/button';
import { useTvNavigation } from '@/hooks/use-tv-navigation';
import { heavyVisualsOk } from '@/lib/device-class';
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

/** Glass shell. `background` (e.g. the poster wall) is rendered outside the frosted panel: backdrop-filter would trap position:fixed. */
export default function MovexaLayout({ title, children, backdrop, background }: { title?: string; children: ReactNode; transparent?: boolean; backdrop?: string | null; background?: ReactNode }) {
    const { auth, profile, homecore, locale, viewAs } = usePage<SharedData>().props;
    const { t } = useT();
    const [searchOpen, setSearchOpen] = useState(false);
    const url = usePage().url;
    useTvNavigation();

    useEffect(() => {
        document.documentElement.classList.toggle('mx-lite', !heavyVisualsOk());
        const onKey = (e: KeyboardEvent) => {
            if ((e.key === 'k' && (e.metaKey || e.ctrlKey)) || (e.key === '/' && !(e.target as HTMLElement)?.closest('input,textarea'))) {
                e.preventDefault();
                setSearchOpen(true);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const can = auth.user?.can;
    const rail = [
        { href: '/home', label: t.home, icon: Home },
        { href: '/search', label: t.search, icon: Search },
        ...(can?.live ? [{ href: '/live', label: t.live, icon: Radio }] : []),
        { href: '/history', label: t.history, icon: History },
        ...(can?.download ? [{ href: '/downloads', label: t.devices.downloads, icon: Download }] : []),
        { href: '/devices', label: t.devices.myDevices, icon: MonitorSmartphone },
        ...(auth.user?.manage && !viewAs ? [{ href: '/admin', label: t.admin, icon: Shield }] : []),
        { href: '/settings/playback', label: t.settings, icon: Settings },
    ];
    const pills = [
        { href: '/home', label: t.home, active: url.startsWith('/home') },
        { href: '/search?type=movie', label: t.movies, active: url.startsWith('/search') && url.includes('type=movie') },
        { href: '/search?type=show', label: t.shows, active: url.startsWith('/search') && url.includes('type=show') },
        ...(can?.live ? [{ href: '/live', label: t.live, active: url.startsWith('/live') }] : []),
        { href: '/history', label: t.history, active: url.startsWith('/history') },
    ];
    const active = (href: string) => (href === '/search' ? url.startsWith('/search') && !/type=(movie|show)/.test(url) : url.startsWith(href));
    const name = auth.user?.name ?? profile?.name ?? '';

    return (
        <div className="relative isolate min-h-dvh text-foreground">
            {profile && !viewAs && <MovexaIntro enabled={profile.settings?.intro !== false} profileId={profile.id} />}
            <Head title={title} />
            <div className="mx-bg" aria-hidden data-testid="glass-bg">{backdrop && <img key={backdrop} src={backdrop} alt="" />}</div>
            {background}

            {/* floating icon rail (tablet / desktop) */}
            <nav aria-label={t.glass.menu} className="mx-glass fixed start-4 top-1/2 z-40 hidden -translate-y-1/2 flex-col gap-2 rounded-full p-2 md:flex" data-testid="rail">
                {rail.map((n) => (
                    <Link key={n.href} href={n.href} title={n.label} aria-label={n.label} aria-current={active(n.href) ? 'page' : undefined}
                        className={cn('flex size-11 items-center justify-center rounded-full text-foreground/70 transition hover:bg-white/10 hover:text-foreground', active(n.href) && 'bg-white/20 text-foreground shadow-inner')}>
                        <n.icon className="size-5" />
                    </Link>
                ))}
            </nav>

            <div className="md:ps-24">
                <div className="mx-glass min-h-dvh md:m-5 md:min-h-[calc(100dvh-2.5rem)] md:rounded-[2rem]" data-testid="glass-panel">
                    <header className="flex flex-wrap items-center gap-3 p-4 md:flex-nowrap md:px-6 md:pt-5 md:pb-2">
                        <Sheet>
                            <SheetTrigger asChild>
                                <Button variant="ghost" size="icon" className="rounded-full md:hidden" aria-label={t.glass.menu}><Menu /></Button>
                            </SheetTrigger>
                            <SheetContent side={locale === 'ar' ? 'right' : 'left'} className="w-72 p-4">
                                <SheetTitle className="mb-4 text-xl font-bold text-primary">Movexa</SheetTitle>
                                <nav className="flex flex-col gap-1">
                                    {rail.map((n) => (
                                        <Link key={n.href} href={n.href} className={cn('flex items-center gap-3 rounded-xl px-3 py-3 text-base hover:bg-accent', active(n.href) && 'bg-accent font-semibold')}>
                                            <n.icon className="size-5" /> {n.label}
                                        </Link>
                                    ))}
                                    {homecore.url && (
                                        <a href={homecore.url} className="mt-4 flex items-center gap-3 rounded-xl px-3 py-3 text-muted-foreground hover:bg-accent"><House className="size-5" /> {t.backToHomecore}</a>
                                    )}
                                </nav>
                            </SheetContent>
                        </Sheet>
                        <Link href="/home" className="flex items-center gap-2 text-xl font-black tracking-tight text-primary lg:hidden"><Film className="size-5" /> Movexa</Link>
                        <button type="button" onClick={() => setSearchOpen(true)} data-testid="search-pill"
                            className="mx-glass-soft hidden h-11 w-64 shrink-0 items-center gap-2 rounded-full px-4 text-sm text-foreground/60 transition hover:text-foreground lg:flex">
                            <Search className="size-4" /> <span className="truncate">{t.glass.searchHint}</span>
                            <kbd className="ms-auto rounded bg-white/10 px-1.5 text-[10px]" dir="ltr">/</kbd>
                        </button>
                        <nav className="mx-no-scrollbar order-last -mx-1 flex w-full gap-1 overflow-x-auto px-1 md:order-none md:mx-0 md:w-auto md:justify-center md:flex-1" data-testid="pills">
                            {pills.map((p) => <Link key={p.href} href={p.href} className="mx-pill" aria-current={p.active ? 'page' : undefined}>{p.label}</Link>)}
                        </nav>
                        <div className="ms-auto flex items-center gap-1">
                            {homecore.url && (
                                <Button asChild variant="ghost" size="icon" className="hidden rounded-full xl:inline-flex" title={t.backToHomecore}><a href={homecore.url} aria-label={t.backToHomecore}><House /></a></Button>
                            )}
                            {profile && !viewAs && <PickDialog />}
                            <Button variant="ghost" size="icon" className="rounded-full lg:hidden" onClick={() => setSearchOpen(true)} aria-label={t.search}><Search /></Button>
                            <Button variant="ghost" size="icon" className="rounded-full" onClick={() => switchLanguage(locale)} aria-label={t.language} title={t.language}><Globe /></Button>
                            {profile && (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <button className="mx-glass-soft flex items-center gap-2 rounded-full p-1 pe-1 transition hover:bg-white/15 sm:pe-3" aria-label={name} data-testid="user-chip">
                                            <ProfileAvatar name={name} color={profile.avatar_color} className="rounded-full" />
                                            <span className="hidden text-start leading-tight sm:block">
                                                <span dir="auto" className="block max-w-32 truncate text-sm font-semibold">{name}</span>
                                                {auth.user?.username && <span className="block text-[11px] text-foreground/60" dir="ltr">@{auth.user.username}</span>}
                                            </span>
                                            <ChevronDown className="hidden size-4 text-foreground/60 sm:block" />
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" className="w-56">
                                        <div className="px-2 py-1.5 text-sm font-semibold" dir="auto">{auth.user?.name}{auth.user?.username && <span className="block text-xs font-normal text-muted-foreground" dir="ltr">@{auth.user.username}</span>}</div>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem asChild><Link href="/settings/profile"><Settings /> {t.settings}</Link></DropdownMenuItem>
                                        <DropdownMenuItem asChild><Link href="/settings/playback"><Sparkles /> {t.visuals.title}</Link></DropdownMenuItem>
                                        {can?.blocks && !viewAs && <DropdownMenuItem asChild><Link href="/blocks"><Ban /> {t.blocks.title}</Link></DropdownMenuItem>}
                                        {can?.live && <DropdownMenuItem asChild><Link href="/live"><Tv /> {t.live}</Link></DropdownMenuItem>}
                                        {can?.download && <DropdownMenuItem asChild><Link href="/downloads"><Download /> {t.devices.downloads}</Link></DropdownMenuItem>}
                                        <DropdownMenuItem asChild><Link href="/remote"><Gamepad2 /> {t.devices.remote}</Link></DropdownMenuItem>
                                        <DropdownMenuItem asChild><Link href="/devices"><MonitorSmartphone /> {t.devices.myDevices}</Link></DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem onClick={() => router.post('/logout')}><LogOut /> {t.logout}</DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                        </div>
                    </header>
                    <main className="px-4 pb-8 md:px-6">{children}</main>
                </div>
            </div>
            {viewAs && (
                <div className="fixed inset-x-0 bottom-0 z-50 flex items-center justify-center gap-3 bg-amber-500 p-2 text-sm text-black">
                    <span dir="auto">{t.blocks.viewingAs} ({profile?.name})</span>
                    <Button size="sm" variant="secondary" onClick={() => router.post('/view-as/exit')}>{t.blocks.exitViewAs}</Button>
                </div>
            )}
            <SearchCommand open={searchOpen} onOpenChange={setSearchOpen} />
        </div>
    );
}
