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
 * SCORM tracking helpers shared between the `track.php` endpoint and its tests.
 *
 * Holds the per-iDevice routing logic so it can be unit-tested without invoking
 * the AJAX script. Two concerns live here:
 *
 *  - {@see self::parse_suspend_data()} decodes eXeLearning v4's `cmi.suspend_data`
 *    string. It is the single PHP source of truth for that format and mirrors the
 *    JavaScript parser in the `view.php` SCORM shim.
 *  - {@see self::apply_item_scores()} routes per-iDevice scores to the gradebook by
 *    the stable `objectid` captured client-side (DEC-0017 / RIE-007), instead of by
 *    the page-local index N the producer emits — which collides across pages.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class track {
    /**
     * Decodes an eXeLearning v4 `cmi.suspend_data` string into per-index results.
     *
     * The producer (`public/app/common/common.js`) serialises one entry per scored
     * iDevice as `{N}. "{title}"; {scoreLabel}: {S}%; {weightLabel}: {W}%`, joined
     * by ".\t". N is the page-local DOM index of the iDevice (NOT our itemnumber);
     * see DEC-0017. The score/weight labels are localised, hence the `[^:]+` parts.
     *
     * @param string $suspend Raw cmi.suspend_data value.
     * @return array Map of page-local N (int) to ['title' => string, 'scorepct' => float,
     *         'weighted' => float]. Empty when nothing parses.
     */
    public static function parse_suspend_data(string $suspend): array {
        $peritem = [];
        if ($suspend === '') {
            return $peritem;
        }
        foreach (preg_split('~\.\t~', $suspend) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // The score/weight numbers accept a comma as the decimal separator so a
            // package localised to es_ES/fr_FR/de_DE ("60,5%") parses too; the
            // captured group is normalised to a dot before casting (mirrors the JS
            // parser in the view.php shim).
            if (
                preg_match(
                    '~^(\d+)\.\s"([^"]*)";\s[^:]+:\s([\d.,]+)%;\s[^:]+:\s([\d.,]+)%\.?$~',
                    $line,
                    $m
                )
            ) {
                $peritem[(int) $m[1]] = [
                    'title'    => $m[2],
                    // Clamp to 0..100: an out-of-range percentage (e.g. "150%") must
                    // not be persisted as a rawscore above maxscore.
                    'scorepct' => max(0.0, min(100.0, self::to_float($m[3]))),
                    'weighted' => self::to_float($m[4]),
                ];
            }
        }
        return $peritem;
    }

    /**
     * Casts a parsed numeric string to float, accepting a comma decimal separator.
     *
     * eXeLearning serialises the score/weight percentages with the producer's locale,
     * so a value can arrive as "60,5". Normalise the comma to a dot before casting.
     *
     * @param string $value Numeric string possibly using a comma decimal separator.
     * @return float
     */
    private static function to_float(string $value): float {
        return (float) str_replace(',', '.', $value);
    }

    /**
     * Recomputes the overall score (0..100) from the per-iDevice objectid scores.
     *
     * DEC-0018 / RIE-007 residual: the producer's `cmi.core.score.raw`
     * (`getFinalScore`) is corrupt under a multi-page `cmi.suspend_data` collision,
     * but the per-iDevice scores the shim captures by stable objectid are not. This
     * derives the overall as the weighted mean of each item's `scorepct` by its
     * `weighted` (eXeLearning's per-iDevice weight); when every weight is zero it
     * falls back to a simple mean so a package without weights still aggregates. For
     * a single-page package (no collision) this equals the producer's overall, so the
     * verified single-page behaviour is preserved.
     *
     * @param array $itemscores Map objectid => ['scorepct' => float, 'weighted' => float, ...].
     * @return float|null Overall percentage in 0..100, or null when no item is usable.
     */
    public static function recompute_overall_pct(array $itemscores): ?float {
        $sumweighted = 0.0;
        $sumweight = 0.0;
        $sumscore = 0.0;
        $count = 0;
        foreach ($itemscores as $info) {
            if (!is_array($info) || !isset($info['scorepct'])) {
                continue;
            }
            $scorepct = max(0.0, min(100.0, (float) $info['scorepct']));
            $weight = (float) ($info['weighted'] ?? 0);
            $sumweighted += $scorepct * $weight;
            $sumweight += $weight;
            $sumscore += $scorepct;
            $count++;
        }
        if ($count === 0) {
            return null;
        }
        return ($sumweight > 0) ? ($sumweighted / $sumweight) : ($sumscore / $count);
    }

    /**
     * Routes per-iDevice scores to the gradebook by stable objectid (DEC-0017).
     *
     * `$itemscores` is keyed by the iDevice objectid (the `.idevice_node` element id
     * the client shim reads from the iframe DOM, which equals `<odeIdeviceId>` in
     * content.xml and the `objectid` stored in `exelearning_grade_item`). Each value
     * carries at least `scorepct`. Routing by objectid is collision-free across
     * pages, unlike the page-local N the producer puts in cmi.suspend_data.
     *
     * For each objectid that resolves to a non-deleted grade item it records the
     * attempt and, unless the activity is in OVERALL grading mode, publishes the
     * aggregated grade to that itemnumber.
     *
     * @param \stdClass $exe          The exelearning instance record.
     * @param int       $userid       The grading user.
     * @param int       $attempt      Attempt number from attempts::resolve_attempt_number().
     * @param array     $itemscores   Map objectid => ['scorepct' => float, ...].
     * @param string    $sessiontoken Page-load session token.
     * @return array<int, float> Map of itemnumber => final published grade.
     */
    public static function apply_item_scores(
        \stdClass $exe,
        int $userid,
        int $attempt,
        array $itemscores,
        string $sessiontoken
    ): array {
        global $DB;

        $persaved = [];
        if ($itemscores === []) {
            return $persaved;
        }
        $ctx = self::grade_context($exe);

        // Index the registered grade items by their stable objectid so an incoming
        // score can be routed to the right itemnumber regardless of page order.
        $rows = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $exe->id, 'deleted' => 0],
            'itemnumber ASC',
            'id, itemnumber, name, objectid'
        );
        $byobjectid = [];
        foreach ($rows as $row) {
            $byobjectid[(string) $row->objectid] = $row;
        }

        foreach ($itemscores as $objectid => $info) {
            $objectid = (string) $objectid;
            // An objectid the package no longer exposes (or never had as a gradable
            // iDevice) has no column to receive the score: skip it silently.
            if (!isset($byobjectid[$objectid]) || !is_array($info)) {
                continue;
            }
            $row = $byobjectid[$objectid];
            $itemnumber = (int) $row->itemnumber;
            $scorepct = max(0.0, min(100.0, (float) ($info['scorepct'] ?? 0)));
            $persaved[$itemnumber] = self::apply_one(
                $exe,
                $ctx,
                $userid,
                $attempt,
                $itemnumber,
                $scorepct,
                (string) $row->name,
                $sessiontoken
            );
        }
        return $persaved;
    }

    /**
     * Legacy fallback: routes per-iDevice scores by the page-local index N parsed
     * from cmi.suspend_data, treating N directly as the itemnumber.
     *
     * Only correct for a single-page package whose iDevices are all gradable (see
     * RIE-007): when two gradable iDevices on different pages share the same
     * page-local N they collide here, so this is used only when the client shim
     * supplied no objectid map. Preserves the pre-DEC-0017 behaviour exactly.
     *
     * @param \stdClass $exe          The exelearning instance record.
     * @param int       $userid       The grading user.
     * @param int       $attempt      Attempt number from attempts::resolve_attempt_number().
     * @param array     $peritem      Map N => ['scorepct' => float, ...] from parse_suspend_data().
     * @param string    $sessiontoken Page-load session token.
     * @return array<int, float> Map of itemnumber => final published grade.
     */
    public static function apply_legacy_peritem(
        \stdClass $exe,
        int $userid,
        int $attempt,
        array $peritem,
        string $sessiontoken
    ): array {
        global $DB;

        $persaved = [];
        if ($peritem === []) {
            return $persaved;
        }
        $ctx = self::grade_context($exe);

        $rows = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $exe->id, 'deleted' => 0],
            'itemnumber ASC',
            'itemnumber, name, objectid'
        );
        foreach ($peritem as $itemnumber => $info) {
            $itemnumber = (int) $itemnumber;
            if (!isset($rows[$itemnumber]) || !is_array($info)) {
                continue;
            }
            $scorepct = max(0.0, min(100.0, (float) ($info['scorepct'] ?? 0)));
            $persaved[$itemnumber] = self::apply_one(
                $exe,
                $ctx,
                $userid,
                $attempt,
                $itemnumber,
                $scorepct,
                (string) $rows[$itemnumber]->name,
                $sessiontoken
            );
        }
        return $persaved;
    }

    /**
     * Resolves the per-instance grading context used by both routing paths.
     *
     * @param \stdClass $exe The exelearning instance record.
     * @return array Keys: grademax (float), grademethod (int), grademodel (int), itemdetailsbase (array).
     */
    private static function grade_context(\stdClass $exe): array {
        return [
            'grademax'    => (float) ($exe->grademax ?? 100),
            'grademethod' => (int) ($exe->grademethod ?? attempts::GRADE_HIGHEST),
            'grademodel'  => (int) ($exe->grademodel ?? EXELEARNING_GRADEMODEL_PERITEM),
            'itemdetailsbase' => [
                'gradetype' => GRADE_TYPE_VALUE,
                'grademax'  => $exe->grademax ?? 100,
                'grademin'  => $exe->grademin ?? 0,
                'display'   => (int) ($exe->gradedisplaytype ?? GRADE_DISPLAY_TYPE_DEFAULT),
            ],
        ];
    }

    /**
     * Records one item's attempt and publishes its aggregated gradebook grade.
     *
     * @param \stdClass $exe          The exelearning instance record.
     * @param array     $ctx          Output of {@see self::grade_context()}.
     * @param int       $userid       The grading user.
     * @param int       $attempt      Attempt number.
     * @param int       $itemnumber   Grade item number (> 0).
     * @param float     $scorepct     Score as a 0..100 percentage.
     * @param string    $name         Gradebook column name.
     * @param string    $sessiontoken Page-load session token.
     * @return float The final published (aggregated) grade for the item.
     */
    private static function apply_one(
        \stdClass $exe,
        array $ctx,
        int $userid,
        int $attempt,
        int $itemnumber,
        float $scorepct,
        string $name,
        string $sessiontoken
    ): float {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $grademax = $ctx['grademax'];
        $rawitem = ($scorepct / 100.0) * $grademax;

        attempts::record_item(
            $exe->id,
            $userid,
            $attempt,
            $itemnumber,
            $rawitem,
            $grademax,
            'completed',
            $sessiontoken
        );
        // Gradebook grade = aggregation of attempts according to grademethod.
        $scaled = attempts::aggregate_scaled($exe->id, $userid, $itemnumber, $ctx['grademethod']);
        $finalitem = ($scaled === null) ? $rawitem : ($scaled * $grademax);
        // In "overall only" mode per-iDevice columns are not published (DEC-0008),
        // but the attempt IS recorded for the report.
        if ($ctx['grademodel'] !== EXELEARNING_GRADEMODEL_OVERALL) {
            grade_update(
                'mod/exelearning',
                $exe->course,
                'mod',
                'exelearning',
                $exe->id,
                $itemnumber,
                (object) ['userid' => $userid, 'rawgrade' => $finalitem],
                $ctx['itemdetailsbase'] + ['itemname' => $name]
            );
        }
        return $finalitem;
    }
}
