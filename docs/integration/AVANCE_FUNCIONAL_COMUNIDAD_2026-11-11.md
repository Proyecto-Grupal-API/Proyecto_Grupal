# Comunidad: avance funcional y evidencia al 11 de noviembre de 2026

**Estimación funcional de E6: 90.36%**, frente a 87.14% en el corte previo. Se supera el objetivo mínimo de 90% con capacidades implementadas y comprobadas. **No es una calificación oficial ni una certificación de aceptación total.** La autoevaluación de la rúbrica (94.95/100) es un documento y una medición distintos.

Fuente: `Campus_Digital_Diseno_Modulos.pdf`, páginas 11–13. Se conservan los once puntos y sus pesos originales de esfuerzo: total 42. Fórmula: `suma(peso × porcentaje funcional) / 42`. Los porcentajes por punto son una estimación técnica razonada y revisable, no una medición automática de líneas o pruebas. Un 100% de un punto se refiere a su alcance funcional descrito; no afirma ausencia de defectos o cierre de todas las dependencias del sistema.

## Desglose contra el documento

| Punto y alcance | Peso | Corte previo | Corte actual |
|---|---:|---:|---:|
| 6.1 Asociaciones estudiantiles | 4 | 80% | **80%** |
| 6.2 Consejo estudiantil | 4 | 85% | **85%** |
| 6.3 Cargos y responsabilidades | 3 | 85% | **85%** |
| 6.4 Convocatorias y solicitudes | 5 | 90% | **95%** |
| 6.5 Becas y beneficios | 4 | 90% | **90%** |
| 6.6 Eventos y accesos | 4 | 95% | **95%** |
| 6.7 Campañas y segmentación | 5 | 80% | **80%** |
| 6.8 Bandeja y mensajes | 4 | 90% | **100%** |
| 6.9 Encuestas | 3 | 85% | **95%** |
| 6.10 Votaciones académicas | 4 | 90% | **95%** |
| 6.11 Transparencia y reportes | 2 | 90% | **100%** |
| **Total ponderado** | **42** | **87.14%** | **90.36%** |

### 6.1 · Asociaciones estudiantiles — 80%

Sin cambio en este corte: estructura, miembros y expedientes propios; persiste la dependencia del directorio académico y validación de ámbitos E1.

### 6.2 · Consejo estudiantil — 85%

Sin cambio: Consejo y atribuciones propias operativos; las designaciones financieras fuera de testing siguen bloqueadas por E1.

### 6.3 · Cargos y responsabilidades — 85%

Sin cambio: cargo, vigencia, delegación y separación de rol institucional; falta el mecanismo productivo E1 para las atribuciones financieras.

### 6.4 · Convocatorias y solicitudes — 95%

Se completa la salida posterior al dictamen para revocar servicios entregados: autorización exacta de Presidencia, motivo, recibo, recuperación e historial. Queda la elegibilidad académica canónica externa.

### 6.5 · Becas y beneficios — 90%

Sin aumento: se entregan y revocan servicios E5; BECA monetaria todavía no tiene aprobación inicial de presupuesto ni designaciones válidas en local.

### 6.6 · Eventos y accesos — 95%

Sin aumento: el corte previo acreditó cobro y devolución de $25, QR y espera. Queda la aceptación con lector físico NFC.

### 6.7 · Campañas y segmentación — 80%

Sin aumento: audiencias propias, padrón congelado y preferencias. Campus/carrera/semestre/grupo y emisores institucionales requieren contrato y directorio E1.

### 6.8 · Bandeja y mensajes — 100%

Se conecta el productor real E7 de puntos y estados de canje, outbox, OAuth, recuperación por clave, enlaces privados al movimiento/canje, lectura/importancia/archivo y preferencias existentes. E2/E5 ya estaban conectados. Las preferencias de correo/push/SMS de E1 no equivalen a silenciar avisos transaccionales de la bandeja.

### 6.9 · Encuestas — 95%

Crear/publicar/participar/cerrar y repetición única probados por HTTP. Se exportan a E7 únicamente los totales cerrados; permanece pendiente el alcance académico externo.

### 6.10 · Votaciones académicas — 95%

Padrón, una participación, repetición idempotente, rechazo de ajenos, cierre y agregados E7 probados. Queda la validación institucional del padrón académico externo.

### 6.11 · Transparencia y reportes — 100%

Se acredita publicación y retiro hacia E7, consulta privada autorizada y cifras/CSV del reporte. Las entregas históricas no desaparecen al revocar; los periodos solapados se muestran separados y no se suman. No hay expedientes ni respuestas personales en el transporte.

## Qué se completó en esta entrega

- **E7 → bandeja E6:** cinco avisos reales del escenario: puntos, dos canjes confirmados, uno entregado y uno cancelado. GET por clave antes del POST, payload congelado, unicidad y lease; repetir dejó cero entregas nuevas. Un alumno ajeno no pudo consultar el canje ni el mensaje. Los enlaces seleccionan el hecho concreto, incluso cuando no está en la primera página del historial.
- **E6 → analítica E7:** reporte publicado, encuesta y votación cerradas llegaron por HTTP firmado HMAC. La consulta requiere sesión y permiso analítico. Al retirar el reporte dejó de aparecer, conservando los cierres de procesos. Para la captura visual se publicó después un segundo reporte mediante API; por eso la captura muestra uno vigente. No se suman periodos que pueden solaparse.
- **Revocación de beneficios E5:** desde dos apoyos ya aprobados como precondición se entregaron 100 impresiones y un locker. El alumno utilizó 30 páginas; Presidencia solicitó la revocación y E5 confirmó 70 liberadas y el locker disponible. El dictamen, la entrega y el consumo anterior se conservaron. El alumno no pudo ordenar su revocación. Repetir el worker no produjo otra cancelación.
- **Interfaz:** bandeja, detalle de canje, movimiento seleccionado, beneficio revocado y panel analítico verificados con Edge. Se quitaron los porcentajes ficticios del desglose por fuente de esa pantalla; esa sección indica que no está disponible. Esta entrega no audita el resto de la analítica propia de E7.

## Evidencia reproducible y límites

| Verificación de este corte | Resultado |
|---|---|
| Casos Feature distintos, considerando la última ejecución de cada clase/nombre | **272 sin fallo final** |
| Ejecuciones acumuladas de las siete suites focalizadas | 283; incluyen repeticiones diagnósticas |
| Recorrido real HTTP/OAuth/HMAC | **106 comprobaciones aprobadas** |
| Edge: estado, enlaces y errores | **8 comprobaciones; cero errores JavaScript y cero HTTP 500** |
| Vite | Compilación aprobada |
| Activación local integrada | Índices creados; tareas habilitadas desde 2026-11-11T10:25:08Z; primer escaneo sin históricos |

Los JUnit conservan el diagnóstico de dos fallos de comparación de fechas BSON/Carbon de la suite de enlaces. Se corrigieron sin debilitar los controles y los siete casos de enlaces pasaron después. La consolidación identifica 272 casos únicos aprobados por su última ejecución: **no se afirma una suite integral única sin advertencias**. El perfil protegido produce advertencias por `.env` ausente de manera deliberada. Se conserva la guarda de E1 y el aislamiento de todas sus conexiones Mongo.

Los escenarios usaron cuentas nuevas y MongoDB aislado. Los 100 puntos iniciales fueron una fixture explícita del laboratorio; el saldo final fue 80. No se simula que procedan de una compra ni se alteraron saldos de presentación. Las solicitudes de servicio comenzaron ya aprobadas para aislar el recorrido de entrega/revocación; este corte no acredita de nuevo su proceso de admisión.

Las pruebas PHP se ejecutan únicamente con el perfil integrado y Mongo aislado autorizado descrito en `E1-FINANCIAL-HTTP-TEST-PREFLIGHT.md`, con los aliases de E1/Comunidad/Servicios/Plataforma apuntando al destino protegido. No ejecutar Pest contra la base de presentación. Archivos focalizados: `PlatformCommunityInboxTest`, `PlatformCommunityNoticesTest`, `PlatformNoticeLinksTest`, `CommunityAnalyticsDeliveryTest`, `CommunityAnalyticsReadTest`, `RevocacionBeneficiosServiciosTest`, `RevocacionMetricasTest`, y la regresión de becas, consultas, reportes y API versionada. El detalle verificable de casos y resultados está en los JUnit, no en un contador manual.

## Entorno entregable y publicación

Código consolidado en `/home/daniel/campus_digital_integracion_e1`, rama `MERGE_COMPLETO`, aplicación http://127.0.0.1:8086. La copia original se conserva. Se activaron únicamente las tareas nuevas acordadas; las credenciales permanecen privadas. La comparación de activación mantuvo 16 usuarios, 9 asignaciones de rol, 2 reportes, 1 beneficio y saldo de puntos cero. No se enviaron registros anteriores al corte. Los laboratorios son temporales y no se entregan como otra versión del producto.

La configuración de cada clon es local: Git publica código, migraciones y `.env.example` con banderas apagadas, **no copia credenciales ni activa otros entornos**. El registro de publicación y el hash se verifican con Git al finalizar.

## Qué impide el 100%

1. **E1: presupuesto inicial BECA.** `InstitutionalBudgetService::approve` necesita `budget.approve`, pero el autorizador publicado solo atiende `budget.commit`. El aval de Tesorería por solicitud no crea ni aprueba el presupuesto. El recorrido previo se detuvo sin emitir bonos.
2. **E1: designaciones fuera de testing.** La guarda oficial restringe concesiones BECA/devoluciones al perfil de pruebas. Hace falta una vía autorizada en el entorno entregable; no cambiar APP_ENV ni eliminar la guarda para aparentar una entrega.
3. **E1: directorio y segmentación académica.** Los clientes/integridad/cuota del directorio y los ámbitos canónicos campus/carrera/semestre/grupo siguen sin estar acreditados para estos recorridos. Tener lectura de estudiantes por referencias no reemplaza el directorio global.
4. **Aceptación restante:** NFC con dispositivo físico, completar esquemas estrictos del catálogo total del portal y validar los recorridos académicos/institucionales cuando estén disponibles. Las evidencias del equipo y la evaluación docente no se sustituyen con esta estimación.

## Archivos de respaldo

- `evidence/comunidad-90-2026-11-11/resumen.json`: metodología de consolidación y resultados.
- `evidence/comunidad-90-2026-11-11/recorrido-http.json`: comprobaciones y recibos del laboratorio, saneados.
- `evidence/comunidad-90-2026-11-11/navegador.json`: validación visual y capturas.
- `evidence/comunidad-90-2026-11-11/activacion-local.json` y `tareas-locales.json`: comparación antes/después y revisión del scheduler.
- `COMUNIDAD_E5_E7_CONTRATOS_2026-11-11.md`: responsabilidades, operaciones y recuperación.
- `ACTIVACION_COMUNIDAD_E7_2026-11-11.md`: alcance autorizado y desactivación.
