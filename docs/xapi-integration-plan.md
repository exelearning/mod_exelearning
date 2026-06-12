# xAPI integration plan

> Status: **plan only** (PR1 is documentation). Implementation is PR2 / TAREA-015, gated on
> eXeLearning PR #1867 freezing its contract. Decision: DEC-0032. Emitter contract: `FTE-011`.
> Statement→model mapping & trust analysis: `AN-012`. Moodle `core_xapi`: `FTE-007`.

## 1. Upstream contract (eXeLearning `exe_xapi.js`, PR #1867)

Verified by reading the source at commit `59b9b9b` (not a summary). The PR is **draft/open**.

- **Bundled into every export** via `BASE_LIBRARIES`; always-on, no export-time option.
  Independent of SCORM/pipwerks.
- **Config** injected by the exporter as `window.exeXapi`:
  `{ odeId, baseIri, activityId, packageTitle, language, actor, parentOrigin, registration }`.
  `baseIri` defaults to `https://exelearning.net/xapi/{odeId}`; `activityId` defaults to
  `baseIri`.
- **Transport** (both may run):
  1. `window.parent.postMessage({ type: 'exe-xapi-statement', statement }, target)` where
     `target = config.parentOrigin || '*'`.
  2. `POST {endpoint}statements` with `X-Experience-API-Version: 1.0.3` when xAPI launch
     params (`endpoint`+`auth`) are in the URL.
- **Statements**:
  - per-iDevice `answered`: `object.id = {baseIri}/idevice/{ideviceId}`, `definition.type =
    .../cmi.interaction`, `result.score = { scaled: s/10, raw: s, min:0, max:10 }`,
    `success = s>=5`, `completion = true`, `contextActivities.parent = [{id: activityId}]`,
    `context.extensions` (package-id, idevice-id, idevice-type, page-id, page-title).
  - package `completed` + (`passed` if `finalScore>=50` else `failed`): `object` = package
    Activity (`definition.type = .../assessment`), `result.score = { scaled: f/100, raw: f,
    min:0, max:100 }`.
  - lifecycle `initialized` / `terminated`: once each, only when a transport exists, **no**
    `result`.
- **Actor**: `config.actor || launch.actor ||` anonymous
  `{ account: { homePage: baseIri, name: 'anonymous' } }`. The emitter never invents PII;
  the host is authoritative.
- **De-dup**: in-page by `(key, verb, raw score)`; `statement.id` is a fresh UUID per emit.

cmi5 is explicitly **excluded** upstream.

## 2. Host config injection (Moodle side)

Just as the plugin injects the pipwerks loader into each package `<head>`
(`exelearning_inject_scorm_loader`, delegador en `lib.php` →
`\mod_exelearning\local\scorm\scorm_injector::inject()`, DEC-0054), it will inject:

```js
window.exeXapi = {
  odeId: '<package odeId>',
  parentOrigin: '<Moodle wwwroot origin>',  // never '*': only deliver to this host
  actor: null,                              // force anonymous; the server attaches $USER
  registration: '<server-issued token>'     // attempt grouping, analogous to sessiontoken
};
```

This keeps PII out of the page and makes honest packages post **only** to Moodle.

## 3. Listener (`amd/src/xapi_listener.js`)

- Listen to `window` `message` events; accept **only** `event.data.type ===
  'exe-xapi-statement'`.
- **Validate `event.origin`** against the iframe `pluginfile.php` origin; drop `'*'` /
  mismatched senders (defense in depth even though `parentOrigin` is set — RIE-013).
- De-dup by `statement.id` within the page session.
- Forward to the endpoint with `sesskey`, `cmid`, and the current `registration` if known.
- Never read or expose PII in JS.
- After editing, rebuild `amd/build/` with Moodle's `grunt amd` (per `AGENTS.md`).

## 4. Server endpoint

Two viable routes (analysis in `AN-012`):

| Route | Pros | Cons |
|---|---|---|
| **Custom external** `classes/external/submit_xapi_statement` (ajax, like `manage_embedded_editor` + `db/services.php`) | Full control of the trust model: ignore `actor` → `$USER`; trivially reuses `apply_item_scores` | Not the `core_xapi` subsystem (no automatic events/state) |
| **Native** `core_xapi_statement_post` + `mod_exelearning\xapi\handler` (FTE-007, h5pactivity pattern AN-003) | Standard; emits Moodle events; less bespoke code | `core_xapi` binds processing to actor identity; our anonymous actor needs care to be accepted as the session user |

**Recommendation:** a custom endpoint for ingestion/grading (clean fit for "ignore actor,
reuse the pipeline"), plus an **optional** `core_xapi` handler later purely for
events/analytics. Final call in PR2.

The endpoint must:

1. Require a valid session + `sesskey`; resolve `cmid`/instance server-side.
2. `require_capability('mod/exelearning:savetrack')` (or preview rule, DEC-0006).
3. **Ignore** the statement actor; attribute to `$USER`.
4. Honour `gradeenabled` (DEC-0029): if grading is off for the activity, there are no grade
   items — accept-and-ignore (no-op) rather than create anything.
5. Resolve `object.id` → `ideviceId` → `exelearning_grade_item.objectid` → `itemnumber`
   for this instance; **reject** unknown ids.
6. Normalize the statement to the existing `itemscores` shape and call
   `track::apply_item_scores()`; take the package `passed`/`failed` as the overall (the
   producer already weights it), re-validating server-side.
7. Idempotency: optionally persist by `statement.id` (`exelearning_tracking_events`).

## 5. Statement → internal model mapping

| xAPI field | Internal model |
|---|---|
| `actor` (anonymous) | **ignored** → `$USER` |
| `verb=answered` (per iDevice) | per-item score → `apply_item_scores` |
| `verb=completed` | overall `completion` (`itemnumber=0`) |
| `verb=passed`/`failed` | overall `status` + `success` |
| `verb=initialized`/`terminated` | attempt open/close (via `registration` ↔ `sessiontoken`) |
| `object.id = …/idevice/{ideviceId}` | `objectid → itemnumber` (DEC-0017); unknown → reject |
| `result.score.scaled` | `scorepct = scaled*100` |
| `result.success` / `result.completion` | `status` / `completion` |
| `context.extensions[package-id]` | instance ownership check |
| `context.registration` | attempt grouping key |
| `statement.id` | optional server-side idempotency key |

## 6. Persistence, grading, completion

- Reuse `exelearning_attempt` (flat, DEC-0007). **No** header+detail tables.
- Grading and completion are **unchanged**: the normalizer feeds the same
  `apply_item_scores` / `record_item` / `grade_update` / `completion_info::update_state`
  path the SCORM endpoint uses. No second grade-calculation path.
- Optional `exelearning_tracking_events` (`statementid` UNIQUE) only for audit/idempotency;
  the UI/grade must depend on normalized columns, not raw JSON.

## 7. Out of scope / sequencing

- **Out of scope:** cmi5, external-LRS dependency, and the `'*'` origin fallback.
- **PR1 (this):** documentation + ADR only; no plugin code.
- **PR2 (TAREA-015):** listener + endpoint + normalizer + optional handler/events +
  optional audit table + PHPUnit/JS tests, **gated** on PR #1867 freezing the envelope and
  `parentOrigin`. SCORM 1.2 stays as the compatibility path (DEC-0003).
