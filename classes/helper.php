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

namespace qbank_bulkpreview;

use core_question\local\bank\question_version_status;

/**
 * Helper for qbank_bulkpreview.
 *
 * @package    qbank_bulkpreview
 * @copyright  2026 Lukasz Miskowicz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /**
     * Shared FROM/WHERE clause selecting the latest ready version of every real
     * question in the given categories.
     *
     * Sub-questions (q.parent <> 0) and random questions are excluded because
     * they cannot be previewed on their own.
     *
     * @param int[] $categoryids one or more question category IDs
     * @return array [string $fromwhere, array $params]
     */
    private static function from_where(array $categoryids): array {
        global $DB;

        [$catsql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'cat');
        $params['ready'] = question_version_status::QUESTION_STATUS_READY;
        $params['noparent'] = 0;

        $fromwhere = "FROM {question} q
                      JOIN {question_versions} qv ON qv.questionid = q.id
                      JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                     WHERE qbe.questioncategoryid $catsql
                       AND q.parent = :noparent
                       AND q.qtype <> 'random'
                       AND qv.version = (
                               SELECT MAX(v.version)
                                 FROM {question_versions} v
                                WHERE v.questionbankentryid = qbe.id
                                  AND v.status = :ready
                           )";

        return [$fromwhere, $params];
    }

    /**
     * Count the previewable questions in the given categories.
     *
     * @param int[] $categoryids one or more question category IDs
     * @return int
     */
    public static function count_questions(array $categoryids): int {
        global $DB;

        if (empty($categoryids)) {
            return 0;
        }

        [$fromwhere, $params] = self::from_where($categoryids);

        return $DB->count_records_sql("SELECT COUNT(1) $fromwhere", $params);
    }

    /**
     * Return the IDs of the latest ready version of every previewable question
     * in the given categories, ordered by question bank entry.
     *
     * @param int[] $categoryids one or more question category IDs
     * @param int $limitfrom return a subset of records, starting at this point (optional)
     * @param int $limitnum return a subset comprising this many records (optional, required if $limitfrom is set)
     * @return int[] question IDs
     */
    public static function get_question_ids(array $categoryids, int $limitfrom = 0, int $limitnum = 0): array {
        global $DB;

        if (empty($categoryids)) {
            return [];
        }

        [$fromwhere, $params] = self::from_where($categoryids);
        $sql = "SELECT q.id $fromwhere ORDER BY qbe.id ASC";

        return array_map('intval', array_keys($DB->get_records_sql($sql, $params, $limitfrom, $limitnum)));
    }
}
