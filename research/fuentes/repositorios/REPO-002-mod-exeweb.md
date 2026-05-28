---
id: REPO-002
titulo: "mod_exeweb — visor de recursos eXeLearning sin calificación"
tipo: moodle-plugin
ruta_local: /Users/ernesto/Dropbox/Trabajo/ate/exelearning/mod_exeweb
url_upstream: "[PENDIENTE]"
commit_consultado: "[PENDIENTE]"
fecha_consulta: 2026-05-28
licencia: "GPL-3.0-or-later [PENDIENTE: confirmar]"
rol_para_mod_exelearning: "Referencia primaria para preservar la sidebar nativa de eXeLearning vía iframe + pluginfile.php."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Hechos

- Component frankenstyle: `mod_exeweb`.
- Requiere Moodle ≥ 4.2.
- **Sin gradebook**: `FEATURE_GRADE_HAS_GRADE` no soportado.
- Acepta `.zip` / `.elpx` con el contenido publicado de eXeLearning.

## Rutas clave

| Ruta | Rol |
|---|---|
| `lib.php` | Hooks Moodle (sin grading) |
| `locallib.php` | `exeweb_display_embed()`, `exeweb_display_frame()` |
| `mod_form.php` | Upload de paquete, opciones de display |
| `view.php` | Renderizado: iframe / frameset / popup |
| `db/install.xml` | Tabla `exeweb` (sin tracks ni grades) |
| `db/access.php` | Capabilities: `view`, `addinstance`, `manageembeddededitor` |
| `classes/exeweb_package.php` | Extracción y validación de paquete |
| `classes/output/renderer.php` | Helpers de renderizado |
| `amd/src/` | Fullscreen, resize, editor modal |
| `backup/moodle2/` | Backup simple, sin tracking |
| `exelearning/` | Sub-app del editor eXeLearning embebido |

## Cómo preserva la sidebar

- El paquete extraído (servido vía `pluginfile.php/.../content/{revision}/`) ya contiene
  `index.html` + `styles/` + `lib/exe_player.js` + `content/`.
- `view.php` lo embebe en `<iframe>` o frameset. **No reimplementa nada**: el árbol de
  navegación es HTML+CSS+JS del paquete.

## Capacidades respecto a `mod_exelearning`

- Sidebar/TOC: **nativa de eXeLearning preservada**. Ésta es la pieza a heredar.
- Grading: **ninguno**. Limitación a superar.
- Tracking: **ninguno**. Habría que añadir un canal SCORM o xAPI.
- Editor embebido: sí.
- Backup/restore: del archivo, no de progreso de alumno.

## Infraestructura de build / CI

Idéntica a REPO-001 (mismo equipo, mismo template):

- `Makefile` con los mismos targets (`up/down/lint/phpmd/test/behat/build-editor/
  package/...`).
- `.github/workflows/`: `release.yml`, `check-editor-releases.yml`,
  `pr-playground-preview.yml`. **Sin `ci.yml` matriz.**
- Misma sub-app `exelearning/` para editor embebido construida con `make build-editor`.

## Riesgos / Limitaciones

- Pérdida de aislamiento de CSS si en el futuro se sirve sin iframe.
- Sin canal de tracking, no se puede calificar.
- Sin manifest formal de actividades calificables.

## Preguntas abiertas

- PREG-001 (compartida con REPO-001): identificadores estables por item calificable en el paquete publicado.
