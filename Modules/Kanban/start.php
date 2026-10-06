<?php

/*
|--------------------------------------------------------------------------
| start.php — DELIBERADAMENTE VACÍO
|--------------------------------------------------------------------------
|
| Este archivo NO debe cargar `Http/routes.php`.
|
| FreeScout (nwidart/laravel-modules) incluye este archivo dentro del grupo de
| middleware `web`, por lo que cargar aquí las rutas las registraría dentro de
| `web` y con CSRF, además de duplicarlas. Ese fue exactamente el bug que
| sufrió el módulo RestApi (respuestas vacías y errores CSRF en la API).
|
| Las rutas se registran en un único punto:
|   KanbanServiceProvider::boot() -> loadRoutesFrom(__DIR__.'/Http/routes.php')
|
| Por eso `module.json` tampoco declara la clave "files".
|
*/
