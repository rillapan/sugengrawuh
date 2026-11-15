<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class MakeFnbOrdersLocationIdNullable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('fnb_orders') && Schema::hasColumn('fnb_orders', 'location_id')) {
            Schema::table('fnb_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('location_id')->nullable()->change();
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
        // Non-destructive: keep column nullable to avoid failing on existing walk-in orders
    }
}
