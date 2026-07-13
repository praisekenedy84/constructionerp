import { LinkButton } from '@/Components/Shared/LinkButton';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { Label } from '@/Components/ui/label';
import ThemeToggle from '@/Components/Shared/ThemeToggle';
import { PageProps } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { FormEvent } from 'react';

export default function Login() {
    const { uiSettings } = usePage<PageProps>().props;
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/login');
    }

    return (
        <>
            <Head title="Sign in" />
            <div className="relative flex min-h-screen bg-white dark:bg-slate-950">
                <div className="absolute right-4 top-4 z-10">
                    <ThemeToggle className="text-slate-500 dark:text-slate-400" />
                </div>
                <div className="hidden w-1/2 bg-gradient-to-br from-blue-900 via-blue-800 to-slate-900 lg:flex lg:flex-col lg:justify-between lg:p-12">
                    <div className="flex items-center gap-3 text-white">
                        <Building2 className="h-8 w-8" />
                        <span className="text-xl font-bold">{uiSettings.app_name}</span>
                    </div>
                    <div>
                        <h2 className="text-3xl font-bold text-white">
                            Construction Resource &amp; Finance
                        </h2>
                        <p className="mt-4 max-w-md text-blue-100">
                            Multi-tenant ERP for construction companies. Budget control, BOQ
                            reservations, requisitions, and financial integrity built in.
                        </p>
                    </div>
                    <p className="text-sm text-blue-200">{uiSettings.tagline}</p>
                </div>

                <div className="flex w-full items-center justify-center px-6 lg:w-1/2 dark:bg-slate-950">
                    <div className="w-full max-w-md space-y-8">
                        <div className="lg:hidden">
                            <div className="flex items-center gap-2">
                                <Building2 className="h-7 w-7 text-blue-700 dark:text-blue-400" />
                                <span className="text-lg font-bold text-slate-900 dark:text-white">
                                    {uiSettings.app_name}
                                </span>
                            </div>
                        </div>

                        <div>
                            <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Sign in</h1>
                            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                                Enter your email and password to access your account.
                            </p>
                        </div>

                        <form onSubmit={submit} className="space-y-6">
                            <div className="space-y-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    autoComplete="email"
                                    autoFocus
                                    required
                                />
                                {errors.email && (
                                    <p className="text-sm text-red-600">{errors.email}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    autoComplete="current-password"
                                    required
                                />
                                {errors.password && (
                                    <p className="text-sm text-red-600">{errors.password}</p>
                                )}
                            </div>

                            <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                                <input
                                    type="checkbox"
                                    checked={data.remember}
                                    onChange={(e) => setData('remember', e.target.checked)}
                                    className="rounded border-slate-300 dark:border-slate-600"
                                />
                                Remember me
                            </label>

                            <Button type="submit" className="w-full" disabled={processing}>
                                {processing ? 'Signing in…' : 'Sign in'}
                            </Button>
                        </form>
                        <div className="text-center">
                            <LinkButton href="/platform/login" variant="ghost" size="default">
                                Platform administrator sign in
                            </LinkButton>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
