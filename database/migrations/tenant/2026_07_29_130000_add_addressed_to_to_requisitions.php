<?php

use App\Enums\FulfillmentType;
use App\Enums\RequisitionAddressedTo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->string('addressed_to')->default(RequisitionAddressedTo::Finance->value)->after('fulfillment_type');
        });

        DB::table('requisitions')
            ->orderBy('id')
            ->each(function (object $row): void {
                $addressedTo = $row->fulfillment_type === FulfillmentType::StockIssue->value
                    ? RequisitionAddressedTo::Storekeeper->value
                    : RequisitionAddressedTo::Finance->value;

                DB::table('requisitions')
                    ->where('id', $row->id)
                    ->update(['addressed_to' => $addressedTo]);
            });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn('addressed_to');
        });
    }
};
