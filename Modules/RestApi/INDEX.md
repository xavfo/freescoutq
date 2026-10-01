# Módulo REST API - Índice de Archivos Creados

## 📂 Estructura del Módulo

```
Modules/RestApi/
├── module.json                     # Metadata del módulo
├── start.php                       # Entry point
├── composer.json                   # Dependencias
├── README.md                       # Documentación principal
├── CHANGELOG.md                    # Historial de cambios
├── .gitignore                      # Git ignore
│
├── Config/
│   └── config.php                  # Configuración del módulo
│
├── Http/
│   ├── routes.php                  # Rutas API REST
│   ├── Controllers/
│   │   ├── ConversationsController.php    # CRUD conversations
│   │   ├── ThreadsController.php          # CRUD threads/replies
│   │   └── CustomersController.php        # CRUD customers
│   ├── Middleware/
│   │   ├── CheckApiTokenMiddleware.php    # Autenticación
│   │   └── RateLimitMiddleware.php        # Rate limiting
│   └── Requests/
│       ├── StoreConversationRequest.php   # Validación
│       ├── UpdateConversationRequest.php
│       ├── StoreThreadRequest.php
│       └── StoreCustomerRequest.php
│
├── Entities/
│   ├── ApiKey.php                  # Model API Keys
│   ├── ApiWebhook.php              # Model Webhooks
│   ├── ApiAuditLog.php             # Model Audit logs
│   ├── ApiRateLimit.php            # Model Rate limits
│   └── DTOs/
│       ├── ConversationDTO.php     # Data transformation
│       ├── ThreadDTO.php           # Data transformation
│       └── CustomerDTO.php         # Data transformation
│
├── Providers/
│   └── RestApiServiceProvider.php  # Registro del módulo
│
├── Console/
│   └── Commands/
│       ├── CreateApiToken.php      # Crear tokens
│       ├── RevokeApiToken.php      # Revocar tokens
│       └── ListApiTokens.php       # Listar tokens
│
├── Events/
│   ├── ConversationCreated.php     # Evento: conversation creada
│   ├── ConversationStatusChanged.php # Evento: status cambió
│   └── ThreadCreated.php           # Evento: thread creado
│
├── Listeners/
│   ├── TriggerConversationCreatedWebhook.php
│   ├── TriggerConversationStatusChangedWebhook.php
│   └── TriggerThreadCreatedWebhook.php
│
├── Database/
│   └── Migrations/
│       ├── 2026_04_15_000001_create_api_keys_table.php
│       ├── 2026_04_15_000002_create_api_audit_logs_table.php
│       ├── 2026_04_15_000003_create_api_webhooks_table.php
│       └── 2026_04_15_000004_create_api_rate_limit_table.php
│
├── Resources/
│   ├── docs/
│   │   ├── openapi.yaml            # Especificación Swagger
│   │   └── QUICK_START.md          # Guía rápida
│   └── views/
│       └── (vacío, ready para UI)
│
└── Tests/
    ├── Feature/
    │   └── Api/
    │       ├── ConversationsApiTest.php
    │       ├── ThreadsApiTest.php
    │       └── CustomersApiTest.php
    └── Unit/
        └── ApiKeyTest.php
```

## 🚀 Inicio Rápido

### 1. Activar el Módulo

```bash
# Opción A: Desde admin panel
# Ir a: Módulos → REST API → Activar

# Opción B: CLI
php artisan module:enable rest-api
php artisan freescout:module-install rest-api
```

### 2. Crear Primer Token

```bash
php artisan restapi:create-token 1 "Mi Integración" --mailbox_ids=1,2
```

### 3. Hacer Primera Llamada

```bash
curl -H "Authorization: Bearer fs_xxxxxxxxxxxx" \
  https://tu-servidor.com/api/v1/conversations
```

## 📋 Resumen de Funcionalidades

### ✅ Autenticación & Seguridad
- [x] Bearer token authentication
- [x] API Key management con DB
- [x] Token hashing (SHA-256)
- [x] Token expiration support
- [x] Per-mailbox authorization
- [x] Rate limiting (configurable)
- [x] Audit logging completo
- [x] Webhook signature verification

### ✅ Endpoints REST

#### Conversations (Tickets)
```
GET    /api/v1/conversations          # Listar (con filtros, paginación, sort)
POST   /api/v1/conversations          # Crear nueva
GET    /api/v1/conversations/{id}     # Obtener una
PUT    /api/v1/conversations/{id}     # Actualizar (status, asignado, cliente)
DELETE /api/v1/conversations/{id}     # Soft delete
```

#### Threads (Mensajes/Respuestas)
```
GET    /api/v1/conversations/{id}/threads    # Listar threads
POST   /api/v1/conversations/{id}/threads    # Agregar respuesta/nota
GET    /api/v1/threads/{id}                  # Obtener un thread
PUT    /api/v1/threads/{id}                  # Editar thread
DELETE /api/v1/threads/{id}                  # Eliminar thread
```

#### Customers (Clientes)
```
GET    /api/v1/customers              # Listar con búsqueda
POST   /api/v1/customers              # Crear cliente
GET    /api/v1/customers/{id}         # Obtener con últimas conversations
```

### ✅ Webhooks
- [x] Eventos: `conversation.created`, `conversation.status_changed`, `thread.created`
- [x] Firmas HMAC-SHA256
- [x] Retry con backoff exponencial
- [x] Registro de intentos fallidos

### ✅ Rate Limiting
- [x] 1000 requests/hour (por defecto, configurable)
- [x] Cache-based tracking
- [x] Headers: `X-RateLimit-{Limit,Remaining,Reset}`
- [x] Respuesta 429 cuando se excede

### ✅ Audit Logging
- [x] Tabla `api_audit_logs`
- [x] Registra: user, method, endpoint, code, response time, IP
- [x] Queryable via DB o CLI

### ✅ Documentación & Testing
- [x] OpenAPI 3.0 spec completo
- [x] Guía Quick Start con ejemplos
- [x] Tests unitarios y de feature
- [x] Ejemplos en cURL, JS, Python

## 📊 Base de Datos

### Tablas Creadas (Migraciones)

```
api_keys
├── id, user_id, name, token, token_hash
├── mailbox_ids (JSON), rate_limit
├── active, expires_at, created_at, updated_at

api_audit_logs
├── id, user_id, api_key_id
├── method, endpoint, query_parameters
├── response_code, response_time, response_message
├── ip_address, created_at

api_webhooks
├── id, user_id, url
├── events (JSON), secret_key, active
├── retry_count, last_triggered_at, last_failed_at
├── last_error_message, created_at

api_rate_limits
├── id, api_key_id, request_count
├── window_starts_at, created_at
```

## 🎯 Línea de Comandos Disponibles

```bash
# Crear token
php artisan restapi:create-token 1 "Token Name"
  --mailbox_ids=1,2,3
  --rate_limit=5000
  --expires=2026-12-31

# Listar tokens
php artisan restapi:list-tokens
php artisan restapi:list-tokens --user_id=1

# Revocar token
php artisan restapi:revoke-token 1
```

## 🧪 Ejecución de Tests

```bash
# Todos los tests del módulo
php artisan test --filter=RestApi

# Tests específicos
php artisan test Tests/Feature/Api/ConversationsApiTest
php artisan test Tests/Unit/ApiKeyTest.php

# Con coverage
php artisan test --filter=RestApi --coverage
```

## 📖 Documentación

1. **README.md** - Documentación completa del módulo
2. **QUICK_START.md** - Ejemplos prácticos en múltiples lenguajes
3. **openapi.yaml** - Especificación OpenAPI 3.0 (Swagger)
4. **CHANGELOG.md** - Historial de cambios

## 🔑 Características Destacadas

### DTOs Customizados
Transforma modelos del core a respuestas limpias:
- `ConversationDTO` → {id, subject, customer, status, assignee, threads_count, ...}
- `ThreadDTO` → {id, from, to, body, type, created_by, attachments_count, ...}
- `CustomerDTO` → {id, name, emails, phone, conversations_count, ...}

### Reutilización de Lógica del Core
- ✅ Usa modelos del core (`App\Conversation`, `App\Thread`, `App\Customer`)
- ✅ Sin duplicación de código
- ✅ Aprovecha observers y events del core
- ✅ Compatible con actualizaciones de FreeScout

### No Modificación del Sistema Base
- ✅ Totalmente encapsulado en `Modules/RestApi/`
- ✅ Cero cambios en archivos core (app/, config/, etc.)
- ✅ Actualizable desde GitHub sin conflictos

## 🔐 Configuración de Seguridad

Editar `config/rest-api.php`:

```php
'api' => [
    'rate_limit_default' => 1000,
    'rate_limit_window' => 3600,
],

'webhooks' => [
    'enabled' => true,
    'timeout' => 10,
    'retries' => 3,
    'backoff_factor' => 2,
],

'logging' => [
    'enabled' => true,
    'log_failed_requests' => true,
],
```

## ⏭️ Próximos Pasos (Mejoras Futuras)

- [ ] UI para gestionar tokens desde admin panel
- [ ] Swagger UI endpoint
- [ ] Postman collection auto-export
- [ ] GraphQL endpoint
- [ ] OAuth 2.0 support
- [ ] Batch operations
- [ ] File attachments via API

## 📞 Soporte

- Documentación: [api-docs.freescout.net](https://api-docs.freescout.net)
- Issues: GitHub issues en [freescoutapp/freescout](https://github.com/freescoutapp/freescout)

---

**Estado**: ✅ Implementación completa - Listo para producción

**Versión**: 1.0.0  
**Fecha**: 2026-04-15  
**Licencia**: AGPL-3.0
