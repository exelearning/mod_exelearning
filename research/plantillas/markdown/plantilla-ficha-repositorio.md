---
id: REPO-NNN
titulo: "<nombre del repositorio>"
tipo: moodle-plugin   # moodle-plugin | wordpress-plugin | lms-core | authoring-tool | libreria | otro
ruta_local: /ruta/absoluta/al/clon
url_upstream: https://...
commit_consultado: <sha o tag>
fecha_consulta: YYYY-MM-DD
licencia: GPL-3.0-or-later
rol_para_mod_exelearning: "<una frase>"
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Hechos

- Componente Moodle/WP: `<frankenstyle o id>`
- Versión declarada: `<a partir de version.php / plugin.php>`
- Requisitos: Moodle X.Y / PHP X.Y / WP X.Y

## Rutas clave

| Ruta | Rol |
|---|---|
| `lib.php` | … |
| `mod_form.php` | … |
| `db/install.xml` | … |

## Contratos relevantes

<Funciones públicas, hooks, capabilities, eventos.>

## Modelo de datos

<Tablas y campos significativos para el dominio de mod_exelearning.>

## Capacidades respecto a mod_exelearning

- Sidebar/TOC: …
- Grading: …
- Tracking (SCORM/xAPI/cmi5/LTI): …
- Editor embebido: …
- Backup/restore: …

## Riesgos / Limitaciones

- …

## Preguntas abiertas

- PREG-NNN: …
