<?php

namespace Drupal\DrupalExtension\Context\Cache;

use Behat\Behat\Context\TranslatableContext as Context;

/**
 * The base implementation for DrupalContext Caching.
 *
 * A simple class to store cached copies of created Drupal items,
 *  with indexing.  Note: not all interface methods are implemented!  It
 *  is up to the subclass to fill in the blanks.
 */
abstract class CacheBase implements CacheInterface {
  // Stores actual copies of cached items.  Using stdclass to allow
  // "string" integer keys.
  protected $cache = NULL;
  // A map with strings as keys, and cache indices as values.  This supplements
  // the basic caching mechanism with a secondary one that allows referring to
  // specific ids within this cache by other names - secondary or even  tertiary
  // indexes. The key is usually the value of the primary key of the created
  // drupal object, or the value of 'key' when an arbitrary string needs to be
  // used for indexing purposes.  In the case of the latter, it is up to the
  // caller to ensure uniqueness of the key, and to only add with the 'key'
  // option for any entries of that type.
  protected $indices = NULL;

  /**
   * Constructor.
   */
  public function __construct() {
    // Print "Constructing ".get_class($this) ."\n";.
    $this->cache = new \stdClass();
    $this->indices = new \stdClass();
    $this->resetCache();
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

  /**
   * {@inheritDoc}.
   */
  public function getNamedIndices() {
    return array_keys(get_object_vars($this->indices));
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
   * {@InheritDoc}.
   */
  public function addIndices() {
    $named_indices = func_get_args();
    if (empty($named_indices)) {
      throw new \Exception(sprintf("%s:: No arguments passed to %s function", get_class($this), __FUNCTION__));
    }
    foreach ($named_indices as $named_index) {
      if (!property_exists($this->indices, $named_index)) {
        $this->indices->{$named_index} = new \stdClass();
      }
    }

  }

  /**
   * {@InheritDoc}.
   */
  public function add($index, $value = NULL) {
    if (empty($index)) {
      throw new \Exception(sprintf("%s::%s: Couldn't determine primary key! Value couldn't be added to cache - cannot safely continue.", get_class($this), __FUNCTION__));
    }
    $index = strval($index);
    if (empty($value)) {
      // Stored value is a primary key.
      $value = $index;
    }
    try {
      $existing = $this->get($index);
      if (!empty($existing)) {
        throw new \Exception(sprintf("%s::%s: An item with the index %s already exists in this cache", get_class($this), __FUNCTION__, $index));
      }
    }
    catch (\Exception $e) {
      // Do nothing - we *want* there to be no entry.
    }
    $this->cache->{$index} = $value;
    return $index;
  }

  /**
   * Finds an item in the cache that matches the passed values.
   *
   * Default behavior does not implement, and throws exception if
   * invoked in a subclass.  Override as necessary.
   *
   * @param array $values
   *   An array where the keys are property names on the cached object, and
   *   where values are the property values.
   */
  public function find(array $values = array()) {
    throw new \Exception(sprintf("%s: does not implement the %s method", get_class($this), __FUNCTION__));
  }

  /**
   * {@InheritDoc}.
   */
  public function count() {
    return count(array_keys(get_object_vars($this->cache)));
  }

  /**
   * {@InheritDoc}.
   */
  public function clean(Context &$context) {
    if ($this->count() === 0) {
      return TRUE;
    }
    // Do not need to delete contexts; just remove references.
    return $this->resetCache();
  }

  /**
   * {@InheritDoc}.
   */
  public function remove($key) {
    throw new \Exception(sprintf("%s:: does not implement the %s method %", get_class($this), __FUNCTION__));
  }

  /**
   * Returns the item found at the named index.
   *
   * An index is different than an alias - indices are defined upon cache
   * creation, and are populated automatically when new items are added.  For
   * example, a user index might be 'name', in which case the user cache would
   * need to capture the value of 'name' and store it referencing the user id.
   *
   * Example usage: `getIndex('name', 'Fred')`
   *
   * @param string $index_name
   *   A known named index items in this cache are stored under.
   * @param string $index_key
   *   The value of the index key to search.
   *
   * @return array
   *   An array of items stored at the index, or NULL if there was no entry
   *   with value $k within the given index.
   *
   * @throws \Exception
   *   If the named index is not valid (which should be known before runtime).
   */
  public function getIndex($index_name, $index_key) {
    if (!property_exists($this->indices, $index_name)) {
      throw new \Exception(sprintf("%s::%s: The index %s does not exist in this cache! Cache state: %", get_class($this), __FUNCTION__, $index_name, $this));
    }
    if (!property_exists($this->indices->{$index_name}, $index_key)) {
      return array();
    }
    return $this->indices->{$index_name}->{$index_key};
  }

  /**
   * {@InheritDoc}.
   */
  public function get($key) {
    if (!property_exists($this->cache, $key)) {
      throw new \Exception(sprintf("%s::%s: No result found for key %s", __CLASS__, __FUNCTION__, $key));
    }
    return $this->cache->{$key};
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
    $result = "\n**************************";
    $result .= "\n " . get_class($this);
    $result .= "\n**************************\nCache entry count: " . $this->count();
    $result .= "\nKeys: " . implode(', ', $this->getCacheIndicies());
    $result .= "\nIndices: " . implode(', ', $this->getNamedIndices());
    $result .= "\n**************************\n";
    return $result;
  }

}
