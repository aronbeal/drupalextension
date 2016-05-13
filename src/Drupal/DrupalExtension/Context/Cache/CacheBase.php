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
  //primary key by which content is indexed.  SHould be a field value.
  //One can also set by an arbitrary index value.  See the add method for
  //more information.
  protected $primary_key = NULL;
  // Stores actual copies of cached items.  Using stdclass to allow
  // "string" integer keys.
  protected $cache = NULL;
  // A map with strings as keys, and cache indices as values.  The key
  // is usually the value of $this->primary_key (not defined in this
  // abstract class), or the value of 'key' when an arbitrary string
  // needs to be used for indexing purposes.  In the case of the latter,
  // it is up to the caller to ensure uniqueness of the key, and to only add
  // with the 'key' option for any entries of that type.
  //protected $hash = new \stdClass();
  // A map with serialized field values as keys, and cache indices as values.
  // (more than one of a given serialized field value is possible).  This
  // is structured as:
  //  $indices (stdClass) -> [index name (stdClass))] -> [index key (array)] ->
  //    [values (string indices)]
  protected $indices = NULL;
  /**
   * Constructor.
   */
  public function __construct() {
    //print "Constructing ".get_class($this) ."\n";
    $this->cache = new \stdClass;
    $this->indices = new \stdClass;
    if(!is_null($this->primary_key)){
      $this->addIndices($this->primary_key);
    }
    $this->resetCache();
  }
  /**
   *  Can only be called internally by the clean method, as that method does
   * db cleanup as a side-effect before calling, which would otherwise not
   * be accomplished.
   * @return NULL
   */
  protected function resetCache() {
    $this->cache = new \stdClass();
    //$this->hash = new \stdClass();
    foreach ($this->getNamedIndices() as $k) {
      //print "Creating named index: $k\n";
      $this->indices->{$k} = new \stdClass();
    }
  }
  /**
   * {@inheritDoc}.
   */
  public function getNamedIndices() {
    return ((is_null($this->primary_key)) ? array() : array($this->primary_key)) + array_keys(get_object_vars($this->indices));
  }
  /**
   * Provides a list of the keys assigned to objects in this cache
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
      throw new \Exception("No arguments passed to addIndices function for " . get_class($this));
    }
    //print "Adding index " . implode(',', $named_indices) . " to ".get_class($this)."\n";
    foreach ($named_indices as $named_index) {
      if (!property_exists($this->indices, $named_index)) {
        $this->indices->{$named_index} = new \stdClass();
      }
    }

  }
  /**
   * {@InheritDoc}.
   */
  public function add($item, $options=array()) {
    if(empty($item)){
      var_dump(debug_backtrace());
      throw new \Exception("Cannot add an empty item to ".get_class($this));
    }
    try{
      $primary_key = (!is_null($this->primary_key) && is_object($item)) ? $item->{$this->primary_key} : NULL;
    } catch(\Exception $e){
      throw new \Exception(sprintf("%s::%s: %s, item: %s:", get_class($this), __FUNCTION__, $e->getMessage(), json_encode($item)));
    }
    $options = $options + array(
      'key'=>$primary_key
    );
    if(empty($options['key'])){
      //in cases where there is no primary key, and no value was passed for
      //'key' in options
      throw new \Exception("Couldn't establish primary key!  Value couldn't be added to cache. Cache state: " . $this);
    }
    $options['key'] = strval($options['key']);
    if (property_exists($this->cache,$options['key'])) {
      throw new \Exception("An item with the index $options[key] already exists in this cache (".get_class($this).'): '.print_r($this->get($options['key']), TRUE));
    }
    $this->cache->{$options['key']} = $item;
    // Look for any established extra indices, and index this content
    // by those as well.
    foreach ($this->getNamedIndices() as $k) {
      if (isset($item->{$k}) && !empty($item->{$k})) {
        // A field we want to index has been discovered, and isn't empty.
        // Capture the serialized value of the fields, and use it for an index.
        $serialized_field_values = serialize($item->{$k});
        if (!property_exists($this->indices->{$k}, $serialized_field_values)) {
          $this->indices->{$k}->{$serialized_field_values} = array();
        }
        // remember, this one's an array - there can be multiples of a given
        // cached item where a field is the only thing determining uniqueness.
        // This should not be true for a serialization of the entire values
        // array - those entries should be unique.
        $this->indices->{$k}->{$serialized_field_values} []= $options['key'];
      }
    }
    //return the primary key index of the stored cache item.
    return $options['key'];
  }
  /**
   * {@InheritDoc}
   */
  public function count(){
    return count($this->getCacheIndicies());
  }
  /**
   * {@InheritDoc}.
   */
  public function get($key) {
    if (property_exists($this->cache,$key)) {
      return $this->cache->{$key};
    }
    return NULL;
  }
  /**
   * {@InheritDoc}
   */
  public function remove($key){
    throw new \Exception(get_class($this).'::'.": does not implement the ".__FUNCTION__." method.");
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
    if(!property_exists($this->indices, $index_name)){
      throw new \Exception("Cache $index_name does not exist! Cache state: " . $this);
    }
    if(!property_exists($this->indices->{$index_name}, $index_key)){
      return array();
    }
    return $this->indices->{$index_name}->{$index_key};
  }

  /**
   * {@inheritDoc}.
   */
  public function find($values = array()) {
    // $results_indexed = array();
    // //print __FUNCTION__.". Keys: ".implode(',', array_keys($values)).", Named indices: " . implode(',', $this->getNamedIndices()) . "\n";
    // $valid_indices = array_intersect($this->getNamedIndices(), array_keys($values));
    // print "Find.  Valid indices: ".implode(',', $valid_indices)."\n";
    // if(empty($valid_indices)){
    //   throw new \Exception("No valid indices were passed to the find method. (Indices asked for: ".implode(',', array_keys($values)).'). Cache state: ' . $this);
    // }
    // // Search by all named indices.
    // foreach ($valid_indices as $index_name) {
    //   // There is an index by this field at this point.  Check to see if our
    //   // entry is present.
    //   $index_key = serialize($values[$index_name]);
    //   $results_indexed []= $this->getIndex($index_name, $index_key);
    // }
    // //at this point, $results is an array of arrays.  Perform an intersection
    // //of all these results to get a final set
    // $results = array_reduce($results_indexed, function($carry, $arr){
    //   return array_intersect($carry, $arr);
    // }, $results_indexed[0]);
    $results = array();
    //TODO: jeez, can we do better than n^3 here?
    foreach($this->cache as $key=>$o){
      foreach($o as $field_name=>$field_value){
        foreach($values as $k=>$v){
          if(property_exists($o, $k)){
            $tmp_value = serialize($field_value);
            if($v != $field_value && $v !== $tmp_value){
              //print "Nomatch.  Tomatch: $v, Regular: $field_value, Serialized: $tmp_value\n";
              break 2;
            }
          }
        }
        $results []= $o;
      }
    }
    return $results;
  }
  /**
   * Magic method to display cache contents as a CLI-formatted string.
   * @return string [description]
   */
  public function __toString(){
    $result = "\n**************************";
    $result .= "\n " . get_class($this);
    $result .= "\n**************************\nCache entry count: ".$this->count();
    $result .= "\nKeys: ".implode(', ', $this->getCacheIndicies());
    $result .= "\nIndices: ".implode(', ', $this->getNamedIndices());
    $result .= "\n**************************\n";
    return $result;
  }
}
