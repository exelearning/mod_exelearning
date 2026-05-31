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

        // Map odePageId -> pageName. Real eXeLearning v4 packages emit the page
        // name immediately after its page id inside <odeNavStructure>; pages
        // without an explicit name simply stay unmapped (best-effort labelling).
        $pagenames = [];
        if (
            preg_match_all(
                '~<odePageId>([^<]+)</odePageId>\s*<pageName>([^<]*)</pageName>~',
                $xml,
                $pn,
                PREG_SET_ORDER
            )
        ) {
            foreach ($pn as $p) {
                $pagenames[$p[1]] = trim($p[2]);
            }
        }

        // Walk the manifest in document order. eXeLearning v4 serialises a flat
        // <odeNavStructure> stream (page ids, page names and iDevice records
        // interleaved), not the nested <odePage>/<odeIdevice> hierarchy an older
        // format used (which never appears in real v4 packages, verified across
        // all shipped fixtures). So we scan the whole document and attribute each
        // gradable iDevice to the most recent page id seen before it. Detection
        // is identical to the previous flat fallback (an <odeIdeviceId> directly
        // followed by its <odeIdeviceTypeName>); the page id/name are recovered
        // on top, so two same-type iDevices on different pages stay distinct.
        $order = 0;
        $currentpage = '';
        $token = '~<odePageId>([^<]+)</odePageId>'
            . '|<odeIdeviceId>([^<]+)</odeIdeviceId>\s*<odeIdeviceTypeName>([^<]+)</odeIdeviceTypeName>~';
        if (preg_match_all($token, $xml, $tokens, PREG_SET_ORDER)) {
            foreach ($tokens as $t) {
                $pageid   = $t[1] ?? '';
                $deviceid = $t[2] ?? '';
                $devtype  = $t[3] ?? '';
                if ($pageid !== '' && $deviceid === '') {
                    $currentpage = $pageid;
                    continue;
                }
                if ($deviceid !== '' && in_array($devtype, self::GRADABLE_IDEVICE_TYPES, true)) {
                    $items[] = (object) [
                        'objectid'    => $deviceid,
                        'idevicetype' => $devtype,
                        'pageid'      => $currentpage,
                        'pagename'    => $pagenames[$currentpage] ?? '',
                        'orderhint'   => $order++,
                    ];
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
