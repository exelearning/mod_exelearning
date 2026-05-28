---
id: FTE-006
titulo: "Moodle Grade API (`lib/gradelib.php`)"
categoria: api-moodle
version_consultada: "Moodle 4.x"
enlaces_oficiales:
  - https://moodledev.io/docs/apis/subsystems/grades
context7:
  library_id: "[PENDIENTE: context7 — /websites/moodledev_io o similar]"
  query: "[PENDIENTE: context7]"
  fecha: null
  version_devuelta: "[PENDIENTE: context7]"
fecha_consulta: 2026-05-28
relevancia_para_mod_exelearning: "API canónica para empujar notas al gradebook. El parámetro `itemnumber` es la clave de la multi-itemnumber strategy."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Qué es

Conjunto de funciones globales y clases (`grade_item`, `grade_grade`, `grade_category`)
que gestionan el libro de calificaciones.

## Conceptos clave

- **grade_item**: registro en `mdl_grade_items` clave `(itemtype, itemmodule, iteminstance, itemnumber)`.
- **itemnumber**:
  - `0` ⇒ grade item canónico, sincronizado con `course_modules`.
  - `1, 2, …` ⇒ items adicionales para la misma instancia.

## API / Puntos de extensión relevantes

```php
grade_update(
    $source,        // p.ej. 'mod/exelearning'
    $courseid,
    $itemtype,      // 'mod'
    $itemmodule,    // 'exelearning'
    $iteminstance,  // id de la instancia
    $itemnumber,    // 0..N
    $grades = null, // null ⇒ sólo upsert del item
    $itemdetails = null,  // ['itemname', 'gradetype', 'grademax', 'grademin', 'deleted'=>true, ...]
    $isbulkupdate = false
);
```

Borrado de un item adicional: `$itemdetails = ['deleted' => true]`.

## Soporte para multi-grade-items

Sí, vía `itemnumber > 0`. Patrón confirmado en `public/mod/workshop/lib.php`
(submission + assessment).

## Implementaciones de referencia consultadas

- REPO-004 — `public/mod/workshop/lib.php:1110-1130`
- REPO-004 — `public/lib/gradelib.php`

## Riesgos / Limitaciones

- Si la lista de items calificables del paquete eXeLearning cambia tras un upload, hay
  que decidir política: ¿reasignar `itemnumber`? ¿marcar viejos como `deleted`?
  ⇒ tema para DEC futura.

## Preguntas abiertas

- Política de re-upload: preservar `itemnumber` por `objectid` estable vs renumerar.
