# AGENTS.md (raíz)

Este repositorio acaba de salir de **fase 0 (investigación)** y entra en
**fase 1 (arranque)**. Existe un **esqueleto mínimo funcional** del plugin
(`version.php`, `lib.php`, `mod_form.php`, `view.php`, `index.php`,
`db/install.xml`, `db/access.php`, `lang/en/exelearning.php`, `pix/`) suficiente
para que Moodle lo instale, pero la lógica real (iframe + sidebar +
multi-grade-items + xAPI + editor embebido) todavía no está implementada. No
inventarla sin un ADR aceptado en `research/decisiones/adr/`.

Las reglas operativas para cualquier agente que trabaje en este repositorio están en
[`research/AGENTS.md`](./research/AGENTS.md). Lee ese archivo antes de tocar nada.

Resumen rápido (no sustituye a `research/AGENTS.md`):

- Toda afirmación técnica requiere evidencia citable (repo + ruta + commit, doc oficial
  con fecha, o experimento reproducible).
- `research/status.yaml`, ADRs y diario son **append-only**.
- Idioma: español (salvo IDs, APIs, nombres propios).
- No vendorar repos externos (`mod_exescorm`, `mod_exeweb`, `wp-exelearning`, `moodle`,
  `exelearning`); enlazar por ruta + commit.
- Marcar interpretaciones con `[INTERPRETACION]`, hipótesis con `[HIPOTESIS]`, huecos
  con `[PENDIENTE]`.
