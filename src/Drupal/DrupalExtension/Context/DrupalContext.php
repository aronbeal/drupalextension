<?php

namespace Drupal\DrupalExtension\Context;

use Behat\Behat\Context\TranslatableContext;
use Behat\Gherkin\Node\TableNode;

// Left in for reference.
// use Behat\Behat\Tester\Exception\PendingException;.
/**
 * Provides pre-built step definitions for interacting with Drupal.
 */
final class DrupalContext extends RawDrupalContext implements TranslatableContext {

  /**
   * Definition for steps:.
   *
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
   * Creates a user.
   *
   * @param TableNode $table
   *   The field information for the new user. Data provided in the form:
   *   | name      | Example user     |
   *   | mail      | user@example.com |
   *   | status    | 1                |
   *   | @         | test_user        |
   *   The field name @ is a special value that assigns the field value
   *   as an alias for the object, so it can be retrieved with later tests.
   *   If no name is explicitly specified, this alias is also set to be the
   *   name of the created user.
   *   A field value starting with @:, followed by an alias and a field name
   *    (e.g. @:test_user/uid) will be translated at runtime to the actual
   *    value of the aliased item.  In this manner, field values of earler
   *    objects not known until after creation can be populated into the fields
   *    of subsequent objects.
   *
   * @return object $user
   *   The created drupal user.
   *
   * @Given the user:
   */
  public function theUser(TableNode $table) {
    $options = self::convertTableNodeToArray($table);
    return $this->createDefaultUser($options);
  }

  /**
   * Convenience step to demonstrate how aliasing works.
   *
   * The @ symbol above is special syntax that defines an 'alias' for a given
   * creation. You can retrieve aliased entries during subsequent steps for
   * modification or deletion. See `resolveAlias` in RawDrupalContext for more
   * information.
   *
   * @param string $alias
   *   :alias: The alias you wish to assign to the new user.
   * @param TableNode $table
   *   :table: The field information for the new user.
   *   Data provided in the form:
   *   | name      | Example user     |
   *   | mail      | user@example.com |
   *   | status    | 1                |
   *   This method accepts aliases and aliased values. See theUser for more
   *   information.
   *
   * @return object $user
   *   The created drupal user.
   *
   * @Given the user with alias :alias:
   */
  public function theAliasedUser($alias, TableNode $table) {
    $options = self::convertTableNodeToArray($table);
    $options['@'] = $alias;
    return $this->createDefaultUser($options);
  }

  /**
   * Retrives a previously created (and aliased) user from the database.
   *
   * Aliases are established using the @ symbol in the table data.  See
   * 'Given the user' step for more information.
   *
   * @param string $alias
   *   :alias: The named alias assigned to the user when they were created.
   *
   * @Given I am the named user :alias
   */
  public function iAmTheNamedUser($alias) {
    $user = $this->resolveAlias($alias);
    $this->login($user);
  }

  /**
   * Creates a new user and logs them in.
   *
   * @param TableNode $table
   *   A table of data that defines the new user. Data provided in the form:
   *   | name      | Example user     |
   *   | mail      | user@example.com |
   *   | status    | 1                |
   *   | ...       | ...              |
   *   This method accepts aliases and aliased values. See theUser for more
   *   information.
   *
   * @Given I am the user:
   */
  public function iAmTheUser(TableNode $table) {
    $user = $this->theUser($table);
    $this->login($user);
  }

  /**
   * Creates and authenticates a user with the given role(s).
   *
   * @param string $roles
   *   A comma-separated list of roles for the new user.
   *
   * @Given I am logged in as a user with the :role role(s)
   * @Given I am a user with the :role role(s)
   * @Given I am logged in as a/an :role
   */
  public function assertAuthenticatedByRole($roles) {
    if ($this->loggedInWithRoles($roles)) {
      return TRUE;
    }
    $user = $this->createDefaultUser(array('roles' => $roles));
    $this->login($user);
  }

  /**
   * Finds the user with the provided name, and logs them in.
   *
   * Note: "Name", in this context, refers to the actual user's name, not
   * just a referential alias (as would be created by the @ symbol).
   *
   * @param string $name
   *   The name of the user to be retrieved.
   *
   * @throws \Exception
   *   If the user with the provided name does not exist in the db (or if
   *   more than one user with the provided name exists in the db - ambiguity
   *   is not currently tolerated).
   *
   * @Given I am logged in as :name
   */
  public function assertLoggedInByName($name) {
    try {
      $user = $this->getNamedUser($name);
      $this->login($user);
    }
    catch (\Exception $e) {
      throw new \Exception(sprintf("%s::%s line %s: %s", get_class($this), __FUNCTION__, __LINE__, $e->getMessage()));
    }
  }

  /**
   * Creates and authenticates a user with the given role(s) and given fields.
   *
   * @param string $role
   *   A comma-separated list of roles for the new user.
   * @param TableNode $fields
   *   A table of data that defines the new user.
   *   Data provided in the form:
   *   | field_user_name     | John  |
   *   | field_user_surname  | Smith |
   *   | ...                 | ...   |
   *   This method accepts aliases and aliased values. See theUser for more
   *   information.
   *
   * @Given I am logged in as a user with the :role role(s) and I have the
   * following fields:
   */
  public function assertAuthenticatedByRoleWithGivenFields($role, TableNode $fields) {
    // Check if a user with this role is already logged in.
    if (!$this->loggedInWithRoles($role)) {
      $values = array(
        'roles' => $role,
      );
      foreach ($fields->getRowsHash() as $field => $value) {
        $values[$field] = $value;
      }
      $user = $this->createDefaultUser($values);

      // Login.
      $this->login($user);
    }
  }

  /**
   * Creates and logs in a user with the given permission set.
   *
   * @param string $permissions
   *   A comma-separated list of permissions for the newly defined user.
   *
   * @Given I am logged in as a user with the :permissions permission(s)
   */
  public function assertLoggedInWithPermissions($permissions) {
    // Create user.
    $user = $this->createDefaultUser();

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
   * @param string $text
   *   The visible text we are searching for.
   * @param string $rowText
   *   Some text within a table row that also contains the link.
   *
   * @Then I should see (the text ):text in the :rowText row
   */
  public function assertTextInTableRow($text, $rowText) {
    $row = $this->getTableRow($this->getSession()->getPage(), $rowText);
    if (empty($row)) {
      throw new \Exception(sprintf("Couldn't find the row with text \"%s\".", $rowText));
    }
    if (strpos($row->getText(), $text) === FALSE) {
      throw new \Exception(sprintf('Found a row containing "%s", but it did not contain the text "%s".', $rowText, $text));
    }
  }

  /**
   * Attempts to find a link in a table row containing giving text.
   *
   * This is for administrative pages such as the administer content types
   * screen found at `admin/structure/types`.
   *
   * @param string $link
   *   The visible text for a given link tag.
   * @param string $rowText
   *   Some text within a table row that also contains the link.
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
   * Cleares the driver cache.
   *
   * @Given the cache has been cleared
   */
  public function assertCacheClear() {
    $this->getDriver()->clearCache();
  }

  /**
   * Runs Cron.
   *
   * @Given I run cron
   */
  public function assertCron() {
    $this->getDriver()->runCron();
  }

  /**
   * Creates content of the given type.
   *
   * @param string $type
   *   The node (bundle) type to create.
   * @param string $title
   *   The title of the newly created node.
   *
   * @Given I am viewing a/an :type (content )with the title :title
   * @Given a/an :type (content )with the title :title
   */
  public function createNode($type, $title) {
    // @todo make this easily extensible.
    $values = array(
      'type' => $type,
      'title' => $title,
    );
    $saved = $this->createDefaultNode($values);
    // Set internal page on the new node.
    $this->getSession()->visit($this->locatePath('/node/' . $saved->nid));
  }

  /**
   * Creates content authored by the current user.
   *
   * @param string $type
   *   The node (bundle) type to create.
   * @param string $title
   *   The title of the newly created node.
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
    $saved = $this->createDefaultNode($values);

    // Set internal page on the new node.
    $this->getSession()->visit($this->locatePath('/node/' . $saved->nid));
  }

  /**
   * Creates content of a given type.
   *
   * @param string $type
   *   The node (bundle) type to view.
   * @param TableNode $nodesTable
   *   The field data defining the node we are to view, in a row-centric
   *   format.
   *   Data provided in the form:
   *   | title    | author     | status | created           |
   *   | My title | Joe Editor | 1      | 2014-10-17 8:00am |
   *   | ...      | ...        | ...    | ...               |
   *   This method accepts aliases and aliased values. See theUser for more
   *   information. Note that in this case (row-centric data), aliases would be
   *   assigned in a header row, e.g.:
   *   | title      | status | @           | my_entity_reference_field |
   *   | My title   | 1      | test_node   |                           |
   *   | My title 2 | 1      | test_node2  | @:test_node/nid           |
   *   .
   *
   * @Given :type content:
   */
  public function createNodes($type, TableNode $nodesTable) {
    foreach ($nodesTable->getHash() as $nodeHash) {
      $nodeHash['type'] = $type;
      $this->createDefaultNode($nodeHash);
    }
  }

  /**
   * Creates content of the given type.
   *
   * @param string $type
   *   The node (bundle) type to view.
   * @param TableNode $fields
   *   The field data defining the node we are to view.
   *   Data provided in the form:
   *   | title     | My node        |
   *   | Field One | My field value |
   *   | author    | Joe Editor     |
   *   | status    | 1              |
   *   | ...       | ...            |
   *   .
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
    $node = $this->createDefaultNode($values);

    // Set internal browser on the node.
    $this->getSession()->visit($this->locatePath('/node/' . $node->nid));
  }

  /**
   * Asserts that a given content type is editable.
   *
   * @param string $type
   *   The node type the currently logged in user is expected
   *   to be able to edit.
   *
   * @Then I should be able to edit a/an :type( content)
   */
  public function assertEditNodeOfType($type) {
    if (!$this->loggedIn()) {
      throw new \Exception(sprintf("%s::%s line %s: Cannot test node edit assertions without a preceding login step.", get_class($this), __FUNCTION__, __LINE__));
    }
    $saved = $this->createDefaultNode(array('type' => $type));

    // Set internal browser on the node edit page.
    $this->getSession()->visit($this->locatePath('/node/' . $saved->nid . '/edit'));

    // Test status.
    $this->assertSession()->statusCodeEquals('200');
  }

  /**
   * Creates a term on an existing vocabulary.
   *
   * @param string $vocabulary
   *   The machine name of the taxonomy to create
   *                            the term for.
   * @param string $name
   *   The term to create.
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
   * @param TableNode $usersTable
   *   The table listing users by row.
   *
   * @Given users:
   *
   * Provide user data in the following format:
   *
   * | name     | mail         | roles        |
   * | user foo | foo@bar.com  | role1, role2 |
   */
  public function createUsers(TableNode $usersTable) {
    foreach ($usersTable->getHash() as $userHash) {
      $this->createDefaultUser($userHash);
    }
  }

  /**
   * Creates one or more terms on an existing vocabulary.
   *
   * @param string $vocabulary
   *   The machine name of the vocabulary to add
   *   the terms to.
   * @param TableNode $termsTable
   *   The table listing terms by row.
   *
   * @Given :vocabulary terms:
   *
   * Provide term data in the following format:
   *
   * | name  | parent | description | weight | taxonomy_field_image |
   * | Snook | Fish   | Marine fish | 10     | snook-123.jpg        |
   * | ...   | ...    | ...         | ...    | ...                  |
   *
   * Required fields: 'name'.
   */
  public function createTerms($vocabulary, TableNode $termsTable) {
    foreach ($termsTable->getHash() as $termsHash) {
      $termsHash = $termsHash + array(
        'name'                    => $this->getDriver()->getRandom()->name(25),
        'vocabulary_machine_name' => $vocabulary,
        'description'             => $this->getDriver()->getRandom()->name(255),
      );
      $term                          = (object) $termsHash;
      if (!isset($term->name)) {
        throw new \Exception(sprintf("%s::%s line %s: Table data contained no value for 'name'", get_class($this), __FUNCTION__, __LINE__));
      }
      $this->termCreate($term);
    }
  }

  /**
   * Creates one or more languages.
   *
   * @param TableNode $langcodesTable
   *   The table listing languages by their ISO code.
   *
   * @Given the/these (following )languages are available:
   *
   * Provide language data in the following format:
   *
   * | langcode |
   * | en       |
   * | fr       |
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
   * Retrieves the named object, and assigns new values to it.
   *
   * @Given I set the values of :alias to:
   */
  public function iSetTheValuesTo($alias, TableNode $table) {
    $o = $this->resolveAlias($alias);
    $values = self::convertTableNodeToArray($table);
    switch (self::$aliases->getCache($alias)) {
      case 'nodes':
        $this->nodeAlter($o, $values);
        break;

      case 'users':
        $this->userAlter($o, $values);
        break;

      default:
        throw new \Exception(sprintf(':%s::%s: Alteration of %s types not yet supported: %s', get_class($this), __FUNCTION__, $type));
    }
  }

  /**
   * Retrieves the named object, and assigns new values to it.
   *
   * @Given I delete/unset the alias value :aliasfield
   */
  public function iDeleteTheAliasField($aliasfield) {
    return $this->deleteAliasValue($aliasfield);
  }

  /**
   * Pauses the scenario.
   *
   * Function will pause the scenario until the user presses a key. Useful when
   * debugging a scenario.  Also includes the 'q' command to exit further
   * scenario execution, instead of continuing.
   *
   * Note: this method signature is altered from earlier versions, to conform
   * with Drupal coding standards.
   *
   * @Then (I )break
   */
  public function iPutAbreakpoint() {
    $this->breakpoint();
  }

  /**
   * Prints the aliased value to the console.
   *
   * Retrieves the provided aliased object, and prints the value of the
   * indicated field.
   *
   * @param string $aliasfield
   *   An alias/field combination to display.  Entry
   *   must be of the form alias_name + '/' + field_name, e.g.:
   *   'test_user/uid'.
   *
   * @Given I debug the alias value :alias
   */
  public function debugAliasValue($aliasfield) {
    // TODO: revisit this regex to ensure this can match any alias/field name
    // combination.
    $field_value = $this->resolveAliasValue($aliasfield);
    $str_field_value = (is_scalar($field_value)) ? $field_value : print_r($field_value, TRUE);
    $str_field_value = implode("\n\t", explode("\n", $str_field_value));
    print sprintf("%s: %s\n", $aliasfield, $str_field_value);

  }

  /**
   * Retrieves the currently logged in user.
   *
   * Note that this relies on the stored cached value of the current user
   * being correct, rather than programmatically trying to determine the current
   * user via the drupal db.  This means that if login is done via interface
   * mainpulation (Mink API), this method could fail.
   *
   * @Given /I debug the current user and expand the field "(?P<fields>(?:[^"]|\\")*)"/
   */
  public function iDebugTheCurrentUser($fields = NULL) {
    $user = $this->getLoggedInUser();
    $options = array('label' => 'Current User');
    if (!is_null($fields)) {
      $options['expand fields'] = array_map("trim", explode(',', $fields));
    }
    fwrite(STDOUT, $this->stringifyObject($user, $options));
  }

  /**
   * Retrieves the aliased object, and prints it to the console.
   *
   * For debugging purposes. Optionally expands the value of the fields in the
   * $fields argument. This is for viewing the entire object - to focus on a
   * single field value, the debugAliasValue method might be preferable.
   *
   * @param string $alias
   *   The named alias of an already-created object.
   * @param string $fields
   *   (optional) The additional names of one or more
   *   fields (separated by comma) whose array/object value should be expanded
   *   (such types are collapsed by default for brevity. A value of 'all' in
   *   this field will expend the whole object).
   *
   * @Given /I debug the (?:\w+) (?:named )?"([^"]+)"$/
   * @Given /I debug the (?:\w+) (?:named )?"([^"]+)" and expand the values? of "([^"]+)"/
   */
  public function whenIdebugTheObjectNamed($alias, $fields = NULL) {
    $object = $this->resolveAlias($alias);
    if (empty($object)) {
      throw new \Exception(sprintf("%s::%s: No value was found for the alias %s", get_class($this), __FUNCTION__, $alias));
    }
    $expand_fields = ($fields === NULL) ? array() : array_map('trim', explode(',', $fields));
    fwrite(STDOUT, $this->stringifyObject($object, array('label' => $alias, 'expand fields' => $expand_fields)));
  }

  /**
   * Provides a high level overview of cache state for debugging purposes.
   *
   * @param string $cache_name
   *   The name of the cache to inspect, or 'all', to display the contents of
   *   all the caches.
   *
   * @Given I show/inspect the :cache_name cache
   * @Given I show/inspect the cache :cache_name
   * @Given I show/inspect the cache
   */
  public function iShowTheCache($cache_name = 'all') {
    return $this->displayCaches($cache_name);
  }

}
