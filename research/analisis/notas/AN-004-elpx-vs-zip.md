---
id: AN-004
titulo: "ELP / ELPX vs paquete publicado: qué consume mod_exelearning"
fecha: 2026-05-28
fuentes:
  - REPO-001
  - REPO-002
  - REPO-005
  - FTE-008
relacionados:
  - DEC-0003
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Resumen

`.elp` / `.elpx` son **archivos de proyecto editable** de eXeLearning, no formatos de
ejecución. `mod_exescorm` los acepta y los **redirige a un flujo de
edición/conversión**. `mod_exelearning` debería distinguir claramente:

- **Subir un proyecto** (`.elp` / `.elpx`): se abre en el editor embebido, se publica
  y el resultado publicado es lo que se sirve al estudiante.
- **Subir un paquete publicado** (`.zip` con `index.html` o con `imsmanifest.xml`):
  se sirve directamente.

## Hechos citados

- `mod_exescorm/mod_form.php` acepta `.zip`, `.xml`, `.elpx` (REPO-001).
- `mod_exescorm/view.php` tiene un caso especial cuando `pathinfo($filename) === 'elpx'`
  (REPO-001).
- `mod_exeweb` acepta `.zip` y `.elpx` y delega al editor para `.elp`/`.elpx` si así
  está configurado (REPO-002).

## [INTERPRETACION]

- La separación entre "proyecto" y "publicación" debe quedar nítida en `mod_form.php`.
- Mientras `mod_exelearning` no ofrezca editor embebido obligatorio, el modo "proyecto"
  podría aplazarse a una fase posterior y aceptar sólo paquetes publicados al
  principio. Reduce superficie.

## Consecuencias para `mod_exelearning`

- Decisión de alcance (DEC futura): ¿v1 acepta `.elp/.elpx` o sólo paquete publicado?
- Si v1 acepta sólo publicado, el flujo es:
  `.zip → extracción → detección de items calificables → registro de grade items → iframe`.

## [PENDIENTE]

- DEC-0005 (futuro): formato de entrada en v1 (publicado-only vs proyecto+publicado).
- Verificar si `.elpx` publicado y `.elp` proyecto son distinguibles por estructura
  interna o sólo por extensión.
