# Guía de demostración por equipo (E1–E7)

Una guía por equipo, sacada de los recorridos con que se validó la candidata `6710e59`: los pasos listados son los que el recorrido ejecutó y comprobó, en ese orden. La contraseña de las cuentas es la que muestra `./campus.sh acceso` en tu instalación.

## Antes de empezar

- Instalación: `./campus.sh instalar` (ver `GUIA-WINDOWS-WSL2-DOCKER.md`).
- **Contraseña:** todas las cuentas `demo.*@campus.test` usan la misma, que muestra `./campus.sh acceso`. No aparece en ningún documento.
- **Segundo factor:** las cuentas marcadas con 2FA lo enrolan en su primer acceso (la pantalla muestra el código para la aplicación de autenticación). Conviene hacer ese primer acceso antes de la demostración.
- Cada equipo puede hacer su demostración sin que otro equipo haya hecho la suya: los datos de partida los deja el instalador.
- Los pasos de cobro con monedero piden confirmar la contraseña y autorizar el pago en la pantalla de Finanzas.

## E1 — Identidad

Entrada: `/students`.

### Cuentas

| Cuenta | Roles de Identidad | 2FA |
|---|---|---|
| `demo.e1` — Administración de Identidad | `admin` | sí |
| `demo.e1.alumno` — Alumno | `estudiante` | no |

### Recorrido

1. acceso con contraseña y segundo factor — *demo.e1*
2. acceso con contraseña (sin segundo factor) — *demo.e1.alumno*
3. 2FA obligatorio del administrador — *demo.e1*
4. 2FA no exigido al alumno — *demo.e1.alumno*
5. registro público de cuenta (/register) — *demo.e1.tmp.mwkjz06s*
6. salida de sesión — *demo.e1.tmp.mwkjz06s*
7. recuperación de cuenta: solicitud hasta el mensaje de envío — *demo.e1.tmp.mwkjz06s*
8. acceso rechazado con contraseña incorrecta — *demo.e1.tmp.mwkjz06s*
9. acceso por formulario — *demo.e1.tmp.mwkjz06s*
10. alumnos: listado — *demo.e1*
11. alumnos: búsqueda por matrícula — *demo.e1*
12. alumnos: alta por formulario — *demo.e1*
13. alumnos: rechazo por matrícula duplicada — *demo.e1*
14. alumnos: rechazo campus–carrera (carrera ARQ con campus CENTRAL) — *demo.e1*
15. alumnos: el cambio de estatus sin motivo se rechaza — *demo.e1*
16. alumnos: edición con cambio de estatus y motivo — *demo.e1*
17. alumnos: fotografía del perfil cargada desde el formulario de edición — *demo.e1*
18. historial de condición: registro del cambio del alumno de la corrida — *demo.e1*
19. historial de condición: consulta propia en /student-services — *demo.e1.alumno*
20. consentimientos: aceptar y revocar (comunicaciones institucionales) — *demo.e1.alumno*
21. preferencias de comunicación: activar y desactivar SMS — *demo.e1.alumno*
22. alumnos: importación CSV — *demo.e1*
23. alumnos: importación CSV rechazada por cabecera inválida — *demo.e1*
24. alumnos: filtros por estatus y campus — *demo.e1*
25. alumnos: reporte CSV con filtros — *demo.e1*
26. alumnos: generación de contraseña temporal — *demo.e1*
27. auditoría parcial: bitácora de seguridad propia — *demo.e1*
28. NFC: alta de tarjeta para el alumno — *demo.e1*
29. NFC: rechazo de un UID ya registrado — *demo.e1*
30. NFC: bloqueo — *demo.e1*
31. NFC: reactivación tras bloqueo — *demo.e1*
32. NFC: suspensión — *demo.e1*
33. NFC: reactivación tras suspensión — *demo.e1*
34. NFC: reporte de pérdida — *demo.e1*
35. NFC: reposición de la tarjeta perdida — *demo.e1*
36. NFC: reactivación de la tarjeta de reposición — *demo.e1*
37. NFC: historial de la tarjeta — *demo.e1*
38. NFC: el historial indica quién realizó cada cambio — *demo.e1*
39. NFC: el alumno consulta sus tarjetas y su historial — *demo.e1.alumno*
40. QR propio: identificación y dinámico — *demo.e1.alumno*
41. QR dinámico: regeneración — *demo.e1.alumno*
42. QR propio: identificación y dinámico — *demo.e1*
43. QR: simulador de validación del administrador (válido y después consumido) — *demo.e1*
44. QR: el administrador valida el código corto del alumno — *demo.e1*
45. roles contextuales: asignación — *demo.e1*
46. roles contextuales: revocación — *demo.e1*
47. dispositivos y sesiones: listado propio con dos navegadores — *demo.e1.tmp.mwkjz06s*
48. dispositivos: reautenticación y marcar como confiable — *demo.e1.tmp.mwkjz06s*
49. sesiones: cerrar todas las demás sesiones — *demo.e1.tmp.mwkjz06s*
50. perfil: consulta de los datos propios — *demo.e1.alumno*
51. perfil: edición del nombre — *demo.e1.tmp.mwkjz06s*
52. permiso negativo: listado de alumnos (/students) — *demo.e1.alumno*
53. permiso negativo: alta de alumnos (/students/create) — *demo.e1.alumno*
54. permiso negativo: reporte CSV de alumnos — *demo.e1.alumno*
55. permiso negativo: alta de tarjeta NFC — *demo.e1.alumno*
56. permiso negativo: asignación de roles — *demo.e1.alumno*
57. permiso negativo: simulador de validación de QR — *demo.e1.alumno*
58. API de Identidad: Servicios rechaza al alumno suspendido y lo acepta al reactivarlo — *demo.e1 → demo.e1.alumno*
59. API de Identidad: Servicios rechaza al alumno egresado y lo acepta al volver a activo — *demo.e1 → demo.e1.alumno*
60. auditoría global: los cambios de Identidad llegan a la plataforma y se ven en Recompensas — *demo.e1*
61. cierre lógico de la cuenta (DELETE /profile por su formulario) — *demo.e1.tmp.mwkjz06s*
62. acceso rechazado a la cuenta cerrada — *demo.e1.tmp.mwkjz06s*
63. salida de sesión — *demo.e1.alumno*
64. salida de sesión — *demo.e1*

### Lo que hoy no se puede demostrar

- **designaciones oficiales** (bloqueado): Sin pantalla: el menú de la administración de Identidad no tiene entrada de designaciones, /designaciones responde 404 y /roles/designaciones 404, y /roles sólo ofrece los seis roles del catálogo. El inventario indica además que la bandera IDENTITY_DESIGNATIONS_ENABLED está apagada.
- **recuperación completa de la cuenta por correo** (bloqueado): La interfaz llega al mensaje «Te enviamos por correo el enlace para restablecer tu contraseña.» y ahí termina: el enlace con el token sólo viaja por correo y ninguna pantalla lo muestra (en Mongo no queda token). El formulario /reset-password/{token} responde 200 y, con un token inventado, rechaza: 

### Resultado

| Corrida | Pasos | Correctos | Fallas | Bloqueados |
|---|---:|---:|---:|---:|
| Instalación 50, primera pasada | 67 | 65 | 0 | 2 |
| Instalación 51, primera pasada | 67 | 65 | 0 | 2 |
| Instalación 50, tras el reinicio | 71 | 69 | 0 | 2 |
| Instalación 51, tras el reinicio | 71 | 69 | 0 | 2 |

## E2 — Finanzas

Entrada: `/finanzas`.

### Cuentas

| Cuenta | Roles de Identidad | 2FA |
|---|---|---|
| `demo.e2` — Operación de caja | `estudiante` | no |
| `demo.e2.presidencia` — Presidencia | `estudiante` | no |
| `demo.e2.supervisor` — Supervisión de caja | `estudiante` | no |
| `demo.e2.alumno` — Alumno titular | `estudiante` | no |
| `demo.e2.alumno2` — Alumno destinatario | `estudiante` | no |
| `demo.e2.controles` — Controles financieros | `admin@service:finanzas` | sí |
| `demo.e2.consulta` — Consulta de controles | `admin@service:finanzas-consulta` | sí |

### Recorrido

1. monedero y saldo del alumno en /finanzas — *demo.e2.alumno*
2. historial de movimientos del alumno en /finanzas — *demo.e2.alumno*
3. identificador de monedero visible en /finanzas/ajustes (bloque «Wallet») — *demo.e2.alumno*
4. delegaciones de caja visibles para la presidencia en /modulo6/asociacion — *demo.e2.presidencia*
5. alta de la caja de la corrida en /finanzas/caja/administracion — *demo.e2*
6. edición de la caja (nombre) con historial de versiones — *demo.e2*
7. política de segunda autorización: umbral de recargas en /finanzas/caja/autorizaciones — *demo.e2*
8. apertura de turno con fondo inicial en /finanzas/caja — *demo.e2*
9. regla de caja: un solo turno abierto por caja — *demo.e2*
10. recarga en efectivo: el operador prepara la solicitud — *demo.e2*
11. recarga en efectivo: el alumno confirma en /finanzas/confirmaciones-caja — *demo.e2.alumno*
12. recarga en efectivo: el operador registra el efectivo (saldo, asiento y recibo) — *demo.e2*
13. recarga en efectivo: saldo y movimiento visibles para el alumno — *demo.e2.alumno*
14. permiso negativo: demo.e2.alumno no puede operar ni administrar caja — *demo.e2.alumno*
15. permiso negativo: demo.e2.presidencia no puede operar ni administrar caja — *demo.e2.presidencia*
16. retiro en efectivo: el operador prepara la solicitud — *demo.e2*
17. retiro en efectivo: el alumno confirma en /finanzas/confirmaciones-caja — *demo.e2.alumno*
18. retiro en efectivo: el operador registra el efectivo (saldo, asiento y recibo) — *demo.e2*
19. retiro en efectivo: saldo y movimiento visibles para el alumno — *demo.e2.alumno*
20. entrada de efectivo al turno — *demo.e2*
21. salida de efectivo del turno — *demo.e2*
22. permiso negativo: el operador no puede recargarse a sí mismo — *demo.e2*
23. ajuste de efectivo del turno (caja.ajustar) — *demo.e2*
24. recarga sobre el umbral: la solicitud queda a la espera de supervisor — *demo.e2*
25. permiso negativo: demo.e2.alumno2 no puede ver ni confirmar la operación de otro titular — *demo.e2.alumno2*
26. recarga sobre el umbral: el alumno confirma — *demo.e2.alumno*
27. permiso negativo: el operador no puede registrar sin supervisor ni autorizarse a sí mismo — *demo.e2*
28. recarga sobre el umbral: el supervisor autoriza en /finanzas/caja/autorizaciones — *demo.e2.supervisor*
29. recarga sobre el umbral: el operador registra tras la autorización — *demo.e2*
30. rechazo de una confirmación por el alumno — *demo.e2.alumno*
31. retiro en efectivo: la caja rechaza un retiro mayor que el saldo del titular — *demo.e2*
32. límite de retiro: la caja bloquea el retiro que lo supera y registra la alerta — *demo.e2.controles*
33. operación inusual: una recarga sobre el límite de alerta se registra y genera una alerta visible — *demo.e2.controles*
34. idempotencia: reintentar una recarga cuya respuesta se perdió no duplica el movimiento — *demo.e2*
35. comprobante de caja: ver e imprimir desde el historial del turno — *demo.e2*
36. comprobante de caja: descarga de PDF — *demo.e2*
37. consulta de caja por supervisión (caja.consultar) — *demo.e2.supervisor*
38. comprobante de caja visible para supervisión (caja.consultar) — *demo.e2.supervisor*
39. permiso negativo: demo.e2.supervisor no puede operar ni administrar caja — *demo.e2.supervisor*
40. permiso negativo: demo.e2.alumno no puede abrir el comprobante de caja de la asociación — *demo.e2.alumno*
41. transferencia entre alumnos en /finanzas/transferencias — *demo.e2.alumno*
42. transferencia: dos asientos (salida y entrada) y detalle del envío — *demo.e2.alumno*
43. transferencia: saldo y recepción visibles para demo.e2.alumno2 — *demo.e2.alumno2*
44. permiso negativo: el alumno no administra la política de transferencias — *demo.e2.alumno*
45. comprobantes personales: listado en /finanzas/comprobantes — *demo.e2.alumno*
46. comprobantes personales: ver comprobante de la recarga — *demo.e2.alumno*
47. comprobantes personales: descarga de PDF — *demo.e2.alumno*
48. permiso negativo: demo.e2.alumno2 no puede abrir el comprobante de otro titular — *demo.e2.alumno2*
49. solicitud de devolución del alumno en /finanzas/ajustes — *demo.e2.alumno*
50. cierre de turno con arqueo — *demo.e2*
51. cierre de un segundo turno con diferencia en el arqueo — *demo.e2*
52. edición de la caja (estado): desactivación tras el cierre — *demo.e2*
53. bonos del alumno en /finanzas/bonos (sólo lectura) — *demo.e2.alumno*
54. permiso negativo: nadie entra a la administración de bonos — *las cinco cuentas de E2*
55. permiso negativo: nadie opera los controles financieros (límites, alertas, conciliación) — *las cinco cuentas de E2*
56. permiso negativo: nadie consulta presupuestos institucionales — *las cinco cuentas de E2*
57. controles financieros: alta, evaluación y baja de un límite — *demo.e2.controles*
58. controles financieros: ejecución de una conciliación y consulta de alertas — *demo.e2.controles*
59. controles financieros: la conciliación reporta la diferencia de caja y se resuelve con una nota — *demo.e2.controles*
60. permiso negativo: la cuenta de consulta ve los controles pero no los administra — *demo.e2.consulta*
61. checkout de otros módulos: compra de Tienda pagada en Finanzas — *demo.e2.alumno*
62. transferencia identificando al destinatario con su QR dinámico de Identidad — *demo.e2.alumno*

### Lo que hoy no se puede demostrar

- **emisión de bonos** (bloqueado): Observado: /finanzas/bonos/administracion abre (HTTP 200) con «No tienes permiso para emitir bonos.» y sin formulario; POST …/issue responde 403 para las cinco cuentas. No hay bonos en la base.
- **cancelación de bonos** (bloqueado): Observado: «No tienes permiso para consultar registros administrativos.»; POST …/{bono}/cancel responde 403 y el listado 403. Sin bonos emitidos no hay nada que cancelar.
- **presupuestos institucionales** (bloqueado): Observado: /finanzas/presupuestos-institucionales responde 403 («Se requiere una cuenta habilitada, segundo factor confirmado y sesion vigente.») y no existe pantalla de alta.
- **reversos de operaciones** (bloqueado): Observado: en «Operaciones y reversos» los 14 botones «Reversar» están deshabilitados; POST …/reverse responde 403 («Sin permiso para esta operación.»).
- **retenciones administrativas (liberar, capturar y plazos)** (bloqueado): Observado: «No hay retenciones registradas.», «La consulta y administración de políticas requieren permisos específicos.» y «Nueva política» deshabilitado; POST …/policies responde 403. No hay interfaz para crear una retención.
- **aprobación y ejecución de devoluciones** (bloqueado): Observado: el botón «Aprobar» de la solicitud está deshabilitado y POST …/refunds/{id}/approve responde 403 («Sin permiso para esta operación.»); la solicitud sigue PENDIENTE.
- **recuperación de efectivo de un retiro** (bloqueado): Observado: en «Recuperar efectivo de un retiro» la interfaz indica «Esta acción requiere un permiso que tu cuenta no tiene.»; además exige una devolución de retiro APROBADA, que nadie puede aprobar.
- **consulta administrativa de comprobantes** (bloqueado): Observado: el formulario de /finanzas/comprobantes-administracion responde 403 («Sin permiso para esta operación.») al consultar la recarga de la corrida con contexto de asociación.
- **alta de monederos** (bloqueado): Sin pantalla: Finanzas crea los monederos con su servicio de dominio (WalletService), que en la demostración invoca el instalador; ninguna cuenta puede crear un monedero desde la interfaz. Su efecto sí es visible: cada titular ve su monedero en /finanzas (paso «monedero y saldo del alumno»). Queda c

### Resultado

| Corrida | Pasos | Correctos | Fallas | Bloqueados |
|---|---:|---:|---:|---:|
| Instalación 50, primera pasada | 72 | 63 | 0 | 9 |
| Instalación 51, primera pasada | 72 | 63 | 0 | 9 |
| Instalación 50, tras el reinicio | 78 | 69 | 0 | 9 |
| Instalación 51, tras el reinicio | 78 | 69 | 0 | 9 |

## E3 — Tienda

Entrada: `/tienda`.

### Cuentas

| Cuenta | Roles de Identidad | 2FA |
|---|---|---|
| `demo.e3` — Dueño de tienda | `business_owner@business:BUS-DEMO-E3`, `estudiante` | no |
| `demo.e3.revisor` — Revisión institucional | `admin@service:tienda` | sí |
| `demo.e3.cajero` — Caja de la tienda | `cashier@business:BUS-DEMO-E3`, `estudiante` | no |
| `demo.e3.comprador` — Comprador | `business_owner@business:BUS-E3-6afc1e948e83a2b22803f227`, `business_owner@business:BUS-E3-6afc30833af330df430fb7e0`, `estudiante` | no |

### Recorrido

1. catálogo público — *demo.e3.comprador*
2. solicitud de negocio nuevo — *demo.e3.comprador*
3. permiso negativo: el comprador no puede aprobar solicitudes — *demo.e3.comprador*
4. revisión: «Solicitar ajustes» — *demo.e3.revisor*
5. revisión: «Rechazar» — *demo.e3.revisor*
6. revisión: «Aprobar y crear negocio» (Identidad e Inventarios) — *demo.e3.revisor*
7. permiso negativo: el revisor no puede guardar la tienda de un negocio ajeno — *demo.e3.revisor*
8. estado del negocio: «Suspender» — *demo.e3.revisor*
9. estado del negocio: «Autorizar» de nuevo — *demo.e3.revisor*
10. estado del negocio: «Suspender» antes de la baja — *demo.e3.revisor*
11. estado del negocio: «Dar de baja» — *demo.e3.revisor*
12. catálogo del dueño: alta de un servicio — *demo.e3*
13. catálogo del dueño: edición del servicio — *demo.e3*
14. configuración comercial y SPEI — *demo.e3*
15. constructor de tienda: marca, descripción y puntos de entrega — *demo.e3*
16. constructor de tienda: horarios — *demo.e3*
17. constructor de tienda: políticas de devoluciones y privacidad — *demo.e3*
18. constructor de tienda: la portada se ve en la tienda pública — *demo.e3*
19. permiso negativo: el cajero no puede cambiar la configuración comercial — *demo.e3.cajero*
20. permiso negativo: el cajero no puede cambiar el catálogo — *demo.e3.cajero*
21. permiso negativo: el comprador no puede editar la tienda de BUS-DEMO-E3 — *demo.e3.comprador*
22. compra con monedero: carrito y registro del pedido («Wallet Campus Digital») — *demo.e3.comprador*
23. compra con monedero: «Pagar con wallet» abre la solicitud de cobro en Finanzas — *demo.e3.comprador*
24. compra con monedero: confirmar contraseña, autorizar y «Confirmar y pagar» — *demo.e3.comprador*
25. compra con monedero: cargo en el monedero y asiento en el ledger — *demo.e3.comprador*
26. compra con monedero: «Consultar pago» concilia el pedido — *demo.e3.comprador*
27. integración con Recompensas: la compra confirmada acumula puntos al comprador — *demo.e3.comprador*
28. permiso negativo: el comprador no puede atender pedidos — *demo.e3.comprador*
29. atención del pedido: «Preparar» — *demo.e3.cajero*
30. atención del pedido: «Listo» — *demo.e3.cajero*
31. atención del pedido: «Entregado» — *demo.e3.cajero*
32. pedido con SPEI: registro con las instrucciones del negocio — *demo.e3.comprador*
33. pedido con SPEI: envío de comprobante — *demo.e3.comprador*
34. cancelación de un pedido sin pagar — *demo.e3.comprador*
35. panel del vendedor — *demo.e3.cajero*
36. reporte CSV de ventas — *demo.e3.cajero*
37. postventa: el comprador solicita la devolución — *demo.e3.comprador*
38. postventa: el dueño resuelve la devolución («Aprobar devolución») — *demo.e3*
39. integración con Recompensas: la devolución aprobada revierte los puntos de la venta — *demo.e3.comprador*
40. catálogo del dueño: desactivación del servicio — *demo.e3*
41. permiso negativo: el comprador no puede editar productos de BUS-DEMO-E3 — *demo.e3.comprador*
42. auditoría comercial del negocio — *demo.e3*
43. auditoría comercial institucional (solicitudes y negocios) — *demo.e3.revisor*
44. tienda con inventario: el dueño deja existencias de su negocio en Inventarios — *demo.e3*
45. producto con inventario: alta en Tienda enlazada a las existencias de Inventarios — *demo.e3*
46. pedido de un producto con inventario: el pedido aparta existencias en Inventarios — *demo.e3.comprador*
47. pedido de un producto con inventario: al cancelarlo, Inventarios libera lo apartado — *demo.e3.comprador*
48. pedido de un producto con inventario: al pagarlo con monedero, Inventarios registra la venta en el kardex — *demo.e3.comprador*
49. pedidos: la pantalla confirma cada registro con su aviso — *demo.e3.comprador*

### Lo que hoy no se puede demostrar

- **Openpay** (bloqueado): Sólo se anotó lo que muestra la interfaz al elegir «Openpay» en el carrito: «Openpay aún está en integración. Este pedido quedará pendiente y no representa un cargo real.». No se registró ningún pedido con ese método.
- **confirmación de pago SPEI por el negocio** (bloqueado): Observado: en «Atención de pedidos» la fila del pedido SPEI sólo ofrece ["Cancelar"] (ningún control para confirmar el pago) y la ruta `resolver-pago` responde 503 «La confirmación de cobros requiere la integración de Finanzas. Un comprobante no autoriza marcar el pedido como pagado.». El pedido sig
- **reembolso real de una devolución** (bloqueado): Observado: al aprobar la devolución el pedido queda `return_approved`/`refund_pending` y la interfaz avisa «Resolución de postventa guardada. El reembolso/inventario requiere confirmar la integración propietaria.»; no hay control de reembolso, el saldo del comprador no cambia y Finanzas no registra 
- **gestión de personal del negocio** (bloqueado): Observado: la tarjeta del negocio no tiene formulario de personal, sólo la leyenda; la ruta `integrantes` responde 503 «Las asignaciones de personal pertenecen a Identidad. Su API de cambios todavía no está integrada.» y las membresías no cambian.
- **API de catálogo** (bloqueado): Observado: `/api/v1/marketplace/catalog` responde 401 porque no existe ningún cliente OAuth con el alcance `marketplace:read` (0 en la base). La variante con sesión `/api/tienda/catalogo` responde 200 con 2 productos (2 en la base), pero es JSON sin pantalla.

### Resultado

| Corrida | Pasos | Correctos | Fallas | Bloqueados |
|---|---:|---:|---:|---:|
| Instalación 50, primera pasada | 55 | 50 | 0 | 5 |
| Instalación 51, primera pasada | 55 | 48 | 2 | 5 |
| Instalación 50, tras el reinicio | 61 | 56 | 0 | 5 |
| Instalación 51, tras el reinicio | 61 | 56 | 0 | 5 |

## E4 — Inventarios

Entrada: `/equipo4`.

### Cuentas

| Cuenta | Roles de Identidad | 2FA |
|---|---|---|
| `demo.e4` — Propietario de inventario | `business_owner@business:BUS-CD-SOUV-001`, `estudiante` | no |
| `demo.e4.gerente` — Gerente | `business_manager@business:BUS-CD-SOUV-001`, `estudiante` | no |
| `demo.e4.almacen` — Responsable de almacén | `estudiante`, `inventory_manager@business:BUS-CD-SOUV-001` | no |
| `demo.e4.comprador` — Comprador | `buyer@business:BUS-CD-SOUV-001`, `estudiante` | no |
| `demo.e4.alumno` — Alumno sin rol | `estudiante` | no |

### Recorrido

1. panel — *demo.e4*
2. panel — *demo.e4.gerente*
3. panel — *demo.e4.almacen*
4. cambio de negocio: el selector lista los negocios activos — *demo.e4*
5. permiso negativo: cambiar a un negocio sin rol se rechaza — *demo.e4*
6. cambio de negocio: abrir el negocio propio — *demo.e4*
7. permiso negativo: alumno sin rol va a /equipo4/not-authorized — *demo.e4.alumno*
8. permiso negativo: alumno sin rol no abre el negocio ni entra a pantallas internas — *demo.e4.alumno*
9. categorías: alta — *demo.e4*
10. categorías: edición — *demo.e4*
11. almacenes: alta — *demo.e4.almacen*
12. almacenes: edición — *demo.e4.almacen*
13. almacenes: alta de un almacén principal y de un mostrador — *demo.e4.almacen*
14. ubicaciones: alta — *demo.e4.almacen*
15. ubicaciones: edición — *demo.e4.almacen*
16. productos: alta — *demo.e4*
17. productos: edición — *demo.e4*
18. productos: alta de un segundo producto (para eliminar) — *demo.e4*
19. permiso negativo: el gerente no puede eliminar productos — *demo.e4.gerente*
20. productos: eliminación (sólo propietario) — *demo.e4*
21. permiso negativo: el responsable de inventario no puede crear proveedores — *demo.e4.almacen*
22. proveedores: alta completa — *demo.e4.gerente*
23. proveedores: edición — *demo.e4*
24. órdenes de compra: alta por el gerente (solicitada) — *demo.e4.gerente*
25. órdenes de compra: edición por el gerente — *demo.e4.gerente*
26. permiso negativo: el gerente no puede autorizar la orden de compra — *demo.e4.gerente*
27. órdenes de compra: autorización por el propietario — *demo.e4*
28. permiso negativo: el responsable de inventario no puede registrar recepciones — *demo.e4.almacen*
29. recepción de la orden de compra por el gerente — *demo.e4.gerente*
30. existencias: la recepción sube la existencia — *demo.e4.gerente*
31. órdenes de compra: alta por el responsable de inventario (borrador) — *demo.e4.almacen*
32. permiso negativo: el responsable de inventario no puede autorizar órdenes de compra — *demo.e4.almacen*
33. órdenes de compra: cancelación por el propietario — *demo.e4*
34. existencias: listado e indicadores — *demo.e4.almacen*
35. existencias: ajuste con motivo — *demo.e4.almacen*
36. kardex: el ajuste aparece como movimiento — *demo.e4.almacen*
37. devolución a proveedor — *demo.e4.gerente*
38. permiso negativo: el responsable de inventario no devuelve a proveedor — *demo.e4.almacen*
39. devolución de cliente — *demo.e4.almacen*
40. costos de referencia: registro («+ Costo») — *demo.e4*
41. permiso negativo: el responsable de inventario no registra costos — *demo.e4.almacen*
42. permiso negativo: el gerente no puede crear reservas — *demo.e4.gerente*
43. reservas: reserva manual por el propietario — *demo.e4*
44. reservas: liberación — *demo.e4*
45. reservas: expirar vencidas — *demo.e4.almacen*
46. alertas: «Recalcular ahora» — *demo.e4.gerente*
47. reglas de reorden: sincronizar — *demo.e4.gerente*
48. reglas de reorden: editar punto — *demo.e4.gerente*
49. reglas de reorden: recalcular punto — *demo.e4.gerente*
50. alertas: descartar una — *demo.e4.gerente*
51. alertas: la alerta descartada no se vuelve a generar — *demo.e4.gerente*
52. conteos: alta — *demo.e4.almacen*
53. conteos: captura de la partida contada (las demás quedan sin contar) — *demo.e4.almacen*
54. conteos: cierre y ajuste de la diferencia — *demo.e4.almacen*
55. kardex: el ajuste del conteo aparece como movimiento — *demo.e4.almacen*
56. souvenirs: alta con preventa — *demo.e4*
57. souvenirs: la temporada capturada se guarda y se muestra al editar — *demo.e4*
58. souvenirs: edición de la preventa — *demo.e4*
59. transferencias entre ubicaciones — *demo.e4.almacen*
60. kardex: la transferencia deja salida y entrada — *demo.e4.almacen*
61. permiso negativo: una transferencia inválida se rechaza — *demo.e4.almacen*
62. rol comprador: panel y menú — *demo.e4.comprador*
63. rol comprador: solicita una orden de compra — *demo.e4.comprador*
64. permiso negativo: el comprador no puede autorizar su orden de compra — *demo.e4.comprador*
65. órdenes de compra: el propietario autoriza la orden del comprador — *demo.e4*
66. recepción parcial: el comprador recibe 4 de 6 y la orden conserva 2 pendientes — *demo.e4.comprador*
67. recepción en la ubicación elegida por el comprador — *demo.e4.comprador*
68. permiso negativo: el comprador no ajusta ni transfiere existencias — *demo.e4.comprador*
69. consulta de auditoría — *demo.e4*
70. permiso negativo: gerente y responsable de inventario no consultan la auditoría — *demo.e4.gerente*

### Lo que hoy no se puede demostrar

- **integraciones firmadas con Tienda, Finanzas y Recompensas** (bloqueado): No se pueden ejercitar con el negocio de Inventarios de la demostración: la confirmación de pagos (Finanzas) está apagada en el laboratorio y las recompensas de demostración no llevan inventario. La reserva de existencias por pedido de Tienda SÍ se demuestra, en el recorrido de Tienda (E3), con el i

### Resultado

| Corrida | Pasos | Correctos | Fallas | Bloqueados |
|---|---:|---:|---:|---:|
| Instalación 50, primera pasada | 72 | 71 | 0 | 1 |
| Instalación 51, primera pasada | 72 | 71 | 0 | 1 |
| Instalación 50, tras el reinicio | 79 | 78 | 0 | 1 |
| Instalación 51, tras el reinicio | 79 | 78 | 0 | 1 |

## E5 — Servicios

Entrada: `/servicios-estudiante`.

### Cuentas

| Cuenta | Roles de Identidad | 2FA |
|---|---|---|
| `demo.e5` — Personal de Servicios | `admin@service:servicios-estudiante` | sí |
| `demo.e5.alumno` — Alumno | `estudiante` | no |
| `demo.e5.alumno2` — Alumno en espera | `estudiante` | no |

### Recorrido

1. portada del módulo y reconocimiento de personal — *demo.e5.alumno*
2. portada del módulo y reconocimiento de personal — *demo.e5*
3. alta de libro — *demo.e5*
4. alta de segundo libro (para reserva cancelada y baja) — *demo.e5*
5. alta de ejemplar — *demo.e5*
6. alta de ejemplar (segundo, para préstamo directo) — *demo.e5*
7. alta de ejemplar (del libro de baja, para cambios de estado) — *demo.e5*
8. cambio de estado de ejemplar: a mantenimiento — *demo.e5*
9. cambio de estado de ejemplar: de vuelta a disponible — *demo.e5*
10. catálogo de biblioteca (lectura) — *demo.e5.alumno*
11. reservar un libro — *demo.e5.alumno*
12. reservar otro libro (para cancelarlo) — *demo.e5.alumno*
13. cancelar una reserva de libro — *demo.e5.alumno*
14. atender reserva: asignar ejemplar — *demo.e5*
15. atender reserva: entregar y generar el préstamo — *demo.e5*
16. renovar un préstamo — *demo.e5.alumno*
17. registrar la devolución de un préstamo — *demo.e5*
18. préstamo directo — *demo.e5*
19. multa: crear — *demo.e5*
20. regla 5.2: con multa pendiente el alumno no puede reservar — *demo.e5.alumno*
21. multa: condonar — *demo.e5*
22. multa: crear otra (para cancelarla) — *demo.e5*
23. multa: cancelar — *demo.e5*
24. multa: crear otra (para pagarla) — *demo.e5*
25. pago de multa de biblioteca por Finanzas (monedero del alumno) — *demo.e5.alumno*
26. validación 5.11 manual: devolución de préstamo de biblioteca — *demo.e5*
27. cambio de estado de ejemplar: extraviado — *demo.e5*
28. baja (desactivación) de libro — *demo.e5*
29. alta de locker — *demo.e5*
30. alta de periodo de lockers — *demo.e5*
31. periodo de lockers: la fecha de fin se muestra como se capturó — *demo.e5*
32. solicitar locker (queda pendiente de pago) — *demo.e5.alumno*
33. cancelar una solicitud de locker — *demo.e5.alumno*
34. solicitar locker y pagarlo por Finanzas (monedero del alumno) — *demo.e5.alumno*
35. la solicitud pagada recibe su locker (asignación automática; manual si no la hubo) — *demo.e5*
36. mi locker tras el pago (lectura del alumno) — *demo.e5.alumno*
37. liberar el locker pagado — *demo.e5*
38. asignación especial de locker — *demo.e5*
39. mi locker (lectura del alumno, sin controles de personal) — *demo.e5.alumno*
40. validación de acceso a locker — *demo.e5*
41. validación 5.11 manual: acceso a locker — *demo.e5*
42. validación 5.11 con credencial QR: acceso a locker (Identidad valida el código) — *demo.e5*
43. renovación de locker — *demo.e5*
44. liberación de locker — *demo.e5*
45. validación de acceso a locker ya liberado (se deniega) — *demo.e5*
46. enviar locker a mantenimiento — *demo.e5*
47. cerrar periodo de lockers — *demo.e5*
48. reservar instalación — *demo.e5.alumno*
49. validación 5.11 manual: check-in de instalación fuera de su ventana (se deniega y queda en bitácora) — *demo.e5*
50. cancelar reserva de instalación — *demo.e5.alumno*
51. reservar y cancelar un laboratorio — *demo.e5.alumno*
52. reservar y cancelar un auditorio — *demo.e5.alumno*
53. reservar y cancelar una cancha — *demo.e5.alumno*
54. reservar zona de descanso — *demo.e5.alumno*
55. lista de espera: el segundo alumno pide el mismo horario — *demo.e5.alumno2*
56. lista de espera vista por el personal (5.10) y promoción manual sin cupo (se rechaza) — *demo.e5*
57. permiso negativo: el alumno no puede cancelar la reservación de otro alumno (404) — *demo.e5.alumno*
58. cancelar reservación de zona de descanso (libera el cupo y promueve la lista de espera) — *demo.e5.alumno*
59. lista de espera: el segundo alumno queda confirmado sin intervenir — *demo.e5.alumno2*
60. cancelar reservación de zona de descanso (segundo alumno) — *demo.e5.alumno2*
61. reservar y cancelar un sillón de descanso — *demo.e5.alumno*
62. alta de zona de descanso — *demo.e5*
63. calendarios 5.10: edición de reglas (horario, días, tolerancia y cancelación) — *demo.e5*
64. calendarios 5.10: bloqueo de horario — *demo.e5*
65. el bloqueo impide al alumno reservar ese horario — *demo.e5.alumno*
66. calendarios 5.10: eliminar bloqueo — *demo.e5*
67. reservar zona de descanso para hoy (franja en curso, con las reglas editadas) — *demo.e5.alumno*
68. validación 5.11 manual: check-in de la reservación — *demo.e5*
69. validación 5.11 manual: check-out de la reservación — *demo.e5*
70. calendarios 5.10: una reservación sin llegada queda como inasistencia — *demo.e5.alumno2*
71. enviar zona de descanso a mantenimiento — *demo.e5*
72. rentar el equipo sin depósito — *demo.e5.alumno*
73. cancelar una renta — *demo.e5.alumno*
74. rentar de nuevo el equipo sin depósito (para la entrega y devolución en mostrador) — *demo.e5.alumno*
75. validación 5.11 manual: entrega del equipo rentado — *demo.e5*
76. permiso negativo: el alumno no puede cancelar ni devolver por sí mismo un equipo ya entregado — *demo.e5.alumno*
77. validación 5.11 manual: devolución de la renta — *demo.e5*
78. validación 5.11: las fechas y horas de sus mensajes corresponden a la hora del campus — *demo.e5*
79. solicitar una impresión (con archivo PDF) — *demo.e5.alumno*
80. pago de impresión por Finanzas (monedero del alumno) — *demo.e5.alumno*
81. «Usar beca» en una segunda orden de impresión — *demo.e5.alumno*
82. solicitar otro servicio (copias) y cancelar la solicitud — *demo.e5.alumno*
83. marcar la orden pagada como «lista» — *demo.e5*
84. entregar la orden lista («Recoger») — *demo.e5*
85. permiso negativo: el alumno no tiene «Marcar lista» ni «Recoger» — *demo.e5.alumno*
86. abrir ticket de soporte — *demo.e5.alumno*
87. abrir un ticket con evidencia adjunta — *demo.e5.alumno*
88. comentar un ticket propio — *demo.e5.alumno*
89. abrir otro ticket (para cancelarlo) — *demo.e5.alumno*
90. cancelar un ticket — *demo.e5.alumno*
91. abrir ticket de soporte (segundo alumno) — *demo.e5.alumno2*
92. el personal ve los tickets de todos y comenta uno — *demo.e5*
93. el alumno ve la respuesta del personal en su ticket — *demo.e5.alumno*
94. permiso negativo: el alumno no ve ni puede tocar el ticket de otro alumno (404) — *demo.e5.alumno*
95. atender un ticket: tomarlo, iniciar la atención, resolverlo y cerrarlo — *demo.e5*
96. permiso negativo: el alumno no puede atender su propio ticket — *demo.e5.alumno*
97. cancelar un ticket (segundo alumno) — *demo.e5.alumno2*
98. reporte de uso: periodo de ayer y hoy, todos los servicios — *demo.e5*
99. reporte de uso: filtro por servicio (tickets de soporte) — *demo.e5*
100. reporte de uso: filtro por fechas (periodo anterior, sin la actividad de la corrida) — *demo.e5*
101. reporte de uso: exportación CSV — *demo.e5*
102. permiso negativo: página de personal «periodos de lockers» (403) — *demo.e5.alumno*
103. permiso negativo: página de personal «validación de acceso a lockers» (403) — *demo.e5.alumno*
104. permiso negativo: página de personal «ejemplares de biblioteca» (403) — *demo.e5.alumno*
105. permiso negativo: página de personal «validación de servicios 5.11» (403) — *demo.e5.alumno*
106. permiso negativo: página de personal «reporte de uso» (403) — *demo.e5.alumno*
107. permiso negativo: página de personal «CSV del reporte de uso» (403) — *demo.e5.alumno*
108. permiso negativo: operaciones de personal por petición directa — biblioteca y multas (403) — *demo.e5.alumno*
109. permiso negativo: operaciones de personal por petición directa — lockers (403) — *demo.e5.alumno*
110. permiso negativo: operaciones de personal por petición directa — calendarios, zonas de descanso y validación 5.11 (403) — *demo.e5.alumno*
111. permiso negativo: operaciones de personal por petición directa — soporte, impresiones y rentas (403) — *demo.e5.alumno*
112. permiso negativo: controles de personal ausentes en las pantallas del alumno — *demo.e5.alumno*
113. avisos a Comunidad (E6): aviso de ticket actualizado — *demo.e5.alumno*
114. auditoría de Servicios publicada hacia Recompensas (E7) — *demo.e5 → demo.e7.auditor*
115. analítica de Servicios (eventos de plataforma) hacia Recompensas (E7) — *demo.e5 → demo.e7.auditor*
116. avisos de Servicios entregados a Comunidad (E6): todos los tipos de la corrida — *demo.e5.alumno*

### Lo que hoy no se puede demostrar

- **renta con garantía (depósito)** (bloqueado): rentar «Kit Arduino Uno» (depósito $100) responde 302 con «La retención está pendiente de la integración con Finanzas.»; no se crea renta (4→4) y el equipo sigue available: la retención de la garantía depende de Finanzas (piloto sólo en entorno de pruebas)

### Resultado

| Corrida | Pasos | Correctos | Fallas | Bloqueados |
|---|---:|---:|---:|---:|
| Instalación 50, primera pasada | 118 | 117 | 0 | 1 |
| Instalación 51, primera pasada | 118 | 117 | 0 | 1 |
| Instalación 50, tras el reinicio | 126 | 125 | 0 | 1 |
| Instalación 51, tras el reinicio | 126 | 125 | 0 | 1 |

## E6 — Comunidad

Entrada: `/modulo6`.

### Cuentas

| Cuenta | Roles de Identidad | 2FA |
|---|---|---|
| `demo.e6` — Presidencia | `estudiante` | no |
| `demo.e6.integrante` — Integrante | `estudiante` | no |
| `demo.e6.revisor` — Comité de becas | `estudiante` | no |
| `demo.e6.staff` — Personal de acceso | `estudiante` | no |
| `demo.e6.gestor` — Gestión de organizaciones | `estudiante` | no |

### Recorrido

1. organización: editar perfil — *demo.e6*
2. organización: registrar periodo — *demo.e6*
3. organización: asignar cargo «Comunicación» a demo.e6.staff — *demo.e6*
4. organización: asignar cargo «Responsable de becas» a demo.e6.staff — *demo.e6*
5. permiso positivo: los cargos habilitan campañas y revisión de becas — *demo.e6.staff*
6. organización: delegación temporal de «becas.revisar» a demo.e6.revisor — *demo.e6*
7. permiso positivo: la delegación habilita la revisión de becas — *demo.e6.revisor*
8. organización: delegación temporal de «comunicacion.gestionar» a demo.e6.integrante — *demo.e6*
9. permiso positivo: la delegación habilita las campañas — *demo.e6.integrante*
10. organización: revocar la delegación de comunicación — *demo.e6*
11. permiso negativo: la delegación revocada deja de habilitar campañas — *demo.e6.integrante*
12. organización: añadir documento (PDF) compartido con integrantes — *demo.e6*
13. permiso positivo: el integrante consulta y descarga el documento — *demo.e6.integrante*
14. eventos: crear y publicar evento gratuito con cupo 1 y lista de espera — *demo.e6*
15. eventos: inscripción confirmada del integrante — *demo.e6.integrante*
16. eventos: boleto con QR y código de acceso — *demo.e6.integrante*
17. eventos: lista de espera con el cupo agotado — *demo.e6.revisor*
18. eventos: asignar personal de acceso — *demo.e6*
19. eventos: el personal de acceso valida el código del boleto — *demo.e6.staff*
20. eventos: un boleto ya utilizado no vuelve a dar acceso — *demo.e6.staff*
21. eventos: inscritos y asistencia — *demo.e6*
22. eventos: crear y publicar el evento de la cancelación de inscripción — *demo.e6*
23. eventos: inscripción del integrante en el segundo evento — *demo.e6.integrante*
24. eventos: demo.e6.staff entra a la lista de espera del segundo evento — *demo.e6.staff*
25. eventos: cancelar la inscripción desde el boleto — *demo.e6.integrante*
26. eventos: el lugar liberado pasa a quien esperaba — *demo.e6.staff*
27. eventos: crear y publicar un evento con costo — *demo.e6*
28. eventos: reserva de 15 minutos en un evento con costo — *demo.e6.staff*
29. eventos: cobro de un evento de pago con wallet — *demo.e6.staff*
30. avisos de Finanzas en la bandeja: el pago del boleto llega como mensaje a quien pagó — *demo.e6.staff*
31. regla: un evento con pagos confirmados no se cancela sin coordinar antes la devolución — *demo.e6*
32. eventos: crear y publicar el evento que se cancelará — *demo.e6*
33. eventos: reserva sin pagar en el evento que se cancelará — *demo.e6.integrante*
34. eventos: la presidencia cancela un evento publicado — *demo.e6*
35. eventos: el boleto de un evento cancelado deja de dar acceso — *demo.e6.integrante*
36. becas: crear convocatoria de impresiones (no monetaria) — *demo.e6*
37. becas: publicar la convocatoria con la recepción abierta hoy — *demo.e6*
38. becas: iniciar solicitud, guardar borrador y adjuntar documento — *demo.e6.integrante*
39. becas: enviar la solicitud — *demo.e6.integrante*
40. becas: configurar el comité (responsable y revisor) — *demo.e6*
41. becas: cerrar la recepción — *demo.e6*
42. becas: el revisor del comité guarda su recomendación — *demo.e6.revisor*
43. permiso negativo: el revisor no puede dictaminar — *demo.e6.revisor*
44. becas: el responsable dictamina y aprueba — *demo.e6*
45. becas: el solicitante ve el dictamen — *demo.e6.integrante*
46. becas: entrega del beneficio al módulo de Servicios — *demo.e6.integrante*
47. becas: finalizar dictámenes — *demo.e6*
48. beca de locker: convocatoria de un locker por persona, publicada — *demo.e6*
49. beca de locker: solicitud enviada con su documento — *demo.e6.integrante*
50. beca de locker: comité, cierre de recepción, recomendación y dictamen aprobado — *demo.e6*
51. beca de locker: Servicios asigna un locker y Comunidad recibe su recibo — *demo.e6.integrante*
52. beca de locker: la presidencia revoca el apoyo y Servicios libera el locker — *demo.e6*
53. beca de locker: finalizar dictámenes (cierre) — *demo.e6*
54. comunicación: crear campaña para los integrantes — *demo.e6.staff*
55. comunicación: vista previa, confirmar envío y entrega por el trabajador de cola — *demo.e6.staff*
56. comunicación: campaña para los inscritos confirmados de un evento — *demo.e6.staff*
57. comunicación: campaña para quienes tienen una solicitud de beca aprobada — *demo.e6.staff*
58. bandeja: el integrante recibe la campaña y al abrirla queda leída — *demo.e6.integrante*
59. bandeja: marcar el mensaje como importante — *demo.e6.integrante*
60. bandeja: archivar el mensaje — *demo.e6.integrante*
61. bandeja: preferencias, silenciar las campañas de la organización — *demo.e6.integrante*
62. bandeja: preferencias, volver a recibir las campañas — *demo.e6.integrante*
63. encuestas: crear y publicar la encuesta con padrón fijo — *demo.e6*
64. encuestas: participar en la encuesta — *demo.e6.integrante*
65. encuestas: participar en la encuesta — *demo.e6.revisor*
66. encuestas: cierre anticipado y resultados agregados — *demo.e6*
67. encuestas: el integrante consulta los resultados — *demo.e6.integrante*
68. votaciones: crear y publicar la votación con padrón fijo — *demo.e6*
69. votaciones: participar en la votación — *demo.e6.integrante*
70. votaciones: participar en la votación — *demo.e6.revisor*
71. votaciones: cierre anticipado y resultados agregados — *demo.e6*
72. votaciones: el integrante consulta los resultados — *demo.e6.integrante*
73. transparencia: generar el borrador del reporte — *demo.e6*
74. transparencia: publicar el reporte — *demo.e6*
75. transparencia: descargar el CSV y compararlo con la base — *demo.e6*
76. transparencia: el integrante consulta el reporte publicado — *demo.e6.integrante*
77. transparencia: última votación cerrada — *demo.e6.integrante*
78. transparencia: retirar la publicación — *demo.e6*
79. permiso negativo: el reporte retirado deja de estar disponible para el integrante — *demo.e6.integrante*
80. transparencia: el reporte del periodo queda publicado y vigente — *demo.e6*
81. dashboard: cifras de la organización — *demo.e6*
82. dashboard: «mi condición académica» — *demo.e6*
83. dashboard: cifras de la organización — *demo.e6.integrante*
84. dashboard: «mi condición académica» — *demo.e6.integrante*
85. gestión de organizaciones: alta con titular por matrícula — *demo.e6.gestor*
86. gestión de organizaciones: suspender — *demo.e6.gestor*
87. gestión de organizaciones: reactivar — *demo.e6.gestor*
88. gestión de organizaciones: ficha e historial — *demo.e6.gestor*
89. organización: el titular cambia de organización activa con el selector y vuelve — *demo.e6.revisor*
90. consejo: la presidencia abre el Consejo y ve su estructura (mesa directiva, integrantes y comisiones) — *demo.e6.integrante*
91. consejo: añadir un integrante por matrícula — *demo.e6.integrante*
92. consejo: asignar el cargo «Tesorería» con vigencia a demo.e6.staff — *demo.e6.integrante*
93. consejo: registrar el periodo de gestión del Consejo — *demo.e6.integrante*
94. consejo: crear una comisión con propósito y periodo de trabajo — *demo.e6.integrante*
95. consejo: integrar la comisión con la coordinación de un integrante — *demo.e6.integrante*
96. permiso negativo: la tesorería y la coordinación de una comisión no administran el Consejo — *demo.e6.staff*
97. permiso negativo: la presidencia de la asociación no alcanza al Consejo ni tiene comisiones — *demo.e6*
98. consejo: retirar la participación con motivo conserva el historial — *demo.e6.integrante*
99. consejo: cerrar la comisión conserva su historial — *demo.e6.integrante*
100. consejo: dar de baja al integrante retira también su cargo de tesorería (cierre) — *demo.e6.integrante*
101. consejo: cancelar el periodo registrado y volver a la asociación (cierre) — *demo.e6.integrante*
102. permiso negativo: el integrante no puede crear eventos — *demo.e6.integrante*
103. permiso negativo: el integrante no puede crear campañas — *demo.e6.integrante*
104. permiso negativo: el integrante no puede crear convocatorias de becas — *demo.e6.integrante*
105. permiso negativo: el integrante no puede crear encuestas — *demo.e6.integrante*
106. permiso negativo: el integrante no puede crear votaciones — *demo.e6.integrante*
107. permiso negativo: el integrante no puede generar reportes de transparencia — *demo.e6.integrante*
108. permiso negativo: el integrante no puede delegar ni editar la organización — *demo.e6.integrante*
109. permiso negativo: la presidencia no entra a la gestión de organizaciones — *demo.e6*
110. permiso negativo: el gestor sin membresía no entra al control de acceso — *demo.e6.gestor*
111. organización: revocar la delegación de revisión de becas (cierre) — *demo.e6*
112. organización: retirar el cargo «Comunicación» (cierre) — *demo.e6*
113. organización: retirar el cargo «Responsable de becas» (cierre) — *demo.e6*
114. permiso negativo: sin cargo ni delegación ya no hay campañas ni revisión — *demo.e6.staff*
115. organización: cancelar el periodo registrado (cierre) — *demo.e6*

### Lo que hoy no se puede demostrar

- **eventos: devolución del pago de un boleto y su conciliación** (bloqueado): Depende de Finanzas (E2): la devolución de un cobro confirmado pasa por la revisión de devoluciones de Finanzas, y ninguna cuenta tiene hoy esa autoridad (documento de pendientes). Comunidad sólo concilia la devolución cuando Finanzas la reporta (orden planificada comunidad:conciliar-pagos-eventos);
- **eventos: validación de acceso con credencial NFC** (bloqueado): Función apagada en esta instalación. Observado en /modulo6/staff: opción «Credencial NFC» deshabilitada con el texto «El acceso con credencial NFC aún no está disponible. Puedes seguir usando el boleto.»; POST /api/v1/comunidad/portal/eventos/checkin con método nfc → HTTP 503 «No fue posible complet
- **organización: recuperar la presidencia** (bloqueado): Sólo existe como orden de consola (comunidad:recuperar-presidencia); no hay pantalla ni ruta. Observado: la tarjeta de Presidencia sólo ofrece «Editar cargo» (transferencia, no ejecutada para no cambiar la presidencia de la demo) y «Retirar»; POST /api/v1/comunidad/portal/organizaciones/recuperar-pr

### Resultado

| Corrida | Pasos | Correctos | Fallas | Bloqueados |
|---|---:|---:|---:|---:|
| Instalación 50, primera pasada | 125 | 122 | 0 | 3 |
| Instalación 51, primera pasada | 125 | 122 | 0 | 3 |
| Instalación 50, tras el reinicio | 135 | 132 | 0 | 3 |
| Instalación 51, tras el reinicio | 135 | 132 | 0 | 3 |

## E7 — Recompensas

Entrada: `/recompensas`.

### Cuentas

| Cuenta | Roles de Identidad | 2FA |
|---|---|---|
| `demo.e7` — Administración de Recompensas | `admin@service:recompensas` | sí |
| `demo.e7.plataforma` — Aprobación de plataforma | `admin@service:plataforma` | sí |
| `demo.e7.auditor` — Auditoría | `admin@service:auditoria` | sí |
| `demo.e7.profesor` — Profesor | `maestro` | sí |
| `demo.e7.alumno` — Alumno | `estudiante` | no |

### Recorrido

1. acceso al módulo y papel asignado — *demo.e7.alumno*
2. acceso al módulo y papel asignado — *demo.e7*
3. acceso al módulo y papel asignado — *demo.e7.profesor*
4. acceso al módulo y papel asignado — *demo.e7.plataforma*
5. acceso al módulo y papel asignado — *demo.e7.auditor*
6. catálogo: alta de la recompensa de la corrida con existencias — *demo.e7*
7. catálogo: baja de otra recompensa de la corrida — *demo.e7*
8. catálogo del alumno: la recompensa de la corrida aparece y el botón depende del saldo — *demo.e7.alumno*
9. flujo de puntos: el admin acredita puntos al alumno con «Ajuste manual de puntos» — *demo.e7*
10. flujo de puntos: el alumno ve su saldo y su actividad tras el abono — *demo.e7.alumno*
11. flujo de puntos: canje 1 de la recompensa de la corrida (lo valida y entrega el profesor) — *demo.e7.alumno*
12. flujo de puntos: «Mis canjes» muestra el canje con su código — *demo.e7.alumno*
13. permiso negativo: el alumno no valida ni entrega su propio canje — *demo.e7.alumno*
14. tablero del profesor: validaciones pendientes y canjes por validar — *demo.e7.profesor*
15. flujo de puntos: el profesor valida el código del canje — *demo.e7.profesor*
16. flujo de puntos: el profesor entrega desde la ficha del código validado («✓ Entregar recompensa») — *demo.e7.profesor*
17. flujo de puntos: un canje ya entregado no se puede entregar otra vez — *demo.e7.profesor*
18. flujo de puntos: canje 2 de la recompensa de la corrida (lo entrega el admin) — *demo.e7.alumno*
19. canjes (admin): listado y filtro por código — *demo.e7*
20. flujo de puntos: el admin entrega el segundo canje desde «Canjes» — *demo.e7*
21. flujo de puntos: canje 3 de la recompensa de la corrida (lo cancela el alumno) — *demo.e7.alumno*
22. flujo de puntos: el alumno cancela el tercer canje y recupera los puntos — *demo.e7.alumno*
23. flujo de puntos: canje 4 de la recompensa de la corrida (lo cancela el admin) — *demo.e7.alumno*
24. flujo de puntos: el admin cancela un canje desde «Canjes» y se reembolsan los puntos — *demo.e7*
25. flujo de puntos: ajuste manual negativo (débito) que devuelve al alumno a su saldo inicial — *demo.e7*
26. flujo de puntos: saldo y existencias finales en pantalla — *demo.e7**
27. alumno: filtros «Ganancias» y «Canjes» de «Mi actividad» — *demo.e7.alumno*
28. ledger: listado global de movimientos — *demo.e7*
29. ledger: filtro por tipo y búsqueda por texto — *demo.e7*
30. ledger: una búsqueda sin coincidencias deja la tabla vacía — *demo.e7*
31. ledger: «Conciliar» recalcula la cuenta del alumno desde el ledger — *demo.e7*
32. ledger: el aviso de «Conciliar» informa el saldo auditado — *demo.e7*
33. auditoría: los registros de las acciones de la corrida aparecen — *demo.e7*
34. auditoría: el buscador filtra los registros — *demo.e7*
35. auditoría: historial del profesor (sólo sus propias acciones) — *demo.e7.profesor*
36. negocios: listado de negocios adheridos — *demo.e7*
37. negocios: «Suspender» el negocio sembrado — *demo.e7*
38. negocios: «Activar» el negocio sembrado — *demo.e7*
39. negocios: alta por el formulario «Adherir negocio» — *demo.e7*
40. reglas: listado de reglas de acumulación — *demo.e7*
41. reglas: «Desactivar regla» sobre la regla sembrada — *demo.e7*
42. reglas: «Activar regla» sobre la regla sembrada — *demo.e7*
43. reglas: alta por el formulario «Nueva regla» — *demo.e7*
44. reglas: edición por el formulario — *demo.e7*
45. campañas: vista del alumno — *demo.e7.alumno*
46. campañas: listado del profesor (activas y propias) — *demo.e7.profesor*
47. campañas: propuesta del profesor («Proponer campaña») — *demo.e7.profesor*
48. gobierno: indicadores de políticas, alertas y conciliación — *demo.e7*
49. acumulación de puntos por compra: regla del negocio, campaña y tope — *demo.e7*
50. acumulación de puntos: el mismo folio no acumula dos veces — *demo.e7*
51. gobierno: alta de política — *demo.e7*
52. gobierno: la política activa limita los canjes del alumno por día — *demo.e7.alumno*
53. gobierno: edición y desactivación de la política — *demo.e7*
54. reverso de una acumulación cuyos puntos ya se usaron: saldo pendiente y alerta — *demo.e7*
55. gobierno: dictamen de una alerta antifraude — *demo.e7*
56. saldo pendiente: los puntos devueltos saldan primero la deuda — *demo.e7*
57. administración: catálogos del programa (lectura) — *demo.e7*
58. administración: alta de catálogos — *demo.e7*
59. administración: un código de catálogo repetido se rechaza — *demo.e7*
60. configuración: quien redacta una versión la envía a aprobación pero no puede aprobarla — *demo.e7*
61. configuración: otra persona con autoridad aprueba y activa la versión — *demo.e7.plataforma*
62. permiso negativo: nadie aprueba la versión que redactó él mismo — *demo.e7.plataforma*
63. edición de recompensas y negocios — *demo.e7*
64. campañas (admin): aprobación de la propuesta del profesor, alta y desactivación — *demo.e7*
65. exportaciones: CSV del ledger y de la auditoría con el filtro de pantalla — *demo.e7**
66. auditoría: detalle de un registro con sus valores de antes y después y el dispositivo — *demo.e7*
67. auditoría: traza de una operación por su correlación — *demo.e7*
68. permiso negativo: el auditor consulta todo y no puede modificar nada — *demo.e7.auditor*
69. permiso negativo: el cargo de Recompensas no administra Identidad — *demo.e7*
70. integración con Tienda: el alumno compra con su monedero en una tienda adherida — *demo.e7.alumno*
71. integración con Tienda: la venta confirmada acumula puntos por el evento firmado de Tienda — *demo.e7**
72. integración y observabilidad: un evento en falla se reintenta tras corregir la causa — *demo.e7*
73. integración y observabilidad: salud y eventos recibidos por módulo — *demo.e7*
74. tablero de administración: indicadores — *demo.e7*
75. tablero de administración: últimos eventos de auditoría y gráfica — *demo.e7*
76. analítica global: indicadores y recompensas más canjeadas — *demo.e7*
77. analítica global: consulta de agregados de Comunidad — *demo.e7*
78. analítica global: indicadores de becas de los reportes vigentes de Comunidad — *demo.e7*
79. analítica global: indicadores de mensajes de los reportes vigentes de Comunidad — *demo.e7*
80. analítica docente (profesor) — *demo.e7.profesor*
81. catálogo: el límite de canjes por alumno rechaza el canje que lo supera — *demo.e7.alumno*
82. catálogo: limpieza del límite por alumno (canje cancelado y recompensa eliminada) — *demo.e7**
83. permiso negativo: el alumno no entra a /recompensas/admin/* — *demo.e7.alumno*
84. permiso negativo: el alumno no entra a /recompensas/profesor/* — *demo.e7.alumno*
85. permiso negativo: el alumno no puede crear recompensas — *demo.e7.alumno*
86. permiso negativo: el alumno no puede acreditarse puntos — *demo.e7.alumno*
87. permiso negativo: el admin no puede canjear (ni a nombre del alumno) — *demo.e7*
88. permiso negativo: el profesor no puede crear recompensas — *demo.e7.profesor*
89. permiso negativo: el profesor no puede ajustar puntos — *demo.e7.profesor*

### Lo que hoy no se puede demostrar

Todos los pasos del recorrido pasan.

### Resultado

| Corrida | Pasos | Correctos | Fallas | Bloqueados |
|---|---:|---:|---:|---:|
| Instalación 50, primera pasada | 90 | 90 | 0 | 0 |
| Instalación 51, primera pasada | 90 | 90 | 0 | 0 |
| Instalación 50, tras el reinicio | 93 | 93 | 0 | 0 |
| Instalación 51, tras el reinicio | 93 | 93 | 0 | 0 |

