@mod @mod_exelearning
Feature: View a mod_exelearning activity and its attempts report
  In order to use eXeLearning content in a course
  As a teacher or a student
  I need to open the activity and, as a teacher, review attempts

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity    | name           | course | idnumber |
      | exelearning | Evaluable unit | C1     | exe1     |

  # These scenarios only assert server-rendered text, so they intentionally run
  # WITHOUT @javascript: the activity view embeds the eXeLearning package in an
  # iframe plus a SCORM shim that issues XHRs, which under the JS driver leaves
  # pending AJAX/JS that Behat waits on forever (the package content does not
  # exist for a generator-created instance). The non-JS driver renders the PHP
  # output we check here and never loads the iframe.
  Scenario: A teacher opens the activity and sees the detected gradable iDevices
    Given I am on the "Evaluable unit" "exelearning activity" page logged in as teacher1
    Then I should see "Evaluable unit"
    And I should see "Gradable iDevices detected:"
    And I should see "View attempts report"

  Scenario: A teacher opens the attempts report and sees the empty-state message
    Given I am on the "Evaluable unit" "exelearning activity" page logged in as teacher1
    When I follow "View attempts report"
    Then I should see "Attempts report"
    And I should see "No attempts have been recorded yet."

  Scenario: A student opens the activity and does not see the teacher report link
    Given I am on the "Evaluable unit" "exelearning activity" page logged in as student1
    Then I should see "Evaluable unit"
    And I should not see "View attempts report"

  Scenario: A teacher sees the participation summary line with no attempts yet
    Given I am on the "Evaluable unit" "exelearning activity" page logged in as teacher1
    Then I should see "0 of 1 students have attempted this activity."
