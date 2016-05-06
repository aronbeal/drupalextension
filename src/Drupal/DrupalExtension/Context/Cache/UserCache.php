<?php

/**
 * @file
 */

namespace Drupal\DrupalExtension\Context\Cache;
/**
 * A simple class to store cached copies of created Drupal items,
 *  with indexing.
 */
class UserCache extends CacheBase {
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
    return user_load($key);
  }
  /**
   * {@InheritDoc}
   */
  public function find($values=array()){
    $results = entity_load('user', array_keys(get_object_vars($this->cache)));
    if (empty($results)) {
      throw new \Exception(sprintf("%s::%s: The cached users couldn't be retrieved!", get_class($this), __FUNCTION__));
    }
    $matches = array();
    foreach ($results as $uid => $entity) {
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
    if(empty($this->cache)){
      return TRUE;
    }
    foreach ($this->cache as $uid) {
      $user = $this->get($uid);
      if(empty($user)){
        print sprintf("%s::%s: WARNING! The created user with id %s couldn't be deleted!\n", __CLASS__, __FUNCTION__, $uid);
      }
      $context->getDriver()->userDelete($user);
    }
    $context->getDriver()->processBatch();
    $this->resetCache();
    return TRUE;
  }
}
