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
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements();

        // Filemanager para el paquete ELPX v4.
        $mform->addElement(
            'filemanager',
            'package',
            get_string('package', 'mod_exelearning'),
            null,
            [
                    'subdirs' => 0,
                    'maxbytes' => 0,
                    'maxfiles' => 1,
                    'accepted_types' => ['.elpx'],
            ]
        );
        $mform->addHelpButton('package', 'package', 'mod_exelearning');
        $mform->addRule('package', null, 'required', null, 'client');

        // Configuración de calificación.
        $mform->addElement(
            'header',
            'gradingsection',
            get_string('gradingheading', 'mod_exelearning')
        );

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

        // Nota para aprobar el overall: alimenta la finalización por nota de
        // Moodle ("exigir nota para aprobar"), estilo SCORM (DEC-0010).
        $mform->addElement(
            'text',
            'gradepass',
            get_string('gradepass', 'mod_exelearning'),
            ['size' => '8']
        );
        $mform->setType('gradepass', PARAM_FLOAT);
        $mform->setDefault('gradepass', 0);
        $mform->addHelpButton('gradepass', 'gradepass', 'mod_exelearning');

        // Agregación de intentos (DEC-0007): cómo se combina el histórico de
        // intentos del alumno para la nota del libro de calificaciones.
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

        // Modelo de columnas en el libro de calificaciones (DEC-0008).
        $mform->addElement(
            'select',
            'grademodel',
            get_string('grademodel', 'mod_exelearning'),
            [
                    EXELEARNING_GRADEMODEL_OVERALL => get_string('grademodel_overall', 'mod_exelearning'),
                    EXELEARNING_GRADEMODEL_PERITEM => get_string('grademodel_peritem', 'mod_exelearning'),
                    EXELEARNING_GRADEMODEL_BOTH    => get_string('grademodel_both', 'mod_exelearning'),
            ]
        );
        $mform->setDefault('grademodel', EXELEARNING_GRADEMODEL_BOTH);
        $mform->addHelpButton('grademodel', 'grademodel', 'mod_exelearning');

        // Límite de intentos por alumno (DEC-0007 fase 2): 0 = ilimitados.
        $mform->addElement(
            'text',
            'maxattempt',
            get_string('maxattempt', 'mod_exelearning'),
            ['size' => '6']
        );
        $mform->setType('maxattempt', PARAM_INT);
        $mform->setDefault('maxattempt', 0);
        $mform->addHelpButton('maxattempt', 'maxattempt', 'mod_exelearning');

        // Revisión de intentos por el alumno (DEC-0007 fase 2).
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

        // Cómo se muestra la nota en el gradebook (numérico, porcentaje, letra).
        // Moodle ALMACENA siempre numérico; este selector sólo afecta a la
        // visualización por columna (gradedisplaytype del grade_item).
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
        $mform->setDefault('teachermodevisible', 1);
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
    }
}
