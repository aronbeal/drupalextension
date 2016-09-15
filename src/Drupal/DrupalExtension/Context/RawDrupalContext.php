<?php

namespace Drupal\DrupalExtension\Context;

use Behat\Behat\Hook\Scope\AfterFeatureScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeFeatureScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Element\Element;
use Behat\Mink\Exception\DriverException;
use Behat\MinkExtension\Context\RawMinkContext;
use Behat\Testwork\Hook\HookDispatcher;
use Drupal\Driver\DrupalDriver;
use Drupal\DrupalDriverManager;
use Drupal\DrupalExtension\Context\Cache\AliasCache;
use Drupal\DrupalExtension\Context\Cache\ContextCache;
use Drupal\DrupalExtension\Context\Cache\LanguageCache;
use Drupal\DrupalExtension\Context\Cache\NodeCache;
use Drupal\DrupalExtension\Context\Cache\RoleCache;
use Drupal\DrupalExtension\Context\Cache\TermCache;
use Drupal\DrupalExtension\Context\Cache\UserCache;
use Drupal\DrupalExtension\Hook\Scope\BeforeNodeCreateScope;

/**
 * Provides the raw functionality for interacting with Drupal.
 */
class RawDrupalContext extends RawMinkContext implements DrupalAwareInterface {

  /**
   * Drupal driver manager.
   *
   * @var \Drupal\DrupalDriverManager
   */
  private $drupal;

  /**
   * Test parameters.
   *
   * @var array
   */
  private $drupalParameters;

  /**
   * Event dispatcher object.
   *
   * @var \Behat\Testwork\Hook\HookDispatcher
   */
  protected $dispatcher;

  /**
   * Current authenticated user.
   *
   * A value of FALSE denotes an anonymous user.
   *
   * @var \stdClass|bool
   */
  public $user = FALSE;
  /**
   * Stores named aliases to cache objects.
   *
   * A cache object that can store a globally unique alias to any object in
   * any of the other caches. This cache stores the primary index of the
   * object in the other cache (and the cache name) rather than the object
   * itself.
   *
   * @var Drupal\DrupalExtension\Context\Cache\CacheInterface
   */
  protected static $aliases = NULL;
  /**
   * Stores ids of created nodes to be cleaned up later.
   *
   * Keep track of nodes so they can be cleaned up, or retrived for further
   * modification. Note that this has been converted to a static variable,
   * reflecting the fact that nodes can be created by multiple contexts.
   *
   * @var Drupal\DrupalExtension\Context\Cache\CacheInterface
   */
  protected static $nodes = NULL;

  /**
   * Keep track of all users that are created so they can easily be removed.
   *
   * @var Drupal\DrupalExtension\Context\Cache\CacheInterface
   */
  protected static $users = NULL;

  /**
   * Keep track of all terms that are created so they can easily be removed.
   *
   * @var Drupal\DrupalExtension\Context\Cache\CacheInterface
   */
  protected static $terms = NULL;

  /**
   * Keep track of any roles that are created so they can easily be removed.
   *
   * @var Drupal\DrupalExtension\Context\Cache\CacheInterface
   */
  protected static $roles = NULL;

  /**
   * For tracking contexts created by the scenario.
   *
   * Keep track of any other contexts run during this scenario.  If they do
   * not require shared state, I can use them.  This *may* need to be in
   * static context, so as to be available to the AfterFeature hook.
   *
   * @var Drupal\DrupalExtension\Context\Cache\CacheInterface
   */
  protected static $contexts = NULL;

  /**
   * Keep track of any languages that are created so they can easily be removed.
   *
   * @var Drupal\DrupalExtension\Context\Cache\CacheInterface
   */
  protected static $languages = NULL;


  /**
   * Tracks whether cache objects have been initialized.
   *
   * The caches are static instances that need to be created only once
   * during feature buildup, and cleared after scenario execution.  Note
   * that clearing also entails removing the corresponding objects from
   * the drupal test instance.
   *
   * @var boolean
   */
  protected static $cachesInitialized = FALSE;

  /**
   * Flag tracking static scenario initialization.
   *
   * Objects like caches are initialized on every feature restart, but are
   * cleared at the end of scenarios.  This flag ensures that clearing is
   * only attempted once.
   *
   * @var boolean
   */
  protected static $scenarioStaticInitialized = FALSE;

  /**
   * Initializes the cache objects, which are static.
   */
  protected static function initializeCaches() {
    if (!self::$cachesInitialized) {
      self::$users = new UserCache();
      self::$users->addIndices('roles', 'name');
      self::$nodes = new NodeCache();
      self::$nodes->addIndices('type');
      self::$languages = new LanguageCache();
      self::$terms = new TermCache();
      self::$roles = new RoleCache();
      self::$contexts = new ContextCache();
      self::$aliases = new AliasCache(array('users' => &self::$users, 'nodes' => &self::$nodes));
      self::$cachesInitialized = TRUE;
    }
  }

  /**
   * Clears the contents of all the (non-context) caches.
   *
   * This should be done after every scenario, and upon exit (normally, or via
   * interruption).
   */
  protected function clearCaches() {
    if (self::$scenarioStaticInitialized) {
      try {
        $this->logout();
        self::$aliases->clean($this);
        self::$users->clean($this);
        self::$nodes->clean($this);
        self::$languages->clean($this);
        self::$terms->clean($this);
        self::$roles->clean($this);
        self::$contexts->clean($this);
        self::$scenarioStaticInitialized = FALSE;
      }
      catch (\Exception $e) {
        throw new \Exception(sprintf("%s::%s line %s: Exception while clearning caches: %s", get_class($this), __FUNCTION__, __LINE__, $e->getMessage()));
      }
    }
  }

  /**
   * Destroys all cache objects.
   *
   * This should be done between features, and upon exiting (normally or via
   * interruption.).
   */
  protected static function destroyCaches() {
    if (self::$cachesInitialized) {
      self::$users = NULL;
      self::$nodes = NULL;
      self::$languages = NULL;
      self::$terms = NULL;
      self::$roles = NULL;
      self::$contexts = NULL;
      self::$aliases = NULL;
      self::$cachesInitialized = FALSE;
    }
  }

  /**
   * Instantiates all cache objects that will be used to store drupal ... stuff.
   *
   * @param Behat\Behat\Hook\Scope\BeforeFeatureScope $scope
   *   The behat surrounding scope.
   *
   * @BeforeFeature
   */
  public static function beforeFeature(BeforeFeatureScope $scope) {
    self::initializeCaches();
  }

  /**
   * Invalidates all static cache objects.
   *
   * Invalidation is done to ensure proper garbage collection.
   *
   * @param Behat\Behat\Hook\Scope\AfterFeatureScope $scope
   *   The behat surrounding scope.
   *
   * @AfterFeature
   */
  public static function afterFeature(AfterFeatureScope $scope) {
    self::destroyCaches();
  }

  /**
   * Captures other contexts.
   *
   * This method uses the BeforeScenario hook to capture other contexts
   * established during this scenario invocation, and stores them for
   * in-context use.
   *
   * @param \Behat\Behat\Hook\Scope\BeforeScenarioScope $scope
   *   The behat scope.
   *
   * @BeforeScenario
   */
  public function beforeScenario(BeforeScenarioScope $scope) {
    if (!self::$scenarioStaticInitialized) {
      $environment = $scope->getEnvironment();
      $settings    = $environment->getSuite()->getSettings();
      foreach ($settings['contexts'] as $context_name) {
        $context = $environment->getContext($context_name);
        self::$contexts->add($context_name, $context);
      }
      self::$scenarioStaticInitialized = TRUE;
    }
  }

  /**
   * Cleans all the cache objects.
   *
   * This has the side effect of cleaning any cached objects from the
   * database.
   *
   * @AfterScenario
   */
  public function afterScenario(AfterScenarioScope $scope) {
    // Note: this clears cache upon the afterscenario hook from the first
    // invoking context.  This assumes that none of the other context
    // instances will have need of anything in the cache in their own
    // afterscenario hook. This may be an erroneous assumption.
    self::clearCaches();
  }

  /**
   * Returns list of definition translation resources paths.
   *
   * @return array
   *   Returns an array containing .xliff i18n entries
   */
  public static function getTranslationResources() {
    return glob(__DIR__ . '/../../../../i18n/*.xliff');
  }

  /**
   * Utility function to create a node quickly and easily.
   *
   * @param array $values
   *   An array of values to assign to the node.
   *
   * @return object
   *   The newly created drupal node.
   */
  protected function createDefaultNode($values = array()) {
    // Create a serializable index from the unique values.
    // Assign defaults where possible.
    $values = $values + array(
      'body' => $this->getDriver()->getRandom()->string(255),
    );
    $values = (object) $values;
    $saved = $this->nodeCreate($values);
    return $saved;
  }

  /**
   * Creates a user.
   *
   * Utility function for the common job (in this context) of creating
   * a user.  This is a bit confusing with the presence of userCreate,
   * but this serves as a wrapper for that call to include caching
   * and role assignment.
   *
   * @param array $values
   *   An array of key/value pairs that describe
   *   the values to be assigned to this user.
   *
   * @return object
   *   The newly created user.
   */
  protected function createDefaultUser($values = array()) {
    // Assign defaults where possible.
    $values = $values + array(
      'name' => $this->getDriver()->getRandom()->name(8),
      'pass' => $this->getDriver()->getRandom()->name(16),
      'roles' => 'authenticated user',
    );
    $values['mail'] = "$values[name]@example.com";
    $values = (object) $values;
    $saved = $this->userCreate($values);
    return $saved;
  }

  /**
   * {@inheritdoc}
   */
  public function setDrupal(DrupalDriverManager $drupal) {
    $this->drupal = $drupal;
  }

  /**
   * {@inheritdoc}
   */
  public function getDrupal() {
    return $this->drupal;
  }

  /**
   * {@inheritdoc}
   */
  public function setDispatcher(HookDispatcher $dispatcher) {
    $this->dispatcher = $dispatcher;
  }

  /**
   * Set parameters provided for Drupal.
   */
  public function setDrupalParameters(array $parameters) {
    $this->drupalParameters = $parameters;
  }

  /**
   * Returns a specific Drupal parameter.
   *
   * @param string $name
   *   Parameter name.
   *
   * @return mixed
   *   Returns either the value of the parameter with the key $name, or NULL if
   *   the parameter is not defined.
   */
  public function getDrupalParameter($name) {
    return isset($this->drupalParameters[$name]) ? $this->drupalParameters[$name] : NULL;
  }

  /**
   * Returns a specific Drupal text value.
   *
   * @param string $name
   *   Text value name, such as 'log_out', which corresponds to the default 'Log
   *   out' link text.
   *
   * @throws \Exception
   *
   * @return mixed
   *   Returns either the value of the text test parameter $name, or NULL if
   *   not found.
   */
  public function getDrupalText($name) {
    $text = $this->getDrupalParameter('text');
    if (!isset($text[$name])) {
      throw new \Exception(sprintf(':%s::%s: No such drupal string: %s', get_class($this), __FUNCTION__, $name));
    }
    return $text[$name];
  }

  /**
   * Returns a specific css selector.
   *
   * @param string $name
   *   CSS selector name.
   *
   * @return string
   *   Returns either the value of the selector test parameter $name, or NULL if
   *   not found.
   */
  public function getDrupalSelector($name) {
    $text = $this->getDrupalParameter('selectors');
    if (!isset($text[$name])) {
      throw new \Exception(sprintf(':%s::%s: No such selector configured: %s', get_class($this), __FUNCTION__, $name));
    }
    return $text[$name];
  }

  /**
   * Get active Drupal Driver.
   *
   * @return \Drupal\Driver\DrupalDriver
   *   The drupal driver
   */
  public function getDriver($name = NULL) {
    return $this->getDrupal()->getDriver($name);
  }

  /**
   * Massage node values to match the expectations on different Drupal versions.
   *
   * @beforeNodeCreate
   */
  public function alterNodeParameters(BeforeNodeCreateScope $scope) {
    $node = $scope->getEntity();

    // Get the Drupal API version if available. This is not available when
    // using e.g. the BlackBoxDriver or DrushDriver.
    $api_version = NULL;
    $driver = $scope->getContext()->getDrupal()->getDriver();
    if ($driver instanceof DrupalDriver) {
      $api_version = $scope->getContext()->getDrupal()->getDriver()->version;
    }

    // On Drupal 8 the timestamps should be in UNIX time.
    switch ($api_version) {
      case 8:
        foreach (array('changed', 'created', 'revision_timestamp') as $field) {
          if (!empty($node->$field) && !is_numeric($node->$field)) {
            $node->$field = strtotime($node->$field);
          }
        }
        break;
    }
  }

  /**
   * Clear static caches.
   *
   * @AfterScenario @api
   */
  public function clearStaticCaches() {
    $this->getDriver()->clearStaticCaches();
  }

  /**
   * Dispatch scope hooks.
   *
   * @param string $scopeType
   *   The entity scope to dispatch.
   * @param \stdClass $entity
   *   The entity.
   */
  protected function dispatchHooks($scopeType, \stdClass $entity) {
    $fullScopeClass = 'Drupal\\DrupalExtension\\Hook\\Scope\\' . $scopeType;
    $scope = new $fullScopeClass($this->getDrupal()->getEnvironment(), $this, $entity);
    $callResults = $this->dispatcher->dispatchScopeHooks($scope);

    // The dispatcher suppresses exceptions, throw them here if there are any.
    foreach ($callResults as $result) {
      if ($result->hasException()) {
        $exception = $result->getException();
        throw new \Exception(sprintf(':%s::%s: %s', get_class($this), __FUNCTION__, $exception->getMessage()));
      }
    }
  }

  /**
   * Parse multi-value fields.
   *
   * Possible formats:
   *    A, B, C
   *    A - B, C - D, E - F.
   *
   * @param string $entity_type
   *   The entity type.
   * @param \stdClass $entity
   *   An object containing the entity properties and fields as properties.
   */
  public function parseEntityFields($entity_type, \stdClass $entity) {
    $multicolumn_field = '';
    $multicolumn_fields = array();

    foreach (clone $entity as $field => $field_value) {
      // Reset the multicolumn field if the field name does not contain a
      // column.
      if (strpos($field, ':') === FALSE) {
        $multicolumn_field = '';
      }
      // Start tracking a new multicolumn field if the field name contains a ':'
      // which is preceded by at least 1 character.
      elseif (strpos($field, ':', 1) !== FALSE) {
        list($multicolumn_field, $multicolumn_column) = explode(':', $field);
      }
      // If a field name starts with a ':' but we are not yet tracking a
      // multicolumn field we don't know to which field this belongs.
      elseif (empty($multicolumn_field)) {
        throw new \Exception(sprintf(':%s::%s: Field name missing for %s', get_class($this), __FUNCTION__, $field));
      }
      // Update the column name if the field name starts with a ':' and we are
      // already tracking a multicolumn field.
      else {
        $multicolumn_column = substr($field, 1);
      }

      $is_multicolumn = $multicolumn_field && $multicolumn_column;
      $field_name = $multicolumn_field ?: $field;
      if ($this->getDriver()->isField($entity_type, $field_name)) {
        // Split up multiple values in multi-value fields.
        $values = array();
        foreach (explode(',', $field_value) as $key => $value) {
          $value = trim($value);
          $columns = $value;
          // Split up field columns if the ' - ' separator is present.
          if (strstr($value, ' - ') !== FALSE) {
            $columns = array();
            foreach (explode(' - ', $value) as $column) {
              // Check if it is an inline named column.
              if (!$is_multicolumn && strpos($column, ': ', 1) !== FALSE) {
                list ($key, $column) = explode(': ', $column);
                $columns[$key] = $column;
              }
              else {
                $columns[] = $column;
              }
            }
          }
          // Use the column name if we are tracking a multicolumn field.
          if ($is_multicolumn) {
            $multicolumn_fields[$multicolumn_field][$key][$multicolumn_column] = $columns;
            unset($entity->$field);
          }
          else {
            $values[] = $columns;
          }
        }
        // Replace regular fields inline in the entity after parsing.
        if (!$is_multicolumn) {
          $entity->$field_name = $values;
        }
      }
    }

    // Add the multicolumn fields to the entity.
    foreach ($multicolumn_fields as $field_name => $columns) {
      $entity->$field_name = $columns;
    }
  }

  /**
   * Prints the cache contents of each cache.
   *
   * For debugging only.
   * TODO: Possibly should be private - revisit.
   *
   * @param string $cache_name
   *   An optional string argument - prints only that
   *                            specific cache.
   */
  protected function displayCaches($cache_name = 'all') {
    if (empty($cache_name)) {
      throw new \Exception(sprintf('%s: Invalid argument for function: %s', get_class($this), __FUNCTION__));
    }
    if ($cache_name === 'all') {
      print self::$users . "\n";
      print self::$roles . "\n";
      print self::$nodes . "\n";
      print self::$terms . "\n";
      print self::$languages . "\n";
      print self::$contexts . "\n";
      return;
    }
    if (!property_exists($this, $cache_name)) {
      throw new \Exception(sprintf('%s:::%s: Cache name is not a valid cache for this context: %s', get_class($this), __FUNCTION__, $cache_name));
    }
    print (self::$$cache_name) . "\n";

  }

  /**
   * Resolves an alias to a cached object.
   *
   * Returns an object that has been previously created and assigned a given
   * alias (using the @ symbol in a feature table.).
   *
   * Aliases are assigned using the @ symbol in table data.  When you assign
   * an alias, it is tracked within this codebase (externally to Drupal), so
   * that you can retrieve specific created objects during subsequent steps
   * for altering.
   *
   * You can additionally refer to field values in aliased objects in
   * subsequent creation steps.
   *
   * @param string $alias
   *   The alias to resolve. Can be any string. If you create a user, for
   *   example, with the values:
   *   | name | Joe Schmoe |
   *   | @    | test_user  |
   *   Then the alias will be 'test_user' for the created user, and the $alias
   *   argument here will be 'test_user'.
   *
   * @return mixed
   *    Returns whatever the original cached object was.  If the
   *    alias referred to a user object, like in the above example, this
   *    function would actually return that object, freshly loaded from the db.
   */
  public function resolveAlias($aliasfield) {
    // Ensure the alias does not have an attached field.
    @list($alias, $field) = explode('/', ltrim($aliasfield, '@:'));
    if (empty($alias)) {
      throw new \Exception(sprintf("%s::%s line %s: No alias was found in the passed argument %s", get_class($this), __FUNCTION__, __LINE__, $aliasfield));
    }
    return self::$aliases->get($alias, $this);
  }

  /**
   * Resolves an alias/field combination to a cached object value.
   *
   * Returns the value of a field on an aliased object.  See resolveAlias
   * for more info.
   *
   * @param string $aliasfield
   *   The alias/field combination to resolve. If you create a node, for
   *   example, with the values:
   *   | field_foo | Some value |
   *   | @         | test_user  |
   *   Then a valid combination would be '@test_user/field_foo'.
   *
   * @return mixed
   *    Returns whatever the field value is.
   */
  public function resolveAliasValue($aliasfield) {
    @list($alias, $field) = explode('/', ltrim($aliasfield, '@:'));
    if (empty($alias)) {
      throw new \Exception(sprintf("%s::%s line %s: No alias was found in the passed argument %s", get_class($this), __FUNCTION__, __LINE__, $aliasfield));
    }
    if (empty($field)) {
      throw new \Exception(sprintf("%s::%s line %s: No alias field was found in the passed argument %s", get_class($this), __FUNCTION__, __LINE__, $aliasfield));
    }
    return self::$aliases->getValue($alias, $field, $this);
  }

  /**
   * Create a user.
   *
   * @return object
   *   The created user.
   */
  public function userCreate($user) {
    if (is_array($user)) {
      $user = (object) $user;
    }
    $named_alias = AliasCache::extractAliasKey($user);
    self::$aliases->convertAliasValues($user, $this);
    $this->dispatchHooks('BeforeUserCreateScope', $user);
    $this->parseEntityFields('user', $user);
    $this->getDriver()->userCreate($user);
    if (isset($user->roles) && !empty($user->roles)) {
      if (!is_array($user->roles)) {
        throw new \Exception(sprintf("%s::%s line %s: the roles property must be an array", get_class($this), __FUNCTION__, __LINE__));
      }
      foreach ($user->roles as $role) {
        if (!in_array(strtolower($role), array('authenticated', 'authenticated user'))) {
          // Only add roles other than 'authenticated user'.
          $this->getDriver()->userAddRole($user, $role);
        }
      }
    }

    $this->dispatchHooks('AfterUserCreateScope', $user);
    self::$users->add($user->uid, $user);
    if (!is_null($named_alias)) {
      self::$aliases->add($named_alias, array('value' => $user->uid, 'cache' => 'users'));
    }
    return $user;
  }

  /**
   * Alter an existing user.
   *
   * @return object
   *   The altered node.
   *
   * @throws \Exception
   *   If the aliased object does not exist, or if any other
   *   situation occurs with the alteration. Exception will provide details.
   */
  public function userAlter($user, $values) {
    // Pay no mind to resolveAlias and convertAliasValues - they serve to
    // dynamically translate strings to field values at runtime.  Assume static
    // values for purposes of simplicity.
    if (!isset($user->uid)) {
      throw new \Exception(sprintf("%s::%s: user argument does not appear to be a valid loaded drupal node!  Load result: %s", get_class($this), __FUNCTION__, print_r($node, TRUE)));
    }
    $values = (object) $values;
    $named_alias = AliasCache::extractAliasKey($values);
    if (!empty($named_alias)) {
      throw new \Exception(sprintf("%s::%s line %s: Alias keys are not allowed in alteration steps.", get_class($this), __FUNCTION__, __LINE__));
    }
    self::$aliases->convertAliasValues($values, $this);
    $this->parseEntityFields('user', $values);
    $this->getDriver()->userAlter($user, $values);
    return $user;

  }

  /**
   * Create a node.
   *
   * @return object
   *   The created node.
   */
  public function nodeCreate($node) {
    if (is_array($node)) {
      $node = (object) $node;
    }
    $named_alias = AliasCache::extractAliasKey($node);
    self::$aliases->convertAliasValues($node, $this);
    $this->dispatchHooks('BeforeNodeCreateScope', $node);
    $this->parseEntityFields('node', $node);
    // note: this driver function actually returns the created object, where
    // others do not.  This should be standardized.
    $node = $this->getDriver()->createNode($node);
    $this->dispatchHooks('AfterNodeCreateScope', $node);
    $node_primary_key = self::$nodes->add($node->nid);
    if (!is_null($named_alias)) {
      self::$aliases->add($named_alias, array('value' => $node->nid, 'cache' => 'nodes'));
    }
    return $node;
  }

  /**
   * Alter an existing node.
   *
   * @return object
   *   The altered node.
   */
  public function nodeAlter($node, $values) {
    // Pay no mind to resolveAlias and convertAliasValues - they serve to
    // dynamically translate strings to field values at runtime.  Assume static
    // values for purposes of simplicity.
    if (!isset($node->nid)) {
      throw new \Exception(sprintf("%s::%s: Node argument does not appear to be a valid loaded drupal node!  Load result: %s", get_class($this), __FUNCTION__, print_r($node, TRUE)));
    }
    $values = (object) $values;
    $named_alias = AliasCache::extractAliasKey($values);
    if (!empty($named_alias)) {
      throw new \Exception(sprintf("%s::%s line %s: Alias keys are not allowed in alteration steps.", get_class($this), __FUNCTION__, __LINE__));
    }
    self::$aliases->convertAliasValues($values, $this);
    $this->parseEntityFields('node', $values);
    $this->getDriver()->getCore()->nodeAlter($node, $values);
    return $node;
    // Situations that need handling:
    // 1) resolving values for differing field types
    // 2) resolving alias changes (for aliases as defined by this class)
    // 3) resolving changes to immutable values.
  }

  /**
   * Create a term.
   *
   * Note: does this deal with multiple taxonomies? It doesn't appear so.
   *
   * @return object
   *   The created term.
   */
  public function termCreate($term) {
    $this->dispatchHooks('BeforeTermCreateScope', $term);
    $this->parseEntityFields('taxonomy_term', $term);
    $saved = $this->getDriver()->createTerm($term);
    $this->dispatchHooks('AfterTermCreateScope', $saved);
    self::$terms->add($saved->tid);
    return $saved;
  }

  /**
   * Creates a role.
   *
   * Extracted from DrupalContext's assertLoggedInWithPermissions,
   * this moves the functionality of creating a possibly shared role
   * into the parent class.
   *
   * @param string $permissions
   *   A comma-separated list of permissions.
   *
   * @return int
   *   The role id of the newly created role.
   */
  public function roleCreate($permissions) {
    // Identifier is *not* always rid, I think.  Not sure.
    $role_identifier = $this->getDriver()->roleCreate($permissions);
    self::$roles->add($role_identifier);
    return $role_identifier;
  }

  /**
   * Creates a language.
   *
   * @param \stdClass $language
   *   An object with the following properties:
   *   - langcode: the langcode of the language to create.
   *
   * @return object|FALSE
   *   The created language, or FALSE if the language was already created.
   */
  public function languageCreate(\stdClass $language) {
    $this->dispatchHooks('BeforeLanguageCreateScope', $language);
    $language = $this->getDriver()->languageCreate($language);
    if ($language) {
      $this->dispatchHooks('AfterLanguageCreateScope', $language);
      self::$languages->add($language->langcode, $language);
    }
    return $language;
  }

  /**
   * Returns the named user if he/she has been created.
   *
   * TODO: This function is not yet implemented (functionality removed until
   * underlying system can be retrofitted)
   *
   * @param string $name
   *   The name of the user.
   *
   * @return object | FALSE
   *   Returns the drupal user, or FALSE if the named
   *   user has not yet been created (in this scenario - doesn't check the db).
   */
  public function getNamedUser($name) {
    $users = self::$users->find(array('name' => $name), $this);
    if (empty($users)) {
      throw new \Exception("No user could be found with the name $name");
    }
    if (count($users) > 1) {
      throw new \Exception(sprintf("Multiple users with the name %s found.  Please be more specific.", get_class($this), __FUNCTION__, __LINE__, $name));
    }
    return $users[0];
  }

  /**
   * Returns the currently logged in user.
   *
   * The returned value is in the format applicable to whatever Drupal
   * version is currently being run, or NULL if nobody is currently
   * logged in. Note that using the aliasing mechanism here, this actually
   * returns a loaded user from the database (with some values slightly
   * modified - like password overwritten with the original, non-hashed values).
   *
   * @return object|NULL
   *   The currently logged in user.
   */
  public function getLoggedInUser() {
    if (!$this->loggedIn()) {
      return NULL;
    }
    $current_user = self::$aliases->get('_current_user_', $this);
    if (empty($current_user)) {
      throw new \Exception(sprintf('%s::%s: The drupal session is logged in, but no
        current user is recorded in the context.  This is an invalid state, and
        shouldn\'t have happened.', get_class($this), __FUNCTION__));
    }
    return $current_user;
  }

  /**
   * Log-in the current user.
   *
   * Logs in the passed user.  Assigns the alias '_current_user_' to this user
   * for later retrieval by other methods that work with the currently
   * logged in user.
   *
   * @param object $user
   *   The user to log in.
   */
  public function login($user) {
    if (!is_object($user) || !isset($user->name) || !isset($user->pass)) {
      throw new \Exception(sprintf('%s::%s line %s: Invalid argument for function: %s', get_class($this), __FUNCTION__, __LINE__, print_r($user, TRUE)));
    }
    try {
      // Check if logged in.
      if ($this->loggedIn()) {
        $this->logout();
      }
      $this->getSession()->visit($this->locatePath('/user/login'));
      $element = $this->getSession()->getPage();
      $element->fillField($this->getDrupalText('username_field'), $user->name);
      $element->fillField($this->getDrupalText('password_field'), $user->pass);
      $submit = $element->findButton($this->getDrupalText('log_in'));
      if (empty($submit)) {
        throw new \Exception(sprintf("%s::%s: No submit button at %s", get_class($this), __FUNCTION__, $this->getSession()->getCurrentUrl()));
      }

      // Log in.
      $submit->click();
      // $user->roles = array_diff($user->roles, array('authenticated user'));.
      if (!$this->loggedIn()) {
        fwrite(STDOUT, "Failed to login as user:" . print_r($user, TRUE));
        // $this->callContext('Drupal', 'iPutABreakpoint');.
        throw new \Exception(sprintf("%s::%s: Failed to log in as user '%s' with role(s) '%s'", get_class($this), __FUNCTION__, $user->name, implode(", ", $user->roles)));
      }
      self::$aliases->add('_current_user_', array('value' => $user->uid, 'cache' => 'users'));
    }
    catch (\Exception $e) {
      var_dump($this->getSession()->getPage()->getContent());
      throw new \Exception(sprintf("%s::%s line %s: %s", get_class($this), __FUNCTION__, __LINE__, $e->getMessage()));
    }
  }

  /**
   * Logs the current user out.
   */
  public function logout() {
    $this->getSession()->visit($this->locatePath('/user/logout'));
    self::$aliases->remove('_current_user_', $this);
  }

  /**
   * Determine if the a user is already logged in.
   *
   * @return bool
   *   Returns TRUE if a user is logged in for this session.
   */
  public function loggedIn() {
    $session = $this->getSession();
    $page = $session->getPage();

    // Look for a css selector to determine if a user is logged in.
    // Default is the logged-in class on the body tag.
    // Which should work with almost any theme.
    try {
      if ($page->has('css', $this->getDrupalSelector('logged_in_selector'))) {
        return TRUE;
      }
    }
    catch (DriverException $e) {
      // This test may fail if the driver did not load any site yet.
    }

    // Some themes do not add that class to the body, so lets check if the login
    // form is displayed on /user/login.
    $session->visit($this->locatePath('/user/login'));
    if (!$page->has('css', $this->getDrupalSelector('login_form_selector'))) {
      return TRUE;
    }
    $session->visit($this->locatePath('/'));

    // If a logout link is found, we are logged in. While not perfect, this is
    // how Drupal SimpleTests currently work as well.
    $element = $session->getPage();
    return $element->findLink($this->getDrupalText('log_out'));
  }

  /**
   * User with a given role(s) is already logged in.
   *
   * Note that the function has changed here from earlier versions,
   * which was 'loggedInWithRole'.
   *
   * @param string $roles
   *   A single role, or multiple comma-separated roles in a single string.
   *
   * @return bool
   *   Returns TRUE if the current logged in user has this role (or roles).
   */
  public function loggedInWithRoles($roles) {
    if (!$this->loggedIn()) {
      return FALSE;
    }
    if (is_string($roles)) {
      $roles = array_map("trim", explode(',', $roles));
    }
    $current_user = self::$aliases->get('_current_user_', $this);
    if (!isset($current_user->roles)) {
      return FALSE;
    }
    return (count(array_intersect($roles, $current_user->roles)) === count($current_user->roles));
  }

  /**
   * User with a given role(s) is already logged in.
   *
   * @deprecated
   *   Changed to loggedInWithRoles.
   */
  public function loggedInWithRole($role) {
    return $this->loggedInWithRoles($role);
  }

  /**
   * Convenience method.  Invokes a method on another context object.
   *
   * @param string $context_name
   *   The name of the other context.  This cannot be arbitrary -  said context
   *   must have been explicitly stored in the class during the BeforeScenario
   *   hook (see gatherContexts).
   *
   * @return RawDrupalContext
   *   The cached context object.  This context must have been instantiated
   *   for a given scenario in order to be retrieved; this function will
   *   throw an exception most likely in cases where the calling test suite
   *   does not include this context among its list of needed resources in
   *   the behat.yml config file.
   *
   * @throws \Exception
   *   If the passed method does not exist on the requested context, or if the
   *   named context does not exist.
   */
  public function getContext($context_name) {
    $other_context = NULL;
    try {
      // Assume context_name is the full literal classpath for starters.
      $other_context = self::$contexts->get($context_name, $this);
    }
    catch (\Exception $e) {
      // Search by classpath failed. Try a partial match, based just on the
      // class name.  If you get a single result, use it.  If not, throw an
      // exception.
      $other_contexts = self::$contexts->find(array('class' => $context_name), $this);
      if (count($other_contexts) === 0) {
        throw new \Exception(sprintf("%s::%s: %s context not available.  Available contexts: %s", get_class($this), __FUNCTION__, $context_name, print_r(self::$contexts, TRUE)));
      }
      if (count($other_contexts) > 1) {
        throw new \Exception(sprintf("%s::%s: line %s: Multiple results for context lookup term %s; please be more specific.", get_class($this), __FUNCTION__, __LINE__, $context_name));
      }
      $other_context = $other_contexts[0];
      unset($other_contexts);
    }
    return $other_context;
  }

  /**
   * Convenience method.  Invokes a method on another context object.
   *
   * @param string $context_name
   *   The name of the other context.  This cannot be arbitrary -  said context
   *   must have been explicitly stored in the class during the BeforeScenario
   *   hook (see gatherContexts).
   * @param string $method
   *   The name of the method to invoke.
   *
   * @return mixed
   *   The results of the callback from the invoked method.
   *
   * @throws \Exception
   *   If the passed method does not exist on the requested context, or if the
   *   named context does not exist.
   */
  public function callContext($context_name, $method) {
    $other_context = $this->getContext($context_name);
    if (!method_exists($other_context, $method)) {
      throw new \Exception(sprintf("%s::%s: The method %s does not exist in the %s context", get_class($this), __FUNCTION__, $method, $context_name));
    }
    $args = array_slice(func_get_args(), 2);
    return call_user_func_array(array($other_context, $method), $args);
  }

  /**
   * Utility function to convert a tableNode to an array.
   *
   * Note: TableNodes are immutable, so I can't directly modify them.  This
   * function assumes row-based ordering.
   *
   * @param TableNode $table
   *   The tablenode to be converted.
   *
   * @return array
   *   An array of the tablenode results. Returns an empty array if the passed
   *   table is null or empty.
   */
  public static function convertTableNodeToArray(TableNode $table = NULL, $arrangement = 'row') {

    $values = array();
    if (is_null($table)) {
      return $values;
    }
    // As far as I can tell, tableNodes are immutable.  Need to step
    // this back down to an array to ensure all required values are
    // being accounted for.
    switch ($arrangement) {
      case 'row':
      case 'rows':
          foreach ($table->getRowsHash() as $field => $value) {
          $values[$field] = $value;
          }
        break;

      case 'column':
      case 'columns':
        foreach ($table->getColumnsHash() as $field => $value) {
          $values[$field] = $value;
        }
        break;

      default:
        throw new \Exception(sprintf("%s::%s: Unknown table structure requested: %s", get_class($this), __FUNCTION__, $arrangement));
    }
    return $values;
  }

  /**
   * Retrieve a table row containing specified text from a given element.
   *
   * Note: moved from DrupalContext to consolidate non-step-defining functions.
   *
   * @param \Behat\Mink\Element\Element $element
   *   The starting element from which the search should be performed.
   * @param string $search
   *   The text to search for in the table row.
   *
   * @return \Behat\Mink\Element\NodeElement
   *   The row containing the text that was searched for.
   */
  public function getTableRow(Element $element, $search) {
    $rows = $element->findAll('css', 'tr');
    if (empty($rows)) {
      throw new \Exception(sprintf('%s::%s: No rows found on the page %s', get_class($this), __FUNCTION__, $this->getSession()->getCurrentUrl()));
    }
    foreach ($rows as $row) {
      if (strpos($row->getText(), $search) !== FALSE) {
        return $row;
      }
    }
    throw new \Exception(sprintf('%s::%s: Failed to find a row containing "%s" on the page %s', get_class($this), __FUNCTION__, $search, $this->getSession()->getCurrentUrl()));
  }

  /**
   * Provides a stringified version of an object.
   *
   * Convenience function for debugging. This only gives one level deep, and
   * reduces data structures to "[Obj/Arr]" unless specifically designated
   * otherwise. It's designed to give an overview of a data structure while
   * not overwhelming the CLI output with noise.
   *
   * @param object $o
   *   The object to stringify.
   * @param array $options
   *   An array of options to control output.
   *
   * @return string
   *   A string version of the object, suitable for output in a
   *   CLI environment.
   */
  protected function stringifyObject($o, $options = array()) {
    if (!is_array($o) && !is_object($o)) {
      return $o;
    }
    $options = $options + array(
      'label' => 'object',
      'expand fields' => array(),
    );
    $expand_all   = in_array('all', $options['expand fields']);
    $output       = "\n<$options[label]>\n";

    foreach ($o as $k => $v) {
      if (is_object($v) || is_array($v)) {
        if ($expand_all || in_array($k, $options['expand fields'])) {
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
    $output .= "</$options[label]>\n";
    return $output;
  }

}
