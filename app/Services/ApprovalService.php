<?php

namespace App\Services;

use App\Enums\ApprovalStepStatus;
use App\Enums\RequisitionStatus;
use App\Models\ApprovalAction;
use App\Models\ApprovalStep;
use App\Models\Notification;
use App\Models\Requisition;
use App\Models\User;
use App\Models\WorkflowConfig;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ApprovalService
{
    public function __construct(
        private readonly RequisitionService $requisitionService,
    ) {}

    public function createSteps(Requisition $requisition): array
    {
        return DB::transaction(function () use ($requisition) {
            $amount = (string) ($requisition->amended_amount ?? $requisition->original_amount);
            $config = $this->resolveWorkflowConfig($requisition->project_id, $amount);

            if (! $config) {
                return [];
            }

            ApprovalStep::where('requisition_id', $requisition->id)
                ->where('status', ApprovalStepStatus::Pending)
                ->update([
                    'status' => ApprovalStepStatus::Skipped,
                    'resolved_at' => now(),
                ]);

            $step = ApprovalStep::create([
                'requisition_id' => $requisition->id,
                'level' => $config->level,
                'required_role' => $config->role_name,
                'status' => ApprovalStepStatus::Pending,
                'assigned_at' => now(),
            ]);

            $this->notifyRole($config->role_name, 'approval_required', [
                'requisition_id' => $requisition->id,
                'requisition_no' => $requisition->requisition_no,
                'step_id' => $step->id,
            ]);

            return [$step];
        });
    }

    public function resolve(ApprovalStep $step, User $actor, string $action, array $opts = []): Requisition
    {
        return DB::transaction(function () use ($step, $actor, $action, $opts) {
            $step = ApprovalStep::lockForUpdate()->findOrFail($step->id);
            $requisition = Requisition::lockForUpdate()->findOrFail($step->requisition_id);

            if ($step->status !== ApprovalStepStatus::Pending) {
                throw new \InvalidArgumentException('Approval step has already been resolved.');
            }

            if (! $actor->hasRole($step->required_role) && ! $actor->isSuperUser()) {
                throw new AuthorizationException("Actor does not have required role: {$step->required_role}.");
            }

            if ($requisition->requestor_id === $actor->id && ! $actor->isSuperUser()) {
                throw new AuthorizationException('You cannot approve your own requisition.');
            }

            $this->assertApprovalPermission($actor, $action);

            $stepStatus = match ($action) {
                'approved' => ApprovalStepStatus::Approved,
                'rejected' => ApprovalStepStatus::Rejected,
                'amended' => ApprovalStepStatus::Approved,
                default => throw new \InvalidArgumentException("Unknown approval action: {$action}"),
            };

            $step->update([
                'status' => $stepStatus,
                'resolved_at' => now(),
            ]);

            ApprovalAction::create([
                'approval_step_id' => $step->id,
                'actor_id' => $actor->id,
                'action' => $action,
                'comment' => $opts['comment'] ?? null,
                'created_at' => now(),
            ]);

            if ($action === 'rejected') {
                $this->requisitionService->onRejected($requisition);
                $requisition->update(['status' => RequisitionStatus::Rejected]);
                $this->recordHistory($requisition, 'under_review', 'rejected', $actor, $opts);

                return $requisition->fresh(['approvalSteps', 'items']);
            }

            if ($action === 'amended') {
                ApprovalStep::where('requisition_id', $requisition->id)
                    ->where('status', ApprovalStepStatus::Pending)
                    ->update([
                        'status' => ApprovalStepStatus::Skipped,
                        'resolved_at' => now(),
                    ]);

                $this->requisitionService->onAmended($requisition, $actor, $opts);
                $requisition->update(['status' => RequisitionStatus::Amended]);

                $this->recordHistory($requisition, 'under_review', 'amended', $actor, $opts);

                return $requisition->fresh(['approvalSteps', 'items']);
            }

            $pendingCount = ApprovalStep::where('requisition_id', $requisition->id)
                ->where('status', ApprovalStepStatus::Pending)
                ->count();

            if ($pendingCount === 0) {
                $this->requisitionService->onApproved($requisition, $actor, $opts);
                $requisition->update(['status' => RequisitionStatus::Approved]);
                $this->recordHistory($requisition, 'under_review', 'approved', $actor, $opts);

                return $requisition->fresh(['approvalSteps', 'items']);
            }

            return $requisition->fresh(['approvalSteps']);
        });
    }

    public function escalateStaleApprovals(): void
    {
        $escalationHours = (int) env('APPROVAL_ESCALATION_HOURS', 48);

        $steps = ApprovalStep::query()
            ->where('status', ApprovalStepStatus::Pending)
            ->with('requisition')
            ->get();

        foreach ($steps as $step) {
            if (! $step->assigned_at || $step->assigned_at->gt(now()->subHours($escalationHours))) {
                continue;
            }

            $nextConfig = WorkflowConfig::query()
                ->where('level', '>', $step->level)
                ->where(function ($query) use ($step) {
                    $query->where('project_id', $step->requisition->project_id)
                        ->orWhereNull('project_id');
                })
                ->orderBy('level')
                ->first();

            $step->update([
                'status' => ApprovalStepStatus::Skipped,
                'resolved_at' => now(),
            ]);

            if ($nextConfig) {
                $newStep = ApprovalStep::create([
                    'requisition_id' => $step->requisition_id,
                    'level' => $nextConfig->level,
                    'required_role' => $nextConfig->role_name,
                    'status' => ApprovalStepStatus::Pending,
                    'assigned_at' => now(),
                ]);

                $this->notifyRole($nextConfig->role_name, 'approval_escalation', [
                    'requisition_id' => $step->requisition_id,
                    'step_id' => $newStep->id,
                ]);
            }
        }
    }

    private function resolveWorkflowConfig(int $projectId, string $amount): ?WorkflowConfig
    {
        return WorkflowConfig::query()
            ->where(function ($query) use ($projectId) {
                $query->where('project_id', $projectId)
                    ->orWhereNull('project_id');
            })
            ->where('threshold_min', '<=', $amount)
            ->where(function ($query) use ($amount) {
                $query->whereNull('threshold_max')
                    ->orWhere('threshold_max', '>=', $amount);
            })
            ->orderByDesc('level')
            ->first();
    }

    private function assertApprovalPermission(User $actor, string $action): void
    {
        if ($actor->isSuperUser()) {
            return;
        }

        $map = [
            'approved' => 'approve',
            'amended' => 'amend',
            'rejected' => 'reject',
        ];

        if (! $actor->hasModulePermission('requisitions', $map[$action])) {
            throw new AuthorizationException('You do not have permission to perform this approval action.');
        }
    }

    /** @param  array<string, mixed>  $opts */
    private function recordHistory(
        Requisition $requisition,
        string $from,
        string $to,
        User $actor,
        array $opts,
    ): void {
        \App\Models\RequisitionStatusHistory::create([
            'requisition_id' => $requisition->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor->id,
            'comment' => $opts['comment'] ?? null,
            'amendment_reason' => $opts['amendment_reason'] ?? null,
            'original_amount' => $requisition->original_amount,
            'amended_amount' => $opts['amended_amount'] ?? null,
            'variance' => isset($opts['amended_amount'])
                ? bcsub((string) $requisition->original_amount, (string) $opts['amended_amount'], 2)
                : null,
            'created_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function notifyRole(string $roleName, string $type, array $data): void
    {
        User::role($roleName)->each(function (User $user) use ($type, $data) {
            Notification::create([
                'user_id' => $user->id,
                'type' => $type,
                'data' => $data,
                'created_at' => now(),
            ]);
        });
    }
}
