<?php

namespace Drupal\DrupalExtension\Context\Cache;

use Drupal\DrupalExtension\Context\RawDrupalContext;

/**
 * The base implementation for DrupalContext Caching.
 *
 * A simple class to store cached copies of created Drupal items,
 *  with indexing.  Note: not all interface methods are implemented!  It
 *  is up to the subclass to fill in the blanks.
 */
abstract class CacheBase implements CacheInterface {

  /**
   * Stores actual copies of cached items.
   *
   * @var \stdClass
   */
  protected $cache;

  /**
   * A map with strings as keys, and cache indices as values.
   *
   * This supplements the basic caching mechanism with a secondary one that
   * allows referring to specific ids within this cache by other names -
   * secondary or even tertiary indexes. The key is usually the value of the
   * primary key of the created drupal object, or the value of 'key' when an
   * arbitrary string needs to be used for indexing purposes.
   * In the case of the latter, it is up to the caller to ensure uniqueness
   * of the key, and to only add with the 'key' option for any entries of
   * that type.
   *
   * @var \stdClass
   */
  protected $indices;

  /**
   * Stores cache instructions for particular cache items.
   *
   * Cache instructions provide a means to bind run-time behavior to
   * individual cache objects.  Currently, this is limited to preventing
   * cleanup of a particular object in the afterScenario.
   *
   * @var \stdClass
   */
  protected $cacheInstructions;

  /**
   * Constructor.
   */
  public function __construct() {

    // Print "Constructing ".get_class($this) ."\n";.
    $this->cache = new \stdClass();
    $this->indices = new \stdClass();
    $this->cacheInstructions = new \stdClass();
    $this->resetCache();
  }

  /**
   * Magic method to display cache contents as a CLI-formatted string.
   *
   * @return string
   *   A cli-formatted string describing the state of the cache, showing
   *   a list of current keys and indices (but not values, which would
   *   usually be overly verbose.
   */
  public function __toString() {
    $index_values = array();
    $result = "\n**************************";
    $result .= "\n " . get_class($this);
    $result .= "\n**************************\nCache entry count: " . $this->count();
    $result .= "\nKeys: " . implode(', ', $this->getCacheIndicies());
    $result .= "\nIndices: ";
    foreach ($this->getNamedIndices() as $index_name) {
      $result .= "\nIndex values stored in $index_name";
      foreach ($this->indices->{$index_name} as $index_value) {
        $result .= "\n\t$index_value";
      }
    }
    $result .= "\n**************************\n";
    return $result;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   *   - If the passed index is empty or non-scalar.
   * @throws \RuntimeException
   *   - If the index to be added already exists.
   */
  public function add($index, $value = NULL) {
    if (empty($index)) {
      throw new \InvalidArgumentException(sprintf("%s::%s: No index key passed! Value couldn't be added to cache - cannot safely continue.", get_class($this), __FUNCTION__));
    }
    if (empty($value)) {
      if (!is_scalar($index)) {
        throw new \InvalidArgumentException(sprintf("%s::%s line %s: cannot add a non-scalar as an index", get_class($this), __FUNCTION__, __LINE__));
      }
      $index = strval($index);
      // Stored value is a primary key.
      $value = strval($index);
    }
    try {
      if (property_exists($this->cache, $index)) {
        throw new \RuntimeException(sprintf("%s::%s: An item with the index %s already exists in this cache", get_class($this), __FUNCTION__, $index));
      }
    }
    catch (\RuntimeException $e) {
      // @TODO: confirm this logic.
      // Do nothing - we *want* there to be no entry.
    }
    $this->cache->{$index} = $value;
    if (is_scalar($value)) {
      return $index;
    }
    // If the value is not scalar, it becomes possible to store references by
    // named index.
    foreach ($this->getNamedIndices() as $index_name) {
      if (!isset($value->{$index_name})) {
        // The value doesn't contain any entries that match any known indices.
        continue;
      }
      $index_value = $value->{$index_name};
      if (!is_scalar($index_value)) {
        // Can't perform an index lookup on a non-scalar value.
        continue;
      }
      if (!isset($this->indices->{$index_name}->{$index_value})) {
        $this->indices->{$index_name}->{$index_value} = array();
      }
      $this->indices->{$index_name}->{$index_value}[] = $index;
    }
    return $index;
  }

  /**
   * Allows the addition of cache instructions for a particular cached value.
   *
   * A mechanism to provide additional information about
   * cached objects that dictates cache behavior.  For instance, if we add
   * the key 'noclean' to a given index, that item is ignored during post-
   * scenario and post-feature cleanup.
   *
   * @param string $index
   *   The index of the item in the cache.
   * @param string $key
   *   The instruction key to set.
   * @param bool $value
   *   The value to set the instruction key to.
   *
   * @throws \RuntimeException
   *   If the cache does not contain the indexed item.
   */
  public function addCacheInstruction($index, $key, $value = TRUE) {
    if (!property_exists($this->cache, $index)) {
      throw new \RuntimeException(sprintf("%s::%s: No item with index %s exists in this cache", get_class($this), __FUNCTION__, $index));
    }
    if (!property_exists($this->cacheInstructions, $index)) {
      $this->cacheInstructions->{$index} = [];
    }
    $this->cacheInstructions->{$index}[$key] = $value;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \RuntimeException
   *   If no arguments were passed.
   */
  public function addIndices() {

    $named_indices = func_get_args();
    if (empty($named_indices)) {
      throw new \RuntimeException(sprintf("%s:: No arguments passed to %s function", get_class($this), __FUNCTION__));
    }
    foreach ($named_indices as $named_index) {
      if (!property_exists($this->indices, $named_index)) {
        $this->indices->{$named_index} = new \stdClass();
      }
    }

  }

  /**
   * {@inheritdoc}
   */
  public function clean(RawDrupalContext &$context) {

    if ($this->count() === 0) {
      return TRUE;
    }
    // Do not need to delete contexts; just remove references.
    return $this->resetCache();
  }

  /**
   * {@inheritdoc}
   */
  public function count() {

    return count(array_keys(get_object_vars($this->cache)));
  }

  /**
   * {@inheritdoc}
   */
  public function deleteValue($key, $field, RawDrupalContext &$context) {
    throw new \RuntimeException(sprintf("%s::%s line %s: No implementation available.", get_class($this), __FUNCTION__, __LINE__));
  }

  /**
   * {@inheritdoc}
   */
  public function exists($key, RawDrupalContext &$context) {
    return (isset($this->cache->{$key}));
  }

  /**
   * {@inheritdoc}
   */
  public function find(array $values, RawDrupalContext &$context) {

    throw new \RuntimeException(sprintf("%s: does not implement the %s method", get_class($this), __FUNCTION__));
  }

  /**
   * {@inheritdoc}
   */
  public function get($key, RawDrupalContext &$context) {

    if (!property_exists($this->cache, $key)) {
      throw new \RuntimeException(sprintf("%s::%s: No result found for key %s.", get_class($this), __FUNCTION__, $key));
    }
    return $this->cache->{$key};
  }

  /**
   * Provides a list of the keys assigned to objects in this cache.
   *
   * @return array
   *   An array of string keys.
   */
  protected function getCacheIndicies() {

    return array_keys(get_object_vars($this->cache));
  }

  /**
   * Retrieves metadata for a particular index.
   *
   * @param string $index
   *   The index to retrieve metadata for.
   * @param string $key
   *   The key to lookup.
   *
   * @return mixed
   *   The value stored for the metadata key $key, or NULL if no metadata
   *   has been assigned for that index.
   *
   * @see \Drupal\DrupalExtension\Context\Cache\CacheBase::addCacheInstruction()
   */
  public function getCacheInstruction($index, $key) {
    if (!property_exists($this->cache, $index)) {
      return NULL;
    }
    if (!property_exists($this->cacheInstructions, $index)) {
      return NULL;
    }
    if (!array_key_exists($key, $this->cacheInstructions->{$index})) {
      return NULL;
    }
    return $this->cacheInstructions->{$index}[$key];
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityType() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getIndex($index_name, $index_key) {

    if (!property_exists($this->indices, $index_name)) {
      throw new \RuntimeException(sprintf("%s::%s: The index %s does not exist in this cache! Cache state: %", get_class($this), __FUNCTION__, $index_name, $this));
    }
    if (!property_exists($this->indices->{$index_name}, $index_key)) {
      return array();
    }
    return $this->indices->{$index_name}->{$index_key};
  }

  /**
   * {@inheritdoc}
   */
  public function getNamedIndices() {

    return array_keys(get_object_vars($this->indices));
  }

  /**
   * {@inheritdoc}
   *
   * @throws \RuntimeException
   *   If the named field does not exist on the object.
   */
  public function getValue($alias, $field, RawDrupalContext &$context) {
    $object = $this->get($alias, $context);
    if (!property_exists($object, $field)) {
      throw new \RuntimeException(sprintf("%s::%s line %s: The property '%s' does not exist on this object.", get_class($this), __FUNCTION__, __LINE__, $field));
    }
    $field_value = $object->{$field};
    return $field_value;
  }

  /**
   * {@inheritdoc}
   *
   * Note: the base implementation does not implement remove.  It simply throws
   * an exception, and should be overridden by subclasses.
   */
  public function remove($key, RawDrupalContext &$context) {

    throw new \RuntimeException(sprintf("%s:: does not implement the %s method %", get_class($this), __FUNCTION__));
  }

  /**
   * Resets cache storage.
   *
   * Should only be called internally by the clean method, as that method does
   * db cleanup as a side-effect before calling, which would otherwise not
   * be accomplished.
   */
  protected function resetCache() {

    $this->cache = new \stdClass();
    // $this->hash = new \stdClass();
    foreach ($this->getNamedIndices() as $k) {
      // Print "Creating named index: $k\n";.
      $this->indices->{$k} = new \stdClass();
    }
  }

}
