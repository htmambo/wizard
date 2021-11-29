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
        Schema::table('wz_pages', function (Blueprint $table) {
            $table->longText('html_code')->nullable()->comment('Markdown渲染后的HTML内容');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('wz_pages', function (Blueprint $table) {
            $table->dropColumn(['html_code']);
        });
    }
}
