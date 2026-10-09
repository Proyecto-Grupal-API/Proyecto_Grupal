# Corrección de autoasignación de roles

Base: 57d6817, 284 pruebas financieras aprobadas. Corrección del endpoint web /roles/assign que aceptaba roles arbitrarios del propio usuario sin comprobar autorización administrativa.

## Cambio

POST /roles/assign conserva su ruta y middleware de autenticación, pero rechaza cualquier asignación con 403 antes de validar o persistir el cuerpo. No se confía en un rol admin existente, ya que pudo provenir del mismo simulador de autoasignación.

GET /roles permanece disponible para consultar los roles propios. La pantalla conserva los controles de 2FA y su estilo, pero elimina el formulario de autoasignación y el catálogo de roles ofrecido como opciones. La consulta no otorga permisos.

No se altera User.assignRole, usado como operación interna de identidad, ni se borran roles existentes. La búsqueda del código instalado encontró que el controlador bloqueado era su único consumidor en app. La administración real de roles deberá conectarse al flujo autorizado del Módulo 1. No se crea un nuevo superadministrador, lista de emails privilegiados ni bypass local.

Los roles previamente autoasignables no constituyen evidencia confiable para autorizar privilegios financieros. Antes de habilitar administración humana se deberá validar su procedencia usando el contrato de identidad/RoleAssignment; el adaptador financiero pendiente continúa denegando ese acceso. Este parche cierra el endpoint vulnerable, no sustituye la integración completa de autorización de Módulo 1.

## Aplicación

Extraer ZIP y ejecutar Apply-Changes.ps1 -ProjectPath. El script verifica hashes y sintaxis PHP antes de copiar, respalda los archivos y aborta si el código difiere del corte analizado. No modifica .env ni requiere migraciones.

Ejecutar Verify-Changes.ps1 -ProjectPath. Corre cinco pruebas de seguridad de roles, toda la suite financiera y npm run build. Las pruebas usan las bases de testing ya configuradas en el proyecto, con el aislamiento OAuth vigente de tests/TestCase.php.

Se revisaron diferencias, imports y la integridad del ZIP aquí. PHP y dependencias frontend del proyecto no están disponibles en este entorno; lint, pruebas y compilación se ejecutan en el equipo del usuario. No se afirma que ya hayan pasado.
