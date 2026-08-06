<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'tax_mode')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE invoices ALTER COLUMN tax_mode SET DEFAULT 'inclusive'");
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE invoices MODIFY tax_mode VARCHAR(255) NOT NULL DEFAULT 'inclusive'");
        } elseif ($driver === 'sqlite') {
            // SQLite cannot reliably change column defaults in place; app default is inclusive.
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('invoices', 'tax_mode')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE invoices ALTER COLUMN tax_mode SET DEFAULT 'exclusive'");
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE invoices MODIFY tax_mode VARCHAR(255) NOT NULL DEFAULT 'exclusive'");
        }
    }
};
