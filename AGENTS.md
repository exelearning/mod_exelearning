# AGENTS.md (raíz)

Repositorio del plugin **`mod_exelearning`** (Moodle 4.5 LTS → 5.x). Fase actual:
**plugin funcional end-to-end** — instala, extrae `.elpx`, sirve con sidebar nativa,
guarda calificaciones en gradebook vía SCORM 1.2 bridge → `track.php` → `grade_update`
multi-itemnumber. Demo Docker + Moodle Playground operativos.

Reglas operativas de investigación: [`research/AGENTS.md`](./research/AGENTS.md)
(append-only, evidencia citable, español, no vendorar externos).

## Estado actual (snapshot 2026-05-28)

### Hecho
- Esqueleto plugin + multi-grade-items (`classes/grades/gradeitems.php`, MAX=100).
- Parser `content.xml` (`classes/local/package.php`, `GRADABLE_IDEVICE_TYPES` ×20).
- Bridge SCORM 1.2: `view.php` shim + `exelearning_inject_scorm_loader` (pipwerks
  auto-init en `<head>`) + `track.php` con parseo de `cmi.suspend_data`
  (regex `^(\d+)\. "([^"]*)"; [^:]+: ([\d.]+)%; [^:]+: ([\d.]+)%`).
- Modos preview/grading (DEC-0006, verificado).
- **Intentos (DEC-0007, Aceptada)**: tabla `exelearning_attempt` + agregación
  `grademethod` (highest/average/first/last/lowest) en `classes/local/attempts.php`
  + `report.php` + privacy provider + backup/restore. Agrupación por
  `sessiontoken` (1 intento por carga de página). Verificado en Docker.
- **Finalización estilo SCORM (DEC-0010, Aceptada)**: `gradepass` + condición
  core `completionpassgrade` ("aprobar para completar"). `track.php` refuerza
  `completion->update_state` tras grabar nota.
- **Self-heal de subidas programáticas**: `view.php` re-extrae el paquete y
  re-detecta iDevices si faltan (arregla el `addModule` del Playground, que no
  pasa por `exelearning_add_instance`). `exelearning_extract_stored_package()`
  separada para reusarla sin draft. Verificado.
- Editor embebido portado de `mod_exeweb` (instalador GitHub + external API).
- Demo seeder idempotente (`scripts/setup_demo.php`) con 3 actividades
  evaluables (exelearning + `mod_scorm` + `mod_h5pactivity`, todas con
  finalización por aprobado) + `blueprint.json` (Playground). Verificado.
- Docker compose `erseco/alpine-moodle:v5.0.7` + MariaDB.
- Icono `pix/monologo.svg` (X sin hamburguesa).
- README estilo `mod_exeweb`, dependabot, composer.json.

### Pendiente (orden sugerido)
1. **TAREA-027 (DEC-0008)**: implementar selector `grademodel` (`overall` / `peritem` /
   `both` con `grade_category` propia y overall excluido del total del curso). Resuelve
   el doble conteo actual (3 columnas suman 65% por una actividad).
2. **Settings del editor como `mod_exeweb`**: falta la página `manage_embedded_editor.php`
   (settings.php enlaza a ella pero no existe → 404) + AMD del modal + la parte de
   "estilos definidos". El instalador GitHub + external API ya están portados.
3. **TAREA-021**: debug `editor/index.php?id=N` devuelve 404.
4. Intentos: pendiente `maxattempt` + `reviewmode` + "borrar intento" en el report (DEC-0007).
5. Cierre DEC-0003 con matriz cuantificada → Aceptada.
6. CI: `ci.yml` con matriz moodle-plugin-ci (DEC-0004).

## Decisiones clave (ver `research/decisiones/adr/`)

| ADR | Estado | Resumen |
|---|---|---|
| DEC-0001 | Aceptada | Metodología evidencia + ADRs |
| DEC-0002 | Aceptada | Política clones externos (no vendorar) |
| DEC-0003 | Propuesta | Plan B: SCO-per-page + parseo `content.xml` (no xAPI todavía) |
| DEC-0004 | Propuesta | CI matriz Moodle 4.5/5.0/5.1/5.2 × PHP 8.1-8.4 × pgsql/mariadb |
| DEC-0005 | **Superseded** by DEC-0009 | Editor embebido (versión con online) |
| DEC-0006 | Aceptada | Modos preview/grading |
| DEC-0007 | **Aceptada** | Intentos: tabla plana `exelearning_attempt` + `grademethod` (implementado) |
| DEC-0008 | Propuesta | Agregación grades: `overall`/`peritem`/`both` |
| DEC-0009 | Aceptada | **Sólo editor embebido**; eliminado eXeLearning Online / hmac |
| DEC-0010 | **Aceptada** | Finalización estilo SCORM = core `completionpassgrade` + `gradepass` |

## Restricciones inmutables

- **Sólo `.elpx` v4**. NO `.elp` legacy, NO `iteexe_online`.
- **NO** vendorar repos externos.
- **NO** integración eXeLearning Online (DEC-0009): no tocar `editormode`,
  `exeonlinebaseuri`, `hmackey1`, `APP_SECRET`, `EXELEARNING_WEB_*`.
- Sidebar nativa **siempre** preservada (técnica iframe de `mod_exeweb`).
- Repo público: `github.com/ateeducacion/mod_exelearning`.
- Organización: ATE = **Área de Tecnología Educativa** (no "Asistencia Técnica").

## Trampas conocidas (no repetir)

- **`itemnumber_mapping`**: Moodle 5 itera el mapeo entero → requiere strings
  `grade_overall_name` + `grade_idevice1..100_name` en `lang/en/exelearning.php`
  (loop generado, MAX=100 porque `test22.elpx` tenía 29 iDevices).
- **Pipwerks lazy**: eXeLearning v4 sólo llama `pipwerks.SCORM.init()` si lo
  inyectamos manualmente en el `<head>` de cada HTML (`exelearning_inject_scorm_loader`).
- **`lesson_status=passed`**: NO ponerlo en feedback (no es estándar; ya eliminado).
- **`enrol_manual->add_default_instance`**: falla en `erseco/alpine-moodle:v5.0.7`
  por defaults globales ausentes → usar `add_instance($course, [...status =>
  ENROL_INSTANCE_ENABLED...])` explícito.
- **`forum_announcementsubscription` undefined**: workaround en `setup_demo.php`
  setea `$CFG->forum_announcementsubscription=1` y `forum_announcementmaxattachments=9`
  antes de `create_course`.
- **Blueprint Playground**: `setLandingPage` requiere `?id=N`, NO `?shortname=`.
- **`monologo.svg`**: viewBox `0 0 78 78`, X ocupa todo, sin hamburguesa. Moodle 4+
  prefiere SVG → no recrear PNGs.
- **Sandbox iframe**: `allow-scripts allow-same-origin allow-popups allow-forms
  allow-popups-to-escape-sandbox` (sin `allow-top-navigation` ni `allow-modals`).
- **Switch-to-student**: en modo grading silencioso, no romper.

## Layout

```
mod_exelearning/
├── lib.php                    # API pública + sync_grade_items + inject_scorm_loader
├── view.php                   # iframe + SCORM 1.2 shim (autocommit 500ms)
├── track.php                  # AJAX endpoint (sesskey + mode preview/grading)
├── mod_form.php
├── settings.php               # 1 toggle (embeddededitor) + link a manage page
├── manage_embedded_editor.php # Página admin (instalar/borrar/actualizar editor)
├── editor/index.php           # [TAREA-021: 404]
├── classes/
│   ├── grades/gradeitems.php  # itemnumber_mapping (MAX 100)
│   ├── local/package.php      # Parser content.xml
│   └── event/course_module_viewed.php
├── db/{install.xml,access.php,upgrade.php}
├── lang/en/exelearning.php
├── pix/                       # monologo.svg (sin hamburguesa)
├── scripts/setup_demo.php     # Idempotente
├── dist/static/               # Editor embebido (build de exelearning v4)
├── blueprint.json             # Playground (?id=2)
├── docker-compose.yml         # erseco/alpine-moodle:v5.0.7
├── .env.dist
├── composer.json              # require-dev: moodlehq/moodle-cs, phpmd, phpunit
├── .github/dependabot.yml
└── research/                  # ADRs, fuentes, fixtures (append-only)
```

## Atajos útiles

```bash
docker compose up -d && docker compose logs -f moodle
docker compose exec moodle php /var/www/html/mod/exelearning/scripts/setup_demo.php
python3 research/tools/build_indexes.py
python3 research/tools/test_schema_validation.py
```

Credenciales demo: admin `user/1234`, teacher `teacher_demo/Demo!2026`,
estudiantes `alumno1, alumno2/Demo!2026`. Curso `EXEDEMO` (id=2).
