<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateApiWebhooksTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('api_webhooks', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->index();
            $table->string('url');
            $table->json('events'); // ["conversation.created", "thread.created", ...]
            $table->string('secret_key')->nullable(); // For HMAC signature
            $table->boolean('active')->default(true);
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('api_webhooks');
    }
}
