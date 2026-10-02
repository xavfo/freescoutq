<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddConversationTypeToApiKeysTable extends Migration
{
    public function up()
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->enum('conversation_type', ['email', 'web_form', 'sms', 'call', 'whatsapp'])->nullable()->after('mailbox_ids');
        });
    }

    public function down()
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('conversation_type');
        });
    }
}