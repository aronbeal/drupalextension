<?php

/**
 * @file
 */

namespace Drupal\DrupalExtension\Context;

use Behat\MinkExtension\Context\RawMinkContext;
use Behat\Mink\Exception\DriverException;
use Behat\Testwork\Hook\HookDispatcher;
use Behat\Mink\Element\Element;

use Drupal\DrupalDriverManager;

use Drupal\DrupalExtension\Hook\Scope\AfterLanguageEnableScope;
use Drupal\DrupalExtension\Hook\Scope\AfterNodeCreateScope;
use Drupal\DrupalExtension\Hook\Scope\AfterTermCreateScope;
use Drupal\DrupalExtension\Hook\Scope\AfterUserCreateScope;
use Drupal\DrupalExtension\Hook\Scope\BaseEntityScope;
use Drupal\DrupalExtension\Hook\Scope\BeforeLanguageEnableScope;
use Drupal\DrupalExtension\Hook\Scope\BeforeNodeCreateScope;
use Drupal\DrupalExtension\Hook\Scope\BeforeUserCreateScope;
use Drupal\DrupalExtension\Hook\Scope\BeforeTermCreateScope;
use Drupal\DrupalExtension\Context\Cache as ExtensionCache;


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
   * @var stdClass|bool
   */
  public $user = FALSE;
  /**
   * A cache object that can store a globally unique alias to any object in
   * any of the other caches.  This cache stores the primary index of the
   * object in the other cache (and the cache name) rather than the object itself.
   *
   * @var Drupal\DrupalExtension\Context\Cache\CacheInterface
   */
  protected static $aliases = NULL;
  /**
   * Keep track of nodes so they can be cleaned up.  Note that this has
   * been converted to a static variable, reflecting the fact that nodes
   * can be created by multiple contexts.
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
  protected static $languages = array();

  /**
   * Static scenario variable objects that need to be refreshed between
   * feature invocations use this flag.
   *
   * @var boolean
   */
  protected static $feature_static_initialized = FALSE;
  /**
   * Static scenario variable objects that need to be refreshed between
   * scenario invocations use this flag.
   *
   * @var boolean
   */
  protected static $scenario_static_initialized = FALSE;


  /**
   * @BeforeFeature
   *
   * Instantiates all cache objects that will be used to store drupal ... stuff.
   *
   * @param  Behat\Behat\Hook\Scope\BeforeFeatureScope $scope
   *   the behat surrounding scope
   * @return NULL
   */
  public static function beforeFeature(\Behat\Behat\Hook\Scope\BeforeFeatureScope $scope) {
    if (!self::$feature_static_initialized) {
      self::$users = new ExtensionCache\UserCache();
      self::$users->addIndices('roles', 'name');
      self::$nodes = new ExtensionCache\NodeCache();
      self::$nodes->addIndices('type');
      self::$languages = new ExtensionCache\LanguageCache();
      self::$terms = new ExtensionCache\TermCache();
      self::$roles = new ExtensionCache\RoleCache();
      self::$contexts = new ExtensionCache\ContextCache();
      self::$aliases = new ExtensionCache\AliasCache();
      self::$feature_static_initialized = TRUE;
    }
  }
  /**
   * @AfterFeature
   *
   * Invalidates all static cache objects to ensure proper garbage
   * collection.
   *
   * @param  Behat\Behat\Hook\Scope\AfterFeatureScope $scope
   *   the behat surrounding scope
   * @return NULL
   */
  public static function afterFeature(\Behat\Behat\Hook\Scope\AfterFeatureScope $scope) {
    if (self::$feature_static_initialized) {
      self::$users = NULL;
      self::$nodes = NULL;
      self::$languages = NULL;
      self::$terms = NULL;
      self::$roles = NULL;
      self::$contexts = NULL;
      self::$aliases = NULL;
      self::$feature_static_initialized = FALSE;
    }

  }
  /**
   * @BeforeScenario
   *
   * Captures other contexts established during this scenario invocation,
   * and stores them for in-context use.
   * @param  \Behat\Behat\Hook\Scope\BeforeScenarioScope $scope
   *   The behat scope
   *   [description]
   * @return [type]                                                       [description]
   */
  public function beforeScenario(\Behat\Behat\Hook\Scope\BeforeScenarioScope $scope) {
    if (!self::$scenario_static_initialized) {
      $environment = $scope->getEnvironment();
      $settings    = $environment->getSuite()->getSettings();
      foreach ($settings['contexts'] as $context_name) {
        $context = $environment->getContext($context_name);
        self::$contexts->add($context_name, $context);
      }
      self::$scenario_static_initialized = TRUE;
    }
  }
  /**
   * @AfterScenario
   * Cleans all the cache objects.  This has the side effect of cleaning
   * any cached objects from the database.
   *
   * TODO: This approach assumes all context scenarios end at the same time.
   * Revisit to ensure this is a valid assumption.
   */
  public function afterScenario(\Behat\Behat\Hook\Scope\AfterScenarioScope $scope) {
    if (self::$scenario_static_initialized) {
      $this->logout();
      self::$users->clean($this);
      self::$nodes->clean($this);
      self::$languages->clean($this);
      self::$terms->clean($this);
      self::$roles->clean($this);
      self::$contexts->clean($this);
      self::$aliases->clean($this);
      self::$scenario_static_initialized = FALSE;
    }
  }
  /**
   * Converts alias values passed in from a feature into the value of the object and field the alias references.
   *
   * @param object $value_object
   *         The parameterized object that will be used to create a new Drupal object (node, user, what have you).
   *         This function is called primarily by the create[X] methods found in RawDrupalContext.
   */
  public function convertAliasValues(&$value_object) {
    // Translate dynamic values if present.
    //
    foreach ($value_object as $field_name => $prospective_alias) {
      if (!is_string($prospective_alias)) {
        //we currently don't allow aliases to exist deeper than the first level.
        continue;
      }
      //print sprintf("%s::%s: Prospective alias: %s\n", get_class($this), __FUNCTION__, $prospective_alias);
      if (preg_match('|^' . ExtensionCache\AliasCache::ALIAS_VALUE_PREFIX . '|', $prospective_alias)) {
        // This should map to a value in the alias cache.
        $confirmed_alias_with_field = str_replace(ExtensionCache\AliasCache::ALIAS_VALUE_PREFIX, '', $prospective_alias);
        $av_components = explode('/', $confirmed_alias_with_field);
        if (count($av_components) < 2) {
          throw new \Exception(sprintf("%s::%s: Any alias passed as a value must have a field assigned to it.  The alias %s does not", get_class($this), __FUNCTION__, $v));
        }
        list($confirmed_alias, $referenced_field_name) = $av_components;
        $o = $this->resolveAlias($confirmed_alias);
        if (empty($o)) {
          throw new \Exception(sprintf('%s::%s: Attempt was made to dynamically reference the property of an item that was not yet created.', get_class($this), __FUNCTION__));
        }
        if (!property_exists($o, $referenced_field_name)) {
          $property_list = array_keys(get_object_vars($o));
          sort($property_list);
          throw new \Exception(sprintf("%s::%s: The field %s was  not found on the retrieved cache object: %s ", get_class($this), __FUNCTION__, $referenced_field_name, print_r($property_list, TRUE)));
        }
        //print sprintf("%s::%s: Retrieved value: %s\n", get_class($this), __FUNCTION__, $o->$referenced_field_name);
        if (is_object($value_object)) {
          $value_object->$field_name = $o->$referenced_field_name;
        }
        elseif (is_array($value_object)) {
          $value_object[$field_name] = $o->$referenced_field_name;
        }
        else {
          throw new \Exception(sprintf('%s::%s: Invalid argument type for function: %s', get_class($this), __FUNCTION__, gettype($value_object)));
        }

      }
    }
  }
  /**
   * Returns list of definition translation resources paths.
   * Note: moved from DrupalContext function to consolidate non-step
   * defining functionality to parent class.
   *
   * @return array
   */
  public static function getTranslationResources() {
    return glob(__DIR__ . '/../../../../i18n/*.xliff');
  }
  /**
   * Utility function to create a node quickly and easily.
   *
   * @param array $valuesAn
   *   array of values to assign to the node.
   *   An array of values to assign to the node.
   *
   * @return The newly created drupal node
   */
  protected function _createNode($values = array()) {
    if(isset($values['nid'])){
      try{
        $node = self::$nodes->get($values['nid']);
        return $node;
      } catch(\Exception $e){
        //TODO: do nothing?  This node exists in the db, but not in cache.  This
        //seems like it should be rethrown.
      }
    }

    // Create a serializable index from the unique values.
    // Assign defaults where possible.
    $values = $values + array(
      'body' => $this->getDriver()->getRandom()->string(255)
    );
    $values = (object) $values;
    $saved = $this->nodeCreate($values);
    return $saved;
  }

  /**
   * Utility function for the common job (in this context) of creating
   * a user.  This is a bit confusing with the presence of userCreate,
   * but this serves as a wrapper for that call to include caching
   * and role assignment.
   *
   * @param array $valuesAn
   *   array of key/value pairs that describe
   *   An array of key/value pairs that describe
   *   the values to be assigned to this user.
   *
   * @return $user         The newly created user.
   */
  protected function _createUser($values = array()) {
    $cached = self::$users->find($values);
    if (!empty($cached)) {
      print sprintf("%s::%s: Cached user found for value array %s", get_class($this), __FUNCTION__, print_r($cached, TRUE));
      return $cached;
    }

    // Assign defaults where possible.
    $values = $values + array(
        'name' => $this->getDriver()->getRandom()->name(8),
        'pass' => $this->getDriver()->getRandom()->name(16),
        'roles' => array()
      );
    $values['mail'] = "$values[name]@example.com";
    $values = (object) $values;
    $user = $this->userCreate($values);
    foreach ($user->roles as $role) {
      if (!in_array(strtolower($role), array('authenticated', 'authenticated user'))) {
        // Only add roles other than 'authenticated user'.
        $this->getDriver()->userAddRole($user, $role);
      }
    }
    return $saved;
  }
  /**
   * {@inheritDoc}.
   */
  public function setDrupal(DrupalDriverManager $drupal) {
    $this->drupal = $drupal;
  }

  /**
   * {@inheritDoc}.
   */
  public function getDrupal() {
    return $this->drupal;
  }


  /**
   * {@inheritDoc}.
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
   * @return
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
   * @param $name
   *   string CSS selector name
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
    if ($driver instanceof \Drupal\Driver\DrupalDriver) {
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
   * @param string $scope
   *   The entity scope to dispatch.
   * @param object $entity
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
   * Parse multi-value fields. Possible formats:
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
      // Reset the multicolumn field if the field name does not contain a column.
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
   * Prints the cache contents of each cache.  For debugging only.
   * TODO: Possibly should be private - revisit.
   *
   * @param string $cache_name
   *   An optional string argument - prints only that
   *                            specific cache.
   *
   * @return NULL
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
   * Create a user.
   *
   * @return object
   *   The created user.
   */
  public function userCreate($user) {
    $named_alias = ExtensionCache\AliasCache::extractAliasKey($user);
    $this->convertAliasValues($user);
    $this->dispatchHooks('BeforeUserCreateScope', $user);
    $this->parseEntityFields('user', $user);
    $this->getDriver()->userCreate($user);
    $this->dispatchHooks('AfterUserCreateScope', $user);
    self::$users->add($user->uid);
    if (!is_null($named_alias)) {
      $this->addAlias($named_alias, $user->uid, 'users');
    }
    return $user;
  }

  /**
   * Create a node.
   *
   * @return object
   *   The created node.
   */
  public function nodeCreate($node) {
    if(is_array($node)){
      $node = (object) $node;
    }
    $named_alias = ExtensionCache\AliasCache::extractAliasKey($node);
    $this->convertAliasValues($node);
    $this->dispatchHooks('BeforeNodeCreateScope', $node);
    $this->parseEntityFields('node', $node);
    $saved = $this->getDriver()->createNode($node);
    $this->dispatchHooks('AfterNodeCreateScope', $saved);
    self::$nodes->add($saved->nid);
    if (!is_null($named_alias)) {
      $this->addAlias($named_alias, $saved->nid, 'nodes');
    }
    return $saved;
  }
  /**
   * Create a term. Note: does this deal with multiple taxonomies? It
   * doesn't appear so.
   *
   * @return object
   *   The created term.
   */
  public function termCreate($term) {
    $named_alias = ExtensionCache\AliasCache::extractAliasKey($term);
    if (!is_null($named_alias)) {
      throw new \Exception(sprintf('%s::%s: Aliasing for terms is not yet supported', get_class($this), __FUNCTION__));
    }
    $this->dispatchHooks('BeforeTermCreateScope', $term);
    $this->parseEntityFields('taxonomy_term', $term);
    $saved = $this->getDriver()->createTerm($term);
    $this->dispatchHooks('AfterTermCreateScope', $saved);
    $term_primary_key = self::$terms->add($term->tid);
    return $saved;
  }
  /**
   * Extracted from DrupalContext's assertLoggedInWithPermissions,
   * this moves the functionality of creating a possibly shared role
   * into the parent class.
   *
   * @param string $permissions
   *   A comma-separated list of permissinos
   *
   * @return int              The role id of the newly created role.
   */
  public function roleCreate($permissions) {
    $named_alias = ExtensionCache\AliasCache::extractAliasKey($permissions);
    if (!is_null($named_alias)) {
      throw new \Exception(sprintf('%s::%s: Aliasing for roles is not yet supported', get_class($this), __FUNCTION__));
    }
    $role_name = $this->getDriver()->roleCreate($permissions);
    $role = new \stdClass();
    $role->rid = $role_name;
    $role->permissions = $permissions;
    $role_primary_key = self::$roles->add($role->rid);
    return $role_name;
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
    $named_alias = ExtensionCache\AliasCache::extractAliasKey($role);
    if (!is_null($named_alias)) {
      throw new \Exception(sprintf('%s::%s: Aliasing for languages is not yet supported', get_class($this), __FUNCTION__));
    }
    $this->dispatchHooks('BeforeLanguageCreateScope', $language);
    $language = $this->getDriver()->languageCreate($language);
    if ($language) {
      $this->dispatchHooks('AfterLanguageCreateScope', $language);
      self::$languages->add($language->langcode);
    }
    return $language;
  }

  /**
   * Returns the named user if he/she has been created.
   *
   * @param string $name
   *   The name of the user
   *
   * @return The user | FALSE
   *                  Returns FALSE if the named user has not yet been
   *                  created (in this scenario - doesn't check the db)
   */
  public function getNamedUser($name) {
    $result = self::$users->find(array('name' => $name));
    if (!empty($results)) {
      return FALSE;
    }
    return reset($results);
  }
  /**
   * Returns the currently logged in user.
   *
   * @return object|NULL
   * The currently logged in user, in the format applicable to whatever drupal
   * version is currently being run, or NULL if nobody is currently
   * logged in.
   */
  public function getLoggedInUser() {
    if ($this->loggedIn()) {
      return NULL;
    }
    $current_user = $this->resolveAlias('_current_user_');
    if (empty($current_user)) {
      throw new \Exception(sprintf('%s::%s: The drupal session is logged in, but no
        current user is recorded in the context.  This is an invalid state, and
        shouldn\'t have happened.', get_class($this), __FUNCTION__));
    }
    return $current_user;
  }
  /**
   * Resolves a cache alias to one of the caches defined in the current static
   * scope.
   * I don't like this.  The alias cache has a hard dependency on this function
   * to translate its output.  Code seems stinky. TODO: rethink this approach.
   *
   * @param string $alias
   *   The alias to retrieve
   *
   * @return object
   *   The object that was stored in the cache at that alias.
   *
   * @throws \Exception
   *   If the alias is not found, or if the named cache does not exist.
   */
  protected function resolveAlias($alias) {
    $a = self::$aliases->get($alias);
    if (empty($a)) {
      throw new \Exception(sprintf("%s::%s: No alias by the name of %s exists", get_class($this), __FUNCTION__, $alias));
    }
    list($cache_name, $key) = $a;
    if (!property_exists($this, $cache_name)) {
      throw new \Exception(sprintf("%s::%s: No cache exists by the name of %s", get_class($this), __FUNCTION__, $cache_name));
    }
    return self::$$cache_name->get($key);
  }
  /**
   * Adds the specified value as an aliased item to the AliasCache.
   *
   * @param string $alias
   *   The globally unique alias to refer to this item by
   * @param string $value
   *   The alias cache is currently restricted to storing string values.  It
   *                                      is expected that these values will correspond to the unique index
   *                                      key of the cached item.
   * @param string $cache_name
   *   The name of the local cache where the item is stored.
   */
  protected function addAlias($alias, $value, $cache_name) {
    if (!property_exists($this, $cache_name)) {
      throw new \Exception(sprintf("%s::%s: No cache exists by the name of %s", get_class($this), __FUNCTION__, $cache_name));
    }
    // TODO: add a step to check if the aliased item exists before setting up the alias.
    self::$aliases->add($alias, array('value' => $value, 'cache' => $cache_name));
  }
  /**
   * Removes the named alias if it exists.
   *
   * @param string $alias
   *   The named alias to remove
   *
   * @return object | NULL
   *         The object the alias referred to, or NULL if the alias
   *         was not found.
   */
  protected function removeAlias($alias) {
    return self::$aliases->remove($alias);
  }
  /**
   * Log-in the current user.
   *
   * @param object $user
   *   The user to log in.
   */
  public function login($user) {
    if (!is_object($user) || !isset($user->name) || !isset($user->pass)) {
      throw new \Exception(sprintf('%s: Invalid argument for function: %s', get_class($this), __FUNCTION__));
    }
    // Check if logged in.
    if ($this->loggedIn()) {
      $this->logout();
    }

    $this->getSession()->visit($this->locatePath('/user'));
    $element = $this->getSession()->getPage();
    $element->fillField($this->getDrupalText('username_field'), $user->name);
    $element->fillField($this->getDrupalText('password_field'), $user->pass);
    $submit = $element->findButton($this->getDrupalText('log_in'));
    if (empty($submit)) {
      throw new \Exception(sprintf("%s::%s: No submit button at %s", get_class($this), __FUNCTION__, $this->getSession()->getCurrentUrl()));
    }

    // Log in.
    $submit->click();

    if (!$this->loggedIn()) {
      throw new \Exception(sprintf("%s::%s: Failed to log in as user '%s' with role '%s'", get_class($this), __FUNCTION__, $user->name, $user->role));
    }
    $this->addAlias('_current_user_', $user->uid, 'users');
  }

  /**
   * Logs the current user out.
   */
  public function logout() {
    $this->getSession()->visit($this->locatePath('/user/logout'));
    $this->removeAlias('_current_user_');
  }

  /**
   * Determine if the a user is already logged in.
   *
   * @return boolean
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

    // Some themes do not add that class to the body, so lets check if the
    // login form is displayed on /user/login.
    $session->visit($this->locatePath('/user/login'));
    if (!$page->has('css', $this->getDrupalSelector('login_form_selector'))) {
      return TRUE;
    }

    $session->visit($this->locatePath('/'));

    // If a logout link is found, we are logged in. While not perfect, this is
    // how Drupal SimpleTests currently work as well.
    $element = $session->getPage();
    $result = $element->findLink($this->getDrupalText('log_out'));
    if ($result) {
      $current_user = $this->resolveAlias('_current_user_');
      if (empty($current_user)) {
        throw new \Exception(sprintf("%s::%s: Invalid state - logged in, but current user is empty", get_class($this), __FUNCTION__));
      }
    }
    return $result;
  }

  /**
   * User with a given role is already logged in.
   *
   * @param string $role
   *   A single role, or multiple comma-separated roles in a single string.
   *
   * @return boolean
   *   Returns TRUE if the current logged in user has this role (or roles).
   */
  public function loggedInWithRoles($roles) {
    if (!$this->loggedIn()) {
      return FALSE;
    }
    if (is_string($roles)) {
      $roles = array_map("trim", explode(',', $roles));
    }
    $current_user = $this->resolveAlias('_current_user_');
    if (!isset($current_user->roles)) {
      return FALSE;
    }
    return (count(array_intersect($roles, $current_user->roles)) === count($current_user->roles));
  }

  /**
   * Convenience method.  Invokes a method on another context object.
   *
   * @param string $context_name
   *   The name of the other context.  This cannot be
   *                             arbitrary -  said context must have been
   *                             explicitly stored in the class during the
   *                             BeforeScenario hook (see gatherContexts).
   * @param string $method
   *   The name of the method to invoke.
   *
   * @return mixed              The results of the callback from the invoked
   *                                method.
   *
   * @throws \Exception   If the passed method does not exist on the requested
   *                       context, or if the named context does not exist.
   */
  public function callContext($context_name, $method) {
    $other_context = self::$contexts->get($context_name);
    if (empty($other_context)) {
      throw new \Exception(sprintf("%s::%s: $context_name context not available from within %s.  Available contexts: %s", get_class($this), __FUNCTION__, get_class($this), print_r(self::$contexts, TRUE)));
    }
    if (!method_exists($other_context, $method)) {
      throw new \Exception(sprintf("%s::%s: The method %s does not exist in the %s context", get_class($this), __FUNCTION__, $method, $context_name));
    }
    $args = array_slice(func_get_args(), 2);
    return call_user_func_array(array($other_context, $method), $args);
  }
  /**
   * Utility function to convert a tableNode to an array.  TableNodes
   * are immutable, so I can't directly modify them.  This function
   * assumes row-based ordering.
   *
   * @param TableNode $table
   *   The tablenode to be converted
   *
   * @return array           An array of the tablenode results.
   */
  public static function convertTableNodeToArray(\Behat\Gherkin\Node\TableNode $table) {

    $options = array();
    // As far as I can tell, tableNodes are immutable.  Need to step
    // this back down to an array to ensure all required values are
    // being accounted for.
    foreach ($table->getRowsHash() as $field => $value) {
      $options[$field] = $value;
    }
    return $options;
  }
  /**
   * Retrieve a table row containing specified text from a given element.
   * Note: moved from DrupalContext to consolidate non-step-defining functions.
   *
   * @param \Behat\Mink\Element\Element
   * @param string
   *   The text to search for in the table row.
   *
   * @return \Behat\Mink\Element\NodeElement
   *
   * @throws \Exception
   */
  public function getTableRow($element, $search) {
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

}
