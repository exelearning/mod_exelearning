# Licencias — mod_exelearning

`mod_exelearning` se distribuirá bajo **GPL-3.0-or-later** (decisión registrada en
DEC-0002), compatible con Moodle.

## Dependencias y su licencia

| Origen | Licencia esperada | Estado |
|---|---|---|
| Moodle core (subsistemas reutilizados) | GPL-3.0-or-later | OK |
| `core_xapi` | GPL-3.0-or-later | OK |
| Patrones tomados de `mod_workshop`, `mod_h5pactivity`, `mod_scorm` | GPL-3.0-or-later | OK |
| Patrones tomados de `mod_exescorm`, `mod_exeweb` | "[PENDIENTE: confirmar en cabeceras]" | Pendiente |
| `wp-exelearning` (inspiración) | "[PENDIENTE: GPL-2.0-or-later esperada]" | Pendiente |
| Paquetes publicados por eXeLearning (contenido del usuario) | Variable (autor) | No aplica al plugin |
| jQuery o framework JS del paquete | "[PENDIENTE: confirmar en EXP-001]" | Pendiente |

## Reglas

- Cualquier dependencia con licencia GPL-incompatible (AGPL, GPLv2-only, comercial)
  bloquea su inclusión.
- Cualquier contribución se acepta sólo si el autor declara compatibilidad con
  GPL-3.0-or-later.

## Pendientes

- [PENDIENTE] Auditoría formal de licencias de `mod_exescorm`, `mod_exeweb` y
  `wp-exelearning` en TAREA-001 (commits SHA aún no registrados).
- [PENDIENTE] Política sobre librerías JS del propio plugin (Mustache, AMD vendor).
