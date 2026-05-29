---
id: DEC-0015
titulo: "¿Merece la pena la multicalificación (N grade items por iDevice)? Justificación, DAFO y comparativa"
estado: Aceptada
fecha: 2026-05-29
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-001
  - REPO-002
  - REPO-004
  - REPO-005
  - FTE-001
  - FTE-006
  - FTE-007
  - FTE-008
relacionados:
  - DEC-0003
  - DEC-0007
  - DEC-0008
  - DEC-0010
  - DEC-0014
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Contexto y motivación

`mod_exelearning` nace para cubrir un hueco entre dos plugins existentes de ATE:

- **`mod_exeweb`** sirve un paquete eXeLearning v4 dentro de Moodle con su
  **sidebar nativa** (técnica iframe), pero **no califica**: es un visor de
  recurso, sin libro de calificaciones, intentos ni finalización por nota.
- **`mod_exescorm`** sí califica, pero como **una única nota** por paquete
  (el `cmi.core.score.raw` agregado de SCORM), perdiendo el detalle por
  ejercicio; además presenta el contenido con el TOC de SCORM, no con la
  sidebar nativa de eXeLearning.

La necesidad pedagógica de ATE es **evaluar cada ejercicio (iDevice) calificable
de un mismo recurso eXeLearning por separado**, manteniendo la experiencia de
navegación nativa. Eso exige **N columnas en el gradebook (una por iDevice)** en
lugar de una sola — la *multicalificación*. `mod_exelearning` fusiona la sidebar
de `mod_exeweb` con un modelo de calificación que va más allá de `mod_exescorm`.

Este ADR responde: **¿merece la pena esa multicalificación, dado su coste?**

## Cómo se consigue (resumen técnico)

El plugin extrae `content.xml` del `.elpx`, detecta los iDevices calificables
(`GRADABLE_IDEVICE_TYPES`), mapea cada `odeIdeviceId` estable a un `itemnumber`
y registra N grade items con `grade_update(..., itemnumber=$n, ...)` (FTE-006).
El tracking llega por un **puente SCORM 1.2** (shim `window.API` + pipwerks
inyectado) que `track.php` parsea y reparte por `itemnumber` (DEC-0003). Los
intentos viven en una tabla propia con `grademethod` (DEC-0007) y la
finalización es la nativa `completionpassgrade` (DEC-0010).

## Ventajas

### Frente a `mod_exeweb`
- Añade **calificación** (exeweb no tiene ninguna): libro, intentos, finalización.
- Columnas **por iDevice**, no solo presencia del recurso.
- Conserva **íntegra** la sidebar nativa (misma técnica iframe).

### Frente a `mod_exescorm` (y `mod_scorm` core)
- **N columnas vs 1 nota**: el profesor ve el rendimiento por ejercicio dentro
  del mismo recurso, no un agregado opaco.
- **Sidebar nativa** de eXeLearning en lugar del TOC de SCORM (mejor UX y
  coherencia con cómo se diseñó el contenido).
- Modelo de **intentos propio** con método configurable (highest/average/…)
  y `grademodel` (por iDevice / global) — DEC-0007, DEC-0008.
- No obliga a re-exportar a SCORM: trabaja sobre el `.elpx` v4 directamente.

## Inconvenientes y complejidad

**Es más complejo que ambos** (exeweb no califica; exescorm da 1 nota). El coste:

- Parsear `content.xml` y mantener la heurística de detección de iDevices
  calificables frente a la evolución del formato de eXeLearning (FTE-008).
- Mapear `odeIdeviceId → itemnumber` de forma **idempotente** y sobrevivir a
  re-uploads; la **(in)estabilidad de ids** al importar/exportar es un riesgo
  real (**RIE-006**), mitigado pero dependiente de upstream.
- El puente **SCORM 1.2 es un shim**, no tracking nativo: eXeLearning no emite
  xAPI hoy (FTE-007/DEC-0014). Es pragmático y funciona, pero es deuda técnica.
- Detalles frágiles ya domados: `itemnumber_mapping` (MAX=100), inyección
  pipwerks, self-heal de subidas programáticas, re-sync tras editar.
- Riesgo de **saturar el libro** con muchas columnas (mitigado: `grademodel`
  por defecto por iDevice, conmutable a global — DEC-0008 rev.).

**¿Más simple o más complicado?** Más complicado en implementación y
mantenimiento; pero la complejidad **compra valor pedagógico real** (evaluación
granular) que ninguna alternativa ofrece con sidebar nativa.

## DAFO

**Debilidades (internas)**
- Puente SCORM 1.2 (shim) en vez de xAPI nativo; mayor superficie de código.
- Dependencia del formato `content.xml` y de la estabilidad de `odeIdeviceId`.
- Tope `MAX=100` iDevices; saturación potencial del gradebook.

**Amenazas (externas)**
- Cambios del export de eXeLearning rompen la detección.
- Reasignación de ids upstream al importar (RIE-006).
- Cambios de la Grade API de Moodle; presión a futuro contra SCORM 1.2.

**Fortalezas (internas)**
- **Único** que combina sidebar nativa eXe + calificación **granular por iDevice**.
- Reutiliza piezas probadas (iframe de exeweb, puente SCORM, patrón
  multi-itemnumber del core, modelo de intentos tipo h5pactivity).
- Basado en estándar vigente (SCORM 1.2) + intentos + finalización nativos.
- Verificado end-to-end (Docker + navegador) y CI verde en Moodle 4.5/5.0/5.1.

**Oportunidades (externas)**
- Hoja de ruta **xAPI nativo** (DEC-0014) cuando eXeLearning emita statements
  → analítica de aprendizaje fina vía LRS.
- Impulso de eXeLearning v4 e interés institucional (ATE/INTEF).
- Posible aportación upstream (ids estables, manifiesto de gradeitems).

## Comparativa

| Dimensión | mod_exeweb | mod_exescorm | mod_scorm (core) | mod_h5pactivity | **mod_exelearning** |
|---|---|---|---|---|---|
| Calificación en libro | ninguna | 1 nota (agregada) | 1 nota | 1 columna | **N columnas (1/iDevice) + global opcional** |
| Granularidad | — | baja | baja | media (1 col, detalle interno) | **alta** |
| Sidebar nativa eXe | sí | no (TOC SCORM) | no | n/a | **sí (iframe)** |
| Tracking | no | SCORM 1.2/2004 | SCORM 1.2/2004 | **xAPI nativo (core_xapi)** | SCORM 1.2 (shim) → multi-itemnumber |
| Intentos | no | sí | sí | sí | **sí (tabla propia + grademethod)** |
| Finalización por nota | no | sí | sí | sí | **sí (completionpassgrade)** |
| Edición in-situ | sí (editor embebido) | parcial | no | editor H5P externo | **sí (editor embebido)** |
| Formato de entrada | `.elpx` v4 | `.elp`/`.elpx` | `.zip` SCORM | `.h5p` | `.elpx` v4 |
| Complejidad de impl. | baja | media | media | media | **alta** |

Matiz sobre **H5P**: `mod_h5pactivity` es el modelo "moderno" (xAPI nativo, gran
detalle de interacción interno), pero **expone una sola columna** en el libro.
La multicalificación de `mod_exelearning` es, de hecho, **más granular en el
gradebook** que H5P — ese es el diferencial. El precio es no tener xAPI nativo
(todavía).

## Decisión / Veredicto

**Sí merece la pena, con matices.**

- **Sí**, porque ningún plugin existente ofrece simultáneamente **sidebar nativa
  de eXeLearning + calificación granular por iDevice**: `mod_exeweb` no califica;
  `mod_exescorm`/`mod_scorm` dan una sola nota; `mod_h5pactivity` da una columna.
  El valor pedagógico (evaluar cada ejercicio dentro de un recurso) es real y
  demandado por ATE, y está implementado y verificado.
- **Con matices**: la complejidad y la dependencia del shim SCORM 1.2 + formato
  `content.xml` son deuda técnica reconocida. La evolución sana es **xAPI nativo**
  (DEC-0014) cuando eXeLearning emita statements; mientras, el enfoque actual es
  pragmático, funciona y se apoya en estándares vigentes.

En resumen: la multicalificación es la **razón de ser** del plugin y justifica
su coste; el camino de reducción de deuda está trazado (DEC-0014) y condicionado
a upstream.

## Consecuencias

- Se mantiene la arquitectura actual (multi-itemnumber + puente SCORM 1.2).
- Se prioriza domar la fragilidad conocida (RIE-006, detección) sobre rehacer el
  canal de tracking, hasta que xAPI nativo sea viable.
- Este ADR es la base del informe ejecutivo PDF (TAREA-007).
