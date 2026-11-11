<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddAutoVolumeLimitToPhysicalDevicesAndCreateEnforcementLogs extends Migration
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

        // 1. Add volume limit fields to physical_devices
        if (Schema::hasTable('physical_devices')) {
            Schema::table('physical_devices', function (Blueprint $table) {
                if (!Schema::hasColumn('physical_devices', 'auto_volume_limit_enabled')) {
                    $table->boolean('auto_volume_limit_enabled')->default(false)->after('verified_capabilities');
                }
                if (!Schema::hasColumn('physical_devices', 'max_volume_limit')) {
                    $table->unsignedTinyInteger('max_volume_limit')->default(50)->after('auto_volume_limit_enabled');
                }
                if (!Schema::hasColumn('physical_devices', 'current_volume')) {
                    $table->unsignedTinyInteger('current_volume')->nullable()->after('max_volume_limit');
                }
                if (!Schema::hasColumn('physical_devices', 'is_muted')) {
                    $table->boolean('is_muted')->default(false)->after('current_volume');
                }
                if (!Schema::hasColumn('physical_devices', 'last_volume_verified_at')) {
                    $table->timestamp('last_volume_verified_at')->nullable()->after('is_muted');
                }
                if (!Schema::hasColumn('physical_devices', 'volume_enforcement_status')) {
                    $table->string('volume_enforcement_status', 40)->default('WITHIN_LIMIT')->after('last_volume_verified_at');
                }
                if (!Schema::hasColumn('physical_devices', 'auto_volume_limit_updated_by_user_id')) {
                    $table->unsignedBigInteger('auto_volume_limit_updated_by_user_id')->nullable()->after('volume_enforcement_status');
                }
            });
        }

        // 2. Create table tv_volume_enforcement_logs
        if (!Schema::hasTable('tv_volume_enforcement_logs')) {
            Schema::create('tv_volume_enforcement_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('station_id');
                $table->unsignedBigInteger('physical_device_id');
                $table->unsignedTinyInteger('previous_volume');
                $table->unsignedTinyInteger('enforced_volume');
                $table->unsignedTinyInteger('max_limit');
                $table->string('enforcement_source', 40)->default('TIZEN_AGENT'); // TIZEN_AGENT, ADMIN_ENFORCE, SIMULATOR
                $table->string('status', 40)->default('ENFORCED'); // ENFORCED, FAILED, UNSUPPORTED
                $table->text('details')->nullable();
                $table->timestamps();

                $table->index(['station_id', 'created_at'], 'idx_tv_vol_enforce_station_created');
                $table->index(['physical_device_id', 'created_at'], 'idx_tv_vol_enforce_device_created');
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
        if (!in_array(DB::connection()->getDatabaseName(), ['kasir_ps_test', 'billing-sugeng-rawuh', 'sugeng_rawuh_pos'], true)) {
            throw new \RuntimeException("SAFETY ABORT: Rollback is only authorized on 'kasir_ps_test' or 'billing-sugeng-rawuh'. Active DB: " . DB::connection()->getDatabaseName());
        }

        // 1. Drop tv_volume_enforcement_logs table
        Schema::dropIfExists('tv_volume_enforcement_logs');

        // 2. Drop added columns from physical_devices
        if (Schema::hasTable('physical_devices')) {
            Schema::table('physical_devices', function (Blueprint $table) {
                $columnsToDrop = [];
                $potentialColumns = [
                    'auto_volume_limit_enabled',
                    'max_volume_limit',
                    'current_volume',
                    'is_muted',
                    'last_volume_verified_at',
                    'volume_enforcement_status',
                    'auto_volume_limit_updated_by_user_id',
                ];

                foreach ($potentialColumns as $column) {
                    if (Schema::hasColumn('physical_devices', $column)) {
                        $columnsToDrop[] = $column;
                    }
                }

                if (!empty($columnsToDrop)) {
                    $table->dropColumn($columnsToDrop);
                }
            });
        }
    }
}

