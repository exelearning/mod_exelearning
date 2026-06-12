# Architecture — `mod_exelearning`

> Responsibility map of the plugin. `mod_exelearning` delivers and grades eXeLearning v4
> (`.elpx`) packages inside a Moodle course, preserving the native eXe sidebar and
> recording multiple gradable items per activity.

## One-paragraph model

A teacher uploads an `.elpx` (a ZIP with `content.xml`). `lib.php` stores it and extracts
it to a per-revision file area; `local\package` parses `content.xml` and enumerates the
**gradable iDevices**; `lib.php` registers one Moodle grade item per iDevice
(multi-`itemnumber`). At view time `view.php` serves the package inside a sandboxed
iframe and injects a SCORM 1.2 `window.API` shim; learner interactions are POSTed to
`track.php`, normalised and scored **server-side** by `local\track`, recorded as
attempts by `local\attempts`, and pushed to the gradebook by `lib.php`. The same scoring
pipeline backs the `save_track` web service. `classes/external` exposes the API surface;
`classes/privacy` declares personal data; `backup/moodle2` exports/imports the activity.

## Layered responsibility map

| Layer | Code | Responsibility | Why it lives here |
|---|---|---|---|
| **Moodle façade** | `lib.php` | Module callbacks: `*_supports`, `*_add/update/delete_instance`, `*_pluginfile`, `*_get_file_areas`, grade callbacks (`*_grade_item_update`, `*_update_grades`, `*_recalculate_user_grades`), reset, settings navigation. Orchestrates package storage/extraction and gradebook sync. | Moodle requires these named functions in `lib.php`; they are the contract with core, not domain logic that should be relocated. |
| **Package domain** | `classes/local/package.php` | Parse `content.xml`, detect gradable iDevices (`isScorm`), content hashing, XML hardening. | Pure parsing/detection — testable in isolation; no Moodle DB/UI coupling. |
| **Tracking domain** | `classes/local/track.php` | Ingest tracking payloads: normalise/clamp scores, route by stable `objectid`, recompute the overall server-side, enforce attempt caps, drive completion. | Single source of truth for scoring; reused by both `track.php` and the `save_track` web service so web and WS cannot diverge ([[DEC-0040]]). |
| **Attempts domain** | `classes/local/attempts.php` | Attempt numbering (session-token grouping), upsert of `exelearning_attempt`, aggregation (highest/average/first/last/lowest). | Encapsulates attempt rules and aggregation independent of transport. |
| **Gradebook mapping** | `classes/grades/gradeitems.php` | `itemnumber_mapping` (0=overall, 1..100=iDevice) for Moodle 5.x completion-by-grade. | Implements a core interface; `strict_types`. |
| **API boundary** | `classes/external/*` (7 classes) | Web services for the mobile app + admin AJAX editor management; each validates context, login and capability. | The published Moodle external contract; fully declared in `db/services.php` ([[DEC-0040]]). |
| **Privacy** | `classes/privacy/provider.php` | Declares `exelearning_attempt` metadata + the `core_grades` data flow; export/delete with grade recalculation. | Moodle Privacy API subsystem. |
| **Backup/Restore** | `backup/moodle2/*` | Export/import instance, grade-item mappings, attempts (gated by `userinfo`), and the `intro`/`package`/`content` file areas; remap user ids. | Moodle Backup/Restore API. |
| **Events** | `classes/event/*` | `attempt_deleted`, `report_viewed`, `course_module_instance_list_viewed`. | Selective observability ([[DEC-0041]]); no per-commit event (would be noise). |
| **Global search** | `classes/search/activity.php` | Search area extending `\core_search\base_activity`: indexes the activity `intro` and, via file indexing, the text extracted from the package `content` file area. | Makes eXe content findable in Moodle global search; visibility/context resolved by the base class ([[DEC-0053]]). |
| **Editor integration** | `classes/local/embedded_editor_*`, `classes/admin/*`, `amd/src/editor_modal.js`, `editor/index.php`, `manage_embedded_editor_upload.php` | Resolve/install/update/repair/uninstall the embedded editor; `postMessage` open/export bridge; save → re-extract → re-sync. | Embedded-editor-only model ([[DEC-0009]]). |
| **Entry points** | `view.php`, `track.php`, `grade.php`, `report.php`, `mod_form.php` | View + SCORM shim; tracking endpoint; gradebook deep-link; attempts report; activity form. | Thin controllers; security checks here, scoring logic delegated to `local\*`. |

## Request flows

**Authoring (upload):**
`mod_form.php` (validate zip-has-`content.xml`) → `exelearning_add/update_instance`
(`lib.php`) → `exelearning_save_and_extract_package` → `local\package::detect_gradable_idevices`
→ `exelearning_sync_grade_items` → `grade_update(..., itemnumber=N, ...)`.

**Delivery + grading (learner):**
`view.php` (sandboxed iframe + `window.API` shim) → iDevice JS (pipwerks SCORM 1.2) →
POST `track.php` (`require_sesskey` + `require_capability('mod/exelearning:savetrack')`)
→ `local\track::ingest()` (normalise/clamp, objectid routing, server-side overall
recompute, attempt cap) → `local\attempts` (record) → `grade_update` + completion.
See `docs/TRACKING.md`.

**External/mobile:** `classes/external/save_track::execute` reuses the **same**
`local\track::ingest()`; read services (`get_user_grades`, `get_user_attempts`, …) read
the same tables. See `docs/EXTERNAL_SERVICES.md`.

## Design principles observed in the code

- **Thin controllers, fat domain classes.** Entry-point PHP files do auth + transport;
  scoring/parsing/aggregation live in `classes/local/*`. The only "heavy" code in
  `lib.php` is the grade-sync set — which **must** be there because they are Moodle
  callbacks, not because of poor layering.
- **One scoring pipeline.** Web and web-service paths converge on `track::ingest()`
  ([[DEC-0040]]), so a fix or a hardening applies to both.
- **Server authority over grades.** The client never sets the overall; it is recomputed
  from per-iDevice scores ([[DEC-0018]]). See `docs/TRACKING.md`.
- **Stable identity.** Grade routing keys on the package `objectid`, not page order
  ([[DEC-0017]]); items soft-delete and carry a content hash for staleness ([[DEC-0021]]).
- **Defensive parsing.** `content.xml` is parsed with a hardened DOM loader and a
  controlled regex fallback ([[DEC-0039]]). See `docs/ELPX_PACKAGE.md`.

## Known, deliberate coupling (technical debt, tracked)

The package HTML is mutated at extraction to inject the SCORM wrapper
(`exelearning_inject_scorm_loader`) and hide the teacher-mode toggle
(`exelearning_require_teacher_mode_hider`). This couples the plugin to eXeLearning v4
internals. It is recognised as the main debt and has a documented exit:
serve-time transform ([[DEC-0045]], deferred) → upstream option ([[DEC-0046]]) → xAPI
([[DEC-0032]]). The SCORM 1.2 shim in `view.php` is **not** debt.

## Functional classification

`exelearning_supports()` declares `MOD_ARCHETYPE_ASSIGNMENT` + `MOD_PURPOSE_ASSESSMENT`
(`lib.php:46-67`). These are resolved per **module type**, not per instance, so they do
not vary with the per-activity `gradeenabled` switch ([[DEC-0029]]); `gradeenabled = 0`
is a resource-like mode within an assessment-archetype module. Decision recorded in
[[DEC-0047]]; see `docs/AUDIT_FOLLOWUP.md`.

## Global search

`classes/search/activity.php` registers a single search area (`mod_exelearning/activity`)
by extending `\core_search\base_activity`. The base class resolves the module context and
enforces visibility/access; the subclass only declares what to index:

- The activity **`intro`** is indexed as the document content (default `get_document()`).
- `uses_file_indexing()` returns `true` and `get_search_fileareas()` returns
  `['intro', 'content']`, so the HTML/text **extracted from the `.elpx` package** (the
  `content` file area populated by `exelearning_save_and_extract_package`, see
  `lib.php`) is attached and text-indexed — making the authored eXe content findable.

The area is auto-discovered; an admin enables it from the global search engine and
reindexes (`php admin/cli/search.php`). Two adjacent integrations were considered and
**deferred** ([[DEC-0053]]): `core\activity_dates` (no `timeopen`/`timeclose` window in
`mod_form.php` today) and analytics indicators (only useful with active models).

## Related documentation

`docs/EXTERNAL_SERVICES.md` · `docs/GRADEBOOK.md` · `docs/TRACKING.md` ·
`docs/ELPX_PACKAGE.md` · `docs/EMBEDDED_EDITOR.md` · `docs/PRIVACY_BACKUP_FILES.md` ·
`docs/RELEASE_CHECKLIST.md` · `docs/AUDIT_FOLLOWUP.md` · `research/decisiones/adr/`.
