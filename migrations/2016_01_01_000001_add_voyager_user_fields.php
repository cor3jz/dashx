<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddVoyagerUserFields extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('email')->default('users/default.png');
            $table->string('username')->nullable()->unique()->after('name');
            $table->bigInteger('role_id')->nullable()->after('id');
            $table->text('settings')->nullable()->default(null)->after('remember_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar');
            $table->dropColumn('username');
            $table->dropColumn('role_id');
            $table->dropColumn('settings');
        });

    }
}
