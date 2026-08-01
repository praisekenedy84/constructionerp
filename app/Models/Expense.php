<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'project_id',
        'boq_item_id',
        'requisition_id',
        'category',
        'sub_type',
        'activity_ref',
        'asset_reg_no',
        'amount',
        'description',
        'expense_date',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'amount' => 'decimal:2',
            'expense_date' => 'date',
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

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function cashDisbursements(): HasMany
    {
        return $this->hasMany(CashDisbursement::class);
    }
}
