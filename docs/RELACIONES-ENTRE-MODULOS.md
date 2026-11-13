# Relaciones entre módulos y lecturas entre dominios (rúbrica 7.6)

| Campo | Valor |
|---|---|
| Versión | 1.0 |
| Fecha | 2026-11-13 |
| Responsables | Integración del tercer parcial (cierre E1–E7) |
| Método | Análisis estático de `use App\Models\…` por módulo propietario del archivo y del modelo (`scripts/refactor-css-e5/xdomain.py` en el paquete de cierre) |

## 1. Diagrama global de relaciones

```mermaid
flowchart LR
  E1[E1 Identidad<br/>users, student_profiles, roles]
  E2[E2 Finanzas<br/>SQL Server: wallets, ledger]
  E3[E3 Marketplace<br/>businesses, orders]
  E4[E4 Inventario<br/>products, inventories]
  E5[E5 Servicios<br/>loans, lockers, tickets]
  E6[E6 Comunidad<br/>organizaciones, eventos, becas]
  E7[E7 Recompensas<br/>points_ledger, rewards]
  E2 -- "OAuth + user_id (adaptador Module1Identity)" --> E1
  E3 -- "HTTP: titular y estado" --> E1
  E3 -- "HTTP firmado: reservas" --> E4
  E6 -- "HTTP/OAuth: cobro de eventos" --> E2
  E2 -- "avisos" --> E6
  E4 -- "sobres firmados" --> E7
  E5 -- "sobres" --> E7
  E6 -- "sobres" --> E7
  E1 -- "sobres de identidad" --> E7
  E6 -- "beneficios de servicio" --> E5
```

Las flechas son contratos HTTP/sobres verificados en el laboratorio (09b, 9.x). Los identificadores canónicos son `users._id` (E1), `wallet public_id` (E2), `business_id` (E3/E4).

## 2. Lecturas directas de modelos de otro módulo (deuda 7.6)

| Lector → propietario | Archivos | Clasificación |
|---|---|---|
| E1 → E6 | 9 | Mayormente controladores de E6 en el espacio raíz `App\Http\Controllers` (clasificación ambigua por ubicación, no deuda de E1) |
| E6 → E1 | 24 | `User` (relación Eloquent con la identidad) y `ServiceClient` (OAuth); `CouncilBeca*` son de E1 |
| E3 → E1 | 1 | Comando de preparación (válido en mantenimiento) |
| E2 → E1 | 8 | `Module1IdentityAdapter` y `User` (válido); `FinancialAuthorizationService`/`FinancialRoleGrantService` son de E1 (falso positivo por nombre) |
| E5 → E1 | 2 | `StudentServicesController` es de **E1** (API `/api/v1/students/{id}/status` y consentimientos; falso positivo por nombre). `ServiceAuthority` de E5 autoriza con `User::hasRole` (API de E1) |
| E7 → E6 | 1 | **Falso positivo revisado:** `CommunityPlatformDelivery` es código de E6 (lee su propia auditoría); E7 recibe la proyección por HTTP: contrato válido |
| E5 → E6 | 2 | **Falso positivo revisado:** `EntregaBeneficiosServicios`/`RevocacionBeneficiosServicios` son código de E6 que lee su propia colección y llama a E5 por HTTP/OAuth (`/api/v1/servicios/asignaciones`): contrato válido |
| E7 → E1 | 1 | **Falso positivo:** `RewardsAuthorizationService` es código de E1 (autoriza derechos de E7 con `EffectiveRoleAssignments`) |

## 3. Revisión manual (2026-11-13) y conclusión
- Los servicios de autorización por dominio en `App\Services` (`FinancialAuthorizationService`, `FinancialRoleGrantService`, `RewardsAuthorizationService`, `CouncilBecaDesignationService`, `CouncilBecaWebAuthorization`) son **código de E1** (commits `feat(e1)`/`feat(identity)`): las colecciones `roles`, `role_permissions`, `permissions` y `role_assignments` sólo las lee E1. E5 autoriza con `User::hasRole` → `EffectiveRoleAssignments` (API de E1).
- `StudentServicesController` (API `/api/v1/students/{id}/status` y consentimientos) es de E1.
- Fuera de E1, los módulos sólo usan el agregado de identidad `User` (relación Eloquent por `users._id`) y `ServiceClient` (OAuth de servicio). Entre E2–E7 no hay lecturas de colecciones ajenas: las relaciones van por HTTP/sobres (E6→E2, E6→E5, E3→E4, E4/E5/E6→E7).
- Observación (no incumplimiento): E6 consulta `User` directamente en 22 archivos; podría canalizarse por un adaptador de identidad como `Module1IdentityAdapter` de E2 para reducir acoplamiento.

Estado en la rúbrica: 7.6 «Cumple» — diagrama global vigente y relaciones respetadas (identificadores canónicos, relación Eloquent con la identidad y contratos HTTP entre dominios).

## 4. Detalle por par

### E1->E6
- `app/Console/Commands/DeliverServiceBenefits.php` usa `AsignacionBeneficio`
- `app/Console/Commands/ReconcileScholarshipBonuses.php` usa `AsignacionBeneficio`
- `app/Http/Controllers/ComunicacionController.php` usa `AuditoriaComunidad`
- `app/Http/Controllers/ComunicacionController.php` usa `Campana`
- `app/Http/Controllers/ComunicacionController.php` usa `ConvocatoriaBeca`
- `app/Http/Controllers/ComunicacionController.php` usa `Evento`
- `app/Http/Controllers/ComunicacionController.php` usa `Mensaje`
- `app/Http/Controllers/ConsultaController.php` usa `AuditoriaComunidad`
- `app/Http/Controllers/ConsultaController.php` usa `ConsultaComunidad`
- `app/Http/Controllers/Controller.php` usa `Organizacion`
- `app/Http/Controllers/DashboardController.php` usa `Campana`
- `app/Http/Controllers/DashboardController.php` usa `ConvocatoriaBeca`
- `app/Http/Controllers/DashboardController.php` usa `Eleccion`
- `app/Http/Controllers/DashboardController.php` usa `Encuesta`
- `app/Http/Controllers/DashboardController.php` usa `Evento`
- `app/Http/Controllers/DashboardController.php` usa `Mensaje`
- `app/Http/Controllers/DashboardController.php` usa `MiembroOrganizacion`
- `app/Http/Controllers/DashboardController.php` usa `RegistroEvento`
- `app/Http/Controllers/DashboardController.php` usa `ReporteTransparencia`
- `app/Http/Controllers/DashboardController.php` usa `RespuestaEncuesta`
- `app/Http/Controllers/DashboardController.php` usa `SolicitudBeca`
- `app/Http/Controllers/DashboardController.php` usa `Voto`
- `app/Http/Controllers/NotificacionController.php` usa `Mensaje`
- `app/Services/AudienciaCampana.php` usa `Campana`
- `app/Services/AudienciaCampana.php` usa `ConvocatoriaBeca`
- `app/Services/AudienciaCampana.php` usa `Evento`
- `app/Services/AudienciaCampana.php` usa `MiembroOrganizacion`
- `app/Services/AudienciaCampana.php` usa `PreferenciaComunicacion`
- `app/Services/AudienciaCampana.php` usa `RegistroEvento`
- `app/Services/AudienciaCampana.php` usa `SolicitudBeca`
- `app/Services/EntregaCampanas.php` usa `AuditoriaComunidad`
- `app/Services/EntregaCampanas.php` usa `Campana`
- `app/Services/EntregaCampanas.php` usa `Mensaje`
- `app/Services/EntregaCampanas.php` usa `Organizacion`

### E6->E1
- `app/Console/Commands/GestorOrganizaciones.php` usa `User`
- `app/Console/Commands/PrepareCommunityEventPayments.php` usa `ServiceClient`
- `app/Console/Commands/RecuperarPresidencia.php` usa `User`
- `app/Http/Controllers/BecaController.php` usa `User`
- `app/Http/Controllers/EventoController.php` usa `User`
- `app/Http/Controllers/GestionOrganizacionesController.php` usa `User`
- `app/Http/Controllers/OrganizacionController.php` usa `User`
- `app/Http/Controllers/StaffEventoController.php` usa `User`
- `app/Http/Middleware/AuthorizeCommunityService.php` usa `ServiceClient`
- `app/Policies/EventoPolicy.php` usa `User`
- `app/Policies/OrganizacionPolicy.php` usa `User`
- `app/Services/AutoridadOrganizaciones.php` usa `User`
- `app/Services/ComisionesConsejo.php` usa `User`
- `app/Services/ComiteBecas.php` usa `User`
- `app/Services/ConsultasComunidad.php` usa `User`
- `app/Services/CouncilBecaDesignationService.php` usa `RoleAssignment`
- `app/Services/CouncilBecaDesignationService.php` usa `User`
- `app/Services/CouncilBecaWebAuthorization.php` usa `SecurityEvent`
- `app/Services/CouncilBecaWebAuthorization.php` usa `User`
- `app/Services/DelegacionesComunidad.php` usa `User`
- `app/Services/DocumentosOrganizacion.php` usa `User`
- `app/Services/InscripcionesEvento.php` usa `User`
- `app/Services/PeriodosOrganizacion.php` usa `User`
- `app/Services/PermisosComunidad.php` usa `User`
- `app/Services/RegistroOrganizaciones.php` usa `User`
- `app/Services/ResponsabilidadesComunidad.php` usa `User`

### E3->E1
- `app/Console/Commands/PrepareMarketplaceIntegration.php` usa `User`

### E2->E1
- `app/Contracts/FinancialGrantAuthority.php` usa `User`
- `app/Domains/Financial/Adapters/Module1IdentityAdapter.php` usa `User`
- `app/Domains/Financial/Services/BonusAdministrationService.php` usa `User`
- `app/Domains/Financial/Services/CashOperationConfirmationService.php` usa `User`
- `app/Domains/Financial/Services/ReceiptDelegationConsentService.php` usa `ServiceClient`
- `app/Http/Controllers/Financial/BonusAdministrationWebController.php` usa `User`
- `app/Services/FinancialAuthorizationService.php` usa `AcademicProgram`
- `app/Services/FinancialAuthorizationService.php` usa `Campus`
- `app/Services/FinancialAuthorizationService.php` usa `Permission`
- `app/Services/FinancialAuthorizationService.php` usa `Role`
- `app/Services/FinancialAuthorizationService.php` usa `RolePermission`
- `app/Services/FinancialAuthorizationService.php` usa `User`
- `app/Services/FinancialRoleGrantService.php` usa `AcademicProgram`
- `app/Services/FinancialRoleGrantService.php` usa `Campus`
- `app/Services/FinancialRoleGrantService.php` usa `Permission`
- `app/Services/FinancialRoleGrantService.php` usa `Role`
- `app/Services/FinancialRoleGrantService.php` usa `RoleAssignment`
- `app/Services/FinancialRoleGrantService.php` usa `RolePermission`
- `app/Services/FinancialRoleGrantService.php` usa `SecurityEvent`
- `app/Services/FinancialRoleGrantService.php` usa `User`

### E5->E1
- `app/Http/Controllers/StudentServicesController.php` usa `CommunicationPreference`
- `app/Http/Controllers/StudentServicesController.php` usa `Consent`
- `app/Http/Controllers/StudentServicesController.php` usa `StudentProfile`
- `app/Http/Controllers/StudentServicesController.php` usa `User`
- `app/Services/StudentServices/ServiceAuthority.php` usa `Role`
- `app/Services/StudentServices/ServiceAuthority.php` usa `User`

### E7->E6
- `app/Services/CommunityPlatformDelivery.php` usa `AuditoriaComunidad`

### E5->E6
- `app/Services/EntregaBeneficiosServicios.php` usa `AsignacionBeneficio`
- `app/Services/RevocacionBeneficiosServicios.php` usa `AsignacionBeneficio`
- `app/Services/RevocacionBeneficiosServicios.php` usa `AuditoriaComunidad`

### E7->E1
- `app/Services/RewardsAuthorizationService.php` usa `Permission`
- `app/Services/RewardsAuthorizationService.php` usa `Role`
- `app/Services/RewardsAuthorizationService.php` usa `RolePermission`
- `app/Services/RewardsAuthorizationService.php` usa `User`
