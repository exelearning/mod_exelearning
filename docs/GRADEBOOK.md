# Gradebook

How `mod_exelearning` maps a published eXeLearning package onto Moodle grade items, attempts and completion — for developers and admins.

## The two grading models

Grading has exactly two **mutually-exclusive** presentations, selected per instance by `grademodel`
(`lib.php:33-36`). The legacy "both" mode was removed (DEC-0008, rev. 2026-05-29).

| Model | Constant (value) | Gradebook columns | Overall column (`itemnumber=0`)? |
|---|---|---|---|
| **PER-ITEM** (default) | `EXELEARNING_GRADEMODEL_PERITEM` (1) | One column per gradable iDevice (`itemnumber` 1..100) | **No** |
| **OVERALL** | `EXELEARNING_GRADEMODEL_OVERALL` (0) | A single aggregated column (SCORM-style) | **Yes** |

The default is PER-ITEM (`lib.php:36,99`; `db/install.xml:25` `grademodel DEFAULT="1"`).

The two models are now **symmetric**: OVERALL shows only the aggregated column, PER-ITEM shows only the
per-iDevice columns. There is **no hidden overall stub** in PER-ITEM anymore — a hidden item still showed (greyed)
to teachers with `moodle/grade:viewhidden` and was reported as a confusing "extra grade", so DEC-0038 removed it.
`exelearning_sync_grade_items()` enforces this: OVERALL un-hides/creates the overall item (`lib.php:1146-1151`),
PER-ITEM deletes any stray overall left over from a previous sync or the legacy hidden-stub model
(`lib.php:1152-1165`). `exelearning_grade_item_update()` carries the same guard so core's unconditional
regrade calls cannot resurrect a phantom overall column (`lib.php:375-387`; B2b, DEC-0044).

## `itemnumber` semantics

`itemnumber` is the routing axis shared by `exelearning_grade_item` and `exelearning_attempt`
(`db/install.xml:49,77`):

- **`0`** = the overall aggregated grade (only exists in OVERALL mode).
- **`1..100`** = one gradable iDevice each.

Moodle 5.x can only label grade items whose `itemnumber` is declared in a component mapping. `mod_exelearning`
implements `core_grades\local\gradeitem\itemnumber_mapping` in `classes/grades/gradeitems.php:52`, with
`MAX_ITEMNUMBER = 100` (`:54`). The mapping is `0 => 'overall'`, `1..100 => 'idevice1'..'idevice100'`
(`:61-71`). Each name maps to a lang string `grade_<itemname>_name`, so the **trap** is that
`grade_overall_name` (`lang/en/exelearning.php:203`) and `grade_idevice1_name` … `grade_idevice100_name`
(`lang/en/exelearning.php:103-114`, 100 entries) **must all exist** or the completion-via-grade dropdown and
Course-overview column labelling break. Registration stops at the cap — beyond 100 gradable iDevices the extra
items are not registered as columns (`lib.php:1249-1261`), with a developer-level `debugging()` warning.

## objectid-stable routing (DEC-0017)

Grade items are keyed by the package's stable `objectid` (the `<odeIdeviceId>` from `content.xml`), **not** by the
page-local index the producer emits — which collides across pages. The `exelearning_grade_item` table stores
`objectid char(191)` with a UNIQUE `(exelearningid, objectid)` index (`db/install.xml:50,67`), and
`exelearning_sync_grade_items()` looks up existing rows by `objectid` (`lib.php:1224`), assigning a new monotonic
`itemnumber` only when an objectid is first seen (`lib.php:1262-1278`).

- **Soft-delete on re-upload**: an iDevice that disappears in a re-upload has its row marked `deleted=1` (preserving
  grade history) and its gradebook column removed (`lib.php:1319-1335`; `db/install.xml:56`). A re-appearing iDevice
  keeps its original `itemnumber`.
- **Content-hash staleness detection (DEC-0021)**: each row stores `contenthash` (sha1 of the iDevice content block,
  `db/install.xml:57`). An in-place options/scoring edit keeps the `objectid` but changes the hash; the sync flags it
  as `changed` (`lib.php:1232-1234`) so the teacher can be warned that existing grades are now stale. Existing
  attempts/grades are **not** recomputed — the scoring runs client-side, so the server cannot re-derive them.

`track::ingest()` accepts only objectids the package exposes for **this** instance and ignores unknown ones — it
never creates grade items from the client (`classes/local/track.php:46-49`).

## Attempt aggregation

Each user submission is one row per gradable item in `exelearning_attempt`, keeping attempt history (DEC-0007). The
gradebook grade for an item is aggregated across that user's attempts by the per-instance `grademethod`:

| Method | Constant (value) | Behaviour |
|---|---|---|
| highest *(default)* | `attempts::GRADE_HIGHEST` (0) | `max()` of scaled scores |
| average | `attempts::GRADE_AVERAGE` (1) | mean of all attempts |
| first | `attempts::GRADE_FIRST` (2) | first attempt |
| last | `attempts::GRADE_LAST` (3) | most recent attempt |
| lowest | `attempts::GRADE_LOWEST` (4) | `min()` of scaled scores |

Constants: `classes/local/attempts.php:33-42`. The aggregation itself is `attempts::aggregate_scaled()`
(`:279-311`), which reads each attempt's `scaledscore` (a 0..1 value stored as `rawscore/maxscore`,
`db/install.xml:80`) and returns a single scaled score (or `null` when there are no attempts).

On publish, `exelearning_recalculate_user_grades()` multiplies that scaled score by the instance `grademax`
(`lib.php:1431`, `rawgrade = scaled * grademax`) and calls `grade_update()` per item, skipping `itemnumber=0` in
PER-ITEM and `itemnumber>0` in OVERALL (`lib.php:1413-1442`). `exelearning_update_grades()` fans this out across
every user with attempts (`lib.php:445-451`), and short-circuits when grading is disabled (`lib.php:435-438`).

## gradepass + completion-by-grade (DEC-0010)

`gradepass` (`db/install.xml:22`, default 0) is the grade required to pass; it feeds Moodle's native
completion-by-grade ("require passing grade", `completionpassgrade`), SCORM-style. `track::ingest()` recalculates
completion after grading (`classes/local/track.php:220`). Completion-by-grade keeps working the Moodle-native way:
the teacher points `completiongradeitemnumber` at a per-iDevice item (workshop model) or uses OVERALL mode to
complete on passing the activity as a whole (`lib.php:1142-1145`). The plugin relaxes core's
`badcompletiongradeitemnumber` rejection for a registered gradable item via
`exelearning_relax_completion_grade_errors()` (`mod_form.php:328-332`, `lib.php:1471-1518`; B7, DEC-0044).

**Validation rule**: `gradepass` must lie in `[grademin, grademax]` (when non-zero), and `grademin` must not exceed
`grademax`, otherwise "require passing grade" completion is unreachable (`mod_form.php:338-343`; strings
`err_grademinmax`, `err_gradepassrange` at `lang/en/exelearning.php:90-91`).

## When grading is disabled (`gradeenabled=0`, DEC-0029)

`gradeenabled` is the master grading switch (`db/install.xml:26`, default 1). When unchecked, the mod_form
`disabledIf` rules grey out **all** grade and attempt fields — `grademodel, grademax, grademin, gradepass,
gradedisplaytype, gradecat, maxattempt, grademethod, reviewmode` (`mod_form.php:221-227`). The module then creates
no grade items and no reports and behaves like a plain resource: `exelearning_sync_grade_items()` removes all grade
items and detects nothing (`lib.php:1123-1126`), and `exelearning_update_grades()` returns early
(`lib.php:435-438`). Attempt history (`exelearning_attempt`) is preserved.

**Caveat**: `FEATURE_GRADE_HAS_GRADE` is **static** — `exelearning_supports()` returns `true` unconditionally
(`lib.php:58-59`), regardless of `gradeenabled`. So Moodle still classifies the activity type as gradable even when a
given instance is not. This functional classification mismatch is tracked in the audit follow-up — see the new ADR
**DEC-0047** (functional classification) and `docs/AUDIT_FOLLOWUP.md`.

## Worked example

An `.elpx` with **3 gradable iDevices**:

- **PER-ITEM** (default) → **3 gradebook columns**, `itemnumber` 1, 2, 3 — one per iDevice, no overall column.
- **OVERALL** → **1 aggregated column**, `itemnumber=0` — the three iDevice scores are recomputed into a single
  overall (DEC-0018) and the per-iDevice rows are kept only for the attempts report (`lib.php:1282-1294`).

Switching an existing instance between the two models deletes and recreates the columns and re-publishes from
`exelearning_attempt` (`exelearning_update_grades()` → `exelearning_recalculate_user_grades()`).

## Admin-facing settings summary

The Grading and Attempts sections of the activity form (`mod_form.php:78-227`), with defaults from `db/install.xml`:

| Setting | Field | Options / range | Default |
|---|---|---|---|
| Graded? (master switch) | `gradeenabled` | on / off | on (1) — `db/install.xml:26` |
| Gradebook columns model | `grademodel` | PER-ITEM / OVERALL | PER-ITEM (1) — `db/install.xml:25`, `mod_form.php:105` |
| Maximum grade (per item) | `grademax` | float | 100 — `db/install.xml:20`, `mod_form.php:115` |
| Minimum grade | `grademin` | float | 0 — `db/install.xml:21`, `mod_form.php:125` |
| Grade to pass | `gradepass` | float in `[grademin,grademax]`, 0 = none | 0 — `db/install.xml:22`, `mod_form.php:136` |
| Grade display type | `gradedisplaytype` | DEFAULT / REAL / PERCENTAGE / LETTER / REAL_PERCENTAGE | DEFAULT (0) — `db/install.xml:23`, `mod_form.php:155` |
| Grade category | `gradecat` | course grade categories | course top category (0) — `db/install.xml:34` |
| Maximum attempts | `maxattempt` | int, 0 = unlimited | 0 — `db/install.xml:27`, `mod_form.php:187` |
| Attempt aggregation | `grademethod` | highest / average / first / last / lowest | highest (0) — `db/install.xml:24`, `mod_form.php:202` |
| Attempt review | `reviewmode` | never / always / after completion | always (1) — `db/install.xml:28`, `mod_form.php:216` |

## See also

- `docs/TRACKING.md` — how per-iDevice scores arrive at `track::ingest()` (and `docs/tracking-architecture.md` for
  the dual SCORM 1.2 + xAPI pipeline).
- `docs/PRIVACY_BACKUP_FILES.md` — backup/restore of `exelearning_grade_item` and attempt data
  (`backup/moodle2/backup_exelearning_stepslib.php`).
- `research/decisiones/adr/` — DEC-0008, DEC-0010, DEC-0017, DEC-0021, DEC-0029, DEC-0038 (and the new DEC-0047).
