<?php

/*
 * Textos del módulo Kanban (español).
 *
 * El tablero es multi-buzón y multi-medio: los nombres de las fases NO viven
 * aquí, se configuran por buzón en /kanban/settings (así cada equipo adapta
 * "Nuevo → En curso → Esperando cliente → Resuelto" a su forma de trabajar).
 */

return [

    'title' => 'Tablero',

    // ------------------------------ filtros ------------------------------
    'filter_mailbox'     => 'Buzón',
    'filter_assignee'    => 'Asignado a',
    'all_mailboxes'      => 'Todos los buzones',
    'all_assignees'      => 'Cualquier asignado',
    'assignee_none'      => 'Sin asignar',
    'assignee_me'        => 'Asignados a mí',
    'search_placeholder' => 'Buscar por asunto, cliente o correo…',
    'show_stale_only'    => 'Solo sin respuesta',
    'refresh'            => 'Actualizar',
    'settings'           => 'Etapas',
    'legend_medium'      => 'Tipo de mensaje:',

    // ------------------------------- tablero -----------------------------
    'empty_column' => 'Sin conversaciones',
    'load_more'    => 'Cargar más',
    'wip_over'     => 'Por encima del límite',
    'no_subject'   => '(sin asunto)',
    'no_assignee'  => 'Sin asignar',
    'stale_hint'   => 'Sin respuesta desde hace :count días',

    // --------------------- acciones rápidas de tarjeta ---------------------
    'card_actions'    => 'Acciones',
    'card_assign_me'  => 'Asignármela',
    'card_unassign'   => 'Quitar asignado',
    'card_active'     => 'Marcar como activa',
    'card_pending'    => 'Marcar como pendiente',
    'card_closed'     => 'Cerrar conversación',
    'card_open'       => 'Abrir conversación',
    'move_to'        => 'Mover a',
    // ------------------------------- ajustes -----------------------------
    'settings_title'   => 'Etapas del tablero',
    'settings_help'    => 'Las etapas son las columnas del tablero y se configuran por buzón. Arrastra las tarjetas entre ellas para cambiar el estado de una conversación sin abrirla.',
    'settings_mailbox' => 'Buzón',
    'stage_name'       => 'Nombre',
    'stage_color'      => 'Color',
    'stage_wip'        => 'Límite',
    'stage_status'     => 'Cambia el estado a',
    'stage_add'        => 'Añadir etapa',
    'stage_remove'     => 'Quitar',
    'stage_wip_hint'   => '0 = sin límite. Si se supera, el contador se marca en rojo (solo avisa).',
    'stage_status_hint' => 'Opcional: al mover aquí una conversación, también cambia su estado.',
    'settings_save'    => 'Guardar etapas',
    'settings_reset'   => 'Restaurar las de por defecto',
    'settings_saved'   => 'Etapas guardadas.',
    'settings_reset_ok' => 'Se han restaurado las etapas por defecto.',
    'settings_none'    => 'Ninguna etapa definida. Añade al menos una.',
    'status_none'      => 'No cambiar el estado',
    'stages'           => 'Etapas',

    // ------------------------------- errores -----------------------------
    'error_mailbox'   => 'No tienes acceso a ningún buzón para ver el tablero.',
    'error_not_found' => 'La conversación no existe.',
    'error_forbidden' => 'No tienes permiso para ver esta conversación.',
    'error_update'    => 'No tienes permiso para modificar esta conversación.',
    'error_stage'     => 'Esa etapa no pertenece al buzón de la conversación.',
    'error_generic'   => 'No se pudo completar la operación.',

    // ------------------------------- acciones ----------------------------
    'moved'            => 'Conversación movida a «:stage».',
    'moved_and_status' => 'Conversación movida a «:stage» y estado actualizado.',

    // ------------------------------- medios ------------------------------
    'media_email'    => 'Correo',
    'media_whatsapp' => 'WhatsApp',
    'media_phone'    => 'Teléfono',
    'media_sms'      => 'SMS',
    'media_chat'     => 'Chat',
    'media_custom'   => 'Otro',

    // ----------------------------- antigüedad ----------------------------
    'age_minutes' => 'hace :count min',
    'age_hours'   => 'hace :count h',
    'age_days'    => 'hace :count d',
    'age_months'  => 'hace :count mes|hace :count meses',

    // ------------------------ sin buzones visibles -----------------------
    'no_mailboxes_title' => 'Todavía no hay nada que mostrar',
    'no_mailboxes_help'  => 'El tablero muestra las conversaciones abiertas de los buzones a los que tienes acceso. Pide a un administrador que te dé acceso a un buzón para empezar.',
];
