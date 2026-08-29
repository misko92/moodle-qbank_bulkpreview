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

/**
 * Question bank navigation node for qbank_bulkpreview.
 *
 * @package    qbank_bulkpreview
 * @copyright  2026 Lukasz Miskowicz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class navigation extends \core_question\local\bank\navigation_node_base {
    /**
     * The visible title of the "Preview all" tab.
     *
     * @return string
     */
    public function get_navigation_title(): string {
        return get_string('previewall', 'qbank_bulkpreview');
    }

    /**
     * The unique key identifying this navigation node.
     *
     * @return string
     */
    public function get_navigation_key(): string {
        return 'bulkpreview';
    }

    /**
     * The target of the "Preview all" tab.
     *
     * @return \moodle_url
     */
    public function get_navigation_url(): \moodle_url {
        return new \moodle_url('/question/bank/bulkpreview/preview.php');
    }

    /**
     * Capabilities that let a user see this tab (any one is enough).
     *
     * @return array|null
     */
    public function get_navigation_capabilities(): ?array {
        return ['moodle/question:viewall', 'moodle/question:viewmine'];
    }
}
