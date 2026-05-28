---
id: FTE-003
titulo: "xAPI (Experience API / Tin Can)"
categoria: estandar
version_consultada: "1.0.3"
enlaces_oficiales:
  - https://adlnet.gov/projects/xapi/
  - https://github.com/adlnet/xAPI-Spec
context7:
  library_id: "[PENDIENTE: context7]"
  query: "[PENDIENTE: context7]"
  fecha: null
  version_devuelta: "[PENDIENTE: context7]"
fecha_consulta: 2026-05-28
relevancia_para_mod_exelearning: "Estándar granular para statements (actor, verb, object, result). Moodle lo soporta nativamente vía core_xapi. Candidato principal para tracking de items independientes."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Qué es

Especificación ADL que reemplaza el modelo CMI por **statements** firmados enviados a
un Learning Record Store (LRS). Forma: `actor + verb + object [+ result + context]`.

## Conceptos clave

- **Statement**: hecho registrado.
- **Activity ID**: IRI estable del object.
- **Verb**: IRI (`http://adlnet.gov/expapi/verbs/answered`, `completed`, …).
- **Result**: contiene `score.scaled`, `score.raw`, `success`, `completion`.

## API / Puntos de extensión relevantes

En Moodle:
- `core_xapi\handler` (abstract) — extender para implementar consumidor.
- `statement_to_event(statement $s): ?core\event\base`.
- Endpoints REST: `core_xapi_post_statement`, `core_xapi_post_state`, etc.
- Tabla `core_xapi_state`.

## Soporte para multi-grade-items

**Excelente.** Cada statement puede llevar un `object.id` distinto. El handler de
`mod_exelearning` filtra por `object.id` y resuelve qué `itemnumber` de gradebook
afectar. Patrón observado en `mod_h5pactivity`.

## Soporte para navegación/sidebar

Ortogonal: xAPI no define UI. La sidebar la sigue sirviendo el paquete eXeLearning
nativo; xAPI sólo es el canal de tracking.

## Implementaciones de referencia consultadas

- REPO-004 — `public/lib/xapi/`, `public/mod/h5pactivity/classes/xapi/handler.php`.

## Riesgos / Limitaciones

- El LRS por defecto en Moodle no es completo (es estado mínimo); para análisis
  avanzados habría que externalizar a un LRS real.
- Verbo/object IRIs deben ser estables: requiere convención con eXeLearning.

## Preguntas abiertas

- ¿eXeLearning emite statements xAPI hoy en sus paquetes? — PREG-001 / PREG-002.
- ¿Conviene definir un perfil xAPI propio (`exelearning.net/xapi/profile/v1`)?
