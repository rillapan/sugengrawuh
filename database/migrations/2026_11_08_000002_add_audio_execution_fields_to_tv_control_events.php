<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddAudioExecutionFieldsToTvControlEvents extends Migration
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

        if (Schema::hasTable('tv_control_events')) {
            Schema::table('tv_control_events', function (Blueprint $table) {
                if (!Schema::hasColumn('tv_control_events', 'execution_status')) {
                    $table->string('execution_status', 32)->nullable()->after('status');
                }
                if (!Schema::hasColumn('tv_control_events', 'real_volume')) {
                    $table->unsignedTinyInteger('real_volume')->nullable()->after('simulated_volume');
                }
                if (!Schema::hasColumn('tv_control_events', 'real_muted')) {
                    $table->boolean('real_muted')->nullable()->after('simulated_muted');
                }
                if (!Schema::hasColumn('tv_control_events', 'hardware_error_code')) {
                    $table->string('hardware_error_code', 64)->nullable()->after('result_code');
                }
                if (!Schema::hasColumn('tv_control_events', 'hardware_error_message')) {
                    $table->text('hardware_error_message')->nullable()->after('error_message');
                }
            });
        }
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

        if (Schema::hasTable('tv_control_events')) {
            Schema::table('tv_control_events', function (Blueprint $table) {
                $columnsToDrop = [];
                foreach (['execution_status', 'real_volume', 'real_muted', 'hardware_error_code', 'hardware_error_message'] as $col) {
                    if (Schema::hasColumn('tv_control_events', $col)) {
                        $columnsToDrop[] = $col;
                    }
                }
                if (!empty($columnsToDrop)) {
                    $table->dropColumn($columnsToDrop);
                }
            });
        }
    }
}

