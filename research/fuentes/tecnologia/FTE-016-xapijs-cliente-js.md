---
id: FTE-016
titulo: "xAPI.js (@xapi/xapi, MIT) — tipos del statement, verbos canónicos y fixtures de test"
categoria: documentacion
version_consultada: "@xapi/xapi v3.0.3 (xapijs/xAPI, rama develop)"
enlaces_oficiales:
  - https://www.xapijs.dev/
  - https://github.com/xapijs/xAPI
  - https://www.npmjs.com/package/@xapi/xapi
  - https://github.com/xapijs/cmi5
context7:
  library_id: "[no consultado en Context7: librería de comunidad, no estándar/API Moodle; consultado vía GitHub raw/API al commit fijado]"
  query: "[N/A]"
  fecha: null
  version_devuelta: "[N/A]"
fecha_consulta: 2026-06-17
relevancia_para_mod_exelearning: "Fuente de tipos TS del statement (contrato compacto del shape) y de fixtures de test, SIN dependencia de runtime. Su separación cliente-base vs @xapi/cmi5 es evidencia citable de 'cmi5 fuera de alcance'. Rol: reference + test-fixtures. Commit develop @ 5e28e9bda892c9ef582b15dfd945ef0780c75570."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Qué es

Librería **cliente xAPI en TypeScript/JS** *strongly typed* que envuelve el LRS REST API (Statements,
State/Activity/Agent Profile, About). Mantenida por un autor individual (Christian Cook, © 2020) bajo la org
GitHub **`xapijs`**; proyecto pequeño (~51 ★) pero con actividad reciente. Sirve para construir/validar
statements con tipos y hacer POST/GET contra un endpoint xAPI desde browser o Node. **El soporte cmi5 NO está
aquí**: vive en un paquete **separado** (`@xapi/cmi5`) que depende de `@xapi/xapi` + `axios` + `deepmerge` +
`uuid`.

## Conceptos clave (citados al repo @ develop)

- **Clase `XAPI`** con métodos de Statement: `sendStatement`, `sendStatements`, `getStatement(s)`,
  `getMoreStatements`, `getVoidedStatement`, `voidStatement(s)` (`src/resources/statement/`). Recursos cubiertos:
  statement, activities (incl. State/Profile docs), agents, document, about.
- **Tipo `Statement`** (`src/resources/statement/Statement.ts`): `{ id?, actor, verb, object, result?, context?,
  timestamp?, version?, attachments? }` — **coincide 1:1** con el shape del plan y del emisor (FTE-011).
- **`ResultScore`** (`src/resources/statement/Result.ts`): `{ scaled: number, raw?, min?, max? }`; `Result`:
  `{ score?, success?, completion?, response?, duration?, extensions? }` — el campo que el endpoint leería como
  score normalizado por iDevice (`answered`) y a nivel paquete (`passed/failed/completed`).
- **`XAPI.Verbs`** (`src/XAPI.ts`): constante con los verbos predefinidos y sus **IRIs canónicos** (`answered`,
  `completed`, `passed`, `failed`, `initialized`, `terminated`) — los **mismos** que usa el plan.
- **Adapters desacoplados** (`src/adapters/`): `fetchAdapter` + `axiosAdapter` con `resolveAdapterFunction` →
  separa la construcción del statement del transporte.
- **Helpers estáticos:** `calculateISO8601Duration`, `getXAPILaunchData`/`getTinCanLaunchData`, `toBasicAuth`.
- **Build:** rollup multi-target `dist/XAPI.cjs.js` (CJS), `.esm.js` (ESM), `.umd.js` (UMD), `.d.ts`.
  **No expone AMD nativo**. Dependencia de runtime única: **`axios` ^1.15.2**.

## API / Puntos de extensión relevantes

- **Tipos como contrato del shape** (`Statement.ts`/`Result.ts`/`Verb.ts`/`StatementObject.ts`/
  `InteractionActivityDefinition.ts`/`Context.ts`/`Actor.ts`) → referencia canónica compacta para documentar el
  JSON-Schema/validación del endpoint PHP y el contrato emisor↔listener↔endpoint (sin releer la spec entera).
- **`XAPI.Verbs`** como tabla de IRIs canónicos en `docs/xapi-integration-plan.md` y en fixtures, en vez de
  strings sueltos.
- **Fixtures derivables** (sin dependencia, MIT): un `answered` con `result.score.scaled` por iDevice + un
  `completed`+`passed/failed` a nivel paquete; más casos inválidos (`object.id` desconocido, `scaled` fuera de
  rango, `statement.id` duplicado, `actor` con PII) → tests Vitest del listener y PHPUnit del endpoint.

## Soporte para multi-grade-items

N/A — cliente/tipos puros, sin lógica de calificación. El mapeo statement→nota vive en el endpoint de
`mod_exelearning`. Lo relevante es que `Result.score.scaled` es el campo de score por iDevice/paquete.

## Soporte para navegación/sidebar

N/A.

## Implementaciones de referencia consultadas

- FTE-011 — `exe_xapi.js` (emisor upstream): su statement encaja en los tipos de `@xapi/xapi`.
- FTE-015 — xAPI 1.0.3: los tipos son una representación TS de la spec.
- REPO-008 — `mod_cmi5launch` (consumidor cmi5; el perfil cmi5 vive en `@xapi/cmi5`, paquete aparte).

## Riesgos / Limitaciones

- **Rol = reference + test-fixtures, NO dependencia de runtime.** Meterla en el bundle AMD arrastraría `axios`
  ^1.15.2 (indeseable en `grunt amd`); el listener debe usar **`fetch` nativo**. No expone build AMD (solo
  CJS/ESM/UMD). → sobre-ingeniería para un listener que solo valida `origin` y reenvía.
- **Licencia MIT** (© 2020 Christian Cook) — compatible GPLv3. Si se copiaran **tipos** verbatim, conservar el
  aviso MIT y declararlo en `thirdpartylibs.xml`. La recomendación es **no copiar**: usar como referencia.
- **cmi5 NO está aquí** (`@xapi/cmi5`, deps extra `deepmerge`/`uuid`): evidencia de que cmi5 aporta valor en
  launch/AU/catálogos lanzables, **no** en recurso embebido → refuerza «cmi5 fuera de alcance» (AN-014/M6).
- Mantenedor único / proyecto pequeño: aceptable como referencia de tipos, **arriesgado como dependencia** de
  producción.
- `[PENDIENTE]` rama por defecto = **`develop`** (no main/master); fijar URLs raw a `develop` al commit. Confirmar
  peso `unpacked-size` exacto del `dist` si alguna vez se vendorizaran tipos (npm devolvió 403 al fetch directo).

## Preguntas abiertas

- PREG: ¿se adopta un JSON-Schema derivado de estos tipos para validar en el endpoint (AN-014/M7), o basta la
  validación canónica de FTE-015/M1? (probablemente M1 es suficiente; los tipos sirven para fixtures).
