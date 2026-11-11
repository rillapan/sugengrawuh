<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateTvControlEventsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Explicit safety check: strictly require kasir_ps_test or billing-sugeng-rawuh
        if (!in_array(DB::connection()->getDatabaseName(), ['kasir_ps_test', 'billing-sugeng-rawuh'], true)) {
            throw new \RuntimeException("SAFETY ABORT: Migration is only authorized on 'kasir_ps_test' or 'billing-sugeng-rawuh'. Active DB: " . DB::connection()->getDatabaseName());
        }

        if (!Schema::hasTable('tv_control_events')) {
            Schema::create('tv_control_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('station_id');
                $table->string('station_name', 255);
                $table->unsignedBigInteger('transaction_id')->nullable();
                $table->unsignedBigInteger('physical_device_id')->nullable();
                $table->unsignedBigInteger('station_device_mapping_id')->nullable();
                $table->unsignedInteger('session_generation')->default(1);
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('actor_role', 30)->default('SYSTEM');

                // Event Classification & Command
                $table->string('event_type', 40); // MANUAL_ALERT, BILLING_ENDING_SOON, VOLUME_Control
                $table->string('command', 40);    // SHOW_ALERT, VOLUME_DOWN, VOLUME_UP, MUTE_TOGGLE
                $table->string('message_preset', 60)->nullable();
                $table->string('message_title', 120)->nullable();
                $table->text('message_body')->nullable();
                $table->unsignedTinyInteger('display_duration_seconds')->default(8);

                // Simulated State Snapshot
                $table->unsignedTinyInteger('simulated_volume')->nullable();
                $table->boolean('simulated_muted')->nullable();
                $table->string('control_mode', 20)->default('MOCK');
                $table->string('driver_type', 40)->default('MOCK_SAMSUNG_TV');

                // Idempotency & Lifecycle Status
                $table->string('idempotency_key', 160)->unique();
                $table->enum('status', [
                    'PENDING',
                    'COMMAND_SENT',
                    'SENT_TO_SIMULATOR',
                    'DELIVERED_TO_APP',
                    'ACKED_BY_SIMULATOR',
                    'ACCEPTED',
                    'EXPIRED',
                    'CANCELLED',
                    'STALE',
                    'FAILED',
                    'REJECTED',
                    'UNVERIFIED'
                ])->default('PENDING');

                $table->string('result_code', 50)->nullable();
                $table->string('error_message', 255)->nullable();

                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('acked_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();

                // Indexes for fast polling and audit history
                $table->index(['station_id', 'status', 'id'], 'idx_tv_events_station_status');
                $table->index(['transaction_id', 'event_type'], 'idx_tv_events_tx_type');
                $table->index(['station_id', 'session_generation'], 'idx_tv_events_station_gen');

                // Foreign keys
                $table->foreign('station_id', 'fk_tv_events_station')
                    ->references('id')->on('devices')
                    ->onDelete('cascade');

                $table->foreign('transaction_id', 'fk_tv_events_tx')
                    ->references('id_transaksi')->on('transactions')
                    ->onDelete('set null');

                $table->foreign('physical_device_id', 'fk_tv_events_phys_dev')
                    ->references('id')->on('physical_devices')
                    ->onDelete('set null');

                $table->foreign('station_device_mapping_id', 'fk_tv_events_mapping')
                    ->references('id')->on('station_device_mappings')
                    ->onDelete('set null');

                $table->foreign('actor_user_id', 'fk_tv_events_actor')
                    ->references('id')->on('users')
                    ->onDelete('set null');
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

        Schema::dropIfExists('tv_control_events');
    }
}

