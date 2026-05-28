# experimentos/

POCs y mediciones reproducibles. Cada experimento es un `EXP-NNN.yaml` con comando
exacto, commit, entorno, métricas y limitaciones (ver
[`../plantillas/checklists/checklist-experimento-reproducible.md`](../plantillas/checklists/checklist-experimento-reproducible.md)).

- `resultados/` — `EXP-NNN.yaml`.
- `evidencias/EXP-NNN/` — capturas, logs, archivos exportados (se crea bajo demanda y
  no se commitea si pesa).

Lista provisional (planificada en backlog):

- `EXP-001` — Estructura del paquete eXeLearning publicado (TAREA-003).
- `EXP-002` — POC multi-grade-items en Moodle 4.x (TAREA-004).
