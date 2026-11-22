<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionPricingSegmentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('transaction_pricing_segments')) {
            Schema::create('transaction_pricing_segments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('transaction_id')->index();
                $table->unsignedInteger('segment_order')->default(1);
                $table->unsignedBigInteger('device_id')->nullable()->index();
                $table->unsignedBigInteger('playstation_id')->nullable()->index();
                $table->string('playstation_name_snapshot', 150);
                $table->decimal('hourly_rate_snapshot', 12, 2)->default(0);
                $table->string('pricing_mode', 32)->default('from_now'); // 'initial', 'correction', 'from_now'
                $table->boolean('is_preset_price')->default(false);
                $table->timestamp('started_at');
                $table->timestamp('ended_at')->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->decimal('raw_amount', 12, 4)->nullable();
                $table->decimal('billed_amount', 12, 2)->nullable();
                $table->unsignedBigInteger('changed_by_user_id')->nullable()->index();
                $table->string('change_reason', 255)->nullable();
                $table->timestamps();

                $table->foreign('transaction_id')->references('id_transaksi')->on('transactions')->onDelete('cascade');
                $table->foreign('device_id')->references('id')->on('devices')->onDelete('set null');
                $table->foreign('playstation_id')->references('id')->on('playstations')->onDelete('set null');
                $table->foreign('changed_by_user_id')->references('id')->on('users')->onDelete('set null');

                $table->index(['transaction_id', 'segment_order'], 'tx_pricing_segments_tx_order_idx');
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
        Schema::dropIfExists('transaction_pricing_segments');
    }
}
