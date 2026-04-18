# RestApi Bugs Fix - Bugfix Design

## Overview

Este documento cubre el diseño de la solución para dos bugs en FreeScout con el módulo RestApi:

- **Bug 1**: Las rutas API se registran dos veces (en `start.php` y en `RestApiServiceProvider::boot()`). Al cargarse en el contexto del grupo `web`, el middleware CSRF de FreeScout interfiere con las requests, produciendo respuestas vacías o bloqueadas.
- **Bug 2**: `APP_URL` en `.env` tiene barra al final (`https://tickets.qsoftware.biz/`), lo que hace que Laravel genere URLs de assets con doble barra (`//js/builds/...`), causando 404s y dejando el dashboard sin estilos ni funcionalidad.

La estrategia de fix es mínima y quirúrgica: eliminar la carga duplicada de rutas en `start.php` y remover la barra final de `APP_URL`.

---

## Glossary

- **Bug_Condition (C)**: La condición que activa el bug — registro duplicado de rutas en contexto `web` (Bug 1) o `APP_URL` con barra final (Bug 2)
- **Property (P)**: El comportamiento correcto esperado — rutas API responden con JSON válido (Bug 1), URLs de assets sin doble barra (Bug 2)
- **Preservation**: Comportamientos existentes que no deben cambiar: autenticación API, rate limiting, rutas web de FreeScout, protección CSRF en rutas web
- **`start.php`**: Archivo de inicialización del módulo en `Modules/RestApi/start.php` que FreeScout carga en el contexto del grupo `web`
- **`loadRoutesFrom()`**: Método de `ServiceProvider` que registra rutas fuera del grupo `web`, en el contexto correcto para APIs
- **`APP_URL`**: Variable de entorno en `.env` que define la URL base de la aplicación, usada por Laravel para generar URLs de assets

---

## Bug Details

### Bug 1 - Registro duplicado de rutas y contexto CSRF

El bug se manifiesta cuando una request llega a `GET /api/v1/conversations` con un token Bearer válido. Las rutas están registradas dos veces: una vez desde `start.php` (en el contexto del grupo `web` de FreeScout, que incluye el middleware CSRF) y otra desde `RestApiServiceProvider::boot()` via `loadRoutesFrom()`. La primera carga en contexto `web` hace que el middleware CSRF interfiera o que las rutas queden en estado inconsistente.

**Formal Specification:**
```
FUNCTION isBugCondition_Bug1(X)
  INPUT: X of type HttpRequest
  OUTPUT: boolean

  RETURN X.path STARTS_WITH 'api/v1/'
     AND X.header('Authorization') MATCHES 'Bearer .+'
     AND routesRegisteredInWebContext() = true
END FUNCTION
```

### Ejemplos

- `GET /api/v1/conversations` con `Authorization: Bearer <token_válido>` → respuesta vacía (esperado: JSON 200 con listado)
- `POST /api/v1/conversations` con body JSON y token válido → sin respuesta (esperado: JSON 201 con conversación creada)
- `GET /api/v1/customers` con token válido → sin respuesta (esperado: JSON 200 con listado de clientes)
- `GET /api/v1/conversations` sin token → debería retornar 401 (comportamiento de preservación)

### Bug 2 - APP_URL con barra final genera doble barra en assets

El bug se manifiesta cuando Laravel genera URLs de assets concatenando `APP_URL` (que termina en `/`) con la ruta del asset (que empieza con `/`), produciendo `https://tickets.qsoftware.biz//js/builds/app.js`.

**Formal Specification:**
```
FUNCTION isBugCondition_Bug2(X)
  INPUT: X of type AssetUrlGeneration
  OUTPUT: boolean

  RETURN env('APP_URL') ENDS_WITH '/'
END FUNCTION
```

### Ejemplos

- `asset('js/builds/app.js')` con `APP_URL=https://tickets.qsoftware.biz/` → `https://tickets.qsoftware.biz//js/builds/app.js` (404)
- `asset('css/app.css')` con `APP_URL=https://tickets.qsoftware.biz/` → `https://tickets.qsoftware.biz//css/app.css` (404)
- `asset('js/builds/app.js')` con `APP_URL=https://tickets.qsoftware.biz` → `https://tickets.qsoftware.biz/js/builds/app.js` (correcto)

---

## Expected Behavior

### Preservation Requirements

**Comportamientos que NO deben cambiar:**
- Requests a la API con token inválido o ausente deben continuar retornando JSON 401
- Requests a la API con token expirado deben continuar retornando JSON 401
- Requests que superen el rate limit deben continuar retornando JSON 429
- Rutas web de FreeScout (login, conversaciones, configuración) deben continuar funcionando con middleware `web` y protección CSRF
- El filtrado de conversaciones por `mailbox_ids` del token debe continuar funcionando
- El dashboard web debe continuar renderizando correctamente una vez corregidas las URLs

**Scope:**
Todas las requests que NO sean a rutas `api/v1/*` con token Bearer deben quedar completamente sin cambios. La eliminación de `start.php` no afecta ninguna otra funcionalidad del módulo porque `RestApiServiceProvider::boot()` ya registra todo lo necesario.

---

## Hypothesized Root Cause

### Bug 1

1. **Carga en contexto `web`**: `start.php` es incluido por FreeScout dentro del grupo de rutas `web`, que aplica el middleware CSRF. Las rutas API no envían CSRF token, por lo que las requests POST/PUT/DELETE son bloqueadas, y las GET pueden quedar en estado inconsistente por el doble registro.

2. **Registro duplicado**: Al registrarse dos veces el mismo archivo de rutas, Laravel puede tener comportamiento indefinido: la primera ruta que matchea gana, y si esa fue registrada en contexto `web`, el middleware CSRF se aplica.

3. **Orden de inicialización**: `start.php` se carga antes que el `ServiceProvider`, por lo que la versión "web" de las rutas tiene precedencia sobre la versión correcta registrada por `loadRoutesFrom()`.

### Bug 2

1. **Concatenación de URL**: Laravel usa `rtrim(APP_URL, '/')` internamente en algunos helpers pero no en todos. La función `asset()` concatena directamente `APP_URL . '/' . $path`, resultando en doble barra cuando `APP_URL` ya termina en `/`.

2. **Configuración incorrecta**: La convención de Laravel es que `APP_URL` no debe tener barra al final. El valor actual `https://tickets.qsoftware.biz/` viola esta convención.

---

## Correctness Properties

Property 1: Bug Condition - Rutas API responden con JSON válido

_For any_ request HTTP donde la condición de bug Bug 1 se cumple (ruta `api/v1/*` con token Bearer válido), la función `handleApiRequest` corregida SHALL retornar una respuesta con código HTTP en el rango [200-429], body JSON válido y no vacío, sin interferencia del middleware CSRF.

**Validates: Requirements 2.1, 2.2, 2.3**

Property 2: Bug Condition - URLs de assets sin doble barra

_For any_ generación de URL de asset donde la condición de bug Bug 2 se cumple (APP_URL sin barra final), la función `asset()` SHALL retornar una URL sin doble barra (excluyendo el protocolo `https://`), accesible con HTTP 200.

**Validates: Requirements 2.4, 2.5**

Property 3: Preservation - Comportamiento de autenticación y rate limiting

_For any_ request donde la condición de bug Bug 1 NO se cumple (token inválido, ausente, expirado, o rate limit superado), el sistema corregido SHALL producir exactamente el mismo resultado que el sistema original, preservando las respuestas 401 y 429.

**Validates: Requirements 3.1, 3.2, 3.3**

Property 4: Preservation - Rutas web de FreeScout

_For any_ request a rutas web de FreeScout (no `api/v1/*`), el sistema corregido SHALL producir exactamente el mismo resultado que el sistema original, preservando el middleware `web` y la protección CSRF.

**Validates: Requirements 3.4, 3.5**

---

## Fix Implementation

### Bug 1 - Eliminar carga duplicada de rutas

**Archivo**: `Modules/RestApi/start.php`

**Cambio**: Eliminar (o vaciar) el contenido que carga `routes.php`, dejando que `RestApiServiceProvider::boot()` sea el único punto de registro de rutas.

**Antes:**
```php
<?php

$app = app();

if (!$app->routesAreCached()) {
    require __DIR__ . '/Http/routes.php';
}
```

**Después:**
```php
<?php

// Routes are registered by RestApiServiceProvider::boot() via loadRoutesFrom().
// Do not load routes here to avoid duplicate registration in the web middleware group.
```

**Razón**: `loadRoutesFrom()` en el `ServiceProvider` registra las rutas fuera del grupo `web`, en el contexto correcto para APIs. La carga en `start.php` es redundante y perjudicial.

**Nota**: No se modifica `RestApiServiceProvider.php` porque ya está correcto — `loadRoutesFrom()` es el mecanismo adecuado.

### Bug 2 - Remover barra final de APP_URL

**Archivo**: `.env`

**Cambio**: Remover la barra al final de `APP_URL`.

**Antes:**
```
APP_URL=https://tickets.qsoftware.biz/
```

**Después:**
```
APP_URL=https://tickets.qsoftware.biz
```

**Nota**: Después del cambio se debe ejecutar `php artisan freescout:clear-cache` para que el cambio tome efecto.

---

## Testing Strategy

### Validation Approach

La estrategia sigue dos fases: primero verificar el comportamiento defectuoso en el código sin corregir (exploratory), luego verificar que el fix funciona (fix checking) y que no rompe nada (preservation checking).

### Exploratory Bug Condition Checking

**Goal**: Demostrar el bug ANTES del fix para confirmar el root cause.

**Test Plan**: Ejecutar requests HTTP a la API con token válido y observar las respuestas vacías. Verificar en logs que las rutas están registradas dos veces. Verificar en el HTML del dashboard que las URLs de assets tienen doble barra.

**Test Cases**:
1. **API sin fix**: `curl -H "Authorization: Bearer <token>" https://tickets.qsoftware.biz/api/v1/conversations` → respuesta vacía (fallará en código sin fix)
2. **Assets sin fix**: Inspeccionar el HTML del dashboard y verificar `src="//js/builds/..."` (fallará en código sin fix)
3. **Rutas duplicadas**: `php artisan route:list | grep api/v1` → aparece dos veces cada ruta (confirma root cause)
4. **APP_URL con barra**: `grep APP_URL .env` → `APP_URL=https://tickets.qsoftware.biz/` (confirma root cause Bug 2)

**Expected Counterexamples**:
- La request a `/api/v1/conversations` retorna body vacío o error CSRF
- Las URLs de assets en el HTML tienen `//` después del dominio

### Fix Checking

**Goal**: Verificar que para todos los inputs donde la condición de bug se cumple, el sistema corregido produce el comportamiento esperado.

**Pseudocode:**
```
FOR ALL request WHERE isBugCondition_Bug1(request) DO
  result := handleApiRequest_fixed(request)
  ASSERT result.status IN [200, 201, 204, 400, 401, 403, 404, 422, 429]
  ASSERT result.body IS valid JSON
  ASSERT result.body IS NOT empty
END FOR

FOR ALL assetPath WHERE isBugCondition_Bug2(assetPath) DO
  url := asset_fixed(assetPath)
  ASSERT url NOT CONTAINS '//' (excluding 'https://')
  ASSERT HTTP_GET(url).status = 200
END FOR
```

### Preservation Checking

**Goal**: Verificar que para todos los inputs donde la condición de bug NO se cumple, el sistema corregido produce el mismo resultado que el original.

**Pseudocode:**
```
FOR ALL request WHERE NOT isBugCondition_Bug1(request) DO
  ASSERT handleApiRequest_original(request) = handleApiRequest_fixed(request)
END FOR

FOR ALL assetPath WHERE NOT isBugCondition_Bug2(assetPath) DO
  ASSERT asset_original(assetPath) = asset_fixed(assetPath)
END FOR
```

**Testing Approach**: Property-based testing es recomendado para preservation checking porque genera muchos casos automáticamente y captura edge cases que tests manuales podrían omitir.

**Test Cases**:
1. **Token inválido**: Verificar que `401` sigue retornándose con token inválido después del fix
2. **Rate limit**: Verificar que `429` sigue retornándose al superar el límite después del fix
3. **Rutas web**: Verificar que login y dashboard web siguen funcionando con CSRF después del fix
4. **Filtrado por mailbox**: Verificar que el filtrado por `mailbox_ids` del token sigue funcionando

### Unit Tests

- Test de registro de rutas: verificar que `php artisan route:list` muestra cada ruta API exactamente una vez
- Test de middleware: verificar que las rutas `api/v1/*` tienen middleware `api-token` y `api-rate-limit`, pero NO `web` ni `csrf`
- Test de generación de URL: verificar que `asset('js/builds/app.js')` no contiene `//` (excluyendo protocolo)

### Property-Based Tests

- Generar tokens aleatorios (válidos e inválidos) y verificar que las respuestas de autenticación son consistentes antes y después del fix
- Generar rutas de assets aleatorias y verificar que ninguna URL generada contiene doble barra
- Generar requests con distintos métodos HTTP (GET, POST, PUT, DELETE) y verificar que el middleware CSRF no interfiere en rutas `api/v1/*`

### Integration Tests

- Test de flujo completo: crear conversación via API, leerla, actualizarla y eliminarla con token válido
- Test de dashboard: cargar el dashboard web y verificar que todos los assets responden con HTTP 200
- Test de coexistencia: verificar que rutas web y rutas API funcionan simultáneamente sin interferencia
