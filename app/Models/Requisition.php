<?php

namespace App\Models;

use App\Enums\FulfillmentType;
use App\Enums\RequisitionAddressedTo;
use App\Enums\RequisitionResourceType;
use App\Enums\RequisitionStatus;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Requisition extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'requisition_no',
        'project_id',
        'boq_item_id',
        'department',
        'department_id',
        'requisition_category_id',
        'resource_type',
        'requestor_id',
        'recipient_name',
        'recipient_position',
        'status',
        'fulfillment_type',
        'addressed_to',
        'original_amount',
        'amended_amount',
    ];

    protected function casts(): array
    {
        return [
            'status' => RequisitionStatus::class,
            'resource_type' => RequisitionResourceType::class,
            'fulfillment_type' => FulfillmentType::class,
            'addressed_to' => RequisitionAddressedTo::class,
            'original_amount' => 'decimal:2',
            'amended_amount' => 'decimal:2',
        ];
    }

    public function isAddressedToFinance(): bool
    {
        return $this->addressed_to === RequisitionAddressedTo::Finance
            || (
                $this->addressed_to === null
                && $this->fulfillment_type !== FulfillmentType::StockIssue
            );
    }

    public function isAddressedToStorekeeper(): bool
    {
        return $this->addressed_to === RequisitionAddressedTo::Storekeeper
            || (
                $this->addressed_to === null
                && $this->fulfillment_type === FulfillmentType::StockIssue
            );
    }

    /**
     * Drafts stay private to the author until published for approval.
     *
     * @param  Builder<Requisition>  $query
     * @return Builder<Requisition>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperUser()) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($user) {
            $inner->where('status', '!=', RequisitionStatus::Draft->value)
                ->orWhere('requestor_id', $user->id);
        });
    }

    public function isVisibleTo(User $user): bool
    {
        if ($user->isSuperUser()) {
            return true;
        }

        if ($this->status !== RequisitionStatus::Draft) {
            return true;
        }

        return (int) $this->requestor_id === (int) $user->id;
    }

    public function isOwnedBy(User $user): bool
    {
        return (int) $this->requestor_id === (int) $user->id;
    }

    public function isOrganizationWide(): bool
    {
        return $this->project_id === null;
    }

    public function isProjectScoped(): bool
    {
        return $this->project_id !== null;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RequisitionCategory::class, 'requisition_category_id');
    }

    public function assignedDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function requestor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requestor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequisitionItem::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(RequisitionStatusHistory::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RequisitionAttachment::class);
    }

    public function approvalSteps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function cashDisbursements(): HasMany
    {
        return $this->hasMany(CashDisbursement::class);
    }

    public function expense(): HasOne
    {
        return $this->hasOne(Expense::class);
    }

    public function inventoryIssues(): HasMany
    {
        return $this->hasMany(InventoryIssue::class);
    }
}
