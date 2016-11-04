<?php

namespace Drupal\DrupalExtension\Context\Cache;

use Drupal\DrupalExtension\Context\RawDrupalContext as Context;

/**
 * Stores any users generated with api calls.
 */
class UserCache extends CacheBase {
  /**
   * Stores user metadata.
   *
   * Metadata in this case is data that cannot be adequately retrieved once it
   * is stored in a user object in the drupal system. The most prominent
   * example is the user's password, which we need to log this user in more
   * than once, but there may be others.
   *
   * Metadata is added during the add method, and retrieved during the get
   * method.
   *
   * @var null
   */
  private $metadata = NULL;

  /**
   * Override constructor to add metadata object.
   *
   * See variable description for more info.
   */
  public function __construct() {
    parent::__construct();
    $this->metadata = new \stdClass();
  }

  /**
   * {@inheritdoc}
   *
   * Extend the base implementation, as we need to pass in the
   * full user object for value. We extract any metadata properties, and then
   * pass to the parent for normal processing.
   */
  public function add($index, $value = NULL) {

    if (empty($value)) {
      throw new \Exception(sprintf("%s::%s: A user object must be passed to the add method for this cache.", get_class($this), __FUNCTION__));
    }
    $metadata = array(
      'name' => $value->name,
      'pass' => $value->pass,
    );
    $this->addMetaData($index, $metadata);
    return parent::add($index, $value);
  }

  /**
   * Adds metadata about a stored cache item.
   *
   * User metadata is data that
   * cannot be retrieved when retrieving the user object.
   *
   * @param mixed $index
   *   The index of the user object in the cache (uid).
   * @param array $metadata
   *   An array of key/value pairs to store for that index.
   */
  private function addMetadata($index, $metadata = array()) {
    if (empty($metadata)) {
      return;
    }
    $index = strval($index);
    $this->metadata->{$index} = (object) $metadata;
  }

  /**
   * {@inheritdoc}
   */
  public function clean(Context &$context) {
    if ($this->count() === 0) {
      return TRUE;
    }
    $uids = array_keys(get_object_vars($this->cache));
    foreach ($uids as $uid) {
      if ($this->getCacheInstruction($uid, 'noclean')) {
        continue;
      }
      $user = new \stdClass();
      $user->uid = $uid;
      foreach ($this->getMetaData($user->uid) as $k => $v) {
        // Adds back items critical for deletion in some drivers.
        $user->{$k} = $v;
      }
      $context->getDriver()->userDelete($user);
    }
    // See note on batch processing at
    // https://api.drupal.org/api/drupal/modules%21user%21user.module/function/user_cancel/7.x
    $context->getDriver()->processBatch();
    $this->resetCache();
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * In order to avoid a db lookup, the find operation for the user cache
   * only stores values stored in an index.
   */
  public function find(array $values, Context &$context) {
    $matches = array();
    $match = TRUE;
    foreach ($values as $k => $v) {
      if (!is_scalar($v)) {
        throw new \Exception(sprintf("%s::%s line %s: This cache does not support searching for non-scalar values.", get_class($this), __FUNCTION__, __LINE__));
      }
      if (!isset($this->indices->{$k})) {
        throw new \Exception(sprintf("%s::%s line %s: The content in this cache has not been indexed by the field %s.  Available indices: %s", get_class($this), __FUNCTION__, __LINE__, $k, array_keys(get_object_vars($this->indices))));
      }
      $index_values = array_keys(get_object_vars($this->indices->{$k}));
      // For now, limit index searching solely to exact matches.
      if (!in_array($v, $index_values)) {
        // If a given value doesn't exist for a given index, then any venn
        // intersection will also be empty.
        return array();
      }
      $matches = $matches + $this->indices->{$k}->{$v};
    }
    // Matches now holds an array of arrays, each of which holds a set of
    // indices.  An overlap of these arrays will comprise the set that  matches
    // all criteria.
    $matches = array_unique($matches);
    for ($i = 0; $i < count($matches); $i++) {
      $matches[$i] = $this->get($matches[$i], $context);
    }
    return $matches;
  }

  /**
   * {@inheritdoc}
   */
  public function get($key, Context &$context) {
    if (!property_exists($this->cache, $key)) {
      throw new \Exception(sprintf("%s::%s: No user result found for key %s", __CLASS__, __FUNCTION__, $key));
    }
    $user = $context->getDriver()->getCore()->userLoad($key);
    $user->pass = $this->getMetaData($user->uid, 'pass');
    return $user;
  }

  /**
   * Returns entity type.
   *
   * @return string
   *   The entity type stored by this cache.
   */
  public function getEntityType() {
    return 'user';
  }

  /**
   * Adds metadata about a stored cache item.
   *
   * @param mixed $index
   *   The index of the user object in the cache (uid).  May be
   *   either int or string.
   * @param string $key
   *   The metadata key to retrieve.  Returns entire
   *   metadata object if key is null.
   */
  private function getMetadata($index, $key = NULL) {
    $index = strval($index);
    if (!property_exists($this->metadata, $index)) {
      throw new \Exception(sprintf("%s::%s: line %s: The user with id %s is unknown to this cache.", get_class($this), __FUNCTION__, __LINE__, $index));
    }
    if (empty($key)) {
      return $this->metadata->{$index};
    }
    if (!property_exists($this->metadata->{$index}, $key)) {
      throw new \Exception(sprintf("%s::%s: line %s: The metadata with key %s was never set for this user", get_class($this), __FUNCTION__, __LINE__, $key));
    }
    return $this->metadata->{$index}->{$key};
  }

  /**
   * {@inheritdoc}
   */
  public function getValue($key, $field, Context &$context) {
    $object = $this->get($key, $context);
    if (!property_exists($object, $field)) {
      throw new \Exception(sprintf("%s::%s line %s: The property '%s' does not exist on this object.", __CLASS__, __FUNCTION__, __LINE__, $field));
    }
    entity_get_controller('user')->resetCache(array($object->uid));
    $w = entity_metadata_wrapper('user', $object);
    return $w->{$field}->value();
  }

}
