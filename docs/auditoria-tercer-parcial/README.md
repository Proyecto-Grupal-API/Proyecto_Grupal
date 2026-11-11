# Auditoría del tercer parcial · Comunidad

Fuente: Auditoria_Tercer_Parcial_Evidencia_Detallada.docx del solicitante. Corte: 2026-11-10. No se modificó el documento original.

- [Documento integrador](INTEGRADOR.md): requisitos, once historias, arquitectura, diccionario y alcance de pruebas.
- [Matriz de los 72 criterios](matriz-cumplimiento.json): estado, respuesta y ubicación de evidencia.
- [Plan de cierre](PLAN_CIERRE.md): parciales y dependencias para aspirar a la calificación máxima.
- [Catálogo API](API.md), [OpenAPI](comunidad.openapi.json) y [colección Postman](Comunidad.postman_collection.json).

La copia Word llenada, el PDF, las capturas, la grabación, las respuestas HTTP, las exportaciones y los logs/JUnit se entregan como paquete de evidencia separado. Los archivos grandes de demostración no se agregan al código de la aplicación.

## Ejecución y seguridad de pruebas

Las pruebas Feature usan los controles existentes de `tests/Support/E1MongoTestTarget.php` y el perfil integrado aprobado de cuatro alias. Exigen una réplica MongoDB aislada, un usuario limitado a `campus_virtual_testing`, bootstrap privado y ausencia de .env del proyecto; nunca ejecutarlas directamente contra la base del alterno. El paquete incluye las herramientas exactas de preparación y ejecución usadas, que generan credenciales efímeras, no secretos del proyecto.

Las pruebas unitarias se ejecutaron con el perfil aislado y bootstrap que declara PHPUNIT_COMPOSER_INSTALL después de cargar Pest; eso permite sus subprocesos sin duplicar el preloader de PHPUnit. Los intentos iniciales con el perfil integrado y sin autoload del subproceso no se consideraron evidencia aprobatoria.

Las advertencias de dotenv del perfil protegido se conservaron en los resultados. La ejecución no amplía las conexiones permitidas ni desactiva los guardas de E1.

No se activa una bandera financiera, scope, grant, fuente de fondos o asignación productiva por el hecho de pasar esta auditoría.
