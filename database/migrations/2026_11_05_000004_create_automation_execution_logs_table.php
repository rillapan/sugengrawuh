<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateAutomationExecutionLogsTable extends Migration
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

        Schema::create('automation_execution_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('intent_id')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('station_id')->nullable();
            $table->string('station_name', 255);
            $table->unsignedBigInteger('physical_device_id')->nullable();
            $table->string('driver_type', 40);
            $table->string('command_sent', 60);
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->enum('execution_status', [
                'SUCCESS',
                'FAILED',
                'SKIPPED',
                'RETRY_SCHEDULED'
            ]);
            $table->string('result_code', 50);
            $table->string('error_category', 50)->nullable();
            $table->text('error_details')->nullable();
            $table->string('observed_output_state', 20)->nullable();
            $table->decimal('observed_power_watts', 8, 2)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('executed_at')->useCurrent();

            // Indexes for audit log retrieval and retention pruning
            $table->index('intent_id', 'idx_auto_logs_intent');
            $table->index(['station_id', 'executed_at'], 'idx_auto_logs_station_date');
            $table->index(['execution_status', 'executed_at'], 'idx_auto_logs_status_date');

            // Foreign keys: ON DELETE SET NULL protects 90-day failed logs when intents are pruned after 30 days
            $table->foreign('intent_id', 'fk_auto_logs_intent')
                ->references('id')->on('automation_intents')
                ->onDelete('set null');

            $table->foreign('station_id', 'fk_auto_logs_station')
                ->references('id')->on('devices')
                ->onDelete('set null');

            $table->foreign('transaction_id', 'fk_auto_logs_transaction')
                ->references('id_transaksi')->on('transactions')
                ->onDelete('set null');

            $table->foreign('physical_device_id', 'fk_auto_logs_physical_device')
                ->references('id')->on('physical_devices')
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
        if (!in_array(DB::connection()->getDatabaseName(), ['kasir_ps_test', 'billing-sugeng-rawuh', 'sugeng_rawuh_pos'], true)) {
            throw new \RuntimeException("SAFETY ABORT: Rollback is only authorized on 'kasir_ps_test' or 'billing-sugeng-rawuh'. Active DB: " . DB::connection()->getDatabaseName());
        }

        Schema::dropIfExists('automation_execution_logs');
    }
}
