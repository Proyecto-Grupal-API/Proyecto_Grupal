# Equipo 2 — Consumo de estado estudiantil

Integre el snapshot de Team 1 **con su código existente**; no reemplace modelos o rutas propios. Contrato principal: [Team 1](TEAM-1-INTEGRATION.md).

- El `student_id` externo es `User._id`, no `StudentProfile._id` ni matrícula.
- Para lectura entre servicios, obtenga un token mediante `POST /api/oauth/token` (`grant_type=client_credentials`) con un cliente autorizado para `students:read`. No use sesión web ni Sanctum para este contrato.
- Consulte `GET /api/v1/students/{User._id}/status` y `/status/history`. El primero devuelve `data.student_id=data.user_id=User._id`; el segundo devuelve `data.student_id=User._id` e `items[]`.
- Estados académicos: `active`, `inactive`, `suspended`, `restricted`, `leave`, `graduated`. `restrictions` y `benefits_eligible` son null: decida elegibilidad en su dominio, no a partir de esos null.
- Si su operación recibe un QR, puede validar por `POST /api/v1/identity/qr-validate` con el scope **distinto** `identity:qr:validate`; no lo necesita para una consulta de estado ordinaria. Validar identidad no autoriza automáticamente el servicio.

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

No consultar `student_profiles` ni `academic_status_history` directamente. Si requiere reglas de beneficio/restricción estructuradas o datos adicionales, declare la dependencia: esa decisión no está expuesta por Team 1. La API de validación NFC aún no existe.
