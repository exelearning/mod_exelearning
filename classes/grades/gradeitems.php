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
 * Moodle 5.x exige que los plugins con itemnumber > 0 declaren un mapeo
 * itemnumber → identificador de string. Como el número real de iDevices
 * calificables depende del paquete subido (es dinámico), pre-asignamos un
 * rango fijo de 20 slots por instancia, que cubre los paquetes eXeLearning
 * realistas. Aumentarlo es trivial; el coste es UX (dropdown más largo en
 * el formulario de completion-via-grade).
 *
 *   itemnumber 0       → 'overall'   (nota agregada)
 *   itemnumber 1..N    → 'ideviceN'  (un slot por iDevice calificable)
 *
 * Strings asociadas en lang/en/exelearning.php:
 *   $string['grade_overall_name']  = 'Overall';
 *   $string['grade_idevice1_name'] = 'iDevice 1'; …
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE Educación
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_exelearning\grades;

use core_grades\local\gradeitem\itemnumber_mapping;

class gradeitems implements itemnumber_mapping {

    public const MAX_ITEMNUMBER = 100;

    public static function get_itemname_mapping_for_component(): array {
        // form_trait usa los valores de este array DIRECTAMENTE para construir
        // strings (grade_<itemname>_name), así que `0 => 'overall'` debe ser
        // no-vacío aunque component_gradeitems::get_itemname_from_itemnumber
        // siempre devuelva '' para itemnumber=0.
        $mapping = [0 => 'overall'];
        for ($n = 1; $n <= self::MAX_ITEMNUMBER; $n++) {
            $mapping[$n] = 'idevice' . $n;
        }
        return $mapping;
    }
}
