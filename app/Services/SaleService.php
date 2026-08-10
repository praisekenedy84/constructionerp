<?php

namespace App\Services;

use App\Enums\PhaseStatus;
use App\Enums\ProjectStatus;
use App\Enums\SaleStatus;
use App\Models\MoneyAccount;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Sale;
use App\Models\SaleReceivablePayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaleService
{
    public function __construct(
        private BudgetService $budgetService,
        private MoneyAccountService $moneyAccountService,
    ) {}

    public function ensureForPhase(ProjectPhase $phase): Sale
    {
        $existing = Sale::query()->where('phase_id', $phase->id)->first();
        if ($existing) {
            return $existing;
        }

        $phase->loadMissing('project');

        return Sale::create([
            'project_id' => $phase->project_id,
            'phase_id' => $phase->id,
            'sale_code' => $this->generateSaleCode($phase),
            'status' => SaleStatus::Open,
            'contract_amount' => (string) $phase->disbursed_amount,
            'profit_amount' => null,
            'collected_amount' => '0.00',
        ]);
    }

    public function ensureAllPhasesHaveSales(): int
    {
        $created = 0;

        ProjectPhase::query()
            ->whereDoesntHave('sale')
            ->orderBy('id')
            ->each(function (ProjectPhase $phase) use (&$created) {
                $this->ensureForPhase($phase);
                $created++;
            });

        return $created;
    }

    public function ensurePhasesHaveSalesForProject(Project $project): int
    {
        $created = 0;

        $project->phases()
            ->whereDoesntHave('sale')
            ->orderBy('sequence_no')
            ->each(function (ProjectPhase $phase) use (&$created) {
                $this->ensureForPhase($phase);
                $created++;
            });

        return $created;
    }

    /**
     * On phase close: convert surplus after absorbing pending deficit, or carry deficit forward.
     */
    public function applyPhaseCloseReceivable(Sale $sale, User $actor): ?Sale
    {
        return DB::transaction(function () use ($sale, $actor) {
            $sale = Sale::lockForUpdate()->findOrFail($sale->id);
            $sale->loadMissing(['project', 'phase']);

            if ($sale->isConverted()) {
                return $sale;
            }

            $project = Project::lockForUpdate()->findOrFail($sale->project_id);

            if ($project->status === ProjectStatus::Loss) {
                return null;
            }

            Sale::query()
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->get();

            $pending = bcadd((string) $project->pending_deficit, '0', 2);
            $liveRemaining = $this->liveRemaining($project);

            if (bccomp($liveRemaining, '0', 2) === 1) {
                $gross = $this->proRataShareOf($sale, $liveRemaining);
                $net = bcsub($gross, $pending, 2);

                if (bccomp($net, '0', 2) === 1) {
                    $converted = $this->convertToReceivable($sale, $actor, $net);
                    $project->update(['pending_deficit' => '0.00']);

                    return $converted;
                }

                // Positive share was fully absorbed by prior debt (or wiped it partially).
                $project->update([
                    'pending_deficit' => bcmul($net, '-1', 2),
                ]);

                return null;
            }

            // Break-even or underwater: no receivable; sync deficit from live shortfall.
            $fromLive = bccomp($liveRemaining, '0', 2) === -1
                ? bcmul($liveRemaining, '-1', 2)
                : '0.00';
            $newPending = bccomp($pending, $fromLive, 2) === 1 ? $pending : $fromLive;
            $project->update(['pending_deficit' => $newPending]);

            return null;
        });
    }

    /**
     * @param  string|null  $forcedAmount  When set, locks this amount (positive surplus or negative loss).
     */
    public function convertToReceivable(Sale $sale, User $actor, ?string $forcedAmount = null): Sale
    {
        return DB::transaction(function () use ($sale, $actor, $forcedAmount) {
            $sale = Sale::lockForUpdate()->findOrFail($sale->id);
            $sale->loadMissing(['project', 'phase']);

            if ($sale->isConverted()) {
                throw new \InvalidArgumentException('This sale has already been converted to a receivable.');
            }

            if (! $sale->phase_id || ! $sale->phase) {
                throw new \InvalidArgumentException('Only phase sales can be converted to a receivable.');
            }

            $phase = ProjectPhase::lockForUpdate()->findOrFail($sale->phase_id);

            if ($phase->status !== PhaseStatus::Closed) {
                throw new \InvalidArgumentException('Only closed phases can convert profit into a receivable.');
            }

            if ($sale->project?->status === ProjectStatus::Loss && $forcedAmount === null) {
                throw new \InvalidArgumentException('This project is marked as a loss; use Mark as Loss for the company deficit.');
            }

            Sale::query()
                ->where('project_id', $sale->project_id)
                ->lockForUpdate()
                ->get();

            if ($forcedAmount !== null) {
                $profit = bcadd($forcedAmount, '0', 2);
                if (bccomp($profit, '0', 2) === 0) {
                    throw new \InvalidArgumentException('Receivable amount cannot be zero.');
                }
            } else {
                $profit = $this->phaseReceivableAmount($sale);
                if (bccomp($profit, '0', 2) !== 1) {
                    throw new \InvalidArgumentException('Phase receivable amount must be greater than zero to convert.');
                }
            }

            $sale->update([
                'status' => SaleStatus::Receivable,
                'contract_amount' => (string) $phase->disbursed_amount,
                'profit_amount' => $profit,
                'collected_amount' => '0.00',
                'converted_at' => now(),
                'converted_by' => $actor->id,
            ]);

            if (bccomp($profit, '0', 2) === 1) {
                Project::whereKey($sale->project_id)->update(['pending_deficit' => '0.00']);
            }

            return $sale->fresh(['project', 'phase', 'converter', 'payments']);
        });
    }

    public function markProjectAsLoss(Project $project, User $actor): Sale
    {
        return DB::transaction(function () use ($project, $actor) {
            $project = Project::lockForUpdate()->findOrFail($project->id);

            if ($project->status === ProjectStatus::Loss) {
                throw new \InvalidArgumentException('This project is already marked as a loss.');
            }

            Sale::query()
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->get();

            $pending = $this->syncPendingDeficit($project);
            if (bccomp($pending, '0', 2) !== 1) {
                throw new \InvalidArgumentException('There is no pending deficit to record as a company loss.');
            }

            $phase = ProjectPhase::query()
                ->where('project_id', $project->id)
                ->where('status', PhaseStatus::Closed)
                ->orderByDesc('sequence_no')
                ->first();

            if (! $phase) {
                $phase = ProjectPhase::query()
                    ->where('project_id', $project->id)
                    ->orderByDesc('sequence_no')
                    ->first();
            }

            if (! $phase) {
                throw new \InvalidArgumentException('Project has no phase to record the loss against.');
            }

            if ($phase->status !== PhaseStatus::Closed) {
                throw new \InvalidArgumentException('Close at least one phase before marking the project as a loss.');
            }

            $sale = Sale::query()
                ->where('project_id', $project->id)
                ->where('status', SaleStatus::Open)
                ->whereHas('phase', fn ($q) => $q->where('status', PhaseStatus::Closed))
                ->orderByDesc('id')
                ->first();

            if (! $sale) {
                $sale = $this->ensureForPhase($phase);
                if ($sale->isConverted()) {
                    throw new \InvalidArgumentException('No open phase sale is available to record the loss.');
                }
            }

            $lossAmount = bcmul($pending, '-1', 2);
            $converted = $this->convertToReceivable($sale, $actor, $lossAmount);

            $project->update([
                'pending_deficit' => '0.00',
                'status' => ProjectStatus::Loss,
                'marked_loss_at' => now(),
                'marked_loss_by' => $actor->id,
            ]);

            return $converted->fresh(['project', 'phase', 'converter', 'payments']);
        });
    }

    /**
     * @param  array{
     *     amount: string|int|float,
     *     money_account_id: int,
     *     method?: string|null,
     *     reference_no?: string|null,
     *     notes?: string|null,
     *     occurred_at?: mixed
     * }  $data
     */
    public function collect(Sale $sale, User $actor, array $data): SaleReceivablePayment
    {
        return DB::transaction(function () use ($sale, $actor, $data) {
            $sale = Sale::lockForUpdate()->findOrFail($sale->id);

            if (! $sale->status->isCollectable()) {
                throw new \InvalidArgumentException('Only receivable sales can receive collections.');
            }

            $amount = bcadd((string) $data['amount'], '0', 2);
            if (bccomp($amount, '0', 2) === 0) {
                throw new \InvalidArgumentException('Collection amount cannot be zero.');
            }

            $outstanding = $sale->outstandingAmount();
            if (bccomp($outstanding, '0', 2) === 0) {
                throw new \InvalidArgumentException('There is no outstanding receivable to collect.');
            }

            if (bccomp($outstanding, '0', 2) === 1) {
                if (bccomp($amount, '0', 2) !== 1 || bccomp($amount, $outstanding, 2) === 1) {
                    throw new \InvalidArgumentException(
                        "Collection amount must be greater than zero and not exceed outstanding ({$outstanding})."
                    );
                }
            } else {
                // Loss receivable: amount must be negative and not beyond outstanding (e.g. -500 vs -1000).
                if (bccomp($amount, '0', 2) !== -1 || bccomp($amount, $outstanding, 2) === -1) {
                    throw new \InvalidArgumentException(
                        "Loss collection must be negative and not exceed outstanding ({$outstanding})."
                    );
                }
            }

            $account = MoneyAccount::findOrFail((int) $data['money_account_id']);
            $isLoss = bccomp($amount, '0', 2) === -1;

            $tx = $this->moneyAccountService->receiveReceivablePayment($account, $amount, $actor, [
                'description' => $isLoss
                    ? "Loss collection for {$sale->sale_code}"
                    : "Receivable collection for {$sale->sale_code}",
                'reference_no' => $data['reference_no'] ?? null,
                'method' => $data['method'] ?? null,
                'reference_entity_type' => 'sale',
                'reference_entity_id' => $sale->id,
                'occurred_at' => $data['occurred_at'] ?? now(),
            ]);

            $payment = SaleReceivablePayment::create([
                'sale_id' => $sale->id,
                'money_account_id' => $account->id,
                'account_transaction_id' => $tx->id,
                'amount' => $amount,
                'method' => $data['method'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->id,
                'occurred_at' => $data['occurred_at'] ?? now(),
            ]);

            $collected = bcadd((string) $sale->collected_amount, $amount, 2);
            $newOutstanding = bcsub((string) $sale->profit_amount, $collected, 2);
            $status = bccomp($newOutstanding, '0', 2) === 0
                ? SaleStatus::Paid
                : SaleStatus::PartiallyPaid;

            $sale->update([
                'collected_amount' => $collected,
                'status' => $status,
            ]);

            return $payment->fresh(['account', 'recorder', 'accountTransaction']);
        });
    }

    /**
     * Net convertible amount for this phase after pending deficit (never negative for normal convert).
     */
    public function phaseReceivableAmount(Sale $sale): string
    {
        $sale->loadMissing(['project', 'phase']);

        $project = $sale->project;
        $phase = $sale->phase;

        if (! $project || ! $phase) {
            return '0.00';
        }

        $breakdown = $this->receivableBreakdown($project, $sale);

        return $breakdown['estimated_profit'];
    }

    public function liveRemaining(Project $project): string
    {
        $remaining = $this->budgetService->remainingBudget($project);
        $recognized = $this->recognizedProfitForProject($project);

        return bcsub($remaining, $recognized, 2);
    }

    /**
     * Ensure pending_deficit reflects the larger of stored deficit and current live shortfall.
     */
    public function syncPendingDeficit(Project $project): string
    {
        $pending = bcadd((string) $project->pending_deficit, '0', 2);
        $liveRemaining = $this->liveRemaining($project);
        if (bccomp($liveRemaining, '0', 2) === -1) {
            $fromLive = bcmul($liveRemaining, '-1', 2);
            if (bccomp($fromLive, $pending, 2) === 1) {
                $pending = $fromLive;
                $project->update(['pending_deficit' => $pending]);
            }
        }

        return $pending;
    }

    /**
     * @return array{
     *     remaining_budget: string,
     *     recognized_amount: string,
     *     live_remaining: string,
     *     recognizable: string,
     *     pending_deficit: string,
     *     unconverted_phase_net: string,
     *     phase_net_budget: string,
     *     phase_share_pct: string,
     *     gross_share: string,
     *     estimated_profit: string
     * }
     */
    public function receivableBreakdown(Project $project, Sale $sale): array
    {
        $sale->loadMissing('phase');
        $phase = $sale->phase;

        $remaining = $this->budgetService->remainingBudget($project);
        $recognized = $this->recognizedProfitForProject($project);
        $liveRemaining = bcsub($remaining, $recognized, 2);
        $pending = bcadd((string) $project->pending_deficit, '0', 2);

        // Pro-rata pool is only the positive live remaining.
        $recognizable = bccomp($liveRemaining, '0', 2) === 1 ? $liveRemaining : '0.00';

        $phaseNet = $phase ? bcadd((string) $phase->phase_net_budget, '0', 2) : '0.00';

        $unconvertedNet = '0.00';
        if (! $sale->isConverted()) {
            $unconvertedPhaseIds = Sale::query()
                ->where('project_id', $project->id)
                ->where('status', SaleStatus::Open)
                ->whereNotNull('phase_id')
                ->pluck('phase_id');

            $unconvertedNet = bcadd(
                (string) ProjectPhase::query()
                    ->whereIn('id', $unconvertedPhaseIds)
                    ->sum('phase_net_budget'),
                '0',
                2
            );
        }

        $sharePct = '0.00';
        $gross = '0.00';
        $estimated = '0.00';

        if (bccomp($unconvertedNet, '0', 2) === 1 && bccomp($phaseNet, '0', 2) === 1
            && bccomp($recognizable, '0', 2) === 1) {
            $sharePct = bcmul(bcdiv($phaseNet, $unconvertedNet, 8), '100', 2);
            $gross = bcmul($recognizable, bcdiv($phaseNet, $unconvertedNet, 8), 2);
            $net = bcsub($gross, $pending, 2);
            $estimated = bccomp($net, '0', 2) === 1 ? $net : '0.00';
        }

        if ($sale->isConverted()) {
            $estimated = (string) $sale->profit_amount;
            $gross = $estimated;
            $sharePct = '0.00';
        }

        return [
            'remaining_budget' => $remaining,
            'recognized_amount' => $recognized,
            'live_remaining' => $liveRemaining,
            'recognizable' => $recognizable,
            'pending_deficit' => $pending,
            'unconverted_phase_net' => $unconvertedNet,
            'phase_net_budget' => $phaseNet,
            'phase_share_pct' => $sharePct,
            'gross_share' => $gross,
            'estimated_profit' => $estimated,
        ];
    }

    public function recognizedProfitForProject(Project $project): string
    {
        $sum = Sale::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [
                SaleStatus::Receivable,
                SaleStatus::PartiallyPaid,
                SaleStatus::Paid,
            ])
            ->sum('profit_amount');

        return bcadd((string) $sum, '0', 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatSale(Sale $sale, ?string $liveProfit = null): array
    {
        $sale->loadMissing([
            'project:id,code,name,client,contract_amount,net_budget,status,pending_deficit',
            'phase:id,project_id,sequence_no,name,status,disbursed_amount,phase_net_budget',
            'converter:id,name',
        ]);

        $project = $sale->project;
        $phase = $sale->phase;
        $breakdown = $project
            ? $this->receivableBreakdown($project, $sale)
            : [
                'remaining_budget' => '0.00',
                'recognized_amount' => '0.00',
                'live_remaining' => '0.00',
                'recognizable' => '0.00',
                'pending_deficit' => '0.00',
                'unconverted_phase_net' => '0.00',
                'phase_net_budget' => '0.00',
                'phase_share_pct' => '0.00',
                'gross_share' => '0.00',
                'estimated_profit' => '0.00',
            ];

        $profit = $sale->isConverted()
            ? (string) $sale->profit_amount
            : ($liveProfit ?? $breakdown['estimated_profit']);

        $outstanding = $sale->isConverted()
            ? $sale->outstandingAmount()
            : $profit;

        $contractAmount = $sale->isConverted()
            ? (string) ($sale->contract_amount ?? '0.00')
            : (string) ($phase?->disbursed_amount ?? $sale->contract_amount ?? $project?->contract_amount ?? '0.00');

        $isLossReceivable = $sale->isConverted() && bccomp((string) $sale->profit_amount, '0', 2) === -1;
        $projectIsLoss = $project?->status === ProjectStatus::Loss;

        return [
            'id' => $sale->id,
            'sale_code' => $sale->sale_code,
            'status' => $sale->status->value,
            'status_label' => $sale->status->label(),
            'contract_amount' => $contractAmount,
            'profit_amount' => $profit,
            'collected_amount' => (string) $sale->collected_amount,
            'outstanding_amount' => $outstanding,
            'converted_at' => $sale->converted_at?->toIso8601String(),
            'is_loss' => $isLossReceivable,
            'can_convert' => ! $sale->isConverted()
                && ! $projectIsLoss
                && $phase?->status === PhaseStatus::Closed
                && bccomp($profit, '0', 2) === 1,
            'can_collect' => $sale->status->isCollectable() && bccomp($outstanding, '0', 2) !== 0,
            'remaining_budget' => $breakdown['remaining_budget'],
            'recognized_amount' => $breakdown['recognized_amount'],
            'live_remaining' => $breakdown['live_remaining'],
            'recognizable_amount' => $breakdown['recognizable'],
            'pending_deficit' => $breakdown['pending_deficit'],
            'phase_share_pct' => $breakdown['phase_share_pct'],
            'project' => $project ? [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'client' => $project->client,
                'contract_amount' => (string) $project->contract_amount,
                'net_budget' => (string) $project->net_budget,
                'pending_deficit' => (string) $project->pending_deficit,
                'status' => $project->status->value,
            ] : null,
            'phase' => $phase ? [
                'id' => $phase->id,
                'sequence_no' => $phase->sequence_no,
                'name' => $phase->name,
                'status' => $phase->status->value,
                'disbursed_amount' => (string) $phase->disbursed_amount,
                'phase_net_budget' => (string) $phase->phase_net_budget,
            ] : null,
            'customer' => $project?->client,
            'converter' => $sale->converter ? [
                'id' => $sale->converter->id,
                'name' => $sale->converter->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatPayment(SaleReceivablePayment $payment): array
    {
        $payment->loadMissing(['account:id,name,type', 'recorder:id,name']);

        return [
            'id' => $payment->id,
            'sale_id' => $payment->sale_id,
            'amount' => (string) $payment->amount,
            'method' => $payment->method,
            'reference_no' => $payment->reference_no,
            'notes' => $payment->notes,
            'occurred_at' => $payment->occurred_at?->toIso8601String(),
            'account' => $payment->account ? [
                'id' => $payment->account->id,
                'name' => $payment->account->name,
                'type' => $payment->account->type->value,
            ] : null,
            'recorder' => $payment->recorder ? [
                'id' => $payment->recorder->id,
                'name' => $payment->recorder->name,
            ] : null,
        ];
    }

    private function proRataShareOf(Sale $sale, string $pool): string
    {
        $sale->loadMissing('phase');
        $phase = $sale->phase;
        if (! $phase || bccomp($pool, '0', 2) !== 1) {
            return '0.00';
        }

        $phaseNet = bcadd((string) $phase->phase_net_budget, '0', 2);
        $unconvertedPhaseIds = Sale::query()
            ->where('project_id', $sale->project_id)
            ->where('status', SaleStatus::Open)
            ->whereNotNull('phase_id')
            ->pluck('phase_id');

        $unconvertedNet = bcadd(
            (string) ProjectPhase::query()
                ->whereIn('id', $unconvertedPhaseIds)
                ->sum('phase_net_budget'),
            '0',
            2
        );

        if (bccomp($unconvertedNet, '0', 2) !== 1 || bccomp($phaseNet, '0', 2) !== 1) {
            return '0.00';
        }

        return bcmul($pool, bcdiv($phaseNet, $unconvertedNet, 8), 2);
    }

    private function generateSaleCode(ProjectPhase $phase): string
    {
        $phase->loadMissing('project');
        $projectCode = (string) ($phase->project?->code ?? 'PRJ');
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $projectCode) ?: 'PRJ');

        return 'SALE-'.$normalized.'-P'.$phase->sequence_no.'-'.$phase->id;
    }
}
