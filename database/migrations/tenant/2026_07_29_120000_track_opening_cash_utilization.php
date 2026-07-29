<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_allocations', function (Blueprint $table) {
            $table->decimal('opening_utilized_amount', 15, 2)
                ->default(0)
                ->after('utilized_amount');
        });

        DB::table('cash_allocations')
            ->orderBy('id')
            ->each(function (object $allocation): void {
                $recordedDisbursements = (string) DB::table('cash_disbursements')
                    ->where('cash_allocation_id', $allocation->id)
                    ->sum('amount');

                $openingUtilized = bcsub(
                    (string) $allocation->utilized_amount,
                    $recordedDisbursements,
                    2,
                );

                DB::table('cash_allocations')
                    ->where('id', $allocation->id)
                    ->update([
                        'opening_utilized_amount' => bccomp($openingUtilized, '0', 2) === 1
                            ? $openingUtilized
                            : '0.00',
                        'utilized_amount' => bcadd(
                            bccomp($openingUtilized, '0', 2) === 1 ? $openingUtilized : '0.00',
                            $recordedDisbursements,
                            2,
                        ),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('cash_allocations', function (Blueprint $table) {
            $table->dropColumn('opening_utilized_amount');
        });
    }
};
