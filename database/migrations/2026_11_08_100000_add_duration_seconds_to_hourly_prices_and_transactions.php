<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddDurationSecondsToHourlyPricesAndTransactions extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!in_array(DB::connection()->getDatabaseName(), ['kasir_ps_test', 'billing-sugeng-rawuh'], true)) {
            throw new \RuntimeException("SAFETY ABORT: Migration is only authorized on 'kasir_ps_test' or 'billing-sugeng-rawuh'. Active DB: " . DB::connection()->getDatabaseName());
        }

        // 1. Update hourly_prices table
        Schema::table('hourly_prices', function (Blueprint $table) {
            if (!Schema::hasColumn('hourly_prices', 'duration_seconds')) {
                $table->unsignedInteger('duration_seconds')->nullable()->after('hour');
            }
        });

        // Backfill duration_seconds from existing hour column
        DB::statement("
            UPDATE hourly_prices 
            SET duration_seconds = hour * 3600 
            WHERE duration_seconds IS NULL AND hour IS NOT NULL
        ");

        // Swap unique constraint on hourly_prices:
        // First add index on playstation_id so MySQL foreign key constraint remains satisfied
        Schema::table('hourly_prices', function (Blueprint $table) {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $sm->listTableIndexes('hourly_prices');
            if (!array_key_exists('hourly_prices_playstation_id_index', $indexes)) {
                $table->index('playstation_id', 'hourly_prices_playstation_id_index');
            }
        });

        Schema::table('hourly_prices', function (Blueprint $table) {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $sm->listTableIndexes('hourly_prices');
            if (array_key_exists('hourly_prices_playstation_id_hour_unique', $indexes)) {
                $table->dropUnique('hourly_prices_playstation_id_hour_unique');
            }
            if (!array_key_exists('hourly_prices_ps_duration_unique', $indexes)) {
                $table->unique(['playstation_id', 'duration_seconds'], 'hourly_prices_ps_duration_unique');
            }
            $table->integer('hour')->nullable()->change();
        });

        // 2. Update transactions table
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'duration_seconds')) {
                $table->unsignedInteger('duration_seconds')->nullable()->after('jam_main');
            }
        });

        // Idempotent backfill for existing transactions
        // For prepaid with numeric jam_main, convert hours to seconds
        DB::statement("
            UPDATE transactions 
            SET duration_seconds = CAST(jam_main AS UNSIGNED) * 3600 
            WHERE duration_seconds IS NULL 
              AND tipe_transaksi = 'prepaid' 
              AND jam_main REGEXP '^[0-9]+$'
        ");

        // For custom_package with numeric jam_main, convert minutes to seconds
        DB::statement("
            UPDATE transactions 
            SET duration_seconds = CAST(jam_main AS UNSIGNED) * 60 
            WHERE duration_seconds IS NULL 
              AND tipe_transaksi = 'custom_package' 
              AND jam_main REGEXP '^[0-9]+$'
        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!in_array(DB::connection()->getDatabaseName(), ['kasir_ps_test', 'billing-sugeng-rawuh'], true)) {
            throw new \RuntimeException("SAFETY ABORT: Rollback is only authorized on 'kasir_ps_test' or 'billing-sugeng-rawuh'. Active DB: " . DB::connection()->getDatabaseName());
        }

        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'duration_seconds')) {
                $table->dropColumn('duration_seconds');
            }
        });

        Schema::table('hourly_prices', function (Blueprint $table) {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $sm->listTableIndexes('hourly_prices');
            if (!array_key_exists('hourly_prices_playstation_id_hour_unique', $indexes)) {
                $table->unique(['playstation_id', 'hour'], 'hourly_prices_playstation_id_hour_unique');
            }
            if (array_key_exists('hourly_prices_ps_duration_unique', $indexes)) {
                $table->dropUnique('hourly_prices_ps_duration_unique');
            }
            if (array_key_exists('hourly_prices_playstation_id_index', $indexes)) {
                $table->dropIndex('hourly_prices_playstation_id_index');
            }
            if (Schema::hasColumn('hourly_prices', 'duration_seconds')) {
                $table->dropColumn('duration_seconds');
            }
            $table->integer('hour')->nullable(false)->change();
        });
    }
}
