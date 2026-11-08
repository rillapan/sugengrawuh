<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHardwarePowerCommandLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('hardware_power_command_logs')) {
            Schema::create('hardware_power_command_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('station_id')->index();
                $table->unsignedBigInteger('physical_device_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('desired_state', 20); // 'ON', 'OFF'
                $table->string('reported_state', 20); // 'ON', 'OFF', 'UNKNOWN', 'OFFLINE', 'UNSUPPORTED'
                $table->string('execution_status', 30); // 'SUCCESS', 'FAILED', 'TIMEOUT', 'UNSUPPORTED', 'REJECTED'
                $table->string('command_sent', 50);
                $table->string('driver_type', 50);
                $table->string('idempotency_key', 100)->nullable()->index();
                $table->string('result_code', 50);
                $table->text('message')->nullable();
                $table->json('telemetry')->nullable();
                $table->unsignedInteger('latency_ms')->nullable();
                $table->timestamps();

                $table->foreign('station_id')->references('id')->on('devices')->onDelete('cascade');
                $table->foreign('physical_device_id')->references('id')->on('physical_devices')->onDelete('cascade');
            });
        }

        if (Schema::hasTable('devices') && !Schema::hasColumn('devices', 'last_manual_power_at')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->timestamp('last_manual_power_at')->nullable()->after('last_hardware_verified_at');
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
        if (Schema::hasTable('devices') && Schema::hasColumn('devices', 'last_manual_power_at')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->dropColumn('last_manual_power_at');
            });
        }

        Schema::dropIfExists('hardware_power_command_logs');
    }
}

