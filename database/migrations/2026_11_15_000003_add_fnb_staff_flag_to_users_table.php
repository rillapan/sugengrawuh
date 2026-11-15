<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFnbStaffFlagToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('users', 'is_fnb_staff')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_fnb_staff')->default(false)->after('role');
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
        if (Schema::hasColumn('users', 'is_fnb_staff')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_fnb_staff');
            });
        }
    }
}
