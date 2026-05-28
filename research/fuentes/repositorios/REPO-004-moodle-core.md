---
id: REPO-004
titulo: "Moodle core — referencias relevantes para mod_exelearning"
tipo: lms-core
ruta_local: /Users/ernesto/Downloads/git/moodle
url_upstream: https://github.com/moodle/moodle
commit_consultado: "[PENDIENTE: registrar HEAD del clon local]"
fecha_consulta: 2026-05-28
licencia: GPL-3.0-or-later
rol_para_mod_exelearning: "Fuente de patrones canónicos: multi-grade-items (mod_workshop), xAPI handler (mod_h5pactivity), bridge SCORM (mod_scorm), grade API (gradelib), core_xapi."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Hechos por área

### Grade API (`lib/gradelib.php`)

- Función canónica: `grade_update($source, $courseid, $itemtype, $itemmodule, $iteminstance, $itemnumber, $grades = null, $itemdetails = null, $isbulkupdate = false)`.
- `$itemnumber` distingue múltiples grade items para la misma `$iteminstance`.

### Patrón multi-grade-items: `public/mod/workshop/lib.php`

- En `workshop_grade_item_update($workshop, $submissiongrades=null, $assessmentgrades=null)` (líneas ~1110-1130) se invoca `grade_update` dos veces:
  - `itemnumber=0` para la nota de envío.
  - `itemnumber=1` para la nota de evaluación.
- Borrado consistente: una invocación por cada `itemnumber` con `array('deleted' => true)`.
- **Este es el patrón a replicar en `mod_exelearning`.**

### xAPI core: `public/lib/xapi/classes/handler.php`

- Clase base abstracta `core_xapi\handler`. Plugins implementan `statement_to_event($statement)`.
- API REST asociada: `post_statement`, `post_state`, `get_state`, `delete_state` con scopes OAuth2.
- Tabla persistente: `core_xapi_state`.

### xAPI consumer: `public/mod/h5pactivity/classes/xapi/handler.php` y `classes/local/grader.php`

- `handler::statement_to_event()` valida verbos (`answered`, `completed`), parsea object id (incluyendo subcontent), actualiza tabla `h5pactivity_attempts`.
- `grader::grade_item_update()` empuja al gradebook (también `itemnumber=0`).
- Modelo a estudiar para diseñar el handler xAPI de `mod_exelearning`.

### SCORM bridge: `public/mod/scorm/lib.php` (líneas ~588-696)

- `scorm_get_user_grades()`, `scorm_grade_user()`, `scorm_update_grades()`, `scorm_grade_item_update()`.
- Confirma: SCORM core también colapsa en `itemnumber=0`.

### LTI 1.3 AGS: `public/mod/lti/service/gradebookservices/classes/local/resources/{lineitems,lineitem,scores}.php`

- Endpoints LTI AGS para múltiples `LineItem` por contexto.
- Soporta multi-item pero requiere que el tool externo descubra y publique line items.

### Layout de un activity module Moodle 4.x

Archivos canónicos requeridos/recomendados (resumen):

- `version.php`, `lib.php`, `mod_form.php`, `view.php`
- `db/install.xml`, `db/access.php`
- `lang/en/<mod>.php`
- `classes/privacy/provider.php`
- `pix/icon.svg` (o `.png`)
- `backup/moodle2/backup_<mod>_stepslib.php`, `restore_<mod>_stepslib.php`, `backup_<mod>_activity_task.class.php`, `restore_<mod>_activity_task.class.php`
- Opcionales: `db/services.php`, `db/events.php`, `db/tasks.php`, `classes/external/`, `classes/event/`, `classes/observer.php`, `classes/xapi/handler.php`, `settings.php`, `tests/`, `amd/src/`, `templates/`.

## Riesgos / Limitaciones

- Versión de Moodle objetivo aún no fijada (ver DEC pendiente sobre versión mínima).
- `core_xapi` evoluciona: dependencia versionada.

## Preguntas abiertas

- ¿Versión mínima de Moodle objetivo (4.2 / 4.5 LTS / 5.x)? — PREG futura.
