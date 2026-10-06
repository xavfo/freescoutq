<?php

namespace Modules\Kanban\Support;

use App\Conversation;
use App\Mailbox;
use Modules\Kanban\Entities\DTOs\CardDTO;

/**
 * Fases del tablero.
 *
 * Configuración por buzón en `mailboxes.meta['kanban']['stages']` (mismo patrón
 * que los ajustes de WhatsApp). Un buzón sin configuración usa las fases por
 * defecto de `config/kanban.php`, así el tablero funciona desde el primer día.
 *
 * La fase de una conversación vive en `conversations.kanban_stage` (columna
 * propia indexada) y la auditoría en `conversations.meta`:
 *   meta['kanban_stage_at'] / meta['kanban_stage_by']
 */
class Stages
{
    /**
     * Columna virtual para conversaciones sin fase (o con una fase borrada).
     */
    const NONE = '__none';

    public static function none()
    {
        return config('kanban.unassigned_column', [
            'id'    => self::NONE,
            'name'  => 'Sin etapa',
            'color' => '#7f8c8d',
        ]);
    }

    public static function defaults()
    {
        return self::normalize((array) config('kanban.stages', []));
    }

    /**
     * Fases configuradas en un buzón (o las de por defecto).
     *
     * @param  Mailbox $mailbox
     * @return array
     */
    public static function forMailbox(Mailbox $mailbox)
    {
        $meta = $mailbox->getMeta('kanban');

        if (is_array($meta) && !empty($meta['stages']) && is_array($meta['stages'])) {
            return self::normalize($meta['stages']);
        }

        return self::defaults();
    }

    /**
     * Guarda las fases de un buzón.
     *
     * @param Mailbox $mailbox
     * @param array   $stages
     */
    public static function saveForMailbox(Mailbox $mailbox, array $stages)
    {
        $meta = $mailbox->getMeta('kanban');
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta['stages'] = self::normalize($stages);

        $mailbox->setMetaParam('kanban', $meta, true);
    }

    /**
     * Fases comunes a varios buzones (unión por id, en orden de aparición).
     *
     * Se usa cuando el tablero muestra "todos los buzones".
     *
     * @param  \Illuminate\Support\Collection $mailboxes
     * @return array
     */
    public static function union($mailboxes)
    {
        $union = [];

        foreach ($mailboxes as $mailbox) {
            foreach (self::forMailbox($mailbox) as $stage) {
                if (!isset($union[$stage['id']])) {
                    $union[$stage['id']] = $stage;
                }
            }
        }

        if (empty($union)) {
            return self::defaults();
        }

        return array_values($union);
    }

    /**
     * Busca una fase por id en la lista de un buzón.
     *
     * @param  Mailbox $mailbox
     * @param  string  $stageId
     * @return array|null
     */
    public static function find(Mailbox $mailbox, $stageId)
    {
        foreach (self::forMailbox($mailbox) as $stage) {
            if ($stage['id'] === $stageId) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Fase actual de una conversación (id, o __none).
     */
    public static function ofConversation(Conversation $conversation)
    {
        return $conversation->kanban_stage ?: self::NONE;
    }

    /**
     * Mueve una conversación a una fase.
     *
     * Si la fase destino define un estado (por ejemplo "Resuelto" -> cerrada),
     * también se cambia el estado de la conversación con el método del core,
     * para conservar el registro en el historial y las notificaciones.
     *
     * @param  Conversation $conversation
     * @param  string       $stageId
     * @param  \App\User|null $user
     * @return array ['stage' => id, 'status_changed' => bool]
     */
    public static function move(Conversation $conversation, $stageId, $user = null)
    {
        $mailbox = $conversation->mailbox;
        $stage = ($stageId === self::NONE) ? null : ($mailbox ? self::find($mailbox, $stageId) : null);

        $conversation->kanban_stage = $stage ? $stage['id'] : null;
        $conversation->setMeta('kanban_stage_at', date('Y-m-d H:i:s'));
        $conversation->setMeta('kanban_stage_by', $user ? $user->id : null);

        $statusChanged = false;

        if (
            $stage && !empty($stage['status'])
            && (int) $stage['status'] !== (int) $conversation->status
            && $user
        ) {
            // changeStatus() guarda la conversación (y registra el cambio).
            $conversation->changeStatus((int) $stage['status'], $user);
            $statusChanged = true;
        }

        $conversation->save();

        return [
            'stage'          => $stage ? $stage['id'] : self::NONE,
            'status_changed' => $statusChanged,
        ];
    }

    /**
     * Normaliza y valida una lista de fases.
     *
     * @param  array $stages
     * @return array
     */
    public static function normalize(array $stages)
    {
        $palette = (array) config('kanban.palette', []);
        // La paleta se compara en mayúsculas: los colores se guardan en
        // mayúsculas (canónico) y, sin esto, elegir un color de la paleta se
        // detectaba como "no permitido" y se sustituía por otro distinto.
        $palette_upper = array_map('strtoupper', $palette);
        $normalized = [];
        $usedIds = [];

        foreach ($stages as $stage) {
            if (!is_array($stage)) {
                continue;
            }

            $name = trim((string) ($stage['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $id = strtolower(trim((string) ($stage['id'] ?? '')));
            $id = preg_replace('/[^a-z0-9_\-]/', '', str_replace([' ', '.'], '_', $id));

            if ($id === '' || $id === self::NONE) {
                $id = substr(self::slug($name), 0, 40);
                $id = trim($id, '_');
            }
            if ($id === '') {
                $id = 'stage_' . (count($normalized) + 1);
            }

            // Ids únicos: si se repite se descarta la entrada.
            if (isset($usedIds[$id])) {
                continue;
            }
            $usedIds[$id] = true;

            $color = strtoupper(trim((string) ($stage['color'] ?? '')));
            if (!preg_match('/^#[0-9A-F]{6}$/', $color) || ($palette_upper && !in_array($color, $palette_upper, true))) {
                $color = $palette_upper
                    ? $palette_upper[count($normalized) % count($palette_upper)]
                    : '#3498DB';
            }

            $wip = (int) ($stage['wip'] ?? 0);
            $status = isset($stage['status']) && $stage['status'] !== '' && $stage['status'] !== null
                ? (int) $stage['status']
                : null;

            if ($status !== null && !array_key_exists($status, Conversation::$statuses)) {
                $status = null;
            }

            $normalized[] = [
                'id'     => $id,
                'name'   => mb_substr($name, 0, 60),
                'color'  => $color,
                'wip'    => max(0, $wip),
                'status' => $status,
            ];
        }

        return $normalized;
    }

    /**
     * Aclara un color mezclándolo con blanco.
     *
     * Es el tinte de fondo de la columna: mismo tono que la cabecera pero muy
     * claro, para que la fase se identifique de un vistazo sin cansar la vista.
     *
     * @param  string $hex
     * @param  float  $amount 0 = sin cambio, 1 = blanco
     * @return string
     */
    public static function tint($hex, $amount = 0.85)
    {
        $hex = ltrim((string) $hex, '#');

        if (strlen($hex) !== 6) {
            return '#F4F6F8';
        }

        $out = '#';

        for ($i = 0; $i < 3; $i++) {
            $channel = (int) hexdec(substr($hex, $i * 2, 2));
            $channel = (int) round($channel + (255 - $channel) * $amount);
            $out .= str_pad(dechex(min(255, max(0, $channel))), 2, '0', STR_PAD_LEFT);
        }

        return strtoupper($out);
    }

    /**
     * Convierte un nombre en identificador: sin acentos, minúsculas y con
     * guiones bajos en lugar de todo lo que no sea letra o número.
     *
     * Se transcriben los acentos para que «En Espera Técnica» dé
     * `en_espera_tecnica` y no `en_espera_t_cnica`.
     *
     * @param  string $name
     * @return string
     */
    public static function slug($name)
    {
        $name = str_replace(
            [
                'á',
                'à',
                'ä',
                'â',
                'ã',
                'é',
                'è',
                'ë',
                'ê',
                'í',
                'ì',
                'ï',
                'î',
                'ó',
                'ò',
                'ö',
                'ô',
                'õ',
                'ú',
                'ù',
                'ü',
                'û',
                'ñ',
                'ç'
            ],
            [
                'a',
                'a',
                'a',
                'a',
                'a',
                'e',
                'e',
                'e',
                'e',
                'i',
                'i',
                'i',
                'i',
                'o',
                'o',
                'o',
                'o',
                'o',
                'u',
                'u',
                'u',
                'u',
                'n',
                'c'
            ],
            mb_strtolower((string) $name, 'UTF-8')
        );

        return preg_replace('/[^a-z0-9]+/', '_', $name);
    }

    /**
     * Estados de conversación que se pueden asociar a una fase, para el editor.
     *
     * @return array
     */
    public static function statusOptions()
    {
        $options = ['' => __('kanban::kanban.status_none')];

        foreach (Conversation::$statuses as $code => $name) {
            $options[$code] = Conversation::statusCodeToName($code);
        }

        return $options;
    }
}
