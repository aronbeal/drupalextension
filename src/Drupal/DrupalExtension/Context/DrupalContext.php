<?php

/**
 * @file
 */

namespace Drupal\DrupalExtension\Context;

use Behat\Behat\Context\TranslatableContext;
use Behat\Mink\Element\Element;

use Behat\Gherkin\Node\TableNode;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
/**
 * Provides pre-built step definitions for interacting with Drupal.
 */
final class DrupalContext extends RawDrupalContext implements TranslatableContext {

  /**
   * Utility function for the common job (in this context) of creating
   * a user.
   *
   * @param array $valuesAn
   *   array of key/value pairs that describe
   *   An array of key/value pairs that describe
   *                       the values to be assigned to this user.
   *
   * @return $user         The newly created user.
   */
  protected function _createUser($values = array()) {
    // Assign defaults where possible.
    $values = $values + array(
        'name' => $this->getRandom()->name(8),
        'pass' => $this->getRandom()->name(16)
      );
    $values['mail'] = "$values[name]@example.com";
    $values = (object) $values;
    return $this->userCreate($values);
  }
  /**
   *
   */
  protected function _createNode($values = array()) {
    // Assign defaults where possible.
    $values = $values + array(
      'body' => $this->getRandom()->string(255)
    );
    $values = (object) $values;
    return $this->nodeCreate($values);
  }
  /**
   * Returns list of definition translation resources paths.
   *
   * @return array
   */
  public static function getTranslationResources() {
    return glob(__DIR__ . '/../../../../i18n/*.xliff');
  }

  /**
   * @Given I am an anonymous user
   * @Given I am not logged in
   */
  public function assertAnonymousUser() {
    // Verify the user is logged out.
    if ($this->loggedIn()) {
      $this->logout();
    }
  }

  /**
   * Creates and authenticates a user with the given role(s).
   *
   * @Given I am logged in as a user with the :role role(s)
   * @Given I am logged in as a/an :role
   */
  public function assertAuthenticatedByRole($role) {
    // Check if a user with this role is already logged in.
    if (!$this->loggedInWithRole($role)) {
      // Create user (and project).

      $user = $this->_createUser(array('role' => $role));

      $roles = explode(',', $role);
      $roles = array_map('trim', $roles);
      foreach ($roles as $role) {
        if (!in_array(strtolower($role), array('authenticated', 'authenticated user'))) {
          // Only add roles other than 'authenticated user'.
          $this->getDriver()->userAddRole($user, $role);
        }
      }

      // Login.
      $this->login();
    }
  }

  /**
   * Creates and authenticates a user with the given role(s) and given fields.
   * | field_user_name     | John  |
   * | field_user_surname  | Smith |
   * | ...                 | ...   |.
   *
   * @Given I am logged in as a user with the :role role(s) and I have the following fields:
   */
  public function assertAuthenticatedByRoleWithGivenFields($role, TableNode $fields) {
    // Check if a user with this role is already logged in.
    if (!$this->loggedInWithRole($role)) {
      // Create user (and project).
      $values = array(
        'role' => $role
      );
      foreach ($fields->getRowsHash() as $field => $value) {
        $values[$field] = $value;
      }
      $user = $this->_createUser($values);

      $roles = explode(',', $role);
      $roles = array_map('trim', $roles);
      foreach ($roles as $role) {
        if (!in_array(strtolower($role), array('authenticated', 'authenticated user'))) {
          // Only add roles other than 'authenticated user'.
          $this->getDriver()->userAddRole($user, $role);
        }
      }

      // Login.
      $this->login();
    }
  }

  /**
   * @Given I am logged in as a user with the :permissions permission(s)
   */
  public function assertLoggedInWithPermissions($permissions) {
    // Create user.
    $user = $this->_createUser();

    // Create and assign a temporary role with given permissions.
    $permissions = explode(',', $permissions);
    $rid = $this->roleCreate($permissions);
    $this->getDriver()->userAddRole($user, $rid);

    // Login.
    $this->login();
  }

  /**
   * Retrieve a table row containing specified text from a given element.
   *
   * @param \Behat\Mink\Element\Element
   * @param string
   *   The text to search for in the table row.
   *
   * @return \Behat\Mink\Element\NodeElement
   *
   * @throws \Exception
   */
  public function getTableRow(Element $element, $search) {
    $rows = $element->findAll('css', 'tr');
    if (empty($rows)) {
      throw new \Exception(sprintf('No rows found on the page %s', $this->getSession()->getCurrentUrl()));
    }
    foreach ($rows as $row) {
      if (strpos($row->getText(), $search) !== FALSE) {
        return $row;
      }
    }
    throw new \Exception(sprintf('Failed to find a row containing "%s" on the page %s', $search, $this->getSession()->getCurrentUrl()));
  }

  /**
   * Find text in a table row containing given text.
   *
   * @Then I should see (the text ):text in the ":rowText" row
   */
  public function assertTextInTableRow($text, $rowText) {
    $row = $this->getTableRow($this->getSession()->getPage(), $rowText);
    if (strpos($row->getText(), $text) === FALSE) {
      throw new \Exception(sprintf('Found a row containing "%s", but it did not contain the text "%s".', $rowText, $text));
    }
  }

  /**
   * Attempts to find a link in a table row containing giving text. This is for
   * administrative pages such as the administer content types screen found at
   * `admin/structure/types`.
   *
   * @Given I click :link in the :rowText row
   *
   * @Then I (should )see the :link in the :rowText row
   */
  public function assertClickInTableRow($link, $rowText) {
    $page = $this->getSession()->getPage();
    if ($link_element = $this->getTableRow($page, $rowText)->findLink($link)) {
      // Click the link and return.
      $link_element->click();
      return;
    }
    throw new \Exception(sprintf('Found a row containing "%s", but no "%s" link on the page %s', $rowText, $link, $this->getSession()->getCurrentUrl()));
  }

  /**
   * @Given the cache has been cleared
   */
  public function assertCacheClear() {
    $this->getDriver()->clearCache();
  }

  /**
   * @Given I run cron
   */
  public function assertCron() {
    $this->getDriver()->runCron();
  }

  /**
   * Creates content of the given type.
   *
   * @Given I am viewing a/an :type (content )with the title :title
   * @Given a/an :type (content )with the title :title
   */
  public function createNode($type, $title) {
    // @todo make this easily extensible.
    $values = array(
      'title' => $title,
      'type' => $type
    );
    $saved = $this->_createNode($values);
    // Set internal page on the new node.
    $this->getSession()->visit($this->locatePath('/node/' . $saved->nid));
  }

  /**
   * Creates content authored by the current user.
   *
   * @Given I am viewing my :type (content )with the title :title
   */
  public function createMyNode($type, $title) {
    if (!$this->loggedIn()) {
      throw new \Exception(sprintf('There is no current logged in user to create a node for.'));
    }

    $values = array(
      'title' => $title,
      'type' => $type,
      'uid' => $this->getCurrentUser()->uid,
    );
    $saved = $this->_createNode($node);

    // Set internal page on the new node.
    $this->getSession()->visit($this->locatePath('/node/' . $saved->nid));
  }

  /**
   * Creates content of a given type provided in the form:
   * | title    | author     | status | created           |
   * | My title | Joe Editor | 1      | 2014-10-17 8:00am |
   * | ...      | ...        | ...    | ...               |.
   *
   * C   * @Given :type content:
   */
  public function createNodes($type, TableNode $nodesTable) {
    foreach ($nodesTable->getHash() as $nodeHash) {
      $nodehash['type'] = $type;
      $this->_createNode($node);
    }
  }

  /**
   * Creates content of the given type, provided in the form:
   * | title     | My node        |
   * | Field One | My field value |
   * | author    | Joe Editor     |
   * | status    | 1              |
   * | ...       | ...            |.
   *
   * @Given I am viewing a/an :type( content):
   */
  public function assertViewingNode($type, TableNode $fields) {
    $values = array(
      'type' => $type,
    );
    foreach ($fields->getRowsHash() as $field => $value) {
      $values[$field] = $value;
    }

    $saved = $this->_createNode($node);

    // Set internal browser on the node.
    $this->getSession()->visit($this->locatePath('/node/' . $saved->nid));
  }

  /**
   * Asserts that a given content type is editable.
   *
   * @Then I should be able to edit a/an :type( content)
   */
  public function assertEditNodeOfType($type) {
    $saved = $this->_createNode(array('type' => $type));

    // Set internal browser on the node edit page.
    $this->getSession()->visit($this->locatePath('/node/' . $saved->nid . '/edit'));

    // Test status.
    $this->assertSession()->statusCodeEquals('200');
  }


  /**
   * Creates a term on an existing vocabulary.
   *
   * @Given I am viewing a/an :vocabulary term with the name :name
   * @Given a/an :vocabulary term with the name :name
   */
  public function createTerm($vocabulary, $name) {
    // @todo make this easily extensible.
    $term = (object) array(
      'name' => $name,
      'vocabulary_machine_name' => $vocabulary,
      'description' => $this->getRandom()->string(255),
    );
    $saved = $this->termCreate($term);

    // Set internal page on the term.
    $this->getSession()->visit($this->locatePath('/taxonomy/term/' . $saved->tid));
  }

  /**
   * Creates multiple users.
   *
   * Provide user data in the following format:
   *
   * | name     | mail         | roles        |
   * | user foo | foo@bar.com  | role1, role2 |
   *
   * @Given users:
   */
  public function createUsers(TableNode $usersTable) {
    foreach ($usersTable->getHash() as $userHash) {

      // Split out roles to process after user is created.
      $roles = array();
      if (isset($userHash['roles'])) {
        $roles = explode(',', $userHash['roles']);
        $roles = array_filter(array_map('trim', $roles));
        unset($userHash['roles']);
      }

      $user = $this->_createUser($userhash);

      // Assign roles.
      foreach ($roles as $role) {
        $this->getDriver()->userAddRole($user, $role);
      }
    }
  }

  /**
   * Creates one or more terms on an existing vocabulary.
   *
   * @Given :vocabulary terms:
   */
  public function createTerms($vocabulary, TableNode $termsTable) {
    foreach ($termsTable->getHash() as $termsHash) {
      $term = (object) $termsHash;
      $term->vocabulary_machine_name = $vocabulary;
      $this->termCreate($term);
    }
  }

  /**
   * Creates one or more languages.
   *
   * @Given the/these (following )languages are available:
   *
   * Provide language data in the following format:
   *
   * | langcode |
   * | en       |
   * | fr       |
   *
   * @param TableNode $langcodesTable
   *   The table listing languages by their ISO code.
   */
  public function createLanguages(TableNode $langcodesTable) {
    foreach ($langcodesTable->getHash() as $row) {
      $language = (object) array(
        'langcode' => $row['languages'],
      );
      $this->languageCreate($language);
    }
  }

  /**
   * Pauses the scenario until the user presses a key. Useful when debugging a scenario.
   *
   * @Then (I )break
   */
  public function iPutABreakpoint() {

    fwrite(STDOUT, "\033[s \033[93m[Breakpoint] Press \033[1;93m[RETURN]\033[0;93m to continue...\033[0m");
    while (fgets(STDIN, 1024) == '') {
    }
    fwrite(STDOUT, "\033[u");
    return;
  }

}
