# Implementation Plan

- [x] 1. Escribir test de exploración de condición de bug (Bug 1 - Rutas duplicadas)
  - **Property 1: Bug Condition** - Registro duplicado de rutas en contexto web
  - **CRITICAL**: Este test DEBE FALLAR en el código sin corregir — el fallo confirma que el bug existe
  - **DO NOT attempt to fix the test or the code when it fails**
  - **NOTE**: Este test codifica el comportamiento esperado — validará el fix cuando pase después de la implementación
  - **GOAL**: Demostrar que las rutas `api/v1/*` están registradas en el contexto `web` con middleware CSRF
  - **Scoped PBT Approach**: Acotar la propiedad al caso concreto: `GET /api/v1/conversations` con token Bearer válido
  - Verificar con `php artisan route:list | grep api/v1` que cada ruta aparece más de una vez (confirma registro duplicado)
  - Ejecutar `curl -H "Authorization: Bearer <token_válido>" https://tickets.qsoftware.biz/api/v1/conversations` y observar respuesta vacía
  - Verificar que las rutas `api/v1/*` tienen middleware `web` (con CSRF) además de `api-token`
  - La condición de bug: `isBugCondition_Bug1(X)` donde `X.path STARTS_WITH 'api/v1/'` AND `X.header('Authorization') MATCHES 'Bearer .+'`
  - El comportamiento esperado: `result.status IN [200..429]` AND `result.body IS valid JSON` AND `result IS NOT empty`
  - Ejecutar en código SIN corregir
  - **EXPECTED OUTCOME**: Test FALLA (esto es correcto — prueba que el bug existe)
  - Documentar contraejemplos encontrados (ej: `GET /api/v1/conversations` retorna body vacío o error CSRF)
  - Marcar tarea completa cuando el test esté escrito, ejecutado y el fallo documentado
  - _Requirements: 1.1, 1.2, 1.3_

- [x] 2. Escribir tests de preservación (ANTES de implementar el fix)
  - **Property 2: Preservation** - Autenticación, rate limiting y rutas web sin cambios
  - **IMPORTANT**: Seguir metodología observation-first
  - Observar en código SIN corregir: `GET /api/v1/conversations` con token inválido → retorna JSON 401
  - Observar en código SIN corregir: `GET /api/v1/conversations` con token expirado → retorna JSON 401
  - Observar en código SIN corregir: request que supera rate limit → retorna JSON 429
  - Observar en código SIN corregir: acceso al dashboard web → renderiza con middleware `web` y CSRF activo
  - Escribir tests de propiedad: para todo request donde `NOT isBugCondition_Bug1(X)`, el sistema corregido produce el mismo resultado que el original
  - Cubrir: tokens inválidos/ausentes (→ 401), tokens expirados (→ 401), rate limit superado (→ 429), rutas web normales (→ funcional con CSRF)
  - Ejecutar tests en código SIN corregir
  - **EXPECTED OUTCOME**: Tests PASAN (confirma comportamiento base a preservar)
  - Marcar tarea completa cuando los tests estén escritos, ejecutados y pasando en código sin corregir
  - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6_

- [ ] 3. Fix Bug 1 - Eliminar registro duplicado de rutas en start.php

  - [x] 3.1 Vaciar `Modules/RestApi/start.php` dejando solo comentario explicativo
    - Reemplazar el contenido actual de `Modules/RestApi/start.php` con solo el comentario
    - El archivo debe quedar con únicamente: `<?php` y el comentario explicativo
    - Dejar que `RestApiServiceProvider::boot()` via `loadRoutesFrom()` sea el único punto de registro
    - _Bug_Condition: isBugCondition_Bug1(X) donde X.path STARTS_WITH 'api/v1/' AND routesRegisteredInWebContext() = true_
    - _Expected_Behavior: result.status IN [200..429] AND result.body IS valid JSON AND result IS NOT empty_
    - _Preservation: Requests con NOT isBugCondition_Bug1(X) producen el mismo resultado antes y después del fix_
    - _Requirements: 2.1, 2.2, 2.3_

  - [x] 3.2 Verificar que el test de exploración ahora pasa
    - **Property 1: Expected Behavior** - Rutas API responden con JSON válido
    - **IMPORTANT**: Re-ejecutar el MISMO test de la tarea 1 — NO escribir un test nuevo
    - El test de la tarea 1 codifica el comportamiento esperado
    - Cuando este test pasa, confirma que el comportamiento esperado se cumple
    - Ejecutar: `php artisan route:list | grep api/v1` → cada ruta debe aparecer exactamente una vez
    - Ejecutar: `curl -H "Authorization: Bearer <token_válido>" https://tickets.qsoftware.biz/api/v1/conversations` → debe retornar JSON 200
    - Verificar que las rutas `api/v1/*` tienen solo middleware `api-token` y `api-rate-limit`, sin `web` ni CSRF
    - **EXPECTED OUTCOME**: Test PASA (confirma que el bug está corregido)
    - _Requirements: 2.1, 2.2, 2.3_

  - [x] 3.3 Verificar que los tests de preservación siguen pasando
    - **Property 2: Preservation** - Autenticación, rate limiting y rutas web sin cambios
    - **IMPORTANT**: Re-ejecutar los MISMOS tests de la tarea 2 — NO escribir tests nuevos
    - Verificar: token inválido → sigue retornando JSON 401
    - Verificar: token expirado → sigue retornando JSON 401
    - Verificar: rate limit superado → sigue retornando JSON 429
    - Verificar: dashboard web → sigue funcionando con middleware `web` y CSRF
    - Verificar: filtrado por `mailbox_ids` del token → sigue funcionando
    - **EXPECTED OUTCOME**: Tests PASAN (confirma que no hay regresiones)

- [ ] 4. Fix Bug 2 - Remover barra final de APP_URL en .env

  - [x] 4.1 Verificar y corregir APP_URL en `.env`
    - Verificar el valor actual: `grep APP_URL .env`
    - Si `APP_URL` termina en `/`, remover la barra final: `APP_URL=https://tickets.qsoftware.biz`
    - _Bug_Condition: isBugCondition_Bug2(X) donde env('APP_URL') ENDS_WITH '/'_
    - _Expected_Behavior: asset(path) NOT CONTAINS '//' (excluyendo protocolo https://)_
    - _Preservation: Para APP_URL sin barra final, generateAssetUrl produce el mismo resultado antes y después_
    - _Requirements: 2.4, 2.5_

  - [x] 4.2 Limpiar caché para que el cambio tome efecto
    - Ejecutar: `php artisan freescout:clear-cache`
    - Verificar que `asset('js/builds/app.js')` genera `https://tickets.qsoftware.biz/js/builds/app.js` (sin doble barra)
    - Cargar el dashboard y verificar que los assets responden con HTTP 200
    - Verificar que el dashboard muestra estilos, navegación y funcionalidad JavaScript completa
    - _Requirements: 2.4, 2.5_

- [x] 5. Checkpoint - Verificar que todos los tests pasan
  - Re-ejecutar el test de exploración Bug 1 (Property 1) → debe PASAR
  - Re-ejecutar los tests de preservación (Property 2) → deben PASAR
  - Verificar `php artisan route:list | grep api/v1` → cada ruta exactamente una vez
  - Verificar que el dashboard carga correctamente sin doble barra en assets
  - Verificar que las rutas web de FreeScout siguen funcionando con CSRF
  - Asegurarse de que todos los tests pasan; consultar al usuario si surgen dudas.
