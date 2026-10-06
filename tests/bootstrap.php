<?php

/**
 * Bootstrap de la suite de pruebas.
 *
 * El `vendor/` versionado en este repositorio se generó con las reglas de
 * producción (`composer dump-autoload` sin `--dev`), así que el namespace de
 * las pruebas (`Tests\`) NO está en el autoloader. Sin esto, cualquier test que
 * extienda `Tests\TestCase` falla con "Class Tests\TestCase not found".
 *
 * Se registra aquí en lugar de tocar el autoloader para no meter reglas de
 * desarrollo (phpunit, var-dumper…) en el paquete que va a producción.
 *
 * Uso:
 *   php phpunit.phar -c phpunit.xml --testsuite Kanban
 */

$loader = require __DIR__ . '/../vendor/autoload.php';

if ($loader instanceof \Composer\Autoload\ClassLoader) {
    $loader->addPsr4('Tests\\', __DIR__);
}

/*
 * Entorno de pruebas.
 *
 * Laravel lee APP_ENV con getenv(), y las etiquetas <env> de phpunit.xml no
 * siempre llegan hasta ahí. Sin esto:
 *
 *  - APP_ENV se queda en "production", el middleware de CSRF no detecta que
 *    estamos en pruebas y cualquier POST responde 419.
 *  - APP_DEBUG hereda el `true` del .env y, al fallar una prueba, el manejador
 *    intenta pintar la página de Whoops, que en este fork no es compatible con
 *    PHP 8.1: en vez de ver el error, el proceso de pruebas muere.
 */
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

putenv('APP_DEBUG=false');
$_ENV['APP_DEBUG'] = 'false';
$_SERVER['APP_DEBUG'] = 'false';

/*
 * Y hay que deshacerse de la configuración cacheada: `bootstrap/cache/config.php`
 * se genera con los valores del .env y congela APP_ENV=production y
 * APP_DEBUG=true, dejando los putenv() de arriba sin efecto. Es un fichero de
 * caché (ignorado por git): se descarta y Laravel vuelve a leer la configuración
 * real. `php artisan freescout:clear-cache` la regenera cuando haga falta.
 */
$cached_config = __DIR__ . '/../bootstrap/cache/config.php';

if (is_file($cached_config)) {
    @unlink($cached_config);
}
