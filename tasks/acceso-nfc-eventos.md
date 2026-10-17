# Acceso NFC de Staff a eventos

Base e83a120, alterno /home/daniel/campus_digital_integracion_e1.

- [x] Contrato E1 revisado; cliente real Comunidad tiene solo students:read.
- [x] Resolución NFC por API, esquema validado y rechazo cerrado ante indisponibilidad.
- [x] Reglas compartidas de asistencia, revalidación tras HTTP y auditoría sin UID.
- [x] Vista de método de acceso, teclado/lector, QR compatible y disponibilidad.
- [x] RED/GREEN y prueba OAuth/HTTP real contra E1 solo en campus_virtual_testing.
- [x] Navegador aislado con NFC apagado, rechazo de boleto inválido, cuatro tamaños y limpieza.
- [ ] Regresión final y suite completa; guía, revisión y commits locales.
- [ ] Autorización explícita del scope identity:nfc:validate para Comunidad y activación solo en el alterno.
- [ ] Tras aprobación, comprobar activación sin crear tarjetas, roles ni cobros.

No usar el cliente de E5 ni concederse scopes desde el código de E6. Mantener el
indicador de NFC apagado hasta autorización. No cambiar credenciales de usuarios,
MFA, pagos, contratos de E1, la copia principal ni publicar a GitHub. Un lector
físico queda pendiente de prueba con hardware; no se afirma certificación NFC.
