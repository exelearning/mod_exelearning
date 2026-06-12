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
 * Shared fixtures for migration tests (issue #13 #3, DEC-0050).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local\migration;

/**
 * Test helpers for the migration suite.
 *
 * The sibling plugins (mod_exeweb / mod_exescorm) are not installed in CI, so these
 * helpers simulate a sibling's `package` filearea (with a parametrizable itemid, which
 * is what exposes the mod_exeweb revision bug) and build SCORM zips and source rows by
 * hand. Together they let the source handlers and the orchestrator be tested without
 * either sibling installed.
 */
trait helper_trait {
    /**
     * Creates an empty (no-package) target exelearning instance.
     *
     * @return array{0:\stdClass,1:int} The instance row and its module context id.
     */
    protected function create_empty_target(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance(['course' => $course->id, 'packagefilepath' => false]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        return [$instance, (int) \context_module::instance($cm->id)->id];
    }

    /**
     * Stores a file in a context as if it were a sibling plugin's package.
     *
     * The $itemid parameter is what makes the mod_exeweb revision storage testable:
     * mod_exeweb stores at itemid = {exeweb}.revision, not 0.
     *
     * @param int $contextid Context to host the stored file.
     * @param string $component Source frankenstyle component (e.g. mod_exeweb).
     * @param string $srcpath Path to the file to store.
     * @param string $filename Stored file name.
     * @param int $itemid Filearea itemid (0 for exescorm, revision for exeweb).
     * @return \stored_file
     */
    protected function store_sibling_package(
        int $contextid,
        string $component,
        string $srcpath,
        string $filename,
        int $itemid = 0
    ): \stored_file {
        $fs = get_file_storage();
        return $fs->create_file_from_pathname(
            [
                'contextid' => $contextid,
                'component' => $component,
                'filearea'  => 'package',
                'itemid'    => $itemid,
                'filepath'  => '/',
                'filename'  => $filename,
            ],
            $srcpath
        );
    }

    /**
     * Builds a SCORM zip embedding the given .elpx entries (plus a stub manifest).
     *
     * @param string[] $elpxentries Zip entry paths to fill with the fixture .elpx
     *                              (e.g. ['content/elp.elpx', 'backup/old.elpx']).
     * @return string Absolute path to the built zip.
     */
    protected function make_scorm_zip(array $elpxentries): string {
        global $CFG;
        $fixture = $CFG->dirroot . '/mod/exelearning/research/fixtures/elpx/actividad-evaluable.elpx';
        $stage = make_request_directory();
        file_put_contents($stage . '/imsmanifest.xml', '<manifest></manifest>');
        $files = ['imsmanifest.xml' => $stage . '/imsmanifest.xml'];
        foreach ($elpxentries as $entry) {
            $files[$entry] = $fixture;
        }
        $zip = make_request_directory() . '/scorm.zip';
        get_file_packer('application/zip')->archive_to_pathname($files, $zip);
        return $zip;
    }

    /**
     * Builds a source row matching source_interface::list_sources() output.
     *
     * @param array $overrides Values to override on the default row.
     * @return \stdClass
     */
    protected function make_source_row(array $overrides = []): \stdClass {
        return (object) array_merge([
            'cmid'                        => 0,
            'course'                      => 0,
            'coursename'                  => 'Course',
            'sectionnum'                  => 0,
            'instanceid'                  => 0,
            'contextid'                   => 0,
            'name'                        => 'Source activity',
            'intro'                       => '',
            'introformat'                 => FORMAT_HTML,
            'migrationid'                 => null,
            'cmvisible'                   => 1,
            'cmvisibleoncoursepage'       => 1,
            'cmgroupmode'                 => 0,
            'cmgroupingid'                => 0,
            'cmavailability'              => null,
            'cmlang'                      => '',
            'cmcompletion'                => 0,
            'cmcompletionview'            => 0,
            'cmcompletionexpected'        => 0,
            'cmcompletiongradeitemnumber' => null,
            'cmcompletionpassgrade'       => 0,
            'revision'                    => 0,
            'exescormtype'                => 'local',
            'reference'                   => '',
        ], $overrides);
    }
}
