<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

// add_pages_is_blog
class AddPagesIsBlog extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('wz_pages', function (Blueprint $table) {
            $table->tinyInteger('is_blog', false, false)->default(0)->comment('是否设置为博客');
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
            $table->dropColumn(['is_blog']);
        });
    }
}
