<?php

namespace Drupal\DrupalExtension\Context;

use Behat\Behat\Context\TranslatableContext;
use Behat\Gherkin\Node\TableNode;

/**
 * Provides pre-built step definitions for interacting with Drupal.
 */
final class DrupalContext extends RawDrupalContext implements TranslatableContext {

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
   * @Given I am the named user :alias;
   */
  public function iAmTheNamedUser($alias) {

    $user = $this->resolveAlias($alias);
    $this->login($user);
  }
  /**
   * @Given I am the user:
   */
  public function iAmTheUser(TableNode $table) {

    $options = self::convertTableNodeToArray($table);
    if (is_string($options['roles'])) {
      $options['roles'] = array_map("trim", explode(",", $options['roles']));
    }
    $user    = $this->_createUser($options);
    $this->login($user);
  }

  /**
   * Creates and authenticates a user with the given role(s).
   *
   * @Given I am logged in as a user with the :role role(s)
   * @Given I am a user with the :role role(s)
   * @Given I am logged in as a/an :role
   */
  public function assertAuthenticatedByRole($roles) {
    if ($this->loggedInWithRoles($roles)) {
      return TRUE;
    }
    $user = $this->_createUser(array('roles' => $roles));
    $this->login($user);
  }

  /**
   * Creates and authenticates a user with the given role(s) and given fields.
   * | field_user_name     | John  |
   * | field_user_surname  | Smith |
   * | ...                 | ...   |.
   *
   * @Given I am logged in as a user with the :roles role(s) and I have the following fields:
   */
  public function assertAuthenticatedByRoleWithGivenFields($roles, TableNode $fields) {
    // Check if a user with this role is already logged in.
    if (!$this->loggedInWithRoles($roles)) {
      foreach ($fields->getRowsHash() as $field => $value) {
        $values[$field] = $value;
      }
      $user = $this->_createUser($values);

      // Login.
      $this->login($user);
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
    $rid         = $this->roleCreate($permissions);
    $this->getDriver()->userAddRole($user, $rid);

    // Login.
    $this->login($user);
  }

  /**
   * Find text in a table row containing given text.
   *
   * @Then I should see (the text ):text in the :rowText row
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
      'type'  => $type,
      'uid'   => $this->getLoggedInUser()->uid,
    );
    $saved = $this->_createNode($values);

    // Set internal page on the new node.
    $this->getSession()->visit($this->locatePath('/node/' . $saved->nid));
  }

  /**
   * Creates content of a given type provided in the form:
   * | title    | author     | status | created           |
   * | My title | Joe Editor | 1      | 2014-10-17 8:00am |
   * | ...      | ...        | ...    | ...               |.
   *
   * @Given :type content:
   */
  public function createNodes($type, TableNode $nodesTable) {
    foreach ($nodesTable->getHash() as $nodeHash) {
      $nodeHash['type'] = $type;
      $this->_createNode($nodeHash);
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

    $node = $this->_createNode($values);

    // Set internal browser on the node.
    $this->getSession()->visit($this->locatePath('/node/' . $node->nid));
  }

  /**
   * Creates user of the given type, provided in the form:
   * | name      | Example user     |
   * | mail      | user@example.com |
   * | status    | 1                |
   * | ...       | ...              |.
   * Assigns the as as the 'working' user.
   *
   * @When working with the user:
   */
  public function whenWorkingWithTheUser(TableNode $fields) {

    $values = array();
    foreach ($fields->getRowsHash() as $field => $value) {
      $values[$field] = $value;
    }
    $saved = $this->_createUser($values);
    return $saved;
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
      'name'                    => $name,
      'vocabulary_machine_name' => $vocabulary,
      'description'             => $this->getDriver()->getRandom()->name(255),
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

      $user = $this->_createUser($userHash);

      // Assign roles.
      foreach ($roles as $role) {
        $this->getDriver()->userAddRole($user, $role);
      }
    }
  }
  /**
   * Creates one or more terms on an existing vocabulary.
   *
   * Provide term data in the following format:
   *
   * | name  | parent | description | weight | taxonomy_field_image |
   * | Snook | Fish   | Marine fish | 10     | snook-123.jpg        |
   * | ...   | ...    | ...         | ...    | ...                  |
   *
   * Only the 'name' field is required.
   *
   * @Given :vocabulary terms:
   */
  public function createTerms($vocabulary, TableNode $termsTable) {
    foreach ($termsTable->getHash() as $termsHash) {
      $term                          = (object) $termsHash;
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
  /**
   * Retrieves the aliased object, and prints it to the console.
   *
   * @param string $alias
   *   The named alias of an already-created object
   * @param string $field
   *   (optional) The additional name of a field whose
   *                        array/object value should be expanded (such types
   *                        are collapsed by default for brevity.  A value of
   *                        'all' in this field will expend the whole object).
   *
   * @Given I debug the object named :alias
   * @Given I debug the object named :alias and expand the value of :field
   */
  public function whenIDebugTheObjectNamed($alias, $field = NULL) {

    $object = $this->resolveAlias($alias);
    if (empty($object)) {
      throw new \Exception(sprintf("%s::%s: No value was found for the alias %s", get_class($this), __FUNCTION__, $alias));
    }

    if (!is_array($object) && !is_object($object)) {
      print $object;
      return;
    }
    $expand_field = ($field) ?: '';
    $expand_all   = (!empty($expand_field) && strtolower($expand_field) === 'all');
    $output       = "\n<$alias>\n";

    foreach ($object as $k => $v) {
      if (is_object($v) || is_array($v)) {
        if ($expand_all || (!empty($expand_field) && $k === $expand_field)) {
          $obj = print_r($v, TRUE);
          $obj = implode("\n", array_map(function ($value) {
            return "\t$value";
          }, explode("\n", $obj)));
          $output .= "\t$k: $obj\n";
          continue;
        }
        else {
          $output .= "\t$k: [Obj/Arr]\n";
          continue;
        }
      }
      $output .= "\t$k: \"$v\",\n";
    }
    $output .= "</$alias>\n";
    print $output;
  }

}
