# Guía visual de Campus Digital (E1–E7)

| Campo | Valor |
|---|---|
| Versión | 1.0 |
| Fecha | 2026-11-13 |
| Responsables | Integración del tercer parcial (cierre E1–E7) |
| Fuente | `resources/css/campus.css` (shell común), `tailwind.config.js`, pantallas de referencia de Campus Digital |
| Rúbrica | 6.5 (consistencia de colores, fuentes y diseño), 6.3/6.4 (estados y formularios), 6.6 (responsive) |

## 1. Estructura común
- Todas las vistas autenticadas usan el **shell** `.campus-shell`: barra lateral fija de 228 px, encabezado blanco y contenido sobre fondo claro. E1, E2, E3, E5, E6 y E7 entran por `AuthenticatedLayout` o `Modulo6Layout`; E4 usa `Equipo4Layout`, que replica el shell con los mismos tokens.
- Cada módulo agrega su navegación secundaria (pestañas o barra de módulo) **dentro** del contenido; no redefine la barra lateral.

## 2. Tokens de color
| Token | Valor | Uso |
|---|---|---|
| `--campus-navy` | `#12285a` | Barra lateral, encabezados de banda oscura |
| `--campus-blue` | `#2c489e` | Acentos y enlaces de navegación |
| `--campus-active` | `#2869e8` | Elemento activo, foco de navegación |
| `--campus-background` | `#f4f7fc` | Fondo de página |
| `--campus-border` | `#e1e7f0` | Bordes de tarjetas, tablas y campos |
| `--campus-ink` | `#263348` | Texto principal |
| `--campus-muted` | `#7d899d` | Texto secundario |
| Acción principal institucional | `#00338d` | Botón primario (familia azul marino; variantes observadas y aceptadas de la misma familia: `#245fae`, `#2c489e`, `#2c63b7`, `#153873`, `#2878ed`) |
| Peligro | `#e11d48` / `#b42332` | Eliminar, rechazar, errores |
| Éxito | `#227a56` / `#16a34a` | Confirmaciones |
| Foco accesible | contorno `3px #6c9cf4` | `:focus-visible` en botones, enlaces y campos |

## 3. Tipografía
- Pila única: `"Segoe UI", system-ui, -apple-system, sans-serif`, 14 px, interlineado 1,5 (definida en `.campus-shell`).
- Títulos de página: 22–30 px, peso 700. Etiquetas de formulario: 12–14 px, peso 600.
- E7 (`--cd-font` en `resources/js/Platform/platform.css`) y E4 (`Equipo4Layout`) usan la misma pila desde el 2026-11-13 (antes: Inter y Figtree).

## 4. Componentes y estados
- **Formularios:** etiqueta visible asociada al campo; error en línea bajo el campo (rojo) y, si hay varios, resumen con `role="alert"` al inicio; mensajes del servidor en español (`lang/es/validation.php`).
- **Carga:** el botón de envío se deshabilita y cambia su texto («Guardando…», «Procesando…»).
- **Éxito:** aviso con `role="status"` o notificación (`aria-live`).
- **Error del servidor:** aviso visible sin perder lo capturado; nunca pantalla en blanco.
- **Tablas:** encabezado en mayúsculas pequeñas, filas con borde `--campus-border`, desplazamiento horizontal dentro del contenedor en pantallas pequeñas.
- **Servicios (E5):** reglas comunes en `resources/css/student-services.css`, incluidas con `<style scoped src>`.

## 5. Responsive
- Se revisa en 1440/1280 px y en 390 px. A 390 px no debe haber desplazamiento horizontal de la página; la barra lateral se oculta y se usa el encabezado compacto.

## 6. Verificación
- Auditoría automática de estilos calculados en 16 pantallas de E1–E7 (tipografía del contenido, barra lateral, fondo, títulos, botones): `evidencias/interfaz/consistencia/consistencia.json` y la lámina comparativa `consistencia.png` en el paquete de resultados del cierre.
- Capturas responsive de las rutas principales (escritorio y 390 px) en `evidencias/interfaz/navegacion.json`.
