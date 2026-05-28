---
id: REPO-005
titulo: "eXeLearning — herramienta de autoría upstream"
tipo: authoring-tool
ruta_local: "[PENDIENTE: clonar en ../_repos/exelearning si se necesita análisis de código]"
url_upstream: https://exelearning.net/
commit_consultado: null
fecha_consulta: 2026-05-28
licencia: "GPL-2.0-or-later [PENDIENTE: confirmar en upstream]"
rol_para_mod_exelearning: "Productor del paquete que mod_exelearning consume. Define la estructura del paquete publicado, los iDevices calificables y el motor JS de la sidebar."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Hechos

- eXeLearning produce paquetes publicados como sitio web estático (HTML/CSS/JS) y
  también puede exportar a SCORM 1.2 / SCORM 2004 / IMS Common Cartridge / EPUB3.
- Formato de proyecto: `.elp` / `.elpx`.
- Los paquetes web ya incluyen el árbol de navegación (sidebar) implementado en JS.

## Estructura típica de un paquete publicado (web)

```
package/
├── index.html               # Entry point con sidebar + área de contenido
├── content/                 # Páginas html
├── styles/                  # CSS de tema
├── lib/                     # JS del player (navegación, accordion, etc.)
├── media/                   # Imágenes, vídeos
├── resources/               # Recursos adicionales
└── metadata.xml             # Metadatos del proyecto
```

## Estructura típica de un paquete SCORM publicado

```
package.zip
├── imsmanifest.xml          # Manifest SCORM (organizations, resources)
├── adlcp_rootv1p2.xsd / ims_xml.xsd / ... # Schemas (en SCORM 1.2)
├── index.html               # Entry point referenciado por la organization
├── content/, styles/, lib/, media/, …
```

## iDevices con potencial calificable

Categoría de iDevices que tradicionalmente puntúan en eXeLearning (a verificar contra
versión actual en `[PENDIENTE: TAREA-003 / EXP-001]`):

- Pregunta verdadero/falso
- Pregunta selección múltiple
- Pregunta de selección sencilla (quiz SCORM)
- Actividad de rellenar huecos
- Cloze / dropdown
- SCORM Quiz (agrupador)

## Capacidades respecto a `mod_exelearning`

- Producción de paquetes: sí (web, SCORM 1.2, SCORM 2004, cmi5 [PENDIENTE: confirmar]).
- Identificación estable de items calificables: parcial — los iDevices tienen `id` interno,
  pero no se sabe si se exporta como manifiesto consultable. PREG-001.

## Riesgos / Limitaciones

- Cambios entre versiones de eXeLearning podrían romper la heurística de detección de items.
- El motor JS de la sidebar depende de jQuery (a confirmar).

## Preguntas abiertas

- PREG-001: identificadores estables de iDevices calificables en el paquete publicado.
- PREG-002: posibilidad de aguas arriba publicar un manifiesto adicional declarativo
  (p. ej. `exelearning-gradeitems.json`) con `{id, title, maxscore}` por item calificable.
