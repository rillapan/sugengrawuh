<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddSessionGenerationAndHardwareFields extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Explicit safety check: strictly require kasir_ps_test or billing-sugeng-rawuh
        if (!in_array(DB::connection()->getDatabaseName(), ['kasir_ps_test', 'billing-sugeng-rawuh', 'sugeng_rawuh_pos'], true)) {
            throw new \RuntimeException("SAFETY ABORT: Migration is only authorized on 'kasir_ps_test' or 'billing-sugeng-rawuh'. Active DB: " . DB::connection()->getDatabaseName());
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedInteger('session_generation')->default(1)->after('status');
            $table->string('last_hardware_state', 30)->default('STATE_UNKNOWN')->after('session_generation');
            $table->timestamp('last_hardware_verified_at')->nullable()->after('last_hardware_state');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedInteger('session_generation')->default(1)->after('billing_status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!in_array(DB::connection()->getDatabaseName(), ['kasir_ps_test', 'billing-sugeng-rawuh', 'sugeng_rawuh_pos'], true)) {
            throw new \RuntimeException("SAFETY ABORT: Rollback is only authorized on 'kasir_ps_test' or 'billing-sugeng-rawuh'. Active DB: " . DB::connection()->getDatabaseName());
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('session_generation');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'session_generation',
                'last_hardware_state',
                'last_hardware_verified_at'
            ]);
        });
    }
}
