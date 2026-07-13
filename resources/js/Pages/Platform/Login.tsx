import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { Label } from '@/Components/ui/label';
import { LinkButton } from '@/Components/Shared/LinkButton';
import ThemeToggle from '@/Components/Shared/ThemeToggle';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import { FormEvent } from 'react';

export default function PlatformLogin() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/platform/login');
    }

    return (
        <>
            <Head title="Platform sign in" />
            <div className="relative flex min-h-screen items-center justify-center bg-slate-50 px-6 dark:bg-slate-950">
                <div className="absolute right-4 top-4">
                    <ThemeToggle className="text-slate-500 dark:text-slate-400" />
                </div>
                <div className="w-full max-w-md space-y-8 rounded-xl border border-slate-200 bg-white p-8 shadow-2xl dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex items-center gap-3 text-slate-900 dark:text-white">
                        <Shield className="h-8 w-8 text-violet-600 dark:text-violet-400" />
                        <div>
                            <h1 className="text-xl font-bold">Platform Administration</h1>
                            <p className="text-sm text-slate-500 dark:text-slate-400">System-wide oversight and control</p>
                        </div>
                    </div>

                    <form onSubmit={submit} className="space-y-6">
                        <div className="space-y-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                autoFocus
                                required
                            />
                            {errors.email && <p className="text-sm text-red-600 dark:text-red-400">{errors.email}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="password">Password</Label>
                            <PasswordInput
                                id="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                required
                            />
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

                        <Button type="submit" className="w-full bg-violet-600 hover:bg-violet-700" disabled={processing}>
                            {processing ? 'Signing in…' : 'Sign in to platform'}
                        </Button>
                    </form>

                    <div className="text-center">
                        <LinkButton href="/login" variant="ghost" size="default">
                            Tenant user sign in
                        </LinkButton>
                    </div>
                </div>
            </div>
        </>
    );
}
