# Entrega 9 — Rediseño de la interfaz

Fecha: 22 de septiembre de 2026.

## Referencias y alcance

Se tomaron como referencia las tres capturas proporcionadas por el usuario: barra lateral azul oscuro, navegación superior por módulos en pastillas, fondo gris claro y tarjetas blancas con borde suave. El contenido corresponde a las vistas reales de Comunidad y a la cuenta autenticada.

El marco compartido usa una barra lateral de 228 px en escritorio, cabecera de 72 px, fondo `#f4f7fc`, azul lateral `#12285a`, selección lateral `#2869e8` y acentos `#2c489e`. La tipografía usa Segoe UI cuando está disponible, con alternativas del sistema.

## Pantallas y navegación

- Resumen, organizaciones, Gestión, eventos, Staff, boletos y su detalle, becas y expedientes, campañas, bandeja, encuestas, votaciones y transparencia comparten márgenes, controles y colores.
- Mi Perfil utiliza el mismo marco, con información real de usuario, edición de datos, contraseña y baja de cuenta. Las etiquetas se muestran en español y la explicación de baja refleja la conservación del historial.
- Inicio de sesión y el contenedor compartido de autenticación usan la misma identidad visual. Se mantienen formularios, validaciones y rutas existentes.
- Mi Perfil y Comunidad enlazan a sus pantallas reales. Cartera, Tienda, Mis Productos, Servicios y Recompensas se muestran sin enlaces operativos y se identifican como pendientes de integración. No se crean pantallas ficticias para esos dominios.
- El menú de cuenta permite abrir el perfil y cerrar sesión. Se conserva el centro de notificaciones y su conexión con la bandeja.
- En móvil, el menú lateral se abre sobre el contenido; el foco entra al menú, Tab permanece dentro y Escape lo cierra devolviendo el foco al botón. La barra superior admite desplazamiento horizontal y deja visible el módulo activo.

## Verificación

Compilación de producción con `npm run build` completada. `git diff --check` sin errores.

Recorrido de las vistas principales en Edge a 1590 × 760 y comprobaciones móviles a 390 × 844. Se verificaron navegación Inertia, selección de sección, apertura del formulario de organización y notificaciones, menú de cuenta, cierre de sesión y ausencia de desbordamiento horizontal de la página. Las tablas extensas conservan desplazamiento dentro de su contenedor.

La comprobación final usó cuentas de presidencia, estudiante, Gestión y Staff: cambio de organización del integrante, ausencia de controles administrativos del estudiante, apertura del alta sin guardar, consulta de Staff, detalle de boleto y expediente propio, perfil y acceso a seguridad. No se detectaron errores JavaScript no controlados. Se corrigió durante la revisión la transición de visibilidad que impedía enfocar el menú móvil.

Esta entrega modifica presentación y navegación; no cambia políticas, contratos, base de datos, cargos ni reglas de cobro. Las 146 pruebas de backend registradas en la entrega anterior no se presentan como una nueva ejecución: la validación de este rediseño fue compilación y recorrido de navegador.

## Capturas

- [Organizaciones en escritorio](capturas/rediseno-organizaciones.png)
- [Organizaciones en móvil](capturas/rediseno-movil.png)
- [Inicio de sesión](capturas/rediseno-login.png)

## Archivos principales

`resources/js/Layouts/Modulo6Layout.vue` concentra el marco y la navegación; `resources/css/campus.css` define el tema; `resources/js/Components/CampusBrand.vue` comparte la marca. Las vistas de Comunidad utilizan el contenedor `campus-page`. El perfil y `GuestLayout.vue` adaptan las pantallas de cuenta al mismo estilo.
