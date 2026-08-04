<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RequisitionCategory extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function requisitions(): BelongsToMany
    {
        return $this->belongsToMany(
            Requisition::class,
            'requisition_requisition_category',
        )->withPivot('sort_order')->withTimestamps();
    }

    /** Legacy single-FK links still dual-written on the header. */
    public function primaryRequisitions(): HasMany
    {
        return $this->hasMany(Requisition::class, 'requisition_category_id');
    }
}
