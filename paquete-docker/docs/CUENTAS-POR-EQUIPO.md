# Cuentas de demostración por equipo

Las crea el instalador en cada computadora. **Usuario:** el nombre de la cuenta más `@campus.test` (por ejemplo `demo.e3.cajero@campus.test`). **Contraseña:** la misma para las 31 cuentas de tu instalación; la ve sólo quien instaló, con `./campus.sh acceso`. Cada computadora tiene la suya.

Para la corrida de un equipo, usa sus cuentas, empezando por la principal (la que no lleva sufijo). Las marcadas con **2FA** piden configurar el segundo factor la primera vez (código QR para una aplicación de autenticación en el teléfono).

| Equipo | Cuenta | Para qué se usa | 2FA |
|---|---|---|---|
| **E1 Identidad** | `demo.e1` | Administración de Identidad (administrador global) | sí |
| | `demo.e1.alumno` | Alumno: perfil, QR, tarjetas | no |
| **E2 Finanzas** | `demo.e2` | Operador de caja de la asociación | no |
| | `demo.e2.presidencia` | Presidencia que delega la caja | no |
| | `demo.e2.supervisor` | Supervisión de caja y segunda autorización | no |
| | `demo.e2.alumno` | Titular del monedero: recargas, retiros, transferencias | no |
| | `demo.e2.alumno2` | Destinatario de transferencias | no |
| | `demo.e2.controles` | Administra límites, alertas y conciliación | sí |
| | `demo.e2.consulta` | Sólo consulta los controles financieros | sí |
| **E3 Tienda** | `demo.e3` | Dueño del negocio de demostración | no |
| | `demo.e3.revisor` | Revisión institucional de negocios | sí |
| | `demo.e3.cajero` | Cajero: atiende pedidos | no |
| | `demo.e3.comprador` | Comprador con monedero | no |
| **E4 Inventarios** | `demo.e4` | Propietario del negocio en Inventarios | no |
| | `demo.e4.gerente` | Gerente | no |
| | `demo.e4.almacen` | Responsable de almacén | no |
| | `demo.e4.comprador` | Compras y abastecimiento | no |
| | `demo.e4.alumno` | Alumno sin rol, para permisos negativos | no |
| **E5 Servicios** | `demo.e5` | Personal de Servicios | sí |
| | `demo.e5.alumno` | Alumno: reserva, paga y usa su apoyo de impresiones | no |
| | `demo.e5.alumno2` | Segundo alumno: lista de espera y registros ajenos | no |
| **E6 Comunidad** | `demo.e6` | Presidencia de la asociación | no |
| | `demo.e6.integrante` | Integrante de la asociación | no |
| | `demo.e6.revisor` | Comité de becas | no |
| | `demo.e6.staff` | Personal de acceso a eventos | no |
| | `demo.e6.gestor` | Gestión de organizaciones | no |
| **E7 Recompensas** | `demo.e7` | Administración del programa | sí |
| | `demo.e7.plataforma` | Segunda aprobación de configuraciones | sí |
| | `demo.e7.auditor` | Auditoría de sólo lectura | sí |
| | `demo.e7.profesor` | Profesor: valida canjes y propone campañas | sí |
| | `demo.e7.alumno` | Alumno: acumula, canjea y compra | no |

**Saldos de partida:** `demo.e3.comprador`, `demo.e5.alumno`, `demo.e6.integrante`, `demo.e6.staff` y `demo.e7.alumno` empiezan con $1,000.00 sintéticos en su monedero. Cada recorrido gasta parte; para muchas demostraciones seguidas, `./campus.sh borrar-todo` y `./campus.sh instalar` dejan todo como nuevo.

**Además** existen siete cuentas de una demostración anterior (por ejemplo `identidad@campus.test`, también administradora global). Usan la misma contraseña; ningún recorrido las necesita.
