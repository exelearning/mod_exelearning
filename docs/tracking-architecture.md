# Tracking architecture — SCORM 1.2 + xAPI ingestion

> Status: **implemented** (DEC-0032 architecture + **DEC-0064** implementation; Spanish ADRs under
> `research/decisiones/adr/`). The xAPI channel shipped in PR2 / TAREA-015 now that eXeLearning
> PR #1867 is merged (`e3b1bd13`). See also `scorm-shim-current-flow.md` (the SCORM path) and
> `xapi-integration-plan.md` (the design + what shipped).
>
> **xAPI-primary (DEC-0064):** a package that bundles `libs/xapi/exe_xapi.js` is graded via xAPI and
> its `window.API` is an **inert stub** (`js/scorm_tracker.js` `disableTracking`); legacy packages keep
> SCORM. So a given package uses exactly one grade channel — no double-counting. The listener is the
> inline IIFE `js/xapi_listener.js` and the endpoint is the plain script `xapi_track.php`
> (sesskey, mirroring `track.php`), delegating to `\mod_exelearning\local\xapi\{statement_normalizer,
> ingestor}`. The overall (`itemnumber=0`) is taken from the package statement and validated
> server-side, because per-iDevice `answered` statements carry no weight.

## Principle

`mod_exelearning` becomes a **dual consumer**: the legacy **SCORM 1.2 shim** and the new
**xAPI emitter** (`exe_xapi.js`, eXeLearning PR #1867 — see `FTE-011`) both feed the **same
internal pipeline**. They are two ingestion *sources*, not two parallel models.

The "common internal model" the dual layer needs **already exists** and is reused as-is:

- `exelearning_attempt` — **flat** attempt table, axis `itemnumber` 0..N + `sessiontoken`
  (DEC-0007; the original header+detail design was evaluated and rejected — DEC-0007:176-186).
- `exelearning_grade_item` — stable `objectid → itemnumber` map (DEC-0017).
- `classes/local/track.php` + `attempts.php` — routing, overall recompute (DEC-0018),
  attempt recording, `grademethod` aggregation, `grade_update()`. The orchestration is the
  single shared entry point `track::ingest()` (DEC-0040): the web `track.php` and the mobile
  `save_track` web service already call it, so a future xAPI source would be a **third**
  caller of the same pipeline, not a parallel one.

xAPI therefore does **not** add a new neutral layer or header+detail tables. At most it adds
**one** optional audit/dedup table (`exelearning_tracking_events`, `statementid` UNIQUE).

## Flow

```mermaid
flowchart TD
  subgraph PKG["Published eXeLearning package (same-origin iframe)"]
    SCORM["pipwerks SCORM 1.2 calls"]
    XAPI["exe_xapi.js emitter (PR #1867)"]
  end

  SCORM -->|"LMSSetValue → window.API (legacy pkgs only; INERT stub for xAPI pkgs)"| ITEMS
  XAPI -->|"postMessage {type:'exe-xapi-statement', statement}"| LIS["js/xapi_listener.js (inline IIFE)"]
  LIS -->|"validate event.origin; POST sesskey + cmid + registration"| EP["xapi_track.php (plain AJAX, like track.php)"]
  EP -->|"statement_normalizer (validate, DEC-0063) → ingestor; ignore actor → \$USER"| ITEMS

  ITEMS["itemscores { objectid: { scorepct, weighted, title } }"] --> TR["track::apply_item_scores (+ overall from package statement)"]
  TR --> AT["attempts::record_item / aggregate_scaled  (exelearning_attempt, flat)"]
  AT --> GB["grade_update() → Moodle gradebook"]
  AT --> CO["completion_info::update_state()"]
  EP -. deferred .-> HND["core_xapi handler → Moodle events (analytics)"]
```

## Trust boundary

Everything the server accepts from the package is validated server-side (identical posture
to the SCORM endpoint today):

- Session + `sesskey`; resolve `cmid`/instance server-side; `require_capability('mod/exelearning:savetrack')`.
- **Ignore the statement `actor`** (the emitter sends an anonymous account by design,
  FTE-011) and attribute the grade to `$USER`.
- Map `object.id` → `objectid` and accept only objectids that already exist for **this**
  instance (DEC-0017); reject unknown ones (never create items from the client).
- Respect `gradeenabled` (DEC-0029): when grading is off there are no grade items, so
  statements route nowhere (a no-op, consistent with rejecting unknown objectids).
- Re-validate the overall on the server (spirit of DEC-0018).
- `postMessage`: the host injects `parentOrigin = <Moodle origin>` and the listener checks
  `event.origin` against the iframe `pluginfile.php` origin; `'*'`/mismatch is rejected
  (RIE-013).

## Reused vs new

| Concern | Reused | New (DEC-0064) |
|---|---|---|
| Internal model | `exelearning_attempt`, `exelearning_grade_item` | `exelearning_tracking_events` (`statementid` UNIQUE — audit/idempotency) |
| Routing / grading | `track::apply_item_scores`, `attempts::*`, `grade_update` | `\local\xapi\statement_normalizer` + `\local\xapi\ingestor` (thin `statement → itemscores`; overall from the package statement) |
| Client capture | `js/scorm_tracker.js` (legacy pkgs) | `js/xapi_listener.js` (inline IIFE); `\local\xapi\config_injector` sets `parentOrigin`/`actor:null` |
| Server entry | `track.php` (SCORM) + `save_track` WS (mobile) | `xapi_track.php` (plain AJAX, sesskey, mirrors `track.php`) — no `db/services.php` entry |
| Events | `course_module_viewed`, `attempt_started`/`attempt_completed` | **deferred**: optional `core_xapi` handler + iDevice/package events |

## SCORM 1.2 vs xAPI — comparison

Both channels feed the same gradebook through the same pipeline; they differ in *how the
package talks to Moodle* and *how much it can say*. This plugin uses **exactly one** channel
per package — xAPI when the package emits it, SCORM otherwise (DEC-0064).

| Dimension | SCORM 1.2 (legacy path) | xAPI (this layer) | Edge |
|---|---|---|---|
| Transport | pipwerks `window.API` shim that Moodle injects and force-inits | `postMessage` emitted natively by the package | **xAPI** — no shim, no pipwerks dependency |
| Per-iDevice detail | parsed out of the `cmi.suspend_data` string with a locale-sensitive regex | one structured `answered` statement per iDevice | **xAPI** — no brittle string parsing |
| Score field | `cmi.core.score.raw` + the suspend_data format eXeLearning serialises | typed `result.score.{scaled,raw,min,max}` | **xAPI** — breaks only if the spec changes, not the producer's string |
| Interaction richness | overall score + lesson status | verbs, per-iDevice results, context, extensions | **xAPI** — captures far more than a final score |
| Weighted overall | recomputed server-side from items (weights travel inline in suspend_data) | taken from the package `finalScore` (answered statements carry no weight) and validated | **SCORM** — weights travel with each item; xAPI leans on the package statement (parity preserved here) |
| Identity / trust | package asserts nothing; server uses `$USER` | actor is anonymous by design; server uses `$USER` | **tie** — both fully server-trusted |
| Idempotency | none (the attempt upsert absorbs repeats) | de-duplicated by `statement.id` (`exelearning_tracking_events`) | **xAPI** — exactly-once auditing |
| Offline / mobile / non-browser | no (needs the SCORM runtime in a browser) | yes (the same statements can also reach an LRS) | **xAPI** — portable beyond the embedded iframe |
| Coupling to the producer | needs pipwerks injected + the `form`/`scrambled-list` save-guard patch (DEC-0042) | none — the emitter is always-on in every export | **xAPI** — fewer serve-time mutations |
| Standard status | legacy (SCORM 1.2, 2004-era) | current (xAPI 1.0.3, forward-compatible with 2.0) | **xAPI** — modern, actively maintained |
| LMS / tooling ubiquity | near-universal, decades of support | modern standard, growing adoption | **SCORM** — widest compatibility |
| Maturity in this plugin | productive, the default since DEC-0003 | new in this layer | **SCORM** — battle-tested |
| Analytics / LRS readiness | none (data stays as Moodle grades) | statements are LRS-shaped (future `core_xapi` handler, deferred) | **xAPI** — a path to learning analytics |

**In short**

- **SCORM 1.2 is better at** ubiquity and maturity, and carries per-iDevice weights inline so
  the weighted overall needs no separate signal. It stays as the compatibility path for
  packages that predate the xAPI emitter (DEC-0003).
- **xAPI is better at** structured per-interaction granularity, dropping the fragile
  `suspend_data` regex and the pipwerks dependency, idempotent auditing, portability
  (mobile/offline/LRS), and being the modern, future-proof standard. It is the primary
  channel for packages that emit it (DEC-0064).

## Scope

In scope: consuming `exe_xapi.js` statements via `postMessage` and grading through the
existing pipeline. **Out of scope** (documented as such, consistent with the emitter):
**cmi5** (FTE-004/009) and any dependency on an **external LRS**. SCORM 1.2 remains as the
compatibility path (DEC-0003).
