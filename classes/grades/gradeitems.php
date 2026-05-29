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
 * Grade item mappings for mod_exelearning.
 *
 * Moodle 5.x requires plugins with itemnumber > 0 to declare a mapping of
 * itemnumber → string identifier. Because the actual number of gradable iDevices
 * depends on the uploaded package (it is dynamic), we pre-assign a fixed range
 * of slots per instance that covers realistic eXeLearning packages. Increasing
 * it is trivial; the cost is UX (a longer dropdown in the completion-via-grade
 * form).
 *
 *   itemnumber 0       → 'overall'   (aggregated grade)
 *   itemnumber 1..N    → 'ideviceN'  (one slot per gradable iDevice)
 *
 * Associated strings in lang/en/exelearning.php:
 *   $string['grade_overall_name']  = 'Overall';
 *   $string['grade_idevice1_name'] = 'iDevice 1'; …
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_exelearning\grades;

use core_grades\local\gradeitem\itemnumber_mapping;

/**
 * Maps grade item numbers to their internal item names for mod_exelearning.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gradeitems implements itemnumber_mapping {
    /** @var int Maximum supported grade item number. */
    public const MAX_ITEMNUMBER = 100;

    /**
     * Returns the mapping between item numbers and item names for this component.
     *
     * @return array The item number to item name mapping.
     */
    public static function get_itemname_mapping_for_component(): array {
        // The form_trait uses the values of this array DIRECTLY to build
        // strings (grade_<itemname>_name), so `0 => 'overall'` must be
        // non-empty even though component_gradeitems::get_itemname_from_itemnumber
        // always returns '' for itemnumber=0.
        $mapping = [0 => 'overall'];
        for ($n = 1; $n <= self::MAX_ITEMNUMBER; $n++) {
            $mapping[$n] = 'idevice' . $n;
        }
        return $mapping;
    }
}
