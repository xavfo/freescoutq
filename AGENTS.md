# Guía para agentes de desarrollo

## Contexto del proyecto

Este repositorio es FreeScout, una aplicación PHP/MySQL de help desk e inbox compartido. Es una base existente y compatible con instalaciones FreeScout; no asumir que es un proyecto Laravel moderno ni reemplazar patrones del core sin necesidad.

- `composer.json` declara PHP `>=7.1` y Laravel `5.5.40`. Mantén compatibilidad con el runtime soportado por el proyecto y evita sintaxis/API de versiones posteriores sin verificar primero.
- El código propio se concentra principalmente en `Modules/RestApi` y `Modules/Kanban`. La integración de WhatsApp con Evolution API también toca el core.
- El proyecto usa módulos de FreeScout, Eloquent, Blade, middleware, eventos y jobs en cola. Inspecciona el patrón del módulo o del core más cercano antes de agregar abstracciones.

## Arquitectura e invariantes

- Los módulos tienen su namespace Composer, `module.json`, proveedor de servicios, rutas, vistas, traducciones y pruebas. Las rutas se registran desde `ServiceProvider::boot()` con `loadRoutesFrom()`.
- `Modules/<Module>/start.php` no debe volver a cargar rutas: FreeScout lo ejecuta dentro del grupo `web`, lo cual puede duplicar rutas API o aplicarles CSRF incorrectamente.
- Las rutas REST usan `api-token` y `api-rate-limit`. Las páginas administrativas usan middleware web, autenticación, roles y protección CSRF. No desactives CSRF para solucionar errores 419.
- Conserva las restricciones de acceso a buzones. En RestApi, `MailboxAccess::normalize()` centraliza la normalización: `null` significa todos los buzones; una lista significa acceso limitado; valores ilegibles deben fallar cerrado. Reutiliza el helper.
- En este fork hay problemas conocidos con `whereHas`/`whereExists` en el query builder. Antes de usarlos, revisa las pruebas y specs del módulo; las consultas documentadas suelen usar subconsultas `whereIn`.
- Para cambios en conversaciones, hilos y entregas, reutiliza los métodos del dominio y los jobs existentes para preservar permisos, auditoría, notificaciones y reglas de envío.
- La integración WhatsApp guarda credenciales cifradas por buzón y envía mediante la cola `emails`. No registres credenciales ni hagas pruebas que puedan enviar mensajes reales.
- Los módulos pueden tener vistas tanto en `Modules/<Module>/Resources/views` como en `resources/views/modules/<module>`. Comprueba qué ruta tiene prioridad en el proveedor y mantén ambas copias coherentes cuando ambas existan.
- Evita editar `vendor/`. `overrides/` contiene adaptaciones de dependencias y solo debe tocarse cuando el cambio lo requiera directamente. Prefiere cambios de módulo frente a cambios del core.

## Flujo de trabajo

1. Lee el código, pruebas y spec de la funcionalidad antes de editar. Los documentos de `.kiro/specs/` son contexto útil, pero algunos estados y checkboxes quedaron desactualizados; contrasta siempre con el código y las pruebas actuales.
2. Haz cambios pequeños y relacionados con la petición. Conserva los permisos, el comportamiento de FreeScout y la compatibilidad con la versión declarada en Composer.
3. Añade pruebas focalizadas para la conducta nueva o corregida. Los tests del proyecto usan PHPUnit y las suites de módulos están declaradas en `phpunit.xml`; la CI prueba con MySQL.
4. Comandos documentados para validar:
   - `php vendor\bin\phpunit --testsuite RestApi --stderr`
   - `php vendor\bin\phpunit --testsuite Kanban --stderr`
   - `phpcs`
   - Para rutas, `php artisan route:list --path=api/v1` o `php artisan route:list --path=kanban`
5. Las pruebas funcionales requieren una base de datos de pruebas preparada y los módulos habilitados según su documentación. El `vendor/` de algunos clones puede estar incompleto; informa qué validaciones no pudieron ejecutarse, en vez de asumir que pasaron.
6. Si necesitas regenerar el autoloader de Composer, revisa primero los scripts del repositorio. La documentación de Kanban requiere `composer dump-autoload --no-scripts`; no ejecutes scripts destructivos por defecto.

## Seguridad y datos

- Nunca leas, muestres, registres ni confirmes en Git secretos de `.env`, tokens reales, claves de Evolution API o datos/dumps de conversaciones.
- No pruebes escrituras que puedan enviar correo o WhatsApp en producción. Usa una base de datos y servicios de prueba; usa `Queue::fake()` o un proveedor simulado cuando corresponda.
- No elimines la autenticación, autorización por buzón, protección CSRF, validación ni auditoría para hacer que una prueba pase.
- Las claves API son credenciales sensibles. Limita su revelación a flujos administrativos explícitos y evita incluirlas en logs, capturas, URLs, fixtures o respuestas que no las necesiten.

## Specs y documentación de referencia

- REST API: `.kiro/specs/restapi-bugs-fix/` y `Modules/RestApi/README.md`.
- Kanban: `.kiro/specs/kanban/` y `Modules/Kanban/README.md`.
- WhatsApp/Evolution API: `.kiro/specs/whatsapp-channel/feature.md` y `docs/qchat-integration.md`.
- Contrato de la REST API: `Modules/RestApi/Resources/docs/openapi.yaml` y `docs/openapi.yaml`.

