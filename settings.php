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
 * mod_exelearning admin settings.
 *
 * Configuración mínima v1. El bloque "styles manager" + plantillas heredadas
 * de mod_exeweb se aplaza a futura iteración (DEC-0005).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE Educación
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configcheckbox(
            'exelearning/embeddededitor',
            get_string('embeddededitor', 'mod_exelearning'),
            get_string('embeddededitor_desc', 'mod_exelearning'),
            1));

    $settings->add(new admin_setting_configselect(
            'exelearning/editormode',
            get_string('editormode', 'mod_exelearning'),
            get_string('editormode_desc', 'mod_exelearning'),
            'embedded',
            [
                'embedded' => get_string('editormode_embedded', 'mod_exelearning'),
                'online'   => get_string('editormode_online',   'mod_exelearning'),
            ]));

    $settings->add(new admin_setting_configtext(
            'exelearning/exeonlinebaseuri',
            get_string('exeonline_baseuri', 'mod_exelearning'),
            get_string('exeonline_baseuri_desc', 'mod_exelearning'),
            '',
            PARAM_URL));

    $settings->add(new admin_setting_configpasswordunmask(
            'exelearning/hmackey1',
            get_string('exeonline_hmackey1', 'mod_exelearning'),
            get_string('exeonline_hmackey1_desc', 'mod_exelearning'),
            ''));
}
