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
 * Web service definitions for mod_exelearning.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_exelearning_manage_embedded_editor_action' => [
        'classname'   => 'mod_exelearning\external\manage_embedded_editor',
        'methodname'  => 'execute_action',
        'description' => 'Install, update, repair, or uninstall the embedded editor.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'moodle/site:config, mod/exelearning:manageembeddededitor',
    ],
    'mod_exelearning_manage_embedded_editor_status' => [
        'classname'   => 'mod_exelearning\external\manage_embedded_editor',
        'methodname'  => 'get_status',
        'description' => 'Get the current status of the embedded editor installation.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'moodle/site:config, mod/exelearning:manageembeddededitor',
    ],
];
