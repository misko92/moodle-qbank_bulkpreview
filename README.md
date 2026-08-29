# Bulk question preview (qbank_bulkpreview)

Adds a **Preview all** tab to the question bank that renders every question in the
current category exactly as it would appear inside a quiz.

## What it does

* Lists the latest *ready* version of every question in the selected category
  (optionally including subcategories).
* Paginated (20 per page by default, `perpage` up to 100). Only the current
  page's IDs are read from the database; the count is a single `COUNT(1)`.
* Bulk-loads the page's question definitions with `question_load_questions()`
  (the path `mod_quiz` uses) rather than one load per question.
* Builds a single in-memory `question_usage_by_activity` with the
  `deferredfeedback` behaviour, starts every question and renders it read-only.
* Optional **Show correct answers** mode finishes each question with an empty
  response so model answers and feedback are revealed. Done per question, so one
  type that cannot finish this way does not blank the rest of the page.

## What it does not do

* Nothing is written to the database. The usage is discarded at the end of the
  request, so there is no cleanup task and no privacy footprint.
* Questions cannot be answered — this is a preview, not an attempt.
* `random` questions and sub-questions are skipped (they cannot be previewed on
  their own). Questions whose type is missing/broken are skipped silently.

## Permissions

The tab is shown to users with `moodle/question:viewall` or
`moodle/question:viewmine` in the category context. Individual questions are
additionally filtered with `question_has_capability_on($question, 'view')`, so
`viewmine`-only users see just their own questions.

## Key files

| File | Purpose |
|------|---------|
| `classes/plugin_feature.php` | Registers the navigation node with the question bank. |
| `classes/navigation.php` | The "Preview all" tab (title, key, URL, capabilities). |
| `classes/helper.php` | `get_question_ids()` — latest ready version per entry in given categories. |
| `preview.php` | Builds the usage, renders the questions, filter bar + paging. |

## Install

Copy to `public/question/bank/bulkpreview/`, then visit *Site administration →
Notifications* (or run `php public/admin/cli/upgrade.php`). Purge caches in a dev
environment so the new component is picked up.
