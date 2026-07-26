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
    nav_overrides?: {
        hidden?: string[];
        role_hidden?: Record<string, string[]>;
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

export type ProjectStatus = 'planning' | 'active' | 'on_hold' | 'closed';

export interface Project {
    id: number;
    code: string;
    name: string;
    client: string;
    location: string;
    contract_amount: string;
    wht_percentage: string;
    net_budget: string;
    remaining_budget?: string;
    physical_progress_pct: string;
    start_date: string;
    end_date: string;
    status: ProjectStatus;
    created_at?: string;
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
    | 'fulfilled'
    | 'closed'
    | 'cancelled';

export type FulfillmentType =
    | 'cash_disbursement'
    | 'stock_issue'
    | 'direct_supplier_payment';

export interface RequisitionItem {
    id: number;
    requisition_id: number;
    boq_item_id: number;
    description: string;
    quantity: string;
    unit_cost: string;
    line_total: string;
    boq_item?: BoqItem;
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

export interface Requisition {
    id: number;
    requisition_no: string;
    project_id: number;
    boq_item_id: number;
    department: string;
    requestor_id: number;
    status: RequisitionStatus;
    fulfillment_type: FulfillmentType;
    original_amount: string;
    amended_amount: string | null;
    created_at: string;
    updated_at: string;
    project?: Project;
    boq_item?: BoqItem;
    requestor?: { id: number; name: string };
    items?: RequisitionItem[];
    history?: RequisitionStatusHistory[];
    attachments?: RequisitionAttachment[];
    approval_steps?: ApprovalStep[];
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

export interface CashAllocation {
    id: number;
    project_id: number | null;
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
    project?: Project | Pick<Project, 'id' | 'code' | 'name'> | null;
    requester?: { id: number; name: string };
    approver?: { id: number; name: string };
}

export interface Expense {
    id: number;
    project_id: number | null;
    boq_item_id: number | null;
    category: 'direct' | 'indirect';
    sub_type: string;
    amount: string;
    description: string | null;
    expense_date: string;
    recorded_by: number;
    project?: Project;
}

export interface Supplier {
    id: number;
    name: string;
    contact_info: string;
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
    requisition_id: number;
    supplier_id: number;
    boq_item_id: number;
    quantity: string;
    unit_cost: string;
    total_amount: string;
    status: PurchaseOrderStatus;
    supplier?: Supplier;
    requisition?: Requisition;
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
    project?: Project;
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
    type: 'maintenance' | 'repair';
    cost: string;
    description: string | null;
    date: string;
    equipment?: Equipment;
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
    certificate_no: number;
    gross_value: string;
    total_deductions: string;
    net_value: string;
    status: ValuationStatus;
    created_by: number;
    certified_by: number | null;
    certified_at: string | null;
    created_at: string;
}

export interface ValuationDeduction {
    id: number;
    valuation_id: number;
    rule_type: string;
    rate: string;
    amount: string;
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
