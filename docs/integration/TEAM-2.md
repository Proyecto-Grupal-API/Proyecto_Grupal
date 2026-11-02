# Equipo 2 — Consumo de estado estudiantil

Integre el snapshot de Team 1 **con su código existente**; no reemplace modelos o rutas propios. Contrato principal: [Team 1](TEAM-1-INTEGRATION.md).

- El `student_id` externo es `User._id`, no `StudentProfile._id` ni matrícula.
- Para lectura entre servicios, obtenga un token mediante `POST /api/oauth/token` (`grant_type=client_credentials`) con un cliente autorizado para `students:read`. No use sesión web ni Sanctum para este contrato.
- Consulte `GET /api/v1/students/{User._id}/status` y `/status/history`. El primero devuelve `data.student_id=data.user_id=User._id`; el segundo devuelve `data.student_id=User._id` e `items[]`.
- Estados académicos: `active`, `inactive`, `suspended`, `restricted`, `leave`, `graduated`. `restrictions` y `benefits_eligible` son null: decida elegibilidad en su dominio, no a partir de esos null.
- Si su operación recibe un QR, puede validar por `POST /api/v1/identity/qr-validate` con el scope **distinto** `identity:qr:validate`; no lo necesita para una consulta de estado ordinaria. Validar identidad no autoriza automáticamente el servicio.
- Si presenta un UID NFC, puede consultar `POST /api/v1/identity/nfc-validate` con `{"credential_uid":"{PRESENTED_UID}"}` y el scope **distinto** `identity:nfc:validate`. Sólo una tarjeta `active` de propietario activo con perfil estudiantil identifica al alumno (`student_id=User._id`); un 422 no devuelve identidad. Este resultado no autoriza recarga, retiro, pago ni saldo: Equipo 2 conserva esas reglas y su auditoría financiera. Véase [Team 1](TEAM-1-INTEGRATION.md) para el contrato completo.

Ejemplo ilustrativo, con marcadores y sin secretos reales:

```http
POST /api/oauth/token HTTP/1.1
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials&client_id={CLIENT_ID}&client_secret={CLIENT_SECRET}&scope=students:read
```

La respuesta incluye `access_token`, `token_type=Bearer`, `expires_in` y `scope=students:read`; no registre ni comparta el token recibido.

```http
GET /api/v1/students/{USER_ID}/status HTTP/1.1
Authorization: Bearer {SERVICE_ACCESS_TOKEN}
```

```json
{
  "data": {
    "student_id": "{USER_ID}", "user_id": "{USER_ID}",
    "name": "Nombre de ejemplo", "enrollment": "ABC-123",
    "program": "Programa de ejemplo", "semester": 2,
    "campus": "Campus de ejemplo", "status": "active",
    "status_label": "Activo", "effective_from": null,
    "status_reason": null, "restrictions": null, "benefits_eligible": null
  },
  "meta": { "request_id": "{REQUEST_ID}", "api_version": "v1" }
}
```

El historial mantiene el mismo sobre `meta` y `data: {"student_id":"{USER_ID}","items":[{"from_status":null,"status":"active","reason":"Alta inicial","actor_id":null,"effective_from":"{ISO_DATE}","recorded_at":"{ISO_DATE}"}]}`. Los campos y nulls son ilustrativos; el esquema real está en la guía principal.

No consultar `student_profiles`, `nfc_cards` ni `academic_status_history` directamente. Si requiere reglas de beneficio/restricción estructuradas o datos adicionales, declare la dependencia: esa decisión no está expuesta por Team 1.

## Consulta financiera de bonos — implementación local Batch 03B

Este contrato HTTP continúa local y no publicado. La política de aplicación E1 se prepara para compartir la decisión con E2; su incorporación al consumidor integrado requiere otra autorización. Las pruebas históricas del adaptador interno no certifican este nuevo comportamiento contextual. E2 conserva emisión/asignación, reglas financieras, saldos e idempotencia.

### Política de aplicación local

`FinancialAuthorizationDecision` se resuelve por atributo Bind a `FinancialAuthorizationPolicy`. Inyecta el contrato exact-scope `BonusAuthorization`, sin cambiar su binding ni su motor. `FinancialContextValidator` no tiene binding productivo: no se inventa un catálogo comercial ni se leen colecciones de otros equipos.

Una consulta global evalúa sólo el grant financiero global específico. NEGOCIO contextual requiere primero una respuesta `Valid` del validador confiable; después evalúa global específico O grant contextual exacto. `Invalid` deniega sin fallback; `Unavailable` o validador ausente produce HTTP 503, nunca `authorized=true`. Se preserva el identificador solicitado. El validador acredita el recurso; no exige membership a quien tiene autoridad global. La rama contextual exacta conserva su requisito de membership efectiva.

BECA contextual y CAMPANIA contextual están bloqueadas en la política y el request, incluso para un titular global. La capacidad técnica academic_program del motor no acredita equivalencia con programas de apoyo. Las pruebas unitarias de orquestación usan dobles explícitos y no acreditan un proveedor comercial real ni legitimidad institucional.

`POST /api/v1/identity/financial-authorization/check`, scope `identity:financial-authorization:check`, token obtenido por `POST /api/oauth/token` con `client_credentials`. No se aprovisionan clientes/grants productivos automáticamente. Un scope comercial, students:read o NFC no sirve para este contrato. Se revalidan tanto scope del token como el grant vigente del cliente persistente en cada request; retirarlo o desactivar el cliente impide usar un token previamente emitido.

```json
{
  "subject_id": "{USER_ID_CANONICO}",
  "bonus_type": "BENEFICIO",
  "operation": "issue",
  "scope_type": null,
  "scope_id": null
}
```

Los cinco campos son obligatorios, incluidos los dos null de ámbito global. Tipos exactos: BECA, CAMPANIA, BENEFICIO, CATEGORIA y NEGOCIO. Sólo operation=issue. Respuesta200 exacta: `{"authorized":true}` o `{"authorized":false}`. No roles, permisos, datos financieros, historial, reason_code ni información adicional del sujeto. Un sujeto inexistente o no autorizado produce la misma denegación booleana.

Para un negocio, el mismo request usa `"bonus_type":"NEGOCIO","scope_type":"business","scope_id":"{BUSINESS_ID}"`; sin proveedor contextual confiable responde 503. BECA/academic_program y CAMPANIA/campaign contextuales responden 422, pendientes de acuerdo. No cambie subject_id por un perfil o matrícula. Solicite el token con `scope=identity:financial-authorization:check` y envíelo en `Authorization: Bearer {SERVICE_ACCESS_TOKEN}`. E2 debe distinguir fallos de transporte de authorized=false y nunca continuar una emisión tras un error.

| Rol técnico | Tipo y ámbito | Estado |
|---|---|---|
| bonus_beneficio_issuer | BENEFICIO global null/null | Definición/mapping en código; no materializado en development |
| bonus_categoria_issuer | CATEGORIA global null/null | Ídem |
| bonus_negocio_issuer | NEGOCIO global null/null | Ídem |
| bonus_negocio_business_issuer | NEGOCIO business/business_id | Ídem; membership efectiva obligatoria |
| bonus_beca_issuer | BECA global null/null | Ídem |
| bonus_beca_program_issuer | BECA academic_program/AcademicProgram._id | Ídem; programa/campus activos y binding original |
| bonus_campania_issuer | CAMPANIA global null/null | Ídem; campaña contextual no implementada |

Cada rol habilita sólo bonuses.<tipo en minúscula>.issue. No rol emisor universal ni admin/owner/manager por analogía. El motor sigue siendo exact-scope; sólo la política compone explícitamente global O contextual después de validar el negocio. El business_id es un identificador opaco de su propietario; falta conectar su comprobación confiable de existencia/estado. No global/platform/campaign como scope_type; nombres/matrículas no sustituyen IDs. No aceptar roles/capabilities/membership/actor_id declarados como prueba de autoridad.

Errores:401 token ausente/inválido;403 token sin scope o cliente sin grant vigente;422 payload malformado o contexto no soportado;429 límite30 requests/minuto;503 fallo de infraestructura durante la evaluación o revalidación del grant OAuth, sin simular una decisión authorized=false. No registrar tokens ni detalles internos de errores. El transporte fallido no es una autorización.

### Cliente y humano son identidades diferentes

client_credentials autentica al servicio E2. subject_id es el User._id del humano que E2 afirma haber vinculado a su propia sesión; E1 no acredita esa sesión a partir del token ni de ese campo. X-Actor-ID no concede autoridad y actor_id no se acepta en el payload. E2 debe vincular el actor a su guard y revalidar el derecho antes de emitir. Una respuesta true no reserva autoridad ni elimina carreras TOCTOU.

### Concesión y activación pendientes

Las siete reglas técnicas están definidas, pero no hay identidad/cargo institucional acreditado para otorgarlas. FinancialGrantAuthority no tiene implementación/binding productivo; FinancialRoleGrantService tiene bloqueo explícito en producción, sin rutas. Sus positivos usan sólo designación sintética en campus_virtual_testing. No acceso de escritura mediante OAuth S2S ni autoasignación; revocación conserva lifecycle, razón/actor y auditoría transaccional.

Antes de operación real se requieren designación institucional, aprobación de activación/materialización del catálogo y writer, configuración explícita del cliente y grant financiero, adaptador consumidor E2 y pruebas reales. No seeders/grants sobre development en este lote. El seeder foundation conserva16 roles/8 permisos/13 mappings; las definiciones financieras están separadas. La infraestructura local no acredita aceptación bilateral ni integración extremo a extremo.


## Alcance de esta copia de incorporación E1 (2026-10-28)

Esta copia temporal parte de MERGE_COMPLETO `373ce0c5079e421f1c01ce81ae87ed8716272517`. Las referencias de publicación y resultados anteriores de esta guía son antecedentes, no certificación de esta incorporación. Los nuevos contratos financieros/directorio y el enriquecimiento de credenciales son código local no publicado; no se habilitan consumidores ni transportes. Ver [estado y límites de incorporación](E1-INCORPORACION-MERGE-COMPLETO.md).
