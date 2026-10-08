# Changelog

Todos los cambios notables en este proyecto se documentan en este archivo.

## [0.1.0-dev] - 2026-08-28

### Rama: `develop`

El desarrollo activo ocurre en la rama `develop`. Los cambios documentados aquí están en construcción y no están listos para producción.

### Added

#### Módulos 1.8 y 1.9: Condición Estudiantil y Consentimientos

- **Interfaz de usuario protegida** en ruta `/student-services`
  - Panel de condición académica con estado, matrícula, programa y campus
  - Visualización de consentimientos (aceptados y pendientes)
  - Gestión de preferencias de comunicación (email, push, SMS)
  
- **API REST v1** bajo `/api/v1` con datos simulados
  - `GET /students/{studentId}/status` - Consulta estado actual
  - `GET /students/{studentId}/status/history` - Historial de cambios
  - `GET /students/{studentId}/consents` - Lista consentimientos
  - `POST /students/{studentId}/consents` - Acepta consentimiento
  - `DELETE /students/{studentId}/consents/{consentId}` - Revoca consentimiento
  - `GET /students/{studentId}/preferences` - Consulta preferencias
  - `PATCH /students/{studentId}/preferences` - Actualiza preferencias

- **Estructura de respuestas uniforme**
  - Todas las respuestas incluyen `data`, `meta` (con `request_id` y `api_version`)
  - Manejo de errores compatible con RFC 7807
  - Validación de payloads en endpoints POST/PATCH

#### Identidad Visual de Campus Digital

- **Logo institucional** en formato SVG (`logo.svg` e isotipo `logo-mark.svg`)
- **Paleta de colores institucionales**
  - Azul marino profundo: `#00338D`
  - Gris pizarra: `#64748B`
  - Blanco puro: `#FFFFFF`
  - Verde validación: `#10B981`
  - Azul NFC: `#0284C7`
  
- **Rediseño de interfaz pública y autenticada**
  - Portada `/` con propuesta de valor y acceso a módulos
  - Dashboard `/dashboard` con acceso directo a módulos 1.8 y 1.9
  - Pantalla de login/registro con composición visual propia
  - Navegación autenticada con logo institucional
  
- **Tipografía Manrope** como fuente principal

### Changed

- Componente `ApplicationLogo.vue` ahora utiliza `logo.svg` en lugar de SVG genérico de Laravel
- Plantilla `GuestLayout.vue` rediseñada con panel lateral azul marino para acceso
- Pantalla de bienvenida `Welcome.vue` reemplazada con propuesta de Campus Digital
- Dashboard actualizado para mostrar acceso a módulos 1.8 y 1.9
- `tailwind.config.js` configurado para tipografía Manrope

### Technical Details

- Controlador `StudentServicesController.php` implementa lógica de módulos con datos simulados
- Rutas API registradas bajo `/routes/api.php` con prefijo `/api/v1`
- Base de datos no integrada aún; lista para migración una vez definido motor (PostgreSQL, SQL Server, MongoDB)
- 2FA preparado en infraestructura (Fortify), interfaz pendiente

### Known Limitations

- **Datos simulados:** Información de estudiante, consentimientos y preferencias son hardcoded
- **Sin persistencia:** Las actualizaciones no se guardan en BD
- **Base de datos pendiente:** Motor aún por definir con el equipo
- **Autenticación entre microservicios:** OAuth 2.0 con tokens JWT está diseñado pero no implementado
- **Eventos:** Estructura preparada, integración pendiente

### Notes for Developers

- Los módulos 1.8 y 1.9 funcionar independientemente; no dependen de otros equipos
- El contrato API es estable; puede consumirse desde otros microservicios
- Datos simulados pueden reemplazarse fácilmente por persistencia real una vez definida la BD
- Documentación API en comentarios inline del controlador

## [0.0.1] - 2026-08-21

### Rama: `main`

Versión inicial con estructura de Laravel, autenticación base y Fortify.

### Added

- Proyecto Laravel 13 con PHP 8.3
- Autenticación con Laravel Fortify y Breeze
- Vue 3 + Inertia.js + Vite
- 2FA preparado (migraciones y configuración base)
- Tailwind CSS
- Migraciones iniciales para usuarios y 2FA
- Repositorio GitHub: `https://github.com/Julian-Darkstar/campus-virtual`

### Status

- Estructura lista para desarrollo
- Autenticación funcional
- Base de datos: sqlite (provisional, cambiar según decisión del equipo)
