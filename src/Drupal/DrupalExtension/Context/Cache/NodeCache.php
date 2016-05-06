<?php

/**
 * @file
 */

namespace Drupal\DrupalExtension\Context\Cache;
/**
 * A simple class to store cached copies of created Drupal items,
 *  with indexing.
 */
class NodeCache extends CacheBase {
  /**
   * {@InheritDoc}.
   *
   * WARNING: leverages the D7 api to directly retrieve a result.  This
   * eventually needs to be rewritten to use drivers.
   */
  public function get($key) {
    if (!property_exists($this->cache, $key)) {
      throw new \Exception(sprintf("%s::%s: No result found for alias %s", __CLASS__, __FUNCTION__, $key));
    }
    return node_load($key);
  }
  /**
   * {@InheritDoc}
   */
  public function find($values=array()){
    $nids = array_keys(get_object_vars($this->cache));
    //print sprintf("%s::%s: NIDS: %s\n", get_class($this), __FUNCTION__, print_r($nids, TRUE));
    $results = entity_load('node', $nids);
    if (empty($results)) {
      return array();
    }
    $matches = array();
    foreach ($results as $nid => $entity) {
      $e_wrapped = entity_metadata_wrapper('user', $entity);
      $match = TRUE;
      foreach ($values as $k => $v) {
        if (get_class($e_wrapped->{$k}) === 'EntityListWrapper' && !is_array($v)) {
          $v = array($v);
        }
        $old_value = $e_wrapped->{$k}->value();
        //stringify for printing in debug messages.
        if($old_value !== $v){
          $match = FALSE;
          break;
        }
      }
      if($match){
        $matches []= $this->get($e_wrapped->getIdentifier());
      }
    }
    return $matches;
  }
  /**
   * {@InheritDoc}
   */
  public function clean(&$context){
    foreach ($this->cache as $nid) {
      $node = $this->get($nid);
      if(!empty($node)){
        $context->getDriver()->nodeDelete($node);
      }
    }
    $this->resetCache();
  }
}
