# Revisión de archivos privados

Base fe354eb. Comando local de diagnóstico para E6; ningún borrado, reparación
o concesión de permisos.

- [x] Reproducir falta de revisión: referencias, ausencias, candidatos e historial.
- [x] Implementar consola humana/JSON con estado completo o incompleto.
- [x] Rechazar rutas inválidas, conservar versiones y evitar cambios de datos.
- [x] Probar y ejecutar revisión real de solo lectura en el alterno.
- [x] Registrar límites, evidencia y commit local.

Cierre: siete pruebas nuevas/48 aserciones y 34 de regresión/408
aserciones aprobadas. Cotejo de 41 casos únicos entre XML, preservando el
fallo de fixture inicial. Revisión real sin observaciones, hashes de datos
y contenido antes/después iguales. Guía:
docs/integration/REVISION_ARCHIVOS_PRIVADOS_COMUNIDAD.md.
