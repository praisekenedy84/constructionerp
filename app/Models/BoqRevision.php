<?php

namespace App\Models;

use App\Enums\BoqRevisionStatus;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoqRevision extends Model
{
    use LogsActivity;

    protected $fillable = [
        'project_id',
        'version_no',
        'reason',
        'requested_by',
        'approved_by',
        'status',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'status' => BoqRevisionStatus::class,
            'activated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
