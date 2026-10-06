<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase actual de una conversación dentro del tablero Kanban.
 *
 * Se guarda en una columna propia (y no en conversations.meta) porque meta es
 * un text con JSON y no se puede consultar ni paginar por fase en SQL: con
 * "todo lo abierto" los contadores y la paginación por columna serían falsos.
 *
 * La auditoría (cuándo y quién) sí se guarda en conversations.meta:
 *   meta['kanban_stage_at'] / meta['kanban_stage_by']
 */
class AddKanbanStageToConversationsTable extends Migration
{
    public function up()
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('kanban_stage', 64)->nullable()->after('status');
            $table->index('kanban_stage');
        });
    }

    public function down()
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['kanban_stage']);
            $table->dropColumn('kanban_stage');
        });
    }
}
