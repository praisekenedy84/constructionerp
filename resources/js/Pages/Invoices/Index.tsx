import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { DialogFormActions, FormErrorSummary } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency, formatDate } from '@/lib/formatters';
import {
    Customer,
    Invoice,
    InvoiceStatus,
    ListingFilters,
    PageProps,
    Paginated,
    Project,
    ProjectPhase,
} from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Banknote, FileSignature, Plus, Printer, Receipt, Send } from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';

interface ProjectWithPhases extends Project {
    phases?: ProjectPhase[];
}

interface CustomerWithProjects extends Customer {
    projects?: ProjectWithPhases[];
}

interface InvoicePageProps extends PageProps {
    invoices: Paginated<Invoice>;
    customers: CustomerWithProjects[];
    projects: Array<Pick<Project, 'id' | 'customer_id' | 'code' | 'name'>>;
    filters: ListingFilters & {
        customer_id?: string;
        project_id?: string;
        status?: string;
        payment_status?: string;
    };
}

interface PaymentForm {
    receipt_number: string;
    payment_date: string;
    amount_paid: string;
    payment_method: string;
    receipt_file: File | null;
    notes: string;
}

interface SignatureForm {
    signature_type: 'prepared_by' | 'approved_by';
    signature_file: File | null;
    signed_date: string;
}

const today = new Date().toISOString().split('T')[0];
const dueDate = new Date(Date.now() + 30 * 86400000).toISOString().split('T')[0];
const statuses: InvoiceStatus[] = [
    'draft',
    'issued',
    'printed',
    'partially_paid',
    'paid',
    'overdue',
];

export default function InvoiceIndex() {
    const { invoices, customers, projects, filters, auth } =
        usePage<InvoicePageProps>().props;
    const rows = invoices.data ?? [];
    const permissions = auth.user?.permissions ?? [];
    const can = (permission: string) => permissions.includes(permission);

    const [createOpen, setCreateOpen] = useState(false);
    const [payable, setPayable] = useState<Invoice | null>(null);
    const [signable, setSignable] = useState<Invoice | null>(null);

    const form = useForm({
        customer_id: '',
        project_id: '',
        phase_id: '',
        invoice_date: today,
        due_date: dueDate,
        description: '',
        tax_mode: 'inclusive' as 'exclusive' | 'inclusive',
        tax_type: 'VAT',
        tax_rate: '18',
        deduction_type: 'Withholding Tax',
        deduction_rate: '0',
        status: 'draft' as 'draft' | 'issued',
    });
    const paymentForm = useForm<PaymentForm>({
        receipt_number: '',
        payment_date: today,
        amount_paid: '',
        payment_method: '',
        receipt_file: null,
        notes: '',
    });
    const signatureForm = useForm<SignatureForm>({
        signature_type: 'prepared_by',
        signature_file: null,
        signed_date: today,
    });

    const customer = customers.find((item) => String(item.id) === form.data.customer_id);
    const customerProjects = customer?.projects ?? [];
    const project = customerProjects.find((item) => String(item.id) === form.data.project_id);
    const phases = project?.phases ?? [];
    const phase = phases.find((item) => String(item.id) === form.data.phase_id);

    const totals = useMemo(() => {
        const phaseAmount = Number(phase?.disbursed_amount ?? 0);
        const taxRate = Number(form.data.tax_rate) || 0;
        const deductionRate = Number(form.data.deduction_rate) || 0;
        let amountBeforeTax = phaseAmount;
        let tax = 0;
        let grossWithTax = phaseAmount;

        if (form.data.tax_mode === 'inclusive' && taxRate > 0) {
            amountBeforeTax = phaseAmount / (1 + taxRate / 100);
            tax = phaseAmount - amountBeforeTax;
            grossWithTax = phaseAmount;
        } else {
            tax = amountBeforeTax * (taxRate / 100);
            grossWithTax = amountBeforeTax + tax;
        }

        const deduction = amountBeforeTax * (deductionRate / 100);

        return {
            amount: amountBeforeTax,
            tax,
            deduction,
            total: grossWithTax - deduction,
        };
    }, [phase, form.data.tax_mode, form.data.tax_rate, form.data.deduction_rate]);

    function submitInvoice(event: FormEvent) {
        event.preventDefault();
        form.post('/invoices', {
            onSuccess: () => {
                setCreateOpen(false);
                form.reset();
            },
        });
    }

    function openPayment(invoice: Invoice) {
        paymentForm.setData({
            receipt_number: '',
            payment_date: today,
            amount_paid: invoice.outstanding_amount,
            payment_method: '',
            receipt_file: null,
            notes: '',
        });
        paymentForm.clearErrors();
        setPayable(invoice);
    }

    function submitPayment(event: FormEvent) {
        event.preventDefault();
        if (!payable) return;
        paymentForm.post(`/invoices/${payable.id}/payments`, {
            forceFormData: true,
            onSuccess: () => {
                setPayable(null);
                paymentForm.reset();
            },
        });
    }

    function openSignature(invoice: Invoice) {
        signatureForm.reset();
        signatureForm.clearErrors();
        setSignable(invoice);
    }

    function submitSignature(event: FormEvent) {
        event.preventDefault();
        if (!signable) return;
        signatureForm.post(`/invoices/${signable.id}/signatures`, {
            forceFormData: true,
            onSuccess: () => {
                setSignable(null);
                signatureForm.reset();
            },
        });
    }

    return (
        <AppShell title="Invoices">
            <Head title="Invoices" />
            <div className="space-y-6">
                <PageHeader
                    title="Invoices"
                    description="Create and follow project phase invoices, payments, receipts, and signatures."
                    actions={can('invoices:create') && (
                        <Button onClick={() => setCreateOpen(true)}>
                            <Plus className="mr-2 h-4 w-4" />
                            New Invoice
                        </Button>
                    )}
                />

                <ListToolbar
                    baseUrl="/invoices"
                    filters={filters}
                    searchPlaceholder="Search invoice, customer, or project…"
                    sortOptions={[
                        { value: 'invoice_date', label: 'Invoice date' },
                        { value: 'due_date', label: 'Due date' },
                        { value: 'total_amount', label: 'Amount' },
                        { value: 'status', label: 'Status' },
                    ]}
                    selectFilters={[
                        {
                            key: 'customer_id',
                            label: 'Customer',
                            emptyLabel: 'All customers',
                            options: customers.map((item) => ({
                                value: String(item.id),
                                label: item.name,
                            })),
                        },
                        {
                            key: 'project_id',
                            label: 'Project',
                            emptyLabel: 'All projects',
                            options: projects.map((item) => ({
                                value: String(item.id),
                                label: `${item.code} — ${item.name}`,
                            })),
                        },
                        {
                            key: 'status',
                            label: 'Status',
                            emptyLabel: 'All statuses',
                            options: statuses.map((status) => ({
                                value: status,
                                label: status.replace(/_/g, ' '),
                            })),
                        },
                        {
                            key: 'payment_status',
                            label: 'Payment',
                            emptyLabel: 'All payment statuses',
                            options: ['unpaid', 'partially_paid', 'paid'].map((status) => ({
                                value: status,
                                label: status.replace(/_/g, ' '),
                            })),
                        },
                    ]}
                />

                <DataPanel title="Invoice List" noPadding>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500 dark:border-slate-700 dark:bg-slate-800/60">
                                    <th className="px-5 py-3">Invoice No.</th>
                                    <th className="px-5 py-3">Customer / Project</th>
                                    <th className="px-5 py-3">Date / Due</th>
                                    <th className="px-5 py-3 text-right">Amount</th>
                                    <th className="px-5 py-3 text-right">Outstanding</th>
                                    <th className="px-5 py-3">Status</th>
                                    <th className="px-5 py-3">Pending</th>
                                    <th className="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {rows.length === 0 ? (
                                    <tr>
                                        <td colSpan={8} className="px-5 py-12 text-center text-slate-500">
                                            No invoices match the current filters.
                                        </td>
                                    </tr>
                                ) : rows.map((invoice) => (
                                    <tr key={invoice.id}>
                                        <td className="px-5 py-4 align-top">
                                            <div className="font-mono font-semibold">{invoice.invoice_number}</div>
                                            <div className="mt-1 text-xs text-slate-500">{invoice.phase?.name}</div>
                                        </td>
                                        <td className="px-5 py-4 align-top">
                                            <div className="font-medium">{invoice.customer?.name}</div>
                                            <div className="text-xs text-slate-500">
                                                {invoice.project?.code} — {invoice.project?.name}
                                            </div>
                                        </td>
                                        <td className="px-5 py-4 align-top">
                                            <div>{formatDate(invoice.invoice_date)}</div>
                                            <div className="text-xs text-slate-500">Due {formatDate(invoice.due_date)}</div>
                                        </td>
                                        <td className="px-5 py-4 text-right font-medium">
                                            {formatCurrency(invoice.total_amount)}
                                        </td>
                                        <td className="px-5 py-4 text-right">
                                            <div className="font-semibold text-amber-700 dark:text-amber-400">
                                                {formatCurrency(invoice.outstanding_amount)}
                                            </div>
                                            <div className="text-xs text-slate-500">
                                                Paid {formatCurrency(invoice.paid_amount)}
                                            </div>
                                        </td>
                                        <td className="px-5 py-4 align-top">
                                            <StatusBadge status={invoice.display_status} />
                                        </td>
                                        <td className="px-5 py-4 align-top">{invoice.pending_days} days</td>
                                        <td className="px-5 py-4">
                                            <div className="flex justify-end gap-1">
                                                {invoice.status === 'draft' && can('invoices:issue') && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => router.post(`/invoices/${invoice.id}/issue`)}
                                                        title="Issue invoice"
                                                    >
                                                        <Send className="h-4 w-4" />
                                                    </Button>
                                                )}
                                                {invoice.status !== 'draft' && can('invoices:print') && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => window.location.assign(`/invoices/${invoice.id}/pdf`)}
                                                        title="Download PDF"
                                                    >
                                                        <Printer className="h-4 w-4" />
                                                    </Button>
                                                )}
                                                {Number(invoice.outstanding_amount) > 0 &&
                                                    invoice.status !== 'draft' &&
                                                    can('invoices:collect') && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => openPayment(invoice)}
                                                            title="Record payment"
                                                        >
                                                            <Banknote className="h-4 w-4" />
                                                        </Button>
                                                    )}
                                                {can('invoices:sign') && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => openSignature(invoice)}
                                                        title="Digital signature"
                                                    >
                                                        <FileSignature className="h-4 w-4" />
                                                    </Button>
                                                )}
                                            </div>
                                            {(invoice.payments?.length ?? 0) > 0 && (
                                                <div className="mt-2 flex justify-end gap-2">
                                                    {invoice.payments?.filter((payment) => payment.receipt_url).map((payment) => (
                                                        <a
                                                            key={payment.id}
                                                            href={payment.receipt_url ?? '#'}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="inline-flex items-center gap-1 text-xs text-blue-600 hover:underline"
                                                        >
                                                            <Receipt className="h-3 w-3" />
                                                            {payment.receipt_number}
                                                        </a>
                                                    ))}
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <PaginationLinks paginator={invoices} />
                </DataPanel>
            </div>

            <Dialog
                open={createOpen}
                onOpenChange={setCreateOpen}
                title="Create Invoice"
                description="Select a customer, project, and phase. The phase amount is loaded automatically."
                className="max-w-4xl"
            >
                <form onSubmit={submitInvoice} className="space-y-5">
                    <FormErrorSummary errors={form.errors as Record<string, string | undefined>} />
                    <div className="grid gap-4 sm:grid-cols-3">
                        <SelectField
                            id="invoice-customer"
                            label="Customer"
                            value={form.data.customer_id}
                            onChange={(value) => {
                                form.setData((data) => ({ ...data, customer_id: value, project_id: '', phase_id: '' }));
                            }}
                            options={customers.map((item) => ({ value: String(item.id), label: item.name }))}
                            placeholder="Select customer"
                        />
                        <SelectField
                            id="invoice-project"
                            label="Project"
                            value={form.data.project_id}
                            onChange={(value) => form.setData((data) => ({ ...data, project_id: value, phase_id: '' }))}
                            options={customerProjects.map((item) => ({
                                value: String(item.id),
                                label: `${item.code} — ${item.name}`,
                            }))}
                            placeholder="Select project"
                            disabled={!customer}
                        />
                        <SelectField
                            id="invoice-phase"
                            label="Phase"
                            value={form.data.phase_id}
                            onChange={(value) => form.setData('phase_id', value)}
                            options={phases.map((item) => ({
                                value: String(item.id),
                                label: `${item.name} — ${formatCurrency(item.disbursed_amount)}`,
                            }))}
                            placeholder="Select phase"
                            disabled={!project}
                        />
                    </div>

                    {customer && (
                        <div className="grid gap-3 rounded-lg border border-slate-200 p-4 sm:grid-cols-4 dark:border-slate-700">
                            <Info label="Customer" value={customer.name} />
                            <Info label="Phone" value={customer.contact} />
                            <Info label="Location" value={customer.address} />
                            <Info label="TIN" value={customer.tax_information} />
                        </div>
                    )}

                    {project && (
                        <div className="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 sm:grid-cols-4 dark:border-slate-700 dark:bg-slate-800/50">
                            <Info label="Location" value={project.location} />
                            <Info label="Contract Amount" value={formatCurrency(project.contract_amount)} />
                            <Info label="Project Status" value={project.status.replace(/_/g, ' ')} />
                            <Info label="Phase Amount" value={formatCurrency(phase?.disbursed_amount ?? 0)} />
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Invoice Date">
                            <Input type="date" value={form.data.invoice_date} onChange={(event) => form.setData('invoice_date', event.target.value)} required />
                        </Field>
                        <Field label="Due Date">
                            <Input type="date" value={form.data.due_date} onChange={(event) => form.setData('due_date', event.target.value)} required />
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Tax Mode">
                            <select
                                className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                                value={form.data.tax_mode}
                                onChange={(event) =>
                                    form.setData(
                                        'tax_mode',
                                        event.target.value as 'exclusive' | 'inclusive',
                                    )
                                }
                            >
                                <option value="inclusive">Tax inclusive (extract tax from phase amount)</option>
                                <option value="exclusive">Tax exclusive (add tax on top)</option>
                            </select>
                            <p className="text-xs text-slate-500">
                                {form.data.tax_mode === 'inclusive'
                                    ? 'Phase amount already includes tax. Net and tax are extracted.'
                                    : 'Phase amount is before tax. Tax is added on top.'}
                            </p>
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid grid-cols-[1fr_110px] gap-2">
                            <Field label="Added Tax Type">
                                <Input value={form.data.tax_type} onChange={(event) => form.setData('tax_type', event.target.value)} placeholder="VAT" />
                            </Field>
                            <Field label="Rate %">
                                <Input type="number" min="0" max="100" step="0.01" value={form.data.tax_rate} onChange={(event) => form.setData('tax_rate', event.target.value)} />
                            </Field>
                        </div>
                        <div className="grid grid-cols-[1fr_110px] gap-2">
                            <Field label="Deduction Type">
                                <Input value={form.data.deduction_type} onChange={(event) => form.setData('deduction_type', event.target.value)} placeholder="Withholding Tax" />
                            </Field>
                            <Field label="Rate %">
                                <Input type="number" min="0" max="100" step="0.01" value={form.data.deduction_rate} onChange={(event) => form.setData('deduction_rate', event.target.value)} />
                            </Field>
                        </div>
                    </div>

                    <Field label="Description">
                        <textarea
                            className="min-h-20 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                            placeholder="Invoice description or payment milestone"
                        />
                    </Field>

                    <div className="grid gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 sm:grid-cols-4 dark:border-blue-900 dark:bg-blue-950/30">
                        <Info label="Before Tax" value={formatCurrency(totals.amount)} />
                        <Info label="Tax" value={formatCurrency(totals.tax)} />
                        <Info label="Deduction" value={`-${formatCurrency(totals.deduction)}`} />
                        <Info label="Invoice Total" value={formatCurrency(totals.total)} strong />
                    </div>

                    <div className="flex items-center gap-2">
                        <input
                            id="issue-now"
                            type="checkbox"
                            checked={form.data.status === 'issued'}
                            onChange={(event) => form.setData('status', event.target.checked ? 'issued' : 'draft')}
                        />
                        <Label htmlFor="issue-now">Issue immediately (otherwise save as draft)</Label>
                    </div>

                    <DialogFormActions
                        onCancel={() => setCreateOpen(false)}
                        processing={form.processing}
                        submitLabel="Create Invoice"
                        processingLabel="Creating…"
                        disabled={!phase || totals.total <= 0}
                    />
                </form>
            </Dialog>

            <Dialog open={payable !== null} onOpenChange={(open) => !open && setPayable(null)} title={`Record Payment — ${payable?.invoice_number ?? ''}`}>
                <form onSubmit={submitPayment} className="space-y-4">
                    <FormErrorSummary errors={paymentForm.errors as Record<string, string | undefined>} />
                    <div className="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800">
                        Outstanding: <strong>{formatCurrency(payable?.outstanding_amount)}</strong>
                    </div>
                    <Field label="Receipt Number">
                        <Input value={paymentForm.data.receipt_number} onChange={(event) => paymentForm.setData('receipt_number', event.target.value)} required />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Payment Date">
                            <Input type="date" value={paymentForm.data.payment_date} onChange={(event) => paymentForm.setData('payment_date', event.target.value)} required />
                        </Field>
                        <Field label="Amount Paid">
                            <AmountInput value={paymentForm.data.amount_paid} onValueChange={(value) => paymentForm.setData('amount_paid', value)} required />
                        </Field>
                    </div>
                    <Field label="Payment Method">
                        <select className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" value={paymentForm.data.payment_method} onChange={(event) => paymentForm.setData('payment_method', event.target.value)} required>
                            <option value="">Select method</option>
                            <option value="cash">Cash</option>
                            <option value="bank">Bank Transfer</option>
                            <option value="mobile">Mobile Money</option>
                            <option value="cheque">Cheque</option>
                        </select>
                    </Field>
                    <Field label="Receipt / Payment Proof (optional)">
                        <Input type="file" accept=".pdf,image/*" onChange={(event) => paymentForm.setData('receipt_file', event.target.files?.[0] ?? null)} />
                    </Field>
                    <DialogFormActions onCancel={() => setPayable(null)} processing={paymentForm.processing} submitLabel="Record Payment" processingLabel="Recording…" />
                </form>
            </Dialog>

            <Dialog open={signable !== null} onOpenChange={(open) => !open && setSignable(null)} title={`Digital Signature — ${signable?.invoice_number ?? ''}`}>
                <form onSubmit={submitSignature} className="space-y-4">
                    <FormErrorSummary errors={signatureForm.errors as Record<string, string | undefined>} />
                    <Field label="Signature Role">
                        <select className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" value={signatureForm.data.signature_type} onChange={(event) => signatureForm.setData('signature_type', event.target.value as SignatureForm['signature_type'])}>
                            <option value="prepared_by">Prepared By</option>
                            <option value="approved_by">Approved By</option>
                        </select>
                    </Field>
                    <Field label="Signature Image">
                        <Input type="file" accept="image/*" onChange={(event) => signatureForm.setData('signature_file', event.target.files?.[0] ?? null)} required />
                    </Field>
                    <Field label="Signature Date">
                        <Input type="date" value={signatureForm.data.signed_date} onChange={(event) => signatureForm.setData('signed_date', event.target.value)} />
                    </Field>
                    <DialogFormActions onCancel={() => setSignable(null)} processing={signatureForm.processing} submitLabel="Save Signature" processingLabel="Saving…" disabled={!signatureForm.data.signature_file} />
                </form>
            </Dialog>
        </AppShell>
    );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return <div className="space-y-2"><Label>{label}</Label>{children}</div>;
}

function Info({ label, value, strong = false }: { label: string; value: React.ReactNode; strong?: boolean }) {
    return <div><div className="text-xs text-slate-500">{label}</div><div className={strong ? 'font-bold text-blue-700 dark:text-blue-300' : 'font-semibold'}>{value || '—'}</div></div>;
}

function SelectField({
    id, label, value, onChange, options, placeholder, disabled = false,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: Array<{ value: string; label: string }>;
    placeholder: string;
    disabled?: boolean;
}) {
    return (
        <Field label={label}>
            <select
                id={id}
                className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm disabled:opacity-50 dark:border-slate-700 dark:bg-slate-800"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                disabled={disabled}
                required
            >
                <option value="">{placeholder}</option>
                {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </select>
        </Field>
    );
}
