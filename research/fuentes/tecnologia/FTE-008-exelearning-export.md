---
id: FTE-008
titulo: "Formato de paquete publicado por eXeLearning"
categoria: formato-paquete
version_consultada: "[PENDIENTE: detectar de upstream actual]"
enlaces_oficiales:
  - https://exelearning.net/
  - https://forum.exelearning.net/
context7:
  library_id: "[PENDIENTE: context7]"
  query: "[PENDIENTE: context7]"
  fecha: null
  version_devuelta: "[PENDIENTE: context7]"
fecha_consulta: 2026-05-28
relevancia_para_mod_exelearning: "Define el artefacto de entrada del plugin: estructura de archivos, motor JS de la sidebar, identificadores de iDevices calificables."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Qué es

Conjunto de archivos producidos por eXeLearning al "exportar" un proyecto `.elp` /
`.elpx` a uno de los formatos publicables: sitio web estático, SCORM 1.2, SCORM 2004,
IMS Common Cartridge, EPUB3, IMS QTI [PENDIENTE: confirmar lista actual].

## Estructura típica (Web)

Ver REPO-005 para detalle. Componentes nucleares para `mod_exelearning`:

- `index.html` — entry point con `<aside>`/`<nav>` que contiene el TOC.
- `lib/exe_player.js` (o equivalente) — motor JS de la sidebar (apertura de nodos,
  scrollspy, fragment routing).
- `styles/` — CSS de tema.
- `content/page-NNN.html` — páginas individuales.

## Estructura típica (SCORM)

- `imsmanifest.xml` con `<organizations>` jerárquico.
- Recursos referenciados desde `<resource>` por `identifier` y `href`.
- HTML idéntico al export web.

## Identificadores que podría exponer

[HIPOTESIS, a validar en EXP-001]
- iDevices calificables tienen un `id` interno (`idevice-{n}`) inyectado en `data-*`
  de su contenedor HTML.
- En SCORM 2004 export, podrían materializarse como `cmi.objectives.{n}.id` o como
  SCOs separados.

## Soporte para multi-grade-items

Depende del export: ver FTE-001/FTE-002/FTE-004 según la decisión que tome DEC-0003.

## Soporte para navegación/sidebar

**Sí, nativa, incluida en el paquete.** Es la razón de ser de la técnica iframe de
`mod_exeweb`.

## Implementaciones de referencia consultadas

- REPO-002 — mod_exeweb (sirve el paquete tal cual).
- REPO-003 — wp-exelearning (proxy + iframe).

## Riesgos / Limitaciones

- Cambios entre versiones de eXeLearning pueden romper la heurística de detección.
- Diferencia entre `.elp` (proyecto editable) y export publicado: documentar
  ambos en EXP-001.

## Preguntas abiertas

- PREG-001: identificadores estables de items calificables en cada formato de export.
- PREG-002: propuesta upstream de un manifiesto `gradeitems.json` adicional.
