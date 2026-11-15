<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddImageToFnbsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('fnbs') && !Schema::hasColumn('fnbs', 'image')) {
            Schema::table('fnbs', function (Blueprint $table) {
                $table->string('image')->nullable()->after('deskripsi');
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
        if (Schema::hasTable('fnbs') && Schema::hasColumn('fnbs', 'image')) {
            Schema::table('fnbs', function (Blueprint $table) {
                $table->dropColumn('image');
            });
        }
    }
}
