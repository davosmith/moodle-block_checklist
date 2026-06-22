@block @block_checklist @mod_checklist
Feature: Embed the checklist items in the checklist block
  In order to enable the checklist block in a course
  As a student
  I can directly view the checklist items and check / uncheck them in the checklist block

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email | idnumber |
      | student  | Student | 1 | student1@example.com | S1 |
      | teacher1 | Teacher | 1 | teacher1@example.com | T1 |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "course enrolments" exist:
      | user | course | role |
      | student  | C1 | student |
      | teacher1 | C1 | editingteacher |

  @javascript
  Scenario: Add a checklist activity and enable embedding in the checklist block
    Given I log in as "teacher1"
    And I am on the "Course 1" "course" page logged in as teacher1
    And I turn editing mode on
    And the following "activities" exist:
      | activity  | name           | intro               | course | section |
      | checklist | Test checklist | This is a checklist | C1     | 1       |
    And the following items exist in checklist "Test checklist":
      | text            | required | duetime       |
      | The first item  | required | 21 April 2018 |
      | The second item | optional |               |
    And I add the "Checklist" block if not present
    And I configure the "Checklist" block
    And I set the following fields to these values:
      | Checklist overview           | No             |
      | Choose checklist             | Test checklist |
      | Embed checklist for students | Checked        |
    And I press "Save changes"
    And I log out
    When I log in as "student"
    And I am on the "Course 1" "course" page logged in as student
    And I should see "Required items" in the "Test checklist" "block"
    And I click on "The first item" "checkbox"
    Then I should see "100%" in the "#checklistprogressrequired" "css_element"
