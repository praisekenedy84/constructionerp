<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Enums\SaleStatus;
use App\Models\MoneyAccount;
use App\Models\Project;
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

    public function ensureForProject(Project $project): Sale
    {
        $existing = Sale::query()->where('project_id', $project->id)->first();
        if ($existing) {
            return $existing;
        }

        return Sale::create([
            'project_id' => $project->id,
            'sale_code' => $this->generateSaleCode($project),
            'status' => SaleStatus::Open,
            'contract_amount' => (string) $project->contract_amount,
            'profit_amount' => null,
            'collected_amount' => '0.00',
        ]);
    }

    public function ensureAllProjectsHaveSales(): int
    {
        $created = 0;

        Project::query()
            ->whereDoesntHave('sale')
            ->orderBy('id')
            ->each(function (Project $project) use (&$created) {
                $this->ensureForProject($project);
                $created++;
            });

        return $created;
    }

    public function convertToReceivable(Sale $sale, User $actor): Sale
    {
        return DB::transaction(function () use ($sale, $actor) {
            $sale = Sale::lockForUpdate()->findOrFail($sale->id);
            $sale->loadMissing('project');

            if ($sale->isConverted()) {
                throw new \InvalidArgumentException('This sale has already been converted to a receivable.');
            }

            $project = $sale->project;
            if (! $project) {
                throw new \InvalidArgumentException('Sale is missing its project.');
            }

            if ($project->status !== ProjectStatus::Closed) {
                throw new \InvalidArgumentException('Only closed projects can convert profit into a receivable.');
            }

            $profit = $this->budgetService->remainingBudget($project);
            if (bccomp($profit, '0', 2) !== 1) {
                throw new \InvalidArgumentException('Net operating profit must be greater than zero to convert.');
            }

            $sale->update([
                'status' => SaleStatus::Receivable,
                'contract_amount' => (string) $project->contract_amount,
                'profit_amount' => $profit,
                'collected_amount' => '0.00',
                'converted_at' => now(),
                'converted_by' => $actor->id,
            ]);

            return $sale->fresh(['project', 'converter', 'payments']);
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
            if (bccomp($amount, '0', 2) !== 1) {
                throw new \InvalidArgumentException('Collection amount must be greater than zero.');
            }

            $outstanding = $sale->outstandingAmount();
            if (bccomp($amount, $outstanding, 2) === 1) {
                throw new \InvalidArgumentException(
                    "Collection amount exceeds outstanding receivable ({$outstanding})."
                );
            }

            $account = MoneyAccount::findOrFail((int) $data['money_account_id']);

            $tx = $this->moneyAccountService->receiveReceivablePayment($account, $amount, $actor, [
                'description' => "Receivable collection for {$sale->sale_code}",
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
     * @return array<string, mixed>
     */
    public function formatSale(Sale $sale, ?string $liveProfit = null): array
    {
        $sale->loadMissing(['project:id,code,name,client,contract_amount,net_budget,status', 'converter:id,name']);

        $project = $sale->project;
        $profit = $sale->isConverted()
            ? (string) $sale->profit_amount
            : ($liveProfit ?? ($project ? $this->budgetService->remainingBudget($project) : '0.00'));

        $outstanding = $sale->isConverted()
            ? $sale->outstandingAmount()
            : $profit;

        return [
            'id' => $sale->id,
            'sale_code' => $sale->sale_code,
            'status' => $sale->status->value,
            'status_label' => $sale->status->label(),
            'contract_amount' => (string) ($sale->contract_amount ?? $project?->contract_amount ?? '0.00'),
            'profit_amount' => $profit,
            'collected_amount' => (string) $sale->collected_amount,
            'outstanding_amount' => $outstanding,
            'converted_at' => $sale->converted_at?->toIso8601String(),
            'can_convert' => ! $sale->isConverted()
                && $project?->status === ProjectStatus::Closed
                && bccomp($profit, '0', 2) === 1,
            'can_collect' => $sale->status->isCollectable() && bccomp($outstanding, '0', 2) === 1,
            'project' => $project ? [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'client' => $project->client,
                'contract_amount' => (string) $project->contract_amount,
                'net_budget' => (string) $project->net_budget,
                'status' => $project->status->value,
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

    private function generateSaleCode(Project $project): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string) $project->code) ?: 'PRJ');

        return 'SALE-'.$normalized.'-'.$project->id;
    }
}
