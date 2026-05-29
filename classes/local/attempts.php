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
 * Attempt recording and aggregation for mod_exelearning (DEC-0007).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local;

/**
 * Stores each user submission as an attempt and aggregates attempts into the
 * gradebook according to the per-instance grademethod, mirroring
 * mod_h5pactivity / mod_scorm.
 */
class attempts {
    /** @var int Aggregation: keep the highest scaled score across attempts. */
    public const GRADE_HIGHEST = 0;
    /** @var int Aggregation: average of all attempts. */
    public const GRADE_AVERAGE = 1;
    /** @var int Aggregation: first attempt. */
    public const GRADE_FIRST = 2;
    /** @var int Aggregation: most recent attempt. */
    public const GRADE_LAST = 3;
    /** @var int Aggregation: lowest scaled score across attempts. */
    public const GRADE_LOWEST = 4;

    /** @var int Review: students never see their past attempts. */
    public const REVIEW_NONE = 0;
    /** @var int Review: students can always review their past attempts. */
    public const REVIEW_ALWAYS = 1;
    /** @var int Review: students review only once the activity is complete. */
    public const REVIEW_AFTERCOMPLETION = 2;

    /**
     * Selectable review modes, for the settings form.
     *
     * @return array<int,string> mode constant => lang string key
     */
    public static function reviewmode_options(): array {
        return [
            self::REVIEW_ALWAYS          => 'reviewmode_always',
            self::REVIEW_AFTERCOMPLETION => 'reviewmode_aftercompletion',
            self::REVIEW_NONE            => 'reviewmode_none',
        ];
    }

    /**
     * Count distinct attempts a user has on an activity (for maxattempt).
     *
     * @param int $exelearningid
     * @param int $userid
     * @return int
     */
    public static function count_user_attempts(int $exelearningid, int $userid): int {
        global $DB;
        return (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT attempt) FROM {exelearning_attempt}
                  WHERE exelearningid = ? AND userid = ?",
            [$exelearningid, $userid]
        );
    }

    /**
     * Lang string key for a grademethod value (for display, not just the form).
     *
     * @param int $grademethod
     * @return string
     */
    public static function grademethod_stringkey(int $grademethod): string {
        $options = self::grademethod_options();
        return $options[$grademethod] ?? $options[self::GRADE_HIGHEST];
    }

    /**
     * Teacher-facing participation summary for the activity front page
     * (DEC-0011 option B, "Assignment-style" summary). Counts how many of the
     * given users have at least one attempt and the mean overall scaled score.
     *
     * Group filtering is the caller's responsibility: pass the userids that the
     * teacher is allowed to see (respecting separate groups).
     *
     * @param int $exelearningid
     * @param int[] $userids Candidate users (enrolled students visible to the teacher).
     * @return array{total:int, attempted:int, meanpercent:float|null}
     */
    public static function participation_summary(int $exelearningid, array $userids): array {
        global $DB;

        $total = count($userids);
        if ($total === 0) {
            return ['total' => 0, 'attempted' => 0, 'meanpercent' => null];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['exeid'] = $exelearningid;

        // Distinct users with at least one overall (itemnumber=0) attempt.
        $attempted = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT userid) FROM {exelearning_attempt}
                  WHERE exelearningid = :exeid AND itemnumber = 0 AND userid $insql",
            $params
        );

        // Mean of each user's best overall scaled score (0..1) → percent.
        $best = $DB->get_records_sql(
            "SELECT userid, MAX(scaledscore) AS best
                   FROM {exelearning_attempt}
                  WHERE exelearningid = :exeid AND itemnumber = 0 AND userid $insql
               GROUP BY userid",
            $params
        );
        $meanpercent = null;
        if (!empty($best)) {
            $sum = 0.0;
            foreach ($best as $row) {
                $sum += (float) $row->best;
            }
            $meanpercent = ($sum / count($best)) * 100.0;
        }

        return ['total' => $total, 'attempted' => $attempted, 'meanpercent' => $meanpercent];
    }

    /**
     * All selectable aggregation methods, for the settings form.
     *
     * @return array<int,string> method constant => lang string key
     */
    public static function grademethod_options(): array {
        return [
            self::GRADE_HIGHEST => 'grademethod_highest',
            self::GRADE_AVERAGE => 'grademethod_average',
            self::GRADE_FIRST   => 'grademethod_first',
            self::GRADE_LAST    => 'grademethod_last',
            self::GRADE_LOWEST  => 'grademethod_lowest',
        ];
    }

    /**
     * Resolve the attempt number for a page-load session.
     *
     * All auto-commits of the same page view share one $sessiontoken, so they
     * update the same attempt. The first commit of a fresh session allocates a
     * new attempt number (max for this user/activity + 1).
     *
     * @param int $exelearningid
     * @param int $userid
     * @param string $sessiontoken
     * @return int Attempt number to write to.
     */
    public static function resolve_attempt_number(
        int $exelearningid,
        int $userid,
        string $sessiontoken
    ): int {
        global $DB;

        if ($sessiontoken !== '') {
            $existing = $DB->get_field('exelearning_attempt', 'attempt', [
                'exelearningid' => $exelearningid,
                'userid'        => $userid,
                'sessiontoken'  => $sessiontoken,
            ], IGNORE_MULTIPLE);
            if ($existing !== false) {
                return (int) $existing;
            }
        }

        $max = (int) $DB->get_field_sql(
            "SELECT COALESCE(MAX(attempt), 0) FROM {exelearning_attempt}
                  WHERE exelearningid = ? AND userid = ?",
            [$exelearningid, $userid]
        );
        return $max + 1;
    }

    /**
     * Record (insert or update) one item result inside an attempt.
     *
     * Upsert keyed by (exelearningid, userid, attempt, itemnumber) so repeated
     * auto-commits in the same session refine the same row instead of piling up.
     *
     * @param int $exelearningid
     * @param int $userid
     * @param int $attempt Attempt number from resolve_attempt_number().
     * @param int $itemnumber 0=overall, >0=iDevice.
     * @param float $rawscore
     * @param float $maxscore
     * @param string $status completed|passed|failed|incomplete
     * @param string $sessiontoken
     */
    public static function record_item(
        int $exelearningid,
        int $userid,
        int $attempt,
        int $itemnumber,
        float $rawscore,
        float $maxscore,
        string $status,
        string $sessiontoken
    ): void {
        global $DB;

        $now = time();
        $scaled = ($maxscore > 0) ? max(0.0, min(1.0, $rawscore / $maxscore)) : 0.0;

        $existing = $DB->get_record('exelearning_attempt', [
            'exelearningid' => $exelearningid,
            'userid'        => $userid,
            'attempt'       => $attempt,
            'itemnumber'    => $itemnumber,
        ]);

        if ($existing) {
            $existing->rawscore     = $rawscore;
            $existing->maxscore     = $maxscore;
            $existing->scaledscore  = $scaled;
            $existing->status       = $status;
            $existing->sessiontoken = $sessiontoken;
            $existing->timemodified = $now;
            $DB->update_record('exelearning_attempt', $existing);
        } else {
            $DB->insert_record('exelearning_attempt', (object) [
                'exelearningid' => $exelearningid,
                'userid'        => $userid,
                'attempt'       => $attempt,
                'itemnumber'    => $itemnumber,
                'rawscore'      => $rawscore,
                'maxscore'      => $maxscore,
                'scaledscore'   => $scaled,
                'status'        => $status,
                'sessiontoken'  => $sessiontoken,
                'timecreated'   => $now,
                'timemodified'  => $now,
            ]);
        }
    }

    /**
     * Aggregate a user's attempts for one item into a single scaled score.
     *
     * @param int $exelearningid
     * @param int $userid
     * @param int $itemnumber
     * @param int $grademethod One of the GRADE_* constants.
     * @return float|null Scaled score (0..1) or null when there are no attempts.
     */
    public static function aggregate_scaled(
        int $exelearningid,
        int $userid,
        int $itemnumber,
        int $grademethod
    ): ?float {
        global $DB;

        $scaled = $DB->get_fieldset_sql(
            "SELECT scaledscore FROM {exelearning_attempt}
                  WHERE exelearningid = ? AND userid = ? AND itemnumber = ?
               ORDER BY attempt ASC",
            [$exelearningid, $userid, $itemnumber]
        );
        if (empty($scaled)) {
            return null;
        }
        $scaled = array_map('floatval', $scaled);

        switch ($grademethod) {
            case self::GRADE_AVERAGE:
                return array_sum($scaled) / count($scaled);
            case self::GRADE_FIRST:
                return reset($scaled);
            case self::GRADE_LAST:
                return end($scaled);
            case self::GRADE_LOWEST:
                return min($scaled);
            case self::GRADE_HIGHEST:
            default:
                return max($scaled);
        }
    }
}
