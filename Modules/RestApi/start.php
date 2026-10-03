<?php

// Routes are registered by RestApiServiceProvider::boot() via loadRoutesFrom(),
// which loads them outside of the "web" middleware group.
//
// Do not load Http/routes.php here: FreeScout includes every module's start.php
// inside the "web" route group, so loading the routes here as well caused the
// API endpoints to be registered twice and to be wrapped by the CSRF middleware
// (blocking or corrupting API requests that only send a Bearer token).
