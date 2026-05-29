---
id: FTE-001
titulo: "SCORM 1.2"
categoria: estandar
version_consultada: "1.2"
enlaces_oficiales:
  - https://adlnet.gov/projects/scorm/
  - https://scorm.com/scorm-explained/technical-scorm/
context7:
  library_id: /jcputney/scorm-again
  query: "SCORM 1.2 runtime API LMSInitialize LMSSetValue cmi.core.score.raw lesson_status, single score per SCO"
  fecha: 2026-05-29
  version_devuelta: "scorm-again (runtime JS de referencia, High, 1887 snippets). NOTA: es una IMPLEMENTACIÓN del runtime, no el spec normativo; la norma autoritativa es ADL (ver enlaces_oficiales)."
fecha_consulta: 2026-05-29
relevancia_para_mod_exelearning: "Formato más común exportado por eXeLearning; soportado por mod_exescorm. Candidato para tracking si el plugin opta por la familia SCORM."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Qué es

Conjunto de especificaciones de ADL para empaquetar contenido formativo
interoperable con LMS. Define un manifest (`imsmanifest.xml`), un data model (CMI) y
una API JS (`LMSInitialize`, `LMSGetValue`, `LMSSetValue`, `LMSCommit`, `LMSFinish`).

## Conceptos clave

- **SCO** (Sharable Content Object): unidad lanzable.
- **Organization**: árbol de SCOs (= TOC navegable).
- **CMI data model**: `cmi.core.score.raw`, `cmi.core.lesson_status`, etc.

## API / Puntos de extensión relevantes

- API JS expuesta por el LMS en `window.parent.API` (descubrible por el SCO).
- Persistencia vía `LMSSetValue` + `LMSCommit`.

## Soporte para multi-grade-items

**Nativo: no.** SCORM 1.2 expone un único `cmi.core.score.raw` por SCO. Para múltiples
grade items en el gradebook Moodle habría que mapear `1 SCO → 1 itemnumber`
implementando a mano la repartición; el estándar no lo contempla.

## Soporte para navegación/sidebar

Sí, vía `organizations` en el manifest (jerarquía de items). El TOC se reconstruye
del lado del LMS (cf. `mod_scorm` / `mod_exescorm`).

## Implementaciones de referencia consultadas

- REPO-001 — mod_exescorm
- REPO-004 — `public/mod/scorm/` (core)

## Riesgos / Limitaciones

- Modelo CMI corto (255 chars) y estructura plana.
- Sin secuenciación avanzada (eso lo cubre SCORM 2004).
- API JS basada en globales, propensa a frameworks modernos rompiéndola.

## Preguntas abiertas

- ¿La versión actual de eXeLearning exporta SCO por iDevice o un único SCO con varios quizzes embebidos? — PREG-001.
