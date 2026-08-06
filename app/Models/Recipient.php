<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Recipient extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'national_id',
        'status',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_recipient')
            ->withTimestamps();
    }

    public function requisitionItems(): HasMany
    {
        return $this->hasMany(RequisitionItem::class);
    }

    public function requisitionRecipientRows(): HasMany
    {
        return $this->hasMany(RequisitionRecipient::class);
    }

    public function requisitions(): HasMany
    {
        return $this->hasMany(Requisition::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(RecipientAttendance::class);
    }
}
