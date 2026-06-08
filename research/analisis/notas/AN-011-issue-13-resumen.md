---
id: AN-011
titulo: "Issue #13: síntesis de los 6 puntos, su resolución y mapa de ADRs (PR #14/#15/#16)"
fecha: 2026-06-04
fuentes:
  - REPO-001
  - REPO-002
  - REPO-004
  - REPO-005
relacionados:
  - DEC-0022
  - DEC-0023
  - DEC-0024
  - DEC-0026
  - DEC-0027
  - DEC-0028
  - DEC-0029
  - DEC-0030
  - DEC-0031
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Contexto

El issue #13 (<https://github.com/ateeducacion/mod_exelearning/issues/13>) pide 6 mejoras. Esta
nota es el índice/síntesis: qué hace cada punto, cómo se resolvió, en qué PR y qué ADRs lo
respaldan. El detalle vive en cada DEC; aquí solo el mapa para no perderse entre PRs apilados.

## Los 6 puntos del issue

| Punto | Qué pide | Resolución | ADR(s) | PR |
|---|---|---|---|---|
| 1 | Crear un .elpx desde cero | Paquete de subida **opcional**; el editor embebido autora el proyecto en vacío | DEC-0024 | 14 |
| 2 | Detectar solo iDevices marcados para evaluar | Detección por **`isScorm > 0`** (no whitelist de tipos); la señal se lee de `jsonProperties` **o** `htmlView` | DEC-0022 (+ enmienda) | 14 |
| 3 | Importar desde mod_exeweb / mod_exescorm | **Herramienta de migración masiva** desde los Ajustes del plugin (no por-actividad) | DEC-0025 → DEC-0026 | 15 |
| 4 | Navegar del libro al iDevice concreto | La **cabecera** la fija el core (no deep-linkable); el **"grade analysis"** (vía `grade.php`) va por rol: profesor → `report.php` (con `userid`), alumno → `view.php?idevice` | DEC-0023, DEC-0028 | 14 |
| 5 | Soportar +10 tipos de iDevice | Cubiertos automáticamente por la detección `isScorm` (form, beforeafter, hidden-image, periodic-table, select-media, flipcards, map, interactive-video, challenge, padlock) | DEC-0022 | 14 |
| 6 | UI: "Edit with eXe" a la derecha + pantalla completa | Botón realineado + toggle **Fullscreen** (`amd/src/fullscreen.js`) | DEC-0024 | 14 |

## Refinamientos derivados (en PR #14)

- **Interruptor "Graded activity" por actividad** (master switch; desactivado = sin libro ni
  informes, conserva los intentos): **DEC-0029**.
- **Split del formulario** en "Grading" + "Attempts management", como mod_exescorm: **DEC-0031**.
- **Versión sentinela** `9999999999` / `dev` (la versión real sale del tag de git vía
  `make package`): **DEC-0030**.
- **Aceptar `.zip`** (con `content.xml`) además de `.elpx`, que también simplifica el uso normal:
  **DEC-0027** (PR #16, ya mergeado a `main`).

## Seguimiento (2026-06-08): bug "solo 12 de 30" — DataGame cifrado

Un usuario reporta que **no todas las actividades evaluables pasan al libro** (issue #13,
[comentario](https://github.com/ateeducacion/mod_exelearning/issues/13#issuecomment-4648392513)):
en `superelpx` (30 iDevices, casi todos `isScorm:1`) solo se detectaban **12**. Causa: la familia
"exe-game" (guess, discover, identify, classify, quick-questions, az-quiz-game, crossword,
word-search, padlock, challenge, select-media-files, complete, sort, mathproblems…) guarda su
config —incluido `isScorm`— **cifrada** en un div oculto `*-DataGame` (`escape()` + XOR 146;
`libs/common.js::decrypt`), invisible al regex de DEC-0022. Fix en **[[DEC-0037]]**: descifrar el
DataGame como tercera fuente del flag → **12→28** en `superelpx` (los 2 fuera, `puzzle` y
`hidden-image`, tienen `isScorm:0` real). Amplía los puntos #2/#5: la detección por `isScorm`
ahora cubre también la familia con config ofuscada.

## Estado (2026-06-04)

- **PR #14** — núcleo (puntos 1, 2, 4, 5, 6 + refinamientos). Rama `feature/issue-13-core`,
  mergeada con `main`; abierto, MERGEABLE, CI en verde.
- **PR #15** — punto 3 (migración masiva). Rama `feature/issue-13-import`, **apilada sobre #14**.
- **PR #16** — `.zip` (DEC-0027). **Mergeado a `main`**.
- Detección verificada con `research/fixtures/elpx/todos-los-idevices.elpx` (50 iDevices) → **17**
  calificables (los otros 33 no llevan `isScorm`).
- Suite del plugin: **56 tests / 213 assertions** en verde; `phpcs --standard=moodle` 0/0.

## Enlaces

[[DEC-0022]] · [[DEC-0023]] · [[DEC-0024]] · [[DEC-0026]] · [[DEC-0027]] · [[DEC-0028]] ·
[[DEC-0029]] · [[DEC-0030]] · [[DEC-0031]] · [[DEC-0037]]
