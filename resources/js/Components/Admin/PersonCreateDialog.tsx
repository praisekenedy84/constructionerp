import { AmountInput } from '@/Components/ui/amount-input';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { PasswordInput } from '@/Components/ui/password-input';
import { Project, User } from '@/types';
import { useForm } from '@inertiajs/react';
import { FormEvent, useEffect } from 'react';

type PersonCreateDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    defaultCreateUser: boolean;
    defaultCreateStaff: boolean;
    assignableRoles: string[];
    projects: Project[];
    linkableUsers?: User[];
};

function initialData(
    defaultCreateUser: boolean,
    defaultCreateStaff: boolean,
    assignableRoles: string[],
    projects: Project[],
) {
    return {
        create_user: defaultCreateUser,
        create_staff: defaultCreateStaff,
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        access_role: assignableRoles[0] ?? 'Site Engineer',
        employee_no: '',
        job_role: '',
        pay_structure: 'monthly' as 'daily' | 'monthly',
        daily_rate: '',
        monthly_salary: '',
        project_id: projects[0]?.id?.toString() ?? '',
        user_id: '',
    };
}

export default function PersonCreateDialog({
    open,
    onOpenChange,
    defaultCreateUser,
    defaultCreateStaff,
    assignableRoles,
    projects,
    linkableUsers = [],
}: PersonCreateDialogProps) {
    const form = useForm(
        initialData(defaultCreateUser, defaultCreateStaff, assignableRoles, projects),
    );

    useEffect(() => {
        if (!open) {
            return;
        }

        form.setData(initialData(defaultCreateUser, defaultCreateStaff, assignableRoles, projects));
        form.clearErrors();
        // Intentionally reset only when the dialog opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    function resetForm() {
        form.setData(initialData(defaultCreateUser, defaultCreateStaff, assignableRoles, projects));
        form.clearErrors();
    }

    function close() {
        if (!confirmDiscardIfDirty(form.isDirty)) {
            return;
        }
        onOpenChange(false);
        form.reset();
        resetForm();
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            project_id: data.create_staff && data.project_id ? Number(data.project_id) : null,
            user_id:
                data.create_staff && !data.create_user && data.user_id
                    ? Number(data.user_id)
                    : null,
            daily_rate:
                data.create_staff && data.pay_structure === 'daily' ? data.daily_rate : null,
            monthly_salary:
                data.create_staff && data.pay_structure === 'monthly'
                    ? data.monthly_salary
                    : null,
            email: data.create_user ? data.email : null,
            password: data.create_user ? data.password : null,
            password_confirmation: data.create_user ? data.password_confirmation : null,
            access_role: data.create_user ? data.access_role : null,
            employee_no: data.create_staff ? data.employee_no : null,
            job_role: data.create_staff ? data.job_role : null,
            pay_structure: data.create_staff ? data.pay_structure : null,
        }));
        form.post('/admin/people', {
            onSuccess: () => {
                form.reset();
                resetForm();
                onOpenChange(false);
            },
        });
    }

    const title =
        form.data.create_user && form.data.create_staff
            ? 'Add user & staff'
            : form.data.create_user
              ? 'Add User'
              : 'Add Staff';

    const description =
        form.data.create_user && form.data.create_staff
            ? 'Create a login account and payroll staff record together.'
            : form.data.create_user
              ? 'Create a login account and assign a role. Optionally add a payroll staff record.'
              : 'Create a payroll staff record. Optionally create or link a login account.';

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => (next ? onOpenChange(true) : close())}
            title={title}
            description={description}
            className="max-w-xl"
        >
            <form onSubmit={submit} className="space-y-4">
                <div className="flex flex-wrap gap-4 rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-sm">
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={form.data.create_user}
                            onChange={(e) => {
                                form.setData('create_user', e.target.checked);
                                if (e.target.checked) {
                                    form.setData('user_id', '');
                                }
                            }}
                        />
                        Create login account
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={form.data.create_staff}
                            onChange={(e) => form.setData('create_staff', e.target.checked)}
                        />
                        Create staff record
                    </label>
                </div>
                {form.errors.create_user && (
                    <p className="text-sm text-red-600">{form.errors.create_user}</p>
                )}

                <div className="space-y-2">
                    <Label htmlFor="person-name">Name</Label>
                    <Input
                        id="person-name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        required
                    />
                    {form.errors.name && (
                        <p className="text-sm text-red-600">{form.errors.name}</p>
                    )}
                </div>

                {form.data.create_user && (
                    <div className="space-y-4 rounded-md border border-slate-200 p-3">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Login account
                        </p>
                        <div className="space-y-2">
                            <Label htmlFor="person-email">Email</Label>
                            <Input
                                id="person-email"
                                type="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                required
                            />
                            {form.errors.email && (
                                <p className="text-sm text-red-600">{form.errors.email}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="person-password">Password</Label>
                            <PasswordInput
                                id="person-password"
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                                required
                            />
                            {form.errors.password && (
                                <p className="text-sm text-red-600">{form.errors.password}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="person-password-confirm">Confirm password</Label>
                            <PasswordInput
                                id="person-password-confirm"
                                value={form.data.password_confirmation}
                                onChange={(e) =>
                                    form.setData('password_confirmation', e.target.value)
                                }
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="person-access-role">Access role</Label>
                            <select
                                id="person-access-role"
                                className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                                value={form.data.access_role}
                                onChange={(e) => form.setData('access_role', e.target.value)}
                            >
                                {assignableRoles.map((role) => (
                                    <option key={role} value={role}>
                                        {role}
                                    </option>
                                ))}
                            </select>
                            {form.errors.access_role && (
                                <p className="text-sm text-red-600">{form.errors.access_role}</p>
                            )}
                        </div>
                    </div>
                )}

                {form.data.create_staff && (
                    <div className="space-y-4 rounded-md border border-slate-200 p-3">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Staff / payroll
                        </p>
                        <div className="space-y-2">
                            <Label htmlFor="person-employee-no">Employee no.</Label>
                            <Input
                                id="person-employee-no"
                                value={form.data.employee_no}
                                onChange={(e) => form.setData('employee_no', e.target.value)}
                                required
                            />
                            {form.errors.employee_no && (
                                <p className="text-sm text-red-600">{form.errors.employee_no}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="person-job-role">Job role</Label>
                            <Input
                                id="person-job-role"
                                value={form.data.job_role}
                                onChange={(e) => form.setData('job_role', e.target.value)}
                                required
                            />
                            {form.errors.job_role && (
                                <p className="text-sm text-red-600">{form.errors.job_role}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="person-project">Project</Label>
                            <select
                                id="person-project"
                                className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                value={form.data.project_id}
                                onChange={(e) => form.setData('project_id', e.target.value)}
                            >
                                {projects.length === 0 ? (
                                    <option value="">No projects available</option>
                                ) : (
                                    projects.map((project) => (
                                        <option key={project.id} value={project.id}>
                                            {project.code} — {project.name}
                                        </option>
                                    ))
                                )}
                            </select>
                            {form.errors.project_id && (
                                <p className="text-sm text-red-600">{form.errors.project_id}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="person-pay-structure">Pay structure</Label>
                            <select
                                id="person-pay-structure"
                                className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                value={form.data.pay_structure}
                                onChange={(e) =>
                                    form.setData(
                                        'pay_structure',
                                        e.target.value as 'daily' | 'monthly',
                                    )
                                }
                            >
                                <option value="monthly">Monthly</option>
                                <option value="daily">Daily</option>
                            </select>
                        </div>
                        {form.data.pay_structure === 'daily' ? (
                            <div className="space-y-2">
                                <Label htmlFor="person-daily-rate">Daily rate</Label>
                                <AmountInput
                                    id="person-daily-rate"
                                    value={form.data.daily_rate}
                                    onValueChange={(v) => form.setData('daily_rate', v)}
                                    required
                                />
                                {form.errors.daily_rate && (
                                    <p className="text-sm text-red-600">{form.errors.daily_rate}</p>
                                )}
                            </div>
                        ) : (
                            <div className="space-y-2">
                                <Label htmlFor="person-monthly-salary">Monthly salary</Label>
                                <AmountInput
                                    id="person-monthly-salary"
                                    value={form.data.monthly_salary}
                                    onValueChange={(v) => form.setData('monthly_salary', v)}
                                    required
                                />
                                {form.errors.monthly_salary && (
                                    <p className="text-sm text-red-600">
                                        {form.errors.monthly_salary}
                                    </p>
                                )}
                            </div>
                        )}
                        {!form.data.create_user && (
                            <div className="space-y-2">
                                <Label htmlFor="person-link-user">
                                    Link to existing user (optional)
                                </Label>
                                <select
                                    id="person-link-user"
                                    className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                    value={form.data.user_id}
                                    onChange={(e) => form.setData('user_id', e.target.value)}
                                >
                                    <option value="">No linked account</option>
                                    {linkableUsers.map((user) => (
                                        <option key={user.id} value={user.id}>
                                            {user.name} ({user.email})
                                        </option>
                                    ))}
                                </select>
                                {form.errors.user_id && (
                                    <p className="text-sm text-red-600">{form.errors.user_id}</p>
                                )}
                            </div>
                        )}
                    </div>
                )}

                <DialogFormActions
                    onCancel={close}
                    processing={form.processing}
                    submitLabel={
                        form.data.create_user && form.data.create_staff
                            ? 'Add user & staff'
                            : form.data.create_user
                              ? 'Add user'
                              : 'Add staff'
                    }
                    processingLabel="Adding…"
                />
            </form>
        </Dialog>
    );
}
