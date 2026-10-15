# Avance publicado de los equipos — 9 de octubre de 2026

Corte de revisión: 17:12, America/Mexico_City. Repositorio: Proyecto-Grupal-API/Proyecto_Grupal. Se consultaron todas las ramas y se comprobó nuevamente que los HEAD no cambiaron durante la revisión. Los commits de esta tabla son fuentes reproducibles, no merges realizados.

## Método y límites

Se contrastó el código con las funciones y puntos de esfuerzo del PDF de diseño, páginas 7–13, y con la Definition of Done de la página 16. Cada equipo tiene 42 puntos de esfuerzo. El backend se estima por función y se pondera por esos puntos; UI e integración se estiman por flujos existentes, contratos, adaptadores y dependencias. Fórmula común orientativa: 60% backend + 20% UI + 20% integración. Se redondea a cinco puntos; considerar al menos ±5–10 puntos de incertidumbre.

Estos porcentajes son una evaluación de implementación visible y preparación para integrar, no una calificación oficial, avance de horas, certificación de calidad ni prueba de ejecución. Tener un modelo, un formulario o un adaptador simulado no equivale a cerrar una función. Se leyeron fuentes, rutas, servicios, proveedores, vistas, contratos y pruebas; no se ejecutaron las suites funcionales de los siete equipos en sus bases. Los conteos de pruebas no intervienen en la fórmula. Se comprobó también la sintaxis con TOKEN_PARSE de PHP 8.5.10: 1086 fuentes PHP únicas, sin errores de análisis. Ese resultado no prueba rutas, dependencias, conexión a bases, compilación de Vue ni ejecución funcional.

## Comparación de GitHub

| Equipo | Backend | UI | Integración | Global orientativo | Fuente y fecha |
|---|---:|---:|---:|---:|---|
| 1 · Identidad | 94% | 90% | 75% | **≈90%** | [8611154](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/86111548a7659ffa3dab4fd6ee441bb70160b3d9) · 07/10 17:57 |
| 2 · Finanzas y wallet | 84% | 80% | 40% | **≈75%** | [d4a7e6b](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/d4a7e6b8cfe2ba3fc0f9258605d6afcf02b54dd5) · 09/10 16:37 |
| 3 · Marketplace | 34% | 40% | 10% | **≈30%** | [85a63cb](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/85a63cbf81d34605c0bd8d6470b730e9b26fde54) · 07/10 18:13 |
| 4 · Inventarios | 82% | 85% | 30% | **≈70%** | [8876af1](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/8876af1b83f4f7f308b93e525710ebf413e61aa9) · 02/10 22:58 |
| 5 · Servicios al estudiante | 85% | 85% | 30% | **≈75%** | [257baeb](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/257baeb67ff72c4efd6fe04db05f81fe75351835) · 08/10 20:51 |
| 6 · Comunidad | 85% | 90% | 45% | **≈80%** | [233293b](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/233293b2d392daa49e27dfe08e55635bbb5775c1) · 24/09 13:35 |
| 7 · Recompensas y auditoría | 32% | 0% | 5% | **≈20%** | [71d49b9](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/71d49b959c095f6951a17e9825d0896e3ab2894a) · 29/09 22:54 |

## Selección de versiones

- E1: `feat/modulo-1-equipo-1` (8611154), más nuevo que su copia integrada y con corrección publicada de consentimientos.
- E2: `feat/modulo-2-equipo-2` (d4a7e6b). `Equipo-2` continúa en el prototipo de septiembre. Este HEAD es posterior a la revisión 57d6817 del mismo día: ya incorpora caja, recibos y varias interfaces que entonces faltaban.
- E3: `equipo3_negocios` (85a63cb), proyecto dentro de `campus-digital-equipo3/`. El controlador MarketplaceController es idéntico al ZIP previamente entregado.
- E4: `Modulo_4` (8876af1).
- E5: `equipo5/completa-modulo-5` (257baeb), posterior y más completo que `Equipo-5` (c523af3). No se supone que su PR esté aprobado por elegir el avance de esa rama.
- E6: la publicación más reciente es `integracion/equipo1-comunidad` (233293b), del 24 de septiembre; `Modulo6_Comunidad` sigue en 055af14, del 22. El avance local más nuevo se informa aparte.
- E7: `Equipo-7` (71d49b9), del 29 de septiembre.
- `main` y `Testings` continúan en f1d03b1, el commit inicial. Las integraciones que vimos en otras ramas aún no están publicadas ahí.

## Evidencia y pendientes por equipo

### Equipo 1 — Identidad

Rama `feat/modulo-1-equipo-1`; commit `86111548a7659ffa3dab4fd6ee441bb70160b3d9`.

Implementado: Cuenta/perfil e importación, Fortify, activación, 2FA, NFC y ciclo de vida, QR, sesiones, estado académico, consentimientos y autorización business con APIs y pruebas.

Pendiente: Contratos externos institucionales/financieros y de algunos contextos; autoridad para ciertos cargos y fuente Department; despliegue conjunto del transporte de eventos con E7.

Fuentes: [routes/api.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/86111548a7659ffa3dab4fd6ee441bb70160b3d9/routes/api.php), [docs/integration/TEAM-1-INTEGRATION.md](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/86111548a7659ffa3dab4fd6ee441bb70160b3d9/docs/integration/TEAM-1-INTEGRATION.md), [app/Services/InstitutionalAuthorizationService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/86111548a7659ffa3dab4fd6ee441bb70160b3d9/app/Services/InstitutionalAuthorizationService.php).

Estimaciones de backend por función: 1.1: 95%, 1.2: 95%, 1.3: 90%, 1.4: 95%, 1.5: 95%, 1.6: 95%, 1.7: 95%, 1.8: 95%, 1.9: 95%, 1.10: 85%.

### Equipo 2 — Finanzas y wallet

Rama `feat/modulo-2-equipo-2`; commit `d4a7e6b8cfe2ba3fc0f9258605d6afcf02b54dd5`.

Implementado: Ledger SQL Server, recargas/retiros, pagos internos con bonos, devoluciones/reversos, retenciones y políticas, caja/turnos/arqueo, comprobantes de caja, confirmación del titular/supervisor, conciliación y diez pantallas financieras.

Pendiente: PendingFinancialWebAuthorizer, PendingCashAuthorizationProvider y PendingBonusAuthorizationProvider siguen denegando permisos. Identidad usa adaptador local antiguo. No hay todavía API OAuth de emisión de bonos para E6, checkout/cobro comercial y transferencia; contrapartida del vendedor y parte de recibos siguen pendientes.

Fuentes: [app/Providers/AppServiceProvider.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/d4a7e6b8cfe2ba3fc0f9258605d6afcf02b54dd5/app/Providers/AppServiceProvider.php), [routes/api.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/d4a7e6b8cfe2ba3fc0f9258605d6afcf02b54dd5/routes/api.php), [routes/web.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/d4a7e6b8cfe2ba3fc0f9258605d6afcf02b54dd5/routes/web.php), [docs/modulo-2/2.5-administracion-bonos.md](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/d4a7e6b8cfe2ba3fc0f9258605d6afcf02b54dd5/docs/modulo-2/2.5-administracion-bonos.md), [docs/modulo-2/2.8-api.md](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/d4a7e6b8cfe2ba3fc0f9258605d6afcf02b54dd5/docs/modulo-2/2.8-api.md).

Estimaciones de backend por función: 2.1: 90%, 2.2: 90%, 2.3: 90%, 2.4: 85%, 2.5: 85%, 2.6: 50%, 2.7: 95%, 2.8: 90%, 2.9: 65%, 2.10: 90%.

### Equipo 3 — Marketplace

Rama `equipo3_negocios`; commit `85a63cbf81d34605c0bd8d6470b730e9b26fde54`.

Implementado: Catálogo/negocios, carrito persistente, pedido e intento de pago, cinco páginas Vue y endpoints de catálogo. La base coincide con el controlador del ZIP entregado.

Pendiente: Pagos, reservas y puntos usan MockTeamAdapters. No aparecen flujos operativos completos de alta/aprobación de negocio, edición de tienda/catálogo, panel por vendedor o postventa. SPEI/Openpay quedan en validación/configuración; solo hay una prueba de acceso.

Fuentes: [campus-digital-equipo3/routes/web.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/routes/web.php), [campus-digital-equipo3/app/Http/Controllers/MarketplaceController.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/app/Http/Controllers/MarketplaceController.php), [campus-digital-equipo3/app/Services/Integrations/MockTeamAdapters.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/app/Services/Integrations/MockTeamAdapters.php), [campus-digital-equipo3/docs/integration-contracts.md](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/85a63cbf81d34605c0bd8d6470b730e9b26fde54/campus-digital-equipo3/docs/integration-contracts.md).

Estimaciones de backend por función: 3.1: 35%, 3.2: 15%, 3.3: 30%, 3.4: 55%, 3.5: 70%, 3.6: 45%, 3.7: 20%, 3.8: 10%, 3.9: 35%, 3.10: 15%.

### Equipo 4 — Inventarios

Rama `Modulo_4`; commit `8876af1b83f4f7f308b93e525710ebf413e61aa9`.

Implementado: Inventario, ubicaciones, kardex, proveedores, compra/recepción, devolución, costos, reserva/liberación, reorden, conteos y souvenirs con pantallas y APIs de reserva.

Pendiente: IdentityService usa stubs por defecto. Team1Client requiere HMAC y /users/{id}, que no coinciden con el contrato OAuth final de E1. Muchos servicios fijan un business_id de instalación; falta contexto multi-negocio por solicitud y prueba real con E3/E7.

Fuentes: [routes/web.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/8876af1b83f4f7f308b93e525710ebf413e61aa9/routes/web.php), [app/Services/Team1Client.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/8876af1b83f4f7f308b93e525710ebf413e61aa9/app/Services/Team1Client.php), [app/Services/IdentityService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/8876af1b83f4f7f308b93e525710ebf413e61aa9/app/Services/IdentityService.php), [app/Services/ReservationService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/8876af1b83f4f7f308b93e525710ebf413e61aa9/app/Services/ReservationService.php), [config/team1.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/8876af1b83f4f7f308b93e525710ebf413e61aa9/config/team1.php).

Estimaciones de backend por función: 4.1: 65%, 4.2: 85%, 4.3: 85%, 4.4: 90%, 4.5: 85%, 4.6: 85%, 4.7: 85%, 4.8: 85%, 4.9: 80%, 4.10: 85%, 4.11: 80%.

### Equipo 5 — Servicios al estudiante

Rama `equipo5/completa-modulo-5`; commit `257baeb67ff72c4efd6fe04db05f81fe75351835`.

Implementado: Biblioteca/préstamos/multas, lockers, instalaciones/descanso, rentas, impresiones, soporte/calendarios y validación de servicios. API de beneficios de lockers e impresiones, pruebas y auditoría de dominio.

Pendiente: En su rama publicada siguen LocalStudentDirectory, LocalStudentCredentialResolver y LocalStudentPaymentGateway; OAuth usa adaptador local. Faltan identidad, pagos/depósitos y avisos reales en esa rama. Parte de esto ya se resolvió en nuestra integración local, sin atribuirlo al GitHub del equipo.

Fuentes: [app/Providers/AppServiceProvider.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/257baeb67ff72c4efd6fe04db05f81fe75351835/app/Providers/AppServiceProvider.php), [routes/student-services.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/257baeb67ff72c4efd6fe04db05f81fe75351835/routes/student-services.php), [routes/api.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/257baeb67ff72c4efd6fe04db05f81fe75351835/routes/api.php), [docs/integration/EQUIPO-5-BENEFICIOS.md](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/257baeb67ff72c4efd6fe04db05f81fe75351835/docs/integration/EQUIPO-5-BENEFICIOS.md).

Estimaciones de backend por función: 5.1: 90%, 5.2: 75%, 5.3: 90%, 5.4: 85%, 5.5: 90%, 5.6: 90%, 5.7: 85%, 5.8: 85%, 5.9: 85%, 5.10: 90%, 5.11: 65%.

### Equipo 6 — Comunidad

Rama `integracion/equipo1-comunidad`; commit `233293b2d392daa49e27dfe08e55635bbb5775c1`.

Implementado: Organizaciones/Consejo/cargos, becas, eventos y Staff, campañas/bandeja, consultas/votaciones, transparencia. La publicación más nueva de Comunidad incluye una primera adaptación de Identidad.

Pendiente: En GitHub todavía faltan la integración final 1–5–6, la API nueva de avisos y entrega automática de beneficios. Pagos, caja/bonos, tiendas oficiales y auditoría central pendientes; siguen consultas heredadas al directorio/roles de E1.

Fuentes: [routes/comunidad.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/233293b2d392daa49e27dfe08e55635bbb5775c1/routes/comunidad.php), [docs/Contratos_Integracion_Modulo_6.md](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/233293b2d392daa49e27dfe08e55635bbb5775c1/docs/Contratos_Integracion_Modulo_6.md), [docs/integration/COMUNIDAD-EQUIPO-1.md](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/233293b2d392daa49e27dfe08e55635bbb5775c1/docs/integration/COMUNIDAD-EQUIPO-1.md).

Estimaciones de backend por función: 6.1: 90%, 6.2: 85%, 6.3: 80%, 6.4: 95%, 6.5: 45%, 6.6: 85%, 6.7: 90%, 6.8: 90%, 6.9: 90%, 6.10: 90%, 6.11: 90%.

### Equipo 7 — Recompensas y auditoría

Rama `Equipo-7`; commit `71d49b959c095f6951a17e9825d0896e3ab2894a`.

Implementado: Modelos y migraciones MongoDB; PointsLedgerService con earn/redeem/reverse, reglas/campañas/topes, faltantes, FraudFlag y auditoría ligada a puntos; veinte pruebas declaradas.

Pendiente: Sin controladores/API de dominio ni vistas de usuario. Falta catálogo/canje con inventario y entrega, membresías operativas, administración/configuración, auditoría transversal, sink de eventos y dashboards. Dependencias externas no explican toda la ausencia de esos flujos.

Fuentes: [src/app/Services/PointsLedgerService.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/app/Services/PointsLedgerService.php), [src/routes/web.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/routes/web.php), [src/tests/Feature/Services/PointsLedgerServiceTest.php](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/71d49b959c095f6951a17e9825d0896e3ab2894a/src/tests/Feature/Services/PointsLedgerServiceTest.php).

Estimaciones de backend por función: 7.1: 80%, 7.2: 20%, 7.3: 60%, 7.4: 15%, 7.5: 35%, 7.6: 45%, 7.7: 10%, 7.8: 30%, 7.9: 5%, 7.10: 10%, 7.11: 10%.

## Nuestro avance local

La carpeta `/home/daniel/campus_digital_integracion_e1`, rama `integracion/equipo1-servicios-comunidad`, incluye cambios todavía sin commit/publicación: Identidad final adaptada, Servicios, OAuth real, estado académico y QR/NFC por HTTP, becas de lockers/impresiones mediante API, avisos individuales, preferencias, reintentos y retiro. Esa fase tiene comprobación previa con HTTP real, compilación Vite y suites aisladas: 115 pruebas en la revisión extensa y 30 en la revisión final, con solapamiento (no se suman).

Como estimación independiente, Comunidad local ronda 85%: queda wallet/bonos/caja real, tiendas, auditoría central y sustituir accesos heredados al directorio/autorización. Este porcentaje no sustituye el 80% orientativo del código publicado ni indica que todos los dominios ya estén integrados. La documentación de esa fase es INTEGRACION_1_5_6.md.

## Cambios frente a la evaluación anterior

- La evaluación previa de E2 (57d6817, 09:01) rondaba 50% global. El nuevo corte (d4a7e6b, 16:37) ronda 75% por la incorporación real de 2.8/caja, comprobantes, pantallas y administración de bonos. Siguen denegados los proveedores de autorización pendientes y no aparece la API de emisión para E6.
- No se encontraron HEAD más nuevos de E1, E3, E4, E5, E6 publicado o E7 respecto a las referencias ya consultadas en el chat. Una reestimación distinta por inspección más precisa no significa que hayan perdido código o retrocedido.
- E3 no puede calificarse como prácticamente completo a partir de este avance público: el documento enumera diez funciones, pero las rutas implementadas se concentran en consulta, carrito y checkout simulado. El ZIP recibido conserva el mismo controlador.

## Recomendación para la unificación

1. Publicar/revisar el conjunto local 1–5–6 en la rama acordada cuando se solicite esa publicación.
2. Incorporar E4 como siguiente dominio, adaptando su contexto de negocio y la autorización al contrato OAuth actual de E1; comprobar reserva/confirmación/liberación antes de habilitar ventas.
3. Preparar E2 en SQL Server aislado y cerrar proveedores de autoridad, API de cobro/transferencia/bonos y contrapartida comercial. La cantidad nueva de pantallas no habilita por sí sola permisos ni cobros.
4. Pedir a E3 cierre de administración comercial y adaptar checkout a pagos/stock reales. Conservación de dinero pendiente cuando no hay resultado confirmado.
5. Integrar E7 por entregas: API/sink de eventos e idempotencia primero; puntos/recompensas y dashboards después. Puede desarrollar contratos, administración y recepción de eventos con fixtures mientras se conecta a los otros módulos.

No se modificó la aplicación ni se publicaron cambios como parte de esta reevaluación.
