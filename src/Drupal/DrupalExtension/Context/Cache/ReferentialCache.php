<?php

namespace Drupal\DrupalExtension\Context\Cache;

use Drupal\DrupalExtension\Context\RawDrupalContext;

/**
 * A cache that stores references to other caches during creation.
 *
 * This is the basis for cache constuctions like AliasCache, which
 * need to store references to cache items in both the node and
 * users cache.  This cache stores objects that contain information
 * about the cache where the actual object is stored, and the index
 * where it may be found.
 */
abstract class ReferentialCache extends CacheBase {

  /**
   * Stores references to other cache objects.
   *
   * @var \stdClass
   */
  protected $cacheReferences;

  /**
   * Constructor.
   *
   * @param array $cache_references
   *   An array of cache references.  This class will proxy requests to
   *   these caches based on some referential key.
   */
  public function __construct(array $cache_references) {
    parent::__construct();
    $this->cacheReferences = (object) $cache_references;
  }

  /**
   * {@inheritdoc}
   *
   * Note that this variant of the cache accepts only an array with the
   * following keys:
   *   'cache'=> Tha name of the cache to store.  Must be a cache that has
   *     been previously created in the beforeScenario step
   *   'value'=> The index of the cached object that is being stored.  This is
   *   the primary index by which the original object is stored.
   *
   * @throws \InvalidArgumentException
   *   If the passed index was empty.
   *   If the passed value is not an array.
   * @throws \RuntimeException
   *   - If either the key 'cache' or 'value' is not set in the passed value
   *   object.
   *   - If the indicated cache is not one of the caches managed by this
   *   referring class.
   */
  public function add($index, $value = NULL) {
    if (empty($index)) {
      throw new \InvalidArgumentException(sprintf("%s::%s: Couldn't determine primary key! Value couldn't be added to cache - cannot safely continue.", get_class($this), __FUNCTION__));
    }
    if (!is_array($value)) {
      throw new \InvalidArgumentException(sprintf("%s::%s: Invalid argument type: %s (array required)", __CLASS__, __FUNCTION__, gettype($value)));
    }
    if (!isset($value['value'])) {
      throw new \RuntimeException(sprintf("%s::%s line %s: cache add method requires that a value be
        passed for 'value' in the second argument (the id of the cached object).  Value array: %s", get_class($this), __FUNCTION__, __LINE__, print_r($value, TRUE)));
    }
    if (!isset($value['cache'])) {
      throw new \RuntimeException(get_class($this) . '::' . __FUNCTION__ . " cache add method requires that a value be
        passed for 'cache' in the second argument (the named cache where
        the object is stored).");
    }
    if (!property_exists($this->cacheReferences, $value['cache'])) {
      throw new \RuntimeException(sprintf("%s::%s: The cache '%s' is not available as a referrable cache", __CLASS__, __FUNCTION__, $value['cache']));
    }
    return parent::add($index, (object) $value);
  }

  /**
   * Retrieves the stored cache, and applies the callable to it.
   *
   * The callable will be passed the cache instance as the first
   * argument, followed by the key being retrieved.  Because it
   * is an anonymous function, extra parameters may be brought in
   * prior to invocation using the "use" operator.
   *
   * @param string $key
   *   The alias key being retrieved.  In referential cache, this will be
   *   a reference to an object that contains the actual id of the object,
   *   along with the actual cache it is kept in.
   * @param \Drupal\DrupalExtension\Context\RawDrupalContext $context
   *   The context that invoked this function.
   * @param callable $fn
   *   A method that will be invoked once the cache object is retrieved.
   *   It will be passed the real cache and the real id as parameters.
   *   Any additional parameters are added via 'use' statemet.
   *
   * @return mixed
   *   Whatever cache callable returns when invoked.
   *
   * @throws \RuntimeException
   *   If the cache that $key is stored in is not a referrable cache.
   */
  protected function apply($key, RawDrupalContext &$context, callable $fn) {
    $o = parent::get($key, $context);
    if (!property_exists($this->cacheReferences, $o->cache)) {
      throw new \RuntimeException(sprintf("%s::%s: The cache '%s' is not referrable", __CLASS__, __FUNCTION__, $o->cache));
    }
    return $fn($this->cacheReferences->{$o->cache}, $o->value);
  }

  /**
   * {@inheritdoc}
   */
  public function get($key, RawDrupalContext &$context) {
    return $this->apply($key, $context, function (CacheInterface $cache, $newkey) use (&$context) {
      return $cache->get($newkey, $context);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function getValue($alias, $field, RawDrupalContext &$context) {
    return $this->apply($alias, $context, function (CacheInterface $cache, $newkey) use (&$context, $field) {
      return $cache->getValue($newkey, $field, $context);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function deleteValue($key, $field, RawDrupalContext &$context) {
    return $this->apply($key, $context, function (CacheInterface $cache, $newkey) use (&$context, $field) {
      return $cache->deleteValue($newkey, $field, $context);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function remove($key, RawDrupalContext &$context) {
    if (property_exists($this->cache, $key)) {
      $o = $this->get($key, $context);
      unset($this->cache->{$key});
      return $o;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   *
   * This cache does not implement this interface method, and will throw an
   * exception if called.
   */
  public function addIndices() {
    throw new \RuntimeException(sprintf("%s::%s: Function not implemented", __CLASS__, __FUNCTION__));
  }

}
