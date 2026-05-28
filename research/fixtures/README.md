# fixtures/

Paquetes eXeLearning reales para pruebas reproducibles del plugin
`mod_exelearning`. Todos los archivos de esta carpeta provienen del repositorio
oficial de eXeLearning v3 (`iteexe_online`) — son las mismas fixtures que el propio
proyecto utiliza en sus tests de integración. Se replican aquí para que los
experimentos de `mod_exelearning` sean ejecutables sin necesidad de clonar
eXeLearning.

> **Política de actualización**: cuando se actualice eXeLearning, regenerar estas
> fixtures desde el upstream y registrar la fecha en este README. No editarlas a
> mano.

## Origen upstream

`/Users/ernesto/Downloads/git/exelearning/test/fixtures/` (alias canónico)
o `/Users/ernesto/Dropbox/Trabajo/ate/exelearning/exelearning/test/fixtures/`.

Fecha de la copia: **2026-05-28**.

## Contenido

### `elp/` — proyectos editables formato legacy

| Archivo | Tamaño | Notas |
|---|---|---|
| `basic-example.elp` | 533 KB | Proyecto mínimo, dos páginas, sólo texto. |
| `latex.elp` | 381 KB | Incluye expresiones LaTeX → test del iDevice de matemáticas. |

### `elpx/` — proyectos editables formato v3 (zip de `content.xml` + recursos)

| Archivo | Tamaño | Notas |
|---|---|---|
| `arrows.elpx` | 455 KB | Sample minimal con jerarquía. |
| `really-simple-test-project.elpx` | 1.2 MB | Sample canónico de pruebas: 6 páginas en árbol, solo iDevices `text`. |

### `web-export/really-simple_web/` — export "sitio web estático" del proyecto anterior

Estructura completa (~2.8 MB): `index.html` + `html/<slug>.html` + `idevices/`
+ `libs/` (incluye `common.js`, `jquery`, `bootstrap`) + `theme/`. **NO** incluye
`SCORM_API_wrapper.js`. Esta es la entrada que consumiría un equivalente directo
de `mod_exeweb`.

### `scorm-export/really-simple_scorm12/` — export SCORM 1.2 del mismo proyecto

Estructura ~3.0 MB:

- `imsmanifest.xml` — **manifest SCORM con jerarquía de SCOs** (ver más abajo).
- `index.html` (entry) + páginas en `html/`.
- `libs/SCORM_API_wrapper.js`, `libs/SCOFunctions.js`, `libs/common.js` con
  pipwerks SCORM.
- `content.xml` — manifest propietario eXeLearning incluido como recurso.
- Schemas SCORM 1.2 incluidos (`adlcp_rootv1p2.xsd`, `imscp_rootv1p1p2.xsd`,
  `lom.xsd`, etc.).

#### Lo crítico del `imsmanifest.xml` (citado literal)

Cada **página** del árbol de eXeLearning se exporta como un **SCO independiente**:

```xml
<resource identifier="RES-20251217061325YPVNGE"
          type="webcontent" adlcp:scormtype="sco" href="index.html"> … </resource>
<resource identifier="RES-202512170617021528Y4"
          type="webcontent" adlcp:scormtype="sco" href="html/page-1-1.html"> … </resource>
<resource identifier="RES-202512170617262192HA"
          type="webcontent" adlcp:scormtype="sco" href="html/page-1-1-1.html"> … </resource>
… (6 SCOs en total para 6 páginas)
<resource identifier="COMMON_FILES" type="webcontent"
          adlcp:scormtype="asset"> … (jquery, scorm wrapper, css, …) </resource>
```

→ **Implicación**: `mod_exelearning` (vía bridge SCORM o leyendo el manifest)
puede registrar **1 grade item por SCO = 1 grade item por página**. Multi-grade
queda resuelto al nivel de granularidad de página **sin cambios upstream**.
La granularidad por iDevice individual sigue requiriendo cambio aguas arriba
(PREG-002). Ver AN-005.

## Cómo regenerar las fixtures desde upstream

```bash
# Desde el clon de eXeLearning (cualquiera de los dos)
EXE=/Users/ernesto/Downloads/git/exelearning
DST=$(git rev-parse --show-toplevel)/research/fixtures

cp "$EXE/test/fixtures/really-simple-test-project.elpx" "$DST/elpx/"
cp "$EXE/test/fixtures/arrows.elpx"                     "$DST/elpx/"
cp "$EXE/test/fixtures/basic-example.elp"               "$DST/elp/"
cp "$EXE/test/fixtures/latex.elp"                       "$DST/elp/"

cp -R "$EXE/test/fixtures/export/really-simple/really-simple-test-project_scorm" \
      "$DST/scorm-export/really-simple_scorm12"
cp -R "$EXE/test/fixtures/export/really-simple/really-simple-test-project_web" \
      "$DST/web-export/really-simple_web"
```

## Lo que NO está aquí (porque pesa demasiado)

- `Manual de eXeLearning 3.0.elpx` (28 MB) — paquete con 40+ tipos de iDevice
  (incluyendo cuestionarios). Para pruebas de iDevices calificables descargar de
  upstream:
  `/Users/ernesto/Downloads/git/exelearning/test/fixtures/Manual de eXeLearning 3.0.elpx`.
- `todos-los-idevices.elp` / `_dos_informes.elpx` (30 MB cada uno) — sample
  exhaustivo.
- Exports de SCORM 2004 (no copiados todavía; ver TAREA futura).

## Licencia

Los archivos provienen del proyecto eXeLearning ([GitHub](https://github.com/exelearning))
publicado bajo GPL-2.0-or-later. Su redistribución aquí mantiene la misma licencia.
