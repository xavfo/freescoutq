# Bugfix Requirements Document

## Introduction

Este documento cubre dos bugs en FreeScout con el módulo RestApi:

1. **Bug 1 - API REST sin respuesta**: El endpoint `GET /api/v1/conversations` con token Bearer válido no devuelve ninguna respuesta. Las rutas se registran dos veces (en `start.php` y en `RestApiServiceProvider::boot()`), y al cargarse en el contexto del grupo `web` de FreeScout, el middleware CSRF puede bloquear las requests o las rutas quedan en un estado inconsistente que produce respuesta vacía.

2. **Bug 2 - Dashboard solo muestra "Toggle Navigation"**: La interfaz web renderiza el HTML completo pero visualmente solo aparece el botón "Toggle Navigation". Los assets (CSS/JS) se cargan con URLs de doble barra (`//js/builds/...`) causando 404s, porque `APP_URL` en `.env` tiene barra al final (`https://tickets.qsoftware.biz/`).

---

## Bug Analysis

### Current Behavior (Defect)

**Bug 1 - Registro duplicado de rutas y respuesta vacía:**

1.1 WHEN se realiza `GET /api/v1/conversations` con un token Bearer válido THEN el sistema devuelve una respuesta vacía sin body ni código de estado HTTP visible

1.2 WHEN el módulo RestApi se inicializa THEN el sistema registra las rutas de `routes.php` dos veces: una vez desde `start.php` (en el contexto del grupo `web`) y otra desde `RestApiServiceProvider::boot()` via `loadRoutesFrom()`

1.3 WHEN las rutas API se registran dentro del grupo `web` de FreeScout (vía `start.php`) THEN el sistema aplica el middleware CSRF a las requests, bloqueando o corrompiendo las llamadas API que no envían token CSRF

**Bug 2 - URLs de assets con doble barra:**

1.4 WHEN se carga cualquier página del dashboard de FreeScout THEN el sistema genera URLs de assets con doble barra (`https://tickets.qsoftware.biz//js/builds/...`) causando que los archivos CSS y JS retornen 404

1.5 WHEN los archivos JS y CSS no cargan por las URLs incorrectas THEN el sistema muestra la página con solo el botón "Toggle Navigation" visible, sin estilos ni funcionalidad

### Expected Behavior (Correct)

**Bug 1 - Registro único de rutas sin interferencia CSRF:**

2.1 WHEN se realiza `GET /api/v1/conversations` con un token Bearer válido THEN el sistema SHALL retornar una respuesta JSON con el listado de conversaciones y código HTTP 200

2.2 WHEN el módulo RestApi se inicializa THEN el sistema SHALL registrar las rutas de `routes.php` una única vez, sin duplicados

2.3 WHEN las rutas API reciben una request THEN el sistema SHALL aplicar únicamente los middlewares `api-token` y `api-rate-limit`, sin el middleware CSRF del grupo `web`

**Bug 2 - URLs de assets correctas:**

2.4 WHEN se carga cualquier página del dashboard THEN el sistema SHALL generar URLs de assets sin doble barra (`https://tickets.qsoftware.biz/js/builds/...`)

2.5 WHEN los assets cargan correctamente THEN el sistema SHALL mostrar el dashboard completo con estilos, navegación y funcionalidad JavaScript operativa

### Unchanged Behavior (Regression Prevention)

3.1 WHEN se realiza una request a la API con un token inválido o ausente THEN el sistema SHALL CONTINUE TO retornar una respuesta JSON 401 con mensaje de error

3.2 WHEN se realiza una request a la API con un token expirado THEN el sistema SHALL CONTINUE TO retornar una respuesta JSON 401 indicando que el token expiró

3.3 WHEN se realiza una request a la API con un token válido pero que supera el rate limit THEN el sistema SHALL CONTINUE TO retornar una respuesta JSON 429

3.4 WHEN un usuario autenticado accede al dashboard web de FreeScout THEN el sistema SHALL CONTINUE TO renderizar la interfaz completa con todas las funcionalidades existentes

3.5 WHEN se accede a rutas web normales de FreeScout (login, conversaciones, configuración) THEN el sistema SHALL CONTINUE TO funcionar con el middleware `web` y protección CSRF sin cambios

3.6 WHEN el token API tiene restricción de mailboxes THEN el sistema SHALL CONTINUE TO filtrar las conversaciones retornadas según los mailbox_ids autorizados

---

## Bug Condition Derivation

### Bug 1 - Condición de bug

```pascal
FUNCTION isBugCondition_Bug1(X)
  INPUT: X of type HttpRequest
  OUTPUT: boolean

  // El bug se activa cuando la request llega a una ruta API
  // que fue registrada dos veces (una en contexto web con CSRF)
  RETURN X.path STARTS_WITH 'api/v1/'
     AND X.header('Authorization') MATCHES 'Bearer .+'
END FUNCTION

// Property: Fix Checking - Rutas API responden correctamente
FOR ALL X WHERE isBugCondition_Bug1(X) DO
  result ← handleApiRequest'(X)
  ASSERT result.status IN [200, 201, 204, 400, 401, 403, 404, 422, 429]
  ASSERT result.body IS valid JSON
  ASSERT result IS NOT empty
END FOR

// Property: Preservation Checking
FOR ALL X WHERE NOT isBugCondition_Bug1(X) DO
  ASSERT handleWebRequest(X) = handleWebRequest'(X)
END FOR
```

### Bug 2 - Condición de bug

```pascal
FUNCTION isBugCondition_Bug2(X)
  INPUT: X of type AssetUrlGeneration
  OUTPUT: boolean

  // El bug se activa cuando APP_URL termina en '/'
  // y Laravel concatena la ruta del asset
  RETURN APP_URL ENDS_WITH '/'
END FUNCTION

// Property: Fix Checking - URLs de assets sin doble barra
FOR ALL X WHERE isBugCondition_Bug2(X) DO
  url ← generateAssetUrl'(X.assetPath)
  ASSERT url NOT CONTAINS '//'  (excepto en el protocolo https://)
  ASSERT url IS accessible (HTTP 200)
END FOR

// Property: Preservation Checking
FOR ALL X WHERE NOT isBugCondition_Bug2(X) DO
  ASSERT generateAssetUrl(X) = generateAssetUrl'(X)
END FOR
```
