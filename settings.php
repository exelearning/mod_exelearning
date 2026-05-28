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
 * actualización / configuración del editor (descargar release desde GitHub,
 * subir un ZIP, gestionar plantillas y estilos) se hace desde la página
 * `manage_embedded_editor.php`, con capability
 * `mod/exelearning:manageembeddededitor`.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Toggle del editor embebido.
    $settings->add(new admin_setting_configcheckbox(
            'exelearning/embeddededitor',
            get_string('embeddededitor', 'mod_exelearning'),
            get_string('embeddededitor_desc', 'mod_exelearning'),
            1));

    // Enlace a la página de gestión (instalar / actualizar / plantillas).
    $manageurl = new moodle_url('/mod/exelearning/manage_embedded_editor.php');
    $settings->add(new admin_setting_description(
            'exelearning/manage_link',
            get_string('manage_editor_heading', 'mod_exelearning'),
            html_writer::link($manageurl,
                    get_string('manage_editor_link', 'mod_exelearning'),
                    ['class' => 'btn btn-secondary'])));
}
