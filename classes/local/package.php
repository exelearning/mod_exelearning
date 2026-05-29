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

namespace mod_exelearning\local;

/**
 * Parser ligero del manifest propietario `content.xml` de eXeLearning v4.
 *
 * Sólo lee los campos que `mod_exelearning` necesita para registrar
 * grade items (multi-itemnumber pattern, ver research/analisis/notas/AN-002.md
 * y AN-007.md). No interpreta secuencia ni renderiza páginas — eso lo hace
 * el JS embebido del propio paquete dentro del iframe.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package {
    /**
     * Tipos de iDevice que SE registran como grade item.
     *
     * Whitelist conservadora basada en la inspección del Manual de eXeLearning
     * v4 (FTE-008). Cualquier tipo no listado aquí se ignora silenciosamente.
     *
     * @var string[]
     */
    public const GRADABLE_IDEVICE_TYPES = [
        'trueorfalse',
        'guess',
        'quick-questions',
        'quick-questions-multiple-choice',
        'quick-questions-video',
        'dragdrop',
        'complete',
        'classify',
        'relate',
        'sort',
        'identify',
        'discover',
        'crossword',
        'word-search',
        'puzzle',
        'trivial',
        'az-quiz-game',
        'mathproblems',
        'mathematicaloperations',
        'scrambled-list',
    ];

    /** @var \stored_file ELPX zip almacenado en filearea 'package'. */
    private \stored_file $file;

    /**
     * Builds the package parser from a stored ELPX file.
     *
     * @param \stored_file $file The ELPX zip stored in the 'package' filearea.
     */
    public function __construct(\stored_file $file) {
        $this->file = $file;
    }

    /**
     * Devuelve la lista ordenada de iDevices calificables detectados.
     *
     * Cada entrada es un stdClass con:
     *   - objectid    string IRI/ID estable del iDevice.
     *   - idevicetype string slug (trueorfalse, guess, …).
     *   - pageid      string ID estable de la página propietaria.
     *   - pagename    string nombre de la página (best-effort, puede vacío).
     *   - orderhint   int    orden de aparición en el documento (0-based).
     *
     * @return \stdClass[]
     */
    public function detect_gradable_idevices(): array {
        $xml = $this->read_content_xml();
        if ($xml === null) {
            return [];
        }

        // Parser conservador con expresiones regulares: las etiquetas usadas en
        // content.xml no anidan en namespaces y son consistentes en v4.
        // (Se evita XMLReader para no exigir libxml + ext-zip en backports.)
        $items = [];

        // Mapa odePageId → pageName (best-effort: en el árbol odeNavStructures).
        $pagenames = [];
        if (
            preg_match_all(
                '~<odeNavStructure>(.*?)</odeNavStructure>~s',
                $xml,
                $structs
            )
        ) {
            foreach ($structs[1] as $seg) {
                if (
                    preg_match('~<odePageId>([^<]+)</odePageId>~', $seg, $mp)
                        && preg_match('~<pageName>([^<]*)</pageName>~', $seg, $mn)
                ) {
                    $pagenames[$mp[1]] = trim($mn[1]);
                }
            }
        }

        $order = 0;
        if (preg_match_all('~<odePage>(.*?)</odePage>~s', $xml, $pages)) {
            foreach ($pages[1] as $pagexml) {
                $pageid = '';
                if (preg_match('~<odePageId>([^<]+)</odePageId>~', $pagexml, $mp)) {
                    $pageid = $mp[1];
                }
                if (
                    preg_match_all(
                        '~<odeIdevice>(.*?)</odeIdevice>~s',
                        $pagexml,
                        $devs
                    )
                ) {
                    foreach ($devs[1] as $devxml) {
                        $id = $type = null;
                        if (
                            preg_match(
                                '~<odeIdeviceId>([^<]+)</odeIdeviceId>~',
                                $devxml,
                                $mid
                            )
                        ) {
                            $id = $mid[1];
                        }
                        if (
                            preg_match(
                                '~<odeIdeviceTypeName>([^<]+)</odeIdeviceTypeName>~',
                                $devxml,
                                $mt
                            )
                        ) {
                            $type = $mt[1];
                        }
                        if (
                            $id !== null && $type !== null
                                && in_array($type, self::GRADABLE_IDEVICE_TYPES, true)
                        ) {
                            $items[] = (object) [
                                'objectid'    => $id,
                                'idevicetype' => $type,
                                'pageid'      => $pageid,
                                'pagename'    => $pagenames[$pageid] ?? '',
                                'orderhint'   => $order++,
                            ];
                        }
                    }
                }
            }
        }

        // Fallback: algunos exports (paquetes generados sin la jerarquía
        // odePage anidada) listan los iDevices en plano. Captura los que
        // queden fuera del primer pase.
        if ($items === []) {
            if (
                preg_match_all(
                    '~<odeIdeviceId>([^<]+)</odeIdeviceId>\s*<odeIdeviceTypeName>([^<]+)</odeIdeviceTypeName>~',
                    $xml,
                    $flat,
                    PREG_SET_ORDER
                )
            ) {
                foreach ($flat as $m) {
                    if (in_array($m[2], self::GRADABLE_IDEVICE_TYPES, true)) {
                        $items[] = (object) [
                            'objectid'    => $m[1],
                            'idevicetype' => $m[2],
                            'pageid'      => '',
                            'pagename'    => '',
                            'orderhint'   => $order++,
                        ];
                    }
                }
            }
        }

        return $items;
    }

    /**
     * Lee `content.xml` del ZIP almacenado.
     *
     * @return string|null Contenido del fichero, o null si no se encuentra.
     */
    private function read_content_xml(): ?string {
        $packer = get_file_packer('application/zip');
        $tmpdir = make_request_directory();
        $extracted = $this->file->extract_to_pathname(
            $packer,
            $tmpdir,
            null,
            true
        );
        if (!is_array($extracted)) {
            return null;
        }
        $path = $tmpdir . '/content.xml';
        if (!is_file($path)) {
            return null;
        }
        $xml = file_get_contents($path);
        return $xml === false ? null : $xml;
    }
}
