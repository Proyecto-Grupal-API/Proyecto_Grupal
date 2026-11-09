# Instalación y mantenimiento de E2

Base revisada: `9c1c91a`. Laravel y Vue comparten este proyecto; MongoDB contiene identidad y SQL Server contiene las entidades financieras actuales. Esta guía no instala ni modifica otros módulos. Docker se abordará en una entrega posterior: este repositorio no contiene todavía una configuración Docker de E2 validada.

## Proyecto existente

En tu instalación `C:\Proyectos\Campus-Digital` no repitas la creación de `.env`, `key:generate`, seeders ni migraciones para ejecutar el diagnóstico. No uses `composer setup` como verificación: el script existente genera clave y ejecuta migraciones, además de instalar dependencias y compilar. Cambiar una APP_KEY existente puede impedir descifrar información previamente guardada.

Ejecuta desde otra ventana de PowerShell:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tools\financial\Check-Environment.ps1 -ProjectPath "C:\Proyectos\Campus-Digital"
```

No requiere parar los servidores ni iniciar SQL Server/MongoDB: este diagnóstico no se conecta a ellos. Un resultado sin errores no acredita que estén disponibles.

## Requisitos de la base revisada

- PHP CLI: el lock completo, incluidas herramientas de desarrollo, requiere al menos 8.4.1 dentro de las ramas 8.4/8.5. La instalación usada por E2 es PHP 8.5.10. No basta con leer el mínimo `^8.3` de composer.json. Si cambia el lock, revisar otra vez.
- Extensiones: las de Laravel, DOM y mbstring para PDF, MongoDB y PDO_SQLSRV para las conexiones actuales. La herramienta comprueba carga, no todas las versiones ni todos los requisitos transitivos.
- Composer accesible en PATH y dependencias correspondientes a composer.lock.
- Node: engines del lock de Vite 8.2.2 admiten `^20.19.0 || >=22.12.0`. Utilizar una versión mantenida compatible; el diagnóstico comprueba el rango, no su ciclo de soporte.
- npm y dependencias de package-lock.json. En Windows usar `npm.cmd` si PowerShell bloquea npm.ps1.
- Para usar la aplicación, además hacen falta SQL Server, sus controladores ODBC/PDO y MongoDB con la configuración de réplica necesaria para las transacciones de identidad. No están certificados por el chequeo estático.

## Instalación nueva en un directorio separado

1. Obtener el código aprobado. Revisar primero el diagnóstico; en una copia nueva es normal que informe dependencias y `.env` ausentes.
2. Instalar con `composer install --no-scripts` y `npm.cmd ci --ignore-scripts` desde los locks. Estos comandos escriben dependencias y pueden descargar paquetes; no forman parte del diagnóstico. No ejecutar `composer update` para intentar reparar requisitos del entorno.
3. Preparar `.env` manualmente con la guía de configuración. Usar bases separadas de desarrollo y testing; no apuntar una instalación nueva a datos compartidos sin consentimiento.
4. Solo en una instalación realmente nueva, generar APP_KEY mediante el procedimiento Laravel aprobado. En una existente, conservar su clave. No copiar credenciales reales a documentación o Git.
5. Revisar los scripts Composer pendientes antes de habilitarlos. `post-autoload-dump` ejecuta descubrimiento de paquetes de Laravel. Si se autoriza, ejecutar `composer run-script post-autoload-dump` después de configurar el entorno.
6. Revisar migraciones por conexión antes de aplicarlas. El repositorio tiene migraciones históricas en la raíz y las actuales de SQL Server en `database/migrations/financial`. No ejecutar `migrate:fresh`, `refresh`, seeders generales ni todos los archivos por intuición. El diagnóstico no decide qué migraciones faltan. En bases existentes revisar historial y respaldo; cada cambio de esquema requiere el consentimiento correspondiente.
7. Compilar con `npm.cmd run build`. Este paso escribe `public/build`. Para desarrollo interactivo se puede usar Vite en su propia terminal.

## Arranque local

Desde el directorio del proyecto, en ventanas separadas:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

```powershell
npm.cmd run dev
```

El segundo comando es para desarrollo con Vite. No es necesario mantenerlo si se usa exclusivamente una compilación válida de producción. No iniciar workers, scheduler o entrega E4 como parte de este arranque básico.

Continuar con [configuración](configuracion.md) y [verificación](verificacion.md). Esta entrega agrega documentación y diagnóstico, no un instalador automático del sistema.
