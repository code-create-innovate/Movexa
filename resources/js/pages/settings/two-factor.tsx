import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import MovexaLayout from '@/layouts/movexa-layout';
import { useT } from '@/lib/i18n';

export default function TwoFactor({ enabled, setup }: { enabled: boolean; setup: { secret: string; uri: string } | null }) {
    const { t } = useT();
    const pw = useForm({ password: '' });
    const code = useForm({ code: '' });
    return (
        <MovexaLayout title={t.twoFactor.title}>
            <div className="mx-auto max-w-md space-y-6 p-6">
                <h1 className="text-2xl font-bold">{t.twoFactor.title}</h1>
                {enabled && <p className="text-emerald-400">{t.twoFactor.enabled}</p>}
                {setup ? (
                    <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); code.put('/settings/two-factor'); }}>
                        <p className="text-sm">{t.twoFactor.scan}</p>
                        <code dir="ltr" className="block rounded bg-muted p-3 text-center font-mono text-lg tracking-widest break-all select-all">{setup.secret.match(/.{1,4}/g)?.join(' ')}</code>
                        <a dir="ltr" href={setup.uri} className="block truncate text-xs text-muted-foreground underline">{setup.uri}</a>
                        <Label htmlFor="code">{t.twoFactor.code}</Label>
                        <Input id="code" inputMode="numeric" autoComplete="one-time-code" value={code.data.code} onChange={(e) => code.setData('code', e.target.value)} />
                        <InputError message={code.errors.code} />
                        <Button type="submit">{t.twoFactor.verify}</Button>
                    </form>
                ) : (
                    <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); enabled ? pw.delete('/settings/two-factor') : pw.post('/settings/two-factor'); }}>
                        <Label htmlFor="password">{t.twoFactor.password}</Label>
                        <Input id="password" type="password" value={pw.data.password} onChange={(e) => pw.setData('password', e.target.value)} />
                        <InputError message={pw.errors.password} />
                        <Button type="submit" variant={enabled ? 'destructive' : 'default'}>{enabled ? t.twoFactor.disable : t.twoFactor.enable}</Button>
                    </form>
                )}
            </div>
        </MovexaLayout>
    );
}
