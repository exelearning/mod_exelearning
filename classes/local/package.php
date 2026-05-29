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
 * Lightweight parser for the proprietary `content.xml` manifest of eXeLearning v4.
 *
 * Only reads the fields that `mod_exelearning` needs to register grade items
 * (multi-itemnumber pattern, see research/analisis/notas/AN-002.md and AN-007.md).
 * It does not interpret sequencing or render pages — that is handled by the
 * package's own embedded JS inside the iframe.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package {
    /**
     * iDevice types that ARE registered as a grade item.
     *
     * Conservative whitelist based on inspection of the eXeLearning v4 manual
     * (FTE-008). Any type not listed here is silently ignored.
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

    /** @var \stored_file ELPX zip stored in the 'package' filearea. */
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
     * Returns the ordered list of detected gradable iDevices.
     *
     * Each entry is a stdClass with:
     *   - objectid    string Stable IRI/ID of the iDevice.
     *   - idevicetype string Slug (trueorfalse, guess, …).
     *   - pageid      string Stable ID of the owning page.
     *   - pagename    string Page name (best-effort, may be empty).
     *   - orderhint   int    Order of appearance in the document (0-based).
     *
     * @return \stdClass[]
     */
    public function detect_gradable_idevices(): array {
        $xml = $this->read_content_xml();
        if ($xml === null) {
            return [];
        }

        // Conservative regex-based parser: the tags used in content.xml do not
        // nest inside namespaces and are consistent across v4.
        // (XMLReader is avoided to avoid requiring libxml + ext-zip in backports.)
        $items = [];

        // Map odePageId → pageName (best-effort: from the odeNavStructures tree).
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

        // Fallback: some exports (packages generated without the nested odePage
        // hierarchy) list iDevices flat. Capture those missed by the first pass.
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
     * Reads `content.xml` from the stored ZIP.
     *
     * @return string|null File contents, or null if not found.
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
