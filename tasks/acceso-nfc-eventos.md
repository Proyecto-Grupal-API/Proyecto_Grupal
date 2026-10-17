# Acceso NFC de Staff a eventos

Base e83a120, alterno /home/daniel/campus_digital_integracion_e1.

- [x] Contrato E1 revisado; cliente real Comunidad tiene solo students:read.
- [x] Resolución NFC por API, esquema validado y rechazo cerrado ante indisponibilidad.
- [x] Reglas compartidas de asistencia, revalidación tras HTTP y auditoría sin UID.
- [x] Vista de método de acceso, teclado/lector, QR compatible y disponibilidad.
- [x] RED/GREEN y prueba OAuth/HTTP real contra E1 solo en campus_virtual_testing.
- [x] Navegador aislado con NFC apagado, rechazo de boleto inválido, cuatro tamaños y limpieza.
- [x] Regresión final y suite completa; guía, revisión y commits locales.
- [ ] Autorización explícita del scope identity:nfc:validate para Comunidad y activación solo en el alterno.
- [ ] Tras aprobación, comprobar activación sin crear tarjetas, roles ni cobros.

No usar el cliente de E5 ni concederse scopes desde el código de E6. Mantener el
indicador de NFC apagado hasta autorización. No cambiar credenciales de usuarios,
MFA, pagos, contratos de E1, la copia principal ni publicar a GitHub. Un lector
físico queda pendiente de prueba con hardware; no se afirma certificación NFC.

## Preparación verificada

Código `ded236c`, backend `849c139`. 1202 pruebas y 8685
aserciones; sin fallos, errores u omisiones. Inventario 91 archivos/16 bloques,
1310.86 segundos acumulados; cotejo independiente completo.
Regresión 61/583, compilación 10.31s y navegador sin errores inesperados.
Cliente real Comunidad continúa con students:read; no se amplió OAuth.
La activación preparada está documentada en docs/integration/ACCESO_NFC_EVENTOS_COMUNIDAD.md.
