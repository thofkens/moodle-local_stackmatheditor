@local @local_stackmatheditor @local_stackmatheditor_cas
Feature: Every visible semantic button produces something STACK can read
  In order to trust the toolbar
  As a question author
  I need each button's output to be accepted by the CAS, not merely converted without errors

  # Issue #34. The Jest contract test proves every button produces a CAS-safe string; it has no
  # CAS, so it cannot prove the CAS agrees. These scenarios put the expression in front of a real
  # STACK and let STACK say whether it can read it.
  #
  # One scenario per group rather than per button: a group shares a contract, and a broken
  # mapping shows up on the first expression that uses it.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following config values are set as admin:
      | maximacommand | maxima | qtype_stack |
      | castimeout    | 300    | qtype_stack |
    And the STACK CAS platform is reset to direct Maxima
    And the plugin enabled mode is set to "1"
    And a STACK quiz "Contract Quiz" with algebraic input exists in "C1"
    And I log in as "student1"
    And I start the STACK MathQuill quiz attempt "Contract Quiz"

  @javascript
  Scenario: Basic arithmetic
    When I enter latex "1+2\cdot 3-4\div 5" into the MathQuill field for "ans1"
    Then STACK should accept the answer in "ans1"

  @javascript
  Scenario: Powers and roots
    When I enter latex "\sqrt{x^{2}+\sqrt{y}}" into the MathQuill field for "ans1"
    Then STACK should accept the answer in "ans1"

  @javascript
  Scenario: Exponential and logarithm
    When I enter latex "\ln\left(e^{x}\right)" into the MathQuill field for "ans1"
    Then STACK should accept the answer in "ans1"

  @javascript
  Scenario: Trigonometry
    When I enter latex "\sin\left(x\right)+\cos\left(2x\right)+\tan\left(x\right)" into the MathQuill field for "ans1"
    Then STACK should accept the answer in "ans1"

  @javascript
  Scenario: Absolute value
    When I enter latex "\left|x-1\right|" into the MathQuill field for "ans1"
    Then STACK should accept the answer in "ans1"

  @javascript
  Scenario: Comparators
    When I enter latex "x\geq 2" into the MathQuill field for "ans1"
    Then STACK should accept the answer in "ans1"

  @javascript
  Scenario: Set theory
    When I enter latex "\left\{1,2\right\}\cup\left\{3\right\}" into the MathQuill field for "ans1"
    Then STACK should accept the answer in "ans1"

  @javascript
  Scenario: Logic
    When I enter latex "x>0\land x<1" into the MathQuill field for "ans1"
    Then STACK should accept the answer in "ans1"

  @javascript
  Scenario: Matrices
    When I enter latex "\begin{bmatrix}1&2\\3&4\end{bmatrix}" into the MathQuill field for "ans1"
    Then STACK should accept the answer in "ans1"

  @javascript
  Scenario: Determinant and transpose
    When I enter latex "\det\left(\begin{bmatrix}1&2\\3&4\end{bmatrix}\right)" into the MathQuill field for "ans1"
    Then STACK should accept the answer in "ans1"

  @javascript
  Scenario: Column vectors
    When I enter latex "\begin{pmatrix}1\\2\\3\end{pmatrix}" into the MathQuill field for "ans1"
    Then STACK should accept the answer in "ans1"

  @javascript
  Scenario: Geometry with points as lists
    When I enter latex "\left(2|3\right)" into the MathQuill field for "ans1"
    Then STACK should accept the answer in "ans1"

  @javascript
  Scenario: Greek letters
    When I enter latex "\alpha+\beta\cdot\gamma" into the MathQuill field for "ans1"
    Then STACK should accept the answer in "ans1"

  @javascript
  Scenario: Mathematical constants
    When I enter latex "\pi+e" into the MathQuill field for "ans1"
    Then STACK should accept the answer in "ans1"

  @javascript
  Scenario: Integrals
    When I enter latex "\int x\,\mathrm{d}x" into the MathQuill field for "ans1"
    Then STACK should accept the answer in "ans1"

  @javascript
  Scenario: Derivatives
    When I enter latex "\frac{\mathrm{d}}{\mathrm{d}x}x^{2}" into the MathQuill field for "ans1"
    Then STACK should accept the answer in "ans1"
