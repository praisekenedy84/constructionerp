<?php

namespace App\Models;

use App\Enums\PayStructure;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'employee_project')
            ->withTimestamps()
            ->orderBy('projects.name');
    }

    public function scopeAssignedToProject(Builder $query, int $projectId): Builder
    {
        return $query->where(function (Builder $inner) use ($projectId): void {
            $inner->whereHas('projects', fn (Builder $projectQuery) => $projectQuery->where('projects.id', $projectId))
                ->orWhere('project_id', $projectId);
        });
    }

    /** @param  array<string, mixed>  $data */
    public static function resolveProjectIds(array $data): array
    {
        $ids = array_map('intval', $data['project_ids'] ?? []);

        if (! empty($data['project_id'])) {
            $ids[] = (int) $data['project_id'];
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /** @param  list<int>  $projectIds */
    public function syncProjectAssignments(array $projectIds): void
    {
        $projectIds = array_values(array_unique(array_filter($projectIds)));
        $this->projects()->sync($projectIds);
        $this->update(['project_id' => $projectIds[0] ?? null]);
    }

    public function detachFromProject(int $projectId): void
    {
        $this->projects()->detach($projectId);

        if ((int) $this->project_id === $projectId) {
            $this->update(['project_id' => $this->projects()->value('projects.id')]);
        }
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
