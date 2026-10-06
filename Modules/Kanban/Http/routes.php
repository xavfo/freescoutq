<?php

/*
|--------------------------------------------------------------------------
| Rutas del módulo Kanban
|--------------------------------------------------------------------------
|
| Registradas desde KanbanServiceProvider::boot() con loadRoutesFrom().
|
| El middleware `web` es necesario para la sesión (auth) y para el token
| CSRF que envían las llamadas AJAX del tablero.
|
*/

Route::group([
    'prefix'     => 'kanban',
    'namespace'  => 'Modules\Kanban\Http\Controllers',
    'middleware' => ['web', 'auth'],
], function () {

    // Tablero
    Route::get('/', 'KanbanController@index')->name('kanban.index');
    Route::post('/board', 'KanbanController@board')->name('kanban.board');
    Route::post('/move', 'KanbanController@move')->name('kanban.move');

    // Configuración de fases (por buzón)
    Route::get('/settings', 'KanbanController@settings')->name('kanban.settings');
    Route::post('/settings', 'KanbanController@saveSettings')->name('kanban.settings.save');
});
