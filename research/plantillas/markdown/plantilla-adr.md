---
id: DEC-NNNN
titulo: "<Título conciso de la decisión>"
estado: Propuesta   # Propuesta | Aceptada | Rechazada | Superseded
fecha: YYYY-MM-DD
agentes:
  - <nombre o handle>
fuentes:
  - REPO-NNN
  - FTE-NNN
experimentos:
  - EXP-NNN
# supersede: DEC-NNNN   # sólo si esta ADR reemplaza otra
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Contexto

<Por qué surge esta decisión, qué problema o necesidad la motiva.>

## Problema

<Enunciado preciso del problema técnico.>

## Opciones consideradas

1. **Opción A** — descripción, ventajas, inconvenientes, evidencias.
2. **Opción B** — …
3. **Opción C** — …

## Evidencia

<Citas verificables: REPO/FTE/AN/EXP. Mínimo una por opción.>

## Decisión

<Opción elegida y por qué.>

## Consecuencias

- Positivas: …
- Negativas / coste: …
- Cambios que dispara en otros ADRs o tareas: …

## Riesgos

- RIE-NNN: …

## Validación

<Cómo se comprobará en la práctica (experimento, tests, métricas).>

## Seguimiento

<Tareas que esta decisión abre o cierra.>
