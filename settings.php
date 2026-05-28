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
 * DEC-0009: sólo modo editor embebido. La integración con eXeLearning Online
 * queda descartada para evitar dependencias externas. La instalación /
 * actualización / reparación / desinstalación del editor (descargando una
 * release desde GitHub) y la gestión de estilos definidos se realizan
 * íntegramente desde esta misma página de ajustes, con la capability
 * `mod/exelearning:manageembeddededitor`.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Register the styles management external page so it can be linked from the
// settings page and reached directly. Must be added before the $fulltree
// guard so it is always registered in the admin tree.
$ADMIN->add('modsettings', new admin_externalpage(
    'mod_exelearning_styles',
    get_string('stylesmanager', 'mod_exelearning'),
    new moodle_url('/mod/exelearning/admin/styles.php'),
    'mod/exelearning:manageembeddededitor'
));

if ($ADMIN->fulltree) {

    // -------------------------------------------------------------------------
    // Embedded editor management (install / update / repair / uninstall).
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'mod_exelearning/embeddededitorheading',
        get_string('embeddededitorsettings', 'mod_exelearning'),
        get_string('editormanagementhelp', 'mod_exelearning')
    ));

    // Inline editor management card (AJAX install/update/repair/uninstall).
    $settings->add(new \mod_exelearning\admin\admin_setting_embeddededitor(
        get_string('embeddededitorstatus', 'mod_exelearning'),
        get_string('editormanagementhelp', 'mod_exelearning')
    ));

    // -------------------------------------------------------------------------
    // Defined styles management (upload / enable / disable / lockdown).
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'mod_exelearning/stylesheading',
        get_string('stylesmanager', 'mod_exelearning'),
        get_string('stylesmanager_intro', 'mod_exelearning')
    ));

    // Upload a new style ZIP.
    $settings->add(new \mod_exelearning\admin\admin_setting_stylesupload());

    // List of uploaded styles with enable/disable/delete actions.
    $settings->add(new \mod_exelearning\admin\admin_setting_stylesuploaded());

    // List of built-in themes with enable/disable toggles.
    $settings->add(new \mod_exelearning\admin\admin_setting_stylesbuiltins());

    // Block importing/installing styles from project content.
    $settings->add(new admin_setting_configcheckbox(
        'exelearning/stylesblockimport',
        get_string('stylesblockimport', 'mod_exelearning'),
        get_string('stylesblockimport_desc', 'mod_exelearning'),
        0
    ));
}
