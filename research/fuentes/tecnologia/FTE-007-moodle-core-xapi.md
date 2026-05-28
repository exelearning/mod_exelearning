---
id: FTE-007
titulo: "Moodle core_xapi"
categoria: api-moodle
version_consultada: "Moodle 4.x"
enlaces_oficiales:
  - https://moodledev.io/docs/apis/subsystems/xapi
context7:
  library_id: "[PENDIENTE: context7]"
  query: "[PENDIENTE: context7]"
  fecha: null
  version_devuelta: "[PENDIENTE: context7]"
fecha_consulta: 2026-05-28
relevancia_para_mod_exelearning: "Pieza central si DEC-0003 elige xAPI: handler que mod_exelearning extiende para recibir statements desde el iframe del paquete."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Qué es

Subsistema Moodle que estandariza la recepción y persistencia de xAPI statements desde
contenido embebido o externo.

## Conceptos clave

- `core_xapi\handler` (abstract base).
- Endpoints REST internos: `core_xapi_post_statement`, `core_xapi_post_state`, etc.
- Tabla `core_xapi_state` para persistir estados.
- `core_xapi\local\statement` (objeto statement validado).

## API / Puntos de extensión relevantes

Para un plugin componente `mod_exelearning`:

```
classes/xapi/handler.php
  namespace mod_exelearning\xapi;
  class handler extends \core_xapi\handler {
      protected function statement_to_event(local\statement $statement): ?\core\event\base { ... }
      protected function supports_group_actors(): bool { return false; }
  }
```

El handler:
- Filtra verbos relevantes (`answered`, `completed`).
- Parsea `object.id` para mapear a un `itemnumber` interno.
- Persiste interacción en tabla propia (`mdl_exelearning_attempt`).
- Llama `exelearning_grade_item_update(...)` para empujar al gradebook.

## Soporte para multi-grade-items

Indirecto pero excelente: el componente decide a qué `itemnumber` afecta cada
statement.

## Implementaciones de referencia consultadas

- REPO-004 — `public/lib/xapi/classes/handler.php`
- REPO-004 — `public/mod/h5pactivity/classes/xapi/handler.php`
- REPO-004 — `public/mod/h5pactivity/classes/local/grader.php`

## Riesgos / Limitaciones

- Requiere autenticación (Moodle session o token); CSRF debe gestionarse en el shim JS
  que el paquete usará para emitir statements.
- Versión mínima de Moodle: comprobar si `core_xapi` está en la versión target.

## Preguntas abiertas

- Versión mínima de Moodle objetivo. PREG futura.
