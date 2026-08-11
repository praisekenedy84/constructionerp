export interface User {
    id: number;
    name: string;
    email: string;
    roles: string[];
    permissions?: string[];
    can_manage_platform?: boolean;
    can_impersonate?: boolean;
    is_self?: boolean;
    is_locked?: boolean;
}

export interface PlatformAdmin {
    id: number;
    name: string;
    email: string;
}

export interface UiSettings {
    app_name: string;
    tagline: string;
    company_address?: string;
    company_contact?: string;
    company_logo_url?: string;
    nav_overrides?: {
        hidden?: string[];
        role_hidden?: Record<string, string[]>;
        order?: string[];
        child_order?: Record<string, string[]>;
    };
}

export interface NavItem {
    key: string;
    label: string;
    href: string;
    group: string;
    active_path?: string;
    children?: NavItem[];
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links?: Array<{ url: string | null; label: string; active: boolean }>;
}

export type ProjectStatus = 'planning' | 'active' | 'on_hold' | 'closed' | 'loss';

export interface Project {
    id: number;
    code: string;
    name: string;
    client: string;
    customer_id?: number | null;
    customer?: Customer | null;
    location: string;
    contract_amount: string;
    wht_percentage: string;
    net_budget: string;
    pending_deficit?: string;
    live_remaining?: string;
    can_mark_loss?: boolean;
    remaining_budget?: string;
    profit_percentage?: string;
    utilization_percentage?: string;
    remaining_contract_value?: string;
    contract_compliance_total?: string;
    physical_progress_pct: string;
    start_date: string;
    end_date: string;
    status: ProjectStatus;
    created_at?: string;
}

export interface Customer {
    id: number;
    name: string;
    contact: string | null;
    email?: string | null;
    address: string | null;
    tax_information: string | null;
    projects?: Project[];
}

export type InvoiceStatus =
    | 'draft'
    | 'issued'
    | 'printed'
    | 'partially_paid'
    | 'paid'
    | 'overdue';

export interface InvoicePayment {
    id: number;
    receipt_number: string;
    payment_date: string;
    amount_paid: string;
    payment_method: string;
    receipt_file: string | null;
    receipt_url?: string | null;
    notes?: string | null;
    creator?: { id: number; name: string };
}

export interface InvoiceSignature {
    id: number;
    signature_type: 'prepared_by' | 'approved_by';
    signature_file: string;
    signature_url?: string;
    signed_date: string;
    signer?: { id: number; name: string };
}

export interface Invoice {
    id: number;
    invoice_number: string;
    customer_id: number;
    project_id: number;
    phase_id: number;
    invoice_date: string;
    due_date: string;
    description: string | null;
    amount_before_tax: string;
    tax_mode: 'exclusive' | 'inclusive';
    tax_type: string | null;
    tax_rate: string;
    tax_amount: string;
    deduction_type: string | null;
    deduction_rate: string;
    deduction_amount: string;
    total_amount: string;
    paid_amount: string;
    outstanding_amount: string;
    payment_status: 'unpaid' | 'partially_paid' | 'paid';
    status: Exclude<InvoiceStatus, 'overdue'>;
    display_status: InvoiceStatus;
    pending_days: number;
    printed_at?: string | null;
    customer?: Customer;
    project?: Project;
    phase?: ProjectPhase;
    payments?: InvoicePayment[];
    signatures?: InvoiceSignature[];
}

export type SaleStatus = 'open' | 'receivable' | 'partially_paid' | 'paid';

export interface Sale {
    id: number;
    sale_code: string;
    status: SaleStatus;
    status_label: string;
    contract_amount: string;
    profit_amount: string;
    collected_amount: string;
    outstanding_amount: string;
    converted_at?: string | null;
    is_loss?: boolean;
    is_retention_receivable?: boolean;
    can_convert: boolean;
    can_collect: boolean;
    remaining_budget?: string;
    recognized_amount?: string;
    live_remaining?: string;
    recognizable_amount?: string;
    pending_deficit?: string;
    phase_share_pct?: string;
    customer?: string | null;
    project?: (Pick<Project, 'id' | 'code' | 'name' | 'client' | 'contract_amount' | 'net_budget' | 'status'> & {
        pending_deficit?: string;
    }) | null;
    phase?: Pick<
        ProjectPhase,
        'id' | 'sequence_no' | 'name' | 'status' | 'disbursed_amount' | 'phase_net_budget'
    > | null;
    converter?: { id: number; name: string } | null;
}

export interface SaleReceivablePayment {
    id: number;
    sale_id: number;
    amount: string;
    method: string | null;
    reference_no: string | null;
    notes: string | null;
    occurred_at: string | null;
    account?: { id: number; name: string; type: string } | null;
    recorder?: { id: number; name: string } | null;
}

export type PhaseStatus = 'pending' | 'in_progress' | 'succeeded' | 'unsatisfactory' | 'closed';
export type RetentionStatus = 'none' | 'held' | 'released' | 'forfeited';

export interface ProjectPhase {
    id: number;
    project_id: number;
    sequence_no: number;
    name: string;
    status: PhaseStatus;
    disbursed_amount: string;
    retention_held_amount: string;
    retention_released_amount: string;
    retention_receivable_amount?: string;
    retention_forfeited_amount: string;
    other_deductions_amount: string;
    phase_net_budget: string;
    retention_status: RetentionStatus;
    valuations_count?: number;
    valuations_sum_total_deductions?: string | null;
}

export type BoqCategory =
    | 'materials'
    | 'labor'
    | 'equipment'
    | 'fuel'
    | 'transport'
    | 'accommodation'
    | 'subcontractors'
    | 'administration'
    | 'contingencies';

export interface BoqItem {
    id: number;
    section_id: number;
    description: string;
    unit: string;
    category: BoqCategory;
    budgeted_qty: string;
    unit_rate: string;
    budgeted_amount: string;
    reserved_qty: string;
    consumed_qty: string;
    available_qty: string;
    requested_qty?: string;
    approved_qty?: string;
    procured_qty?: string;
    received_qty?: string;
    issued_qty?: string;
}

export interface BoqSection {
    id: number;
    project_id: number;
    name: string;
    display_order: number;
    items: BoqItem[];
}

export type BudgetTransactionType =
    | 'APPROVED_REQUISITION'
    | 'AMENDED_REQUISITION'
    | 'PURCHASE'
    | 'PAYROLL'
    | 'EQUIPMENT_COST'
    | 'FUEL_COST'
    | 'DIRECT_EXPENSE'
    | 'MANUAL_ADJUSTMENT';

export interface BudgetTransaction {
    id: number;
    project_id: number;
    boq_item_id: number | null;
    type: BudgetTransactionType;
    amount: string;
    reason: string | null;
    created_by: number;
    created_at: string;
    creator?: { id: number; name: string };
}

export type RequisitionStatus =
    | 'draft'
    | 'submitted'
    | 'under_review'
    | 'approved'
    | 'amended'
    | 'rejected'
    | 'partially_fulfilled'
    | 'fulfilled'
    | 'closed'
    | 'cancelled';

export type FulfillmentType =
    | 'cash_disbursement'
    | 'stock_issue'
    | 'direct_supplier_payment';

export type RequisitionAddressedTo = 'finance' | 'storekeeper';

export type RequisitionResourceType =
    | 'materials'
    | 'cash'
    | 'equipment'
    | 'labor'
    | 'fuel'
    | 'transport'
    | 'services'
    | 'other';

export interface RequisitionItem {
    id: number;
    requisition_id: number;
    boq_item_id: number | null;
    inventory_item_id?: number | null;
    requisition_category_id?: number | null;
    description: string;
    unit?: string | null;
    quantity: string;
    fulfilled_quantity?: string;
    unit_cost: string;
    line_total: string;
    recipient_id?: number | null;
    recipient_name?: string | null;
    position_id?: number | null;
    recipient_position?: string | null;
    original_quantity?: string | null;
    original_unit_cost?: string | null;
    original_line_total?: string | null;
    original_description?: string | null;
    details?: {
        workers?: string;
        days?: string;
        rate_per_day?: string;
        estimated_amount?: string;
        duration?: string;
        duration_unit?: string;
        rate?: string;
        trips?: string;
        cost_per_trip?: string;
        employee_id?: number | string;
    } | null;
    boq_item?: BoqItem;
    inventory_item?: InventoryItem;
    category?: RequisitionCategory | null;
    position?: Position | null;
    recipient?: Recipient | null;
}

export interface RequisitionStatusHistory {
    id: number;
    requisition_id: number;
    from_status: RequisitionStatus;
    to_status: RequisitionStatus;
    actor_id: number;
    comment: string | null;
    amendment_reason: string | null;
    original_amount: string | null;
    amended_amount: string | null;
    variance: string | null;
    amendment_items?: {
        before: Array<{
            id?: number;
            description: string;
            unit?: string | null;
            quantity: string;
            unit_cost: string;
            line_total: string;
        }>;
        after: Array<{
            id?: number;
            description: string;
            unit?: string | null;
            quantity: string;
            unit_cost: string;
            line_total: string;
            original_quantity?: string | null;
            original_unit_cost?: string | null;
            original_line_total?: string | null;
        }>;
    } | null;
    created_at: string;
    actor?: { id: number; name: string };
}

export interface RequisitionAttachment {
    id: number;
    requisition_id: number;
    file_url: string;
    document_type: 'quotation' | 'grn' | 'receipt' | 'invoice' | 'other';
    uploaded_by: number;
    created_at: string;
}

export interface RequisitionCategory {
    id: number;
    name: string;
    description?: string | null;
    expense_type?: 'direct' | 'indirect';
    is_active?: boolean;
    sort_order?: number;
}

export interface Department {
    id: number;
    name: string;
    description?: string | null;
    is_active?: boolean;
    sort_order?: number;
}

export interface Position {
    id: number;
    name: string;
    description?: string | null;
    is_active?: boolean;
    sort_order?: number;
}

export interface Unit {
    id: number;
    name: string;
    description?: string | null;
    is_active?: boolean;
    sort_order?: number;
}

export interface RequisitionRecipient {
    id?: number;
    recipient_id?: number | null;
    name: string;
    phone?: string | null;
    position_id?: number | null;
    position_name?: string | null;
    sort_order?: number;
    recipient?: Recipient | null;
}

export interface Recipient {
    id: number;
    name: string;
    phone: string;
    email?: string | null;
    address?: string | null;
    national_id?: string | null;
    status: 'active' | 'inactive';
}

export interface RecipientAttendance {
    id: number;
    recipient_id: number;
    project_id: number;
    date: string;
    check_in?: string | null;
    check_out?: string | null;
    status: 'present' | 'absent';
    notes?: string | null;
    recipient?: Pick<Recipient, 'id' | 'name' | 'phone' | 'status'> | null;
    project?: Pick<Project, 'id' | 'code' | 'name'> | null;
}

export interface Requisition {
    id: number;
    requisition_no: string;
    project_id: number | null;
    boq_item_id: number | null;
    department: string;
    department_id?: number | null;
    requisition_category_id?: number | null;
    resource_type: RequisitionResourceType;
    requestor_id: number;
    recipient_id?: number | null;
    recipient_name?: string | null;
    recipient_position?: string | null;
    position_id?: number | null;
    status: RequisitionStatus;
    fulfillment_type: FulfillmentType;
    fulfillment_scope?: 'whole' | 'items' | null;
    addressed_to?: RequisitionAddressedTo;
    original_amount: string;
    amended_amount: string | null;
    fulfilled_amount?: string;
    created_at: string;
    updated_at: string;
    project?: Project | null;
    boq_item?: BoqItem;
    category?: RequisitionCategory | null;
    categories?: RequisitionCategory[];
    recipient?: Recipient | null;
    recipients?: RequisitionRecipient[];
    requestor?: { id: number; name: string; email?: string };
    items?: RequisitionItem[];
    history?: RequisitionStatusHistory[];
    attachments?: RequisitionAttachment[];
    approval_steps?: ApprovalStep[];
    expense?: Expense | null;
}

export interface ApprovalStep {
    id: number;
    requisition_id: number;
    level: number;
    required_role: string;
    status: 'pending' | 'approved' | 'rejected' | 'skipped';
    assigned_at: string;
    resolved_at: string | null;
    requisition?: Requisition;
}

export type CashAllocationStatus = 'pending' | 'approved' | 'rejected' | 'received';

export interface MoneyAccount {
    id: number;
    name: string;
    bank_name?: string | null;
    type: 'manager' | 'finance';
    balance: string;
    is_active: boolean;
    notes?: string | null;
    created_at?: string | null;
}

export interface AccountTransaction {
    id: number;
    money_account_id: number;
    type: string;
    deposit_source?: string | null;
    deposit_source_label?: string | null;
    amount: string;
    balance_after: string;
    description: string | null;
    reference_no: string | null;
    method: string | null;
    is_credit: boolean;
    occurred_at: string | null;
    account?: Pick<MoneyAccount, 'id' | 'name' | 'type'> | null;
    related_account?: Pick<MoneyAccount, 'id' | 'name' | 'type'> | null;
    recorder?: { id: number; name: string } | null;
}

export type DepositSource =
    | 'owner_capital'
    | 'loan'
    | 'customer_advance'
    | 'other_income'
    | 'retention_release'
    | 'other';

export type CompanyDebtType = 'loan' | 'customer_advance';
export type CompanyDebtStatus = 'open' | 'partially_paid' | 'cleared';

export interface CompanyDebt {
    id: number;
    type: CompanyDebtType;
    type_label: string;
    creditor_name: string;
    original_amount: string;
    outstanding_amount: string;
    status: CompanyDebtStatus;
    status_label: string;
    money_account_id: number;
    deposit_transaction_id?: number | null;
    notes?: string | null;
    occurred_at: string | null;
    created_at?: string | null;
    money_account?: Pick<MoneyAccount, 'id' | 'name' | 'type'> | null;
    recorder?: { id: number; name: string } | null;
}

export interface CompanyDebtPayment {
    id: number;
    company_debt_id: number;
    amount: string;
    money_account_id: number;
    account_transaction_id?: number | null;
    notes?: string | null;
    method?: string | null;
    reference_no?: string | null;
    occurred_at: string | null;
    money_account?: Pick<MoneyAccount, 'id' | 'name' | 'type'> | null;
    recorder?: { id: number; name: string } | null;
}

export interface CashAllocation {
    id: number;
    project_id: number | null;
    source_account_id?: number | null;
    requested_amount: string;
    received_amount: string;
    utilized_amount: string;
    balance?: string;
    status: CashAllocationStatus;
    method: string | null;
    reference_no: string | null;
    requested_at: string;
    received_at: string | null;
    decided_at?: string | null;
    rejection_reason?: string | null;
    source_account?: { id: number; name: string } | null;
    project?: Project | Pick<Project, 'id' | 'code' | 'name'> | null;
    requester?: { id: number; name: string };
    approver?: { id: number; name: string };
}

export interface Expense {
    id: number;
    project_id: number | null;
    boq_item_id: number | null;
    requisition_id?: number | null;
    valuation_id?: number | null;
    category: 'direct' | 'indirect';
    sub_type: string;
    activity_ref?: string | null;
    asset_reg_no?: string | null;
    amount: string;
    description: string | null;
    expense_date: string;
    recorded_by: number;
    project?: Project | null;
    boq_item?: Pick<BoqItem, 'id' | 'description' | 'unit'> | null;
    requisition?: Pick<Requisition, 'id' | 'requisition_no' | 'status'> | null;
    valuation?: Pick<Valuation, 'id' | 'certificate_no' | 'project_id'> | null;
    recorder?: { id: number; name: string } | null;
    cash_disbursements?: Array<{
        id: number;
        amount: string;
        method: string;
        payee: string | null;
        reference_no: string | null;
        account_name?: string | null;
        cash_allocation?: Pick<CashAllocation, 'id' | 'project_id' | 'reference_no'> | null;
    }>;
}

export interface SpendableCashFloat {
    id: number;
    project_id: number | null;
    received_amount: string;
    utilized_amount: string;
    balance: string;
    reference_no: string | null;
    received_at: string | null;
    project: Pick<Project, 'id' | 'code' | 'name'> | null;
}

export interface Supplier {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    contact_info: string | null;
    performance_rating: string | null;
}

export type PurchaseOrderStatus =
    | 'draft'
    | 'sent'
    | 'confirmed'
    | 'partially_received'
    | 'fully_received'
    | 'cancelled';

export interface PurchaseOrder {
    id: number;
    purchase_order_no?: string | null;
    requisition_id: number;
    supplier_id: number;
    equipment_id?: number | null;
    boq_item_id: number | null;
    quantity: string;
    unit_cost: string;
    total_amount: string;
    paid_amount: string;
    outstanding_amount: string;
    payment_status: 'unpaid' | 'partially_paid' | 'paid';
    purchase_date?: string | null;
    status: PurchaseOrderStatus;
    supplier?: Supplier;
    requisition?: Requisition;
    equipment?: Equipment | null;
    items?: PurchaseOrderItem[];
    payments?: PurchaseOrderPayment[];
}

export interface PurchaseOrderItem {
    id: number;
    purchase_order_id: number;
    name: string;
    quantity: string;
    unit_price: string;
    total_amount: string;
}

export interface PurchaseOrderPayment {
    id: number;
    purchase_order_id: number;
    amount: string;
    method: 'cash' | 'mobile' | 'bank';
    reference_no: string;
    notes?: string | null;
    recorded_by: number;
    paid_at: string;
}

export interface GoodsReceipt {
    id: number;
    purchase_order_id: number;
    quantity_received: string;
    condition: 'good' | 'damaged' | 'partial';
    received_by: number;
    received_at: string;
    purchase_order?: PurchaseOrder;
}

export interface StockLocation {
    id: number;
    name: string;
    project_id?: number;
}

export interface InventoryItem {
    id: number;
    code: string;
    name: string;
    unit: string;
    category: string;
    reorder_point: string | null;
}

export interface StockBalance {
    id: number;
    inventory_item_id: number;
    stock_location_id: number;
    quantity_on_hand: string;
    average_cost: string;
    inventory_item?: InventoryItem;
    location?: { id: number; name: string };
    stock_location?: { id: number; name: string };
}

export interface InventoryTransaction {
    id: number;
    inventory_item_id: number;
    stock_location_id: number;
    type: 'IN' | 'OUT' | 'TRANSFER' | 'RETURN' | 'ADJUSTMENT' | 'DAMAGE';
    quantity: string;
    unit_cost: string | null;
    created_by: number;
    created_at: string;
    inventory_item?: InventoryItem;
}

export interface InventoryIssue {
    id: number;
    requisition_id: number | null;
    inventory_item_id: number;
    stock_location_id: number;
    quantity: string;
    recipient_id: number;
    work_section: string | null;
    value: string;
    issued_at: string;
    inventory_item?: InventoryItem;
    requisition?: Requisition;
}

export interface Employee {
    id: number;
    employee_no: string;
    name: string;
    role: string;
    pay_structure: 'daily' | 'monthly';
    daily_rate: string | null;
    monthly_salary: string | null;
    project_id: number;
    user_id?: number | null;
    project?: Project;
    user?: { id: number; name: string; email: string };
}

export interface Attendance {
    id: number;
    employee_id: number;
    date: string;
    status: 'present' | 'absent' | 'half_day' | 'leave';
    hours_worked: string | null;
    employee?: Employee;
}

export interface PayrollRun {
    id: number;
    project_id: number;
    period_start: string;
    period_end: string;
    status: 'draft' | 'approved' | 'posted';
    requisition_id?: number | null;
    project?: Project;
    requisition?: Pick<Requisition, 'id' | 'requisition_no' | 'status'> | null;
    items?: PayrollItem[];
    items_count?: number;
    items_sum_net_pay?: string | null;
    total_net_pay?: string;
}

export interface PayrollItem {
    id: number;
    payroll_run_id: number;
    employee_id: number;
    base: string;
    overtime: string;
    allowances: string;
    deductions_total: string;
    net_pay: string;
    employee?: Employee;
}

export type EquipmentStatus = 'available' | 'assigned' | 'under_maintenance' | 'retired';

export interface Equipment {
    id: number;
    name: string;
    type: string;
    status: EquipmentStatus;
}

export interface EquipmentAssignment {
    id: number;
    equipment_id: number;
    project_id: number;
    boq_item_id: number | null;
    hours_budgeted: string | null;
    hours_used: string;
    start_date: string;
    end_date: string | null;
    equipment?: Equipment;
    project?: Project;
}

export interface EquipmentMaintenance {
    id: number;
    equipment_id: number;
    purchase_order_id?: number | null;
    type: 'maintenance' | 'repair';
    cost: string;
    description: string | null;
    date: string;
    equipment?: Equipment;
    purchase_order?: Pick<PurchaseOrder, 'id' | 'purchase_order_no'> | null;
}

export interface EquipmentFuelLog {
    id: number;
    equipment_id: number;
    assignment_id: number | null;
    liters: string;
    cost: string;
    date: string;
    equipment?: Equipment;
}

export type ValuationStatus = 'draft' | 'certified';

export interface Valuation {
    id: number;
    project_id: number;
    phase_id: number;
    certificate_no: number;
    gross_value: string;
    total_deductions: string;
    net_value: string;
    status: ValuationStatus;
    created_by: number;
    certified_by: number | null;
    certified_at: string | null;
    created_at: string;
    deductions?: ValuationDeduction[];
}

export type ComplianceCalculationType = 'rate_percent' | 'fixed_amount';

export interface ValuationDeduction {
    id: number;
    valuation_id: number;
    compliance_rule_id: number | null;
    name: string;
    calculation_type: ComplianceCalculationType;
    rule_type: string | null;
    rate: string | null;
    fixed_amount: string | null;
    amount: string;
}

export interface ComplianceRule {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    created_at?: string;
}

export interface ReportDefinition {
    slug: string;
    name: string;
    description: string;
    category: string;
}

export interface ReportSchedule {
    id: number;
    report_slug: string;
    frequency: string;
    recipients: string[];
    is_active: boolean;
    last_run_at: string | null;
}

export interface AuditLog {
    id: number;
    entity_type: string;
    entity_id: number;
    action: string;
    before_data: Record<string, unknown> | null;
    after_data: Record<string, unknown> | null;
    performed_by: number | null;
    ip_address: string | null;
    created_at: string;
    performer?: { id: number; name: string };
}

export interface DashboardStats {
    active_projects: number;
    total_projects: number;
    pending_approvals: number;
    budget_utilization: number;
    cash_on_hand: string;
    finance_wallet_balance?: string;
    company_accounts_balance?: string;
    open_requisitions: number;
}

export interface DashboardCharts {
    project_budget: {
        name: string;
        spent: number;
        remaining: number;
        utilization: number;
    }[];
    requisition_status: {
        name: string;
        count: number;
        amount: number;
    }[];
}

export interface ReconciliationSummary {
    committed: string;
    disbursed: string;
    outstanding: string;
    cash_on_hand: string;
}

export interface CashAvailability {
    spends_cash: boolean;
    scope: 'organization' | 'project';
    cash_on_hand: string;
    committed: string;
    available: string;
    required: string;
    exceeds: boolean;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links?: { url: string | null; label: string; active: boolean }[];
}

export interface ListingFilters {
    search?: string;
    from?: string;
    to?: string;
    sort?: string;
    direction?: 'asc' | 'desc';
    per_page?: string | number;
}

export interface SortOption {
    value: string;
    label: string;
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    auth: {
        user: User | null;
        platform_admin?: PlatformAdmin | null;
        impersonator_id?: number | null;
        platform_impersonator_id?: number | null;
    };
    currentProject: number | null;
    unreadNotificationCount: number;
    uiSettings: UiSettings;
    navigation: NavItem[];
    flash: {
        success?: string;
        error?: string;
    };
};
