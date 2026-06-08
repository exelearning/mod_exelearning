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
     * Known gradable iDevice types — INFORMATIONAL ONLY (no longer the detection gate).
     *
     * Detection is now driven by the author's per-iDevice `isScorm` flag
     * (see {@see self::idevice_reports_score()} and DEC-0022 / issue #13 #2,#5),
     * because eXeLearning v4 gates all SCORM score reporting on that flag, not on
     * the iDevice type. This catalogue is kept as documentation of which types can
     * be configured to report a grade — it includes both the originally supported
     * set and the 10 types requested in issue #13 #5 (form, beforeafter,
     * hidden-image, periodic-table, select-media-files, flipcards=Memory cards,
     * map, interactive-video, challenge, padlock=Lock).
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
        // Added in issue #13 #5 (detected when isScorm > 0, like any other type).
        'form',
        'beforeafter',
        'hidden-image',
        'periodic-table',
        'select-media-files',
        'flipcards',
        'map',
        'interactive-video',
        'challenge',
        'padlock',
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
     *   - contenthash string sha1 of the iDevice content block (detects an
     *                        in-place options edit; same objectid, new scoring).
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
        // PREG_OFFSET_CAPTURE records each token's byte position. A gradable
        // iDevice's content block is the slice from its own <odeIdeviceId>
        // marker to the next token (any page id or iDevice id) — i.e. all of its
        // properties/answers/scoring. Hashing that slice lets a re-sync tell an
        // unchanged iDevice from one whose options were edited in place.
        $order = 0;
        $currentpage = '';
        $token = '~<odePageId>([^<]+)</odePageId>'
            . '|<odeIdeviceId>([^<]+)</odeIdeviceId>\s*<odeIdeviceTypeName>([^<]+)</odeIdeviceTypeName>~';
        if (preg_match_all($token, $xml, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $total = count($tokens);
            $xmllen = strlen($xml);
            for ($i = 0; $i < $total; $i++) {
                $t = $tokens[$i];
                $pageid   = $t[1][0] ?? '';
                $deviceid = $t[2][0] ?? '';
                $devtype  = $t[3][0] ?? '';
                if ($pageid !== '' && $deviceid === '') {
                    $currentpage = $pageid;
                    continue;
                }
                if ($deviceid === '') {
                    continue;
                }
                // Slice this iDevice's content block (its id marker → the next
                // token) once: it carries both the scoring flag we gate on and the
                // pedagogical content we hash.
                $start = (int) $t[0][1];
                $end = ($i + 1 < $total) ? (int) $tokens[$i + 1][0][1] : $xmllen;
                $block = substr($xml, $start, $end - $start);
                // Detection gate (DEC-0022, issue #13 #2/#5): register a grade item
                // only for iDevices the author explicitly marked for assessment
                // (isScorm > 0), regardless of type. This drops gradable-type
                // iDevices left unscored (#2) and picks up any newly supported type
                // once it is configured to report a score (#5).
                if (!$this->idevice_reports_score($block)) {
                    continue;
                }
                $items[] = (object) [
                    'objectid'    => $deviceid,
                    'idevicetype' => $devtype,
                    'pageid'      => $currentpage,
                    'pagename'    => $pagenames[$currentpage] ?? '',
                    'orderhint'   => $order++,
                    'contenthash' => $this->hash_idevice_block($block),
                ];
            }
        }

        return $items;
    }

    /**
     * Decides whether an iDevice was marked for assessment by its author.
     *
     * eXeLearning v4 stores a per-iDevice `isScorm` flag: `0` = does not report a
     * grade, `1` = auto-save score, `2` = "Send score" button. The whole eXeLearning
     * gamification/SCORM layer gates score reporting on `isScorm > 0` (see the editor
     * sources `public/app/common/common.js` — the `cmi.suspend_data` line builder this
     * plugin parses in {@see \mod_exelearning\local\track} — and each iDevice's
     * `export/*.js`), so the same flag is the single source of truth for whether an
     * activity should own a Moodle grade item. Reading it makes detection
     * type-agnostic (issue #13 #2 and #5; DEC-0022): any iDevice configured to be
     * scored is detected (incl. Form, Map, Interactive Video, …) and gradable-type
     * iDevices left unscored are skipped.
     *
     * The flag is stored in one of THREE places depending on the iDevice family,
     * so detection reads all of them and treats the iDevice as scored when ANY
     * source reports isScorm > 0 (taking the maximum avoids a plain `0` shadowing
     * an encrypted `1`):
     *   1. `<jsonProperties>` — json-type iDevices (trueorfalse, form, map,
     *      scrambled-list, …). Plain JSON.
     *   2. `<htmlView>` plain — some html-type iDevices store the flag in their
     *      rendered HTML (interactive-video, dragdrop, periodic-table, beforeafter,
     *      flipcards, relate, trivial, mathematicaloperations). The value may be
     *      nested (e.g. interactive-video stores it under `scorm`), so the match is
     *      not anchored to the top level (DEC-0022 amendment).
     *   3. `<htmlView>` encrypted `*-DataGame` div — the "exe-game" family
     *      (guess, discover, identify, classify, quick-questions*, az-quiz-game,
     *      crossword, word-search, padlock, challenge, select-media-files,
     *      complete, sort, mathproblems, …) keeps its whole config — including
     *      isScorm — obfuscated inside a hidden `*-DataGame` div, so the plain
     *      regex never saw it (this was the "only 12 of 30 detected" bug, issue
     *      #13 comment; DEC-0037 amendment). {@see self::extract_isscorm_datagame()}.
     *
     * @param string $block Raw content.xml slice for one iDevice (id → next token).
     * @return bool True when the iDevice declares isScorm 1 or 2 in any source.
     */
    private function idevice_reports_score(string $block): bool {
        $max = null;
        foreach (
            [
                $this->extract_isscorm($block, 'jsonProperties'),
                $this->extract_isscorm($block, 'htmlView'),
                $this->extract_isscorm_datagame($block),
            ] as $value
        ) {
            if ($value !== null && ($max === null || $value > $max)) {
                $max = $value;
            }
        }
        return $max !== null && $max > 0;
    }

    /**
     * Reads the `isScorm` value from one element of an iDevice content block.
     *
     * @param string $block Raw content.xml slice for one iDevice.
     * @param string $tag Element to scan ('jsonProperties' or 'htmlView').
     * @return int|null The isScorm value (0..9), or null when the element/flag is absent.
     */
    private function extract_isscorm(string $block, string $tag): ?int {
        if (!preg_match('~<' . $tag . '>(.*?)</' . $tag . '>~s', $block, $m)) {
            return null;
        }
        // The element payload is HTML-escaped JSON/HTML; decode before scanning.
        $decoded = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('~"isScorm"\s*:\s*"?([0-9])~', $decoded, $mm)) {
            return (int) $mm[1];
        }
        return null;
    }

    /**
     * Reads `isScorm` from the encrypted `*-DataGame` div(s) inside the htmlView.
     *
     * The "exe-game" iDevice family (guess, discover, identify, classify,
     * quick-questions*, az-quiz-game, crossword, word-search, padlock, challenge,
     * select-media-files, complete, sort, mathproblems, …) does not expose its
     * scoring config in plain text: the whole game JSON, including the `isScorm`
     * flag, is obfuscated inside a hidden `<div class="…-DataGame …">` within the
     * rendered htmlView. Each iDevice's `export/*.js` reverses it at runtime via
     * eXeLearning's `decrypt()` (see `libs/common.js`): `unescape()` followed by an
     * XOR with the fixed key 146 (0x92). We replicate that to read the flag so
     * these iDevices register a Moodle grade item like the plain-text family
     * (issue #13 "only 12 of 30 detected"; DEC-0037, amends DEC-0022).
     *
     * The div content is `escape()` output (no `<`), so the non-greedy `</div>`
     * terminator is safe. A block may hold more than one DataGame div, so the
     * maximum flag wins.
     *
     * @param string $block Raw content.xml slice for one iDevice.
     * @return int|null The decrypted isScorm value, or null when absent/undecodable.
     */
    private function extract_isscorm_datagame(string $block): ?int {
        if (!preg_match('~<htmlView>(.*?)</htmlView>~s', $block, $m)) {
            return null;
        }
        $html = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match_all('~class="[^"]*DataGame[^"]*"[^>]*>(.*?)</div>~s', $html, $divs)) {
            return null;
        }
        $max = null;
        foreach ($divs[1] as $encoded) {
            $json = $this->decrypt_datagame(trim($encoded));
            if (preg_match('~"isScorm"\s*:\s*"?([0-9])~', $json, $mm)) {
                $value = (int) $mm[1];
                if ($max === null || $value > $max) {
                    $max = $value;
                }
            }
        }
        return $max;
    }

    /**
     * Decrypts an eXeLearning `DataGame` payload back to its JSON source.
     *
     * Mirrors eXeLearning's `decrypt()` (`libs/common.js`): apply a JavaScript
     * `unescape()` to recover the code points, then XOR each one with the fixed
     * key 146 (0x92). `unescape()` understands both `%XX` (one byte) and `%uXXXX`
     * (one code unit), so both are handled; any literal character left by
     * `escape()` is plain ASCII and maps to its byte value.
     *
     * @param string $encoded The `escape()`-encoded, XOR-obfuscated div content.
     * @return string The decrypted UTF-8 string (expected to be the game JSON).
     */
    private function decrypt_datagame(string $encoded): string {
        $out = '';
        $len = strlen($encoded);
        $i = 0;
        while ($i < $len) {
            $codepoint = null;
            if ($encoded[$i] === '%' && $i + 1 < $len) {
                if (
                    $encoded[$i + 1] === 'u' && $i + 6 <= $len
                    && ctype_xdigit(substr($encoded, $i + 2, 4))
                ) {
                    $codepoint = hexdec(substr($encoded, $i + 2, 4));
                    $i += 6;
                } else if ($i + 3 <= $len && ctype_xdigit(substr($encoded, $i + 1, 2))) {
                    $codepoint = hexdec(substr($encoded, $i + 1, 2));
                    $i += 3;
                }
            }
            if ($codepoint === null) {
                // The escape() output leaves only safe ASCII literals, so a raw byte is its code point.
                $codepoint = ord($encoded[$i]);
                $i++;
            }
            $out .= \core_text::code2utf8(146 ^ (int) $codepoint);
        }
        return $out;
    }

    /**
     * Hashes an iDevice content block, ignoring volatile metadata.
     *
     * eXeLearning re-serialises content.xml on every save; per-iDevice
     * modification timestamps would otherwise flip the hash even when the
     * scoring/options did not change. We strip the obvious volatile tags
     * (anything whose name contains "date", "modified" or "timestamp") before
     * hashing so the hash tracks the pedagogical content, not the export time.
     * A residual false positive only produces an extra informational warning
     * (the save is never blocked), matching mod_scorm's conservative notice.
     *
     * @param string $block Raw content.xml slice for one iDevice.
     * @return string 40-char sha1.
     */
    private function hash_idevice_block(string $block): string {
        // Drop volatile metadata tags first.
        $normalised = preg_replace(
            '~<([a-zA-Z0-9_]*(?:[Dd]ate|[Mm]odified|[Tt]imestamp)[a-zA-Z0-9_]*)>[^<]*</\1>~',
            '',
            $block
        ) ?? $block;
        // Then collapse whitespace so the residual gap a stripped tag leaves, and
        // any reflow a re-export introduces, does not flip the hash.
        $normalised = trim(preg_replace('~\s+~', ' ', $normalised) ?? $normalised);
        return sha1($normalised);
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
