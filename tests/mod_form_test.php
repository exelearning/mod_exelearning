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
require_once($CFG->dirroot . '/mod/exelearning/mod_form.php');

/**
 * Tests for the settings form validation (mod_form.php).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning_mod_form
 */
final class mod_form_test extends advanced_testcase {
    /**
     * Build the settings form for an existing instance.
     *
     * @param \stdClass $instance the exelearning instance row
     * @param \stdClass $course the course row
     * @return \mod_exelearning_mod_form
     */
    protected function build_form(\stdClass $instance, \stdClass $course): \mod_exelearning_mod_form {
        global $PAGE;
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        // Argument 2 of moodleform_mod is the section NUMBER (resolved via
        // get_section_info()), not the cm->section id — passing the id makes the
        // section lookup return null and construction fatals on $section->visible.
        $sectionnum = (int) get_fast_modinfo($course)->get_cm($cm->id)->sectionnum;
        $PAGE->set_course($course);
        // The moodleform_mod base treats $current->id as the instance id (per core mod_form tests).
        $current = (object) ['instance' => $instance->id, 'id' => $instance->id, 'course' => $course->id];
        return new \mod_exelearning_mod_form($current, $sectionnum, $cm, $course);
    }

    /**
     * Completion-by-grade ("receive a grade") on a registered gradable iDevice must
     * be saveable. Core's moodleform_mod::validation() otherwise rejects every
     * completiongradeitemnumber with badcompletiongradeitemnumber (mod_exelearning
     * exposes no per-itemnumber grade form field), making the DEC-0038 feature
     * impossible to save; the stopgap clears that error for a real gradebook column
     * while leaving an unregistered selection rejected (B7, DEC-0044).
     */
    public function test_completion_by_grade_saves_for_registered_item(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance([
            'course'     => $course->id,
            'grademodel' => EXELEARNING_GRADEMODEL_PERITEM,
        ]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $form = $this->build_form($instance, $course);

        $base = [
            // Identity fields core's moodleform_mod::validation() reads unguarded.
            'modulename'          => 'exelearning',
            'instance'            => $instance->id,
            'coursemodule'        => $cm->id,
            'name'                => $instance->name,
            'gradeenabled'        => 1,
            'grademodel'          => EXELEARNING_GRADEMODEL_PERITEM,
            'grademax'            => 100,
            'grademin'            => 0,
            'gradepass'           => 0,
            'completion'          => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade'  => 1,
            'completionpassgrade' => 0,
        ];

        // A registered per-iDevice item (itemnumber 1) is saveable.
        $errors = $form->validation($base + ['completiongradeitemnumber' => '1'], []);
        $this->assertArrayNotHasKey('completionpassgrade', $errors);

        // An unregistered itemnumber is still rejected (the stopgap does not mask it).
        $errors = $form->validation($base + ['completiongradeitemnumber' => '99'], []);
        $this->assertArrayHasKey('completionpassgrade', $errors);
    }
}
