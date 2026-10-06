<?php

return [

    /*
     * Estados de conversación que entran en el tablero.
     * 1 = Activa, 2 = Pendiente. Por defecto "todo lo abierto".
     */
    'statuses' => [1, 2],

    /*
     * Tarjetas que se cargan por columna y por petición ("cargar más").
     */
    'per_column' => 30,

    /*
     * Días sin respuesta a partir de los cuales se marca la tarjeta en rojo.
     * 0 desactiva el aviso.
     */
    'stale_days' => 3,

    /*
     * Refresco automático del tablero en segundos. 0 = solo manual.
     */
    'refresh_seconds' => 0,

    /*
     * Columna virtual donde caen las conversaciones sin fase asignada
     * (o con una fase que ya no existe en el buzón).
     */
    'unassigned_column' => [
        'id'    => '__none',
        'name'  => 'Sin etapa',
        'color' => '#7f8c8d',
    ],

    /*
     * Color de la línea izquierda de la tarjeta según el TIPO DE MEDIO.
     * Se aplica en el CSS con .card[data-medium="..."].
     */
    'media' => [
        'email'    => '#0078d7',
        'whatsapp' => '#25d366',
        'phone'    => '#e91e63',
        'sms'      => '#ff9800',
        'chat'     => '#00bcd4',
        'custom'   => '#78909c',
    ],

    /*
     * Fases por defecto. Un buzón sin configuración propia usa estas.
     *   id     identificador estable (no cambiar una vez en uso)
     *   name   etiqueta visible
     *   color  color de la cabecera y tinte de la columna
     *   wip    límite de trabajo en curso (0 = sin límite, solo avisa)
     *   status si no es null, mover aquí cambia el estado de la conversación
     */
    'stages' => [
        ['id' => 'new',         'name' => 'Nuevo',             'color' => '#3498db', 'wip' => 0, 'status' => null],
        ['id' => 'in_progress', 'name' => 'En curso',          'color' => '#f39c12', 'wip' => 0, 'status' => null],
        ['id' => 'waiting',     'name' => 'Esperando cliente', 'color' => '#9b59b6', 'wip' => 0, 'status' => null],
        ['id' => 'resolved',    'name' => 'Resuelto',          'color' => '#27ae60', 'wip' => 0, 'status' => 3],
    ],

    /*
     * Colores permitidos al editar fases (evita valores arbitrarios).
     */
    'palette' => [
        '#3498db',
        '#0078d7',
        '#17a2b8',
        '#27ae60',
        '#2ecc71',
        '#f39c12',
        '#e67e22',
        '#e91e63',
        '#9b59b6',
        '#e74c3c',
        '#7f8c8d',
        '#34495e',
    ],
];
