# Embedded eXeLearning editor — sources, lifecycle and postMessage bridge

> How `mod_exelearning` resolves, installs and embeds the static eXeLearning v4
> editor, and how the in-browser editor saves a package back into the activity.
> Embedded-only by design (DEC-0009): no eXeLearning Online, no HMAC, no remote
> service. (DEC-0005 — the original embedded/online toggle — is superseded.)

## 1. Source precedence: moodledata wins over bundled

The editor is served from a local directory; there are two possible sources and a
fixed precedence policy `moodledata → bundled → none`, implemented in the single
source of truth `embedded_editor_source_resolver`:

| Order | Source | Location | Constant |
|-------|--------|----------|----------|
| 1 (highest) | Admin-installed | `$CFG->dataroot/mod_exelearning/embedded_editor/` | `SOURCE_MOODLEDATA` |
| 2 | Bundled with the plugin | `$CFG->dirroot/mod/exelearning/dist/static/` | `SOURCE_BUNDLED` |
| 3 | None usable | — | `SOURCE_NONE` |

- Precedence is decided in `embedded_editor_source_resolver::get_active_source()`
  (`classes/local/embedded_editor_source_resolver.php:136-146`): the admin-installed
  copy is checked first, then the bundled copy.
- A directory is "usable" only if it passes
  `validate_editor_dir()` — `index.html` exists and is readable, plus at least one
  of `app`, `libs`, `files` (`embedded_editor_source_resolver.php:88-107`,
  `EXPECTED_ASSET_DIRS` at `:57`).
- The Moodle paths are fixed: `MOODLEDATA_SUBDIR = 'mod_exelearning/embedded_editor'`
  (`:49`, `get_moodledata_dir()` `:64-67`) and the bundled
  `dist/static` (`get_bundled_dir()` `:74-77`).
- The README states the same policy in prose
  (`README.md:160-165`), and the admin help string matches it: *"The version
  installed by the administrator takes priority over the bundled one"*
  (`lang/en/exelearning.php:66`, key `editormanagementhelp`).

`lib.php` exposes thin wrappers over the resolver used across the codebase:
`exelearning_get_embedded_editor_index_source()` (`lib.php:1683-1685`),
`exelearning_embedded_editor_enabled()` (`lib.php:1694-1696`),
`exelearning_embedded_editor_uses_local_assets()` (`lib.php:1703-1705`).

## 2. Lifecycle: install / update / repair / uninstall

All install operations are orchestrated by
`embedded_editor_installer` (`classes/local/embedded_editor_installer.php`).
They write into moodledata only, never into the Moodle code tree.

| Operation | Method | Notes |
|-----------|--------|-------|
| Install latest | `install_latest()` `:70-80` | discovers the newest release, then `do_install()` |
| Install a specific version | `install_version($v)` `:89-99` | skips discovery |
| Install from a local ZIP | `install_from_local_zip($zip, $v)` `:109-119` | used by the Playground upload endpoint |
| Update | (via external API) | runs `install_latest()` over the existing copy |
| Repair | (via external API) | `uninstall()` then `install_latest()` |
| Uninstall | `uninstall()` `:775-781` | `remove_dir()` + clear config metadata |

### Release discovery and integrity

`do_install()` (`:128-153`) runs the full pipeline:

1. **Discover the latest version** from the GitHub *Atom* feed
   `https://github.com/exelearning/exelearning/releases.atom`
   (`GITHUB_RELEASES_FEED_URL` `:41`; `discover_latest_version()` `:161-163`,
   feed parsing `:226-243`). The first `<entry>` is newest.
2. **Fetch the published SHA-256** for the `exelearning-static-v<version>.zip`
   asset (`ASSET_PREFIX` `:47`) from the GitHub Releases *REST* API
   `GITHUB_RELEASES_API_URL` (`:44`), reading the asset `digest` field
   (`fetch_release_asset_sha256()` `:409-436`,
   `extract_asset_sha256_from_release_api()` `:445-463`). This binds the install
   to GitHub release metadata, not just transport TLS (TAREA-010 / RIE-008,
   DEC-0016).
3. **Download** the asset to a temp file (`download_to_temp()` `:486-520`) and
   **verify** its SHA-256 with `hash_equals()`
   (`verify_file_sha256()` `:472-477`); a mismatch raises `editordigestmismatch`.
4. **Validate, extract, normalize, install**: ZIP magic-byte check
   (`validate_zip()` `:528-539`), per-entry zip-slip rejection *before* extracting
   (`extract_to_temp()` `:617-625`, reusing `styles_service::is_unsafe_zip_entry()`),
   layout normalization for 1–3 nesting levels (`normalize_extraction()` `:654-686`),
   content validation (`validate_editor_contents()` `:694-698`), then an atomic
   `rename()` install with backup/rollback (`safe_install()` `:708-770`).
5. **Store metadata**: `embedded_editor_version` and `embedded_editor_installed_at`
   in plugin config (`store_metadata()` `:788-791`).

### TLS posture

Outbound requests verify the certificate chain and keep Moodle's SSRF blocklist
active on a real server; both are relaxed **only** under the Moodle Playground
php-wasm runtime, gated on the `MOODLE_PLAYGROUND` constant
(`is_playground()` `:190-192`, `curl_security_options()` `:203-213`).

### Concurrent-install lock

Each install acquires a config lock `embedded_editor_installing`
(`acquire_lock()` `:824-830`, `release_lock()` `:835-837`). A second install while
one is in progress throws `editorinstallconcurrent`. The lock is considered stale
after `INSTALL_LOCK_TIMEOUT = 300` seconds (`:59`), which also bounds the PHP time
limit and the download timeout.

### External API and capabilities

The AJAX-facing actions live in `manage_embedded_editor`
(`classes/external/manage_embedded_editor.php`):

- `execute_action($action)` (`:70-118`) accepts `install`, `update`, `repair`,
  `uninstall` and maps them onto the installer (`update`→`install_latest`,
  `repair`→`uninstall`+`install_latest`).
- `get_status($checklatest)` (`:183-250`) returns the active source, installed
  version, `update_available`, the install-lock state (`installing`,
  `install_stale`) and per-action capability flags.

**Both** endpoints require, in the **system context**,
`moodle/site:config` **and** `mod/exelearning:manageembeddededitor`
(`execute_action()` `:83-84`, `get_status()` `:189-190`).

### Playground same-origin upload path

In Moodle Playground the browser downloads the release ZIP and POSTs it to the
same-origin endpoint `manage_embedded_editor_upload.php`, which re-checks
`require_login()` + `require_sesskey()` + the same two capabilities
(`manage_embedded_editor_upload.php:51-56`), then calls
`install_from_local_zip()` (`:96`). This keeps the heavy network fetch in the
browser; the WASM runtime only does local extraction/install.

## 3. Admin UI

Editor management is an **in-settings AJAX widget**, not a standalone page. It is
registered from `settings.php` (`:44-55`) under
`admin/settings.php?section=modsettingexelearning`. The custom setting
`admin_setting_embeddededitor` stores no value (`get_setting()`/`write_setting()`
are no-ops) and only renders the status/action card
(`classes/admin/admin_setting_embeddededitor.php:58-72`), reading
`embedded_editor_source_resolver::get_status()` and wiring the AMD module
`mod_exelearning/admin_embedded_editor` (`:85-135`). The card reads locally-cached
state on render; the "latest version" check is an explicit AJAX call.

## 4. Embedding the editor and the postMessage bridge

The editor bootstrap page is `editor/index.php`. Access requires
`require_login()` + `context_module` + `require_capability('moodle/course:manageactivities')`
+ `require_sesskey()` — teachers only (`editor/index.php:79-82`). It reads the
active editor `index.html` (resolver), injects a `<base>` tag pointing at
`editor/static.php/<cmid>` and a Moodle config script, swallows 404s on
missing `.css`/`idevices` resources, disables `preview-sw.js` registration, and
appends the bridge script `amd/src/moodle_exe_bridge.js`
(`editor/index.php:98-322`). The response sets `X-Frame-Options: SAMEORIGIN`
(`:326`).

The client-side overlay is `amd/src/editor_modal.js`. A delegated click on a
`[data-action="mod_exelearning/editor-open"]` button (`:722-739`) opens a
full-screen overlay containing an `<iframe>` whose `src` is the editor URL
(`open()` `:643-715`).

### Protocol messages

`postToEditor()` (`:241-250`) is the single send path; it forwards `transfer`
arguments so binary payloads move by ownership transfer rather than copy. Key
messages:

| Direction | Type | Purpose |
|-----------|------|---------|
| host → editor | `CONFIGURE` | sent on `EXELEARNING_READY`, hides file menu / save / user menu (`:465-478`) |
| host → editor | `OPEN_FILE` | sends the current package as an `ArrayBuffer` (transferable) with a `requestId` (`:341-352`) |
| host → editor | `REQUEST_EXPORT` | asks the editor to export the current document (`:414-430`) |
| editor → host | `EXELEARNING_READY` / `DOCUMENT_LOADED` / `DOCUMENT_CHANGED` | lifecycle (`:464-488`) |
| editor → host | `OPEN_FILE_SUCCESS` / `OPEN_FILE_ERROR` | open ack, matched by `requestId` (`:490-506`) |
| editor → host | `EXPORT_FILE` | returns the exported bytes for upload (`:508-517`) |

A legacy `exeweb-editor` message dialect is still handled for older static
builds (`handleLegacyBridgeMessage()` `:537-560`).

### Request de-dup, retry and backoff

Each request carries a unique `requestId` from `nextRequestId()` (`:45-48`).
Responses are accepted only when their `requestId` matches the pending
`openRequestId` / `exportRequestId` (`:491`, `:500`, `:509`). The initial
`OPEN_FILE` is retried up to `MAX_OPEN_ATTEMPTS = 3` with linear backoff
(`scheduleOpenRetry()` `:269-277`, `armOpenResponseTimer()` `:284-295`,
`OPEN_RESPONSE_TIMEOUT_MS = 3000` `:22`).

### Origin handling — current behavior and a hardening opportunity

`editorOrigin` is derived from the editor URL via `getOrigin()` (`:31-37`), which
returns `new URL(...).origin` **or falls back to `'*'` when the URL cannot be
parsed** (`:34`). `editorOrigin` starts as `'*'` (`:10`) and is assigned in
`open()` (`:650`).

- On send, `postToEditor()` posts with `editorOrigin` as the target origin
  (`:246-248`). If it fell back to `'*'`, the message is broadcast to any frame.
- On receive, `isEditorBridgeMessage()` always checks
  `event.source === iframe.contentWindow`, but only checks `event.origin` when
  `editorOrigin !== '*'` (`:441-449`). When the fallback is in effect, the origin
  check is skipped and only the source identity is enforced (RIE-010 notes the same
  boundary requirement for the legacy bridge).

> **Hardening opportunity (not a current guarantee).** Because the editor is
> same-origin in the standard deployment, `getOrigin()` normally resolves to the
> Moodle origin and the check is strict. But the documented fallback to `'*'`
> means origin validation is **not unconditional**. A future hardening could
> require a concrete origin (e.g. derive it from `$CFG->wwwroot`, as
> `editor/index.php:138-145` already computes `parentOrigin`/`trustedOrigins`)
> and refuse to post/accept when it cannot be resolved. Do not describe the
> current code as strict origin validation.

## 5. Save / export flow

The "Save to Moodle" button drives the export round-trip
(`editor_modal.js:676-681`, `requestExport()` `:414-430`):

1. Host posts `REQUEST_EXPORT`; the editor replies with `EXPORT_FILE` carrying the
   exported bytes (`:508-517`).
2. `uploadExportedFile()` (`:366-407`) builds a `FormData` with the `package` blob,
   `format`, `cmid` and `sesskey` and POSTs it to `session.saveUrl`, which is
   `editor/save.php` (set in `editor/index.php:93`,
   `__MOODLE_EXE_CONFIG__.saveUrl`).

   > Note: the save endpoint is `editor/save.php` (content save), **not**
   > `manage_embedded_editor_upload.php` (which installs the *editor itself*, §2).
3. `editor/save.php` re-checks `require_login()` + `require_sesskey()` +
   `require_capability('moodle/course:manageactivities')` (`editor/save.php:43-46`),
   stores the upload in the **`package` filearea** at `itemid = revision + 1`
   (`:70-83`), bumps `revision` (`:113-116`), deletes older package revisions
   (`:118-124`), re-extracts the package into the **`content/{revision}`** filearea
   with the SCORM loader shim (`exelearning_extract_stored_package()` `:132`), and
   **re-detects gradable iDevices** via `exelearning_sync_grade_items()` (`:133`) —
   new iDevices add gradebook columns, removed ones are soft-deleted with grade
   history preserved (DEC-0021 warning via `exelearning_warn_if_grades_stale()`
   `:138`). It returns `{success, revision, format}`.
4. The client rewrites the package and content URLs to the new revision with a
   cache buster (`updatePackageUrlRevision()` `:57-71`,
   `updateContentUrlRevision()` `:98-112`), refreshes the activity iframe, then
   reloads `view.php` so server-rendered blocks reflect the re-synced gradebook
   (`:393-406`).

## 6. Relation to the eXeLearning LMS-embedding model

`mod_exelearning` uses eXeLearning's LMS-embedding model in its embedded-only
variant. The plugin embeds the **static** editor in a same-origin iframe and
exchanges packages purely over `postMessage` + same-origin AJAX. The eXeLearning
**Online** mode — a remote authenticated service with HMAC-signed tokens — was
deliberately discarded (DEC-0009,
`research/decisiones/adr/DEC-0009-solo-editor-embebido.md:23-45`): no
`editormode` toggle, no `exeonlinebaseuri`, no `hmackey1`, no token TTL. The
only outbound traffic is the admin-driven download of the editor release from
GitHub (§2).
