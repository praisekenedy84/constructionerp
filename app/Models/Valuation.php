<?php

namespace App\Models;

use App\Enums\ValuationStatus;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Valuation extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'project_id',
        'phase_id',
        'certificate_no',
        'gross_value',
        'total_deductions',
        'net_value',
        'status',
        'created_by',
        'certified_by',
        'certified_at',
    ];

    protected function casts(): array
    {
        return [
            'certificate_no' => 'integer',
            'gross_value' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_value' => 'decimal:2',
            'status' => ValuationStatus::class,
            'certified_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'phase_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function certifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'certified_by');
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(ValuationDeduction::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
