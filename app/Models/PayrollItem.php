<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollItem extends Model
{
    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'boq_item_id',
        'base',
        'overtime',
        'allowances',
        'deductions_total',
        'net_pay',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'base' => 'decimal:2',
            'overtime' => 'decimal:2',
            'allowances' => 'decimal:2',
            'deductions_total' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(PayrollDeduction::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(Advance::class);
    }
}
