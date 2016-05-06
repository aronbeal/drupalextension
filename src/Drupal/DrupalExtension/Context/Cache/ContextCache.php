<?php

/**
 * @file
 */

namespace Drupal\DrupalExtension\Context\Cache;
/**
 * A simple class to store cached copies of created Drupal items,
 *  with indexing.
 */
class ContextCache extends CacheBase {

  /**
   * {@InheritDoc}
   */
  public function clean(&$context){
    if(empty($this->cache)){
      return TRUE;
    }
    //do not need to delete contexts; just remove references.
    return $this->resetCache();
  }

  /**
   * This cache does not implement this interface method, and will throw an
   * exception if called.
   */
  public function addIndices() {
    throw new \Exception(get_class($this).'::'.": does not implement the ".__FUNCTION__." method.");
  }
}
