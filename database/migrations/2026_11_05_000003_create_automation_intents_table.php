<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateAutomationIntentsTable extends Migration
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

        Schema::create('automation_intents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('station_id');
            $table->string('station_name', 255)->nullable();
            $table->unsignedBigInteger('physical_device_id')->nullable();
            $table->unsignedBigInteger('station_device_mapping_id')->nullable();
            $table->unsignedInteger('session_generation')->default(1);
            $table->string('event_type', 40);
            $table->string('desired_state', 20);
            $table->unsignedTinyInteger('target_channel')->default(0);
            $table->string('idempotency_key', 140)->unique();
            $table->enum('status', [
                'PENDING',
                'PROCESSING',
                'COMPLETED',
                'FAILED',
                'RETRY_PENDING',
                'CANCELLED',
                'STALE',
                'SKIPPED'
            ])->default('PENDING');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(4);
            $table->timestamp('scheduled_at')->useCurrent();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('locked_by', 60)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error_category', 50)->nullable();
            $table->string('last_error_message', 255)->nullable();
            $table->timestamps();

            // Indexes for fast polling and fencing
            $table->index(['status', 'scheduled_at'], 'idx_auto_intents_poll_pending');
            $table->index(['status', 'next_retry_at'], 'idx_auto_intents_poll_retry');
            $table->index(['station_id', 'session_generation'], 'idx_auto_intents_station_gen');
            $table->index('transaction_id', 'idx_auto_intents_tx');

            // Foreign keys
            $table->foreign('station_id', 'fk_auto_intents_station')
                ->references('id')->on('devices')
                ->onDelete('restrict');

            $table->foreign('transaction_id', 'fk_auto_intents_transaction')
                ->references('id_transaksi')->on('transactions')
                ->onDelete('set null');

            $table->foreign('physical_device_id', 'fk_auto_intents_physical_device')
                ->references('id')->on('physical_devices')
                ->onDelete('set null');

            $table->foreign('station_device_mapping_id', 'fk_auto_intents_mapping')
                ->references('id')->on('station_device_mappings')
                ->onDelete('set null');
        });
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

        Schema::dropIfExists('automation_intents');
    }
}
