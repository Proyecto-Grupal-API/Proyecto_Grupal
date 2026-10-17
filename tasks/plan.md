# Comisiones y documentos de Comunidad

Alcance autorizado: apartados 6.1 y 6.2 del PDF, páginas 11–12. Trabajar en
`/home/daniel/campus_digital_integracion_e1`, conservando la aplicación original.

## Decisiones

- Comisiones solo para organizaciones de tipo Consejo; presidencia las administra,
  integrantes del Consejo las consultan. La coordinación es una responsabilidad
  organizacional; no concede capacidades, representación comercial ni autoridad financiera.
- Nombre, propósito y periodo; integrantes activos del Consejo, una coordinación
  como máximo; baja y cierre con motivo e historial. Una baja/reingreso no reactiva
  asignaciones anteriores. El periodo usa fechas de Ciudad de México, fin inclusivo.
- Documentos para asociaciones y Consejo: archivos privados PDF/JPG/PNG de hasta
  5 MB, metadatos y versiones inmutables. Visibilidad por defecto solo presidencia;
  si se comparte con integrantes, estos acceden únicamente a la versión actual.
  Versiones anteriores y documentos retirados quedan para revisión de presidencia.
- APIs de sesión actuales, CSRF y contexto de organización; consultas a colecciones
  propias de E6. Sin cambios de contratos de E1, OAuth, permisos institucionales o E2.
- Reutilizar bloqueo de responsabilidades, transacciones MongoDB, auditoría y
  componentes existentes. Idempotencia para altas/archivos, control de versión
  para ediciones y conservación de archivos al retirar.

## Orden

1. API de comisiones y pruebas de permisos, periodo, coordinación y bajas.
2. Interfaz de comisiones en Organizaciones, verificación y commit.
3. API documental con almacenamiento privado, historial y pruebas de privacidad/fallos.
4. Interfaz documental, comprobación en navegador y commit.
5. Regresión serial completa, compilación y documentación de la entrega.

Las tareas y criterios están en `tasks/todo.md`. Los porcentajes del informe/PDF
del 15 de octubre se conservan como evidencia histórica.

## Cierre del 16 de octubre de 2026

Este plan queda concluido en el entorno alterno. Comisiones se guardó en
`099806f` y el expediente documental en `907c0d0`. El inventario completo del
código de este último commit pasó en 14 bloques seriales: 1146 pruebas y
8189 aserciones, sin fallos ni omisiones. Compilación y flujos de navegador
verificados. El cierre posterior modifica únicamente esta documentación.

La guía funcional y los pendientes que siguen separados están en
`docs/integration/COMISIONES_Y_DOCUMENTOS_COMUNIDAD.md`.
