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
 * Tests for {@see helper}.
 *
 * @package    qbank_bulkpreview
 * @copyright  2026 Lukasz Miskowicz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qbank_bulkpreview\helper
 */
final class helper_test extends \advanced_testcase {
    public function test_get_question_ids(): void {
        global $DB;
        $this->resetAfterTest();

        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $parentcat = $generator->create_question_category(['contextid' => $context->id]);
        $childcat = $generator->create_question_category(
            ['contextid' => $context->id, 'parent' => $parentcat->id]
        );

        $q1 = $generator->create_question('truefalse', null, ['category' => $parentcat->id]);
        $q2 = $generator->create_question('shortanswer', null, ['category' => $parentcat->id]);
        $q3 = $generator->create_question('truefalse', null, ['category' => $childcat->id]);

        // A random question must never be returned. qtype_random has no test helper,
        // so fake one by re-typing a real question in the parent category.
        $randomq = $generator->create_question('truefalse', null, ['category' => $parentcat->id]);
        $DB->set_field('question', 'qtype', 'random', ['id' => $randomq->id]);

        // Non-recursive: only the two questions directly in the parent category.
        $ids = helper::get_question_ids([$parentcat->id]);
        sort($ids);
        $expected = [$q1->id, $q2->id];
        sort($expected);
        $this->assertEquals($expected, $ids);

        // Recursive: include the child category's question too.
        $ids = helper::get_question_ids([$parentcat->id, $childcat->id]);
        sort($ids);
        $expected = [$q1->id, $q2->id, $q3->id];
        sort($expected);
        $this->assertEquals($expected, $ids);

        // Empty input is handled.
        $this->assertSame([], helper::get_question_ids([]));
        $this->assertSame(0, helper::count_questions([]));

        // Counts match the ID-list length.
        $this->assertSame(2, helper::count_questions([$parentcat->id]));
        $this->assertSame(3, helper::count_questions([$parentcat->id, $childcat->id]));
    }

    public function test_get_question_ids_pagination(): void {
        $this->resetAfterTest();

        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $category = $generator->create_question_category(['contextid' => $context->id]);

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = (int) $generator->create_question('truefalse', null, ['category' => $category->id])->id;
        }

        $this->assertSame(5, helper::count_questions([$category->id]));

        $firsttwo = helper::get_question_ids([$category->id], 0, 2);
        $nexttwo = helper::get_question_ids([$category->id], 2, 2);
        $last = helper::get_question_ids([$category->id], 4, 2);

        $this->assertCount(2, $firsttwo);
        $this->assertCount(2, $nexttwo);
        $this->assertCount(1, $last);
        // Pages do not overlap and together cover everything.
        $this->assertSame(
            helper::get_question_ids([$category->id]),
            array_merge($firsttwo, $nexttwo, $last)
        );
    }

    public function test_get_question_ids_returns_latest_ready_version(): void {
        $this->resetAfterTest();

        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $category = $generator->create_question_category(['contextid' => $context->id]);

        $question = $generator->create_question('truefalse', null, ['category' => $category->id]);
        $updated = $generator->update_question($question, null, ['name' => 'Version 2']);

        $ids = helper::get_question_ids([$category->id]);

        $this->assertSame([(int) $updated->id], $ids);
        $this->assertNotContains((int) $question->id, $ids);
    }
}
