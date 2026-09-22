// Índices actuales de integration_idempotency:
d.integration_idempotency.createIndex({ service: 1, idempotency_key: 1 }, { unique: true });
d.integration_idempotency.createIndex({ expires_at: 1 }, { expireAfterSeconds: 0 });

// Corrección C: limpia registros de idempotencia que se quedan atascados en
// PROCESSING (por ejemplo, si el proceso muere antes de llegar a un estado
// terminal). Mongo TTL ignora valores null, así que este índice únicamente
// borra los documentos donde 'unexpired_processing_at' sigue siendo una
// fecha; en cuanto el registro llega a COMPLETED/FAILED/REJECTED, la
// aplicación pone ese campo en null y el TTL deja de aplicarle.
d.integration_idempotency.createIndex(
    { unexpired_processing_at: 1 },
    { expireAfterSeconds: 0 }
);
