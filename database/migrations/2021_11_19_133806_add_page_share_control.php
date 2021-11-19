<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddPageShareControl extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('wz_page_share', function (Blueprint $table) {
            $table->string('password', 32)->default('')->comment('是否需要使用密码打开');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('wz_page_share', function (Blueprint $table) {
            $table->dropColumn(['password']);
        });
    }
}
