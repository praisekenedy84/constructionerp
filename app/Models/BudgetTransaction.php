<?php

namespace App\Models;

use App\Enums\BudgetTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BudgetTransaction extends Model
{
    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'project_id',
        'boq_item_id',
        'type',
        'amount',
        'reference_entity_type',
        'reference_entity_id',
        'reason',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => BudgetTransactionType::class,
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function referenceEntity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'reference_entity_type', 'reference_entity_id');
    }
}
