<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFnbOrdersTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('fnb_orders')) {
            Schema::create('fnb_orders', function (Blueprint $table) {
                $table->id();
                $table->string('order_number', 50)->unique();
                $table->string('session_token', 64)->nullable()->index();
                $table->enum('source', ['QR', 'CASHIER', 'FNB_STAFF'])->default('QR');
                $table->unsignedBigInteger('location_id')->index();
                $table->foreign('location_id')->references('id')->on('locations')->onDelete('restrict');
                $table->unsignedBigInteger('transaction_id')->nullable()->index();
                $table->foreign('transaction_id')->references('id_transaksi')->on('transactions')->onDelete('set null');
                $table->string('customer_name', 100);
                $table->string('billing_name', 100)->nullable();
                $table->enum('status', [
                    'PENDING',
                    'ACCEPTED',
                    'PREPARING',
                    'READY',
                    'DELIVERED',
                    'CANCELLED',
                    'EXPIRED',
                ])->default('PENDING');
                $table->enum('payment_status', ['unpaid', 'paid'])->default('unpaid');
                $table->boolean('requires_upfront_payment')->default(false);
                $table->text('notes')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
                $table->foreign('cancelled_by_user_id')->references('id')->on('users')->onDelete('set null');
                $table->string('cancelled_reason', 255)->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('fnb_order_items')) {
            Schema::create('fnb_order_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('fnb_order_id')->index();
                $table->foreign('fnb_order_id')->references('id')->on('fnb_orders')->onDelete('cascade');
                $table->unsignedBigInteger('fnb_id')->index();
                $table->foreign('fnb_id')->references('id')->on('fnbs')->onDelete('restrict');
                $table->integer('qty');
                $table->decimal('unit_price_beli', 12, 2)->default(0);
                $table->decimal('unit_price_jual', 12, 2);
                $table->decimal('subtotal', 12, 2);
                $table->enum('payment_status', ['unpaid', 'paid'])->default('unpaid');
                $table->text('notes')->nullable();
                $table->timestamps();
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
        Schema::dropIfExists('fnb_order_items');
        Schema::dropIfExists('fnb_orders');
    }
}
