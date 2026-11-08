<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePhysicalDevicesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('physical_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->enum('category', ['SMART_TV', 'SMART_RELAY']);
            $table->enum('brand', ['SAMSUNG', 'SHELLY']);
            $table->string('model', 50)->nullable();
            $table->string('mac_address', 17)->unique();
            $table->string('ip_address', 45);
            $table->string('device_identifier', 100)->nullable();
            $table->enum('connection_status', ['UNVERIFIED', 'ONLINE', 'OFFLINE'])->default('UNVERIFIED');
            $table->enum('pairing_status', ['UNVERIFIED', 'NOT_REQUIRED', 'PENDING', 'PAIRED', 'FAILED'])->default('UNVERIFIED');
            $table->text('pairing_payload')->nullable();
            $table->json('verified_capabilities')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['ip_address', 'is_active'], 'idx_physical_devices_ip_active');
            $table->index(['category', 'brand', 'is_active'], 'idx_physical_devices_cat_brand');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('physical_devices');
    }
}
