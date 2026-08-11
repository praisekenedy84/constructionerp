<?php

namespace App\Services;

use App\Enums\CompanyDebtStatus;
use App\Enums\DepositSource;
use App\Models\CompanyDebt;
use App\Models\CompanyDebtPayment;
use App\Models\MoneyAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CompanyDebtService
{
    public function __construct(
        private readonly MoneyAccountService $moneyAccountService,
    ) {}

    /**
     * @param  array{
     *     method?: string|null,
     *     reference_no?: string|null,
     *     notes?: string|null,
     *     occurred_at?: mixed
     * }  $opts
     */
    public function recordRepayment(
        CompanyDebt $debt,
        string $amount,
        MoneyAccount $account,
        User $actor,
        array $opts = [],
    ): CompanyDebtPayment {
        return DB::transaction(function () use ($debt, $amount, $account, $actor, $opts) {
            $debt = CompanyDebt::lockForUpdate()->findOrFail($debt->id);

            if (! $debt->status->isPayable()) {
                throw new \InvalidArgumentException('This debt has already been cleared.');
            }

            $normalized = bcadd($amount, '0', 2);
            if (bccomp($normalized, '0', 2) !== 1) {
                throw new \InvalidArgumentException('Repayment amount must be greater than zero.');
            }

            if (bccomp($normalized, (string) $debt->outstanding_amount, 2) === 1) {
                throw new \InvalidArgumentException(
                    "Repayment cannot exceed outstanding amount ({$debt->outstanding_amount})."
                );
            }

            $transaction = $this->moneyAccountService->repayDebtFromManager(
                $account,
                $normalized,
                $actor,
                [
                    'description' => "Debt repayment — {$debt->creditor_name}",
                    'reference_no' => $opts['reference_no'] ?? null,
                    'method' => $opts['method'] ?? null,
                    'reference_entity_type' => 'company_debt',
                    'reference_entity_id' => $debt->id,
                    'occurred_at' => $opts['occurred_at'] ?? now(),
                ],
            );

            $payment = CompanyDebtPayment::create([
                'company_debt_id' => $debt->id,
                'amount' => $normalized,
                'money_account_id' => $account->id,
                'account_transaction_id' => $transaction->id,
                'notes' => $opts['notes'] ?? null,
                'method' => $opts['method'] ?? null,
                'reference_no' => $opts['reference_no'] ?? null,
                'recorded_by' => $actor->id,
                'occurred_at' => $opts['occurred_at'] ?? now(),
            ]);

            $outstanding = bcsub((string) $debt->outstanding_amount, $normalized, 2);
            $status = bccomp($outstanding, '0', 2) === 0
                ? CompanyDebtStatus::Cleared
                : CompanyDebtStatus::PartiallyPaid;

            $debt->update([
                'outstanding_amount' => $outstanding,
                'status' => $status,
            ]);

            return $payment->fresh(['moneyAccount', 'recorder']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function formatDebt(CompanyDebt $debt): array
    {
        $debt->loadMissing(['moneyAccount:id,name,type', 'recorder:id,name']);

        return [
            'id' => $debt->id,
            'type' => $debt->type->value,
            'type_label' => $debt->type->label(),
            'creditor_name' => $debt->creditor_name,
            'original_amount' => (string) $debt->original_amount,
            'outstanding_amount' => (string) $debt->outstanding_amount,
            'status' => $debt->status->value,
            'status_label' => $debt->status->label(),
            'money_account_id' => $debt->money_account_id,
            'deposit_transaction_id' => $debt->deposit_transaction_id,
            'notes' => $debt->notes,
            'occurred_at' => $debt->occurred_at?->toIso8601String(),
            'created_at' => $debt->created_at?->toIso8601String(),
            'money_account' => $debt->moneyAccount ? [
                'id' => $debt->moneyAccount->id,
                'name' => $debt->moneyAccount->name,
                'type' => $debt->moneyAccount->type->value,
            ] : null,
            'recorder' => $debt->recorder ? [
                'id' => $debt->recorder->id,
                'name' => $debt->recorder->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatPayment(CompanyDebtPayment $payment): array
    {
        $payment->loadMissing(['moneyAccount:id,name,type', 'recorder:id,name']);

        return [
            'id' => $payment->id,
            'company_debt_id' => $payment->company_debt_id,
            'amount' => (string) $payment->amount,
            'money_account_id' => $payment->money_account_id,
            'account_transaction_id' => $payment->account_transaction_id,
            'notes' => $payment->notes,
            'method' => $payment->method,
            'reference_no' => $payment->reference_no,
            'occurred_at' => $payment->occurred_at?->toIso8601String(),
            'money_account' => $payment->moneyAccount ? [
                'id' => $payment->moneyAccount->id,
                'name' => $payment->moneyAccount->name,
                'type' => $payment->moneyAccount->type->value,
            ] : null,
            'recorder' => $payment->recorder ? [
                'id' => $payment->recorder->id,
                'name' => $payment->recorder->name,
            ] : null,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function depositSourceOptions(): array
    {
        return array_map(
            fn (DepositSource $source) => [
                'value' => $source->value,
                'label' => $source->label(),
            ],
            DepositSource::cases(),
        );
    }
}
