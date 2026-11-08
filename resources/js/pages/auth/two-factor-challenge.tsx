import { Head, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AuthLayout from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';

export default function TwoFactorChallenge() {
    const { t } = useT();
    const form = useForm({ code: '' });
    return (
        <AuthLayout title={t.twoFactor.title} description={t.twoFactor.code}>
            <Head title={t.twoFactor.title} />
            <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); form.post('/two-factor-challenge'); }}>
                <Input autoFocus inputMode="numeric" autoComplete="one-time-code" dir="ltr" className="text-center text-xl tracking-widest" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                <InputError message={form.errors.code} />
                <Button type="submit" className="w-full" disabled={form.processing}>{t.twoFactor.verify}</Button>
            </form>
        </AuthLayout>
    );
}
