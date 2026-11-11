<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddGameConsoleSupportToHardwareTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Safety check: ensure authorized database
        if (!in_array(DB::connection()->getDatabaseName(), ['kasir_ps_test', 'billing-sugeng-rawuh', 'sugeng_rawuh_pos'], true)) {
            throw new \RuntimeException("SAFETY ABORT: Migration is only authorized on 'kasir_ps_test' or 'billing-sugeng-rawuh'. Active DB: " . DB::connection()->getDatabaseName());
        }

        // 1. Modify physical_devices category and brand to varchar(50) to support GAME_CONSOLE, SONY, PLAYSTATION
        if (Schema::hasTable('physical_devices')) {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE physical_devices MODIFY COLUMN category VARCHAR(50) NOT NULL");
                DB::statement("ALTER TABLE physical_devices MODIFY COLUMN brand VARCHAR(50) NOT NULL");
            }
        }

        // 2. Modify station_device_mappings mapping_role to varchar(50) to support GAME_CONSOLE
        if (Schema::hasTable('station_device_mappings')) {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE station_device_mappings MODIFY COLUMN mapping_role VARCHAR(50) NOT NULL");
            }
        }

        // 3. Add target_channel to tv_control_events if not present
        if (Schema::hasTable('tv_control_events')) {
            Schema::table('tv_control_events', function (Blueprint $table) {
                if (!Schema::hasColumn('tv_control_events', 'target_channel')) {
                    $table->string('target_channel', 30)->default('TV')->after('driver_type');
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
        if (Schema::hasTable('tv_control_events')) {
            Schema::table('tv_control_events', function (Blueprint $table) {
                if (Schema::hasColumn('tv_control_events', 'target_channel')) {
                    $table->dropColumn('target_channel');
                }
            });
        }
    }
}

