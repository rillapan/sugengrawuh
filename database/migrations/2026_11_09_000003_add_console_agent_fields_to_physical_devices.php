<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddConsoleAgentFieldsToPhysicalDevices extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('physical_devices', function (Blueprint $table) {
            if (!Schema::hasColumn('physical_devices', 'device_token_hash')) {
                $table->string('device_token_hash', 64)->nullable()->after('device_identifier')->index();
            }
            if (!Schema::hasColumn('physical_devices', 'firmware_version')) {
                $table->string('firmware_version', 50)->nullable()->after('model');
            }
            if (!Schema::hasColumn('physical_devices', 'homebrew_type')) {
                $table->string('homebrew_type', 50)->nullable()->after('firmware_version');
            }
            if (!Schema::hasColumn('physical_devices', 'homebrew_version')) {
                $table->string('homebrew_version', 50)->nullable()->after('homebrew_type');
            }
            if (!Schema::hasColumn('physical_devices', 'driver_type')) {
                $table->string('driver_type', 50)->nullable()->after('homebrew_version');
            }
            if (!Schema::hasColumn('physical_devices', 'transport_config')) {
                $table->json('transport_config')->nullable()->after('driver_type');
            }
        });

        // Make mac_address nullable for game consoles that don't specify it initially
        Schema::table('physical_devices', function (Blueprint $table) {
            $table->string('mac_address', 20)->nullable()->change();
        });

        Schema::table('tv_control_events', function (Blueprint $table) {
            if (!Schema::hasColumn('tv_control_events', 'agent_response_payload')) {
                $table->text('agent_response_payload')->nullable()->after('hardware_error_message');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('physical_devices', function (Blueprint $table) {
            if (Schema::hasColumn('physical_devices', 'device_token_hash')) {
                $table->dropIndex(['device_token_hash']);
                $table->dropColumn('device_token_hash');
            }
            if (Schema::hasColumn('physical_devices', 'firmware_version')) {
                $table->dropColumn('firmware_version');
            }
            if (Schema::hasColumn('physical_devices', 'homebrew_type')) {
                $table->dropColumn('homebrew_type');
            }
            if (Schema::hasColumn('physical_devices', 'homebrew_version')) {
                $table->dropColumn('homebrew_version');
            }
            if (Schema::hasColumn('physical_devices', 'driver_type')) {
                $table->dropColumn('driver_type');
            }
            if (Schema::hasColumn('physical_devices', 'transport_config')) {
                $table->dropColumn('transport_config');
            }
        });

        Schema::table('tv_control_events', function (Blueprint $table) {
            if (Schema::hasColumn('tv_control_events', 'agent_response_payload')) {
                $table->dropColumn('agent_response_payload');
            }
        });
    }
}

