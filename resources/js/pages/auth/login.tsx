import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

type LoginForm = {
    email: string;
    password: string;
    remember: boolean;
}

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}

import { useT } from '@/lib/i18n';

export default function Login({ status, canResetPassword, canRegister }: LoginProps) {
    const { locale } = useT();
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout title={locale === 'ar' ? 'تسجيل الدخول' : 'Sign in'} description={locale === 'ar' ? 'كل فرد يدخل بحسابه الخاص' : 'Everyone signs in with their own account'}>
            <Head title={locale === 'ar' ? 'تسجيل الدخول' : 'Sign in'} />

            <form className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="email">{locale === 'ar' ? 'اسم المستخدم أو البريد' : 'Username or email'}</Label>
                        <Input
                            id="email"
                            type="text"
                            dir="ltr"
                            required
                            autoFocus
                            tabIndex={1}
                            autoComplete="username"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            placeholder="sara"
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <div className="flex items-center">
                            <Label htmlFor="password">{locale === 'ar' ? 'كلمة المرور' : 'Password'}</Label>
                            {canResetPassword && (
                                <TextLink href={route('password.request')} className="ml-auto text-sm" tabIndex={5}>
                                    {locale === 'ar' ? 'نسيت كلمة المرور؟' : 'Forgot password?'}
                                </TextLink>
                            )}
                        </div>
                        <Input
                            id="password"
                            type="password"
                            required
                            tabIndex={2}
                            autoComplete="current-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            placeholder={locale === 'ar' ? 'كلمة المرور' : 'Password'}
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="flex items-center space-x-3">
                        <Checkbox id="remember" name="remember" tabIndex={3} />
                        <Label htmlFor="remember">{locale === 'ar' ? 'تذكرني' : 'Remember me'}</Label>
                    </div>

                    <Button type="submit" className="mt-4 w-full" tabIndex={4} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        {locale === 'ar' ? 'دخول' : 'Sign in'}
                    </Button>
                </div>

                {canRegister && (
                    <div className="text-muted-foreground text-center text-sm">
                        {locale === 'ar' ? 'أول تشغيل؟ ' : 'First run? '}
                        <TextLink href={route('register')} tabIndex={5}>
                            {locale === 'ar' ? 'أنشئ حساب المسؤول' : 'Create the admin account'}
                        </TextLink>
                    </div>
                )}
            </form>

            <a href="/tv" className="text-muted-foreground text-center text-sm underline-offset-4 hover:underline">📺 {locale === 'ar' ? 'الدخول على التلفزيون برمز QR' : 'Sign in on a TV with a QR code'}</a>

            {status && <div className="mb-4 text-center text-sm font-medium text-green-600">{status}</div>}
        </AuthLayout>
    );
}
