<?php

/**
 * @file
 */

namespace Drupal\DrupalExtension\Context\Cache;
/**
 * A simple class to store cached copies of created Drupal items,
 *  with indexing.  Note: not all interface methods are implemented!  It
 *  is up to the subclass to fill in the blanks.
 */
abstract class CacheBase implements CacheInterface {
  // Stores actual copies of cached items.  Using stdclass to allow
  // "string" integer keys.
  protected $cache = NULL;
  // A map with strings as keys, and cache indices as values.  The key
  // is usually the value of $this->primary_key (not defined in this
  // abstract class), or the value of 'key' when an arbitrary string
  // needs to be used for indexing purposes.  In the case of the latter,
  // it is up to the caller to ensure uniqueness of the key, and to only add
  // with the 'key' option for any entries of that type.
  // protected $hash = new \stdClass();
  // A map with serialized field values as keys, and cache indices as values.
  // (more than one of a given serialized field value is possible).  This
  // is structured as:
  //  $indices (stdClass) -> [index name (stdClass))] -> [index key (array)] ->
  //    [values (string indices)].
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
   * Can only be called internally by the clean method, as that method does
   * db cleanup as a side-effect before calling, which would otherwise not
   * be accomplished.
   *
   * @return NULL
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
   * @return array an array of string keys.
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
    // Print "Adding index " . implode(',', $named_indices) . " to ".get_class($this)."\n";
    foreach ($named_indices as $named_index) {
      if (!property_exists($this->indices, $named_index)) {
        $this->indices->{$named_index} = new \stdClass();
      }
    }

  }
  /**
   * {@InheritDoc}.
   */
  public function add($index, $value=NULL) {
    if (empty($index)) {
        throw new \Exception(sprintf("%s::%s: Couldn't determine primary key! Value couldn't be added to cache - cannot safely continue.", get_class($this), __FUNCTION__));
    }
    //print sprintf("%s::%s: Preparing to add item with key %s, value %s\n", get_class($this), __FUNCTION__, $index, $value);
    $index = strval($index);
    if (empty($value)) {
      $value = $index; //stored value is a primary key
    }
    try{
      if (!empty($this->get($index))) {
        throw new \Exception(sprintf("%s::%s: An item with the index %s already exists in this cache", get_class($this), __FUNCTION__, $index));
      }
    } catch(\Exception $e){
      //do nothing - we *want* there to be no entry.
    }
    //print sprintf("%s::%s: Adding item with key %s, value type %s\n", get_class($this), __FUNCTION__, $index, gettype($value));
    $this->cache->{$index} = $value;
    return $index;
  }
  /**
   * This cache does not implement this interface method, and will throw an
   * exception if called.
   */
  public function find($values=array()) {
    throw new \Exception(get_class($this).'::'.": does not implement the ".__FUNCTION__." method.");
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
  public function remove($key) {
    throw new \Exception(sprintf("%s:: does not implement the %s method %", get_class($this), __FUNCTION__));
  }
  /**
   * Returns the item found at the named index.
   *
   * @param string $index_name
   *   A known named index items in this cache are stored
   *                            under
   * @param string $k
   *   The value of the index key to search
   *
   * @return array              An array of items stored at the index, or NULL
   *                                if there was no entry with value $k.
   *
   * @throws \Exception If the named index is not valid (which should be known
   *          before runtime).
   */
  public function getIndex($index_name, $index_key) {
    if (!property_exists($this->indices, $index_name)) {
      throw new \Exception(sprintf("%s::%s: Cache %s does not exist! Cache state: %", get_class($this), __FUNCTION__, $index_name, $this));
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
      throw new \Exception(sprintf("%s::%s: No result found for alias %s", __CLASS__, __FUNCTION__, $key));
    }
    return $this->cache->{$key};
  }
  /**
   * Magic method to display cache contents as a CLI-formatted string.
   *
   * @return string [description]
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
