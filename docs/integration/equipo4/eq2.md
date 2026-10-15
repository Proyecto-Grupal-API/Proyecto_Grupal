```markdown

\# Integración con Equipo 2 — Wallet / Pagos



\*\*Rol de Eq.4\*\*: Servidor (exponemos webhook a Eq.2)

\*\*Controller\*\*: `App\\Api\\PaymentWebhookController`

\*\*Ruta\*\*: `POST /api/equipo4/webhooks/payment-status`



\## Contexto



Cuando Eq.2 procesa un pago o reembolso, nos notifica vía webhook. Eq.4 no procesa pagos ni conoce métodos de pago — solo reacciona al estado que Eq.2 publica para generar movimientos de inventario (RETURN cuando hay refund).



\## Endpoint expuesto



\### POST /api/equipo4/webhooks/payment-status



Firma HMAC-SHA256 con `TEAM\_SERVICE\_SHARED\_SECRET` (ver README).



Headers requeridos:

\- X-Team-Timestamp: 1790000123

\- X-Team-Signature: <hmac-hex>

\- Content-Type: application/json

\- Accept: application/json



Body esperado:

```json

{

&#x20; "payment\_intent\_id": "PI-2026-ABCD1234",

&#x20; "order\_id": "ORD-2026-XYZ",

&#x20; "business\_id": "BUS-CD-SOUV-001",

&#x20; "status": "PAID",

&#x20; "amount": 349.00,

&#x20; "currency": "MXN",

&#x20; "occurred\_at": "2026-10-02T19:00:00-06:00",

&#x20; "items": \[

&#x20;   {

&#x20;     "product\_id": "6abffeaf31a8cb527504f753",

&#x20;     "variant\_id": null,

&#x20;     "location\_id": "6abffeaf31a8cb527504f754",

&#x20;     "quantity": 1,

&#x20;     "unit\_price": 349.00

&#x20;   }

&#x20; ]

}

```



Campos requeridos:



| Campo | Requerido | Notas |

|---|---|---|

| payment\_intent\_id | Sí | ID único de Eq.2 (idempotencia) |

| order\_id | Sí | Referencia de la orden |

| business\_id | Sí | Debe ser BUS-CD-SOUV-001 |

| status | Sí | Ver estados abajo |

| amount | Sí | Numérico |

| currency | Sí | ISO 4217 (MXN) |

| occurred\_at | Sí | ISO 8601 |

| items\[] | Solo REFUNDED | Requerido solo para REFUNDED / PARTIALLY\_REFUNDED |



Estados permitidos:



| Estado | Acción de Eq.4 |

|---|---|

| PENDING | Guarda evento. NO genera movimiento |

| PAID | Guarda evento. NO genera movimiento (lo confirma Eq.3) |

| FAILED | Guarda evento. NO genera movimiento |

| REFUNDED | Genera movimiento RETURN si vienen items\[] |

| PARTIALLY\_REFUNDED | Genera movimiento RETURN parcial si vienen items\[] |



\## Idempotencia



Eq.4 deduplica por tupla (payment\_intent\_id, status, amount).



\- Webhook idéntico al anterior → 202 con idempotent: true, NO inserta de nuevo

\- Webhook con status distinto para mismo payment\_intent\_id → lo trata como evento nuevo (permite transiciones PENDING → PAID → REFUNDED)



Colección: payment\_events



\## Respuesta exitosa



```json

{

&#x20; "received": true,

&#x20; "payment\_event\_id": "6712abc...",

&#x20; "idempotent": false

}

```



HTTP Status: 202 Accepted



Si es duplicado:

```json

{

&#x20; "received": true,

&#x20; "payment\_event\_id": "6712abc...",

&#x20; "idempotent": true

}

```



HTTP Status: 202 Accepted



\## Errores



| Código | Cuándo |

|---|---|

| 401 | Firma HMAC inválida o timestamp fuera de 5 min |

| 422 | Payload con campos faltantes o tipos incorrectos |

| 500 | Error interno (se loggea, Eq.2 puede reintentar) |



\## Auto-return en REFUNDED (bonus)



Cuando el body incluye items\[] y status = REFUNDED:



1\. Eq.4 crea un movimiento RETURN por cada item

2\. Actualiza inventario (on\_hand sube, available sube)

3\. Registra kardex en stock\_movements



Si el body NO incluye items\[]:



1\. Solo guarda el evento en payment\_events

2\. NO genera movimiento (Eq.4 no puede adivinar qué items reingresar)



Recomendación a Eq.2: en REFUNDED y PARTIALLY\_REFUNDED, siempre mandar items\[] para que Eq.4 pueda reingresar el stock.



\## Relación con Eq.3 (verificación cruzada)



El payment\_intent\_id que Eq.2 manda a este webhook es el mismo que Eq.3 debe mandar en el /confirm de reservas a Eq.4.



Flujo completo:



```

1\. Eq.2 procesa pago → genera payment\_intent\_id

2\. Eq.2 → webhook a Eq.4 con status=PAID

3\. Eq.4 guarda evento en payment\_events

4\. Eq.3 → POST /reservations/{id}/confirm con payment\_intent\_id

5\. Eq.4 valida que existe un evento PAID con ese id → confirma

```



Acción requerida de Eq.2: además de mandar el payment\_intent\_id a Eq.4, también debe hacérselo llegar a Eq.3 (por el canal que definan) para que Eq.3 pueda pasárnoslo en el confirm.



\## Endpoint de salud



GET /up — Health check estándar de Laravel. No requiere HMAC.

```
