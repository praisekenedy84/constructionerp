import ThemeToggle from '@/Components/Shared/ThemeToggle';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ArrowRight, Building2, Check, Eye, Loader2, LogOut, Search } from 'lucide-react';
import { useState } from 'react';

interface Company { id: string; name: string; read_only: boolean; identity_id: number; username: string }
const choiceKey = (company: Company) => `${company.identity_id}:${company.id}`;

function initials(name: string) {
    return name.trim().split(/\s+/).slice(0, 2).map(word => word[0]).join('').toUpperCase();
}

export default function Companies({ companies }: { companies: Company[] }) {
    const { auth, uiSettings, flash } = usePage<PageProps>().props;
    const [pending, setPending] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const accounts = companies.filter(company => !company.read_only);
    const home = accounts[0];
    const shared = companies.filter(company => company.read_only);
    const visible = shared.filter(company => company.name.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()));
    const name = auth.user?.name ?? 'there';

    function select(company: Company) {
        if (pending) return;
        setPending(choiceKey(company));
        router.post('/auth/company', { tenant_id: company.id, identity_id: company.identity_id }, { onFinish: () => setPending(null) });
    }

    function signOut() {
        if (pending) return;
        setPending('logout');
        router.post('/logout', {}, { onFinish: () => setPending(null) });
    }

    return <div className="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <Head title="Choose company" />
        <header className="border-b border-slate-200/80 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-5 py-5 sm:px-8">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-700 text-white"><Building2 className="h-5 w-5" aria-hidden="true" /></span>
                    <div className="min-w-0"><p className="truncate text-sm font-bold tracking-tight">{uiSettings.app_name}</p><p className="hidden text-xs text-slate-500 sm:block dark:text-slate-400">Company workspace</p></div>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    <ThemeToggle />
                    <Button variant="ghost" size="sm" onClick={signOut} disabled={pending !== null}>
                        {pending === 'logout' ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : <LogOut className="h-4 w-4" aria-hidden="true" />} Sign out
                    </Button>
                </div>
            </div>
        </header>

        <main className="mx-auto max-w-6xl px-5 py-10 sm:px-8 sm:py-16">
            <div className="mb-9 max-w-2xl">
                <p className="mb-3 text-sm font-medium text-blue-700 dark:text-blue-400">Welcome, {name}</p>
                <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">Choose your company</h1>
                <p className="mt-3 text-base leading-relaxed text-slate-500 dark:text-slate-400">Choose a company to work with your assigned role, or open a shared company in read-only mode.</p>
            </div>
            {flash?.error && <p role="alert" className="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{flash.error}</p>}

            <div className="grid items-start gap-8 lg:grid-cols-5 lg:gap-10">
                <section aria-labelledby="main-company-heading" className="lg:col-span-2">
                    <h2 id="main-company-heading" className="mb-4 text-xs font-semibold uppercase tracking-widest text-slate-500 dark:text-slate-400">Your company accounts</h2>
                    {home ? <button type="button" onClick={() => select(home)} disabled={pending !== null} aria-busy={pending === choiceKey(home)}
                        className="group flex w-full flex-col rounded-2xl bg-blue-950 p-6 text-left text-white shadow-lg shadow-blue-950/10 transition hover:bg-blue-900 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-400 focus-visible:ring-offset-4 disabled:cursor-wait disabled:opacity-70 sm:p-8 dark:bg-blue-900 dark:hover:bg-blue-800 dark:focus-visible:ring-offset-slate-950">
                        <div className="mb-8 flex w-full items-center justify-between gap-3">
                            <span className="flex h-14 w-14 items-center justify-center rounded-2xl border border-white/20 bg-white/10 text-lg font-semibold">{initials(home.name)}</span>
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-blue-100"><Check className="h-3 w-3" aria-hidden="true" /> Role-based access</span>
                        </div>
                        <h3 className="break-words text-2xl font-semibold tracking-tight">{home.name}</h3><p className="mt-1 text-xs text-blue-200">{home.username}</p>
                        <p className="mt-3 text-sm leading-relaxed text-blue-200">Your everyday workspace, with your assigned roles and permissions.</p>
                        <span className="mt-8 flex w-full items-center justify-between border-t border-white/15 pt-5 text-sm font-medium">
                            {pending === choiceKey(home) ? 'Opening workspace...' : 'Enter workspace'}
                            {pending === choiceKey(home) ? <Loader2 className="h-5 w-5 animate-spin" aria-hidden="true" /> : <ArrowRight className="h-5 w-5 transition-transform group-hover:translate-x-1 motion-reduce:transform-none" aria-hidden="true" />}
                        </span>
                    </button> : <p className="rounded-2xl border border-dashed border-slate-300 p-6 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">Your main company is currently unavailable.</p>}
                    {accounts.slice(1).map(company => <button key={choiceKey(company)} type="button" onClick={() => select(company)} disabled={pending !== null}
                        className="mt-3 flex w-full items-center gap-4 rounded-2xl border border-blue-200 bg-white p-5 text-left transition hover:border-blue-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 disabled:opacity-60 dark:border-blue-900 dark:bg-slate-900">
                        <Building2 className="h-6 w-6 shrink-0 text-blue-600 dark:text-blue-400" aria-hidden="true" />
                        <span className="min-w-0 flex-1"><span className="block break-words font-semibold">{company.name}</span><span className="mt-1 block text-xs text-slate-500 dark:text-slate-400">{company.username} - Role-based access</span></span>
                        {pending === choiceKey(company) ? <Loader2 className="h-5 w-5 animate-spin" aria-hidden="true" /> : <ArrowRight className="h-5 w-5 shrink-0" aria-hidden="true" />}
                    </button>)}
                </section>

                <section aria-labelledby="shared-company-heading" className="lg:col-span-3">
                    <div className="mb-4 flex items-center gap-2">
                        <h2 id="shared-company-heading" className="text-xs font-semibold uppercase tracking-widest text-slate-500 dark:text-slate-400">Shared with you</h2>
                        <span className="rounded-full bg-slate-200/70 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-400">{shared.length}</span>
                    </div>
                    {shared.length > 5 && <div className="relative mb-4">
                        <Search className="pointer-events-none absolute left-3 top-3 h-4 w-4 text-slate-400" aria-hidden="true" />
                        <Input aria-label="Search shared companies" placeholder="Find a company..." className="pl-9" value={search} onChange={event => setSearch(event.target.value)} />
                    </div>}
                    <div className="space-y-3">
                        {visible.map(company => <button key={choiceKey(company)} type="button" onClick={() => select(company)} disabled={pending !== null} aria-busy={pending === choiceKey(company)}
                            className="group flex w-full items-center gap-4 rounded-2xl border border-slate-200 bg-white p-5 text-left transition hover:border-blue-300 hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-blue-700 dark:focus-visible:ring-offset-slate-950">
                            <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-sm font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{initials(company.name)}</span>
                            <div className="min-w-0 flex-1">
                                <h3 className="break-words text-sm font-semibold sm:text-base">{company.name}</h3><p className="mt-1 text-xs text-slate-500 dark:text-slate-400">Via {company.username}</p>
                                <span className="mt-2 inline-flex items-center gap-1.5 rounded-md bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700 dark:bg-amber-950/50 dark:text-amber-400"><Eye className="h-3 w-3" aria-hidden="true" /> Read-only access</span>
                            </div>
                            {pending === choiceKey(company) ? <Loader2 className="h-5 w-5 shrink-0 animate-spin text-blue-600" aria-hidden="true" /> : <ArrowRight className="h-5 w-5 shrink-0 text-slate-400 transition-transform group-hover:translate-x-1 group-hover:text-blue-600 motion-reduce:transform-none" aria-hidden="true" />}
                        </button>)}
                        {shared.length === 0 && <div className="rounded-2xl border border-dashed border-slate-300 p-8 dark:border-slate-700">
                            <Eye className="mb-4 h-7 w-7 text-slate-400" aria-hidden="true" /><h3 className="text-sm font-medium">No shared companies yet</h3>
                            <p className="mt-2 text-sm leading-relaxed text-slate-500 dark:text-slate-400">Companies shared with you will appear here.</p>
                        </div>}
                        {shared.length > 0 && visible.length === 0 && <p className="rounded-xl border border-dashed border-slate-300 p-6 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">No companies match your search.</p>}
                    </div>
                    {shared.length > 0 && <p className="mt-5 flex items-start gap-2 text-xs leading-relaxed text-slate-500 dark:text-slate-400"><Eye className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />Shared company access lets you view records. Changes and downloads are unavailable.</p>}
                </section>
            </div>
            <p aria-live="polite" className="sr-only">{pending && pending !== 'logout' ? 'Opening company workspace. Please wait.' : ''}</p>
        </main>
        <footer className="mx-auto max-w-6xl px-5 pb-8 text-xs text-slate-400 sm:px-8 dark:text-slate-500">{uiSettings.tagline}</footer>
    </div>;
}
