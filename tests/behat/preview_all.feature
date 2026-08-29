@qbank @qbank_bulkpreview @javascript
Feature: Preview every question in a category as a quiz
  In order to review a whole question category at a glance
  As a teacher
  I want to see all its questions rendered as they would appear in a quiz

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | name    | course | idnumber |
      | qbank    | Qbank 1 | C1     | qbank1   |
    And the following "question categories" exist:
      | contextlevel    | reference | name       |
      | Activity module | qbank1    | Parent cat |
      | Activity module | qbank1    | Empty cat  |
    And the following "question categories" exist:
      | contextlevel    | reference | name      | questioncategory |
      | Activity module | qbank1    | Child cat | Parent cat       |
    And the following "questions" exist:
      | questioncategory | qtype       | name       | questiontext          |
      | Parent cat       | truefalse   | Parent TF  | The sky is blue       |
      | Parent cat       | numerical   | Parent Num | What is two plus two  |
      | Child cat        | shortanswer | Child SA   | Name a primary colour |

  # The tertiary-nav menu links are built at page load, so the category filter must
  # be in the page URL (a reload) before it reaches them and, in turn, this plugin.
  Scenario: Preview all questions in a category rendered as a quiz
    Given I am on the "Qbank 1" "core_question > question bank" page logged in as "teacher1"
    And I apply question bank filter "Category" with value "Parent cat"
    And I reload the page
    When I select "Preview all" from the "Question bank tertiary navigation" singleselect
    Then I should see "The sky is blue"
    And I should see "What is two plus two"
    And I should see "Marked out of"
    And the field "Include subcategories" matches value "1"

  Scenario: Subcategory questions are included only when requested
    Given I am on the "Qbank 1" "core_question > question bank" page logged in as "teacher1"
    And I apply question bank filter "Category" with value "Parent cat"
    And I reload the page
    And I select "Preview all" from the "Question bank tertiary navigation" singleselect
    And I should see "Name a primary colour"
    When I set the field "Include subcategories" to "0"
    And I press "Apply"
    Then I should see "The sky is blue"
    And I should not see "Name a primary colour"

  Scenario: Showing correct answers reveals the right answer
    Given I am on the "Qbank 1" "core_question > question bank" page logged in as "teacher1"
    And I apply question bank filter "Category" with value "Parent cat"
    And I reload the page
    And I select "Preview all" from the "Question bank tertiary navigation" singleselect
    And I should not see "The correct answer is"
    When I set the field "Show correct answers" to "1"
    And I press "Apply"
    Then I should see "The correct answer is"

  Scenario: An empty category shows a friendly message
    Given I am on the "Qbank 1" "core_question > question bank" page logged in as "teacher1"
    And I apply question bank filter "Category" with value "Empty cat"
    And I reload the page
    When I select "Preview all" from the "Question bank tertiary navigation" singleselect
    Then I should see "There are no questions in this category"

  Scenario: The category picker switches which category is previewed
    Given I am on the "Qbank 1" "core_question > question bank" page logged in as "teacher1"
    And I apply question bank filter "Category" with value "Parent cat"
    And I reload the page
    And I select "Preview all" from the "Question bank tertiary navigation" singleselect
    And I should see "The sky is blue"
    When I set the field "Category to preview" to "Child cat"
    And I press "Apply"
    Then I should see "Name a primary colour"
    And I should not see "The sky is blue"
