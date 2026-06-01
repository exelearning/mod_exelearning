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

  # Multi-page detection (server-rendered, no @javascript): a package whose two
  # gradable iDevices live on different pages registers them as two distinct
  # columns. This is the parser/sync half of the RIE-007 / DEC-0017 fix.
  Scenario: A multi-page package registers one column per gradable iDevice across pages
    Given the following "activities" exist:
      | activity    | name            | course | idnumber | packagefilepath                                |
      | exelearning | Multi-page unit | C1     | exemp    | research/fixtures/elpx/multipage-gradable.elpx |
    And I am on the "Multi-page unit" "exelearning activity" page logged in as teacher1
    Then I should see "Gradable iDevices detected:"
    And I should see "#1 trueorfalse"
    And I should see "#2 guess"

  # Browser-level bridge coverage for DEC-0017 belongs in manual/Playwright e2e:
  # under moodle-plugin-ci the JS driver can enter the scenario with Moodle core JS
  # still pending before the first Background step. This deterministic Behat case
  # keeps the report contract covered while PHPUnit covers the objectid collision.
  Scenario: Objectid-routed scores on different pages appear in their report columns
    Given the following "activities" exist:
      | activity    | name            | course | idnumber | packagefilepath                                |
      | exelearning | Multi-page unit | C1     | exemp    | research/fixtures/elpx/multipage-gradable.elpx |
    And the following eXeLearning SCORM scores exist:
      | activity | user     | sessiontoken     | objectid            | score |
      | exemp    | student1 | multipage-attempt | idevice-tf-0001     | 90    |
      | exemp    | student1 | multipage-attempt | idevice-guess-0002  | 30    |
    When I am on the "Multi-page unit" "exelearning activity" page logged in as teacher1
    And I follow "View attempts report"
    Then I should see "Page One"
    And I should see "Page Two"
    And I should see "90.00 / 100.00"
    And I should see "30.00 / 100.00"
