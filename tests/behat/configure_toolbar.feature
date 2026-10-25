@local @local_stackmatheditor
Feature: MathQuill toolbar configuration
  As a course teacher
  I want to configure the MathQuill toolbar for individual STACK questions and entire quizzes
  So that students see only the relevant toolbar buttons

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher@example.com  |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name      | idnumber |
      | quiz     | C1     | Test Quiz | quiz1    |
    And I log in as "teacher1"

  @javascript
  Scenario: Navigation selector contains MathQuill entry when STACK questions exist
    Given a STACK question exists in quiz "Test Quiz"
    When I am on the "Test Quiz" "mod_quiz > Edit" page
    Then I should see "Set up STACK MathQuill Editor" in the quiz navigation select

  @javascript
  Scenario: Quiz-level configure page opens without question ID
    Given a STACK question exists in quiz "Test Quiz"
    And I am on the "Test Quiz" "mod_quiz > Edit" page
    When I navigate to the STACK MathQuill quiz configuration
    Then I should see "MathQuill default settings for quiz: Test Quiz"
    And I should see "Default toolbar groups"

  @javascript
  Scenario: Question-level configure page shows question preview
    Given a STACK question "My STACK Q" exists in quiz "Test Quiz"
    And I am on the "Test Quiz" "mod_quiz > Edit" page
    When I click the MathQuill configure icon next to "My STACK Q"
    Then I should see "MathQuill toolbar for: My STACK Q"

  @javascript
  Scenario: Saving quiz-level config persists across page reload
    Given a STACK question exists in quiz "Test Quiz"
    And I am on the STACK MathQuill quiz configuration page for "Test Quiz"
    When I deselect the "Trigonometry" toolbar group
    And I press "Save configuration"
    And I reload the page
    Then the "Trigonometry" toolbar group should be deselected

  @javascript
  Scenario: Question-level config overrides quiz-level default
    Given a STACK question "Q1" exists in quiz "Test Quiz"
    And the quiz-level config has "Trigonometry" enabled
    And I am on the MathQuill configuration page for question "Q1" in "Test Quiz"
    When I deselect the "Trigonometry" toolbar group
    And I press "Save configuration"
    Then the question-level config for "Q1" should override the quiz default

  @javascript
  Scenario: Enabled checkbox only appears in modes 2 and 3
    Given the plugin enabled mode is set to "1"
    And a STACK question exists in quiz "Test Quiz"
    When I am on the STACK MathQuill quiz configuration page for "Test Quiz"
    Then I should see "Globally enabled"
    And I should not see a checkbox with id "id_sme_enabled"

  @javascript
  Scenario: Enabled checkbox appears and works in mode 2
    Given the plugin enabled mode is set to "2"
    And a STACK question exists in quiz "Test Quiz"
    When I am on the STACK MathQuill quiz configuration page for "Test Quiz"
    Then I should see a checkbox with id "id_sme_enabled"
    And the checkbox "id_sme_enabled" should be unchecked

  @javascript
  Scenario: Enabled checkbox is pre-checked in mode 3
    Given the plugin enabled mode is set to "3"
    And a STACK question exists in quiz "Test Quiz"
    When I am on the STACK MathQuill quiz configuration page for "Test Quiz"
    Then the checkbox "id_sme_enabled" should be checked

  # ── Back button returns to the calling page (#47) ─────────────────────────────

  @javascript
  Scenario: Back returns to the quiz view page the configuration was opened from
    Given a STACK question exists in quiz "Test Quiz"
    And I am on the "Test Quiz" "mod_quiz > View" page
    When I navigate to the STACK MathQuill quiz configuration
    And I press "Back"
    Then I should be on the quiz view page of "Test Quiz"

  @javascript
  Scenario: Back returns to the quiz edit page the configuration was opened from
    Given a STACK question exists in quiz "Test Quiz"
    And I am on the "Test Quiz" "mod_quiz > Edit" page
    When I navigate to the STACK MathQuill quiz configuration
    And I press "Back"
    Then I should be on the quiz edit page of "Test Quiz"

  Scenario: A direct call ignores an external return URL and falls back to the view page
    Given a STACK question exists in quiz "Test Quiz"
    And I am on the STACK MathQuill quiz configuration page for "Test Quiz" with return URL "https://example.org/"
    When I press "Back"
    Then I should be on the quiz view page of "Test Quiz"

  # ── Authorisation before question resolution (#54) ───────────────────────────

  Scenario Outline: A guest sees the login page, never a question-specific error
    Given a STACK question exists in quiz "Test Quiz"
    And I log out
    When I am on the STACK MathQuill quiz configuration page for "Test Quiz" with question "<question>"
    Then I should see "Log in"
    And I should not see "not a STACK question"
    And I should not see "Cannot resolve the question"

    Examples:
      | question    |
      | nonexistent |
      | stack       |

  Scenario: A logged-in user without the manage capability is stopped before the question is resolved
    Given a STACK question exists in quiz "Test Quiz"
    And I log in as "student1"
    Then the STACK MathQuill quiz configuration page for "Test Quiz" with question "nonexistent" is denied to me
