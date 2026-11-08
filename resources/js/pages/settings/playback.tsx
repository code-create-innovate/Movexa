import { useState } from 'react';
import { Switch } from '@/components/ui/switch';
import MovexaLayout from '@/layouts/movexa-layout';
import { api, useT } from '@/lib/i18n';
import type { ProfileSettings } from '@/types';

/** Per-profile viewing settings (saved on the profile). */
export default function PlaybackSettings({ settings: initial }: { settings: ProfileSettings }) {
    const { t } = useT();
    const [s, setS] = useState(initial);
    const save = (patch: Partial<ProfileSettings>) => { const next = { ...s, ...patch }; setS(next); api('/profile/preferences', 'PUT', { settings: patch }); };
    const row = (label: string, key: 'intro' | 'pause_screen' | 'poster_wall' | 'recap') => (
        <label className="flex items-center justify-between gap-4 rounded-lg border bg-card p-4">
            <span>{label}</span>
            <Switch checked={s[key]} onCheckedChange={(v) => save({ [key]: v } as Partial<ProfileSettings>)} aria-label={label} />
        </label>
    );
    return (
        <MovexaLayout title={t.visuals.title}>
            <div className="mx-auto max-w-xl space-y-3 p-6">
                <h1 className="mb-4 text-2xl font-bold">{t.visuals.title}</h1>
                {row(t.visuals.intro, 'intro')}
                {row(t.visuals.pauseScreen, 'pause_screen')}
                {row(t.visuals.posterWall, 'poster_wall')}
                {row(t.visuals.recap, 'recap')}
                <div className="flex items-center justify-between gap-4 rounded-lg border bg-card p-4">
                    <span>{t.visuals.ambient}</span>
                    <div className="flex gap-1">
                        {(['off', 'soft', 'strong'] as const).map((a) => (
                            <button key={a} onClick={() => save({ ambient: a })} className={`rounded-md px-3 py-1.5 text-sm ${s.ambient === a ? 'bg-primary text-primary-foreground' : 'bg-secondary'}`}>{t.visuals[a]}</button>
                        ))}
                    </div>
                </div>
            </div>
        </MovexaLayout>
    );
}
