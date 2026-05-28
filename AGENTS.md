# AGENTS.md (raíz)

Este repositorio está en **fase 0 (investigación)**. **No existe código del plugin
`mod_exelearning` todavía** — no inventarlo.

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
