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
 * mod_exelearning database upgrades.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE Educación
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_exelearning_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // Stage 1 (2026052800): add multi-grade-items mapping table + grademin/grademax on instance.
    if ($oldversion < 2026052800) {

        $instance = new xmldb_table('exelearning');
        $grademax = new xmldb_field('grademax', XMLDB_TYPE_NUMBER, '10,5',
                null, XMLDB_NOTNULL, null, '100', 'revision');
        if (!$dbman->field_exists($instance, $grademax)) {
            $dbman->add_field($instance, $grademax);
        }
        $grademin = new xmldb_field('grademin', XMLDB_TYPE_NUMBER, '10,5',
                null, XMLDB_NOTNULL, null, '0', 'grademax');
        if (!$dbman->field_exists($instance, $grademin)) {
            $dbman->add_field($instance, $grademin);
        }

        $table = new xmldb_table('exelearning_grade_item');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',            XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('exelearningid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('itemnumber',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('objectid',      XMLDB_TYPE_CHAR,    '191', null, XMLDB_NOTNULL, null, null);
            $table->add_field('pageid',        XMLDB_TYPE_CHAR,    '191', null, null, null, null);
            $table->add_field('idevicetype',   XMLDB_TYPE_CHAR,    '64',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('name',          XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('grademax',      XMLDB_TYPE_NUMBER,  '10,5', null, XMLDB_NOTNULL, null, '100');
            $table->add_field('grademin',      XMLDB_TYPE_NUMBER,  '10,5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('deleted',       XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('exelearningid_fk', XMLDB_KEY_FOREIGN, ['exelearningid'], 'exelearning', ['id']);
            $table->add_index('exelearningid_itemnumber', XMLDB_INDEX_UNIQUE, ['exelearningid', 'itemnumber']);
            $table->add_index('exelearningid_objectid',   XMLDB_INDEX_UNIQUE, ['exelearningid', 'objectid']);

            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026052800, 'exelearning');
    }

    // Stage 2 (2026052801): añadir gradedisplaytype en exelearning.
    if ($oldversion < 2026052801) {
        $instance = new xmldb_table('exelearning');
        $field = new xmldb_field('gradedisplaytype', XMLDB_TYPE_INTEGER, '4',
                null, XMLDB_NOTNULL, null, '0', 'grademin');
        if (!$dbman->field_exists($instance, $field)) {
            $dbman->add_field($instance, $field);
        }
        upgrade_mod_savepoint(true, 2026052801, 'exelearning');
    }

    return true;
}
