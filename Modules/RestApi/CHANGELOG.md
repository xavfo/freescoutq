# Changelog

All notable changes to the REST API module will be documented in this file.

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
