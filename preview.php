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
 * Render every question in a question bank category as it would appear in a quiz.
 *
 * This is a read-only preview: a question usage is built in memory, rendered and
 * discarded. Nothing is written to the database and the questions cannot be answered.
 *
 * @package    qbank_bulkpreview
 * @copyright  2026 Lukasz Miskowicz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/question/editlib.php');

use qbank_bulkpreview\helper;

require_login();
core_question\local\bank\helper::require_plugin_enabled('qbank_bulkpreview');

[$thispageurl, $contexts, $cmid, $cm, $module, $pagevars] =
        question_edit_setup('questions', '/question/bank/bulkpreview/preview.php');

$recurse     = optional_param('recurse', 1, PARAM_BOOL);
$showcorrect = optional_param('showcorrect', 0, PARAM_BOOL);
$page        = optional_param('page', 0, PARAM_INT);
$perpage     = optional_param('perpage', 20, PARAM_INT);
$perpage     = min(max($perpage, 1), 100);

// Resolve the current category. The question bank carries the selected category
// either in the legacy "cat" parameter (id,contextid) or, when a category filter
// is applied, only in the "filter" datafilter. Mirror core question bank view and
// prefer the filter value when present.
[$catid] = explode(',', $pagevars['cat']);
if (isset($pagevars['filter']['category']['values'][0])) {
    $catid = (int) $pagevars['filter']['category']['values'][0];
}
$category = $DB->get_record('question_categories', ['id' => $catid], '*', MUST_EXIST);
$catcontext = context::instance_by_id($category->contextid);

// The category must belong to a context this question bank is allowed to use.
$allowedcontextids = array_map(static fn($context) => $context->id, $contexts->all());
if (!in_array($category->contextid, $allowedcontextids)) {
    throw new moodle_exception('invalidcontext', 'error');
}

// Normalised "id,contextid" for the resolved category, so this page's own links and
// the filter form carry it explicitly regardless of how it was passed in.
$catparam = $category->id . ',' . $category->contextid;

// Every category in this question bank, indented and with question counts, for the
// picker in the filter bar. Keys are "id,contextid"; the popup-form shape groups by
// context in the way html_writer::select() expects for optgroups.
$categoryoptions = (new \core_question\output\question_category_selector())
    ->question_category_options($contexts->all(), false, 0, true);

// Must be allowed to look at questions in this context at all. Per-question
// viewmine/viewall filtering happens in the loop below.
if (!has_any_capability(['moodle/question:viewall', 'moodle/question:viewmine'], $catcontext)) {
    require_capability('moodle/question:viewall', $catcontext);
}

$thispageurl->params([
    'cat'         => $catparam,
    'recurse'     => $recurse,
    'showcorrect' => $showcorrect,
    'perpage'     => $perpage,
]);

$PAGE->set_url($thispageurl);
$PAGE->set_title(get_string('previewall', 'qbank_bulkpreview'));
$PAGE->set_heading($COURSE->fullname);
$PAGE->activityheader->disable();

// Collect the questions for this category (and optionally its subcategories).
$categoryids = $recurse ? question_categorylist($category->id) : [$category->id];
$totalcount = helper::count_questions($categoryids);

// If filters changed and the requested page no longer exists, fall back to the last one.
$lastpage = $totalcount ? (int) ceil($totalcount / $perpage) - 1 : 0;
$page = min(max($page, 0), $lastpage);

// Only this page's IDs are fetched from the database.
$pagequestionids = helper::get_question_ids($categoryids, $page * $perpage, $perpage);

// Bulk-load the question definitions for the page in one round of queries
// (the same path mod_quiz uses), rather than one load per question.
$questiondata = [];
if (!empty($pagequestionids)) {
    $loaded = question_load_questions($pagequestionids);
    if (is_array($loaded)) {
        $questiondata = $loaded;
    }
}

// A single context-level check covers everyone with viewall / editall; only
// viewmine / editmine restricted users need the per-question filter below.
$canviewall = has_capability('moodle/question:viewall', $catcontext);
$caneditall = has_capability('moodle/question:editall', $catcontext);
$showeditlinks = \core\plugininfo\qbank::is_plugin_enabled('qbank_editquestion');

// Build an in-memory question usage and start every question. Nothing is saved.
$quba = question_engine::make_questions_usage_by_activity('qbank_bulkpreview', $catcontext);
$quba->set_preferred_behaviour('deferredfeedback');

$slots = [];
$slotquestions = [];
foreach ($pagequestionids as $questionid) {
    if (
        !isset($questiondata[$questionid]) ||
            !question_bank::is_qtype_installed($questiondata[$questionid]->qtype)
    ) {
        // Missing question type or broken definition. Skip it.
        continue;
    }
    try {
        $question = question_bank::make_question($questiondata[$questionid]);
    } catch (Exception $e) {
        continue;
    }
    if (!$canviewall && !question_has_capability_on($question, 'view')) {
        continue;
    }
    try {
        $slot = $quba->add_question($question, $question->defaultmark);
    } catch (Exception $e) {
        continue;
    }
    $slots[] = $slot;
    $slotquestions[$slot] = $question;
}

$answerswarning = false;
if (!empty($slots)) {
    $quba->start_all_questions();
    if ($showcorrect) {
        // Submit the model answer into each question (the same thing the single
        // question preview's "Fill in correct responses" does) then finish it, so
        // the correct option shows filled in and marked, not just described in a
        // feedback line. Done per question so one that has no single correct
        // response (e.g. essay) does not stop the rest.
        foreach ($slots as $slot) {
            try {
                $correctresponse = $quba->get_correct_response($slot);
                if ($correctresponse !== null) {
                    $quba->process_action($slot, $correctresponse);
                }
                $quba->finish_question($slot);
            } catch (Exception $e) {
                $answerswarning = true;
            }
        }
    }
}

// Display options: readonly, quiz-like, with optional answers/feedback.
$options = new question_display_options();
$options->readonly = true;
$options->flags = question_display_options::HIDDEN;
$options->marks = question_display_options::MAX_ONLY;
$options->manualcomment = question_display_options::HIDDEN;
$options->history = question_display_options::HIDDEN;
if ($showcorrect) {
    $options->correctness = question_display_options::VISIBLE;
    $options->feedback = question_display_options::VISIBLE;
    $options->numpartscorrect = question_display_options::VISIBLE;
    $options->generalfeedback = question_display_options::VISIBLE;
    $options->rightanswer = question_display_options::VISIBLE;
} else {
    $options->correctness = question_display_options::HIDDEN;
    $options->feedback = question_display_options::HIDDEN;
    $options->generalfeedback = question_display_options::HIDDEN;
    $options->rightanswer = question_display_options::HIDDEN;
}

question_engine::initialise_js();

if ($answerswarning) {
    \core\notification::warning(get_string('cannotshowanswers', 'qbank_bulkpreview'));
}

echo $OUTPUT->header();

// Rendering a large category can take a while and nothing below writes to the
// session, so release the session lock now to avoid blocking the user's other tabs.
\core\session\manager::write_close();

// Question bank tertiary navigation (the same select menu as the other tabs).
$renderer = $PAGE->get_renderer('core_question', 'bank');
echo $renderer->render(new \core_question\output\qbank_action_menu($thispageurl));

echo $OUTPUT->heading(format_string($category->name, true, ['context' => $catcontext]));

// Filter bar.
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $thispageurl->out_omit_querystring(),
        'class' => 'mb-3 d-flex flex-wrap align-items-center gap-3',
        'aria-label' => get_string('filteroptions', 'qbank_bulkpreview')]);
// Hidden defaults so an unchecked box submits an explicit 0 (the checkbox below, when
// ticked, overrides these because it appears later in the query string).
foreach (
    ['cmid' => $cmid, 'perpage' => $perpage, 'recurse' => 0, 'showcorrect' => 0] as $name => $value
) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
}
echo html_writer::div(
    html_writer::label(
        get_string('previewcategory', 'qbank_bulkpreview'),
        'id_bulkpreviewcat',
        false,
        ['class' => 'me-1']
    ) .
    html_writer::select($categoryoptions, 'cat', $catparam, false, ['id' => 'id_bulkpreviewcat']),
    'form-group'
);
echo html_writer::div(
    html_writer::checkbox('recurse', 1, (bool) $recurse, get_string('recurse', 'qbank_bulkpreview')),
    'form-check'
);
echo html_writer::div(
    html_writer::checkbox('showcorrect', 1, (bool) $showcorrect, get_string('showcorrect', 'qbank_bulkpreview')),
    'form-check'
);
echo html_writer::tag(
    'button',
    get_string('apply', 'qbank_bulkpreview'),
    ['type' => 'submit', 'class' => 'btn btn-secondary']
);
echo html_writer::end_tag('form');

if ($totalcount == 0) {
    echo $OUTPUT->notification(get_string('noquestions', 'qbank_bulkpreview'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$rangeinfo = (object) [
    'from'  => $page * $perpage + 1,
    'to'    => min($page * $perpage + $perpage, $totalcount),
    'total' => $totalcount,
];
echo html_writer::tag('p', get_string('showingrange', 'qbank_bulkpreview', $rangeinfo), ['class' => 'text-muted']);
echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $thispageurl);

// No per-question view event is triggered here on purpose: a page shows up to
// 100 questions and firing an event for each would only add log noise.

// Render the questions inside an inert form so form-based question types display correctly.
echo html_writer::start_tag('form', ['action' => '#', 'method' => 'post', 'onsubmit' => 'return false;',
        'class' => 'qbank-bulkpreview']);
$editreturnurl = (new moodle_url($thispageurl, ['page' => $page]))->out_as_local_url(false);
$displaynumber = $page * $perpage + 1;
foreach ($slots as $slot) {
    $questionhtml = $quba->render_question($slot, $options, (string) $displaynumber);
    $question = $slotquestions[$slot];

    $editlink = '';
    if ($showeditlinks && ($caneditall || question_has_capability_on($question, 'edit'))) {
        $editurl = new moodle_url('/question/bank/editquestion/question.php', [
            'id' => $question->id,
            'cmid' => $cmid,
            'returnurl' => $editreturnurl,
        ]);
        $editlink = html_writer::div(
            html_writer::link(
                $editurl,
                $OUTPUT->pix_icon('t/edit', '') . get_string('editquestion', 'question'),
                ['target' => '_blank', 'rel' => 'noopener']
            ),
            'qbank-bulkpreview-edit small mt-2'
        );
    }

    if ($editlink !== '') {
        // Splice the link into the question's own info column, just after the
        // version badge. If core ever changes that markup, fall back to placing
        // it below the whole question.
        $spliced = preg_replace(
            '~(</div>\s*<div class="content">)~',
            $editlink . '$1',
            $questionhtml,
            1,
            $count
        );
        $questionhtml = $count ? $spliced : $questionhtml . $editlink;
    }

    echo html_writer::div($questionhtml, 'qbank-bulkpreview-item');
    $displaynumber++;
}
echo html_writer::end_tag('form');

echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $thispageurl);

echo $OUTPUT->footer();
