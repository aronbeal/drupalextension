<?php

namespace Drupal\DrupalExtension\Context\Cache;

use Drupal\DrupalExtension\Context\RawDrupalContext as Context;

/**
 * A cache that stores references to other caches during creation, so that
 * it can return the result of a cache other than itself.
 */
abstract class ReferentialCache extends CacheBase {

  protected $cache_references = NULL;

  /**
   *
   */
  public function __construct() {
    parent::__construct();
    if (func_num_args() !== 1) {
      throw new \Exception(sprintf("%s::%s: Alias cache requires an array of cache references as an argument. Number of arguments passed: %s", __CLASS__, __FUNCTION__, func_num_args()));
    }
    if (!is_array(func_get_arg(0))) {
      throw new \Exception(sprintf("%s::%s: Wrong argument type (%s) passed.", __CLASS__, __FUNCTION__, gettype(func_get_arg(0))));
    }
    $this->cache_references = (object) func_get_arg(0);
  }

  /**
   * {@InheritDoc}.
   *
   * Note that this variant of the cache accepts only an array with the following keys:
   *   'cache'=> Tha name of the cache to store.  Must be a cache that has
   *     been previously created in the beforeScenario step
   *   'value'=> The index of the cached object that is being stored.  This is
   *   the primary index by which the original object is stored.
   */
  public function add($index, $value = NULL) {
    if (empty($index)) {
      throw new \Exception(sprintf("%s::%s: Couldn't determine primary key! Value couldn't be added to cache - cannot safely continue.", get_class($this), __FUNCTION__));
    }
    if (!is_array($value)) {
      throw new \Exception(sprintf("%s::%s: Invalid argument type: %s (array required)", __CLASS__, __FUNCTION__, gettype($value)));
    }
    if (!isset($value['value'])) {
      throw new \Exception(sprintf("%s::%s line %s: cache add method requires that a value be
        passed for 'value' in the second argument (the id of the cached object).  Value array: %s", get_class($this), __FUNCTION__, __LINE__, print_r($value, TRUE)));
    }
    if (!isset($value['cache'])) {
      throw new \Exception(get_class($this) . '::' . __FUNCTION__ . " cache add method requires that a value be
        passed for 'cache' in the second argument (the named cache where
        the object is stored).");
    }
    if (!property_exists($this->cache_references, $value['cache'])) {
      throw new \Exception(sprintf("%s::%s: The cache '%s' is not available as a referrable cache", __CLASS__, __FUNCTION__, $value['cache']));
    }
    // Print sprintf("%s::%s: Adding named alias: %s to id %s with cache %s\n", get_class($this), __FUNCTION__, $index, $value['value'], $value['cache']);.
    return parent::add($index, (object) $value);
  }

  /**
   * {@InheritDoc}.
   *
   * @return array The cache name and cache key as the first and second indices
   * of an array.
   */
  public function get($key, Context &$context) {
    $o = parent::get($key, $context);
    if (!property_exists($this->cache_references, $o->cache)) {
      throw new \Exception(sprintf("%s::%s: The cache '%s' is not referrable", __CLASS__, __FUNCTION__, $o->cache));
    }
    return $this->cache_references->{$o->cache}->get($o->value, $context);
  }

  /**
   * Returns the value for a field from a given alias.
   */
  public function getValue($key, $field, Context &$context) {
    $o = parent::get($key, $context);
    if (!property_exists($this->cache_references, $o->cache)) {
      throw new \Exception(sprintf("%s::%s: The cache '%s' is not referrable", __CLASS__, __FUNCTION__, $o->cache));
    }
    return $this->cache_references->{$o->cache}->getValue($o->value, $field, $context);
  }

  /**
   * {@InheritDoc}.
   */
  public function remove($key, Context &$context) {
    if (property_exists($this->cache, $key)) {
      $o = $this->get($key, $context);
      unset($this->cache->{$key});
      return $o;
    }
    return NULL;
  }

  /**
   * This cache does not implement this interface method, and will throw an
   * exception if called.
   */
  public function addIndices() {
    throw new \Exception(sprintf("%s::%s: Function not implemented", __CLASS__, __FUNCTION__));
  }

}
