<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSessionTokenToTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('transactions', 'session_token')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->string('session_token', 64)->nullable()->unique()->after('billing_status');
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
        if (Schema::hasColumn('transactions', 'session_token')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn('session_token');
            });
        }
    }
}
