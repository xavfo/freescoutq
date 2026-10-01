<?php

$app = app();

if (!$app->routesAreCached()) {
    require __DIR__ . '/Http/routes.php';
}
