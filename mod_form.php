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
 * mod_exelearning configuration form.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->libdir . '/grade/constants.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * mod_exelearning module instance settings form.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_exelearning_mod_form extends moodleform_mod {
    /**
     * Defines the form fields for the module instance.
     *
     * @return void
     */
    public function definition() {
        global $COURSE;
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements();

        // File manager for the ELPX v4 package.
        $mform->addElement(
            'filemanager',
            'package',
            get_string('package', 'mod_exelearning'),
            null,
            [
                    'subdirs' => 0,
                    'maxbytes' => 0,
                    'maxfiles' => 1,
                    'accepted_types' => ['.elpx', '.zip'],
            ]
        );
        $mform->addHelpButton('package', 'package', 'mod_exelearning');
        // The package is optional (issue #13 #1, DEC-0024): leaving it empty creates
        // an empty activity that the teacher authors in place with the embedded
        // editor ("Edit with eXeLearning"), mirroring how the sibling plugins let
        // you start a new resource from scratch. Uploading an .elpx still works.

        // Grading configuration. Split into two sections — "Grading" (how the
        // activity is scored) and "Attempts management" (how multiple attempts are
        // handled) — mirroring mod_scorm / mod_exescorm so the grade settings stay
        // focused and uncluttered (DEC-0031).
        $mform->addElement(
            'header',
            'gradingsection',
            get_string('gradingheading', 'mod_exelearning')
        );

        // Master grading switch (issue #13, DEC-0029): when off, the activity
        // creates no grade items, no reports, and shows nothing in the gradebook
        // (it behaves like a plain resource). On by default.
        $mform->addElement(
            'advcheckbox',
            'gradeenabled',
            get_string('gradeenabled', 'mod_exelearning')
        );
        $mform->setDefault('gradeenabled', 1);
        $mform->addHelpButton('gradeenabled', 'gradeenabled', 'mod_exelearning');

        // Gradebook columns model (DEC-0008).
        $mform->addElement(
            'select',
            'grademodel',
            get_string('grademodel', 'mod_exelearning'),
            [
                    EXELEARNING_GRADEMODEL_PERITEM => get_string('grademodel_peritem', 'mod_exelearning'),
                    EXELEARNING_GRADEMODEL_OVERALL => get_string('grademodel_overall', 'mod_exelearning'),
            ]
        );
        $mform->setDefault('grademodel', EXELEARNING_GRADEMODEL_PERITEM);
        $mform->addHelpButton('grademodel', 'grademodel', 'mod_exelearning');

        $mform->addElement(
            'text',
            'grademax',
            get_string('grademax', 'mod_exelearning'),
            ['size' => '8']
        );
        $mform->setType('grademax', PARAM_FLOAT);
        $mform->setDefault('grademax', 100);
        $mform->addHelpButton('grademax', 'grademax', 'mod_exelearning');

        $mform->addElement(
            'text',
            'grademin',
            get_string('grademin', 'mod_exelearning'),
            ['size' => '8']
        );
        $mform->setType('grademin', PARAM_FLOAT);
        $mform->setDefault('grademin', 0);

        // Passing grade for the overall: feeds Moodle's completion-by-grade
        // ("require passing grade"), SCORM style (DEC-0010).
        $mform->addElement(
            'text',
            'gradepass',
            get_string('gradepass', 'mod_exelearning'),
            ['size' => '8']
        );
        $mform->setType('gradepass', PARAM_FLOAT);
        $mform->setDefault('gradepass', 0);
        $mform->addHelpButton('gradepass', 'gradepass', 'mod_exelearning');

        // How the grade is displayed in the gradebook (numeric, percentage, letter).
        // Moodle always stores the raw number; this selector only affects the
        // per-column display (gradedisplaytype on the grade_item).
        $displayoptions = [
            GRADE_DISPLAY_TYPE_DEFAULT       => get_string('gradedisplay_default', 'mod_exelearning'),
            GRADE_DISPLAY_TYPE_REAL          => get_string('gradedisplay_real', 'mod_exelearning'),
            GRADE_DISPLAY_TYPE_PERCENTAGE    => get_string('gradedisplay_percentage', 'mod_exelearning'),
            GRADE_DISPLAY_TYPE_LETTER        => get_string('gradedisplay_letter', 'mod_exelearning'),
            GRADE_DISPLAY_TYPE_REAL_PERCENTAGE => get_string('gradedisplay_real_percentage', 'mod_exelearning'),
        ];
        $mform->addElement(
            'select',
            'gradedisplaytype',
            get_string('gradedisplay', 'mod_exelearning'),
            $displayoptions
        );
        $mform->setDefault('gradedisplaytype', GRADE_DISPLAY_TYPE_DEFAULT);
        $mform->addHelpButton('gradedisplaytype', 'gradedisplay', 'mod_exelearning');

        // Grade category (DEC-0034): standard "Grade category" selector, reusing
        // core's strings. The chosen category is applied to every grade item of the
        // activity (overall + per-iDevice) by exelearning_apply_grade_category()
        // through grade_item::set_parent(), because grade_update() ignores categoryid.
        $mform->addElement(
            'select',
            'gradecat',
            get_string('gradecategoryonmodform', 'grades'),
            grade_get_categories_menu($COURSE->id)
        );
        $mform->addHelpButton('gradecat', 'gradecategoryonmodform', 'grades');

        // Attempts management: how multiple student attempts are limited, combined
        // into the gradebook grade, and reviewed. Kept in its own section so the
        // grade settings above are not overcrowded (DEC-0007, DEC-0031).
        $mform->addElement(
            'header',
            'attemptssection',
            get_string('attemptsmanagementheading', 'mod_exelearning')
        );

        // Attempt limit per student (DEC-0007 phase 2): 0 = unlimited.
        $mform->addElement(
            'text',
            'maxattempt',
            get_string('maxattempt', 'mod_exelearning'),
            ['size' => '6']
        );
        $mform->setType('maxattempt', PARAM_INT);
        $mform->setDefault('maxattempt', 0);
        $mform->addHelpButton('maxattempt', 'maxattempt', 'mod_exelearning');

        // Attempt aggregation (DEC-0007): how the student's attempt history is
        // combined for the gradebook grade.
        $methodoptions = [];
        foreach (\mod_exelearning\local\attempts::grademethod_options() as $val => $strkey) {
            $methodoptions[$val] = get_string($strkey, 'mod_exelearning');
        }
        $mform->addElement(
            'select',
            'grademethod',
            get_string('grademethod', 'mod_exelearning'),
            $methodoptions
        );
        $mform->setDefault('grademethod', \mod_exelearning\local\attempts::GRADE_HIGHEST);
        $mform->addHelpButton('grademethod', 'grademethod', 'mod_exelearning');

        // Student attempt review (DEC-0007 phase 2).
        $reviewoptions = [];
        foreach (\mod_exelearning\local\attempts::reviewmode_options() as $val => $strkey) {
            $reviewoptions[$val] = get_string($strkey, 'mod_exelearning');
        }
        $mform->addElement(
            'select',
            'reviewmode',
            get_string('reviewmode', 'mod_exelearning'),
            $reviewoptions
        );
        $mform->setDefault('reviewmode', \mod_exelearning\local\attempts::REVIEW_ALWAYS);
        $mform->addHelpButton('reviewmode', 'reviewmode', 'mod_exelearning');

        // Disable every grade and attempt setting when the activity is not graded
        // (DEC-0029); covers the fields in both the Grading and Attempts sections.
        $gradefields = [
            'grademodel', 'grademax', 'grademin', 'gradepass', 'gradedisplaytype',
            'gradecat', 'maxattempt', 'grademethod', 'reviewmode',
        ];
        foreach ($gradefields as $gradefield) {
            $mform->disabledIf($gradefield, 'gradeenabled', 'notchecked');
        }

        // Appearance: whether to show the teacher preview/grading toggle in the
        // activity view (mod_exeweb parity). Default on.
        $mform->addElement(
            'header',
            'appearancesection',
            get_string('appearance', 'mod_exelearning')
        );
        $mform->addElement(
            'advcheckbox',
            'teachermodevisible',
            get_string('teachermodevisible', 'mod_exelearning')
        );
        $mform->setDefault('teachermodevisible', 0);
        $mform->addHelpButton('teachermodevisible', 'teachermodevisible', 'mod_exelearning');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Prepares the form default values before display.
     *
     * @param array $defaultvalues Reference to the default values array.
     * @return void
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);

        $draftitemid = file_get_submitted_draft_itemid('package');
        if (!empty($this->current->id)) {
            $context = context_module::instance($this->current->coursemodule);
            file_prepare_draft_area(
                $draftitemid,
                $context->id,
                'mod_exelearning',
                'package',
                0,
                ['subdirs' => 0, 'maxfiles' => 1]
            );
        }
        $defaultvalues['package'] = $draftitemid;

        // The grademax/grademin values are stored as decimal(10,5). In the form
        // they are shown without trailing zeros (100 instead of 100.00000).
        foreach (['grademax', 'grademin', 'gradepass'] as $f) {
            if (isset($defaultvalues[$f])) {
                $v = (float) $defaultvalues[$f];
                $defaultvalues[$f] = ($v == (int) $v) ? (int) $v
                        : rtrim(rtrim(number_format($v, 5, '.', ''), '0'), '.');
            }
        }

        // Reflect the real grade category in the selector. The stored gradecat is
        // authoritative, but fall back to the overall grade item's actual category
        // when it is unset (activity created before the gradecat column, or moved
        // manually in the gradebook) so re-saving does not silently relocate it
        // (DEC-0034).
        if (empty($defaultvalues['gradecat']) && !empty($this->current->id)) {
            $overall = grade_item::fetch([
                'itemtype'     => 'mod',
                'itemmodule'   => 'exelearning',
                'iteminstance' => $this->current->id,
                'itemnumber'   => 0,
                'courseid'     => $this->current->course,
            ]);
            if ($overall) {
                $defaultvalues['gradecat'] = $overall->categoryid;
            }
        }
    }

    /**
     * Validate the grade range fields before the instance is saved.
     *
     * Guards the assumptions track.php relies on: grademin must not exceed grademax
     * (otherwise the score clamp inverts), and a non-zero gradepass must fall inside
     * the grade range (otherwise "require passing grade" completion is unreachable).
     *
     * @param array $data  The submitted form data.
     * @param array $files The submitted files.
     * @return array Map of field name => error string (empty when valid).
     */
    public function validation($data, $files) {
        global $USER, $DB;
        $errors = parent::validation($data, $files);

        // Core's moodleform_mod::validation() rejects every (B7, DEC-0044)
        // completiongradeitemnumber with a badcompletiongradeitemnumber error
        // ($errors['completionpassgrade']) because mod_exelearning maps 101
        // itemnumbers (gradeitems::MAX_ITEMNUMBER) but stores each grade in its own
        // table instead of exposing per-itemnumber grade_ideviceN form fields, so
        // core's "this item has no grade field" check always fails — making the
        // DEC-0038 completion-by-grade feature impossible to save from the form.
        // Stopgap: clear that specific error when "require passing grade" is OFF and
        // the chosen item is a real gradebook column (a per-iDevice item in PERITEM,
        // or the overall in OVERALL) — it does carry a grade, just not via a core
        // form field. "Require passing grade" needs a core_grades fieldname_mapping
        // to validate the pass threshold and is left to that proper fix (deferred,
        // see DEC-0044). Only fires when completionpassgrade is unchecked, so it
        // never masks the legitimate "grade to pass not set" validation.
        $selected = $data['completiongradeitemnumber'] ?? null;
        if (
            $selected !== null && $selected !== ''
            && empty($data['completionpassgrade'])
            && isset($errors['completionpassgrade'])
        ) {
            $itemnumber = (int) $selected;
            $grademodel = (int) ($data['grademodel'] ?? EXELEARNING_GRADEMODEL_PERITEM);
            // A real gradebook column exists for the overall (0) only in OVERALL
            // mode, and for a per-iDevice item only in PERITEM mode — OVERALL
            // deletes the per-iDevice Moodle columns (DEC-0038), so completion must
            // not target one there even though its exelearning_grade_item row is kept
            // for the report.
            $registered = ($itemnumber === 0)
                ? ($grademodel === EXELEARNING_GRADEMODEL_OVERALL)
                : ($grademodel === EXELEARNING_GRADEMODEL_PERITEM
                    && $DB->record_exists('exelearning_grade_item', [
                        'exelearningid' => (int) ($this->current->id ?? 0),
                        'itemnumber'    => $itemnumber,
                        'deleted'       => 0,
                    ]));
            if ($registered) {
                unset($errors['completionpassgrade']);
            }
        }

        $grademax = (float) ($data['grademax'] ?? 100);
        $grademin = (float) ($data['grademin'] ?? 0);
        $gradepass = (float) ($data['gradepass'] ?? 0);

        if ($grademin > $grademax) {
            $errors['grademin'] = get_string('err_grademinmax', 'mod_exelearning');
        }
        if ($gradepass != 0 && ($gradepass < $grademin || $gradepass > $grademax)) {
            $errors['gradepass'] = get_string('err_gradepassrange', 'mod_exelearning');
        }

        // Accept both .elpx and .zip, but the upload must be a real eXeLearning v4
        // package, i.e. a ZIP containing content.xml (DEC-0027). This rejects an
        // arbitrary .zip at submit time instead of creating a broken activity.
        $draftid = (int) ($data['package'] ?? 0);
        if ($draftid > 0) {
            $fs = get_file_storage();
            $usercontext = context_user::instance($USER->id);
            $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftid, 'id DESC', false);
            $package = reset($draftfiles);
            if ($package && !exelearning_package_has_content_xml($package)) {
                $errors['package'] = get_string('err_nocontentxml', 'mod_exelearning');
            }
        }

        return $errors;
    }
}
