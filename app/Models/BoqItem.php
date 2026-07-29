<?php

namespace App\Models;

use App\Enums\BoqItemCategory;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BoqItem extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'section_id',
        'description',
        'unit',
        'category',
        'budgeted_qty',
        'unit_rate',
        'budgeted_amount',
        'reserved_qty',
        'consumed_qty',
        'requested_qty',
        'approved_qty',
        'procured_qty',
        'received_qty',
        'issued_qty',
    ];

    protected function casts(): array
    {
        return [
            'category' => BoqItemCategory::class,
            'budgeted_qty' => 'decimal:3',
            'unit_rate' => 'decimal:2',
            'budgeted_amount' => 'decimal:2',
            'reserved_qty' => 'decimal:3',
            'consumed_qty' => 'decimal:3',
            'requested_qty' => 'decimal:3',
            'approved_qty' => 'decimal:3',
            'procured_qty' => 'decimal:3',
            'received_qty' => 'decimal:3',
            'issued_qty' => 'decimal:3',
        ];
    }

    protected function availableQty(): Attribute
    {
        return Attribute::get(function (): string {
            return bcsub(
                bcsub((string) $this->budgeted_qty, (string) $this->consumed_qty, 3),
                (string) $this->reserved_qty,
                3
            );
        });
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(BoqSection::class, 'section_id');
    }

    public function budgetTransactions(): HasMany
    {
        return $this->hasMany(BudgetTransaction::class);
    }

    public function requisitions(): HasMany
    {
        return $this->hasMany(Requisition::class);
    }

    public function requisitionItems(): HasMany
    {
        return $this->hasMany(RequisitionItem::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function equipmentAssignments(): HasMany
    {
        return $this->hasMany(EquipmentAssignment::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
