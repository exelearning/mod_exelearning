---
id: FTE-008
titulo: "Formato de paquete publicado por eXeLearning"
categoria: formato-paquete
version_consultada: "ELPX v3 (eXeLearning v3/iteexe_online, paquete OneDrive 2025-12-18)"
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

## Estructura real observada (ELPX v3, Manual de eXeLearning)

Evidencia: EXP-001 sobre `Manual de eXeLearning.elpx` (148+ entradas comprimidas como
ZIP `store`). Estructura top-level:

```
package.elpx (ZIP)
├── content.xml              # MANIFEST PROPIETARIO eXeLearning (no SCORM)
├── index.html               # Entry point con sidebar y router
├── content/                 # Recursos del proyecto (imágenes, vídeos, audio)
│   ├── css/                 # CSS base del tema
│   ├── img/
│   └── resources/<id>/      # Carpeta por recurso, ID temporal yyyymmddhhmmss<6 chars>
├── html/                    # Una página HTML por nodo del árbol de navegación
│   ├── <slug-pagina-1>.html
│   ├── <slug-pagina-2>.html
│   └── …
├── idevices/                # Templates de iDevice instanciados en el paquete
│   ├── text/{text.html,text.js,text.css}
│   ├── trueorfalse/…        # SCORM-aware (ver REPO-005)
│   ├── quick-questions-multiple-choice/…
│   ├── dragdrop/…, complete/…, classify/…, relate/…, sort/…
│   └── 40+ iDevices distintos en el manual
├── libs/                    # Librerías JS comunes
│   ├── jquery/, bootstrap/
│   ├── common.js            # Player principal (pipwerks SCORM, navegación)
│   ├── common_i18n.js
│   ├── exe_export.js
│   ├── exe_effects/, exe_highlighter/, exe_lightbox/, exe_magnify/,
│   ├── exe_math/, exe_media/, exe_tooltips/
│   ├── jquery-ui/, mermaid/
│   └── favicon.ico
├── theme/                   # Tema seleccionado (style.js + config.xml)
│   ├── style.js
│   └── config.xml
├── custom/                  # Overrides del autor (puede estar vacío)
└── (en SCORM export adicional)
    └── libs/SCORM_API_wrapper.js  +  libs/SCOFunctions.js  +  imsmanifest.xml
```

## content.xml — manifest propietario

Esquema observado (relevante para `mod_exelearning`):

- `<ode>` raíz.
- `<userPreferences>` — preferencias del autor (tema, …).
- `<odeResources>` — metadatos del proyecto: `odeVersionId`, `odeId`,
  `odeVersionName`, `eXeVersion`, etc.
- `<odeProperties>` — propiedades del paquete: `pp_title`, `pp_subtitle`, `pp_lang`,
  `pp_author`, `license`, `pp_description`, `pp_addExeLink`, `pp_addPagination`,
  `pp_addSearchBox`, `pp_addAccessibilityToolbar`, `pp_extraHeadContent`, `footer`.
- `<odeNavStructures>` — **árbol de navegación (= sidebar)**: cada `<odeNavStructure>`
  con `<odePageId>` (ID estable yyyymmddhhmmss<6chars>), `<odeParentPageId>` (jerarquía),
  `<pageName>`, `<odeNavStructureOrder>`, `<odeNavStructureProperties>` (entre ellas
  `titlePage`).
- Por página, los iDevices se materializan con `<odeIdeviceId>` (ID estable) y
  `<odeIdeviceTypeName>` (tipo, p.ej. `trueorfalse`, `dragdrop`, `text`).

→ **Esto resuelve PREG-001 para el formato ELPX**: las IDs de página y de iDevice
son estables y exportadas en `content.xml`. Son el candidato natural para
`mdl_exelearning_grade_item.objectid`.

## iDevices con potencial calificable (lista observada)

Del Manual de eXeLearning (40+ tipos):

- Cuestionarios: `trueorfalse`, `quick-questions`, `quick-questions-multiple-choice`,
  `quick-questions-video`.
- Coincidencias / orden: `relate`, `sort`, `classify`, `scrambled-list`, `complete`,
  `identify`, `discover`, `dragdrop`.
- Juegos: `crossword`, `word-search`, `puzzle`, `trivial`, `az-quiz-game`, `guess`,
  `padlock`, `magnifier`, `hidden-image`.
- Matemáticas: `mathproblems`, `mathematicaloperations`, `periodic-table`.
- Multimedia interactivo: `interactive-video`, `flipcards`, `beforeafter`,
  `image-gallery`, `select-media-files`.
- Otros: `casestudy`, `challenge`, `checklist`, `rubric`, `form`, `map`,
  `external-website`, `geogebra-activity`, `udl-content`.
- Texto/recursos (no calificables): `text`, `download-source-file`.

(Filtrar los efectivamente calificables requiere inspección por iDevice; ver AN-005.)

## Estructura típica (SCORM)

- `imsmanifest.xml` con `<organizations>` jerárquico.
- Recursos referenciados desde `<resource>` por `identifier` y `href`.
- HTML idéntico al export web.

## Identificadores expuestos (confirmado tras EXP-001)

- **Página**: `odePageId` (formato `yyyymmddhhmmss<6CHARS>`, ej. `20251121091824DNMCSV`).
  Estable, único por página, declarado en `content.xml#/ode/odeNavStructures`.
- **iDevice**: `odeIdeviceId` con el mismo formato, declarado en `content.xml` dentro
  de cada `odePage`. Estable a través de re-uploads si el autor no recrea el iDevice.
- **Tipo de iDevice**: `odeIdeviceTypeName` (slug ASCII estable, p.ej. `trueorfalse`).

[HIPOTESIS pendiente] En SCORM 2004 export, comprobar si `odeIdeviceId` se materializa
como `cmi.objectives.{n}.id` en `imsmanifest.xml` o si se pierde y solo queda
`cmi.core.score.raw` agregado por SCO. Ver REPO-005 § "Integración SCORM observada"
para evidencia de que el export SCORM actual agrega.

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
