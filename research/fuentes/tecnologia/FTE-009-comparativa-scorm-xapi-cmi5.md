---
id: FTE-009
titulo: "Comparativa de estándares e-learning: SCORM vs xAPI vs cmi5 (y dónde encaja mod_exelearning)"
categoria: estandar-comparativa
version_consultada: "2026"
enlaces_oficiales:
  - https://xapi.com/cmi5/comparison-of-scorm-xapi-and-cmi5/
  - https://aicc.github.io/CMI-5_Spec_Current/tincan/
  - https://aicc.github.io/CMI-5_Spec_Current/SCORM/
  - https://www.ispringsolutions.com/blog/elearning-standards
  - https://www.ispring.es/blog/aicc-vs-scorm-vs-xapi-vs-cmi5
context7:
  library_id: /adlnet/xapi-spec
  query: "cmi5 profile of xAPI, moveOn masteryScore AU assignable unit, session registration, verb sequence"
  fecha: 2026-05-29
  version_devuelta: "adlnet/xapi-spec + aicc.github.io/CMI-5_Spec_Current (consulta web 2026-05-29)"
fecha_consulta: 2026-05-29
relevancia_para_mod_exelearning: "Fundamenta la hoja de ruta de tracking (DEC-0003 vigente SCORM 1.2, DEC-0014 xAPI). Sirve para decidir si una futura capa xAPI/cmi5 merece la pena y qué exige de eXeLearning."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Resumen ejecutivo

Tres estándares de tracking en juego (AICC queda descartado: legado de 1988, grupo
disuelto en 2014 — iSpring). En una frase:

- **SCORM (1.2 / 2004)**: instrucciones de lanzamiento + criterios de finalización
  normalizados, pero contenido **atado al paquete y al navegador/mismo dominio**, y
  datos limitados a elementos predefinidos que viven **en el LMS**. Soporte casi
  universal. Es lo que `mod_exelearning` usa hoy (vía puente SCORM 1.2, DEC-0003).
- **xAPI (Experience API / Tin Can)**: statements `actor-verb-object[-result]`
  flexibles enviados a un **LRS**; rastrea cualquier cosa (móvil, offline, juegos),
  datos portables. **Pero** "pelado" carece de reglas estandarizadas de
  lanzamiento/finalización y vocabulario → difícil de explotar de forma
  interoperable.
- **cmi5**: **perfil de xAPI** (no un subconjunto) para el caso de uso
  "**el LMS lanza el contenido**". Combina la **estructura de SCORM** con la
  **trazabilidad de xAPI**. Adopción creciente; "todos los authoring tools
  modernos ya lo soportan" (iSpring).

## Citas clave (autoritativas)

- cmi5 ⊂ xAPI: «ADL actively maintains cmi5 as an xAPI profile that defines the
  "LMS launches content" use case. cmi5 is implemented using the Experience API
  (xAPI) as the content-to-LMS communication layer». (aicc.github.io/tincan)
- Problema del xAPI pelado: «While xAPI Statements can capture all kinds of data,
  it becomes challenging for systems to extrapolate and analyze that data in a
  meaningful way without a defined vocabulary and instructions». (xapi.com)
- cmi5 como puente: «cmi5 bridges that gap by having xAPI's tracking flexibility
  while maintaining the structure of SCORM». (xapi.com)
- SCORM vs cmi5 (ubicación de datos): SCORM «all content is required to be located
  in the package... same domain as the LMS»; cmi5 «content does not have to be in
  the package and can be located on any domain/local device» y los datos van a un
  **LRS** vía REST/JSON. (aicc.github.io/SCORM)

## Qué añade cmi5 sobre xAPI pelado

- **AU (Assignable Units)**: 1..1000 por curso, jerarquía lanzable con criterios de
  progresión.
- **moveOn** + **masteryScore** por AU → finalización/aprobado interoperable.
- **session** + **Context.registration** en cada statement → reporting normalizado.
- **Secuencia de verbos definida** en una sesión de AU (launched, initialized,
  passed/failed, completed, terminated…).
- **"fetch URL"** (servicio de token) para autenticación segura del AU contra el LRS.

## Tabla de decisión (sintetizada)

| Criterio | SCORM 1.2/2004 | xAPI | cmi5 |
|---|---|---|---|
| Modelo | contenido en paquete, datos al LMS | statements a LRS | xAPI + reglas de launch/finalización |
| Granularidad de datos | predefinida (CMI) | libre (verbos/IRIs) | libre + vocabulario normalizado |
| Offline / móvil / no-navegador | no | sí | sí |
| Finalización interoperable | sí | no (sin perfil) | sí (moveOn/masteryScore) |
| Soporte LMS | casi universal | medio | creciente |
| Lanzamiento "LMS→contenido" | sí | no definido | sí (definido) |

## Implicación para mod_exelearning (ver DEC-0014 y AN-010)

- Hoy el bridge es **SCORM 1.2** porque eXeLearning **no emite xAPI** (FTE-007).
  Funciona y da multi-itemnumber con un shim (ver AN-010 / glosario "shim").
- Para una capa xAPI nativa harían falta cambios **aguas arriba en eXeLearning**
  (emitir statements por iDevice), no solo en el plugin. cmi5 añadiría además el
  modelo de lanzamiento (AU/fetch URL), que **no encaja** con el caso actual
  (contenido servido embebido por Moodle, no "lanzado" a un LRS externo).
- Conclusión preliminar: **xAPI puro** (consumido por `core_xapi` de Moodle) es el
  objetivo natural si upstream emite statements; **cmi5 es probablemente excesivo**
  para el caso "recurso embebido en Moodle" (su valor está en LRS externos y
  catálogos de cursos lanzables). Se detalla en DEC-0014 / DEC-0015.
