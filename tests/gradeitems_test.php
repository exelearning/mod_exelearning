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
use core_grades\local\gradeitem\itemnumber_mapping;
use mod_exelearning\grades\gradeitems;

/**
 * Tests for the multi-itemnumber grade mapping (Moodle 5.x completion-by-grade).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\grades\gradeitems
 */
final class gradeitems_test extends advanced_testcase {
    /**
     * The mapping exposes the overall slot plus one slot per supported iDevice.
     */
    public function test_mapping_covers_overall_and_idevice_slots(): void {
        $mapping = gradeitems::get_itemname_mapping_for_component();

        // Overall (0) plus one slot per supported iDevice (1..MAX_ITEMNUMBER).
        $this->assertCount(gradeitems::MAX_ITEMNUMBER + 1, $mapping);
        $this->assertSame('overall', $mapping[0]);
        $this->assertSame('idevice1', $mapping[1]);
        $this->assertSame('idevice100', $mapping[gradeitems::MAX_ITEMNUMBER]);
        // No slot is allocated beyond the documented cap.
        $this->assertArrayNotHasKey(gradeitems::MAX_ITEMNUMBER + 1, $mapping);
    }

    /**
     * The class honours the documented cap and the core mapping contract.
     */
    public function test_max_itemnumber_and_interface(): void {
        $this->assertSame(100, gradeitems::MAX_ITEMNUMBER);
        $this->assertInstanceOf(itemnumber_mapping::class, new gradeitems());
    }

    /**
     * Every mapping value must resolve to a real lang string: the form trait
     * builds grade_<value>_name directly from the mapping, so a missing string
     * breaks the completion-by-grade dropdown (the MAX=100 lang trap).
     */
    public function test_every_mapping_value_has_a_lang_string(): void {
        $manager = get_string_manager();
        foreach (gradeitems::get_itemname_mapping_for_component() as $value) {
            $key = 'grade_' . $value . '_name';
            $this->assertTrue(
                $manager->string_exists($key, 'mod_exelearning'),
                "Missing lang string {$key} for mapping value '{$value}'"
            );
        }
    }
}
