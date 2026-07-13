<?php

namespace App\Models;

use App\Enums\FulfillmentType;
use App\Enums\RequisitionStatus;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Requisition extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'requisition_no',
        'project_id',
        'boq_item_id',
        'department',
        'requestor_id',
        'status',
        'fulfillment_type',
        'original_amount',
        'amended_amount',
    ];

    protected function casts(): array
    {
        return [
            'status' => RequisitionStatus::class,
            'fulfillment_type' => FulfillmentType::class,
            'original_amount' => 'decimal:2',
            'amended_amount' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
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

    public function inventoryIssues(): HasMany
    {
        return $this->hasMany(InventoryIssue::class);
    }
}
