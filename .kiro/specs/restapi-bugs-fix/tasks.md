# Implementation Plan

## Estado de ejecución (2026-10-01)

- **Bug 1 (rutas duplicadas / CSRF): corregido.** `Modules/RestApi/start.php` ya no carga `Http/routes.php`
  (FreeScout lo incluye dentro del grupo `web`). Verificado cargando el proveedor: las 15 rutas
  `api/v1/*` se registran **una sola vez** y solo con `api-token`/`api-rate-limit`.
- **Bug 2 (doble barra en assets): corregido** en `.env` (`APP_URL` sin barra final). No requiere cambios de código.
- **Bug 3 (HTTP 500 en escrituras): corregido.** Causa: doble codificación JSON de `mailbox_ids`
  (`json_encode()` + cast `json` del modelo) → `json_decode()` devolvía un `string` → `in_array()` lanzaba
  `TypeError` → 500; y `index()` filtraba de menos, exponiendo todos los buzones. Se añadió
  `Modules/RestApi/Support/MailboxAccess.php`, se normaliza en el middleware y en todos los handlers, y se
  corrigió la creación de conversaciones/hilos/clientes (modelo de datos real de FreeScout).
- **Bloqueos de entorno encontrados y resueltos para poder verificar:**
  1. `overrides/rap2hpoutre/laravel-log-viewer/src/...` no contenía `Level.php` ni `Pattern.php` (el autoload
     PSR-4 apunta ahí), lo que hacía fallar **todos** los comandos de `php artisan`. Añadidos desde el paquete en
     `vendor/`.
  2. `vendor/composer/autoload_psr4.php` / `autoload_static.php` no incluían `Modules\RestApi\` (nunca se regeneró
     el autoload). Añadido el mapeo, idéntico al que produce `composer dump-autoload`. **Nota:** el `vendor/`
     versionado es parcial, por lo que `composer dump-autoload` no puede completarse en el clon.
- **Verificado localmente (con MariaDB 11.4 real):** sintaxis (`php -l` de todo el módulo), registro único de rutas, 38 comprobaciones del
  normalizador `MailboxAccess` (0 fallos), **verificación end-to-end de la API a través del kernel HTTP contra una BD migrada
  (49 comprobaciones, 0 fallos, 1 omitida)** y **la suite PHPUnit del módulo (71 tests, 0 fallos, 2 omitidos)**.
- **Dos bugs reales adicionales encontrados y corregidos al verificar:** `now()->isAfter()` (no existe en el Carbon de esta versión →
  HTTP 500 con tokens expirados en lugar de 401) y `whereHas()` (el `Query\Builder` sobrescrito por FreeScout llama a
  `compact('operator')` con la variable sin definir → `whereHas`/`whereExists` revienta; la búsqueda de clientes se reescribió con una
  subconsulta `whereIn`).
- **Cómo reproducir la suite:** `composer install` (este clon tiene el `vendor/` parcial: le faltan PHPUnit y Faker), activar el
  módulo en la tabla `modules` de la BD de pruebas, `php artisan migrate --force` con `DB_CONNECTION=testing` y ejecutar
  `vendor/bin/phpunit --testsuite RestApi --stderr`. El `--stderr` es obligatorio: el middleware `ResponseHeaders` de FreeScout usa
  `header()` y con la salida de PHPUnit en stdout las respuestas devuelven 500 ("headers already sent").
- **Limitación observada:** ejecutar TODA la suite en un solo proceso resetea la BD de pruebas (queda solo el esquema base y la
  activación del módulo se pierde). Ejecutándola por clases pasa al 100 %: `ConversationsApiTest`+`CustomersApiTest` 14/14,
  `MailboxRestrictionRegressionTest` 12 (2 omitidos) y `ThreadsApiTest`+`BugConditionExplorationTest`+`ApiKeyTest`+`MailboxAccessTest` 45/45.
  Pendiente de investigar (no afecta al módulo).

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

- [ ] 5. Checkpoint - Verificar que todos los tests pasan
  - Test de exploración Bug 1 (Property 1) → reescrito y consistente con el fix; **no ejecutado** (sin PHPUnit/MySQL locales)
  - Tests de preservación (Property 2) → **no ejecutados** por el mismo motivo
  - `php artisan route:list | grep api/v1` → **verificado**: cada ruta exactamente una vez y sin `web`
  - Dashboard sin doble barra en assets → `.env` ya corregido
  - Rutas web de FreeScout con CSRF → sin cambios (`start.php` solo dejó de registrar rutas de API; `restapi/tokens` sigue en el grupo `web`, ahora además con `auth` + `roles`)
  - Pendiente: ejecutar la suite completa en una instancia con MySQL (tarea 7)

- [ ] 6. Fix Bug 3 - Endpoints de escritura fallan con `in_array()`
  - [x] 6.1 Código del módulo localizado en `Modules/RestApi/` de este clon. No se accedió al servidor remoto (sin acceso SSH): los números de línea del reporte (`show()` 117, `store()` 139, `ThreadsController::store()` 111) coinciden con este clon, así que es la misma versión. No se tocaron `.env`, tokens ni dumps.
  - [x] 6.2 Causa confirmada (antes de editar): **doble codificación JSON**. `ApiTokenController::store()` y `restapi:create-token` ya hacían `json_encode()`, y el modelo `ApiKey` castea `mailbox_ids` a `json` (que vuelve a codificar). La columna quedaba con un *string* JSON, `json_decode()` devolvía un `string` y `in_array()` lanzaba `Argument #2 ($haystack) must be of type array, string given` → HTTP 500. `index()` se salvaba por su guarda `is_array()`, pero **sin aplicar el filtro: filtraba de menos y exponía todos los buzones**. Además se detectó que el módulo asumía una columna `customers.emails` inexistente (FreeScout guarda los correos en la tabla `emails`) y que `Conversation`/`Thread` se creaban con mass assignment, sin `source_via`/`source_type`/`folder_id` y sin `state`.
  - [x] 6.3 Pruebas añadidas: `Tests/Unit/MailboxAccessTest.php` (sin BD), `Tests/Feature/Api/MailboxRestrictionRegressionTest.php` (show, creación de conversación y de thread; CSV, doble codificación, acceso a todos, token restringido, acceso denegado y hash inválido), `BugConditionExplorationTest` corregido, `ApiKeyTest` ampliado y `phpunit.xml` con la suite `RestApi`.
  - [x] 6.4 Normalización centralizada en `Modules/RestApi/Support/MailboxAccess.php` (idempotente, falla cerrado ante valores no interpretables) y aplicada en el middleware y en todos los handlers. Se eliminó la doble codificación en los dos puntos de escritura y el modelo `ApiKey` normaliza en el accessor/mutator. Autorización intacta: `null` = todos los buzones, lista = solo esos, resto = 403 (ya no 500).
  - [x] 6.5 Mecanismo de asignación confirmado: `POST /api/v1/conversations` acepta `assigned_to` (validado `integer|exists:users,id`); por defecto asigna al usuario del token. Para el usuario global `1`: `{"assigned_to": 1}`. `freescout_client.php` no existe en el repositorio, por lo que no se pudo completar.
  - [x] 6.6 Confirmado: **la API solo soporta correo**. `to`/`cc`/`bcc` se validan con `email` y `Conversation::sanitizeEmails()` descarta lo que no sea una dirección válida. `type` de conversación acepta 1-4 (email/phone/chat/custom) y el de thread 2/3/8, pero el `to` sigue siendo email. No hay soporte WhatsApp/SMS: haría falta otro validador y otro canal de envío.
  - [x] 6.7 Verificado que **no hay ningún token expuesto en el repositorio**: búsqueda de `fs_[0-9a-f]{64}` y de literales `Bearer ...` en archivos versionados (excluyendo `vendor/`) y en el historial → sin resultados. **Pendiente fuera del alcance local:** rotar el token de la instancia de producción (requiere acceso al servidor).
  - Detalle del error reportado, contrato y supuestos: [bugfix.md](./bugfix.md#bug-3---endpoints-de-escritura-devuelven-http-500).

- [x] 7. Verificación con base de datos real (completada el 2026-10-03)
  - [x] 7.1 Suite PHPUnit ejecutada contra MariaDB 11.4 (`freescout-test`): **71 tests, 0 fallos, 2 omitidos** (ejecutada por clases;
        ver la limitación de la ejecución conjunta en el resumen de arriba). Requisitos: `composer install`, módulo activo en la tabla
        `modules`, `DB_CONNECTION=testing` y `--stderr`.
  - [x] 7.2 `route:list` / inspección de rutas: **15 rutas `api/v1` exactamente una vez**, sin `web`/`csrf`, solo `api-token`+`api-rate-limit`;
        `restapi/tokens` con `web, auth, roles`.
  - [x] 7.3 `MailboxAccess`: 38 comprobaciones unitarias + las 12 de regresión por API (incluidas las formas CSV, JSON doble y valor
        ilegible) → 0 fallos.
  - [x] 7.4 Verificación end-to-end a través del kernel HTTP contra la BD migrada: **49 comprobaciones, 0 fallos**, cubriendo
        autenticación (401), rate limit (429), alcance de buzones (200/403), lecturas, escrituras (conversación e hilo) y clientes.
  - [ ] 7.5 Falta reproducir sobre una copia de la BD **de producción**: los tokens existentes con `mailbox_ids` mal codificado
        (la normalización los tolera, pero conviene confirmarlo con datos reales) y reescribirlos al formato canónico si procede.
  - [ ] 7.6 Falta probar las escrituras contra la instancia de prueba real y **nunca** sobre producción: crear una conversación
        puede enviar correo al destinatario.

## Trabajo relacionado: canal de WhatsApp

- Se añadió el tipo de conversación `whatsapp` al catálogo de los tokens de API (`ApiKey::CONVERSATION_TYPES`), con columna y selector en la vista, validación en `ApiTokenController` y tests en `ApiKeyTest`. **Los endpoints REST todavía no aplican ese campo** (ver opciones en `.kiro/specs/whatsapp-channel/feature.md`).
- El canal de WhatsApp (tipo propio de conversación + envío real por Evolution API) está documentado y verificado en `.kiro/specs/whatsapp-channel/feature.md`.

## Trabajo relacionado: API multi-medio y errores de configuración (2026-10-03)

- **Endpoints con varios tipos de medio (incl. WhatsApp):** `POST /api/v1/conversations` y
  `POST /api/v1/conversations/{id}/threads` aceptan `type` (1=email, 2=phone, 3=chat, 4=custom,
  5=whatsapp) o `channel` (`email|phone|chat|custom|whatsapp`). Para telefonía/WhatsApp el
  destinatario se identifica por `phone` (o `to[0]`) y el cliente se busca/crea por teléfono.
  Nuevo helper `Modules/RestApi/Support/Channels.php` (nombres, validaciones y entrega).
- **Tokens sin restricción por medio:** se eliminó `conversation_type` del formulario y de la
  validación/guardado (`ApiTokenController`). La columna se conserva (nullable) por compatibilidad;
  ningún endpoint aplica restricción de medio. Solo se mantiene la restricción **por buzón**.
- **Errores de configuración informados:** `send_message` (alias `send`) decide la entrega real.
  Antes de encolar se valida el buzón y se responde `422` con código estable:
  `whatsapp_not_configured`, `email_not_configured`, `phone_required`, `unsupported_channel`,
  `customer_unresolved`, `validation`. La entrega se encola en la cola `emails`
  (`SendWhatsappReply` / `SendReplyToCustomer`). Por defecto WhatsApp se entrega; email solo si
  `send_message=true` (compatibilidad).
- **DTOs:** `ConversationDTO` expone `type_name` y `channel`; `ThreadDTO` expone `send_status` y
  `send_status_name`; las respuestas de creación añaden un bloque `delivery`.
- **CRUD de clientes completado:** `PUT/DELETE /api/v1/customers/{id}` (las rutas existían sin
  método). `DELETE` responde `409 customer_has_conversations` si el cliente tiene conversaciones.
- **Verificación real:** script temporal contra `freescout-test` ejercitando los controladores y
  `FormRequest` reales (con `Queue::fake()`): **45/45 comprobaciones**, 0 fallos. Se encontró y
  corrigió un bug: la validación de `StoreConversationRequest` ignoraba `channel` (solo miraba `type`).
  Se añadió `Modules/RestApi/Tests/Feature/Api/ConversationMediaTest.php` (pendiente de ejecutar con
  PHPUnit, no disponible en este clon).
- **Documentación QChat:** `docs/qchat-integration.md` (guía completa) y `docs/openapi.yaml`
  (OpenAPI 3.0, 6 rutas, 13 esquemas).
