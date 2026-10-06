<?php

namespace Modules\Kanban\Support;

use App\Conversation;

/**
 * Tipo de medio de una conversación (origen del mensaje).
 *
 * El dato fiable es `conversations.type`:
 *   1 email · 2 teléfono/llamada · 3 chat · 4 personalizado · 5 WhatsApp
 *
 * `conversations.channel` es una columna que FreeScout deja libre para que los
 * módulos definan sus propios canales (se lee con Conversation::getChannelName(),
 * que pasa por el filtro `channel.name`). Si algún módulo la rellena, se usa su
 * nombre para resolver el medio (así, por ejemplo, un canal "SMS" recibe el color
 * de SMS sin tocar este módulo). Si no, se usa `type`.
 */
class Medium
{
    const EMAIL    = 'email';
    const WHATSAPP = 'whatsapp';
    const PHONE    = 'phone';
    const SMS      = 'sms';
    const CHAT     = 'chat';
    const CUSTOM   = 'custom';

    /**
     * Medio deducido del tipo de conversación de FreeScout.
     */
    public static function fromType($type)
    {
        switch ((int) $type) {
            case Conversation::TYPE_EMAIL:
                return self::EMAIL;
            case Conversation::TYPE_PHONE:
                return self::PHONE;
            case Conversation::TYPE_CHAT:
                return self::CHAT;
            case Conversation::TYPE_WHATSAPP:
                return self::WHATSAPP;
            case Conversation::TYPE_CUSTOM:
            default:
                return self::CUSTOM;
        }
    }

    /**
     * Normaliza un nombre libre (canal, etiqueta) a una clave de medio conocida.
     * Devuelve null si no se reconoce.
     */
    public static function fromName($name)
    {
        $key = strtolower(trim((string) $name));

        if ($key === '') {
            return null;
        }

        $key = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $key);

        $aliases = [
            'email'           => self::EMAIL,
            'e-mail'          => self::EMAIL,
            'correo'          => self::EMAIL,
            'mail'            => self::EMAIL,
            'whatsapp'        => self::WHATSAPP,
            'wa'              => self::WHATSAPP,
            'phone'           => self::PHONE,
            'telefono'        => self::PHONE,
            'llamada'         => self::PHONE,
            'call'            => self::PHONE,
            'sms'             => self::SMS,
            'chat'            => self::CHAT,
            'custom'          => self::CUSTOM,
            'personalizado'   => self::CUSTOM,
            'otro'            => self::CUSTOM,
        ];

        return $aliases[$key] ?? null;
    }

    /**
     * Medio de una conversación concreta.
     */
    public static function of(Conversation $conversation)
    {
        if (!empty($conversation->channel)) {
            $byName = self::fromName($conversation->getChannelName());
            if ($byName !== null) {
                return $byName;
            }
        }

        return self::fromType($conversation->type);
    }

    /**
     * Todos los medios soportados: clave => etiqueta traducida.
     */
    public static function all()
    {
        return [
            self::EMAIL    => __('kanban::kanban.media_email'),
            self::WHATSAPP => __('kanban::kanban.media_whatsapp'),
            self::PHONE    => __('kanban::kanban.media_phone'),
            self::SMS      => __('kanban::kanban.media_sms'),
            self::CHAT     => __('kanban::kanban.media_chat'),
            self::CUSTOM   => __('kanban::kanban.media_custom'),
        ];
    }

    /**
     * Etiqueta legible del medio.
     */
    public static function label($key)
    {
        $all = self::all();

        return $all[$key] ?? $key;
    }

    /**
     * Color de la línea izquierda de la tarjeta.
     */
    public static function color($key)
    {
        $media = (array) config('kanban.media', []);

        return $media[$key] ?? ($media[self::CUSTOM] ?? '#78909c');
    }
}
