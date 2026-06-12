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

namespace mod_exelearning;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

/**
 * Tests for lib.php stored-package helpers.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::exelearning_get_stored_package
 * @covers     ::exelearning_package_has_content_xml
 * @covers     \mod_exelearning\local\package_manager
 */
final class lib_package_test extends advanced_testcase {
    /**
     * The stored package of an instance is found and recognised as a real
     * eXeLearning package (content.xml at the archive root).
     */
    public function test_get_stored_package_and_content_xml(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $context = \context_module::instance($cm->id);

        $package = exelearning_get_stored_package($context->id);
        $this->assertInstanceOf(\stored_file::class, $package);
        $this->assertTrue(exelearning_package_has_content_xml($package));

        // A context with no package returns null.
        $this->assertNull(exelearning_get_stored_package(\context_course::instance($course->id)->id));
    }

    /**
     * A plain .zip without content.xml is rejected as a package.
     */
    public function test_package_has_content_xml_rejects_plain_zip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $ziptmp = make_temp_directory('mod_exelearning') . '/nocontent-' . random_string(6) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($ziptmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('readme.txt', 'no content.xml here');
        $zip->close();

        $fs = get_file_storage();
        $file = $fs->create_file_from_pathname([
            'contextid' => \context_system::instance()->id,
            'component' => 'mod_exelearning',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'plain.zip',
        ], $ziptmp);

        $this->assertFalse(exelearning_package_has_content_xml($file));
    }
}
