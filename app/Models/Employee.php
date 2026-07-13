<?php

namespace App\Models;

use App\Enums\PayStructure;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'employee_no',
        'name',
        'role',
        'pay_structure',
        'daily_rate',
        'monthly_salary',
        'project_id',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'pay_structure' => PayStructure::class,
            'daily_rate' => 'decimal:2',
            'monthly_salary' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(Advance::class);
    }
}
