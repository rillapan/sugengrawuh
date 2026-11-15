<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInvoicesAndItemsTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->string('invoice_number', 50)->unique();
                $table->unsignedBigInteger('transaction_id')->nullable()->index();
                $table->foreign('transaction_id')->references('id_transaksi')->on('transactions')->onDelete('set null');
                $table->enum('invoice_type', [
                    'GAME_AND_FNB',
                    'GAME_ONLY',
                    'FNB_STANDALONE',
                ])->default('GAME_AND_FNB');
                $table->decimal('subtotal', 12, 2);
                $table->decimal('discount', 12, 2)->default(0);
                $table->decimal('final_total', 12, 2);
                $table->decimal('amount_paid', 12, 2);
                $table->decimal('change_amount', 12, 2)->default(0);
                $table->enum('payment_method', ['tunai', 'e-wallet', 'transfer_bank']);
                $table->unsignedBigInteger('cashier_user_id')->index();
                $table->foreign('cashier_user_id')->references('id')->on('users')->onDelete('restrict');
                $table->timestamp('paid_at');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('invoice_items')) {
            Schema::create('invoice_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('invoice_id')->index();
                $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
                $table->enum('item_type', ['GAME_RENTAL', 'FNB_ITEM']);
                $table->unsignedBigInteger('fnb_id')->nullable()->index();
                $table->foreign('fnb_id')->references('id')->on('fnbs')->onDelete('set null');
                $table->unsignedBigInteger('fnb_order_item_id')->nullable()->unique();
                $table->foreign('fnb_order_item_id')->references('id')->on('fnb_order_items')->onDelete('restrict');
                $table->unsignedBigInteger('transaction_fnb_id')->nullable()->unique();
                $table->foreign('transaction_fnb_id')->references('id')->on('transaction_fnbs')->onDelete('restrict');
                $table->string('name', 150);
                $table->integer('qty')->default(1);
                $table->decimal('unit_price_beli', 12, 2)->default(0);
                $table->decimal('unit_price_jual', 12, 2);
                $table->decimal('subtotal', 12, 2);
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
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
}
