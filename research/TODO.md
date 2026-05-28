# TODO inmediato

Lista humana de acciones pendientes tras el bootstrap. La fuente de verdad operativa
sigue siendo `status.yaml` + `tareas/backlog/`.

## Fase 0 (cierre)

- [x] Crear estructura de carpetas
- [x] Plantillas (MD/YAML/checklists)
- [x] Schemas
- [x] Fichas REPO-001..REPO-005
- [x] Fichas FTE-001..FTE-008 (estructura; consultas Context7 pendientes)
- [x] Notas AN-001..AN-004
- [x] Matriz `matriz-estandar-tracking.yaml`
- [x] ADRs DEC-0001..DEC-0003 (DEC-0003 en estado Propuesta)
- [x] Tareas TAREA-001..TAREA-005
- [x] Preguntas PREG-001..PREG-002
- [x] Diario 2026-05-28
- [x] Tools (`build_indexes.py`, `test_schema_validation.py`)
- [x] Dashboard `index.html`

## Próximo turno (fase 0.1)

- [ ] TAREA-002: completar FTE-001..FTE-008 con Context7 (queries reales + fechas).
- [ ] TAREA-003: ejecutar EXP-001 (descomprimir un paquete eXeLearning publicado y
      documentar la estructura del sidebar y los puntos de inyección xAPI).
- [ ] TAREA-004: ejecutar EXP-002 (POC de multi-grade-items en una instalación de
      Moodle 4.x — registrar 2 grade items desde una actividad de prueba).
- [ ] TAREA-005: cerrar DEC-0003 con matriz cuantificada (puntuaciones, no rangos
      cualitativos).

## Antes de empezar a programar el plugin

- [ ] DEC-0004: layout de tablas (`mdl_exelearning`, `mdl_exelearning_track`,
      `mdl_exelearning_grade_item`).
- [ ] DEC-0005: estrategia de detección de items calificables en el paquete
      (manifiesto adicional vs heurística sobre HTML/JS publicado).
- [ ] DEC-0006: integración con editor embebido (reutilizar `exelearning/` de
      `mod_exescorm`/`mod_exeweb` vs subárbol nuevo).
