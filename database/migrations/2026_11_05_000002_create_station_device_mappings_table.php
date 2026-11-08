<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStationDeviceMappingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('station_device_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('station_id');
            $table->unsignedBigInteger('physical_device_id');
            $table->enum('mapping_role', ['DISPLAY_TV', 'LIGHT_RELAY']);
            $table->enum('mapping_status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->unsignedBigInteger('attached_by_user_id')->nullable();
            $table->unsignedBigInteger('detached_by_user_id')->nullable();
            $table->timestamp('attached_at')->useCurrent();
            $table->timestamp('detached_at')->nullable();
            $table->text('attach_notes')->nullable();
            $table->text('detach_notes')->nullable();

            // Virtual Generated Columns for MySQL Active Uniqueness
            $table->string('active_physical_device_id', 32)
                ->virtualAs("IF(mapping_status = 'ACTIVE', CAST(physical_device_id AS CHAR), NULL)")
                ->nullable();

            $table->string('active_station_role', 64)
                ->virtualAs("IF(mapping_status = 'ACTIVE', CONCAT(station_id, '_', mapping_role), NULL)")
                ->nullable();

            $table->timestamps();

            // Foreign Keys with ON DELETE RESTRICT for historical protection
            $table->foreign('station_id')
                ->references('id')
                ->on('devices')
                ->onDelete('restrict');

            $table->foreign('physical_device_id')
                ->references('id')
                ->on('physical_devices')
                ->onDelete('restrict');

            $table->foreign('attached_by_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->foreign('detached_by_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            // Unique constraints on active virtual columns
            $table->unique('active_physical_device_id', 'uq_active_physical_device');
            $table->unique('active_station_role', 'uq_active_station_role');

            // History index
            $table->index(['station_id', 'attached_at'], 'idx_station_mapping_history');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('station_device_mappings');
    }
}
