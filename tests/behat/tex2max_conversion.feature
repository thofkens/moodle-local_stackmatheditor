@local @local_stackmatheditor
Feature: tex2max converts LaTeX to Maxima notation correctly
  As a plugin developer
  I want the MathQuill editor to produce correct Maxima output for all supported constructs
  So that STACK questions receive syntactically valid CAS expressions

  # Tests drive the full UI path: MathQuill input -> tex2max -> hidden STACK input.
  # Requires a working STACK CAS (for quiz attempt rendering) and MathQuill init.
  # The plugin variableMode is configured per-quiz; tests use the default "explicit_single"
  # for operator-protection tests and "stack" for symbol/fraction tests.

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
    And a STACK quiz "Conversion Quiz" with algebraic input exists in "C1"
    And I log in as "student1"
    And I start the STACK MathQuill quiz attempt "Conversion Quiz"

  # ── Operator keyword protection (#27) ────────────────────────────────────────

  @javascript
  Scenario: "or" is not split into o*r in explicit_single mode
    When I enter latex "x=3 or x=6" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should contain "or"
    And the underlying STACK input for "ans1" should not contain "o*r"

  @javascript
  Scenario: "and" is not split into a*n*d in explicit_single mode
    When I enter latex "x>0 and x<5" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should contain "and"
    And the underlying STACK input for "ans1" should not contain "a*n*d"

  @javascript
  Scenario: "not" is not split into n*o*t in explicit_single mode
    When I enter latex "\neg (x=0)" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should not contain "n*o*t"
    And the underlying STACK input for "ans1" should not contain "#g"

  # ── Mixed-fraction fix (#29) ──────────────────────────────────────────────────

  @javascript
  Scenario: Mixed fraction 2+1/2 is grouped correctly
    When I enter latex "2\frac{1}{2}" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "(2+1/2)"

  @javascript
  Scenario: Multi-digit mixed fraction 21+3/4 is grouped correctly
    When I enter latex "21\frac{3}{4}" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "(21+3/4)"

  @javascript
  Scenario: Regular fraction is not affected by mixed-fraction fix
    When I enter latex "\frac{1}{2}" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "(1)/(2)"

  # ── Pi notation (#31) ─────────────────────────────────────────────────────────

  @javascript
  Scenario: Pi is rendered as plain "pi" by default (usePercentPi off)
    When I enter latex "\pi" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "pi"

  @javascript
  Scenario: Pi is rendered as "%pi" when usePercentPi is enabled
    Given the plugin usePercentPi setting is "1"
    When I enter latex "\pi" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "%pi"

  # ── pm / ± expansion (#30) ────────────────────────────────────────────────────

  @javascript
  Scenario: Prefix pm produces two nounor alternatives with unary plus stripped
    When I enter latex "x=\pm 2" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "(x=2) nounor (x=-2)"
    And the underlying STACK input for "ans1" should not contain "x=+2"

  @javascript
  Scenario: Infix pm retains both plus and minus signs
    When I enter latex "a\pm b" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "(a+b) nounor (a-b)"

  @javascript
  Scenario: Coupled pm and mp give exactly two alternatives
    When I enter latex "x=a\pm b\mp c" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "(x=a+b-c) nounor (x=a-b+c)"

  @javascript
  Scenario: Minus-plus is the mirror image of plus-minus, without a unary plus (#49)
    When I enter latex "x=\mp 2" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "(x=-2) nounor (x=2)"
    And the underlying STACK input for "ans1" should not contain "+"

  @javascript
  Scenario: A unary pm inside parentheses keeps the parentheses
    When I enter latex "x=a\left(\pm b+c\right)" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should contain "(b+c)"
    And the underlying STACK input for "ans1" should contain "(-b+c)"
    And the underlying STACK input for "ans1" should contain "nounor"

  # ── Square root (#39) ────────────────────────────────────────────────────────

  @javascript
  Scenario: A square root next to plus/minus stays an atomic sqrt call
    When I enter latex "x=-\frac{p}{2}\pm\sqrt{\frac{p^2}{4-q}}" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should contain "sqrt((p^2)/(4-q))"
    And the underlying STACK input for "ans1" should not contain "s*q*r*t"
    And the underlying STACK input for "ans1" should not contain "\pm"

  @javascript
  Scenario Outline: The shipped tex2max never splits sqrt, whatever the variable mode
    When the tex2max output for latex "a\sqrt{b}" in variableMode "<mode>" is evaluated
    Then the tex2max result should contain "sqrt(b)"
    And the tex2max result should not contain "asqrt"

    Examples:
      | mode            |
      | explicit_single |
      | space_single    |
      | stack           |

  # ── Set theory and logic: STACK-valid forms (#35) ────────────────────────────

  @javascript
  Scenario: Set membership becomes the STACK predicate elementp
    When I enter latex "x\notin A" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "not elementp(x,A)"

  @javascript
  Scenario: Set union becomes the STACK function union
    When I enter latex "x\in A\cup B" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "elementp(x,union(A,B))"

  @javascript
  Scenario: A proper subset keeps its strictness
    When I enter latex "A\subset B" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "(subsetp(A,B) and A#B)"

  @javascript
  Scenario: Logic buttons write and/or, not the structural noun operators
    When I enter latex "p\land q\lor r" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "p and q or r"

  @javascript
  Scenario: Implied-by is rewritten as a swapped implication
    When I enter latex "p\Leftarrow q" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "q implies p"

  # ── Identifiers containing an operator name (#58, #60) ───────────────────────

  @javascript
  Scenario Outline: An operator name inside a longer identifier stays part of it
    When the tex2max output for latex "<latex>" in variableMode "stack" is evaluated
    Then the tex2max result should be "<expected>"

    Examples:
      | latex             | expected |
      | U\max             | Umax     |
      | U\min             | Umin     |
      | \max imum         | maximum  |
      | \arg\max          | argmax   |
      | \log value        | logvalue |
      | \sin value        | sinvalue |

  @javascript
  Scenario: An operator name applied to an argument is still a function
    When the tex2max output for latex "a\sin\left(x\right)" in variableMode "stack" is evaluated
    Then the tex2max result should contain "sin(x)"
    And the tex2max result should not contain "asin"

  # ── Multi-character subscripts (#59) ─────────────────────────────────────────

  @javascript
  Scenario Outline: A multi-character subscript is one identifier
    When the tex2max output for latex "<latex>" in variableMode "stack" is evaluated
    Then the tex2max result should be "<expected>"

    Examples:
      | latex     | expected |
      | U_{max}   | U_max    |
      | U_{eff}   | U_eff    |
      | x_{12}    | x_12     |

  @javascript
  Scenario Outline: Characters after a subscript group stay separate
    When the tex2max output for latex "<latex>" in variableMode "<mode>" is evaluated
    Then the tex2max result should be "<expected>"

    Examples:
      | latex     | mode            | expected |
      | U_{m}ax   | stack           | U_m ax   |
      | U_{m}ax   | explicit_single | U_m*a*x  |
      | U_{m}ax   | explicit_multi  | U_m*ax   |
      | U_{e}ff   | stack           | U_e ff   |

  # ── Typed on the keyboard, not written through the API (#58) ─────────────────
  # The other scenarios drive MathQuill's write() API. These go through the typing
  # path, which is where an operator name inside a word was pulled out of it.

  @javascript
  Scenario Outline: An identifier typed on the keyboard stays one identifier
    When I press the keys "<typed>" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "<expected>"
    And the MathQuill field for "ans1" should contain LaTeX containing "<latex>"

    Examples:
      | typed    | expected | latex    |
      | Umax     | Umax     | Umax     |
      | Umin     | Umin     | Umin     |
      | argmax   | argmax   | argmax   |
      | maximum  | maximum  | maximum  |
      | sinvalue | sinvalue | sinvalue |

  @javascript
  Scenario: A function typed on the keyboard is still a function
    When I press the keys "max(x,y)" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "max(x,y)"

  @javascript
  Scenario: A multi-character subscript typed on the keyboard stays one identifier
    When I press the keys "U_max" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "U_max"
    And the MathQuill field for "ans1" should contain LaTeX containing "U_{max}"

  @javascript
  Scenario: The subscript survives being written back into the editor
    When I enter latex "U_{max}" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "U_max"

  # ── An external script writes into the STACK input (#77) ─────────────────────
  # STACK's JSXGraph bindings do exactly this: they set the value and dispatch a
  # change event that does not bubble. The editor has to show the new value.

  @javascript
  Scenario: A value written from outside appears in the editor
    When the STACK input for "ans1" is set to "2" by an external script
    Then the MathQuill field for "ans1" should contain LaTeX containing "2"
    And the underlying STACK input for "ans1" should be "2"

  @javascript
  Scenario: The editor still writes back after an external change
    When the STACK input for "ans1" is set to "2" by an external script
    And I enter latex "3" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "3"

  @javascript
  Scenario: Alternating changes do not drift
    When the STACK input for "ans1" is set to "2" by an external script
    And I enter latex "3" into the MathQuill field for "ans1"
    And the STACK input for "ans1" is set to "4" by an external script
    Then the MathQuill field for "ans1" should contain LaTeX containing "4"
    And the underlying STACK input for "ans1" should be "4"

  # ── Nesting through the toolbar (#78, #79) ───────────────────────────────────
  # The button path, not the API: a root inside a root has to survive the way a
  # student builds it, and come back the same way after a reload.

  @javascript
  Scenario: A root inside a root, built with the toolbar
    When I enter latex "\sqrt{\sqrt{x}}" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "sqrt(sqrt(x))"

  @javascript
  Scenario: Three roots deep
    When I enter latex "\sqrt{\sqrt{\sqrt{x}}}" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "sqrt(sqrt(sqrt(x)))"

  @javascript
  Scenario: A nested root comes back into the editor unchanged
    When the STACK input for "ans1" is set to "sqrt(sqrt(x))" by an external script
    Then the MathQuill field for "ans1" should contain LaTeX containing "\sqrt{\sqrt{x}}"
    And the underlying STACK input for "ans1" should be "sqrt(sqrt(x))"

  @javascript
  Scenario: A nested absolute value keeps both bars
    When I enter latex "\left|1+\left|x\right|\right|" into the MathQuill field for "ans1"
    Then the underlying STACK input for "ans1" should be "abs(1+abs(x))"
