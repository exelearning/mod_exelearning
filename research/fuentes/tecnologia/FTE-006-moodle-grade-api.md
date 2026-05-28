---
id: FTE-006
titulo: "Moodle Grade API (`lib/gradelib.php`)"
categoria: api-moodle
version_consultada: "Moodle 4.x"
enlaces_oficiales:
  - https://moodledev.io/docs/apis/subsystems/grades
context7:
  library_id: /websites/moodledev_io_5_2
  query: "grade_update function signature itemnumber multiple grade items workshop pattern grade_item_update activity module"
  fecha: 2026-05-28
  version_devuelta: "Moodle 5.2 dev docs"
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

## Moodle 5.x: vista de "Course overview" con multi-items

Evidencia Context7 (`/websites/moodledev_io_5_2` · 2026-05-28): a partir de Moodle 5.x,
los plugins con múltiples grade items **deben** implementar
`get_grade_item_names(array $items): array` en la clase de course overview, indexado
por `grade_item->id`, para que el bloque "Course overview" muestre las columnas.
Sin esto Moodle no sabe qué nombre dar a cada columna y oculta los items.

Plantilla (de moodledev.io):

```php
#[\Override]
protected function get_grade_item_names(array $items): array {
    $names = [];
    foreach ($items as $item) {
        $stridentifier = ($item->itemnumber == 0)
            ? 'submission_gradenoun'
            : 'assessment_gradenoun';
        $names[$item->id] = get_string($stridentifier, 'mod_YOURPLUGIN');
    }
    return $names;
}
```

Aplicado a `mod_exelearning`: la implementación deberá devolver el `itemname` por
`itemnumber` resolviendo contra `mdl_exelearning_grade_item` (ver AN-002).

## Riesgos / Limitaciones

- Si la lista de items calificables del paquete eXeLearning cambia tras un upload, hay
  que decidir política: ¿reasignar `itemnumber`? ¿marcar viejos como `deleted`?
  ⇒ tema para DEC futura.

## Preguntas abiertas

- Política de re-upload: preservar `itemnumber` por `objectid` estable vs renumerar.
