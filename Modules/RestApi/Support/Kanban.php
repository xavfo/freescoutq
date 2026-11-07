<?php

namespace Modules\RestApi\Support;

use App\Conversation;
use App\Mailbox;
use App\User;
use Modules\Kanban\Support\Stages;

/**
 * Puente opcional entre la API REST y el módulo Kanban.
 *
 * RestApi debe seguir funcionando en instalaciones sin Kanban. Por eso todas
 * las llamadas comprueban primero que el módulo esté activo y que sus clases
 * estén disponibles; si no lo está, el campo `kanban_stage` devuelve un error
 * estructurado en lugar de provocar un error de SQL por una columna inexistente.
 */
class Kanban
{
    const NONE = '__none';

    const ERROR_NOT_AVAILABLE = 'kanban_not_available';
    const ERROR_STAGE_INVALID = 'kanban_stage_invalid';

    /**
     * ¿Está el módulo Kanban activo y autoloadable?
     */
    public static function isAvailable()
    {
        return class_exists(Stages::class) && \Module::isActive('kanban');
    }

    /**
     * Etapas del buzón, con la columna virtual "Sin etapa" al inicio.
     *
     * @param  Mailbox $mailbox
     * @return array
     */
    public static function stages(Mailbox $mailbox)
    {
        if (!self::isAvailable()) {
            return [];
        }

        $none = Stages::none();
        $stages = [[
            'id'      => self::NONE,
            'name'    => $none['name'] ?? 'Sin etapa',
            'color'   => $none['color'] ?? null,
            'wip'     => 0,
            'status'  => null,
            'virtual' => true,
        ]];

        foreach (Stages::forMailbox($mailbox) as $stage) {
            $stage['virtual'] = false;
            $stages[] = $stage;
        }

        return $stages;
    }

    /**
     * Valida el valor recibido en `kanban_stage`.
     *
     * Devuelve:
     *   ok=true, stage=null|string   para aplicar
     *   ok=false, error, message     para responder 422
     *
     * @param  Mailbox $mailbox
     * @param  mixed   $value
     * @return array
     */
    public static function validate(Mailbox $mailbox, $value)
    {
        if (!self::isAvailable()) {
            return [
                'ok'      => false,
                'error'   => self::ERROR_NOT_AVAILABLE,
                'message' => __('Kanban module is not active'),
            ];
        }

        if ($value === null || $value === '' || $value === self::NONE) {
            return ['ok' => true, 'stage' => null];
        }

        $stageId = trim((string) $value);

        if ($stageId === '' || strlen($stageId) > 64) {
            return [
                'ok'      => false,
                'error'   => self::ERROR_STAGE_INVALID,
                'message' => __('Invalid Kanban stage'),
            ];
        }

        $stage = Stages::find($mailbox, $stageId);

        if (!$stage) {
            return [
                'ok'      => false,
                'error'   => self::ERROR_STAGE_INVALID,
                'message' => __('The Kanban stage does not belong to this mailbox'),
            ];
        }

        return ['ok' => true, 'stage' => $stage['id']];
    }

    /**
     * Aplica una etapa ya validada. `null` equivale a la columna virtual
     * "Sin etapa"; el módulo guarda NULL y registra la auditoría en meta.
     *
     * @param  Conversation $conversation
     * @param  string|null  $stageId
     * @param  int|null     $userId
     * @return void
     */
    public static function apply(Conversation $conversation, $stageId, $userId = null)
    {
        if (!self::isAvailable()) {
            return;
        }

        $user = $userId ? User::find($userId) : null;

        Stages::move(
            $conversation,
            $stageId === null ? self::NONE : $stageId,
            $user
        );
    }
}
