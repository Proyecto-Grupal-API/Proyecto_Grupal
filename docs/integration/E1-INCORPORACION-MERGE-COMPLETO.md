# Entrega integral E1 sobre la rama del equipo

Base de incorporación: feat/modulo-1-equipo-1, 86111548a7659ffa3dab4fd6ee441bb70160b3d9. Selección E1 procedente del candidato integrado validado sobre MERGE_COMPLETO; no se incorpora ese árbol ni se realiza un merge. Propuesta preparada, sin commit ni publicación.

Incluye identidad/perfiles, autenticación/2FA, autorización contextual y separación de dominios, NFC/QR, sesiones, condición académica, consentimientos, OAuth, directorio D1–D4, consulta financiera y enriquecimiento de eventos de credenciales.

UserSession conserva su colección efectiva user_sessions de forma explícita. Una migración aditiva prepara índices no únicos sin mover documentos ni eliminar sessions. ProfileTest conserva la versión Pest publicada en la rama E1, que no invoca migraciones generales redundantes.

Evidencia: lote integrado de 210 pruebas y 1.037 aserciones, sin fallos, errores ni Risky; advertencias por la ausencia deliberada de .env en la vista privada. Índices comprobados sin cambio de documentos. Build frontend satisfactorio según la ejecución registrada. D1–D4 integrado: 31 pruebas y 172 aserciones satisfactorias. La tercera suite histórica de escalamiento conserva su salvedad documental de continuidad; no se fabrica una certificación canónica de esa ejecución.

No incluye perfiles privados de ejecución, credenciales, dependencias, builds ni evidencias del laboratorio. El soporte de seguridad dentro de tests sí se conserva porque las pruebas y fixtures E1 lo requieren. El modo integrado de testing es explícito, admite sólo los cuatro alias acreditados y nunca debe apuntar a datos de desarrollo. No ejecutar la suite grupal con migraciones generales sin revisión de su destino; el lote focal no certifica todas las suites E2–E7.

No sustituye User, StudentProfile, providers, bindings, conexiones ni consumidores existentes en la rama E1. No importa implementaciones E2–E7 ausentes. Los cambios compartidos se limitan a rutas/middleware, catálogos, emisores y soporte de pruebas necesarios para E1.

La configuración de directorio mantiene clients vacío y verificaciones operativas desactivadas: publicar código no habilita consumidores. Su activación exige clientes/scopes, atestación de matrícula y preparación acreditada del almacén de cuotas por despliegue. No crear presupuestos automáticamente.

La autoridad financiera no tiene binding productivo y su writer permanece restringido a testing. Los contextos no acreditados se cierran según contrato. No se materializan roles ni concesiones automáticas. Recompensas conserva sus decisiones institucionales pendientes, sin sustituir la autorización E7.

E2 conserva efectos financieros, fondos e idempotencia; E3/E4 sus negocios e inventarios; E5 sus servicios/elegibilidad; E6 membresías y cargos; E7 recepción, deduplicación y reglas de recompensas. El outbox y los eventos E1 no prueban recepción externa. La fuente Department, autoridad financiera, validación comercial contextual y acuerdos de transporte siguen pendientes de sus responsables. No anunciar D1–D4 consumible por E6 antes de publicación, configuración y verificación real.
