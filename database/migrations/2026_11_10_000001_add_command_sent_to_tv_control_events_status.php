<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddCommandSentToTvControlEventsStatus extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $dbName = DB::connection()->getDatabaseName();
        $driver = DB::connection()->getDriverName();

        if (!in_array($dbName, ['kasir_ps_test', 'billing-sugeng-rawuh'], true) && $driver !== 'sqlite') {
            throw new \RuntimeException("SAFETY ABORT: Migration is only authorized on 'kasir_ps_test', 'billing-sugeng-rawuh', or sqlite. Active DB: {$dbName}");
        }

        if (Schema::hasTable('tv_control_events')) {
            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE tv_control_events MODIFY COLUMN status ENUM('PENDING','COMMAND_SENT','SENT_TO_SIMULATOR','DELIVERED_TO_APP','ACKED_BY_SIMULATOR','ACCEPTED','EXPIRED','CANCELLED','STALE','FAILED','REJECTED','UNVERIFIED') DEFAULT 'PENDING'");
            }

            // Clean up historical noise: backfill existing REAL events that were recorded as SENT_TO_SIMULATOR
            DB::table('tv_control_events')
                ->where('control_mode', 'REAL')
                ->where('status', 'SENT_TO_SIMULATOR')
                ->update([
                    'status' => 'COMMAND_SENT',
                    'execution_status' => 'COMMAND_SENT',
                ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $dbName = DB::connection()->getDatabaseName();
        $driver = DB::connection()->getDriverName();

        if (!in_array($dbName, ['kasir_ps_test', 'billing-sugeng-rawuh'], true) && $driver !== 'sqlite') {
            throw new \RuntimeException("SAFETY ABORT: Rollback is only authorized on 'kasir_ps_test', 'billing-sugeng-rawuh', or sqlite. Active DB: {$dbName}");
        }

        if (Schema::hasTable('tv_control_events') && $driver === 'mysql') {
            DB::statement("ALTER TABLE tv_control_events MODIFY COLUMN status ENUM('PENDING','SENT_TO_SIMULATOR','DELIVERED_TO_APP','ACKED_BY_SIMULATOR','EXPIRED','CANCELLED','STALE','FAILED','REJECTED') DEFAULT 'PENDING'");
        }
    }
}

