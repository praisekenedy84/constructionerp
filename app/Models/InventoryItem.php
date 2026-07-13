<?php

namespace App\Models;

use App\Enums\InventoryItemCategory;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'unit',
        'category',
        'reorder_point',
    ];

    protected function casts(): array
    {
        return [
            'category' => InventoryItemCategory::class,
            'reorder_point' => 'decimal:4',
        ];
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(InventoryIssue::class);
    }
}
