---
id: REPO-003
titulo: "wp-exelearning — plugin de WordPress para insertar recursos eXeLearning"
tipo: wordpress-plugin
ruta_local: /Users/ernesto/Dropbox/Trabajo/ate/exelearning/wp-exelearning
url_upstream: "[PENDIENTE]"
commit_consultado: "[PENDIENTE]"
fecha_consulta: 2026-05-28
licencia: "GPL-2.0-or-later [PENDIENTE: confirmar en cabecera del plugin]"
rol_para_mod_exelearning: "Referencia secundaria: técnica de content-proxy + iframe + bloque Gutenberg + editor embebido. Sin grading."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Hechos

- Plugin WordPress, no Moodle. **No tiene gradebook**.
- Sirve paquetes eXeLearning extraídos vía endpoint REST con headers de seguridad
  (CSP, X-Frame-Options) y protección contra path traversal.

## Rutas clave

| Ruta | Rol |
|---|---|
| `exelearning.php` | Bootstrap del plugin |
| `includes/class-exelearning.php` | Loader principal |
| `includes/class-elp-file-service.php` | Parseo y extracción de `.elp` |
| `includes/class-content-proxy.php` | Endpoint REST `/wp-json/exelearning/v1/content` |
| `includes/class-exelearning-editor.php` | Integración con editor embebido |
| `includes/class-elp-upload-handler.php` | Subida de paquetes |
| `includes/class-elp-upload-block.php` | Bloque Gutenberg |
| `public/class-shortcodes.php` | Shortcode `[exelearning id="123" height="600"]` que renderiza un iframe |
| `admin/class-admin-*.php` | Configuración y subida desde admin |

## Modelo de servicio

- Paquetes extraídos a `{uploads}/exelearning/{hash}/`.
- Inserción via shortcode o bloque Gutenberg.
- Display: iframe sandbox que carga el endpoint REST con validación de acceso.

## Capacidades respecto a `mod_exelearning`

- Sidebar/TOC: preservada vía iframe (igual técnica que `mod_exeweb`).
- Grading: **ninguno**.
- Tracking: **ninguno**.
- Editor embebido: sí.

## Riesgos / Limitaciones

- Ecosistema WordPress: contratos distintos a Moodle (no aplicable directamente).
- Sirve como inspiración para CSP/headers y para el patrón de content-proxy.

## Preguntas abiertas

- Ninguna específica al diseño de `mod_exelearning`; sirve sobre todo de referencia de patrones.
