# Changelog

All notable changes to the REST API module will be documented in this file.

La versión se declara **solo** en `module.json` (es la que muestra FreeScout en la página de
módulos y la que devuelve `RestApiServiceProvider::moduleVersion()`). Esquema `MAYOR.MENOR.PARCHE`:
MAYOR = cambio incompatible en la API, MENOR = funcionalidad nueva compatible, PARCHE = correcciones.

## [1.5.0] - 2026-10-05

### Added
- **Varios tipos de medio en los endpoints**, incluido **WhatsApp**: `email` (1), `phone` (2),
  `chat` (3), `custom` (4) y `whatsapp` (5). Se acepta `type` (numérico) o `channel` (nombre).
- `Support/Channels.php`: nombres de canal, validaciones por medio, comprobación de configuración
  del buzón y encolado de la entrega.
- Entrega real de los mensajes en la cola `emails`: `App\Jobs\SendWhatsappReply` (Evolution API)
  y `App\Jobs\SendReplyToCustomer` (correo).
- Campo `send_message` (alias `send`) para decidir si el mensaje se entrega al cliente. WhatsApp se
  entrega por defecto; el correo solo si se pide explícitamente (compatibilidad).
- Errores estructurados de configuración: `whatsapp_not_configured`, `email_not_configured`,
  `phone_required`, `unsupported_channel`, `customer_unresolved`.
- CRUD de clientes completo: `PUT`/`DELETE /customers/{id}` (las rutas existían sin método).
- `ConversationDTO` expone `type_name` y `channel`; `ThreadDTO` expone `send_status` y
  `send_status_name`; las respuestas de creación añaden el bloque `delivery`.
- `docs/qchat-integration.md` (guía de integración) y `docs/openapi.yaml` (OpenAPI 3.0).

### Changed
- **Los tokens de API ya no están restringidos por tipo de medio.** El mismo token opera con
  email, teléfono, chat, personalizado y WhatsApp; solo se mantiene la restricción por buzón
  (`mailbox_ids`). Se eliminó el selector "Tipo de origen de conversación" del formulario.
- Las conversaciones de `phone`/`whatsapp` identifican al cliente por teléfono (`phone` o `to[0]`).
- El error de configuración de canal se devuelve **antes** de crear la conversación (422).

### Fixed
- Doble codificación de `mailbox_ids` que producía HTTP 500 y filtraba de menos en `index()`.
- `whereHas`/`orWhereHas` (el `Query\Builder` sobrescrito por FreeScout revienta) → subconsultas.
- `now()->isAfter()`, que no existe en el Carbon incluido → `gt()`.
- La validación de `StoreConversationRequest` ignoraba `channel` (solo miraba `type`).
- `thread.send_status` no se actualizaba (`SendLogObserver` no está registrado en
  `AppServiceProvider`); ahora el job lo actualiza explícitamente.

## [1.0.0] - 2026-04-15

### Added
- Initial release of REST API module
- Authentication via Bearer tokens
- API Key management with rate limiting
- Conversation CRUD endpoints
- Thread/message management endpoints
- Customer management endpoints
- Webhook support with signature verification
- Audit logging for all API calls
- Rate limiting per API token
- OpenAPI/Swagger documentation
- Comprehensive test suite
- CLI commands for token management

### Security
- HMAC-SHA256 token hashing
- Bearer token authentication
- Per-mailbox authorization
- Token expiration support
- Rate limiting to prevent abuse

### Features
- List, create, read, update, delete conversations
- Add reply messages and internal notes
- Manage customers
- Real-time webhooks for events
- Pagination support
- Advanced filtering and sorting
- Audit logs with response times

## Future Releases

### Planned for v1.1.0
- [ ] Swagger UI endpoint
- [ ] Postman collection export
- [ ] GraphQL support
- [ ] Batch operations
- [ ] File attachments via API
- [ ] Search endpoint

### Planned for v1.2.0
- [ ] OAuth 2.0 authentication
- [ ] Custom fields support
- [ ] Bulk email sending
- [ ] Template support
