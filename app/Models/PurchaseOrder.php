<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use LogsActivity, SoftDeletes;

    protected $appends = [
        'paid_amount',
        'outstanding_amount',
        'payment_status',
    ];

    protected $fillable = [
        'purchase_order_no',
        'requisition_id',
        'supplier_id',
        'equipment_id',
        'boq_item_id',
        'quantity',
        'unit_cost',
        'total_amount',
        'purchase_date',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'purchase_date' => 'date',
            'status' => PurchaseOrderStatus::class,
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchaseOrderPayment::class);
    }

    public function maintenance(): HasOne
    {
        return $this->hasOne(EquipmentMaintenance::class);
    }

    protected function paidAmount(): Attribute
    {
        return Attribute::get(function (): string {
            if (array_key_exists('payments_sum_amount', $this->attributes)) {
                return bcadd((string) ($this->attributes['payments_sum_amount'] ?? 0), '0', 2);
            }

            if ($this->relationLoaded('payments')) {
                return bcadd((string) $this->payments->sum('amount'), '0', 2);
            }

            return bcadd((string) $this->payments()->sum('amount'), '0', 2);
        });
    }

    protected function outstandingAmount(): Attribute
    {
        return Attribute::get(fn (): string => bcsub(
            (string) $this->total_amount,
            (string) $this->paid_amount,
            2,
        ));
    }

    protected function paymentStatus(): Attribute
    {
        return Attribute::get(function (): string {
            if (bccomp((string) $this->paid_amount, '0', 2) === 0) {
                return 'unpaid';
            }

            return bccomp((string) $this->outstanding_amount, '0', 2) === 1
                ? 'partially_paid'
                : 'paid';
        });
    }
}
