<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Storage and extraction of the stored ELPX package (mod_exeweb style).
 *
 * Extracted verbatim from lib.php (DEC-0054). It owns the lifecycle of the
 * `package` and `content` fileareas: save the uploaded ZIP, locate the stored
 * archive at any itemid, validate it, extract it to `content/{revision}/` and
 * apply the serve-time SCORM transforms. It complements {@see package} (which
 * only parses content.xml for gradable iDevices) — it does not duplicate it.
 * lib.php keeps thin delegators with the original function signatures so every
 * existing caller (view.php, editor/*, mod_form.php, migration) is unchanged.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local;

use context_module;
use context_user;
use mod_exelearning\local\scorm\idevice_patch;
use mod_exelearning\local\scorm\scorm_injector;
use moodle_url;
use stdClass;

/**
 * Manages the stored ELPX package filearea and its extraction to content.
 */
final class package_manager {
    /**
     * Saves the uploaded ELPX in the 'package' filearea and extracts it to 'content/{revision}/'.
     *
     * @param stdClass $data Form data (with `coursemodule`, `package` draftid, `revision`).
     */
    public static function save_and_extract(stdClass $data): void {
        global $USER;

        if (empty($data->package)) {
            return;
        }
        $context = context_module::instance($data->coursemodule);
        $fs = get_file_storage();

        // Safety net against destroying the stored package (B1, DEC-0044). The
        // submitted value is a draft itemid that is non-empty even when it carries no
        // file; saving such an empty draft used to delete every stored package itemid
        // (the form reads itemid 0 but the embedded editor stores at itemid=revision),
        // leaving the activity with no content and the source .elpx unrecoverable.
        // When the incoming draft has no file but a package is already stored, keep
        // the existing one and just (re-)extract it to the current revision instead of
        // wiping it. data_preprocessing() seeds the draft from the stored package, so
        // a genuine settings save (or a real upload/replacement) still round-trips a
        // non-empty draft and falls through to the normal path below.
        $usercontext = context_user::instance($USER->id);
        $draftfiles = $fs->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            (int) $data->package,
            'id',
            false
        );
        if (empty($draftfiles) && self::get_stored_package($context->id) !== null) {
            self::extract_stored($context->id, (int) $data->revision);
            return;
        }

        // 1) Persist the ZIP in 'package/0/'.
        $fs->delete_area_files($context->id, 'mod_exelearning', 'package');
        file_save_draft_area_files(
            $data->package,
            $context->id,
            'mod_exelearning',
            'package',
            0,
            ['subdirs' => 0, 'maxfiles' => 1]
        );

        // 2) Extract the newly saved ZIP to the content filearea.
        self::extract_stored($context->id, (int) $data->revision);
    }

    /**
     * Locate the stored ELPX in the 'package' filearea WITHOUT assuming an itemid.
     *
     * The form upload stores it at itemid=0, but programmatic paths leave it at a
     * different itemid (e.g. the Moodle Playground `addModule`, which uploads with
     * `itemid: 1`, or `editor/save.php`, which uses the revision as itemid). We scan
     * ALL itemids and return the most recent file.
     *
     * @param int $contextid
     * @return \stored_file|null
     */
    public static function get_stored_package(int $contextid): ?\stored_file {
        $fs = get_file_storage();
        // Itemid=false means every itemid in the filearea.
        $files = $fs->get_area_files(
            $contextid,
            'mod_exelearning',
            'package',
            false,
            'itemid DESC, sortorder, filepath, filename',
            false
        );
        foreach ($files as $file) {
            if (!$file->is_directory()) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Whether a stored package archive is a real eXeLearning v4 package.
     *
     * Both `.elpx` and `.zip` are accepted on upload (DEC-0027); the genuine marker is
     * a `content.xml` (ODE 2.0) entry at the archive root, which every eXeLearning v4
     * export contains. Used by mod_form to reject an arbitrary .zip at submit time.
     *
     * @param \stored_file $file The uploaded package (.elpx or .zip).
     * @return bool True when the archive contains content.xml at its root.
     */
    public static function validate_content_xml(\stored_file $file): bool {
        $packer = get_file_packer('application/zip');
        $entries = $file->list_files($packer);
        if (!is_array($entries)) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry->pathname === 'content.xml') {
                return true;
            }
        }
        return false;
    }

    /**
     * Extracts the ELPX already stored in `package` to `content/{revision}/`.
     *
     * Kept separate from save_and_extract() so it can be re-run WITHOUT a draft
     * itemid (e.g. the view.php self-heal when a programmatic upload such as the
     * Playground's `addModule` left the package in the 'package' filearea but did
     * not extract/sync it). Idempotent: clears previous content and re-extracts.
     *
     * @param int $contextid
     * @param int $revision
     */
    public static function extract_stored(int $contextid, int $revision): void {
        $fs = get_file_storage();

        // Locate the stored ZIP (any itemid).
        $package = self::get_stored_package($contextid);
        if (!$package instanceof \stored_file) {
            return;
        }

        $data = (object) ['revision' => $revision];
        $context = (object) ['id' => $contextid];

        // 3) Clear previous content and extract to the current revision.
        $fs->delete_area_files($context->id, 'mod_exelearning', 'content');

        $packer = get_file_packer('application/zip');
        $package->extract_to_storage(
            $packer,
            $context->id,
            'mod_exelearning',
            'content',
            (int) $data->revision,
            '/'
        );

        // 4) Ensure index.html is set as mainfile (for the file browser).
        $entry = $fs->get_file(
            $context->id,
            'mod_exelearning',
            'content',
            (int) $data->revision,
            '/',
            'index.html'
        );
        if ($entry) {
            file_set_sortorder(
                $context->id,
                'mod_exelearning',
                'content',
                (int) $data->revision,
                '/',
                'index.html',
                1
            );
        }

        // 5) If the package (web export) does not include libs/SCORM_API_wrapper.js,
        // inject it from the plugin's assets/ directory. eXeLearning v4 only bundles
        // this wrapper in the SCORM export; without it, gradable iDevices display
        // "this page is not part of a SCORM package".
        foreach (['SCORM_API_wrapper.js', 'SCOFunctions.js'] as $shimname) {
            $present = $fs->get_file(
                $context->id,
                'mod_exelearning',
                'content',
                (int) $data->revision,
                '/libs/',
                $shimname
            );
            if ($present) {
                continue;
            }
            $assetpath = __DIR__ . '/../../assets/scorm/' . $shimname;
            if (!is_file($assetpath)) {
                continue;
            }
            $fs->create_file_from_pathname([
                'contextid' => $context->id,
                'component' => 'mod_exelearning',
                'filearea'  => 'content',
                'itemid'    => (int) $data->revision,
                'filepath'  => '/libs/',
                'filename'  => $shimname,
            ], $assetpath);
        }

        // 6) Inject <script src="libs/SCORM_API_wrapper.js"></script> into the
        // package HTML files. eXeLearning v4 only loads the wrapper on-demand when
        // the user clicks "Save score", but before that (in libs/common.js:1052) it
        // already checks `typeof pipwerks === 'undefined'` to decide whether to show
        // the "not a SCORM package" message or the save-score bar. By forcing the
        // load at page-load time, that check passes and the iDevice recognises the
        // SCORM environment.
        scorm_injector::inject($context->id, (int) $data->revision);

        // 7) Make the two iDevices that gate their score-save on the `exe-scorm` body
        // class also save in this web-export embedding (issue #13). All other gradable
        // iDevices save on `isScorm > 0` alone; only `form` and `scrambled-list` add a
        // `body.hasClass('exe-scorm')` condition, which is absent here (we serve a web
        // export). We drop that condition from their save guard at serve time.
        idevice_patch::patch($context->id, (int) $data->revision);
    }

    /**
     * Returns the URL of the ELPX stored in the 'package' filearea of an instance.
     *
     * Ported from mod_exeweb::exeweb_get_package_url() and adapted to this plugin:
     * filearea 'package', component 'mod_exelearning'. The itemid is taken from the
     * stored file (editor uploads use itemid = revision; form uploads use itemid = 0)
     * to build a URL servable via exelearning_pluginfile().
     *
     * @param stdClass $exelearning Instance record.
     * @param \context $context Module context.
     * @return moodle_url|null URL to the package file, or null if it does not exist.
     */
    public static function get_package_url($exelearning, $context): ?moodle_url {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $context->id,
            'mod_exelearning',
            'package',
            false,
            'itemid DESC, sortorder DESC, id ASC',
            false
        );
        $package = reset($files);
        if (!$package) {
            return null;
        }
        return moodle_url::make_pluginfile_url(
            $context->id,
            'mod_exelearning',
            'package',
            $package->get_itemid(),
            $package->get_filepath(),
            $package->get_filename()
        );
    }
}
