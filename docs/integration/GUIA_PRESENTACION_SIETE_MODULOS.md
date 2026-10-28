# Guía para mostrar la integración

Alterno: http://127.0.0.1:8086. Fecha: 27 de octubre de 2026.

1. Entrar con una cuenta existente de Campus Digital. No hay login separado para E2 o E7.
2. Mostrar el menú con los siete módulos: Perfil, Cartera, Tienda, Inventarios,
   Servicios, Comunidad y Recompensas. La cuenta conserva sus permisos de E1.
3. En Cartera, consultar cartera propia, bonos, comprobantes y compras por confirmar.
   Si no existe una cartera, se muestra ese estado. No se depositó saldo para aparentar pagos.
4. Mostrar el flujo ya conectado: pedido en Tienda → reserva real en Inventarios;
   condición académica E1 → servicios; beneficios Comunidad → Servicios;
   eventos y Staff QR/NFC → validación de identidad.
5. En Recompensas, mostrar puntos propios, campañas, catálogo, canjes y actividad.
   Los valores vienen de la API y su MongoDB separado. Una base recién integrada puede estar vacía.
6. Para administrar E7, usar el admin global vigente de E1 y completar el segundo factor
   que E1 ya exige. Un administrador limitado a un negocio no abre este panel global.
   No se crearon ni se promovieron usuarios para saltarse ese requisito.
7. Explicar el estado financiero: E2 ya está instalado sobre SQL Server; los acuerdos de
   fondos, autoridad, receptores y checkout aún no permiten acreditar un pago entre módulos.
8. La recepción y entrega de auditorías Comunidad → E7 están probadas
   y ya se activaron con autorización explícita: hay 71 auditorías entregadas, sin duplicados.
   Aceptar una auditoría no asigna puntos ni confirma dinero.

El estado de integración es demostrable por pantallas, pruebas y bases separadas.
No equivale a haber terminado todos los requisitos funcionales de cada equipo.
Rama de entrega en GitHub: `MERGE_COMPLETO`, por solicitud del usuario.
Las credenciales y la activación local permanecen fuera de Git.
