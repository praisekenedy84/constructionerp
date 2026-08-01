<?php

use App\Enums\RequisitionResourceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisition_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->string('resource_type');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();
        $defaults = [];
        foreach (RequisitionResourceType::cases() as $index => $type) {
            $defaults[] = [
                'name' => $type->label(),
                'description' => null,
                'resource_type' => $type->value,
                'is_active' => true,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('requisition_categories')->insert($defaults);

        Schema::table('requisitions', function (Blueprint $table) {
            $table->foreignId('requisition_category_id')
                ->nullable()
                ->after('department')
                ->constrained('requisition_categories')
                ->nullOnDelete();
        });

        $categories = DB::table('requisition_categories')->pluck('id', 'resource_type');
        foreach ($categories as $resourceType => $categoryId) {
            DB::table('requisitions')
                ->where('resource_type', $resourceType)
                ->whereNull('requisition_category_id')
                ->update(['requisition_category_id' => $categoryId]);
        }
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requisition_category_id');
        });

        Schema::dropIfExists('requisition_categories');
    }
};
