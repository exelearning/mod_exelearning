# eXeLearning resource for Moodle

[![Preview in Moodle Playground](https://raw.githubusercontent.com/ateeducacion/action-moodle-playground-pr-preview/refs/heads/main/assets/playground-preview-button.svg)](https://moodle-playground.com/?blueprint-url=https://raw.githubusercontent.com/ateeducacion/mod_exelearning/refs/heads/main/blueprint.json)

> **Activity-type Moodle module to create, edit and grade eXeLearning resources
> (`.elpx`) directly in Moodle, preserving eXeLearning's native navigation and
> supporting multiple gradebook items per activity.**

Activity-type module to embed eXeLearning v4 packages (`.elpx`) inside Moodle while
**preserving the package's native sidebar navigation** and **recording one or more
gradable items per resource** in the Moodle gradebook (e.g. a single resource with
two quizzes registers two independent gradebook columns).

This plugin merges the best of two siblings:

* [`mod_exeweb`](https://github.com/ateeducacion/mod_exeweb) — read-only viewer that
  keeps eXeLearning's native sidebar but does not grade.
* [`mod_exescorm`](https://github.com/ateeducacion/mod_exescorm) — SCORM player with
  eXeLearning extensions; grades into a single aggregated column.

`mod_exelearning` keeps the native sidebar AND splits the grade into independent
columns per iDevice. Click the **Preview in Moodle Playground** badge above to try
it without installing anything.

## Compatibility

This plugin targets every supported Moodle release from **Moodle 4.5 LTS** (the
minimum required, see `version.php`: `$plugin->requires = 2024100700`) up to the
latest Moodle 5.x stable.

| Moodle branch         | Status                                    |
| --------------------- | ----------------------------------------- |
| 4.5.x (LTS)           | Supported (minimum required version)      |
| 5.0.x                 | Supported · default reference image       |
| 5.1.x                 | Supported                                 |
| 5.2.x (latest stable) | Supported                                 |

Older Moodle releases (3.x, 4.0–4.4) are **not** supported because the plugin
relies on the multi-grade-item API (`get_grade_item_names` and the
`itemnumber_mapping` interface) that was finalised in Moodle 4.5 LTS, and on
`core_xapi` for the runtime SCORM/xAPI bridge. The plugin is expected to keep
working with newer Moodle releases as they appear; if you find an incompatibility
please open an issue at <https://github.com/ateeducacion/mod_exelearning/issues>.

### Requirements

* **Moodle**: 4.5 or later (see table above).
* **PHP**: 8.1+ (whatever Moodle 4.5+ requires).
* **Database**: any database supported by the Moodle release in use.
* **Browser**: any modern, evergreen browser with JavaScript enabled.

## Quick test (no install)

The fastest way to try this plugin is **Moodle Playground**. Click the badge at
the top of this README and you'll get a fresh Moodle in your browser with:

* `mod_exelearning` installed.
* A demo course `EXEDEMO` (_Demo eXeLearning · ejemplo de uso_).
* Teacher account `teacher_demo / Demo!2026`.
* Two enrolled students `alumno1`, `alumno2 / Demo!2026`.
* An activity preloaded with `actividad-evaluable.elpx` (2 gradable iDevices:
  `trueorfalse` + `guess`).

Nothing to install locally; everything runs in the browser via WebAssembly.

## Installation

> **Important:** It is recommended to install from a [release ZIP](https://github.com/ateeducacion/mod_exelearning/releases),
> which includes the embedded editor pre-built for optimal performance. If the
> release ZIP does not include the editor, or if you want to install a newer
> version, administrators can download it from GitHub Releases via the
> _Manage embedded editor_ page in the plugin settings.

### Installing via uploaded ZIP file

1. Download the latest ZIP from
   [Releases](https://github.com/ateeducacion/mod_exelearning/releases).
2. Log in to your Moodle site as an admin and go to _Site administration >
   Plugins > Install plugins_.
3. Upload the ZIP file with the plugin code. You should only be prompted to add
   extra details if your plugin type is not automatically detected.
4. Check the plugin validation report and finish the installation.

### Installing manually

1. Download and extract the latest ZIP from
   [Releases](https://github.com/ateeducacion/mod_exelearning/releases).
2. Place the extracted contents in `{your/moodle/dirroot}/mod/exelearning`.
3. Log in to your Moodle site as an admin and go to _Site administration >
   Notifications_ to complete the installation.

Alternatively, you can run

    $ php admin/cli/upgrade.php

to complete the installation from the command line.

## Local development environment (Docker)

A `docker-compose.yml` is provided to spin up a self-contained test environment
based on `erseco/alpine-moodle:v5.0.7` + MariaDB:

```bash
cp .env.dist .env             # first time only
docker compose up -d          # start Moodle + MariaDB
docker compose logs -f moodle # follow the install/seed progress
```

Once the install finishes (~1 minute), `POST_CONFIGURE_COMMANDS` automatically
runs `scripts/setup_demo.php`, which seeds the same content as the playground
badge above:

```
=== mod_exelearning · setup_demo ===
  · Categoría creada: Demo eXeLearning (id=2)
  · Curso creado: EXEDEMO (id=2)
  · Profesor:    teacher_demo / Demo!2026
  · Estudiantes: alumno1, alumno2 / Demo!2026
  · Actividad creada: Actividad evaluable (demo) (cmid=2, instance=1)
```

Moodle is then reachable at <http://localhost> with admin `user / 1234` (override
via `.env`).

To regenerate the demo without reinstalling Moodle:

    $ docker compose exec moodle php /var/www/html/mod/exelearning/scripts/setup_demo.php

To tear down keeping data: `docker compose down`. To wipe everything:
`docker compose down -v`.

## Configuration

Go to:

    {your/moodle/dirroot}/admin/settings.php?section=modsettingexelearning

All settings live on a single admin page (see
[DEC-0009](./research/decisiones/adr/DEC-0009-solo-editor-embebido.md) for the
rationale of dropping the eXeLearning Online integration — only the embedded
editor remains):

* **Embedded editor management** (inline widget): install / update / repair /
  uninstall the editor, downloading the latest release from GitHub
  (`exelearning/exelearning`) without leaving the settings page.
* **Styles**: upload eXeLearning style packages (`.zip`), enable/disable the
  editor's built-in styles, and optionally block users from importing styles
  bundled inside an `.elpx`. A dedicated _Styles_ admin page lists and manages
  them.

## Embedded editor management

The plugin supports two editor sources with the following precedence:

1. **Admin-installed** (moodledata): downloaded from GitHub Releases via the
   admin management page. Stored under `moodledata/mod_exelearning/embedded_editor/`.
2. **Bundled** (plugin): included in the plugin release ZIP at `dist/static/`.

An admin-installed version always takes precedence over the bundled version. If
neither source is available, the embedded editor cannot be used. The management
page requires the `moodle/site:config` and `mod/exelearning:manageembeddededitor`
capabilities.

## Gradebook behaviour

When a teacher uploads a `.elpx`, the plugin extracts the package and detects
gradable iDevices from `content.xml`. The current default emits:

* One overall column per activity (`itemnumber=0`).
* One column per detected gradable iDevice (`itemnumber=1..N`).

The gradebook columns model is configurable per activity (see
[DEC-0008](./research/decisiones/adr/DEC-0008-grade-aggregation-y-feedback.md)):
**overall only**, **per-iDevice only**, or **both** with the overall excluded
from the course total (default, avoids double counting).

Each submission is stored as an **attempt** (see
[DEC-0007](./research/decisiones/adr/DEC-0007-gestion-intentos.md)); the
gradebook value is the configured aggregation (highest / average / first / last
/ lowest). You can cap the number of attempts, let students review their past
attempts, and delete attempts from the teacher report (the grade is
recalculated). Completion can require a passing grade (SCORM-style, see
[DEC-0010](./research/decisiones/adr/DEC-0010-finalizacion-estilo-scorm.md)).

Grading runtime uses a SCORM 1.2 bridge: a small `window.API` shim installed by
`view.php` accepts `LMSSetValue` calls from the iDevice's bundled pipwerks
wrapper and forwards them to `track.php`, which calls Moodle's `grade_update()`.
xAPI support via `core_xapi` is on the roadmap.

## Roadmap

See `research/decisiones/adr/` for the full set of ADRs. Highlights:

* [DEC-0003](./research/decisiones/adr/DEC-0003-estandar-tracking-y-multi-grade-items.md) — tracking standard and multi-grade-items (xAPI: proposed).
* [DEC-0006](./research/decisiones/adr/DEC-0006-modos-preview-grading.md) — preview vs grading modes (done).
* [DEC-0007](./research/decisiones/adr/DEC-0007-gestion-intentos.md) — multi-attempt support (done).
* [DEC-0008](./research/decisiones/adr/DEC-0008-grade-aggregation-y-feedback.md) — overall vs per-iDevice grade aggregation (done).
* [DEC-0009](./research/decisiones/adr/DEC-0009-solo-editor-embebido.md) — embedded editor only, no eXeLearning Online (done).
* [DEC-0010](./research/decisiones/adr/DEC-0010-finalizacion-estilo-scorm.md) — SCORM-style completion by passing grade (done).

## Development

For development setup, build instructions, and contributing guidelines, see
[DEVELOPMENT.md](DEVELOPMENT.md) (forthcoming). The full research history,
including ADRs, source fixtures and analysis notes, lives under
[`research/`](./research/).

## Support

Please report bugs and feature requests on the GitHub issue tracker:
<https://github.com/ateeducacion/mod_exelearning/issues>

## About

Copyright 2026:
ATE (Área de Tecnología Educativa) /
Centro Nacional de Desarrollo Curricular en Sistemas no Propietarios (CeDeC) /
INTEF (Instituto Nacional de Tecnologías Educativas y de Formación del
Profesorado).

### License

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should receive a copy of the GNU General Public License
along with this program.
