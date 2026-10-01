<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateApiRateLimitTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('api_rate_limits', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('api_key_id')->unique()->index();
            $table->unsignedInteger('request_count')->default(0);
            $table->timestamp('window_starts_at');
            $table->timestamps();

            $table->foreign('api_key_id')
                ->references('id')
                ->on('api_keys')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('api_rate_limits');
    }
}
