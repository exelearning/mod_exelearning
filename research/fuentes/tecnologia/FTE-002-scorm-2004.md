---
id: FTE-002
titulo: "SCORM 2004 (4th Edition)"
categoria: estandar
version_consultada: "2004 4th Edition"
enlaces_oficiales:
  - https://adlnet.gov/projects/scorm/
  - https://scorm.com/scorm-explained/technical-scorm/scorm-2004-overview/
context7:
  library_id: "[PENDIENTE: context7]"
  query: "[PENDIENTE: context7]"
  fecha: null
  version_devuelta: "[PENDIENTE: context7]"
fecha_consulta: 2026-05-28
relevancia_para_mod_exelearning: "Alternativa a SCORM 1.2; soporta secuenciación y múltiples objetivos (cmi.objectives), lo que abre la puerta a varios sub-scores por SCO."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Qué es

Evolución de SCORM 1.2 con Sequencing & Navigation (IMS SS), soporte de
`cmi.objectives.n.score.scaled` y modelos enriquecidos.

## Conceptos clave

- `cmi.objectives.{n}` con `id`, `score`, `success_status`, `completion_status`.
- Secuenciación declarativa (IMS SS) en `imsmanifest.xml`.
- Múltiples intents/intentos manejables.

## API / Puntos de extensión relevantes

- API JS `API_1484_11.Initialize/GetValue/SetValue/Commit/Terminate`.

## Soporte para multi-grade-items

**Parcial.** `cmi.objectives` permite múltiples objetivos con score por SCO. Podría
mapearse `1 objective → 1 itemnumber` si eXeLearning publica un objective por iDevice
calificable. Requiere convención + cooperación del paquete.

## Soporte para navegación/sidebar

Sí, vía `organizations` (igual que 1.2) más Sequencing & Navigation.

## Implementaciones de referencia consultadas

- REPO-001 — mod_exescorm (datamodels/scorm_13.js)
- REPO-004 — `public/mod/scorm/`

## Riesgos / Limitaciones

- Secuenciación poco usada en la práctica; muchas implementaciones la ignoran.
- Soporte completo en Moodle es histórico pero conviene verificar contra versión target.

## Preguntas abiertas

- ¿eXeLearning publica `cmi.objectives.{n}.id` estables por iDevice? — PREG-001.
