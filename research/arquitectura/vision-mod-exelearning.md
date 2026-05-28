---
titulo: "[HIPOTESIS] Visión arquitectónica de mod_exelearning"
fecha: 2026-05-28
estado: hipotesis
relacionados:
  - DEC-0003
  - AN-001
  - AN-002
  - AN-003
  - AN-004
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

> **Aviso**: este documento es una hipótesis de trabajo. Sólo se materializa cuando
> los ADRs correspondientes pasen a `Aceptada`. No es código aprobado.

## Capas propuestas

```
┌──────────────────────────────────────────────────────────────┐
│  Moodle gradebook  ◀── grade_update(itemnumber=0..N)         │
└─────▲────────────────────────────────────────────────────────┘
      │
┌─────┴──────────── classes/local/grader.php ──────────────────┐
│  exelearning_grade_item_update($instance, $grades_por_item)  │
└─────▲────────────────────────────────────────────────────────┘
      │
┌─────┴──────── classes/local/attempt.php ─────────────────────┐
│  Persistencia en mdl_exelearning_attempt                     │
└─────▲────────────────────────────────────────────────────────┘
      │
┌─────┴──────── classes/xapi/handler.php (extends core_xapi) ──┐
│  statement_to_event() · routing object.id → itemnumber       │
└─────▲────────────────────────────────────────────────────────┘
      │  REST core_xapi_post_statement
┌─────┴────────── amd/src/xapi_shim.js (cliente) ──────────────┐
│  Recoge eventos del iframe vía postMessage y los convierte   │
│  en statements firmados                                      │
└─────▲────────────────────────────────────────────────────────┘
      │  postMessage('xapi:statement', ...)
┌─────┴───── iframe paquete eXeLearning publicado ─────────────┐
│  index.html + lib/exe_player.js + styles/                    │
│  (sidebar nativa preservada)                                 │
└──────────────────────────────────────────────────────────────┘
```

## Contratos

### `mdl_exelearning` (instancia)

`id, course, name, intro, introformat, package_fileid, package_revision,
publish_format, manifest_json, grade_aggregation_method, timecreated, timemodified`

### `mdl_exelearning_grade_item` (mapa item → itemnumber)

`id, exelearningid, itemnumber, objectid, name, maxscore, grademin, deleted`

- `objectid` = IRI estable del iDevice tomado del manifest del paquete.
- `itemnumber` es monotónico y nunca se reutiliza, ni siquiera si el item se elimina.

### `mdl_exelearning_attempt` (persistencia de statements)

`id, exelearningid, userid, gradeitemid, scaled, raw, success, completion,
statement_id, timecreated`

### Contrato `postMessage` entre iframe y shim

```json
{
  "type": "xapi:statement",
  "statement": { /* xAPI 1.0.3 */ }
}
```

## Decisiones pendientes que esta visión asume

- DEC-0003 acepta xAPI como canal (PROPUESTA hoy).
- DEC-0004 acepta el esquema de tablas anterior.
- DEC-0005 acepta paquete-publicado-only en v1 (proyecto `.elp` en fase posterior).
- DEC-0006 acepta reutilizar la sub-app `exelearning/` del editor embebido o,
  alternativamente, externalizar el editor a eXeLearning Online.

## Riesgos arquitectónicos

- `postMessage` desde el iframe requiere autenticación del LMS (sesión Moodle). Hay
  que diseñar el flujo de sesión/token sin exponer secretos en el paquete.
- Re-uploads que cambian la lista de items necesitan política clara para no romper
  notas históricas.

## Próximos pasos

1. Validar EXP-001 (sidebar + identificadores).
2. Validar EXP-002 (multi-grade-items en POC).
3. Cerrar DEC-0003 y abrir DEC-0004/0005/0006.
