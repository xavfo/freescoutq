<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateApiAuditLogsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('api_audit_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->unsignedInteger('api_key_id')->nullable()->index();
            $table->string('method', 10); // GET, POST, PUT, DELETE, etc.
            $table->string('endpoint');
            $table->text('query_parameters')->nullable();
            $table->integer('response_code')->nullable();
            $table->float('response_time', 8, 3)->nullable(); // milliseconds
            $table->text('response_message')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->foreign('api_key_id')
                ->references('id')
                ->on('api_keys')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('api_audit_logs');
    }
}
