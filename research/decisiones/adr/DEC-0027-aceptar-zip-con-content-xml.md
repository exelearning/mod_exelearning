---
id: DEC-0027
titulo: "Aceptar también .zip (con content.xml) además de .elpx en la subida"
estado: Propuesta
fecha: 2026-06-03
agentes:
  - erseco
  - claude-code
fuentes:
  - REPO-004
relacionados:
  - DEC-0024
  - DEC-0026
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Contexto

Hoy el plugin solo acepta la extensión `.elpx` (`mod_form.php` →
`accepted_types => ['.elpx']`, y la restricción "sólo `.elpx` v4" en `AGENTS.md`). Pero un
`.elpx` **es** un ZIP que contiene `content.xml` (ODE 2.0) + el HTML pre-renderizado; la
extracción (`exelearning_extract_stored_package()` con
`get_file_packer('application/zip')`) ya trata cualquier ZIP — la única puerta que mira la
extensión es el `filemanager` del formulario.

Casos de uso donde el paquete llega como `.zip` y no como `.elpx`:

- El usuario descarga/re-empaqueta el contenido y le queda `.zip` (o un sistema externo lo
  entrega así); tener que renombrar a `.elpx` es fricción innecesaria.
- **Importación/migración (DEC-0026):** la fuente embebida que se extrae de un SCORM de
  `mod_exescorm`, o cualquier export con `content.xml`, puede no llevar la extensión `.elpx`.
  Aceptar `.zip` unifica el camino y simplifica el motor.

## Decisión (propuesta)

Aceptar **también `.zip`** en la subida, **siempre que** el archivo contenga un `content.xml`
válido (estructura ODE 2.0 de eXeLearning v4) en la raíz. El `.elpx` sigue siendo el formato
**primario y recomendado**; el `.zip` es una conveniencia que pasa exactamente la misma
validación e idéntico pipeline de extracción/sincronización.

- `mod_form.php`: `accepted_types => ['.elpx', '.zip']`.
- **Validación obligatoria tras extraer:** confirmar que existe `content.xml` (y, en su caso,
  `index.html`). Si falta `content.xml`, **rechazar** con un mensaje claro ("no es un paquete
  eXeLearning"). Esto impide aceptar ZIPs arbitrarios y mantiene el invariante real: lo que
  importa no es la extensión, sino que sea un **paquete v4 ODE 2.0**.
- No cambia el formato interno, el editor embebido ni el contrato de `content.xml`; sólo la
  puerta de entrada.

## Consecuencias

- Relaja la restricción "sólo `.elpx`" de `AGENTS.md` → reformularla como "sólo paquetes v4
  ODE 2.0 (con `content.xml`), aceptados como `.elpx` o `.zip`".
- Riesgo: un `.zip` sin `content.xml` (o un zip malicioso). Mitigado por la validación
  post-extracción + el sandbox del iframe (DEC-0019) ya existente para el contenido servido.
- Simplifica DEC-0026 (la resolución de fuente no depende de la extensión).

## Implementación (follow-up, aún no realizada)

- `mod_form.php`: añadir `.zip` a `accepted_types`.
- Validación de `content.xml` en `exelearning_save_and_extract_package()` /
  `exelearning_extract_stored_package()` (devolver/mostrar error si falta).
- Nuevo string de error (`err_nocontentxml` o similar).
- Tests: subir `.zip` con `content.xml` → OK (se detecta y extrae igual que un `.elpx`); subir
  `.zip` sin `content.xml` → rechazado.
- Actualizar la nota de `AGENTS.md`.

> **Nota de estado:** ADR registrada en `main` como decisión de diseño/roadmap; la
> implementación irá en su propio PR (independiente de los PR de importación/migración).
