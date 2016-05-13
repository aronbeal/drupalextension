<?php

/**
 * @file
 */

namespace Drupal\DrupalExtension\Context\Cache;

/**
 * A simple class to store globally unique aliases to specific items.
 */
class AliasCache extends CacheBase {
  const ALIAS_KEY_PREFIX   = '@';
  const ALIAS_VALUE_PREFIX = '@:';

  protected $primary_key = NULL;

  /**
   * Looks for a defined alias as a property of the passed object.  Unsets it
   * if found, and returns whatever the alias stored there is.
   *
   * @param object &$oAn
   *   object
   *   An object
   *
   * @return string|NULL
   *         The string alias if one was found, or NULL if no alias key was
   *         present.
   */
  public static function extractAliasKey(&$o) {
    if(!is_object($o)){
      throw new \Exception(sprintf("%s::%s: Wrong argument type (%s) passed.", __CLASS__, __FUNCTION__, gettype($o)));
    }
    $alias = NULL;
    if (is_object($o)) {
      if (property_exists($o, self::ALIAS_KEY_PREFIX)) {
        $alias = $o->{self::ALIAS_KEY_PREFIX};
        unset($o->{self::ALIAS_KEY_PREFIX});
      }
    }
    elseif (is_array($o)) {
      if (array_key_exists(self::ALIAS_KEY_PREFIX, $o)) {
        $alias = $o[self::ALIAS_KEY_PREFIX];
        unset($o[self::ALIAS_KEY_PREFIX]);
      }
    }
    else {
      throw new \Exception(sprintf("%s::%s: Invalid argument type: %s", __CLASS__, __FUNCTION__, gettype($o)));
    }
    return $alias;
  }

  /**
   * {@InheritDoc}.
   *
   * Note that this variant of the cache accepts integer values to store (which
   * correspond to the primary key of the object they're aliasing to).  It
   * also takes the (required) 'cache' argument that tells it which cache is
   * storing this item.
   */
  public function add($index, $value=NULL) {
    if (empty($index)) {
        throw new \Exception(sprintf("%s::%s: Couldn't determine primary key! Value couldn't be added to cache - cannot safely continue.", get_class($this), __FUNCTION__));
    }
    if(!is_array($value)){
      throw new \Exception(sprintf("%s::%s: Invalid argument type: %s", __CLASS__, __FUNCTION__, gettype($value)));
    }
    if (!isset($value['cache']) || !isset($value['value'])) {
      throw new \Exception(get_class($this) . '::' . __FUNCTION__ . "Alias cache add method requires that a value be
        passed for 'cache' in the second argument (the named cache where
        the object is stored).");
    }
    return parent::add($index, (object) $value);
    // $index = strval($index);
    // if (empty($value)) {
    //   $value = $index; //stored value is a primary key
    // }
    // if (!isset($options['key'])) {
    //   throw new \Exception(get_class($this) . '::' . __FUNCTION__ . "Alias cache add method requires that a value be
    //     passed for 'key' in the second argument.");
    // }
    // if (!isset($options['cache'])) {
    //   throw new \Exception(get_class($this) . '::' . __FUNCTION__ . "Alias cache add method requires that a value be
    //     passed for 'cache' in the second argument (the named cache where
    //     the object is stored).");
    // }
    // if (!is_string($value)) {
    //   throw new \Exception(get_class($this) . '::' . __FUNCTION__ . "The aliasCache currently only accepts string primitives as cachable values");
    // }
    // $o             = new \stdClass();
    // $o->cache_name = $options['cache'];
    // unset($options['cache']);
    // $o->value = $value;
    // return parent::add($o, $options);
  }
  /**
   * {@InheritDoc}.
   *
   * @return array The cache name and cache key as the first and second indices
   * of an array.
   */
  public function get($key) {
    if (!property_exists($this->cache, $key)) {
      throw new \Exception(sprintf("%s::%s: No result found for alias %s", __CLASS__, __FUNCTION__, $key));
    }
    $o = $this->cache->{$key};
    return array($o->cache, $o->value);
  }

  /**
   * {@InheritDoc}.
   */
  public function remove($key) {
    if (property_exists($this->cache, $key)) {
      $o = $this->cache->{$key};
      unset($this->cache->{$key});
      return array($o->cache, $o->value);
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
  /**
   * {@InheritDoc}.
   */
  public function clean(&$context) {

    if (empty($this->cache)) {
      return TRUE;
    }
    // Do not need to delete contexts; just remove references.
    return $this->resetCache();
  }

}
